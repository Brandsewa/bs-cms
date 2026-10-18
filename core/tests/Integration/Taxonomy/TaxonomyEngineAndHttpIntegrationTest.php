<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Taxonomy;

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
use BsCms\Http\Controllers\CollectionsController;
use BsCms\Http\Controllers\EntriesController;
use BsCms\Http\Controllers\PublicEntriesController;
use BsCms\Http\Controllers\TaxonomiesController;
use BsCms\Http\Kernel;
use BsCms\Http\Middleware\AuthenticateMiddleware;
use BsCms\Http\Middleware\ErrorHandlerMiddleware;
use BsCms\Http\Middleware\JsonBodyMiddleware;
use BsCms\Http\Middleware\RequestIdMiddleware;
use BsCms\Http\Middleware\SessionMiddleware;
use BsCms\Kernel\Environment;
use BsCms\Operations\ConfirmTokenService;
use BsCms\Routing\ApiRoutes;
use BsCms\Routing\RouteCollection;
use BsCms\Routing\Router;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use BsCms\Schema\SchemaApplier;
use BsCms\Schema\SchemaLock;
use BsCms\Schema\SchemaPlanner;
use BsCms\Security\Ulid;
use BsCms\Taxonomy\Jobs\RecomputeTaxonomyCountsJob;
use BsCms\Taxonomy\TaxonomyRepository;
use BsCms\Taxonomy\TermRepository;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class TaxonomyEngineAndHttpIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private ?PDO $adminPdo = null;
    private Kernel $kernel;
    private string $tempDir;

    private TaxonomyRepository $taxonomies;
    private TermRepository $terms;
    private EntryRepository $entries;
    private CollectionRegistry $collections;
    private Authorizer $authorizer;
    private SchemaPlanner $planner;
    private SchemaApplier $applier;
    private string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_tax_test_' . bin2hex(random_bytes(4));

        try {
            $this->adminPdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $this->adminPdo->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            if (getenv('DB_HOST') !== false) {
                $this->fail('Database configured via DB_HOST but unreachable: ' . $e->getMessage());
            }
            $this->markTestSkipped('No local MySQL/MariaDB available.');
        }

        $this->connection = new Connection([
            'host' => $host,
            'port' => $port,
            'database' => $this->dbName,
            'username' => $user,
            'password' => $pass,
            'prefix' => 'bs_',
        ]);

        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $migrator->migrate();

        $this->tempDir = sys_get_temp_dir() . '/bs_tax_test_' . bin2hex(random_bytes(4));
        @mkdir($this->tempDir . '/cache', 0777, true);
        @mkdir($this->tempDir . '/logs', 0777, true);

        $this->taxonomies = new TaxonomyRepository($this->connection);
        $this->terms = new TermRepository($this->connection);

        $cacheFile = $this->tempDir . '/cache/schema.php';
        $this->collections = new CollectionRegistry($this->connection, $cacheFile);
        $fieldTypes = new FieldTypeRegistry();

        $slugService = new SlugService($this->connection);
        $hierarchyValidator = new HierarchyValidator($this->connection);
        $versionManager = new VersionManager($this->connection);
        $relationManager = new RelationManager($this->connection);

        $this->entries = new EntryRepository(
            $this->connection,
            $this->collections,
            $fieldTypes,
            $slugService,
            $hierarchyValidator,
            $versionManager,
            $relationManager
        );

        $compiler = new PermissionCompiler($this->connection);
        $this->authorizer = new Authorizer($this->connection, $compiler);
        $auditLogger = new AuditLogger($this->connection);
        $confirmTokens = new ConfirmTokenService('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef', $this->connection);

        $taxController = new TaxonomiesController(
            $this->taxonomies,
            $this->terms,
            $this->authorizer
        );

        $schemaLock = new SchemaLock($this->connection);
        $this->planner = new SchemaPlanner($this->connection, $this->collections, $fieldTypes);
        $this->applier = new SchemaApplier($this->connection, $this->collections, $schemaLock);

        $collectionsController = new CollectionsController(
            $this->connection,
            $this->collections,
            $this->planner,
            $this->applier,
            $this->authorizer,
            $confirmTokens,
            $auditLogger
        );

        $queryParser = new QueryParser();
        $entriesController = new EntriesController(
            $this->collections,
            $this->entries,
            $queryParser,
            $versionManager,
            $this->authorizer,
            $auditLogger,
            $confirmTokens
        );

        $publicCache = new PublicResponseCache($this->tempDir . '/cache');
        $publicController = new PublicEntriesController(
            $this->connection,
            $this->collections,
            $this->entries,
            $queryParser,
            $publicCache
        );

        // Setup owner actor and API token
        $ownerId = Ulid::generate();
        $this->connection->execute(
            "INSERT INTO `{$this->connection->prefixTable('users')}`
             (`id`, `email`, `password_hash`, `status`, `name`, `failed_logins`, `created_at`, `updated_at`)
             VALUES (?, 'owner@bscms.local', 'hash', 'active', 'Owner', 0, NOW(), NOW())",
            [$ownerId]
        );
        $this->connection->execute(
            "INSERT INTO `{$this->connection->prefixTable('user_roles')}` (`user_id`, `role_id`)
             VALUES (?, ?)",
            [$ownerId, SystemRoles::ROLE_OWNER]
        );

        $tokenService = new ApiTokenService($this->connection, $auditLogger);
        $tokenData = $tokenService->create(
            userId: $ownerId,
            name: 'Test Token',
            roleId: SystemRoles::ROLE_OWNER,
            scopes: ['*'],
            aiMode: 'full'
        );
        $this->ownerToken = $tokenData['token'];

        // Build Router and Kernel
        $routes = new RouteCollection();
        $router = new Router($routes);

        $dummyAuth = new class () extends \BsCms\Http\Controllers\AuthController {
            public function __construct()
            {
            }
        };
        $dummyMe = new class () extends \BsCms\Http\Controllers\MeController {
            public function __construct()
            {
            }
        };
        $dummyUsers = new class () extends \BsCms\Http\Controllers\UsersController {
            public function __construct()
            {
            }
        };
        $dummyRoles = new class () extends \BsCms\Http\Controllers\RolesController {
            public function __construct()
            {
            }
        };
        $dummyTokens = new class () extends \BsCms\Http\Controllers\TokensController {
            public function __construct()
            {
            }
        };
        $dummyAudit = new class () extends \BsCms\Http\Controllers\AuditController {
            public function __construct()
            {
            }
        };
        $dummyEmail = new class () extends \BsCms\Http\Controllers\EmailSettingsController {
            public function __construct()
            {
            }
        };
        $dummyNotif = new class () extends \BsCms\Http\Controllers\NotificationsController {
            public function __construct()
            {
            }
        };

        ApiRoutes::register(
            $routes,
            $dummyAuth,
            $dummyMe,
            $dummyUsers,
            $dummyRoles,
            $dummyTokens,
            $dummyAudit,
            $dummyEmail,
            $dummyNotif,
            collections: $collectionsController,
            entries: $entriesController,
            publicEntries: $publicController,
            taxonomies: $taxController
        );

        $sessionManager = new SessionManager($this->connection, 3600);
        $apiTokenService = new ApiTokenService($this->connection, $auditLogger);

        $fileLogger = new \BsCms\Log\FileLogger($this->tempDir . '/logs/app.log');
        $kernel = new Kernel($router);
        $kernel->addMiddleware(new RequestIdMiddleware());
        $kernel->addMiddleware(new ErrorHandlerMiddleware(new Environment(Environment::TESTING), $fileLogger));
        $kernel->addMiddleware(new JsonBodyMiddleware());
        $kernel->addMiddleware(new SessionMiddleware($sessionManager, $this->connection));
        $kernel->addMiddleware(new AuthenticateMiddleware($apiTokenService));

        $this->kernel = $kernel;
    }

    protected function tearDown(): void
    {
        if ($this->adminPdo !== null && $this->dbName !== '') {
            try {
                $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $uri,
        ?array $body = null,
        bool $auth = true,
        array $headers = []
    ): ResponseInterface {
        $req = new ServerRequest($method, $uri);
        $req = $req->withHeader('Accept', 'application/json');
        if ($auth) {
            $req = $req->withHeader('Authorization', 'Bearer ' . $this->ownerToken);
        }
        foreach ($headers as $k => $v) {
            $req = $req->withHeader($k, $v);
        }
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json');
            $req = $req->withParsedBody($body);
        }
        return $this->kernel->handle($req);
    }

    public function testTaxonomyCrudViaHttp(): void
    {
        // 1. Create hierarchical taxonomy
        $res = $this->request('POST', '/api/v1/taxonomies', [
            'handle' => 'categories',
            'label_singular' => 'Category',
            'label_plural' => 'Categories',
            'hierarchical' => true,
            'max_depth' => 3,
            'has_public_archive' => true,
        ]);
        $this->assertSame(201, $res->getStatusCode());
        /** @var array<string, mixed> $cat */
        $cat = json_decode((string) $res->getBody(), true);
        $this->assertSame('categories', $cat['handle']);
        $this->assertTrue((bool) $cat['hierarchical']);
        $this->assertSame(3, $cat['max_depth']);

        // 2. Get taxonomy
        $res = $this->request('GET', '/api/v1/taxonomies/categories');
        $this->assertSame(200, $res->getStatusCode());

        // 3. Update taxonomy
        $res = $this->request('PATCH', '/api/v1/taxonomies/categories', [
            'label_plural' => 'All Categories',
        ]);
        $this->assertSame(200, $res->getStatusCode());
        /** @var array<string, mixed> $updated */
        $updated = json_decode((string) $res->getBody(), true);
        $this->assertSame('All Categories', $updated['label_plural']);

        // 4. List taxonomies
        $res = $this->request('GET', '/api/v1/taxonomies');
        $this->assertSame(200, $res->getStatusCode());
        /** @var list<mixed> $list */
        $list = json_decode((string) $res->getBody(), true);
        $this->assertCount(1, $list);

        // 5. Delete taxonomy
        $res = $this->request('DELETE', '/api/v1/taxonomies/categories');
        $this->assertSame(200, $res->getStatusCode());

        $res = $this->request('GET', '/api/v1/taxonomies/categories');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testTermTreeOperationsCycleDetectionAndDepthEnforcement(): void
    {
        // Create taxonomy with max_depth=3
        $this->taxonomies->create([
            'handle' => 'categories',
            'hierarchical' => true,
            'max_depth' => 3,
            'has_public_archive' => true,
        ]);

        // 1. Create root term: Electronics
        $res = $this->request('POST', '/api/v1/taxonomies/categories/terms', [
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);
        $this->assertSame(201, $res->getStatusCode());
        /** @var array<string, mixed> $root */
        $root = json_decode((string) $res->getBody(), true);
        $this->assertSame(0, $root['depth']);
        $this->assertSame('/' . $root['id'] . '/', $root['path']);
        $this->assertSame('electronics', $root['slug_path']);

        // 2. Create child term: Computers under Electronics
        $res = $this->request('POST', '/api/v1/taxonomies/categories/terms', [
            'name' => 'Computers',
            'slug' => 'computers',
            'parent_id' => $root['id'],
        ]);
        $this->assertSame(201, $res->getStatusCode());
        /** @var array<string, mixed> $child */
        $child = json_decode((string) $res->getBody(), true);
        $this->assertSame(1, $child['depth']);
        $this->assertSame($root['path'] . $child['id'] . '/', $child['path']);
        $this->assertSame('electronics/computers', $child['slug_path']);

        // 3. Create leaf term: Laptops under Computers (depth 2)
        $res = $this->request('POST', '/api/v1/taxonomies/categories/terms', [
            'name' => 'Laptops',
            'slug' => 'laptops',
            'parent_id' => $child['id'],
        ]);
        $this->assertSame(201, $res->getStatusCode());
        /** @var array<string, mixed> $leaf */
        $leaf = json_decode((string) $res->getBody(), true);
        $this->assertSame(2, $leaf['depth']);
        $this->assertSame('electronics/computers/laptops', $leaf['slug_path']);

        // 4. Create term at depth 3: Gaming Laptops under Laptops
        $res = $this->request('POST', '/api/v1/taxonomies/categories/terms', [
            'name' => 'Gaming Laptops',
            'slug' => 'gaming',
            'parent_id' => $leaf['id'],
        ]);
        $this->assertSame(201, $res->getStatusCode());
        /** @var array<string, mixed> $deep */
        $deep = json_decode((string) $res->getBody(), true);
        $this->assertSame(3, $deep['depth']);

        // 5. Depth constraint: attempt to create child under Gaming Laptops (depth 4 > max_depth 3) -> 422
        $res = $this->request('POST', '/api/v1/taxonomies/categories/terms', [
            'name' => 'RGB Gaming',
            'parent_id' => $deep['id'],
        ]);
        $this->assertSame(422, $res->getStatusCode());

        // 6. Cycle detection: Attempt to move Electronics into its descendant Laptops -> 422
        $res = $this->request('POST', "/api/v1/taxonomies/categories/terms/{$root['id']}/move", [
            'parent_id' => $leaf['id'],
        ]);
        $this->assertSame(422, $res->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $res->getBody(), true);
        $this->assertStringContainsString('descendant', (string) $body['detail']);

        // 7. Move into self: Attempt to move Electronics into Electronics -> 422
        $res = $this->request('POST', "/api/v1/taxonomies/categories/terms/{$root['id']}/move", [
            'parent_id' => $root['id'],
        ]);
        $this->assertSame(422, $res->getStatusCode());

        // 8. Valid Move: Move Laptops to root (parent_id = null)
        $res = $this->request('POST', "/api/v1/taxonomies/categories/terms/{$leaf['id']}/move", [
            'parent_id' => null,
            'position' => 5,
        ]);
        $this->assertSame(200, $res->getStatusCode());
        /** @var array<string, mixed> $movedLeaf */
        $movedLeaf = json_decode((string) $res->getBody(), true);
        $this->assertSame(0, $movedLeaf['depth']);
        $this->assertSame('laptops', $movedLeaf['slug_path']);
        $this->assertSame('/' . $leaf['id'] . '/', $movedLeaf['path']);

        // Verify descendant Gaming Laptops updated path and depth automatically!
        $res = $this->request('GET', "/api/v1/taxonomies/categories/terms/{$deep['id']}");
        $this->assertSame(200, $res->getStatusCode());
        /** @var array<string, mixed> $updatedDeep */
        $updatedDeep = json_decode((string) $res->getBody(), true);
        $this->assertSame(1, $updatedDeep['depth']);
        $this->assertSame('laptops/gaming', $updatedDeep['slug_path']);
        $this->assertSame('/' . $leaf['id'] . '/' . $deep['id'] . '/', $updatedDeep['path']);

        // 9. Update slug updates descendant slug_path
        $res = $this->request('PATCH', "/api/v1/taxonomies/categories/terms/{$leaf['id']}", [
            'slug' => 'notebooks',
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $res = $this->request('GET', "/api/v1/taxonomies/categories/terms/{$deep['id']}");
        /** @var array<string, mixed> $updatedDeep2 */
        $updatedDeep2 = json_decode((string) $res->getBody(), true);
        $this->assertSame('notebooks/gaming', $updatedDeep2['slug_path']);

        // 10. Delete term with children is blocked (Laptops has Gaming)
        $res = $this->request('DELETE', "/api/v1/taxonomies/categories/terms/{$leaf['id']}");
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('child terms', (string) $res->getBody());

        // Delete leaf child first
        $res = $this->request('DELETE', "/api/v1/taxonomies/categories/terms/{$deep['id']}");
        $this->assertSame(200, $res->getStatusCode());

        // Now delete parent
        $res = $this->request('DELETE', "/api/v1/taxonomies/categories/terms/{$leaf['id']}");
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testMergeTermsReassignsRelationships(): void
    {
        $tax = $this->taxonomies->create([
            'handle' => 'tags',
            'hierarchical' => false,
        ]);

        $termA = $this->terms->create((string) $tax['id'], ['name' => 'PHP', 'slug' => 'php']);
        $termB = $this->terms->create((string) $tax['id'], ['name' => 'PHP 8', 'slug' => 'php8']);

        $relTable = $this->connection->prefixTable('term_relationships');
        $entryId = Ulid::generate();
        $this->connection->execute(
            "INSERT INTO `{$relTable}` (`collection_id`, `entry_id`, `term_id`, `is_primary`, `position`, `is_published`, `sort_at`)
             VALUES ('col1', ?, ?, 1, 0, 1, NOW())",
            [$entryId, $termA['id']]
        );

        // Merge termA into termB
        $res = $this->request('POST', "/api/v1/taxonomies/tags/terms/{$termA['id']}/merge", [
            'target_term_id' => $termB['id'],
        ]);
        $this->assertSame(200, $res->getStatusCode());

        // Verify termA is deleted
        $this->assertNull($this->terms->findById($termA['id']));
        // Verify relationship reassigned to termB
        $relStmt = $this->connection->execute("SELECT `term_id` FROM `{$relTable}` WHERE `entry_id` = ?", [$entryId]);
        $this->assertSame($termB['id'], $relStmt->fetchColumn());
    }

    public function testPublicPresenterKeySet(): void
    {
        $tax = $this->taxonomies->create([
            'handle' => 'categories',
            'has_public_archive' => true,
        ]);
        $this->terms->create((string) $tax['id'], ['name' => 'News', 'slug' => 'news']);

        $res = $this->request('GET', '/api/v1/public/taxonomies/categories/terms', auth: false);
        $this->assertSame(200, $res->getStatusCode());
        /** @var list<array<string, mixed>> $body */
        $body = json_decode((string) $res->getBody(), true);
        $this->assertCount(1, $body);

        $term = $body[0];
        $expectedKeys = [
            'id', 'taxonomy_id', 'parent_id', 'name', 'slug', 'slug_path',
            'description', 'depth', 'position', 'image_media_id', 'color', 'icon', 'seo', 'entry_count'
        ];
        sort($expectedKeys);
        $actualKeys = array_keys($term);
        sort($actualKeys);

        $this->assertSame($expectedKeys, $actualKeys, 'Public term presenter must strictly match allowed key set.');
        $this->assertArrayNotHasKey('path', $term, 'Internal materialized path must never be exposed publicly.');
        $this->assertArrayNotHasKey('created_at', $term);
        $this->assertArrayNotHasKey('updated_at', $term);
    }

    public function testEntryLifecycleUpdatesTermCounts(): void
    {
        // 1. Create taxonomy
        $tax = $this->taxonomies->create([
            'handle' => 'topics',
            'hierarchical' => true,
        ]);
        $term = $this->terms->create((string) $tax['id'], ['name' => 'Backend', 'slug' => 'backend']);

        // 2. Create collection with taxonomy field
        $articlesDef = [
            'handle' => 'articles',
            'kind' => 'collection',
            'title_field' => 'title',
            'fields' => [
                'topic' => [
                    'handle' => 'topic',
                    'type' => 'taxonomy',
                    'config' => ['taxonomy' => 'topics'],
                ],
            ],
        ];
        $this->applier->apply($this->planner->plan($articlesDef), $articlesDef);

        // Initial entry count is 0
        $t = $this->terms->findById($term['id']);
        $this->assertNotNull($t);
        $this->assertSame(0, (int) $t['entry_count']);

        // Create draft entry with topic
        $draft = $this->entries->create('articles', [
            'title' => 'Building Fast APIs',
            'topic' => $term['id'],
            'status' => 'draft',
        ]);
        $t = $this->terms->findById($term['id']);
        $this->assertNotNull($t);
        $this->assertSame(0, (int) $t['entry_count'], 'Draft entry must not increment published entry_count');

        // Publish entry -> count becomes 1
        $this->entries->update('articles', $draft['id'], [
            'status' => 'published',
        ], isPatch: true);
        $t = $this->terms->findById($term['id']);
        $this->assertNotNull($t);
        $this->assertSame(1, (int) $t['entry_count'], 'Publishing entry must increment published entry_count to 1');

        // Schedule entry -> count becomes 0
        $this->entries->update('articles', $draft['id'], [
            'status' => 'scheduled',
            'scheduled_at' => date('Y-m-d H:i:s', time() + 3600),
        ], isPatch: true);
        $t = $this->terms->findById($term['id']);
        $this->assertNotNull($t);
        $this->assertSame(0, (int) $t['entry_count'], 'Scheduling entry must decrement published entry_count to 0');

        // Re-publish entry -> count becomes 1
        $this->entries->update('articles', $draft['id'], [
            'status' => 'published',
        ], isPatch: true);
        $t = $this->terms->findById($term['id']);
        $this->assertNotNull($t);
        $this->assertSame(1, (int) $t['entry_count']);

        // Soft-delete (trash) -> count becomes 0
        $this->entries->delete('articles', $draft['id'], permanent: false);
        $t = $this->terms->findById($term['id']);
        $this->assertNotNull($t);
        $this->assertSame(0, (int) $t['entry_count'], 'Trashing entry must decrement published entry_count to 0');

        // Restore -> count becomes 1
        $this->entries->restore('articles', $draft['id']);
        $t = $this->terms->findById($term['id']);
        $this->assertNotNull($t);
        $this->assertSame(1, (int) $t['entry_count'], 'Restoring published entry must restore published entry_count to 1');

        // Unpublish -> count becomes 0
        $this->entries->update('articles', $draft['id'], [
            'status' => 'draft',
        ], isPatch: true);
        $t = $this->terms->findById($term['id']);
        $this->assertNotNull($t);
        $this->assertSame(0, (int) $t['entry_count'], 'Unpublishing entry must decrement published entry_count to 0');

        // Publish and then permanent purge -> count becomes 0
        $this->entries->update('articles', $draft['id'], ['status' => 'published'], isPatch: true);
        $t = $this->terms->findById($term['id']);
        $this->assertNotNull($t);
        $this->assertSame(1, (int) $t['entry_count']);
        $this->entries->delete('articles', $draft['id'], permanent: true);
        $t = $this->terms->findById($term['id']);
        $this->assertNotNull($t);
        $this->assertSame(0, (int) $t['entry_count']);
    }

    public function testNightlyIntegrityRecomputesCounts(): void
    {
        $tax = $this->taxonomies->create(['handle' => 'categories', 'hierarchical' => false]);
        $term = $this->terms->create((string) $tax['id'], ['name' => 'Science', 'slug' => 'science']);

        // Simulate corrupted count in terms table
        $table = $this->connection->prefixTable('terms');
        $this->connection->execute("UPDATE `{$table}` SET `entry_count` = 999 WHERE `id` = ?", [$term['id']]);
        $t = $this->terms->findById($term['id']);
        $this->assertNotNull($t);
        $this->assertSame(999, (int) $t['entry_count']);

        // Run nightly integrity job
        $job = new RecomputeTaxonomyCountsJob($this->connection);
        $job->handle([]);

        $recomputed = $this->terms->findById($term['id']);
        $this->assertNotNull($recomputed);
        $this->assertSame(0, (int) $recomputed['entry_count'], 'Integrity job must recompute accurate entry_count');
    }

    public function testEntriesListTaxonomyFilteringInAndAllWithDescendants(): void
    {
        $tax = $this->taxonomies->create(['handle' => 'genres', 'hierarchical' => true]);
        $rock = $this->terms->create((string) $tax['id'], ['name' => 'Rock', 'slug' => 'rock']);
        $prog = $this->terms->create((string) $tax['id'], ['name' => 'Prog Rock', 'slug' => 'prog', 'parent_id' => $rock['id']]);
        $jazz = $this->terms->create((string) $tax['id'], ['name' => 'Jazz', 'slug' => 'jazz']);

        // Create collection
        $albumsDef = [
            'handle' => 'albums',
            'kind' => 'collection',
            'title_field' => 'title',
            'fields' => [
                'genres' => [
                    'handle' => 'genres',
                    'type' => 'taxonomy',
                    'config' => ['taxonomy' => 'genres', 'multiple' => true],
                ],
            ],
        ];
        $this->applier->apply($this->planner->plan($albumsDef), $albumsDef);

        // Entry 1: Prog Rock only
        $this->entries->create('albums', [
            'title' => 'Close to the Edge',
            'genres' => [$prog['id']],
            'status' => 'published',
        ]);

        // Entry 2: Jazz and Prog Rock
        $this->entries->create('albums', [
            'title' => 'Fusion Experiment',
            'genres' => [$jazz['id'], $prog['id']],
            'status' => 'published',
        ]);

        // Entry 3: Jazz only
        $this->entries->create('albums', [
            'title' => 'Kind of Blue',
            'genres' => [$jazz['id']],
            'status' => 'published',
        ]);

        // 1. Filter[genres][in]=rock WITHOUT include_descendants -> matches 0 entries
        $res = $this->request('GET', '/api/v1/collections/albums/entries?filter[genres][in]=rock');
        $this->assertSame(200, $res->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $res->getBody(), true);
        $this->assertCount(0, (array) $data['data']);

        // 2. Filter[genres][in]=rock WITH include_descendants=true -> matches 2 entries (both Prog Rock entries!)
        $res = $this->request('GET', '/api/v1/collections/albums/entries?filter[genres][in]=rock&include_descendants=true');
        $this->assertSame(200, $res->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $res->getBody(), true);
        $this->assertCount(2, (array) $data['data']);

        // 3. Filter[genres][all]=jazz,prog -> matches 1 entry having BOTH genres
        $res = $this->request('GET', '/api/v1/collections/albums/entries?filter[genres][all]=jazz,prog');
        $this->assertSame(200, $res->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $res->getBody(), true);
        $this->assertCount(1, (array) $data['data']);
        $this->assertSame('Fusion Experiment', $data['data'][0]['title']);

        // 4. Invalid field in filter -> 422
        $res = $this->request('GET', '/api/v1/collections/albums/entries?filter[non_existent_field][eq]=val');
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testExplainSubtreeListingUsesIxTermrelSorted(): void
    {
        $tax = $this->taxonomies->create(['handle' => 'categories', 'hierarchical' => true]);
        $term = $this->terms->create((string) $tax['id'], ['name' => 'Tech', 'slug' => 'tech']);

        $relTable = $this->connection->prefixTable('term_relationships');
        $sql = "EXPLAIN SELECT `entry_id` FROM `{$relTable}`
                WHERE `term_id` = ? AND `collection_id` = ? AND `is_published` = 1
                ORDER BY `sort_at` DESC";

        $stmt = $this->connection->execute($sql, [$term['id'], 'articles']);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($rows);
        $explainRow = $rows[0];

        $keyUsed = (string) ($explainRow['key'] ?? '');
        $possibleKeys = (string) ($explainRow['possible_keys'] ?? '');

        $this->assertTrue(
            str_contains($keyUsed, 'ix_termrel_sorted') || str_contains($possibleKeys, 'ix_termrel_sorted'),
            "Subtree listing query must use ix_termrel_sorted index. Actual key: '{$keyUsed}', possible_keys: '{$possibleKeys}'"
        );
    }
}
