<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Auth;

use BsCms\Auth\SessionManager;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Http\Middleware\CsrfMiddleware;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use DateTimeImmutable;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private PDO $adminPdo;
    private SessionManager $sessionManager;
    private string $testUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_sess_' . bin2hex(random_bytes(4));

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

        $this->sessionManager = new SessionManager($this->connection, idleTimeout: 3600, absoluteTimeout: 86400);

        // Seed test active user
        $this->testUserId = Ulid::generate();
        $now = Clock::sql(Clock::now());
        $usersTable = $this->connection->prefixTable('users');
        $this->connection->getPdo()->exec("INSERT INTO `{$usersTable}`
            (`id`, `email`, `name`, `status`, `created_at`, `updated_at`)
            VALUES ('{$this->testUserId}', 'alice@example.com', 'Alice Smith', 'active', '{$now}', '{$now}')");
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo) && $this->dbName !== '') {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }
        parent::tearDown();
    }

    public function testCreateFindAndRevokeSession(): void
    {
        $sessionData = $this->sessionManager->create($this->testUserId, '127.0.0.1', 'Mozilla/5.0');
        $token = $sessionData['token'];

        $found = $this->sessionManager->findValidSession($token);
        $this->assertNotNull($found);
        $this->assertSame($this->testUserId, $found['user_id']);
        $this->assertSame('Alice Smith', $found['name']);
        $this->assertSame($sessionData['csrf_token'], $found['csrf_secret']);

        // Revoke
        $this->sessionManager->revoke((string) $found['id']);
        $this->assertNull($this->sessionManager->findValidSession($token));
    }

    public function testRotateSessionFixationDefense(): void
    {
        $sessionData = $this->sessionManager->create($this->testUserId, '127.0.0.1');
        $oldToken = $sessionData['token'];
        $oldId = $sessionData['id'];

        $rotated = $this->sessionManager->rotate($oldId, '127.0.0.1', 'RotatedAgent');
        $this->assertNotSame($oldId, $rotated['id']);
        $this->assertNotSame($oldToken, $rotated['token']);

        // Old token must no longer be valid!
        $this->assertNull($this->sessionManager->findValidSession($oldToken));

        // New token must be valid!
        $newFound = $this->sessionManager->findValidSession($rotated['token']);
        $this->assertNotNull($newFound);
        $this->assertSame($this->testUserId, $newFound['user_id']);
    }

    public function testTimeouts(): void
    {
        // 1. Within limits: valid
        $session1 = $this->sessionManager->create($this->testUserId);
        $this->assertNotNull($this->sessionManager->findValidSession($session1['token'], Clock::now()->modify('+10 minutes')));

        // 2. Past idle limit (3600 seconds): invalid
        $session2 = $this->sessionManager->create($this->testUserId);
        $this->assertNull($this->sessionManager->findValidSession($session2['token'], Clock::now()->modify('+3601 seconds')));

        // 3. Past absolute limit (86400 seconds): invalid
        $session3 = $this->sessionManager->create($this->testUserId);
        $this->assertNull($this->sessionManager->findValidSession($session3['token'], Clock::now()->modify('+86401 seconds')));
    }

    public function testCsrfMiddlewareEnforcement(): void
    {
        $sessionData = $this->sessionManager->create($this->testUserId);
        $session = $this->sessionManager->findValidSession($sessionData['token']);
        $this->assertNotNull($session);

        $csrfMiddleware = new CsrfMiddleware('http://localhost:8000');
        $dummyHandler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'OK');
            }
        };

        // 1. Safe method (GET) passes without CSRF token
        $getRequest = (new ServerRequest('GET', 'http://localhost:8000/api/v1/test'))
            ->withAttribute('session', $session);
        $res = $csrfMiddleware->process($getRequest, $dummyHandler);
        $this->assertSame(200, $res->getStatusCode());

        // 2. Mutating method (POST) without CSRF token fails with 403 Problem Details
        $postWithoutToken = (new ServerRequest('POST', 'http://localhost:8000/api/v1/test'))
            ->withAttribute('session', $session);
        $res403 = $csrfMiddleware->process($postWithoutToken, $dummyHandler);
        $this->assertSame(403, $res403->getStatusCode());

        // 3. Mutating method with valid CSRF token but origin mismatch fails
        $postWrongOrigin = (new ServerRequest('POST', 'http://localhost:8000/api/v1/test'))
            ->withAttribute('session', $session)
            ->withHeader('X-CSRF-Token', (string) $session['csrf_secret'])
            ->withHeader('Origin', 'http://evil.com');
        $resOrigin403 = $csrfMiddleware->process($postWrongOrigin, $dummyHandler);
        $this->assertSame(403, $resOrigin403->getStatusCode());

        // 4. Mutating method with valid CSRF token and valid Origin succeeds
        $postValid = (new ServerRequest('POST', 'http://localhost:8000/api/v1/test'))
            ->withAttribute('session', $session)
            ->withHeader('X-CSRF-Token', (string) $session['csrf_secret'])
            ->withHeader('Origin', 'http://localhost:8000');
        $resSuccess = $csrfMiddleware->process($postValid, $dummyHandler);
        $this->assertSame(200, $resSuccess->getStatusCode());
    }
}
