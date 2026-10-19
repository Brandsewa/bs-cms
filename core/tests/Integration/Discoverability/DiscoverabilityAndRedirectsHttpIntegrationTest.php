<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Discoverability;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Auth\ApiTokenService;
use BsCms\Auth\SessionManager;
use BsCms\Auth\SystemRoles;
use BsCms\Authz\Authorizer;
use BsCms\Authz\PermissionCompiler;
use BsCms\Content\Cache\PublicResponseCache;
use BsCms\Content\EntryRepository;
use BsCms\Content\HierarchyValidator;
use BsCms\Content\Query\QueryParser;
use BsCms\Content\RelationManager;
use BsCms\Content\SlugService;
use BsCms\Content\VersionManager;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Health\SiteHealthPresenter;
use BsCms\Health\SiteHealthService;
use BsCms\Http\Controllers\SiteHealthController;
use BsCms\Http\Controllers\AuthController;
use BsCms\Http\Controllers\AuditController;
use BsCms\Http\Controllers\CollectionsController;
use BsCms\Http\Controllers\EmailSettingsController;
use BsCms\Http\Controllers\EntriesController;
use BsCms\Http\Controllers\MeController;
use BsCms\Http\Controllers\MediaController;
use BsCms\Http\Controllers\MediaFoldersController;
use BsCms\Http\Controllers\NotificationsController;
use BsCms\Http\Controllers\OpenApiController;
use BsCms\Http\Controllers\PublicEntriesController;
use BsCms\Http\Controllers\PublicSeoController;
use BsCms\Http\Controllers\RedirectsController;
use BsCms\Http\Controllers\RolesController;
use BsCms\Http\Controllers\TaxonomiesController;
use BsCms\Http\Controllers\TokensController;
use BsCms\Http\Controllers\UsersController;
use BsCms\Http\Kernel;
use BsCms\Http\Middleware\AuthenticateMiddleware;
use BsCms\Http\Middleware\ErrorHandlerMiddleware;
use BsCms\Http\Middleware\JsonBodyMiddleware;
use BsCms\Http\Middleware\RequestIdMiddleware;
use BsCms\Http\Middleware\SessionMiddleware;
use BsCms\Kernel\Environment;
use BsCms\Media\ChunkUploadManager;
use BsCms\Media\MediaFolderRepository;
use BsCms\Media\MediaProcessor;
use BsCms\Media\MediaRepository;
use BsCms\Redirects\RedirectPresenter;
use BsCms\Redirects\RedirectRepository;
use BsCms\Routing\ApiRoutes;
use BsCms\Routing\RouteCollection;
use BsCms\Routing\Router;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use BsCms\Schema\SchemaApplier;
use BsCms\Schema\SchemaLock;
use BsCms\Schema\SchemaPlanner;
use BsCms\Security\Ulid;
use BsCms\Seo\SeoPresenter;
use BsCms\Seo\SeoResolver;
use BsCms\Taxonomy\TaxonomyRepository;
use BsCms\Taxonomy\TermRepository;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class DiscoverabilityAndRedirectsHttpIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName;
    private string $tempDir;
    private string $mediaDir;
    private ?PDO $adminPdo = null;

    private RedirectRepository $redirectRepository;
    private SeoResolver $seoResolver;
    private SiteHealthService $healthService;
    private EntryRepository $entryRepository;
    private MediaRepository $mediaRepository;
    private CollectionRegistry $collectionRegistry;

    private Kernel $kernel;
    private Actor $ownerActor;
    private string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $pass = getenv('DB_PASS');
        if ($pass === false) {
            $this->markTestSkipped('Database tests require DB_PASS environment variable.');
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';

        $this->dbName = 'bs_test_disc_' . bin2hex(random_bytes(4));
        $this->adminPdo = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->adminPdo->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->connection = new Connection([
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $this->dbName,
            'username' => $user,
            'password' => $pass,
            'charset' => 'utf8mb4',
            'prefix' => 'bs_',
        ]);

        $this->tempDir = sys_get_temp_dir() . '/bs_disc_test_' . bin2hex(random_bytes(6));
        $this->mediaDir = $this->tempDir . '/media';
        @mkdir($this->mediaDir, 0777, true);

        // Run migrations
        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $migrator->migrate();

        // Schema setup
        $fieldTypes = new FieldTypeRegistry();
        $this->collectionRegistry = new CollectionRegistry($this->connection);
        $planner = new SchemaPlanner($this->connection, $this->collectionRegistry, $fieldTypes);
        $lock = new SchemaLock($this->connection);
        $applier = new SchemaApplier($this->connection, $this->collectionRegistry, $lock);

        $schemaDir = dirname(__DIR__, 3) . '/resources/schema';
        foreach (['pages.json', 'posts.json'] as $file) {
            $path = $schemaDir . '/' . $file;
            if (file_exists($path)) {
                $raw = (array) json_decode((string) file_get_contents($path), true);
                $plan = $planner->plan($raw);
                $applier->apply($plan, $raw);
            }
        }

        // Domain Repositories
        $compiler = new PermissionCompiler($this->connection);
        $authorizer = new Authorizer($this->connection, $compiler);
        $auditLogger = new AuditLogger($this->connection);
        $slugService = new SlugService($this->connection);
        $hierarchyValidator = new HierarchyValidator($this->connection);
        $versionManager = new VersionManager($this->connection);
        $relationManager = new RelationManager($this->connection);

        $this->entryRepository = new EntryRepository(
            $this->connection,
            $this->collectionRegistry,
            $fieldTypes,
            $slugService,
            $hierarchyValidator,
            $versionManager,
            $relationManager
        );

        $mediaProcessor = new MediaProcessor($this->connection, $this->mediaDir);
        $this->mediaRepository = new MediaRepository($this->connection, $mediaProcessor, $this->mediaDir);
        $mediaFolders = new MediaFolderRepository($this->connection);
        $chunkManager = new ChunkUploadManager($this->tempDir . '/tmp');

        $taxonomyRepo = new TaxonomyRepository($this->connection);
        $termRepo = new TermRepository($this->connection);

        $this->redirectRepository = new RedirectRepository($this->connection);
        $this->seoResolver = new SeoResolver(
            $this->connection,
            $this->mediaRepository,
            $taxonomyRepo,
            $termRepo,
            'https://example.com'
        );
        $this->healthService = new SiteHealthService($this->connection);

        // Seed owner user & API token
        $ownerId = Ulid::generate();
        $this->connection->execute(
            "INSERT INTO `{$this->connection->prefixTable('users')}` (`id`, `name`, `email`, `password_hash`, `status`, `created_at`, `updated_at`)
             VALUES (?, 'Owner User', 'owner@example.com', 'dummy_hash', 'active', NOW(), NOW())",
            [$ownerId]
        );
        $this->connection->execute(
            "INSERT INTO `{$this->connection->prefixTable('user_roles')}` (`user_id`, `role_id`) VALUES (?, ?)",
            [$ownerId, SystemRoles::ROLE_OWNER]
        );

        $tokenData = (new ApiTokenService($this->connection, $auditLogger))->create(
            userId: $ownerId,
            name: 'Test Owner Token',
            roleId: SystemRoles::ROLE_OWNER,
            scopes: ['*']
        );
        $this->ownerToken = $tokenData['token'];

        $this->ownerActor = new Actor(
            type: Actor::TYPE_USER,
            id: $ownerId,
            label: 'Owner User',
            onBehalfOf: null,
            roleIds: [SystemRoles::ROLE_OWNER],
            scopes: ['*']
        );

        // Setup HTTP Kernel
        $routes = new RouteCollection();

        $queryParser = new QueryParser();
        $cache = new PublicResponseCache($this->tempDir . '/cache/public');

        $authController = $this->createMock(AuthController::class);
        $meController = $this->createMock(MeController::class);
        $usersController = $this->createMock(UsersController::class);
        $rolesController = $this->createMock(RolesController::class);
        $tokensController = $this->createMock(TokensController::class);
        $auditController = $this->createMock(AuditController::class);
        $emailController = $this->createMock(EmailSettingsController::class);
        $notificationsController = $this->createMock(NotificationsController::class);
        $collectionsController = $this->createMock(CollectionsController::class);
        $entriesController = $this->createMock(EntriesController::class);
        $openApiController = $this->createMock(OpenApiController::class);

        $taxonomiesController = new TaxonomiesController($taxonomyRepo, $termRepo, $authorizer);
        $mediaController = new MediaController($this->mediaRepository, $chunkManager, $authorizer, $this->tempDir . '/tmp');
        $mediaFoldersController = new MediaFoldersController($mediaFolders, $authorizer);

        $redirectsController = new RedirectsController($this->redirectRepository, $authorizer, $auditLogger);
        $publicSeoController = new PublicSeoController($this->seoResolver);
        $siteHealthController = new SiteHealthController($this->healthService, $authorizer);
        $publicEntriesController = new PublicEntriesController(
            $this->connection,
            $this->collectionRegistry,
            $this->entryRepository,
            $queryParser,
            $cache,
            $this->seoResolver
        );

        ApiRoutes::register(
            $routes,
            $authController,
            $meController,
            $usersController,
            $rolesController,
            $tokensController,
            $auditController,
            $emailController,
            $notificationsController,
            $collectionsController,
            $entriesController,
            null,
            null,
            null,
            null,
            null,
            $publicEntriesController,
            $openApiController,
            $taxonomiesController,
            $mediaController,
            $mediaFoldersController,
            $redirectsController,
            $publicSeoController,
            $siteHealthController
        );

        $router = new Router($routes);
        $this->kernel = new Kernel($router);

        $env = new Environment(Environment::TESTING, true);
        $sessionManager = new SessionManager($this->connection);
        $apiTokenService = new ApiTokenService($this->connection, $auditLogger);

        $this->kernel->addMiddleware(new RequestIdMiddleware());
        $this->kernel->addMiddleware(new ErrorHandlerMiddleware($env));
        $this->kernel->addMiddleware(new JsonBodyMiddleware());
        $this->kernel->addMiddleware(new SessionMiddleware($sessionManager, $this->connection));
        $this->kernel->addMiddleware(new AuthenticateMiddleware($apiTokenService));
    }

    protected function tearDown(): void
    {
        if ($this->adminPdo !== null) {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }
        $this->deleteRecursive($this->tempDir);
        parent::tearDown();
    }

    private function deleteRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->deleteRecursive($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /**
     * Helper to issue an authenticated HTTP request through public/index.php kernel.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $uri,
        ?array $body = null,
        array $headers = [],
        bool $authenticated = true
    ): ResponseInterface {
        $req = new ServerRequest($method, $uri);
        $req = $req->withHeader('Accept', 'application/json');

        if ($authenticated) {
            $req = $req->withHeader('Authorization', 'Bearer ' . $this->ownerToken);
        }

        foreach ($headers as $k => $v) {
            $req = $req->withHeader($k, $v);
        }

        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json');
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $stream = fopen('php://temp', 'r+');
            if ($stream !== false) {
                fwrite($stream, $json);
                rewind($stream);
                $req = $req->withBody(new \Nyholm\Psr7\Stream($stream));
            }
            $req = $req->withParsedBody($body);
        }

        return $this->kernel->handle($req);
    }

    // =========================================================================
    // 1. Redirects Endpoints & Matcher Tests
    // =========================================================================

    public function testRedirectsCrudAndExactKeySet(): void
    {
        // 1. Create Exact Redirect -> 201
        $resCreate = $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/legacy-about',
            'to_path' => '/about-us',
            'status_code' => 301,
            'match_type' => 'exact',
        ]);

        $this->assertSame(201, $resCreate->getStatusCode(), (string) $resCreate->getBody());
        $created = json_decode((string) $resCreate->getBody(), true);

        // Exact Key-Set Assertion
        $expectedKeys = [
            'id',
            'from_path',
            'to_path',
            'status_code',
            'match_type',
            'is_auto',
            'hits',
            'last_hit_at',
            'created_by',
            'created_at',
        ];
        sort($expectedKeys);
        $actualKeys = array_keys($created);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys, 'Redirect creation must return exact canonical 10 keys');

        $this->assertSame('/legacy-about', $created['from_path']);
        $this->assertSame('/about-us', $created['to_path']);
        $this->assertSame(301, $created['status_code']);
        $this->assertSame('exact', $created['match_type']);
        $this->assertSame(0, $created['hits']);
        $id = $created['id'];

        // 2. Read Redirect -> 200
        $resGet = $this->request('GET', "/api/v1/redirects/{$id}");
        $this->assertSame(200, $resGet->getStatusCode());
        $fetched = json_decode((string) $resGet->getBody(), true);
        $this->assertSame($id, $fetched['id']);

        // 3. Update Redirect -> 200
        $resPatch = $this->request('PATCH', "/api/v1/redirects/{$id}", [
            'to_path' => '/company/about',
            'status_code' => 308,
        ]);
        $this->assertSame(200, $resPatch->getStatusCode());
        $updated = json_decode((string) $resPatch->getBody(), true);
        $this->assertSame('/company/about', $updated['to_path']);
        $this->assertSame(308, $updated['status_code']);

        // 4. Delete Redirect -> 200
        $resDelete = $this->request('DELETE', "/api/v1/redirects/{$id}");
        $this->assertSame(200, $resDelete->getStatusCode());
        $delBody = json_decode((string) $resDelete->getBody(), true);
        $this->assertTrue($delBody['success']);

        // 5. Subsequent GET -> 404
        $resNotFound = $this->request('GET', "/api/v1/redirects/{$id}");
        $this->assertSame(404, $resNotFound->getStatusCode());
    }

    public function testRedirectLoopAndChainRejections(): void
    {
        // 1. Self-Loop Rejection -> 422
        $resSelfLoop = $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/infinite-loop',
            'to_path' => '/infinite-loop',
            'status_code' => 301,
        ]);
        $this->assertSame(422, $resSelfLoop->getStatusCode());
        $bodySelf = json_decode((string) $resSelfLoop->getBody(), true);
        $this->assertSame('Validation Failed', $bodySelf['title']);

        // 2. Circular Loop Rejection (A -> B, then B -> A) -> 422
        $resA = $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/step-a',
            'to_path' => '/step-b',
            'status_code' => 301,
        ]);
        $this->assertSame(201, $resA->getStatusCode());

        $resB = $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/step-b',
            'to_path' => '/step-a',
            'status_code' => 301,
        ]);
        $this->assertSame(422, $resB->getStatusCode(), 'Circular redirect loop must be rejected with 422');
        $bodyB = json_decode((string) $resB->getBody(), true);
        $this->assertStringContainsString('Redirect loop detected', $bodyB['detail']);

        // 3. Regex Match Type Rejection -> 422 (Strictly exact or prefix allowed)
        $resRegex = $this->request('POST', '/api/v1/redirects', [
            'from_path' => '^/regex-test/(.*)',
            'to_path' => '/clean/$1',
            'match_type' => 'regex',
        ]);
        $this->assertSame(422, $resRegex->getStatusCode(), 'Regex redirects must be rejected with 422');
    }

    public function testPublicRedirectMatcherAndHitCount(): void
    {
        // Create an exact redirect
        $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/pricing-2025',
            'to_path' => '/pricing',
            'status_code' => 301,
            'match_type' => 'exact',
        ]);

        // Create a prefix redirect
        $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/docs-v1/',
            'to_path' => '/v2/docs/',
            'status_code' => 302,
            'match_type' => 'prefix',
        ]);

        // 1. Exact match via public matcher (no auth required)
        $resMatchExact = $this->request('GET', '/api/v1/public/redirects/match?path=/pricing-2025', null, [], false);
        $this->assertSame(200, $resMatchExact->getStatusCode());
        $bodyExact = json_decode((string) $resMatchExact->getBody(), true);
        $this->assertSame('/pricing', $bodyExact['destination']);
        $this->assertSame(301, $bodyExact['status_code']);
        $this->assertSame('exact', $bodyExact['match_type']);
        $exactId = $bodyExact['id'];

        // Verify hits incremented
        $resCheckHits = $this->request('GET', "/api/v1/redirects/{$exactId}");
        $checkHits = json_decode((string) $resCheckHits->getBody(), true);
        $this->assertSame(1, $checkHits['hits']);
        $this->assertNotNull($checkHits['last_hit_at']);

        // 2. Prefix match via public matcher
        $resMatchPrefix = $this->request('GET', '/api/v1/public/redirects/match?path=/docs-v1/installation/guide', null, [], false);
        $this->assertSame(200, $resMatchPrefix->getStatusCode());
        $bodyPrefix = json_decode((string) $resMatchPrefix->getBody(), true);
        $this->assertSame('/v2/docs/installation/guide', $bodyPrefix['destination']);
        $this->assertSame(302, $bodyPrefix['status_code']);
        $this->assertSame('prefix', $bodyPrefix['match_type']);

        // 3. Unmatched path -> 404
        $resNoMatch = $this->request('GET', '/api/v1/public/redirects/match?path=/unregistered-path', null, [], false);
        $this->assertSame(404, $resNoMatch->getStatusCode());
    }

    public function testRedirectsCsvExportAndImport(): void
    {
        // 1. Create a few redirects
        $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/csv-source-1',
            'to_path' => '/csv-dest-1',
            'status_code' => 301,
        ]);
        $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/csv-source-2',
            'to_path' => '/csv-dest-2',
            'status_code' => 307,
            'match_type' => 'prefix',
        ]);

        // 2. Export CSV -> 200 text/csv
        $resExport = $this->request('GET', '/api/v1/redirects/export');
        $this->assertSame(200, $resExport->getStatusCode());
        $this->assertStringContainsString('text/csv', $resExport->getHeaderLine('Content-Type'));
        $csvData = (string) $resExport->getBody();
        $this->assertStringContainsString('from_path,to_path,status_code,match_type', $csvData);
        $this->assertStringContainsString('/csv-source-1,/csv-dest-1,301', $csvData);

        // 3. Import CSV
        $importCsv = "from_path,to_path,status_code,match_type\n/imported-one,/target-one,301,exact\n/imported-two,/target-two,302,prefix\n";
        $resImport = $this->request('POST', '/api/v1/redirects/import', [
            'csv' => $importCsv,
        ]);
        $this->assertSame(200, $resImport->getStatusCode());
        $importBody = json_decode((string) $resImport->getBody(), true);
        $this->assertSame(2, $importBody['imported']);
        $this->assertSame(0, $importBody['skipped']);

        // 4. Verify imported redirect works in matcher
        $resMatchedImport = $this->request('GET', '/api/v1/public/redirects/match?path=/imported-one', null, [], false);
        $this->assertSame(200, $resMatchedImport->getStatusCode());
        $matchedBody = json_decode((string) $resMatchedImport->getBody(), true);
        $this->assertSame('/target-one', $matchedBody['destination']);
    }

    // =========================================================================
    // 2. SEO Resolver Endpoints & Structured Data Tests
    // =========================================================================

    public function testPublicSeoResolverAndStructuredDataGraph(): void
    {
        // 1. Create a published post
        $post = $this->entryRepository->create('posts', [
            'title' => 'Mastering AI Agent Workflows',
            'slug' => 'mastering-ai-agent-workflows',
            'status' => 'published',
            'excerpt' => 'A comprehensive deep dive into autonomous agentic coding workflows.',
            'summary' => 'Answers the architectural principles of multi-turn autonomous coding.',
            'faq' => [
                [
                    'question' => 'What is an autonomous coding agent?',
                    'answer' => 'An AI software engineer that plans, tests, and validates code changes.',
                ],
            ],
            'seo' => [
                'title' => 'Mastering AI Agent Workflows - BS CMS Guide',
                'description' => 'A comprehensive deep dive into autonomous agentic coding workflows.',
                'canonical' => 'https://example.com/posts/mastering-ai-agent-workflows',
                'noindex' => false,
            ],
        ], 'user', 'Owner', $this->ownerActor->id);

        $this->assertNotNull($post['id']);

        // 2. Resolve via Public SEO Endpoint: GET /api/v1/public/seo/resolve?path=/posts/mastering-ai-agent-workflows
        $resSeo = $this->request('GET', '/api/v1/public/seo/resolve?path=/posts/mastering-ai-agent-workflows', null, [], false);
        $this->assertSame(200, $resSeo->getStatusCode(), (string) $resSeo->getBody());
        $this->assertStringContainsString('public, max-age=60', $resSeo->getHeaderLine('Cache-Control'));

        $seoBody = json_decode((string) $resSeo->getBody(), true);
        $this->assertSame('/posts/mastering-ai-agent-workflows', $seoBody['path']);
        $this->assertSame('post', $seoBody['entity_type']);
        $this->assertSame($post['id'], $seoBody['entity_id']);

        // Assert exact key sets on seo_resolved
        $seoResolved = $seoBody['seo_resolved'];
        $expectedTopKeys = ['canonical', 'description', 'open_graph', 'robots', 'title', 'twitter'];
        sort($expectedTopKeys);
        $actualTopKeys = array_keys($seoResolved);
        sort($actualTopKeys);
        $this->assertSame($expectedTopKeys, $actualTopKeys, 'seo_resolved must match canonical 6 top keys');

        $this->assertSame('Mastering AI Agent Workflows - BS CMS Guide', $seoResolved['title']);
        $this->assertSame('https://example.com/posts/mastering-ai-agent-workflows', $seoResolved['canonical']);
        $this->assertFalse($seoResolved['robots']['noindex']);
        $this->assertSame('article', $seoResolved['open_graph']['type']);

        // Assert structured_data (@context, @graph)
        $structured = $seoBody['structured_data'];
        $this->assertSame('https://schema.org', $structured['@context']);
        $this->assertIsArray($structured['@graph']);

        $graphTypes = array_column($structured['@graph'], '@type');
        $this->assertContains('Organization', $graphTypes);
        $this->assertContains('WebSite', $graphTypes);
        $this->assertContains('BreadcrumbList', $graphTypes);
        $this->assertContains('WebPage', $graphTypes);
        $this->assertContains('BlogPosting', $graphTypes);
        $this->assertContains('FAQPage', $graphTypes);

        // 3. Test non-existent path -> 404
        $resNotFound = $this->request('GET', '/api/v1/public/seo/resolve?path=/posts/non-existent-article-slug', null, [], false);
        $this->assertSame(404, $resNotFound->getStatusCode());

        // 4. Test public entries endpoint bySlug attaches seo_resolved and structured_data
        $resEntrySlug = $this->request('GET', '/api/v1/public/collections/posts/entries/by-slug/mastering-ai-agent-workflows', null, [], false);
        $this->assertSame(200, $resEntrySlug->getStatusCode());
        $entryBody = json_decode((string) $resEntrySlug->getBody(), true);
        $this->assertArrayHasKey('seo_resolved', $entryBody['data']);
        $this->assertArrayHasKey('structured_data', $entryBody['data']);
        $this->assertSame('Mastering AI Agent Workflows - BS CMS Guide', $entryBody['data']['seo_resolved']['title']);
    }

    // =========================================================================
    // 3. Site Health Scanner & API Tests
    // =========================================================================

    public function testSiteHealthScannerDetectsIssuesAndProducesReport(): void
    {
        // 1. Seed problem cases:
        // Case A: Post with missing title
        $this->connection->execute(
            "INSERT INTO `bs_c_posts` (`id`, `title`, `slug`, `status`, `created_at`, `updated_at`, `data`, `seo`)
             VALUES (?, '', 'missing-title-slug', 'published', NOW(), NOW(), '{}', '{}')",
            [Ulid::generate()]
        );

        // Case B: Post with over-long title (> 70 chars)
        $longTitle = str_repeat('Extremely Long Article Title That Exceeds The Recommended Seventy Characters Limit ', 2);
        $this->connection->execute(
            "INSERT INTO `bs_c_posts` (`id`, `title`, `slug`, `status`, `created_at`, `updated_at`, `data`, `seo`)
             VALUES (?, ?, 'long-title-slug', 'published', NOW(), NOW(), '{}', '{}')",
            [Ulid::generate(), $longTitle]
        );

        // Case C: Image with missing alt text
        $this->connection->execute(
            "INSERT INTO `bs_media` (`id`, `filename`, `original_name`, `mime`, `extension`, `size_bytes`, `content_hash`, `storage_path`, `created_at`, `updated_at`)
             VALUES (?, 'banner.jpg', 'header_banner.jpg', 'image/jpeg', 'jpg', 1024, 'dummy_hash_1', 'media/test.jpg', NOW(), NOW())",
            [Ulid::generate()]
        );

        // Case D: FAQ with empty answer
        $this->connection->execute(
            "INSERT INTO `bs_c_pages` (`id`, `title`, `slug`, `status`, `created_at`, `updated_at`, `data`, `seo`)
             VALUES (?, 'FAQ Page', 'faq-page', 'published', NOW(), NOW(), ?, '{}')",
            [
                Ulid::generate(),
                json_encode(['faq' => [['question' => 'How does pricing work?', 'answer' => '']]])
            ]
        );

        // 2. HTTP Request to GET /api/v1/site-health
        $resHealth = $this->request('GET', '/api/v1/site-health');
        $this->assertSame(200, $resHealth->getStatusCode(), (string) $resHealth->getBody());

        $report = json_decode((string) $resHealth->getBody(), true);

        // Key set assertion for report
        $expectedReportKeys = ['issues', 'rules_checked', 'scanned_at', 'summary'];
        sort($expectedReportKeys);
        $actualReportKeys = array_keys($report);
        sort($actualReportKeys);
        $this->assertSame($expectedReportKeys, $actualReportKeys);

        $summary = $report['summary'];
        $this->assertGreaterThanOrEqual(1, $summary['errors']);
        $this->assertGreaterThanOrEqual(1, $summary['warnings']);
        $this->assertSame('critical', $summary['status']);

        // Verify detected rule IDs in issues list
        $ruleIds = array_column($report['issues'], 'rule_id');
        $this->assertContains('seo.title.missing', $ruleIds);
        $this->assertContains('seo.title.too_long', $ruleIds);
        $this->assertContains('seo.image.alt.missing', $ruleIds);
        $this->assertContains('geo.faq.empty_answers', $ruleIds);

        // Exact key set assertion for each issue
        $firstIssue = $report['issues'][0];
        $expectedIssueKeys = ['category', 'entity_id', 'entity_title', 'entity_type', 'fix_hint', 'message', 'rule_id', 'severity'];
        sort($expectedIssueKeys);
        $actualIssueKeys = array_keys($firstIssue);
        sort($actualIssueKeys);
        $this->assertSame($expectedIssueKeys, $actualIssueKeys);
    }

    // =========================================================================
    // 4. Media Stream Endpoint Security Headers
    // =========================================================================

    public function testMediaStreamEndpointDeliversSecurityHeadersAndEtag(): void
    {
        // 1. Create a dummy media file
        $fileId = Ulid::generate();
        $storedFilename = "{$fileId}.jpg";
        $diskPath = $this->mediaDir . '/' . $storedFilename;
        file_put_contents($diskPath, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xDB\x00C\x00\xFF\xD9");

        $hash = hash_file('sha256', $diskPath);
        $this->connection->execute(
            "INSERT INTO `bs_media` (`id`, `filename`, `original_name`, `mime`, `extension`, `size_bytes`, `content_hash`, `storage_path`, `created_at`, `updated_at`)
             VALUES (?, ?, 'photo.jpg', 'image/jpeg', 'jpg', ?, ?, ?, NOW(), NOW())",
            [$fileId, $storedFilename, filesize($diskPath), $hash, "media/{$storedFilename}"]
        );

        // 2. Request through public streaming route: GET /api/v1/public/media/{id}/file
        $resStream = $this->request('GET', "/api/v1/public/media/{$fileId}/file", null, [], false);
        $this->assertSame(200, $resStream->getStatusCode());

        // Assert Security Headers
        $this->assertSame('nosniff', $resStream->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $resStream->getHeaderLine('X-Frame-Options'));
        $this->assertSame("default-src 'none'; sandbox", $resStream->getHeaderLine('Content-Security-Policy'));
        $this->assertStringContainsString('public, max-age=31536000', $resStream->getHeaderLine('Cache-Control'));
        $this->assertSame('image/jpeg', $resStream->getHeaderLine('Content-Type'));

        $etag = $resStream->getHeaderLine('ETag');
        $this->assertNotEmpty($etag);

        // 3. Conditional GET with matching ETag -> 304 Not Modified
        $res304 = $this->request('GET', "/api/v1/public/media/{$fileId}/file", null, ['If-None-Match' => $etag], false);
        $this->assertSame(304, $res304->getStatusCode());
    }
}
