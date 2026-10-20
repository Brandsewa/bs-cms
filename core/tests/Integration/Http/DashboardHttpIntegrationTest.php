<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Http;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\ApiTokenService;
use BsCms\Auth\PasswordHasher;
use BsCms\Auth\SessionManager;
use BsCms\Authz\Authorizer;
use BsCms\Authz\PermissionCompiler;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Health\SiteHealthService;
use BsCms\Http\Controllers\DashboardController;
use BsCms\Http\Kernel;
use BsCms\Http\Middleware\AuthenticateMiddleware;
use BsCms\Http\Middleware\ErrorHandlerMiddleware;
use BsCms\Http\Middleware\JsonBodyMiddleware;
use BsCms\Http\Middleware\RequestIdMiddleware;
use BsCms\Http\Middleware\SessionMiddleware;
use BsCms\Kernel\Application;
use BsCms\Kernel\Config;
use BsCms\Kernel\Environment;
use BsCms\OAuth\OAuthController;
use BsCms\PendingChanges\PendingChangesService;
use BsCms\Routing\ApiRoutes;
use BsCms\Routing\RouteCollection;
use BsCms\Routing\Router;
use BsCms\Schema\CollectionRegistry;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * End-to-end HTTP integration tests for GET /api/v1/dashboard:
 * 1. Anonymous rejected (401)
 * 2. OAuth/MCP token rejected (403)
 * 3. Owner/Admin receives complete payload with coarse telemetry (no leaked paths/env)
 * 4. Editor receives content queues without audit activity or system telemetry
 * 5. Author/Contributor strictly scoped to own content in SQL (isolation verification)
 * 6. Viewer receives read-only summary without audit or pending changes
 * 7. Key-set assertions per role and performance verification
 */
final class DashboardHttpIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private ?PDO $adminPdo = null;
    private Kernel $kernel;
    private string $tempDir;
    private ApiTokenService $apiTokenService;
    private OAuthController $oauthController;

    /** @var array<string, array{token: string, user_id: string, name: string}> */
    private array $actors = [];
    private string $oauthAccessToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_dash_test_' . bin2hex(random_bytes(4));

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

        $this->tempDir = sys_get_temp_dir() . '/bs_dash_tmp_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/logs', 0777, true);
        mkdir($this->tempDir . '/cache', 0777, true);

        $pdo = $this->connection->getPdo();

        // 1. Seed Collections: posts and pages
        $postsColId = Ulid::generate();
        $pagesColId = Ulid::generate();
        $pdo->exec("INSERT INTO `bs_collections` (`id`, `handle`, `label_singular`, `label_plural`, `table_name`, `kind`, `is_system`, `features`, `created_at`, `updated_at`)
                    VALUES ('{$postsColId}', 'posts', 'Post', 'Posts', 'c_posts', 'collection', 0, '{}', NOW(), NOW()),
                           ('{$pagesColId}', 'pages', 'Page', 'Pages', 'c_pages', 'collection', 0, '{}', NOW(), NOW())");

        // 2. Create physical collection tables
        $pdo->exec("CREATE TABLE IF NOT EXISTS `bs_c_posts` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `slug` VARCHAR(191) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `status` VARCHAR(12) NOT NULL DEFAULT 'draft',
            `locale` CHAR(5) NOT NULL DEFAULT 'en',
            `parent_id` CHAR(26) NULL,
            `position` INT NOT NULL DEFAULT 0,
            `author_id` CHAR(26) NULL,
            `published_at` DATETIME(3) NULL,
            `scheduled_at` DATETIME(3) NULL,
            `seo` JSON NULL,
            `data` JSON NOT NULL,
            `search_text` MEDIUMTEXT NULL,
            `created_at` DATETIME(3) NOT NULL,
            `created_by` CHAR(26) NULL,
            `updated_at` DATETIME(3) NOT NULL,
            `updated_by` CHAR(26) NULL,
            `deleted_at` DATETIME(3) NULL,
            KEY `ix_posts_updated` (`updated_at`),
            KEY `ix_posts_status_pub` (`status`, `published_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `bs_c_pages` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `slug` VARCHAR(191) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `status` VARCHAR(12) NOT NULL DEFAULT 'draft',
            `locale` CHAR(5) NOT NULL DEFAULT 'en',
            `parent_id` CHAR(26) NULL,
            `position` INT NOT NULL DEFAULT 0,
            `author_id` CHAR(26) NULL,
            `published_at` DATETIME(3) NULL,
            `scheduled_at` DATETIME(3) NULL,
            `seo` JSON NULL,
            `data` JSON NOT NULL,
            `search_text` MEDIUMTEXT NULL,
            `created_at` DATETIME(3) NOT NULL,
            `created_by` CHAR(26) NULL,
            `updated_at` DATETIME(3) NOT NULL,
            `updated_by` CHAR(26) NULL,
            `deleted_at` DATETIME(3) NULL,
            KEY `ix_pages_updated` (`updated_at`),
            KEY `ix_pages_status_pub` (`status`, `published_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 3. Build services
        $compiler = new PermissionCompiler($this->connection);
        $authorizer = new Authorizer($this->connection, $compiler);
        $collections = new CollectionRegistry($this->connection);
        $auditLogger = new AuditLogger($this->connection);
        $pendingChanges = new PendingChangesService($this->connection, $authorizer);
        $siteHealth = new SiteHealthService($this->connection);
        $config = new Config(['app' => ['env' => 'production', 'name' => 'BS CMS Test']]);

        $dashboardService = new \BsCms\Dashboard\DashboardService(
            $this->connection,
            $collections,
            $siteHealth,
            $config
        );

        $dashboardController = new DashboardController($dashboardService);

        $this->oauthController = new OAuthController(
            $this->connection,
            new PasswordHasher(),
            $auditLogger,
            $authorizer,
            $config
        );

        $dummy = new class () {
        };

        $routes = new RouteCollection();
        ApiRoutes::register(
            $routes,
            $this->createMock(\BsCms\Http\Controllers\AuthController::class),
            $this->createMock(\BsCms\Http\Controllers\MeController::class),
            $this->createMock(\BsCms\Http\Controllers\UsersController::class),
            $this->createMock(\BsCms\Http\Controllers\RolesController::class),
            $this->createMock(\BsCms\Http\Controllers\TokensController::class),
            $this->createMock(\BsCms\Http\Controllers\AuditController::class),
            $this->createMock(\BsCms\Http\Controllers\EmailSettingsController::class),
            $this->createMock(\BsCms\Http\Controllers\NotificationsController::class),
            oauth: $this->oauthController,
            dashboard: $dashboardController
        );

        $router = new Router($routes);
        $sessionManager = new SessionManager($this->connection, 3600);
        $this->apiTokenService = new ApiTokenService($this->connection, $auditLogger);

        $fileLogger = new \BsCms\Log\FileLogger($this->tempDir . '/logs/app.log');
        $this->kernel = new Kernel($router);
        $this->kernel->addMiddleware(new RequestIdMiddleware());
        $this->kernel->addMiddleware(new ErrorHandlerMiddleware(new Environment(Environment::TESTING), $fileLogger));
        $this->kernel->addMiddleware(new JsonBodyMiddleware());
        $this->kernel->addMiddleware(new SessionMiddleware($sessionManager, $this->connection));
        $this->kernel->addMiddleware(new AuthenticateMiddleware($this->apiTokenService, $this->oauthController));

        // 4. Create actor accounts for: owner, admin, editor, author1, author2, contributor, viewer
        $rolesToCreate = [
            'owner' => 'owner',
            'admin' => 'admin',
            'editor' => 'editor',
            'author1' => 'author',
            'author2' => 'author',
            'contributor' => 'contributor',
            'viewer' => 'viewer',
        ];

        foreach ($rolesToCreate as $key => $roleHandle) {
            $name = ucfirst($key) . ' User';
            $this->actors[$key] = $this->createActorWithRole($roleHandle, $name);
        }

        // 5. Create an OAuth access token
        $this->oauthAccessToken = $this->createOAuthAccessToken($this->actors['author1']['user_id']);

        // 6. Seed content items
        // Post 1: created by author1, draft
        $post1Id = Ulid::generate();
        $pdo->exec("INSERT INTO `bs_c_posts` (`id`, `slug`, `title`, `status`, `locale`, `data`, `created_at`, `created_by`, `updated_at`, `updated_by`)
                    VALUES ('{$post1Id}', 'post-author-1', 'Post By Author 1', 'draft', 'en', '{}', NOW(), '{$this->actors['author1']['user_id']}', NOW(), '{$this->actors['author1']['user_id']}')");

        // Post 2: created by author2, draft
        $post2Id = Ulid::generate();
        $pdo->exec("INSERT INTO `bs_c_posts` (`id`, `slug`, `title`, `status`, `locale`, `data`, `created_at`, `created_by`, `updated_at`, `updated_by`)
                    VALUES ('{$post2Id}', 'post-author-2', 'Post By Author 2', 'draft', 'en', '{}', NOW(), '{$this->actors['author2']['user_id']}', NOW(), '{$this->actors['author2']['user_id']}')");

        // Post 3: created by editor, scheduled
        $post3Id = Ulid::generate();
        $pdo->exec("INSERT INTO `bs_c_posts` (`id`, `slug`, `title`, `status`, `locale`, `data`, `scheduled_at`, `created_at`, `created_by`, `updated_at`, `updated_by`)
                    VALUES ('{$post3Id}', 'scheduled-post', 'Scheduled Post', 'scheduled', 'en', '{}', DATE_ADD(NOW(), INTERVAL 2 DAY), NOW(), '{$this->actors['editor']['user_id']}', NOW(), '{$this->actors['editor']['user_id']}')");

        // Page 1: created by admin, published
        $page1Id = Ulid::generate();
        $pdo->exec("INSERT INTO `bs_c_pages` (`id`, `slug`, `title`, `status`, `locale`, `data`, `published_at`, `created_at`, `created_by`, `updated_at`, `updated_by`)
                    VALUES ('{$page1Id}', 'about-us', 'About Us', 'published', 'en', '{}', NOW(), NOW(), '{$this->actors['admin']['user_id']}', NOW(), '{$this->actors['admin']['user_id']}')");

        // 7. Seed pending changes entry (for admin/owner)
        $pcId = Ulid::generate();
        $now = Clock::sql(Clock::now());
        $expiry = date('Y-m-d H:i:s.v', strtotime('+7 days'));
        $pdo->exec("INSERT INTO `bs_pending_changes` (`id`, `actor_type`, `actor_label`, `on_behalf_of`, `operation`, `subject_type`, `subject_id`, `payload`, `status`, `expires_at`, `created_at`)
                    VALUES ('{$pcId}', 'ai_client', 'Claude (via Anna)', '{$this->actors['owner']['user_id']}', 'publish_entry', 'posts', '{$post1Id}', '{}', 'pending', '{$expiry}', '{$now}')");

        // 8. Seed audit log entry
        $pdo->exec("INSERT INTO `bs_audit_log` (`request_id`, `actor_type`, `actor_id`, `actor_label`, `action`, `subject_type`, `subject_id`, `summary`, `result`, `created_at`)
                    VALUES ('req_test_01', 'user', '{$this->actors['owner']['user_id']}', 'Owner User', 'entry.created', 'posts', '{$post1Id}', 'Created Post By Author 1', 'success', NOW())");
    }

    protected function tearDown(): void
    {
        if ($this->adminPdo !== null && $this->dbName !== '') {
            try {
                $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
            } catch (\Throwable) {
            }
        }
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $this->deleteRecursive($this->tempDir);
        }
        parent::tearDown();
    }

    private function deleteRecursive(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            if (is_dir($p)) {
                $this->deleteRecursive($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    /**
     * @return array{token: string, user_id: string, name: string}
     */
    private function createActorWithRole(string $roleHandle, string $name): array
    {
        $userId = Ulid::generate();
        $email = strtolower(str_replace(' ', '_', $name)) . '@test.local';

        $this->connection->execute(
            "INSERT INTO `bs_users` (`id`, `email`, `password_hash`, `status`, `name`, `failed_logins`, `created_at`, `updated_at`)
             VALUES (?, ?, 'hash', 'active', ?, 0, NOW(), NOW())",
            [$userId, $email, $name]
        );

        $roleRow = $this->connection->execute(
            "SELECT `id` FROM `bs_roles` WHERE `handle` = ? LIMIT 1",
            [$roleHandle]
        )->fetch();

        $roleId = $roleRow !== false ? (string) $roleRow['id'] : $roleHandle;

        $this->connection->execute(
            "INSERT INTO `bs_user_roles` (`user_id`, `role_id`) VALUES (?, ?)",
            [$userId, $roleId]
        );

        $tokenResult = $this->apiTokenService->create(
            userId: $userId,
            name: "Token for {$name}",
            roleId: $roleId,
            scopes: ['*']
        );

        return [
            'token' => $tokenResult['token'],
            'user_id' => $userId,
            'name' => $name,
        ];
    }

    private function createOAuthAccessToken(string $userId): string
    {
        $clientId = Ulid::generate();
        $this->connection->execute(
            "INSERT INTO `bs_oauth_clients` (`id`, `client_id`, `name`, `redirect_uris`, `is_public`, `registration_method`, `created_at`)
             VALUES (?, 'test_client', 'Claude Desktop', '[\"https://example.com/cb\"]', 1, 'static', NOW())",
            [$clientId]
        );

        $grantId = Ulid::generate();
        $roleRow = $this->connection->execute("SELECT `id` FROM `bs_roles` WHERE `handle` = 'editor' LIMIT 1")->fetch();
        $roleId = $roleRow !== false ? (string) $roleRow['id'] : 'editor';

        $this->connection->execute(
            "INSERT INTO `bs_oauth_grants` (`id`, `client_id`, `user_id`, `role_id`, `scopes`, `ai_mode`, `created_at`)
             VALUES (?, ?, ?, ?, '[\"mcp:read\",\"mcp:write\"]', 'draft_only', NOW())",
            [$grantId, $clientId, $userId, $roleId]
        );

        $rawToken = 'oa_test_' . bin2hex(random_bytes(16));
        $tokenHash = hash('sha256', $rawToken);
        $expiry = date('Y-m-d H:i:s.v', strtotime('+1 hour'));

        $this->connection->execute(
            "INSERT INTO `bs_oauth_access_tokens` (`token_hash`, `grant_id`, `expires_at`)
             VALUES (?, ?, ?)",
            [$tokenHash, $grantId, $expiry]
        );

        return $rawToken;
    }

    private function sendRequest(string $method, string $uri, ?string $token = null): ResponseInterface
    {
        $request = new ServerRequest($method, $uri);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        return $this->kernel->handle($request);
    }

    public function testAnonymousCannotAccessDashboard401(): void
    {
        $response = $this->sendRequest('GET', '/api/v1/dashboard');
        $this->assertSame(401, $response->getStatusCode());

        $body = (string) $response->getBody();
        $json = json_decode($body, true);
        $this->assertSame(401, $json['status']);
        $this->assertStringContainsString('Authentication is required', $json['detail']);
    }

    public function testOAuthTokenCannotAccessDashboard403(): void
    {
        $response = $this->sendRequest('GET', '/api/v1/dashboard', $this->oauthAccessToken);
        $this->assertSame(403, $response->getStatusCode());

        $body = (string) $response->getBody();
        $json = json_decode($body, true);
        $this->assertSame(403, $json['status']);
        $this->assertStringContainsString('OAuth and MCP tokens cannot access', $json['detail']);
    }

    public function testOwnerAdminReceivesCompletePayloadAndCoarseTelemetry(): void
    {
        $response = $this->sendRequest('GET', '/api/v1/dashboard', $this->actors['owner']['token']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));

        $body = (string) $response->getBody();
        $json = json_decode($body, true);
        $this->assertIsArray($json);

        // Required top-level keys for Admin/Owner
        $expectedKeys = [
            'greeting',
            'counts',
            'recent_edits',
            'drafts_waiting',
            'scheduled_items',
            'pending_ai_changes',
            'site_health',
            'recent_activity',
            'system_status',
            'onboarding',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json, "Missing top-level key: {$key}");
        }

        // Greeting shape
        $this->assertSame('Owner User', $json['greeting']['user_name']);
        $this->assertSame('production', $json['greeting']['environment']);

        // Counts shape
        $this->assertGreaterThanOrEqual(2, $json['counts']['drafts']);
        $this->assertGreaterThanOrEqual(1, $json['counts']['scheduled']);
        $this->assertGreaterThanOrEqual(1, $json['counts']['pending_ai_changes']);

        // Content queues
        $this->assertNotEmpty($json['recent_edits']);
        $this->assertNotEmpty($json['drafts_waiting']);
        $this->assertNotEmpty($json['scheduled_items']);
        $this->assertNotEmpty($json['pending_ai_changes']);

        // Telemetry privacy: coarse values, no paths or hostnames leaked
        $status = $json['system_status'];
        $this->assertContains($status['cron'], ['healthy', 'warning', 'error', 'disabled']);
        $this->assertContains($status['mail'], ['healthy', 'warning', 'error', 'unconfigured']);
        $this->assertIsInt($status['disk_usage_percent']);
        $this->assertGreaterThanOrEqual(0, $status['disk_usage_percent']);
        $this->assertLessThanOrEqual(100, $status['disk_usage_percent']);
        $this->assertSame(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION, $status['php_version']);

        // No paths leaked
        $statusString = is_string($enc = json_encode($status)) ? $enc : '';
        $this->assertStringNotContainsString('C:\\', $statusString);
        $this->assertStringNotContainsString('/home/', $statusString);
        $this->assertStringNotContainsString('/var/', $statusString);
    }

    public function testEditorReceivesContentQueuesWithoutAuditOrSystemTelemetry(): void
    {
        $response = $this->sendRequest('GET', '/api/v1/dashboard', $this->actors['editor']['token']);
        $this->assertSame(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        $json = json_decode($body, true);
        $this->assertIsArray($json);

        // Editor receives content queues
        $this->assertArrayHasKey('greeting', $json);
        $this->assertArrayHasKey('counts', $json);
        $this->assertArrayHasKey('recent_edits', $json);
        $this->assertArrayHasKey('drafts_waiting', $json);
        $this->assertArrayHasKey('scheduled_items', $json);
        $this->assertArrayHasKey('site_health', $json);
        $this->assertArrayHasKey('onboarding', $json);

        // Editor must NOT receive audit activity or system telemetry
        $this->assertArrayNotHasKey('recent_activity', $json, 'Editor should not receive audit activity');
        $this->assertArrayNotHasKey('system_status', $json, 'Editor should not receive system telemetry');
        $this->assertEmpty($json['pending_ai_changes'], 'Editor should not receive pending AI changes');
    }

    public function testAuthor1ScopedToOwnContentInSqlAndCannotSeeAuthor2(): void
    {
        $response1 = $this->sendRequest('GET', '/api/v1/dashboard', $this->actors['author1']['token']);
        $this->assertSame(200, $response1->getStatusCode());
        $json1 = json_decode((string) $response1->getBody(), true);

        // Author receives content queues and site health, but NOT audit or telemetry
        $this->assertArrayNotHasKey('recent_activity', $json1);
        $this->assertArrayNotHasKey('system_status', $json1);
        $this->assertEmpty($json1['pending_ai_changes']);

        // Author 1 should ONLY see Post 1, never Post 2
        $titles1 = array_column($json1['recent_edits'], 'title');
        $this->assertContains('Post By Author 1', $titles1);
        $this->assertNotContains('Post By Author 2', $titles1, 'Author 1 saw Author 2 content!');

        $draftTitles1 = array_column($json1['drafts_waiting'], 'title');
        $this->assertContains('Post By Author 1', $draftTitles1);
        $this->assertNotContains('Post By Author 2', $draftTitles1);
        $this->assertSame(1, $json1['counts']['drafts'], 'Author 1 should only count their own drafts');

        // Author 2 check
        $response2 = $this->sendRequest('GET', '/api/v1/dashboard', $this->actors['author2']['token']);
        $this->assertSame(200, $response2->getStatusCode());
        $json2 = json_decode((string) $response2->getBody(), true);

        // Author 2 should ONLY see Post 2, never Post 1
        $titles2 = array_column($json2['recent_edits'], 'title');
        $this->assertContains('Post By Author 2', $titles2);
        $this->assertNotContains('Post By Author 1', $titles2, 'Author 2 saw Author 1 content!');

        $draftTitles2 = array_column($json2['drafts_waiting'], 'title');
        $this->assertContains('Post By Author 2', $draftTitles2);
        $this->assertNotContains('Post By Author 1', $draftTitles2);
        $this->assertSame(1, $json2['counts']['drafts'], 'Author 2 should only count their own drafts');
    }

    public function testContributorScopedToOwnContentInSql(): void
    {
        // Contributor creates Draft 5
        $pdo = $this->connection->getPdo();
        $contribPostId = Ulid::generate();
        $pdo->exec("INSERT INTO `bs_c_posts` (`id`, `slug`, `title`, `status`, `locale`, `data`, `created_at`, `created_by`, `updated_at`, `updated_by`)
                    VALUES ('{$contribPostId}', 'contrib-draft', 'Contributor Draft', 'draft', 'en', '{}', NOW(), '{$this->actors['contributor']['user_id']}', NOW(), '{$this->actors['contributor']['user_id']}')");

        $response = $this->sendRequest('GET', '/api/v1/dashboard', $this->actors['contributor']['token']);
        $this->assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true);

        $titles = array_column($json['recent_edits'], 'title');
        $this->assertContains('Contributor Draft', $titles);
        $this->assertNotContains('Post By Author 1', $titles);
        $this->assertNotContains('Post By Author 2', $titles);

        $this->assertArrayNotHasKey('recent_activity', $json);
        $this->assertArrayNotHasKey('system_status', $json);
    }

    public function testViewerReceivesReadOnlySummaryWithoutAuditOrPendingChanges(): void
    {
        $response = $this->sendRequest('GET', '/api/v1/dashboard', $this->actors['viewer']['token']);
        $this->assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('greeting', $json);
        $this->assertArrayHasKey('counts', $json);
        $this->assertArrayHasKey('recent_edits', $json);
        $this->assertArrayHasKey('site_health', $json);

        $this->assertArrayNotHasKey('recent_activity', $json);
        $this->assertArrayNotHasKey('system_status', $json);
        $this->assertEmpty($json['pending_ai_changes']);
    }

    public function testPerformanceUnderLoad(): void
    {
        // Warmup request
        $this->sendRequest('GET', '/api/v1/dashboard', $this->actors['admin']['token']);

        $start = microtime(true);
        $response = $this->sendRequest('GET', '/api/v1/dashboard', $this->actors['admin']['token']);
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertSame(200, $response->getStatusCode());
        $this->assertLessThan(100.0, $elapsed, "Dashboard response took {$elapsed}ms, expected < 100ms");
    }
}
