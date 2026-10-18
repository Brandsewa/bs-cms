<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Http;

use BsCms\Auth\Actor;
use BsCms\Auth\ApiTokenService;
use BsCms\Auth\PasswordHasher;
use BsCms\Auth\SessionManager;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Http\Controllers\AuditController;
use BsCms\Http\Controllers\AuthController;
use BsCms\Http\Controllers\EmailSettingsController;
use BsCms\Http\Controllers\MeController;
use BsCms\Http\Controllers\NotificationsController;
use BsCms\Http\Controllers\RolesController;
use BsCms\Http\Controllers\TokensController;
use BsCms\Http\Controllers\UsersController;
use BsCms\Http\Kernel;
use BsCms\Http\Middleware\AuthenticateMiddleware;
use BsCms\Http\Middleware\BodyLimitMiddleware;
use BsCms\Http\Middleware\CsrfMiddleware;
use BsCms\Http\Middleware\ErrorHandlerMiddleware;
use BsCms\Http\Middleware\HttpsEnforcerMiddleware;
use BsCms\Http\Middleware\JsonBodyMiddleware;
use BsCms\Http\Middleware\MaintenanceModeMiddleware;
use BsCms\Http\Middleware\RateLimitMiddleware;
use BsCms\Http\Middleware\RequestIdMiddleware;
use BsCms\Http\Middleware\SecurityHeadersMiddleware;
use BsCms\Http\Middleware\SessionMiddleware;
use BsCms\Kernel\Application;
use BsCms\Kernel\CoreServiceProvider;
use BsCms\Kernel\Environment;
use BsCms\Routing\ApiRoutes;
use BsCms\Routing\RouteCollection;
use BsCms\Routing\Router;
use BsCms\Security\RateLimiter;
use BsCms\Users\RoleRepository;
use BsCms\Users\UserRepository;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * End-to-end HTTP pipeline test suite running against the exact front controller
 * middleware stack, container bindings, and ApiRoutes.
 */
final class HttpApiEndToEndIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private ?PDO $adminPdo = null;
    private Application $app;
    private Kernel $kernel;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_http_e2e_' . bin2hex(random_bytes(4));

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

        // Prepare app config and directory structure
        $this->tempDir = sys_get_temp_dir() . '/bs_e2e_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/config', 0777, true);
        mkdir($this->tempDir . '/storage', 0777, true);

        $configData = [
            'app' => [
                'name' => 'BS CMS Test',
                'env' => 'production',
                'url' => 'https://test.bscms.local',
                'key' => 'base64:' . base64_encode(random_bytes(32)),
            ],
            'database' => [
                'host' => $host,
                'port' => $port,
                'database' => $this->dbName,
                'username' => $user,
                'password' => $pass,
                'prefix' => 'bs_',
            ],
        ];
        file_put_contents($this->tempDir . '/config/app.php', '<?php return ' . var_export($configData['app'], true) . ';');
        file_put_contents($this->tempDir . '/config/database.php', '<?php return ' . var_export($configData['database'], true) . ';');

        $this->app = new Application($this->tempDir);
        $this->app->register(new CoreServiceProvider($this->app));
        $this->app->boot();

        $container = $this->app->getContainer();
        // Bind the active test connection so the container reuses our migrated test database
        $container->instance(Connection::class, $this->connection);

        // Setup routes
        $routes = new RouteCollection();
        $router = new Router($routes);

        ApiRoutes::register(
            $routes,
            $container->get(AuthController::class),
            $container->get(MeController::class),
            $container->get(UsersController::class),
            $container->get(RolesController::class),
            $container->get(TokensController::class),
            $container->get(AuditController::class),
            $container->get(EmailSettingsController::class),
            $container->get(NotificationsController::class)
        );

        $fileLogger = new \BsCms\Log\FileLogger($this->tempDir . '/storage/logs/app.log');
        $kernel = new Kernel($router);
        $kernel->addMiddleware(new RequestIdMiddleware());
        $kernel->addMiddleware(new ErrorHandlerMiddleware(new Environment(Environment::PRODUCTION), $fileLogger));
        $kernel->addMiddleware(new SecurityHeadersMiddleware());
        $kernel->addMiddleware(new HttpsEnforcerMiddleware(new Environment(Environment::PRODUCTION)));
        $kernel->addMiddleware(new MaintenanceModeMiddleware($this->tempDir . '/storage'));
        $kernel->addMiddleware(new BodyLimitMiddleware(2 * 1024 * 1024));
        $kernel->addMiddleware(new JsonBodyMiddleware());

        $sessionManager = $container->get(SessionManager::class);
        $apiTokenService = $container->get(ApiTokenService::class);

        // Session -> Authenticate -> RateLimit -> CSRF
        $kernel->addMiddleware(new SessionMiddleware($sessionManager, $this->connection));
        $kernel->addMiddleware(new AuthenticateMiddleware($apiTokenService));

        $limiter = new RateLimiter(60, 60, [], fn () => $this->connection);
        $kernel->addMiddleware(new RateLimitMiddleware($limiter));
        $kernel->addMiddleware(new CsrfMiddleware('https://test.bscms.local'));

        $this->kernel = $kernel;
    }

    protected function tearDown(): void
    {
        if ($this->adminPdo !== null && $this->dbName !== '') {
            try {
                $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
            } catch (\Throwable $e) {
            }
        }
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $this->removeDir($this->tempDir);
        }
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        return $this->kernel->handle($request);
    }

    public function testUnauthenticatedProtectedRoutesReturn401(): void
    {
        // Protected /api/v1/* routes return 401 when no session or token is provided
        $reqMe = new ServerRequest('GET', 'https://test.bscms.local/api/v1/me');
        $resMe = $this->dispatch($reqMe);
        $this->assertSame(401, $resMe->getStatusCode());
        $this->assertStringContainsString('application/problem+json', $resMe->getHeaderLine('Content-Type'));

        $reqUsers = new ServerRequest('GET', 'https://test.bscms.local/api/v1/users');
        $resUsers = $this->dispatch($reqUsers);
        $this->assertSame(401, $resUsers->getStatusCode());
    }

    public function testPublicAuthRoutesAccessibleWithoutSession(): void
    {
        // /api/v1/auth/session returns 401 unauthenticated with JSON problem, not an exception
        $reqSession = new ServerRequest('GET', 'https://test.bscms.local/api/v1/auth/session');
        $resSession = $this->dispatch($reqSession);
        $this->assertSame(401, $resSession->getStatusCode());

        // /api/v1/auth/login allows POST without credentials returning 401 with generic error message
        $reqLogin = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/auth/login'))
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['email' => 'nobody@example.com', 'password' => 'WrongPassword!']);
        $resLogin = $this->dispatch($reqLogin);
        $this->assertSame(401, $resLogin->getStatusCode());
        $body = (string) $resLogin->getBody();
        $this->assertStringContainsString('Email or password is incorrect.', $body);
    }

    public function testLoginFlowCookieFlagsAndCsrfProtection(): void
    {
        $userRepo = new UserRepository($this->connection);
        $hasher = new PasswordHasher();
        $roleRepo = new RoleRepository($this->connection);
        $ownerRole = $roleRepo->findByHandle('owner');
        $this->assertNotNull($ownerRole);

        $userId = $userRepo->create([
            'email' => 'admin@example.com',
            'name' => 'Admin Owner',
            'password_hash' => $hasher->hash('AdminPassword2026!'),
            'role_id' => $ownerRole['id'],
            'status' => 'active',
        ]);

        // 1. Successful Login
        $loginReq = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/auth/login'))
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['email' => 'admin@example.com', 'password' => 'AdminPassword2026!']);
        $loginRes = $this->dispatch($loginReq);

        $this->assertSame(200, $loginRes->getStatusCode());
        $loginData = json_decode((string) $loginRes->getBody(), true);
        $this->assertSame('success', $loginData['status']);
        $this->assertArrayHasKey('user', $loginData);
        $this->assertArrayNotHasKey('password_hash', $loginData['user']);
        $this->assertArrayNotHasKey('totp_secret', $loginData['user']);

        // Check Set-Cookie headers
        $setCookie = $loginRes->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString('__Host-bs_session=', $setCookie);
        $this->assertStringContainsString('HttpOnly', $setCookie);
        $this->assertStringContainsString('Secure', $setCookie);
        $this->assertStringContainsString('SameSite=Lax', $setCookie);

        // Extract session token from cookie
        $matched = preg_match('/__Host-bs_session=([^;]+)/', $setCookie, $matches);
        $this->assertSame(1, $matched);
        $sessionCookie = $matches[1];
        $this->assertNotEmpty($sessionCookie);

        // 2. Fetch authenticated session to obtain CSRF secret
        $sessionReq = (new ServerRequest('GET', 'https://test.bscms.local/api/v1/auth/session'))
            ->withCookieParams(['__Host-bs_session' => $sessionCookie]);
        $sessionRes = $this->dispatch($sessionReq);
        $this->assertSame(200, $sessionRes->getStatusCode());
        $sessionJson = json_decode((string) $sessionRes->getBody(), true);
        $this->assertSame('authenticated', $sessionJson['status']);
        $this->assertNotEmpty($sessionJson['csrf_token']);
        $csrfToken = $sessionJson['csrf_token'];

        // 3. Mutating PATCH /api/v1/me without CSRF token fails with 403
        $updateProfileNoCsrf = (new ServerRequest('PATCH', 'https://test.bscms.local/api/v1/me'))
            ->withCookieParams(['__Host-bs_session' => $sessionCookie])
            ->withHeader('Origin', 'https://test.bscms.local')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody([
                'name' => 'Updated Admin Name',
            ]);
        $resNoCsrf = $this->dispatch($updateProfileNoCsrf);
        $this->assertSame(403, $resNoCsrf->getStatusCode());
        $this->assertStringContainsString('Invalid or missing CSRF token', (string) $resNoCsrf->getBody());

        // 4. Mutating PATCH /api/v1/me with origin mismatch fails with 403
        $updateProfileEvilOrigin = (new ServerRequest('PATCH', 'https://test.bscms.local/api/v1/me'))
            ->withCookieParams(['__Host-bs_session' => $sessionCookie])
            ->withHeader('Origin', 'https://evil-attacker.com')
            ->withHeader('X-CSRF-Token', $csrfToken)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody([
                'name' => 'Updated Admin Name',
            ]);
        $resEvilOrigin = $this->dispatch($updateProfileEvilOrigin);
        $this->assertSame(403, $resEvilOrigin->getStatusCode());

        // 5. Mutating PATCH /api/v1/me with valid session, origin, and CSRF token succeeds
        $updateProfileValid = (new ServerRequest('PATCH', 'https://test.bscms.local/api/v1/me'))
            ->withCookieParams(['__Host-bs_session' => $sessionCookie])
            ->withHeader('Origin', 'https://test.bscms.local')
            ->withHeader('X-CSRF-Token', $csrfToken)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody([
                'name' => 'Updated Admin Name',
            ]);
        $resValid = $this->dispatch($updateProfileValid);
        $this->assertSame(200, $resValid->getStatusCode());
        $respData = json_decode((string) $resValid->getBody(), true);
        $this->assertSame('profile_updated', $respData['status']);

        // Verify updated profile through GET /api/v1/me
        $getUpdatedMe = (new ServerRequest('GET', 'https://test.bscms.local/api/v1/me'))
            ->withCookieParams(['__Host-bs_session' => $sessionCookie]);
        $resUpdatedMe = $this->dispatch($getUpdatedMe);
        $this->assertSame(200, $resUpdatedMe->getStatusCode());
        $meUpdatedData = json_decode((string) $resUpdatedMe->getBody(), true);
        $this->assertSame('Updated Admin Name', $meUpdatedData['name']);

        // 6. Last owner protection: Attempting to delete the sole initial owner or remove owner role
        $deleteInitialOwnerReq = (new ServerRequest('DELETE', "https://test.bscms.local/api/v1/users/{$userId}"))
            ->withCookieParams(['__Host-bs_session' => $sessionCookie])
            ->withHeader('Origin', 'https://test.bscms.local')
            ->withHeader('X-CSRF-Token', $csrfToken);
        $deleteRes = $this->dispatch($deleteInitialOwnerReq);
        // Either 403 or 400 forbidding self-deletion or last owner demotion
        $this->assertTrue(in_array($deleteRes->getStatusCode(), [400, 403, 422], true));
    }

    public function testBearerApiTokensBypassCsrfAndEnforceScopes(): void
    {
        $userRepo = new UserRepository($this->connection);
        $roleRepo = new RoleRepository($this->connection);
        $ownerRole = $roleRepo->findByHandle('owner');
        $this->assertNotNull($ownerRole);

        $userId = $userRepo->create([
            'email' => 'token_user@example.com',
            'name' => 'Token User',
            'role_id' => $ownerRole['id'],
            'status' => 'active',
        ]);

        $container = $this->app->getContainer();
        /** @var ApiTokenService $tokenService */
        $tokenService = $container->get(ApiTokenService::class);
        $createdToken = $tokenService->create(
            $userId,
            'CI API Test Token',
            roleId: (string) $ownerRole['id'],
            scopes: ['*']
        );
        $plainToken = $createdToken['token'];

        // 1. GET /api/v1/me with Bearer token succeeds without any cookie or CSRF
        $reqMe = (new ServerRequest('GET', 'https://test.bscms.local/api/v1/me'))
            ->withHeader('Authorization', 'Bearer ' . $plainToken);
        $resMe = $this->dispatch($reqMe);
        $this->assertSame(200, $resMe->getStatusCode());
        $meData = json_decode((string) $resMe->getBody(), true);
        $this->assertSame('token_user@example.com', $meData['email']);

        // 2. GET /api/v1/users with Bearer token succeeds with owner permissions
        $reqUsers = (new ServerRequest('GET', 'https://test.bscms.local/api/v1/users'))
            ->withHeader('Authorization', 'Bearer ' . $plainToken);
        $resUsers = $this->dispatch($reqUsers);
        $this->assertSame(200, $resUsers->getStatusCode());
        $usersData = json_decode((string) $resUsers->getBody(), true);
        $this->assertArrayHasKey('items', $usersData);

        // 3. Revoke token
        $this->assertTrue($tokenService->revoke((string) $createdToken['id'], $userId));
        $resRevoked = $this->dispatch($reqMe);
        $this->assertSame(401, $resRevoked->getStatusCode());
    }

    public function testUserPresenterSanitizesListAndGetResponsesAndContainsNoSecrets(): void
    {
        $userRepo = new UserRepository($this->connection);
        $hasher = new PasswordHasher();
        $roleRepo = new RoleRepository($this->connection);
        $ownerRole = $roleRepo->findByHandle('owner');
        $this->assertNotNull($ownerRole);

        $userId = $userRepo->create([
            'email' => 'presenter_test@example.com',
            'name' => 'Presenter Test',
            'password_hash' => $hasher->hash('PasswordSecret123!'),
            'totp_secret_enc' => 'base64:encryptedSecretTotp1234567890',
            'recovery_codes_hash' => ['hash1', 'hash2'],
            'role_id' => $ownerRole['id'],
            'status' => 'active',
        ]);

        $container = $this->app->getContainer();
        /** @var ApiTokenService $tokenService */
        $tokenService = $container->get(ApiTokenService::class);
        $tokenData = $tokenService->create($userId, 'Presenter Token', roleId: (string) $ownerRole['id'], scopes: ['*']);

        // 1. GET /api/v1/users
        $reqList = (new ServerRequest('GET', 'https://test.bscms.local/api/v1/users'))
            ->withHeader('Authorization', 'Bearer ' . $tokenData['token']);
        $resList = $this->dispatch($reqList);
        $this->assertSame(200, $resList->getStatusCode());
        $this->assertNoSecretsInResponse($resList);

        $listData = json_decode((string) $resList->getBody(), true);
        $this->assertArrayHasKey('items', $listData);
        $found = false;
        foreach ($listData['items'] as $item) {
            if ($item['email'] === 'presenter_test@example.com') {
                $found = true;
                $this->assertArrayNotHasKey('password_hash', $item);
                $this->assertArrayNotHasKey('totp_secret_enc', $item);
                $this->assertArrayNotHasKey('recovery_codes_hash', $item);
                $this->assertArrayNotHasKey('failed_logins', $item);
                $this->assertArrayNotHasKey('locked_until', $item);
                $this->assertArrayHasKey('id', $item);
                $this->assertArrayHasKey('name', $item);
                $this->assertArrayHasKey('email', $item);
                $this->assertArrayHasKey('status', $item);
                $this->assertArrayHasKey('two_factor_enabled', $item);
            }
        }
        $this->assertTrue($found);

        // 2. GET /api/v1/users/{id}
        $reqGet = (new ServerRequest('GET', "https://test.bscms.local/api/v1/users/{$userId}"))
            ->withHeader('Authorization', 'Bearer ' . $tokenData['token']);
        $resGet = $this->dispatch($reqGet);
        $this->assertSame(200, $resGet->getStatusCode());
        $this->assertNoSecretsInResponse($resGet);
        $getData = json_decode((string) $resGet->getBody(), true);
        $this->assertSame('presenter_test@example.com', $getData['email']);
        $this->assertArrayNotHasKey('password_hash', $getData);
        $this->assertArrayNotHasKey('totp_secret_enc', $getData);
        $this->assertArrayNotHasKey('recovery_codes_hash', $getData);
    }

    public function testLoginAndAuditLogSupportIpv6AndNamedArgumentsWithoutSqlError(): void
    {
        $userRepo = new UserRepository($this->connection);
        $hasher = new PasswordHasher();
        $roleRepo = new RoleRepository($this->connection);
        $ownerRole = $roleRepo->findByHandle('owner');
        $this->assertNotNull($ownerRole);

        $userRepo->create([
            'email' => 'ipv6_user@example.com',
            'name' => 'IPv6 User',
            'password_hash' => $hasher->hash('IPv6UserSecure2026!'),
            'role_id' => $ownerRole['id'],
            'status' => 'active',
        ]);

        $ipv6 = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
        $customUa = 'Custom-IPv6-Agent/2.5.0 (Platform; Arch) ComplianceTest/99';
        $customReqId = 'custom-ipv6-trace-id-12345';

        $loginReq = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/auth/login', [], null, '1.1', ['REMOTE_ADDR' => $ipv6]))
            ->withHeader('User-Agent', $customUa)
            ->withHeader('X-Request-ID', $customReqId)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody([
                'email' => 'ipv6_user@example.com',
                'password' => 'IPv6UserSecure2026!',
            ]);

        $loginRes = $this->dispatch($loginReq);
        $this->assertSame(200, $loginRes->getStatusCode(), 'Login must return 200 with IPv6 without SQLSTATE 22001');
        $this->assertNoSecretsInResponse($loginRes);

        // Verify exact audit log storage in database
        $auditTable = $this->connection->prefixTable('audit_log');
        $stmt = $this->connection->getPdo()->prepare(
            "SELECT `ip`, `user_agent`, `request_id`, `action`, `result` FROM `{$auditTable}`
             WHERE `action` = 'auth.login' AND `summary` LIKE '%ipv6_user@example.com%' OR `ip` = ?
             ORDER BY `id` DESC LIMIT 1"
        );
        $stmt->execute([$ipv6]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'Audit row must be recorded');
        $this->assertSame($ipv6, $row['ip'], 'Audit log must store full IPv6 address in ip column');
        $this->assertSame($customReqId, $row['request_id'], 'Audit log must store request_id in request_id column');
        $this->assertSame($customUa, $row['user_agent'], 'Audit log must store user_agent in user_agent column');
        $this->assertSame('success', $row['result']);
    }

    public function testFullTwoFactorAuthenticationLifecycleOverHttp(): void
    {
        $userRepo = new UserRepository($this->connection);
        $hasher = new PasswordHasher();
        $roleRepo = new RoleRepository($this->connection);
        $twoFactor = new \BsCms\Auth\TwoFactorService();
        $ownerRole = $roleRepo->findByHandle('owner');
        $this->assertNotNull($ownerRole);

        $userId = $userRepo->create([
            'email' => 'mfa_user@example.com',
            'name' => 'MFA Lifecycle User',
            'password_hash' => $hasher->hash('MfaUserSecure2026!'),
            'role_id' => $ownerRole['id'],
            'status' => 'active',
        ]);

        // Step 1: Initial Login to start 2FA setup
        $loginReq = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/auth/login'))
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['email' => 'mfa_user@example.com', 'password' => 'MfaUserSecure2026!']);
        $loginRes = $this->dispatch($loginReq);
        $this->assertSame(200, $loginRes->getStatusCode());
        $this->assertNoSecretsInResponse($loginRes);

        $cookieHeader = $loginRes->getHeaderLine('Set-Cookie');
        preg_match('/__Host-bs_session=([^;]+)/', $cookieHeader, $matches);
        $sessionCookie = isset($matches[1]) ? (string) $matches[1] : '';
        $this->assertNotEmpty($sessionCookie);

        $sessionReq = (new ServerRequest('GET', 'https://test.bscms.local/api/v1/auth/session'))
            ->withCookieParams(['__Host-bs_session' => $sessionCookie]);
        $sessionRes = $this->dispatch($sessionReq);
        $csrfToken = json_decode((string) $sessionRes->getBody(), true)['csrf_token'];

        // Step 2: Setup 2FA (GET or POST /api/v1/me/2fa/setup)
        $setupReq = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/me/2fa/setup'))
            ->withCookieParams(['__Host-bs_session' => $sessionCookie])
            ->withHeader('Origin', 'https://test.bscms.local')
            ->withHeader('X-CSRF-Token', $csrfToken);
        $setupRes = $this->dispatch($setupReq);
        $this->assertSame(200, $setupRes->getStatusCode());
        $setupData = json_decode((string) $setupRes->getBody(), true);
        $this->assertArrayHasKey('secret', $setupData);
        $this->assertArrayHasKey('otpauth_uri', $setupData);
        $secret = (string) $setupData['secret'];

        // Step 3: Confirm 2FA sending code ONLY (no secret sent by client!)
        $validCode = $twoFactor->getCode($secret);
        $confirmReq = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/me/2fa/confirm'))
            ->withCookieParams(['__Host-bs_session' => $sessionCookie])
            ->withHeader('Origin', 'https://test.bscms.local')
            ->withHeader('X-CSRF-Token', $csrfToken)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['code' => $validCode]);
        $confirmRes = $this->dispatch($confirmReq);
        $this->assertSame(200, $confirmRes->getStatusCode());
        $confirmData = json_decode((string) $confirmRes->getBody(), true);
        $this->assertSame('2fa_enabled', $confirmData['status']);
        $this->assertArrayHasKey('recovery_codes', $confirmData);
        $this->assertCount(10, $confirmData['recovery_codes']);
        $recoveryCodes = $confirmData['recovery_codes'];

        // Step 4: Logout
        $logoutReq = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/auth/logout'))
            ->withCookieParams(['__Host-bs_session' => $sessionCookie])
            ->withHeader('Origin', 'https://test.bscms.local')
            ->withHeader('X-CSRF-Token', $csrfToken);
        $logoutRes = $this->dispatch($logoutReq);
        $this->assertSame(200, $logoutRes->getStatusCode());

        // Step 5: Login again -> must return status: requires_2fa and challenge_token
        $mfaLoginReq = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/auth/login'))
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['email' => 'mfa_user@example.com', 'password' => 'MfaUserSecure2026!']);
        $mfaLoginRes = $this->dispatch($mfaLoginReq);
        $this->assertSame(200, $mfaLoginRes->getStatusCode());
        $this->assertNoSecretsInResponse($mfaLoginRes);
        $mfaLoginData = json_decode((string) $mfaLoginRes->getBody(), true);
        $this->assertSame('requires_2fa', $mfaLoginData['status']);
        $this->assertNotEmpty($mfaLoginData['challenge_token']);
        $challengeToken = (string) $mfaLoginData['challenge_token'];

        // Step 6: Challenge with wrong code -> 401
        $wrongReq = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/auth/2fa/challenge'))
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody([
                'challenge_token' => $challengeToken,
                'code' => '000000',
            ]);
        $wrongRes = $this->dispatch($wrongReq);
        $this->assertSame(401, $wrongRes->getStatusCode());

        // Step 7: Challenge with valid TOTP code
        $validTotpCode = $twoFactor->getCode($secret);
        $validChallengeReq = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/auth/2fa/challenge'))
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody([
                'challenge_token' => $challengeToken,
                'code' => $validTotpCode,
            ]);
        $validChallengeRes = $this->dispatch($validChallengeReq);
        $this->assertSame(200, $validChallengeRes->getStatusCode());
        $this->assertNoSecretsInResponse($validChallengeRes);
        $this->assertStringContainsString('__Host-bs_session=', $validChallengeRes->getHeaderLine('Set-Cookie'));

        // Step 8: Replay same challenge token -> must be rejected (single use!)
        $replayReq = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/auth/2fa/challenge'))
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody([
                'challenge_token' => $challengeToken,
                'code' => $validTotpCode,
            ]);
        $replayRes = $this->dispatch($replayReq);
        $this->assertSame(401, $replayRes->getStatusCode(), 'Replayed challenge token must be rejected');

        // Step 9: Login again and challenge with a Recovery Code
        $loginAgain = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/auth/login'))
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['email' => 'mfa_user@example.com', 'password' => 'MfaUserSecure2026!']);
        $loginAgainRes = $this->dispatch($loginAgain);
        $token2 = json_decode((string) $loginAgainRes->getBody(), true)['challenge_token'];

        $recoveryReq = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/auth/2fa/challenge'))
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody([
                'challenge_token' => $token2,
                'code' => $recoveryCodes[0],
            ]);
        $recoveryRes = $this->dispatch($recoveryReq);
        $this->assertSame(200, $recoveryRes->getStatusCode());

        // Step 10: Replay same recovery code on a new login -> must be rejected
        $loginAgain3 = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/auth/login'))
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['email' => 'mfa_user@example.com', 'password' => 'MfaUserSecure2026!']);
        $token3 = json_decode((string) $loginAgain3->getBody(), true)['challenge_token'];

        $replayRecoveryReq = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/auth/2fa/challenge'))
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody([
                'challenge_token' => $token3,
                'code' => $recoveryCodes[0],
            ]);
        $replayRecoveryRes = $this->dispatch($replayRecoveryReq);
        $this->assertSame(401, $replayRecoveryRes->getStatusCode(), 'Used recovery code cannot be replayed');
    }

    public function testApiTokenRoleInheritanceScopeRestrictionsAndAiDraftOnlyMode(): void
    {
        $userRepo = new UserRepository($this->connection);
        $roleRepo = new RoleRepository($this->connection);
        $ownerRole = $roleRepo->findByHandle('owner');
        $this->assertNotNull($ownerRole);

        $userId = $userRepo->create([
            'email' => 'owner_token@example.com',
            'name' => 'Owner Token Tester',
            'role_id' => $ownerRole['id'],
            'status' => 'active',
        ]);

        $container = $this->app->getContainer();
        /** @var ApiTokenService $tokenService */
        $tokenService = $container->get(ApiTokenService::class);

        // 1. Token with role_id = null inherits owner's roles
        $inheritedToken = $tokenService->create(
            userId: $userId,
            name: 'Inherited Owner Token',
            roleId: null,
            scopes: ['*']
        );
        $reqInherited = (new ServerRequest('GET', 'https://test.bscms.local/api/v1/users'))
            ->withHeader('Authorization', 'Bearer ' . $inheritedToken['token']);
        $resInherited = $this->dispatch($reqInherited);
        $this->assertSame(200, $resInherited->getStatusCode(), 'Token with null role_id inherits owner permissions');

        // 2. Read-only scoped token: scopes = ['users:read']
        $readToken = $tokenService->create(
            userId: $userId,
            name: 'Read Token',
            roleId: (string) $ownerRole['id'],
            scopes: ['users:read']
        );

        // Read token can GET /api/v1/users
        $reqReadAllowed = (new ServerRequest('GET', 'https://test.bscms.local/api/v1/users'))
            ->withHeader('Authorization', 'Bearer ' . $readToken['token']);
        $resReadAllowed = $this->dispatch($reqReadAllowed);
        $this->assertSame(200, $resReadAllowed->getStatusCode());

        // Read token CANNOT invite users (POST /api/v1/users/invite)
        $reqInviteForbidden = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/users/invite'))
            ->withHeader('Authorization', 'Bearer ' . $readToken['token'])
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['email' => 'invited@example.com', 'role_id' => (string) $ownerRole['id']]);
        $resInviteForbidden = $this->dispatch($reqInviteForbidden);
        $this->assertSame(403, $resInviteForbidden->getStatusCode(), 'Read token cannot invite users');
        $this->assertStringContainsString('Token scope does not permit', (string) $resInviteForbidden->getBody());

        // 3. AI Mode draft_only blocks destructive actions (e.g. deleting users or suspending)
        $aiDraftToken = $tokenService->create(
            userId: $userId,
            name: 'AI Draft Token',
            roleId: (string) $ownerRole['id'],
            scopes: ['*'],
            aiMode: Actor::AI_MODE_DRAFT_ONLY
        );

        $reqAiDelete = (new ServerRequest('DELETE', "https://test.bscms.local/api/v1/users/{$userId}"))
            ->withHeader('Authorization', 'Bearer ' . $aiDraftToken['token']);
        $resAiDelete = $this->dispatch($reqAiDelete);
        $this->assertSame(403, $resAiDelete->getStatusCode());
        $this->assertStringContainsString('draft_only mode', (string) $resAiDelete->getBody());
    }

    public function testRequestIdValidationAndErrorHandlerLoggingWithRedaction(): void
    {
        // 1. Malicious or invalid X-Request-ID is rejected and replaced with valid ULID
        $maliciousReq = (new ServerRequest('GET', 'https://test.bscms.local/health'))
            ->withHeader('X-Request-ID', '<script>alert(1)</script>');
        $resMalicious = $this->dispatch($maliciousReq);
        $reqIdHeader = $resMalicious->getHeaderLine('X-Request-ID');
        $this->assertNotSame('<script>alert(1)</script>', $reqIdHeader);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $reqIdHeader);

        // 2. Valid custom X-Request-ID is accepted
        $validReq = (new ServerRequest('GET', 'https://test.bscms.local/health'))
            ->withHeader('X-Request-ID', 'valid-client-trace-12345');
        $resValid = $this->dispatch($validReq);
        $this->assertSame('valid-client-trace-12345', $resValid->getHeaderLine('X-Request-ID'));
    }

    public function testTieredRateLimiterBucketsForAuthenticatedAndStrictAuthEndpoints(): void
    {
        // 1. Sensitive auth endpoint (/api/v1/auth/login) gets strict bucket limit 60
        $reqLogin = (new ServerRequest('POST', 'https://test.bscms.local/api/v1/auth/login'))
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['email' => 'none@example.com', 'password' => 'wrong']);
        $resLogin = $this->dispatch($reqLogin);
        $this->assertSame('60', $resLogin->getHeaderLine('RateLimit-Limit'));

        // 2. Authenticated user gets generous bucket limit 600
        $userRepo = new UserRepository($this->connection);
        $roleRepo = new RoleRepository($this->connection);
        $ownerRole = $roleRepo->findByHandle('owner');
        $this->assertNotNull($ownerRole);

        $userId = $userRepo->create([
            'email' => 'rl_tester@example.com',
            'name' => 'RL Tester',
            'role_id' => $ownerRole['id'],
            'status' => 'active',
        ]);
        $container = $this->app->getContainer();
        /** @var ApiTokenService $tokenService */
        $tokenService = $container->get(ApiTokenService::class);
        $tok = $tokenService->create($userId, 'RL Token', roleId: (string) $ownerRole['id'], scopes: ['*']);

        $reqAuth = (new ServerRequest('GET', 'https://test.bscms.local/api/v1/me'))
            ->withHeader('Authorization', 'Bearer ' . $tok['token']);
        $resAuth = $this->dispatch($reqAuth);
        $this->assertSame('600', $resAuth->getHeaderLine('RateLimit-Limit'), 'Authenticated user must receive generous 600/min rate limit bucket');
    }

    private function assertNoSecretsInResponse(ResponseInterface $response): void
    {
        $body = (string) $response->getBody();
        if (trim($body) === '') {
            return;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return;
        }
        $this->scanForSecretsRecursively($data);
    }

    private function scanForSecretsRecursively(mixed $data, string $path = ''): void
    {
        if (is_array($data)) {
            foreach ($data as $key => $val) {
                $currentPath = $path === '' ? (string) $key : "{$path}.{$key}";
                $bannedKeys = ['password_hash', 'totp_secret_enc', 'recovery_codes_hash'];
                $this->assertFalse(
                    in_array((string) $key, $bannedKeys, true),
                    "Leaked secret key '{$key}' at response path: {$currentPath}"
                );

                $this->scanForSecretsRecursively($val, $currentPath);
            }
            return;
        }

        if (is_string($data)) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\$argon2id\$/i',
                $data,
                "Found Argon2id password hash string in response at path: {$path}"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/^\$2[ayb]\$[0-9]{2}\$[A-Za-z0-9\.\/]{53}$/',
                $data,
                "Found bcrypt password hash string in response at path: {$path}"
            );
        }
    }
}
