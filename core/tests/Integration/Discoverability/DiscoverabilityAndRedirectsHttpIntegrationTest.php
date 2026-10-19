<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Discoverability;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Auth\ApiTokenService;
use BsCms\Cli\Console;
use BsCms\Kernel\Application;
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
use BsCms\Operations\ConfirmTokenService;
use BsCms\Settings\SettingsRepository;
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
    private SettingsRepository $settingsRepository;
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

        $this->redirectRepository = new RedirectRepository($this->connection);
        $this->settingsRepository = new SettingsRepository($this->connection);

        $this->entryRepository = new EntryRepository(
            $this->connection,
            $this->collectionRegistry,
            $fieldTypes,
            $slugService,
            $hierarchyValidator,
            $versionManager,
            $relationManager,
            null,
            $this->redirectRepository
        );

        $mediaProcessor = new MediaProcessor($this->connection, $this->mediaDir);
        $this->mediaRepository = new MediaRepository($this->connection, $mediaProcessor, $this->mediaDir);
        $mediaFolders = new MediaFolderRepository($this->connection);
        $chunkManager = new ChunkUploadManager($this->tempDir . '/tmp');

        $taxonomyRepo = new TaxonomyRepository($this->connection);
        $termRepo = new TermRepository($this->connection, $this->redirectRepository);

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
        $confirmToken = new ConfirmTokenService('01234567890123456789012345678901');
        $entriesController = new EntriesController(
            $this->collectionRegistry,
            $this->entryRepository,
            $queryParser,
            $versionManager,
            $authorizer,
            $auditLogger,
            $confirmToken
        );
        $openApiController = $this->createMock(OpenApiController::class);

        $taxonomiesController = new TaxonomiesController($taxonomyRepo, $termRepo, $authorizer);
        $mediaController = new MediaController($this->mediaRepository, $chunkManager, $authorizer, $this->tempDir . '/tmp');
        $mediaFoldersController = new MediaFoldersController($mediaFolders, $authorizer);

        $redirectsController = new RedirectsController($this->redirectRepository, $authorizer, $auditLogger, $this->settingsRepository);
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
        $this->assertSame(0, $importBody['errors_count']);
        $this->assertSame(2, $importBody['total']);
        $this->assertCount(2, $importBody['results']);
        $this->assertSame('imported', $importBody['results'][0]['status']);

        // 4. Verify imported redirect works in matcher
        $resMatchedImport = $this->request('GET', '/api/v1/public/redirects/match?path=/imported-one', null, [], false);
        $this->assertSame(200, $resMatchedImport->getStatusCode());
        $matchedBody = json_decode((string) $resMatchedImport->getBody(), true);
        $this->assertSame('/target-one', $matchedBody['destination']);
    }

    public function testExternalAndUnsafeTargetsRejectedByDefault(): void
    {
        // 1. External HTTPS target rejected by default (422)
        $resExt = $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/out-1',
            'to_path' => 'https://evil.example/x',
            'status_code' => 301,
        ]);
        $this->assertSame(422, $resExt->getStatusCode());
        $bodyExt = json_decode((string) $resExt->getBody(), true);
        $this->assertStringContainsString('allow-list setting', (string) json_encode($bodyExt));

        // 2. javascript: URI rejected (422)
        $resJs = $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/out-2',
            'to_path' => 'javascript:alert(1)',
            'status_code' => 301,
        ]);
        $this->assertSame(422, $resJs->getStatusCode());
        $bodyJs = json_decode((string) $resJs->getBody(), true);
        $this->assertStringContainsString('Unsafe redirect target', (string) json_encode($bodyJs));

        // 3. Protocol-relative // rejected (422)
        $resProto = $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/out-3',
            'to_path' => '//evil.example/x',
            'status_code' => 301,
        ]);
        $this->assertSame(422, $resProto->getStatusCode());
        $bodyProto = json_decode((string) $resProto->getBody(), true);
        $this->assertStringContainsString('Protocol-relative', (string) json_encode($bodyProto));

        // 4. data: URI rejected (422)
        $resData = $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/out-4',
            'to_path' => 'data:text/html,<script>alert(1)</script>',
            'status_code' => 301,
        ]);
        $this->assertSame(422, $resData->getStatusCode());

        // 5. External domain allowed when present in allow-list setting
        $this->settingsRepository->set('redirects.allowed_external_domains', ['trusted.example', 'partner.org']);

        $resAllowed = $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/trusted-partner',
            'to_path' => 'https://trusted.example/welcome',
            'status_code' => 302,
        ]);
        $this->assertSame(201, $resAllowed->getStatusCode());
        $allowedBody = json_decode((string) $resAllowed->getBody(), true);
        $this->assertSame('https://trusted.example/welcome', $allowedBody['to_path']);

        // Match public redirect to external target
        $resMatchExt = $this->request('GET', '/api/v1/public/redirects/match?path=/trusted-partner', null, [], false);
        $this->assertSame(200, $resMatchExt->getStatusCode());
        $matchExtBody = json_decode((string) $resMatchExt->getBody(), true);
        $this->assertSame('https://trusted.example/welcome', $matchExtBody['destination']);
    }

    public function testCsvImportPerResultValidationAndSafety(): void
    {
        $csv = implode("\n", [
            'from_path,to_path,status_code,match_type',
            '/csv-valid-1,/valid-dest,301,exact',
            '/csv-ext,https://malicious.example/steal,301,exact',
            '/csv-js,javascript:alert(document.cookie),301,exact',
            '/csv-proto,//cdn.evil.example/malware,302,exact',
        ]);

        $res = $this->request('POST', '/api/v1/redirects/import', ['csv' => $csv]);
        $this->assertSame(200, $res->getStatusCode());
        $data = json_decode((string) $res->getBody(), true);

        $this->assertSame(4, $data['total']);
        $this->assertSame(1, $data['imported']);
        $this->assertSame(3, $data['errors_count']);
        $this->assertCount(4, $data['results']);

        // Row 1 passed
        $this->assertSame(1, $data['results'][0]['row']);
        $this->assertSame('imported', $data['results'][0]['status']);
        $this->assertSame('/csv-valid-1', $data['results'][0]['from_path']);

        // Row 2 rejected (external)
        $this->assertSame(2, $data['results'][1]['row']);
        $this->assertSame('failed', $data['results'][1]['status']);
        $this->assertNotEmpty($data['results'][1]['errors']);

        // Row 3 rejected (javascript)
        $this->assertSame(3, $data['results'][2]['row']);
        $this->assertSame('failed', $data['results'][2]['status']);
        $this->assertNotEmpty($data['results'][2]['errors']);

        // Row 4 rejected (protocol-relative)
        $this->assertSame(4, $data['results'][3]['row']);
        $this->assertSame('failed', $data['results'][3]['status']);
        $this->assertNotEmpty($data['results'][3]['errors']);
    }

    public function testDuplicateFromPathReturns409Conflict(): void
    {
        // 1. Initial creation -> 201
        $resInitial = $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/conflict-source',
            'to_path' => '/target-alpha',
            'status_code' => 301,
            'match_type' => 'exact',
        ]);
        $this->assertSame(201, $resInitial->getStatusCode());

        // 2. Duplicate exact creation -> 409 Conflict (never overwrite silently!)
        $resDuplicate = $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/conflict-source',
            'to_path' => '/target-beta',
            'status_code' => 302,
            'match_type' => 'exact',
        ]);
        $this->assertSame(409, $resDuplicate->getStatusCode());
        $dupBody = json_decode((string) $resDuplicate->getBody(), true);
        $this->assertSame('Conflict', $dupBody['title']);
        $this->assertStringContainsString('already exists', $dupBody['detail']);

        // Confirm original rule remains untouched
        $resMatch = $this->request('GET', '/api/v1/public/redirects/match?path=/conflict-source', null, [], false);
        $this->assertSame(200, $resMatch->getStatusCode());
        $matchBody = json_decode((string) $resMatch->getBody(), true);
        $this->assertSame('/target-alpha', $matchBody['destination']);
    }

    public function testPrefixRedirectMatchesOnlyPathSegmentBoundary(): void
    {
        $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/docs',
            'to_path' => '/manual',
            'match_type' => 'prefix',
            'status_code' => 301,
        ]);

        // Exact prefix boundary
        $resExact = $this->request('GET', '/api/v1/public/redirects/match?path=/docs', null, [], false);
        $this->assertSame(200, $resExact->getStatusCode());
        $this->assertSame('/manual', json_decode((string) $resExact->getBody(), true)['destination']);

        // Subpath segment boundary
        $resSub = $this->request('GET', '/api/v1/public/redirects/match?path=/docs/getting-started', null, [], false);
        $this->assertSame(200, $resSub->getStatusCode());
        $this->assertSame('/manual/getting-started', json_decode((string) $resSub->getBody(), true)['destination']);

        // NON-boundary match (e.g. /docsx/a) MUST NOT MATCH (404)
        $resNonBoundary = $this->request('GET', '/api/v1/public/redirects/match?path=/docsx/a', null, [], false);
        $this->assertSame(404, $resNonBoundary->getStatusCode(), 'Prefix redirect for /docs must NOT match /docsx/a');

        $resNonBoundary2 = $this->request('GET', '/api/v1/public/redirects/match?path=/documentation', null, [], false);
        $this->assertSame(404, $resNonBoundary2->getStatusCode(), 'Prefix redirect for /docs must NOT match /documentation');
    }

    public function testLongPathReturns422ValidationFailedNot500(): void
    {
        $hugeFrom = '/' . str_repeat('a', 3000);
        $resLongFrom = $this->request('POST', '/api/v1/redirects', [
            'from_path' => $hugeFrom,
            'to_path' => '/valid-target',
            'status_code' => 301,
        ]);
        $this->assertSame(422, $resLongFrom->getStatusCode(), 'Long path must return 422, never 500 DB error');
        $body = json_decode((string) $resLongFrom->getBody(), true);
        $this->assertSame('Validation Failed', $body['title']);
        $this->assertArrayHasKey('from_path', $body['errors'] ?? []);

        $hugeTo = '/' . str_repeat('b', 3000);
        $resLongTo = $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/valid-source',
            'to_path' => $hugeTo,
            'status_code' => 301,
        ]);
        $this->assertSame(422, $resLongTo->getStatusCode(), 'Long destination must return 422, never 500 DB error');
        $bodyTo = json_decode((string) $resLongTo->getBody(), true);
        $this->assertSame('Validation Failed', $bodyTo['title']);
        $this->assertArrayHasKey('to_path', $bodyTo['errors'] ?? []);
    }

    public function testAutoRedirectOnPublishedEntrySlugChangeAndTaxonomyMove(): void
    {
        // 1. Entry slug change on published page creates auto-redirect
        $resCreatePage = $this->request('POST', '/api/v1/collections/pages/entries', [
            'title' => 'Initial About Page',
            'slug' => 'old-about-page',
            'status' => 'published',
            'data' => [],
        ]);
        $this->assertSame(201, $resCreatePage->getStatusCode(), (string) $resCreatePage->getBody());
        $page = json_decode((string) $resCreatePage->getBody(), true);
        $pageId = $page['id'];
        $version = (string) ($page['version'] ?? 1);

        // Change slug of the published page
        $resUpdateSlug = $this->request('PATCH', "/api/v1/collections/pages/entries/{$pageId}", [
            'slug' => 'new-about-page',
        ], [
            'If-Match' => '"' . $version . '"',
        ]);
        $this->assertSame(200, $resUpdateSlug->getStatusCode(), (string) $resUpdateSlug->getBody());

        // Verify public match automatically redirects old slug to new slug with 301
        $resMatchPage = $this->request('GET', '/api/v1/public/redirects/match?path=/old-about-page', null, [], false);
        $this->assertSame(200, $resMatchPage->getStatusCode());
        $matchPageBody = json_decode((string) $resMatchPage->getBody(), true);
        $this->assertSame('/new-about-page', $matchPageBody['destination']);
        $this->assertSame(301, $matchPageBody['status_code']);

        // Also collection path redirect /pages/old-about-page -> /pages/new-about-page
        $resMatchCol = $this->request('GET', '/api/v1/public/redirects/match?path=/pages/old-about-page', null, [], false);
        $this->assertSame(200, $resMatchCol->getStatusCode());
        $matchColBody = json_decode((string) $resMatchCol->getBody(), true);
        $this->assertSame('/pages/new-about-page', $matchColBody['destination']);

        // 2. Taxonomy term slug change creates auto-redirect
        $resCreateTax = $this->request('POST', '/api/v1/taxonomies', [
            'name' => 'Topics',
            'handle' => 'topics',
            'hierarchical' => true,
            'has_public_archive' => true,
        ]);
        $this->assertSame(201, $resCreateTax->getStatusCode(), (string) $resCreateTax->getBody());

        $resCreateTerm = $this->request('POST', '/api/v1/taxonomies/topics/terms', [
            'name' => 'Original Topic',
            'slug' => 'original-topic',
        ]);
        $this->assertSame(201, $resCreateTerm->getStatusCode(), (string) $resCreateTerm->getBody());
        $term = json_decode((string) $resCreateTerm->getBody(), true);
        $termId = $term['id'];

        // Update term slug
        $resUpdateTerm = $this->request('PATCH', "/api/v1/taxonomies/topics/terms/{$termId}", [
            'slug' => 'renamed-topic',
        ]);
        $this->assertSame(200, $resUpdateTerm->getStatusCode(), (string) $resUpdateTerm->getBody());

        // Verify public match for taxonomy archive
        $resMatchTerm = $this->request('GET', '/api/v1/public/redirects/match?path=/topics/original-topic', null, [], false);
        $this->assertSame(200, $resMatchTerm->getStatusCode());
        $matchTermBody = json_decode((string) $resMatchTerm->getBody(), true);
        $this->assertSame('/topics/renamed-topic', $matchTermBody['destination']);
        $this->assertSame(301, $matchTermBody['status_code']);
    }

    public function testRedirectChainCollapsesToTerminalTarget(): void
    {
        // Chain: /chain-1 -> /chain-2 -> /chain-3 -> /final-landing
        $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/chain-1',
            'to_path' => '/chain-2',
            'status_code' => 301,
        ]);
        $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/chain-2',
            'to_path' => '/chain-3',
            'status_code' => 301,
        ]);
        $this->request('POST', '/api/v1/redirects', [
            'from_path' => '/chain-3',
            'to_path' => '/final-landing',
            'status_code' => 301,
        ]);

        // Matching /chain-1 MUST directly return /final-landing in 1 hop!
        $resMatch1 = $this->request('GET', '/api/v1/public/redirects/match?path=/chain-1', null, [], false);
        $this->assertSame(200, $resMatch1->getStatusCode());
        $body1 = json_decode((string) $resMatch1->getBody(), true);
        $this->assertSame('/final-landing', $body1['destination']);

        // Matching /chain-2 MUST directly return /final-landing
        $resMatch2 = $this->request('GET', '/api/v1/public/redirects/match?path=/chain-2', null, [], false);
        $this->assertSame(200, $resMatch2->getStatusCode());
        $body2 = json_decode((string) $resMatch2->getBody(), true);
        $this->assertSame('/final-landing', $body2['destination']);
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

        // 3. CLI Command: `bs site:health` verification
        @mkdir($this->tempDir . '/config', 0777, true);
        file_put_contents(
            $this->tempDir . '/config/database.php',
            "<?php return ['database' => '{$this->dbName}', 'username' => '" . (getenv('DB_USER') ?: 'root') . "', 'password' => '" . addslashes((string) getenv('DB_PASS')) . "', 'host' => '" . (getenv('DB_HOST') ?: '127.0.0.1') . "', 'port' => " . ((int) (getenv('DB_PORT') ?: 3306)) . ", 'prefix' => 'bs_'];\n"
        );
        $cliApp = new Application($this->tempDir);
        $console = new Console($cliApp);

        // 3a. JSON mode (`bs site:health --json`)
        ob_start();
        $cliExitCodeJson = $console->run(['bs', 'site:health', '--json']);
        $cliOutputJson = (string) ob_get_clean();

        $this->assertSame(1, $cliExitCodeJson, 'Should return exit code 1 due to critical issues');
        $cliReport = json_decode($cliOutputJson, true);
        $this->assertIsArray($cliReport);
        $cliReportKeys = array_keys($cliReport);
        sort($cliReportKeys);
        $this->assertSame($expectedReportKeys, $cliReportKeys);

        // 3b. Pretty table mode (`bs site:health`)
        ob_start();
        $cliExitCodeText = $console->run(['bs', 'site:health']);
        $cliOutputText = (string) ob_get_clean();

        $this->assertSame(1, $cliExitCodeText);
        $this->assertStringContainsString('=== BS CMS Site Health Scan ===', $cliOutputText);
        $this->assertStringContainsString('Rules checked:', $cliOutputText);
        $this->assertStringContainsString('CRITICAL', $cliOutputText);
        $this->assertStringContainsString('seo.title.missing', $cliOutputText);
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
