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
use BsCms\Taxonomy\TaxonomyRepository;
use BsCms\Taxonomy\TermRepository;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class TaxonomyMergeAndDeleteIntegrationTest extends TestCase
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
    private string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_tax_fix_' . bin2hex(random_bytes(4));

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
        @mkdir($this->tempDir . '/cache', 0755, true);

        $cacheFile = $this->tempDir . '/cache/schema.php';
        $this->collections = new CollectionRegistry($this->connection, $cacheFile);
        $this->collections->invalidate();

        $fieldTypes = new FieldTypeRegistry();
        $slugService = new SlugService($this->connection);
        $hierarchy = new HierarchyValidator($this->connection);
        $versions = new VersionManager($this->connection);
        $relations = new RelationManager($this->connection);

        $this->entries = new EntryRepository(
            $this->connection,
            $this->collections,
            $fieldTypes,
            $slugService,
            $hierarchy,
            $versions,
            $relations
        );

        $this->taxonomies = new TaxonomyRepository($this->connection);
        $this->terms = new TermRepository($this->connection);

        $compiler = new PermissionCompiler($this->connection);
        $authorizer = new Authorizer($this->connection, $compiler);
        $publicCache = new PublicResponseCache($this->tempDir . '/cache/public_http');

        $planner = new SchemaPlanner($this->connection, $this->collections, $fieldTypes);
        $lock = new SchemaLock($this->connection);
        $applier = new SchemaApplier($this->connection, $this->collections, $lock);
        $confirm = new ConfirmTokenService('01234567890123456789012345678901', $this->connection);
        $auditLogger = new AuditLogger($this->connection);
        $colCtrl = new CollectionsController(
            $this->connection,
            $this->collections,
            $planner,
            $applier,
            $authorizer,
            $confirm,
            $auditLogger
        );
        $queryParser = new QueryParser();
        $entCtrl = new EntriesController(
            $this->collections,
            $this->entries,
            $queryParser,
            $versions,
            $authorizer,
            $auditLogger,
            $confirm
        );
        $pubCtrl = new PublicEntriesController(
            $this->connection,
            $this->collections,
            $this->entries,
            $queryParser,
            $publicCache
        );
        $taxCtrl = new TaxonomiesController($this->taxonomies, $this->terms, $authorizer, $publicCache);

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

        $routes = new RouteCollection();
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
            collections: $colCtrl,
            entries: $entCtrl,
            publicEntries: $pubCtrl,
            taxonomies: $taxCtrl
        );
        $router = new Router($routes);

        $sessionManager = new SessionManager($this->connection, 3600);
        $apiTokenService = new ApiTokenService($this->connection, $auditLogger);

        $fileLogger = new \BsCms\Log\FileLogger($this->tempDir . '/logs/app.log');
        $this->kernel = new Kernel($router);
        $this->kernel->addMiddleware(new RequestIdMiddleware());
        $this->kernel->addMiddleware(new ErrorHandlerMiddleware(new Environment(Environment::TESTING), $fileLogger));
        $this->kernel->addMiddleware(new JsonBodyMiddleware());
        $this->kernel->addMiddleware(new SessionMiddleware($sessionManager, $this->connection));
        $this->kernel->addMiddleware(new AuthenticateMiddleware($apiTokenService));

        $this->ownerToken = $this->createActorToken(SystemRoles::ROLE_OWNER, 'owner', $apiTokenService);

        // Seed a sample collection 'articles' with category taxonomy field
        $this->createArticleCollection($planner, $applier);
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

    private function createActorToken(string $roleId, string $roleName, ApiTokenService $apiTokenService): string
    {
        $userId = Ulid::generate();
        $this->connection->execute(
            "INSERT INTO `{$this->connection->prefixTable('users')}`
             (`id`, `email`, `password_hash`, `status`, `name`, `failed_logins`, `created_at`, `updated_at`)
             VALUES (?, ?, 'hash', 'active', ?, 0, NOW(), NOW())",
            [$userId, "{$roleName}_" . bin2hex(random_bytes(3)) . "@test.local", ucfirst($roleName)]
        );
        $this->connection->execute(
            "INSERT INTO `{$this->connection->prefixTable('user_roles')}` (`user_id`, `role_id`)
             VALUES (?, ?)",
            [$userId, $roleId]
        );

        $tokenData = $apiTokenService->create(
            userId: $userId,
            name: "Token for {$roleName}",
            roleId: $roleId,
            scopes: ['*']
        );
        return $tokenData['token'];
    }

    private function createArticleCollection(SchemaPlanner $planner, SchemaApplier $applier): void
    {
        $def = [
            'handle' => 'articles',
            'kind' => 'collection',
            'title_field' => 'title',
            'label_singular' => 'Article',
            'label_plural' => 'Articles',
            'features' => ['drafts' => true],
            'fields' => [
                'categories' => [
                    'handle' => 'categories',
                    'type' => 'taxonomy',
                    'config' => ['taxonomy' => 'categories', 'multiple' => true],
                ],
            ],
        ];
        $plan = $planner->plan($def);
        $applier->apply($plan, $def);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, ?array $body = null, ?string $token = null): ResponseInterface
    {
        $req = new ServerRequest($method, $uri);
        if ($token !== null) {
            $req = $req->withHeader('Authorization', "Bearer {$token}");
        }
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json');
            $req->getBody()->write((string) json_encode($body));
            $req->getBody()->rewind();
        }
        return $this->kernel->handle($req);
    }

    public function testMergeReassignsRelationshipsDeduplicatesAndCreatesRedirect(): void
    {
        $tax = $this->taxonomies->create([
            'handle' => 'categories',
            'hierarchical' => true,
            'has_public_archive' => true,
        ]);
        $taxId = (string) $tax['id'];

        $sourceTerm = $this->terms->create($taxId, ['name' => 'Legacy Category', 'slug' => 'legacy-cat']);
        $targetTerm = $this->terms->create($taxId, ['name' => 'Modern Category', 'slug' => 'modern-cat']);

        $srcId = (string) $sourceTerm['id'];
        $tgtId = (string) $targetTerm['id'];

        // Entry 1 has SOURCE only (as primary)
        $entry1 = $this->entries->create('articles', [
            'title' => 'Article One',
            'categories' => [$srcId],
            'status' => 'published',
        ]);

        // Entry 2 has BOTH SOURCE and TARGET (source primary)
        $entry2 = $this->entries->create('articles', [
            'title' => 'Article Two',
            'categories' => [$srcId, $tgtId],
            'status' => 'published',
        ]);

        // Entry 3 has TARGET only
        $entry3 = $this->entries->create('articles', [
            'title' => 'Article Three',
            'categories' => [$tgtId],
            'status' => 'published',
        ]);

        // Initial counts check
        $srcFresh = $this->terms->findById($srcId);
        $tgtFresh = $this->terms->findById($tgtId);
        $this->assertNotNull($srcFresh);
        $this->assertNotNull($tgtFresh);
        $this->assertSame(2, $srcFresh['entry_count']);
        $this->assertSame(2, $tgtFresh['entry_count']);

        // Merge SOURCE into TARGET via HTTP
        $res = $this->request('POST', "/api/v1/taxonomies/categories/terms/{$srcId}/merge", [
            'target_term_id' => $tgtId,
        ], $this->ownerToken);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        // 1. Source term must be deleted
        $this->assertNull($this->terms->findById($srcId));

        // 2. Target term entry_count must be updated (all 3 entries now have target)
        $tgtAfter = $this->terms->findById($tgtId);
        $this->assertNotNull($tgtAfter);
        $this->assertSame(3, $tgtAfter['entry_count']);

        // 3. Entry 1 data JSON must have target ID, no source ID
        $e1Fresh = $this->entries->find('articles', (string) $entry1['id']);
        $this->assertNotNull($e1Fresh);
        $this->assertContains($tgtId, $e1Fresh['data']['categories']);
        $this->assertNotContains($srcId, $e1Fresh['data']['categories']);

        // 4. Entry 2 data JSON must have target ID exactly once (deduped), no source ID
        $e2Fresh = $this->entries->find('articles', (string) $entry2['id']);
        $this->assertNotNull($e2Fresh);
        $this->assertContains($tgtId, $e2Fresh['data']['categories']);
        $this->assertNotContains($srcId, $e2Fresh['data']['categories']);
        $this->assertCount(1, $e2Fresh['data']['categories']);

        // 5. Term relationship for entry 2 must have is_primary = 1 (inherited from source)
        $relTable = $this->connection->prefixTable('term_relationships');
        $stmtRel = $this->connection->execute(
            "SELECT `is_primary` FROM `{$relTable}` WHERE `entry_id` = ? AND `term_id` = ?",
            [$entry2['id'], $tgtId]
        );
        $isPrimary = (int) $stmtRel->fetchColumn();
        $this->assertSame(1, $isPrimary, "Primary status must be preserved on deduplicated target relation.");

        // 6. 301 Auto-redirect must be created in redirects table
        $redTable = $this->connection->prefixTable('redirects');
        $stmtRed = $this->connection->execute(
            "SELECT `from_path`, `to_path`, `status_code` FROM `{$redTable}` WHERE `from_path` = ?",
            ['/categories/legacy-cat']
        );
        $redirect = $stmtRed->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($redirect);
        $this->assertSame('/categories/modern-cat', $redirect['to_path']);
        $this->assertSame(301, (int) $redirect['status_code']);
    }

    public function testDeleteTermRequiresReassignToWhenPublishedEntriesExist(): void
    {
        $tax = $this->taxonomies->create(['handle' => 'categories', 'hierarchical' => true]);
        $taxId = (string) $tax['id'];

        $termToDelete = $this->terms->create($taxId, ['name' => 'To Delete', 'slug' => 'to-delete']);
        $termFallback = $this->terms->create($taxId, ['name' => 'Fallback', 'slug' => 'fallback']);

        $delId = (string) $termToDelete['id'];
        $fallId = (string) $termFallback['id'];

        // Create published entry using the term
        $entry = $this->entries->create('articles', [
            'title' => 'Published Article',
            'categories' => [$delId],
            'status' => 'published',
        ]);

        // 1. DELETE without reassign_to must fail with HTTP 422
        $resFail = $this->request('DELETE', "/api/v1/taxonomies/categories/terms/{$delId}", null, $this->ownerToken);
        $this->assertSame(422, $resFail->getStatusCode());
        $body = json_decode((string) $resFail->getBody(), true);
        $this->assertStringContainsString('published entries', $body['detail']);

        // Term must still exist
        $this->assertNotNull($this->terms->findById($delId));

        // 2. DELETE with reassign_to succeeds
        $resOk = $this->request('DELETE', "/api/v1/taxonomies/categories/terms/{$delId}?reassign_to={$fallId}", null, $this->ownerToken);
        $this->assertSame(200, $resOk->getStatusCode(), (string) $resOk->getBody());

        // Term is gone
        $this->assertNull($this->terms->findById($delId));

        // Entry now references fallback term, not deleted term
        $eFresh = $this->entries->find('articles', (string) $entry['id']);
        $this->assertNotNull($eFresh);
        $this->assertContains($fallId, $eFresh['data']['categories']);
        $this->assertNotContains($delId, $eFresh['data']['categories']);

        // 3. No orphan term_relationships exist
        $relTable = $this->connection->prefixTable('term_relationships');
        $stmtOrphan = $this->connection->execute(
            "SELECT COUNT(*) FROM `{$relTable}` WHERE `term_id` = ?",
            [$delId]
        );
        $this->assertSame(0, (int) $stmtOrphan->fetchColumn());
    }

    public function testReservedTaxonomyHandlesForbidsSystemNames(): void
    {
        // Try creating reserved handles
        $reserved = ['users', 'collections', 'auth', 'media', 'api', 'system'];

        foreach ($reserved as $handle) {
            $res = $this->request('POST', '/api/v1/taxonomies', [
                'handle' => $handle,
                'name' => ucfirst($handle),
            ], $this->ownerToken);

            $this->assertSame(422, $res->getStatusCode(), "Creating taxonomy with reserved handle '{$handle}' must return 422.");
        }
    }

    public function testTermSlugValidationStrictAndHostileCharacters(): void
    {
        $tax = $this->taxonomies->create(['handle' => 'categories', 'hierarchical' => true]);
        $taxId = (string) $tax['id'];

        // Hostile name "%_%" -> sanitized slug is empty or rejected, never produces "_" or "%"
        $threw = false;
        try {
            $this->terms->create($taxId, ['name' => '%_%', 'slug' => '']);
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, "Hostile name consisting only of wildcard characters must fail slug generation.");

        // SQL injection string in name
        $termSql = $this->terms->create($taxId, ['name' => "x'; DROP TABLE bs_users;--"]);
        $this->assertDoesNotMatchRegularExpression('/[_%]/', $termSql['slug'], "Slug must not contain underscores or wildcard characters.");
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $termSql['slug']);
    }

    public function testNightlyIntegrityCheckCleansOrphansAndRecomputes(): void
    {
        $tax = $this->taxonomies->create(['handle' => 'categories', 'hierarchical' => true]);
        $taxId = (string) $tax['id'];
        $term = $this->terms->create($taxId, ['name' => 'Integrity Check', 'slug' => 'integrity-check']);
        $termId = (string) $term['id'];

        // Create published entry
        $this->entries->create('articles', [
            'title' => 'Article For Integrity',
            'categories' => [$termId],
            'status' => 'published',
        ]);

        // Artificially corrupt counts in terms table
        $termsTable = $this->connection->prefixTable('terms');
        $this->connection->execute("UPDATE `{$termsTable}` SET `entry_count` = 999 WHERE `id` = ?", [$termId]);

        // Insert orphan term relationship
        $relTable = $this->connection->prefixTable('term_relationships');
        $this->connection->execute(
            "INSERT INTO `{$relTable}` (`term_id`, `collection_id`, `entry_id`, `position`, `is_primary`, `is_published`, `sort_at`)
             VALUES (?, 'nonexistent_col', 'nonexistent_entry', 0, 0, 1, '2026-10-19 00:00:00')",
            [$termId]
        );

        // Run integrity check
        $this->terms->recomputeCounts();

        // Verify counts restored to exactly 1
        $fresh = $this->terms->findById($termId);
        $this->assertNotNull($fresh);
        $this->assertSame(1, $fresh['entry_count']);

        // Verify orphan removed
        $stmtCheck = $this->connection->execute(
            "SELECT COUNT(*) FROM `{$relTable}` WHERE `entry_id` = 'nonexistent_entry'"
        );
        $this->assertSame(0, (int) $stmtCheck->fetchColumn());
    }
}
