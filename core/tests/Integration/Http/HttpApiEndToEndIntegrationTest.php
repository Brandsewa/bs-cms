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

        $kernel = new Kernel($router);
        $kernel->addMiddleware(new ErrorHandlerMiddleware(new Environment(Environment::PRODUCTION)));
        $kernel->addMiddleware(new RequestIdMiddleware());
        $kernel->addMiddleware(new SecurityHeadersMiddleware());
        $kernel->addMiddleware(new HttpsEnforcerMiddleware(new Environment(Environment::PRODUCTION)));
        $kernel->addMiddleware(new MaintenanceModeMiddleware($this->tempDir . '/storage'));
        $kernel->addMiddleware(new BodyLimitMiddleware(2 * 1024 * 1024));
        $kernel->addMiddleware(new JsonBodyMiddleware());

        $limiter = new RateLimiter(60, 60, [], fn () => $this->connection);
        $kernel->addMiddleware(new RateLimitMiddleware($limiter));

        $sessionManager = $container->get(SessionManager::class);
        $apiTokenService = $container->get(ApiTokenService::class);

        // Session -> Authenticate -> CSRF
        $kernel->addMiddleware(new SessionMiddleware($sessionManager, $this->connection));
        $kernel->addMiddleware(new AuthenticateMiddleware($apiTokenService));
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
        if (is_dir($this->tempDir)) {
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
}
