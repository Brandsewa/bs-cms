<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Ai;

use BsCms\Ai\AiAccessService;
use BsCms\Ai\AiDisconnectPresenter;
use BsCms\Ai\AiKillSwitchGuard;
use BsCms\Ai\AiReconnectPresenter;
use BsCms\Ai\AiStatusPresenter;
use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Auth\ApiTokenService;
use BsCms\Auth\PasswordHasher;
use BsCms\Auth\SessionManager;
use BsCms\Auth\SystemRoles;
use BsCms\Authz\Authorizer;
use BsCms\Authz\PermissionCompiler;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Http\Controllers\AiController;
use BsCms\Http\Controllers\AuthController;
use BsCms\Http\Kernel;
use BsCms\Http\Middleware\AiKillSwitchMiddleware;
use BsCms\Http\Middleware\AuthenticateMiddleware;
use BsCms\Http\Middleware\CsrfMiddleware;
use BsCms\Http\Middleware\ErrorHandlerMiddleware;
use BsCms\Http\Middleware\JsonBodyMiddleware;
use BsCms\Http\Middleware\RequestIdMiddleware;
use BsCms\Http\Middleware\SessionMiddleware;
use BsCms\Kernel\Config;
use BsCms\Kernel\Environment;
use BsCms\Mcp\McpController;
use BsCms\OAuth\OAuthController;
use BsCms\Routing\ApiRoutes;
use BsCms\Routing\RouteCollection;
use BsCms\Routing\Router;
use BsCms\Security\Ulid;
use BsCms\Settings\SettingsRepository;
use BsCms\Users\RoleRepository;
use BsCms\Users\UserRepository;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class AiKillSwitchHttpIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private ?PDO $adminPdo = null;
    private Kernel $kernel;
    private PasswordHasher $hasher;
    private SettingsRepository $settings;
    private AiAccessService $aiService;
    private AiKillSwitchGuard $aiGuard;
    private OAuthController $oauthController;
    private McpController $mcpController;
    private AiController $aiController;
    private SessionManager $sessionManager;
    private UserRepository $userRepo;

    /** @var array<string, array{id: string, email: string, password: string, cookie: string, csrf: string}> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_ai_test_' . bin2hex(random_bytes(4));

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

        $auditLogger = new AuditLogger($this->connection);
        $roleRepo = new RoleRepository($this->connection);
        $compiler = new PermissionCompiler($this->connection);
        $authorizer = new Authorizer($this->connection, $compiler);
        $this->settings = new SettingsRepository($this->connection);
        $this->aiGuard = new AiKillSwitchGuard($this->settings);
        $this->aiService = new AiAccessService($this->connection, $this->settings, $auditLogger);
        $this->hasher = new PasswordHasher();
        $this->userRepo = new UserRepository($this->connection);
        $this->sessionManager = new SessionManager($this->connection, 3600);

        $config = new Config([
            'app' => ['url' => 'https://example.com'],
            'security' => ['oauth' => ['registration_rate_limit' => ['limit' => 100, 'window' => 3600]]],
        ]);

        $this->oauthController = new OAuthController(
            $this->connection,
            $this->hasher,
            $auditLogger,
            $authorizer,
            $config,
            $this->settings,
            $this->aiGuard
        );

        $apiTokenService = new ApiTokenService($this->connection, $auditLogger);

        $this->mcpController = new McpController(
            $this->connection,
            $this->oauthController,
            $apiTokenService,
            null,
            $authorizer,
            $auditLogger,
            null,
            $this->settings,
            $config,
            null,
            null,
            $this->aiGuard
        );

        $this->aiController = new AiController(
            $this->aiService,
            $this->userRepo,
            $this->hasher
        );

        $routes = new RouteCollection();
        ApiRoutes::register(
            $routes,
            $this->createMock(AuthController::class),
            $this->createMock(\BsCms\Http\Controllers\MeController::class),
            $this->createMock(\BsCms\Http\Controllers\UsersController::class),
            $this->createMock(\BsCms\Http\Controllers\RolesController::class),
            $this->createMock(\BsCms\Http\Controllers\TokensController::class),
            $this->createMock(\BsCms\Http\Controllers\AuditController::class),
            $this->createMock(\BsCms\Http\Controllers\EmailSettingsController::class),
            $this->createMock(\BsCms\Http\Controllers\NotificationsController::class),
            oauth: $this->oauthController,
            mcp: $this->mcpController,
            ai: $this->aiController
        );

        $router = new Router($routes);
        $this->kernel = new Kernel($router);

        $env = new Environment(Environment::TESTING, true);
        $this->kernel->addMiddleware(new RequestIdMiddleware());
        $this->kernel->addMiddleware(new ErrorHandlerMiddleware($env));
        $this->kernel->addMiddleware(new JsonBodyMiddleware());
        $this->kernel->addMiddleware(new AiKillSwitchMiddleware($this->aiGuard));
        $this->kernel->addMiddleware(new SessionMiddleware($this->sessionManager, $this->connection));
        $this->kernel->addMiddleware(new AuthenticateMiddleware($apiTokenService, $this->oauthController));
        $this->kernel->addMiddleware(new CsrfMiddleware('https://example.com'));

        $this->seedUsers();
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

    private function seedUsers(): void
    {
        $pdo = $this->connection->getPdo();
        $roles = [
            'owner' => SystemRoles::ROLE_OWNER,
            'admin' => SystemRoles::ROLE_ADMIN,
            'editor' => SystemRoles::ROLE_EDITOR,
            'author' => SystemRoles::ROLE_AUTHOR,
            'viewer' => SystemRoles::ROLE_VIEWER,
        ];

        foreach ($roles as $handle => $roleId) {
            $userId = Ulid::generate();
            $email = "{$handle}@example.com";
            $password = 'Secret123!';
            $now = date('Y-m-d H:i:s');

            $stmt = $pdo->prepare("INSERT INTO `bs_users` (`id`, `email`, `password_hash`, `name`, `status`, `created_at`, `updated_at`)
                                   VALUES (?, ?, ?, ?, 'active', ?, ?)");
            $stmt->execute([$userId, $email, $this->hasher->hash($password), ucfirst($handle), $now, $now]);

            $rStmt = $pdo->prepare("INSERT INTO `bs_user_roles` (`user_id`, `role_id`) VALUES (?, ?)");
            $rStmt->execute([$userId, $roleId]);

            // Create active session
            $session = $this->sessionManager->create($userId, '127.0.0.1', 'PHPUnit');
            $csrf = (string) $session['csrf_token'];

            $this->users[$handle] = [
                'id' => $userId,
                'email' => $email,
                'password' => $password,
                'cookie' => 'bs_session=' . $session['token'],
                'csrf' => $csrf,
            ];
        }
    }

    /**
     * @param array<string, string> $headers
     * @param mixed $body
     */
    private function request(
        string $method,
        string $path,
        array $headers = [],
        mixed $body = null
    ): ResponseInterface {
        $uri = new Uri("https://example.com{$path}");
        $req = new ServerRequest($method, $uri, $headers);
        $req = $req->withHeader('Host', 'example.com');

        if (isset($headers['Cookie'])) {
            $cookies = [];
            foreach (explode(';', $headers['Cookie']) as $part) {
                $pieces = explode('=', trim($part), 2);
                if (count($pieces) === 2) {
                    $cookies[$pieces[0]] = $pieces[1];
                }
            }
            $req = $req->withCookieParams($cookies);
        }

        if ($body !== null) {
            if (is_array($body)) {
                $req = $req->withHeader('Content-Type', 'application/json');
                $req->getBody()->write((string) json_encode($body));
            } elseif (is_string($body)) {
                $req->getBody()->write($body);
            }
        }

        return $this->kernel->handle($req);
    }

    public function testAiDisconnectPresenterKeySet(): void
    {
        $raw = [
            'status' => 'disconnected',
            'ai_disabled' => true,
            'revoked_grants_count' => 3,
            'revoked_tokens_count' => 2,
            'expired_pending_changes_count' => 5,
            'extra_field_should_be_stripped' => 'secret',
        ];

        $presented = AiDisconnectPresenter::present($raw);

        $expectedKeys = [
            'status',
            'ai_disabled',
            'revoked_grants_count',
            'revoked_tokens_count',
            'expired_pending_changes_count',
        ];

        $actualKeys = array_keys($presented);
        sort($expectedKeys);
        sort($actualKeys);

        $this->assertSame($expectedKeys, $actualKeys);
        $this->assertSame('disconnected', $presented['status']);
        $this->assertTrue($presented['ai_disabled']);
        $this->assertSame(3, $presented['revoked_grants_count']);
        $this->assertSame(2, $presented['revoked_tokens_count']);
        $this->assertSame(5, $presented['expired_pending_changes_count']);
        $this->assertArrayNotHasKey('extra_field_should_be_stripped', $presented);
    }

    public function testAiStatusPresenterKeySet(): void
    {
        $raw = [
            'ai_disabled' => false,
            'active_grants_count' => 4,
            'active_ai_tokens_count' => 2,
            'pending_changes_count' => 1,
            'internal_db_meta' => 'hide_me',
        ];

        $presented = AiStatusPresenter::present($raw);

        $expectedKeys = [
            'ai_disabled',
            'active_grants_count',
            'active_ai_tokens_count',
            'pending_changes_count',
        ];

        $actualKeys = array_keys($presented);
        sort($expectedKeys);
        sort($actualKeys);

        $this->assertSame($expectedKeys, $actualKeys);
        $this->assertFalse($presented['ai_disabled']);
        $this->assertSame(4, $presented['active_grants_count']);
        $this->assertSame(2, $presented['active_ai_tokens_count']);
        $this->assertSame(1, $presented['pending_changes_count']);
        $this->assertArrayNotHasKey('internal_db_meta', $presented);
    }

    public function testAiReconnectPresenterKeySet(): void
    {
        $raw = ['status' => 'connected', 'ai_disabled' => false, 'ignored' => true];
        $presented = AiReconnectPresenter::present($raw);

        $expectedKeys = ['status', 'ai_disabled'];
        $actualKeys = array_keys($presented);
        sort($expectedKeys);
        sort($actualKeys);

        $this->assertSame($expectedKeys, $actualKeys);
        $this->assertSame('connected', $presented['status']);
        $this->assertFalse($presented['ai_disabled']);
    }

    public function testAnonymousGets401OnAllAiEndpoints(): void
    {
        $resStatus = $this->request('GET', '/api/v1/ai/status');
        $this->assertSame(401, $resStatus->getStatusCode());

        $resDisc = $this->request('POST', '/api/v1/ai/disconnect');
        $this->assertSame(401, $resDisc->getStatusCode());

        $resRec = $this->request('POST', '/api/v1/ai/reconnect');
        $this->assertSame(401, $resRec->getStatusCode());
    }

    public function testViewerEditorAuthorCannotCallDisconnectOrReconnect(): void
    {
        foreach (['viewer', 'editor', 'author'] as $role) {
            $user = $this->users[$role];
            $headers = [
                'Cookie' => $user['cookie'],
                'X-CSRF-Token' => $user['csrf'],
                'Origin' => 'https://example.com',
            ];

            // Can call status
            $resStatus = $this->request('GET', '/api/v1/ai/status', $headers);
            $this->assertSame(200, $resStatus->getStatusCode(), "Role {$role} should be able to view AI status");

            // Disconnect forbidden
            $resDisc = $this->request('POST', '/api/v1/ai/disconnect', $headers, ['password' => $user['password']]);
            $this->assertSame(403, $resDisc->getStatusCode(), "Role {$role} must be forbidden from calling disconnect");

            // Reconnect forbidden
            $resRec = $this->request('POST', '/api/v1/ai/reconnect', $headers, ['password' => $user['password']]);
            $this->assertSame(403, $resRec->getStatusCode(), "Role {$role} must be forbidden from calling reconnect");
        }
    }

    public function testStepUpRequirementEnforced(): void
    {
        $admin = $this->users['admin'];
        $headers = [
            'Cookie' => $admin['cookie'],
            'X-CSRF-Token' => $admin['csrf'],
            'Origin' => 'https://example.com',
        ];

        // 1. Without step-up session and without password in body -> 403 Problem Details
        $resNoStepUp = $this->request('POST', '/api/v1/ai/disconnect', $headers, []);
        $this->assertSame(403, $resNoStepUp->getStatusCode());
        $body = json_decode((string) $resNoStepUp->getBody(), true);
        $this->assertSame('Step-Up Authentication Required', $body['title'] ?? '');

        // 2. With wrong password in body -> 403 Problem Details
        $resWrongPass = $this->request('POST', '/api/v1/ai/disconnect', $headers, ['password' => 'WrongPass!']);
        $this->assertSame(403, $resWrongPass->getStatusCode());

        // 3. With correct password in body -> 200 OK
        $resPass = $this->request('POST', '/api/v1/ai/disconnect', $headers, ['password' => $admin['password']]);
        $this->assertSame(200, $resPass->getStatusCode());
        $data = json_decode((string) $resPass->getBody(), true);
        $this->assertSame('disconnected', $data['status']);
        $this->assertTrue($data['ai_disabled']);
    }

    public function testEndToEndKillSwitchLifecycleOverHttp(): void
    {
        $pdo = $this->connection->getPdo();
        $admin = $this->users['admin'];
        $adminHeaders = [
            'Cookie' => $admin['cookie'],
            'X-CSRF-Token' => $admin['csrf'],
            'Origin' => 'https://example.com',
        ];

        // ---------------------------------------------------------------------
        // STEP 1: Verify Initial AI Status (Enabled)
        // ---------------------------------------------------------------------
        $resInitStatus = $this->request('GET', '/api/v1/ai/status', $adminHeaders);
        $this->assertSame(200, $resInitStatus->getStatusCode());
        $initStatus = json_decode((string) $resInitStatus->getBody(), true);
        $this->assertFalse($initStatus['ai_disabled']);
        $this->assertSame(0, $initStatus['active_grants_count']);

        // ---------------------------------------------------------------------
        // STEP 2: Register Client, Authorize, and Obtain OAuth Access Token
        // ---------------------------------------------------------------------
        $resReg = $this->request('POST', '/oauth/register', [], [
            'client_name' => 'Test AI Assistant',
            'redirect_uris' => ['https://example.com/oauth/callback'],
        ]);
        $this->assertSame(201, $resReg->getStatusCode());
        $regData = json_decode((string) $resReg->getBody(), true);
        $clientId = $regData['client_id'];

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(8));

        // Submit consent approval
        $resAuth = $this->request('POST', '/oauth/authorize', [
            'Cookie' => $admin['cookie'],
            'X-CSRF-Token' => $admin['csrf'],
            'Origin' => 'https://example.com',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://example.com/oauth/callback',
            'response_type' => 'code',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            'ai_mode' => 'draft_only',
            'role_id' => SystemRoles::ROLE_ADMIN,
            'scopes' => 'mcp:read mcp:write',
            'password' => $admin['password'],
            'decision' => 'allow',
        ]));

        $this->assertSame(302, $resAuth->getStatusCode());
        $location = $resAuth->getHeaderLine('Location');
        $queryParts = [];
        $queryStr = parse_url($location, PHP_URL_QUERY);
        parse_str(is_string($queryStr) ? $queryStr : '', $queryParts);
        $code = $queryParts['code'];

        // Exchange code for token
        $resToken = $this->request('POST', '/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => 'https://example.com/oauth/callback',
        ]));

        $this->assertSame(200, $resToken->getStatusCode());
        $tokenData = json_decode((string) $resToken->getBody(), true);
        $accessToken = $tokenData['access_token'];
        $refreshToken = $tokenData['refresh_token'];
        $this->assertNotEmpty($accessToken);

        // ---------------------------------------------------------------------
        // STEP 3: Prior to Kill Switch, MCP Works Unimpeded
        // ---------------------------------------------------------------------
        $resMcp = $this->request('POST', '/mcp', [
            'Authorization' => "Bearer {$accessToken}",
        ], [
            'jsonrpc' => '2.0',
            'id' => 101,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18'],
        ]);
        $this->assertSame(200, $resMcp->getStatusCode());
        $mcpInit = json_decode((string) $resMcp->getBody(), true);
        $this->assertSame('2025-06-18', $mcpInit['result']['protocolVersion'] ?? '');

        // Seed 1 active AI API token and 1 pending change created by AI
        $apiTokenId = Ulid::generate();
        $prefix = bin2hex(random_bytes(4));
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $rawApiToken = "bstok_{$prefix}_{$secret}";
        $pdo->prepare("INSERT INTO `bs_api_tokens` (`id`, `user_id`, `role_id`, `name`, `token_prefix`, `token_hash`, `scopes`, `ai_mode`, `created_at`)
                       VALUES (?, ?, ?, 'Test AI Bot Token', ?, ?, '[\"*\"]', 'draft_only', NOW())")
            ->execute([$apiTokenId, $admin['id'], SystemRoles::ROLE_ADMIN, $prefix, hash('sha256', $rawApiToken)]);

        $pendingChangeId = Ulid::generate();
        $pdo->prepare("INSERT INTO `bs_pending_changes` (`id`, `actor_type`, `actor_label`, `on_behalf_of`, `operation`, `subject_type`, `subject_id`, `payload`, `diff`, `status`, `expires_at`, `created_at`)
                       VALUES (?, 'ai_client', 'Test Assistant', ?, 'create_entry', 'entry', 'new', '{}', '{}', 'pending', DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())")
            ->execute([$pendingChangeId, $admin['id']]);

        // Status before disconnect: 1 grant, 1 AI token, 1 pending change
        $resPreStatus = $this->request('GET', '/api/v1/ai/status', $adminHeaders);
        $preStatus = json_decode((string) $resPreStatus->getBody(), true);
        $this->assertFalse($preStatus['ai_disabled']);
        $this->assertSame(1, $preStatus['active_grants_count']);
        $this->assertSame(1, $preStatus['active_ai_tokens_count']);
        $this->assertSame(1, $preStatus['pending_changes_count']);

        // ---------------------------------------------------------------------
        // STEP 4: Activate Kill Switch via POST /api/v1/ai/disconnect
        // ---------------------------------------------------------------------
        $resDisc = $this->request('POST', '/api/v1/ai/disconnect', $adminHeaders, [
            'password' => $admin['password'],
        ]);
        $this->assertSame(200, $resDisc->getStatusCode());
        $discData = json_decode((string) $resDisc->getBody(), true);
        $this->assertSame('disconnected', $discData['status']);
        $this->assertTrue($discData['ai_disabled']);
        $this->assertSame(1, $discData['revoked_grants_count']);
        $this->assertSame(1, $discData['revoked_tokens_count']);
        $this->assertSame(1, $discData['expired_pending_changes_count']);

        // Verify DB updates
        $stmtGrant = $pdo->query("SELECT revoked_at FROM bs_oauth_grants LIMIT 1");
        $this->assertInstanceOf(\PDOStatement::class, $stmtGrant);
        $grantRevokedAt = $stmtGrant->fetchColumn();
        $this->assertNotNull($grantRevokedAt);

        $stmtToken = $pdo->query("SELECT revoked_at FROM bs_oauth_access_tokens WHERE token_hash = '" . hash('sha256', $accessToken) . "'");
        $this->assertInstanceOf(\PDOStatement::class, $stmtToken);
        $tokenRevokedAt = $stmtToken->fetchColumn();
        $this->assertNotNull($tokenRevokedAt);

        $stmtApi = $pdo->query("SELECT revoked_at FROM bs_api_tokens WHERE id = '{$apiTokenId}'");
        $this->assertInstanceOf(\PDOStatement::class, $stmtApi);
        $apiTokenRevokedAt = $stmtApi->fetchColumn();
        $this->assertNotNull($apiTokenRevokedAt);

        $stmtPending = $pdo->query("SELECT status, decision_note FROM bs_pending_changes WHERE id = '{$pendingChangeId}'");
        $this->assertInstanceOf(\PDOStatement::class, $stmtPending);
        /** @var array{status: string, decision_note: string}|false $pendingStatus */
        $pendingStatus = $stmtPending->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($pendingStatus);
        $this->assertSame('rejected', $pendingStatus['status']);
        $this->assertSame('AI access disconnected by administrator', $pendingStatus['decision_note']);

        // ---------------------------------------------------------------------
        // STEP 5: After Switch, Every MCP Call Returns -32000 With No Data
        // ---------------------------------------------------------------------
        $resBlockedMcp = $this->request('POST', '/mcp', [
            'Authorization' => "Bearer {$accessToken}",
        ], [
            'jsonrpc' => '2.0',
            'id' => 999,
            'method' => 'tools/list',
            'params' => [],
        ]);

        $this->assertSame(200, $resBlockedMcp->getStatusCode());
        $blockedBody = json_decode((string) $resBlockedMcp->getBody(), true);
        $this->assertSame('2.0', $blockedBody['jsonrpc']);
        $this->assertSame(999, $blockedBody['id']);
        $this->assertArrayHasKey('error', $blockedBody);
        $this->assertSame(-32000, $blockedBody['error']['code']);
        $this->assertSame('AI access is disabled by administrator.', $blockedBody['error']['message']);
        $this->assertArrayNotHasKey('data', $blockedBody['error'], 'Error response must have no data payload');

        // Personal API token call to /mcp also blocked
        $resBlockedApiToken = $this->request('POST', '/mcp', [
            'Authorization' => "Bearer {$rawApiToken}",
        ], [
            'jsonrpc' => '2.0',
            'id' => 888,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18'],
        ]);
        $this->assertSame(200, $resBlockedApiToken->getStatusCode());
        $apiTokenBlocked = json_decode((string) $resBlockedApiToken->getBody(), true);
        $this->assertSame(-32000, $apiTokenBlocked['error']['code']);

        // ---------------------------------------------------------------------
        // STEP 6: OAuth Authorize, Token, and Register Blocked with 403
        // ---------------------------------------------------------------------
        $resBlockedAuthGet = $this->request('GET', "/oauth/authorize?client_id={$clientId}&response_type=code", $adminHeaders);
        $this->assertSame(403, $resBlockedAuthGet->getStatusCode());
        $authGetBody = json_decode((string) $resBlockedAuthGet->getBody(), true);
        $this->assertSame('access_denied', $authGetBody['error']);

        $resBlockedAuthPost = $this->request('POST', '/oauth/authorize', [
            'Cookie' => $admin['cookie'],
            'X-CSRF-Token' => $admin['csrf'],
            'Origin' => 'https://example.com',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query(['client_id' => $clientId, 'response_type' => 'code']));
        $this->assertSame(403, $resBlockedAuthPost->getStatusCode());

        // Token refresh attempt fails
        $resBlockedRefresh = $this->request('POST', '/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ]));
        $this->assertSame(403, $resBlockedRefresh->getStatusCode());

        // Dynamic registration fails
        $resBlockedReg = $this->request('POST', '/oauth/register', [], [
            'client_name' => 'New Bot',
            'redirect_uris' => ['https://example.com/oauth/cb'],
        ]);
        $this->assertSame(403, $resBlockedReg->getStatusCode());

        // ---------------------------------------------------------------------
        // STEP 7: Revoke Keeps Working So Clients Can Clean Up
        // ---------------------------------------------------------------------
        $resRevoke = $this->request('POST', '/oauth/revoke', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => $clientId,
            'token' => $accessToken,
            'token_type_hint' => 'access_token',
        ]));
        $this->assertSame(200, $resRevoke->getStatusCode());

        // ---------------------------------------------------------------------
        // STEP 8: Idempotency / Double Disconnect is Safe
        // ---------------------------------------------------------------------
        $resDiscAgain = $this->request('POST', '/api/v1/ai/disconnect', $adminHeaders, [
            'password' => $admin['password'],
        ]);
        $this->assertSame(200, $resDiscAgain->getStatusCode());
        $discAgainData = json_decode((string) $resDiscAgain->getBody(), true);
        $this->assertSame('disconnected', $discAgainData['status']);
        $this->assertTrue($discAgainData['ai_disabled']);
        $this->assertSame(0, $discAgainData['revoked_grants_count']);
        $this->assertSame(0, $discAgainData['revoked_tokens_count']);
        $this->assertSame(0, $discAgainData['expired_pending_changes_count']);

        // ---------------------------------------------------------------------
        // STEP 9: Reconnecting Does NOT Resurrect Revoked Grants
        // ---------------------------------------------------------------------
        $resRec = $this->request('POST', '/api/v1/ai/reconnect', $adminHeaders, [
            'password' => $admin['password'],
        ]);
        $this->assertSame(200, $resRec->getStatusCode());
        $recData = json_decode((string) $resRec->getBody(), true);
        $this->assertSame('connected', $recData['status']);
        $this->assertFalse($recData['ai_disabled']);

        // Old grant is still revoked
        $stmtGrantAfter = $pdo->query("SELECT revoked_at FROM bs_oauth_grants LIMIT 1");
        $this->assertInstanceOf(\PDOStatement::class, $stmtGrantAfter);
        $grantRevokedAfter = $stmtGrantAfter->fetchColumn();
        $this->assertNotNull($grantRevokedAfter);

        // Calling /mcp with old revoked token now returns 401 Unauthorized (not -32000)
        $resMcpOldToken = $this->request('POST', '/mcp', [
            'Authorization' => "Bearer {$accessToken}",
        ], [
            'jsonrpc' => '2.0',
            'id' => 301,
            'method' => 'tools/list',
            'params' => [],
        ]);
        $this->assertSame(401, $resMcpOldToken->getStatusCode());
    }

    public function testOAuthTokenCannotCallAiManagementEndpoints(): void
    {
        $pdo = $this->connection->getPdo();
        $admin = $this->users['admin'];

        // Register client & get token
        $resReg = $this->request('POST', '/oauth/register', [], [
            'client_name' => 'OAuth Agent',
            'redirect_uris' => ['https://example.com/cb'],
        ]);
        $clientId = json_decode((string) $resReg->getBody(), true)['client_id'];

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $resAuth = $this->request('POST', '/oauth/authorize', [
            'Cookie' => $admin['cookie'],
            'X-CSRF-Token' => $admin['csrf'],
            'Origin' => 'https://example.com',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://example.com/cb',
            'response_type' => 'code',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'ai_mode' => 'draft_only',
            'role_id' => SystemRoles::ROLE_ADMIN,
            'scopes' => 'mcp:read mcp:write',
            'password' => $admin['password'],
            'decision' => 'allow',
        ]));

        $location = $resAuth->getHeaderLine('Location');
        $q = [];
        $queryStr = parse_url($location, PHP_URL_QUERY);
        parse_str(is_string($queryStr) ? $queryStr : '', $q);
        $code = $q['code'];

        $resToken = $this->request('POST', '/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => 'https://example.com/cb',
        ]));
        $token = json_decode((string) $resToken->getBody(), true)['access_token'];

        // OAuth token accessing /api/v1/ai/disconnect -> 403
        $resDisc = $this->request('POST', '/api/v1/ai/disconnect', [
            'Authorization' => "Bearer {$token}",
        ], ['password' => $admin['password']]);
        $this->assertSame(403, $resDisc->getStatusCode());

        // OAuth token accessing /api/v1/ai/status -> 403
        $resStatus = $this->request('GET', '/api/v1/ai/status', [
            'Authorization' => "Bearer {$token}",
        ]);
        $this->assertSame(403, $resStatus->getStatusCode());
    }
}
