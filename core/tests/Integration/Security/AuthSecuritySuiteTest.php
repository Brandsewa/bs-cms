<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Security;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\ApiTokenService;
use BsCms\Auth\LoginService;
use BsCms\Auth\PasswordHasher;
use BsCms\Auth\PasswordResetService;
use BsCms\Auth\RecoveryCodes;
use BsCms\Auth\SessionManager;
use BsCms\Auth\TwoFactorService;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Http\Middleware\CsrfMiddleware;
use BsCms\Mail\MailerService;
use BsCms\Mail\MailMessage;
use BsCms\Mail\NullTransport;
use BsCms\Mail\TemplateRenderer;
use BsCms\Support\Clock;
use BsCms\Users\RoleRepository;
use BsCms\Users\UserRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthSecuritySuiteTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private ?PDO $adminPdo = null;
    private UserRepository $users;
    private RoleRepository $roles;
    private PasswordHasher $hasher;
    private SessionManager $sessions;
    private TwoFactorService $twoFactor;
    private AuditLogger $auditLogger;
    private MailerService $mailer;
    private TemplateRenderer $templates;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_sec_' . bin2hex(random_bytes(4));

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

        $this->users = new UserRepository($this->connection);
        $this->roles = new RoleRepository($this->connection);
        $this->hasher = new PasswordHasher();
        $this->sessions = new SessionManager($this->connection);
        $this->twoFactor = new TwoFactorService();
        $this->auditLogger = new AuditLogger($this->connection);
        $this->mailer = new MailerService($this->connection, new NullTransport());
        $this->templates = new TemplateRenderer(dirname(__DIR__, 3) . '/src/Mail/Templates');
    }

    protected function tearDown(): void
    {
        if ($this->adminPdo !== null && $this->dbName !== '') {
            try {
                $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
            } catch (\Throwable $e) {
            }
        }
        parent::tearDown();
    }

    public function testSessionFixationPreventionAndCookieFlags(): void
    {
        $userId = $this->users->create([
            'email' => 'victim_' . bin2hex(random_bytes(3)) . '@example.com',
            'name' => 'Victim User',
            'password_hash' => $this->hasher->hash('VictimPassphrase2026!'),
            'status' => 'active',
        ]);

        // 1. Pre-login session exists
        $preSession = $this->sessions->create($userId, '1.2.3.4', 'Pre-Browser');
        $oldToken = $preSession['token'];
        $oldSessionId = $preSession['id'];
        $this->assertNotNull($this->sessions->findValidSession($oldToken));

        // 2. Rotate session on login
        $rotated = $this->sessions->rotate($oldSessionId, '1.2.3.4', 'Post-Browser');
        $newToken = $rotated['token'];
        $this->assertNotSame($oldToken, $newToken, 'Rotated token must differ from old token');

        // 3. Old token must be invalidated
        $this->assertNull($this->sessions->findValidSession($oldToken), 'Old session token must be revoked upon rotation');
        $this->assertNotNull($this->sessions->findValidSession($newToken), 'New session token must be valid');

        // 4. Verify cookie flags
        $cookieHeaderHttps = $this->sessions->buildCookieHeader($newToken, 86400, true);
        $this->assertStringContainsString('__Host-bs_session=', $cookieHeaderHttps);
        $this->assertStringContainsString('Secure', $cookieHeaderHttps);
        $this->assertStringContainsString('HttpOnly', $cookieHeaderHttps);
        $this->assertStringContainsString('SameSite=Lax', $cookieHeaderHttps);
        $this->assertStringContainsString('Path=/', $cookieHeaderHttps);

        $cookieHeaderHttp = $this->sessions->buildCookieHeader($newToken, 86400, false);
        $this->assertStringContainsString('bs_session=', $cookieHeaderHttp);
        $this->assertStringNotContainsString('Secure', $cookieHeaderHttp);
    }

    public function testCsrfMiddlewareRejectsMissingInvalidAndMismatchedOrigin(): void
    {
        $userId = $this->users->create([
            'email' => 'csrf_' . bin2hex(random_bytes(3)) . '@example.com',
            'name' => 'CSRF Tester',
            'password_hash' => $this->hasher->hash('SecretPassphrase2026!'),
            'status' => 'active',
        ]);
        $session = $this->sessions->create($userId, '127.0.0.1', 'PHPUnit');
        $validToken = $session['token'];
        $sessionData = $this->sessions->findValidSession($validToken);
        $this->assertNotNull($sessionData);
        $csrfSecret = $sessionData['csrf_secret'];

        $csrfMiddleware = new CsrfMiddleware('https://admin.example.com');
        $dummyHandler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'OK');
            }
        };

        // 1. Missing CSRF token on POST -> 403
        $req1 = (new ServerRequest('POST', '/api/v1/users'))
            ->withHeader('Origin', 'https://admin.example.com')
            ->withAttribute('session', $sessionData);
        $res1 = $csrfMiddleware->process($req1, $dummyHandler);
        $this->assertSame(403, $res1->getStatusCode());

        // 2. Invalid CSRF token on POST -> 403
        $req2 = (new ServerRequest('POST', '/api/v1/users'))
            ->withHeader('Origin', 'https://admin.example.com')
            ->withHeader('X-CSRF-Token', 'wrong_csrf_token_value')
            ->withAttribute('session', $sessionData);
        $res2 = $csrfMiddleware->process($req2, $dummyHandler);
        $this->assertSame(403, $res2->getStatusCode());

        // 3. Origin mismatch -> 403
        $req3 = (new ServerRequest('POST', '/api/v1/users'))
            ->withHeader('Origin', 'https://attacker.evil.com')
            ->withHeader('X-CSRF-Token', $csrfSecret)
            ->withAttribute('session', $sessionData);
        $res3 = $csrfMiddleware->process($req3, $dummyHandler);
        $this->assertSame(403, $res3->getStatusCode());

        // 4. Valid CSRF token and allowed origin -> 200
        $req4 = (new ServerRequest('POST', '/api/v1/users'))
            ->withHeader('Origin', 'https://admin.example.com')
            ->withHeader('X-CSRF-Token', $csrfSecret)
            ->withAttribute('session', $sessionData);
        $res4 = $csrfMiddleware->process($req4, $dummyHandler);
        $this->assertSame(200, $res4->getStatusCode());

        // 5. GET request does not require CSRF token
        $req5 = (new ServerRequest('GET', '/api/v1/users'))
            ->withAttribute('session', $sessionData);
        $res5 = $csrfMiddleware->process($req5, $dummyHandler);
        $this->assertSame(200, $res5->getStatusCode());
    }

    public function testIdleAndAbsoluteSessionTimeouts(): void
    {
        $userId = $this->users->create([
            'email' => 'timeout_' . bin2hex(random_bytes(3)) . '@example.com',
            'name' => 'Timeout User',
            'status' => 'active',
        ]);

        $pdo = $this->connection->getPdo();
        $sessionsTable = $this->connection->prefixTable('sessions');

        // Test 1: Idle timeout (12 hours)
        $session1 = $this->sessions->create($userId, '127.0.0.1', 'TestAgent');
        $token1 = $session1['token'];
        $hash1 = hash('sha256', $token1);

        // Manually age last_seen_at by 13 hours
        $thirteenHoursAgo = Clock::sql((new DateTimeImmutable())->modify('-13 hours'));
        $pdo->exec("UPDATE `{$sessionsTable}` SET `last_seen_at` = '{$thirteenHoursAgo}' WHERE `token_hash` = '{$hash1}'");

        $this->assertNull($this->sessions->findValidSession($token1), 'Session past 12h idle timeout must be rejected');

        // Test 2: Absolute timeout (14 days)
        $session2 = $this->sessions->create($userId, '127.0.0.1', 'TestAgent');
        $token2 = $session2['token'];
        $hash2 = hash('sha256', $token2);

        // Manually age created_at by 15 days
        $fifteenDaysAgo = Clock::sql((new DateTimeImmutable())->modify('-15 days'));
        $pdo->exec("UPDATE `{$sessionsTable}` SET `created_at` = '{$fifteenDaysAgo}' WHERE `token_hash` = '{$hash2}'");

        $this->assertNull($this->sessions->findValidSession($token2), 'Session past 14d absolute timeout must be rejected');
    }

    public function testBruteForceLockoutAfterFiveFailures(): void
    {
        $loginService = new LoginService(
            $this->connection,
            $this->users,
            $this->hasher,
            $this->sessions,
            $this->twoFactor,
            $this->auditLogger,
            $this->mailer,
            $this->templates
        );

        $email = 'brute_' . bin2hex(random_bytes(3)) . '@example.com';
        $userId = $this->users->create([
            'email' => $email,
            'name' => 'Brute Target',
            'password_hash' => $this->hasher->hash('RealPassword2026!'),
            'status' => 'active',
        ]);

        // Attempt 1 through 5 with wrong password
        for ($i = 1; $i <= 5; $i++) {
            $result = $loginService->login($email, 'WrongPassword123!', '127.0.0.1', 'Tester');
            $this->assertSame('failed', $result['status']);
            $this->assertSame('Email or password is incorrect.', $result['message']);
        }

        // Check user record: failed_logins must be >= 5 and locked_until must be set
        $user = $this->users->findById($userId);
        $this->assertNotNull($user);
        $this->assertGreaterThanOrEqual(5, (int) $user['failed_logins']);
        $this->assertNotNull($user['locked_until']);

        // Attempt 6: even with the correct password, login must be blocked due to account lockout
        $resultLocked = $loginService->login($email, 'RealPassword2026!', '127.0.0.1', 'Tester');
        $this->assertSame('locked', $resultLocked['status']);
        $this->assertStringContainsString('Account is temporarily locked', (string) $resultLocked['message']);
    }

    public function testAntiEnumerationOnLoginAndForgotPassword(): void
    {
        $loginService = new LoginService(
            $this->connection,
            $this->users,
            $this->hasher,
            $this->sessions,
            $this->twoFactor,
            $this->auditLogger,
            $this->mailer,
            $this->templates
        );

        $existingEmail = 'known_' . bin2hex(random_bytes(3)) . '@example.com';
        $this->users->create([
            'email' => $existingEmail,
            'name' => 'Known User',
            'password_hash' => $this->hasher->hash('KnownPassword2026!'),
            'status' => 'active',
        ]);

        $unknownEmail = 'nonexistent_' . bin2hex(random_bytes(3)) . '@example.com';

        // 1. Login anti-enumeration: responses must be identical
        $resKnown = $loginService->login($existingEmail, 'WrongPassword!', '127.0.0.1', 'Tester');
        $resUnknown = $loginService->login($unknownEmail, 'WrongPassword!', '127.0.0.1', 'Tester');

        $this->assertSame('failed', $resKnown['status']);
        $this->assertSame('failed', $resUnknown['status']);
        $this->assertSame($resKnown['message'], $resUnknown['message']);
        $this->assertSame('Email or password is incorrect.', $resKnown['message']);

        // 2. Forgot-password anti-enumeration: executes silently for both
        $resetService = new PasswordResetService(
            $this->connection,
            $this->users,
            $this->hasher,
            $this->sessions,
            $this->auditLogger,
            $this->mailer,
            $this->templates,
            'https://example.com'
        );

        // Does not throw and does not leak presence
        $resetService->requestReset($existingEmail, '127.0.0.1', 'Tester');
        $resetService->requestReset($unknownEmail, '127.0.0.1', 'Tester');

        $this->assertNotNull($this->users->findByEmail($existingEmail));
        $this->assertNull($this->users->findByEmail($unknownEmail));
    }

    public function testTotpReplayRejectionAndRecoveryCodeSingleUse(): void
    {
        $secret = $this->twoFactor->generateSecret();
        $now = time();
        $code = $this->twoFactor->getCode($secret, $now);
        $step = (int) floor($now / 30);

        // First verification succeeds
        $this->assertTrue($this->twoFactor->verifyCode($secret, $code, lastUsedStep: $step - 1));

        // Replaying same code with lastUsedStep = current step must be rejected
        $this->assertFalse(
            $this->twoFactor->verifyCode($secret, $code, lastUsedStep: $step),
            'Replay of TOTP within the same time step must be rejected'
        );

        // Recovery codes: single-use verification
        $recovery = new RecoveryCodes();
        $generated = $recovery->generate(10);
        $rawCodes = $generated['codes'];
        $hashedList = $generated['hashes'];

        $codeToUse = $rawCodes[0];
        $remaining = $recovery->consume($codeToUse, $hashedList);
        $this->assertNotNull($remaining);
        $this->assertCount(9, $remaining);

        // Attempting to reuse the consumed code fails
        $reconsume = $recovery->consume($codeToUse, $remaining);
        $this->assertNull($reconsume, 'Reused recovery code must be rejected');
    }

    public function testPasswordResetSingleUseAndSessionRevocation(): void
    {
        $email = 'reset_' . bin2hex(random_bytes(3)) . '@example.com';
        $userId = $this->users->create([
            'email' => $email,
            'name' => 'Reset Target',
            'password_hash' => $this->hasher->hash('OldPassphrase2026!'),
            'status' => 'active',
        ]);

        $resetService = new PasswordResetService(
            $this->connection,
            $this->users,
            $this->hasher,
            $this->sessions,
            $this->auditLogger,
            $this->mailer,
            $this->templates,
            'https://example.com'
        );

        // Create an active session
        $sess = $this->sessions->create($userId, '127.0.0.1', 'TestBrowser');
        $sessionToken = $sess['token'];
        $this->assertNotNull($this->sessions->findValidSession($sessionToken));

        // Request reset
        $resetService->requestReset($email);

        $pdo = $this->connection->getPdo();
        $resetTable = $this->connection->prefixTable('password_resets');
        $stmt = $pdo->prepare("SELECT * FROM `{$resetTable}` WHERE `user_id` = ?");
        $stmt->execute([$userId]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        // Generate a known test token and link it to this row
        $rawBytes = random_bytes(32);
        $rawToken = rtrim(strtr(base64_encode($rawBytes), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $rawToken);

        $up = $pdo->prepare("UPDATE `{$resetTable}` SET `token_hash` = ? WHERE `id` = ?");
        $up->execute([$tokenHash, $row['id']]);

        // Validate token
        $this->assertNotNull($resetService->validateToken($rawToken));

        // Perform reset
        $newPass = 'NewBrandSewaPassphrase2026!';
        $resetOk = $resetService->resetPassword($rawToken, $newPass, '127.0.0.1', 'TestBrowser');
        $this->assertTrue($resetOk);

        // Token must be single use (no longer valid)
        $this->assertNull($resetService->validateToken($rawToken), 'Reset token must be single-use');

        // All prior sessions must be revoked
        $this->assertNull($this->sessions->findValidSession($sessionToken), 'All pre-existing sessions must be revoked after password reset');

        // New password works
        $userUpdated = $this->users->findById($userId);
        $this->assertNotNull($userUpdated);
        $this->assertTrue($this->hasher->verify($newPass, (string) $userUpdated['password_hash']));
    }

    public function testApiTokensHashedAtRestAndRevokedTokenRejected(): void
    {
        $userId = $this->users->create([
            'email' => 'token_' . bin2hex(random_bytes(3)) . '@example.com',
            'name' => 'Token Owner',
            'status' => 'active',
        ]);

        $tokenService = new ApiTokenService($this->connection, $this->auditLogger);

        $ownerRole = $this->roles->findByHandle('owner');
        $this->assertNotNull($ownerRole);

        $created = $tokenService->create(
            $userId,
            'CI Automation Token',
            roleId: (string) $ownerRole['id'],
            scopes: ['content:read', 'content:write'],
            expiresAt: (new DateTimeImmutable())->modify('+7 days')
        );

        /** @var string $plainToken */
        $plainToken = $created['token'];
        /** @var string $tokenId */
        $tokenId = $created['id'];

        // 1. Verify plain token is NOT stored in DB
        $pdo = $this->connection->getPdo();
        $table = $this->connection->prefixTable('api_tokens');
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `id` = ?");
        $stmt->execute([$tokenId]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame(hash('sha256', $plainToken), $row['token_hash']);
        $this->assertStringNotContainsString($plainToken, serialize($row), 'Plain token must never be stored in database');

        // 2. Validate token
        $found = $tokenService->authenticate($plainToken);
        $this->assertNotNull($found);
        $this->assertSame($tokenId, $found['id']);

        // 3. Revoke token
        $this->assertTrue($tokenService->revoke($tokenId, $userId));

        // 4. Revoked token must be rejected
        $this->assertNull($tokenService->authenticate($plainToken), 'Revoked API token must be rejected');
    }

    public function testEmailHeaderInjectionAndDiffRedaction(): void
    {
        // 1. Header injection rejection in MailMessage
        $this->expectException(InvalidArgumentException::class);
        new MailMessage(
            to: "victim@example.com\r\nBcc: evil@attacker.com",
            subject: "Legit Subject\nInjected-Header: value",
            htmlBody: '<p>Hello</p>',
            textBody: 'Hello',
            template: 'test_template'
        );
    }

    public function testAuditLoggerRedactsSecretsFromDiff(): void
    {
        $table = $this->connection->prefixTable('audit_log');
        $pdo = $this->connection->getPdo();

        $sensitiveDiff = [
            'password' => 'SuperSecretPass123!',
            'api_token' => 'bs_tok_supersecretvalue999',
            'app_key' => 'base64:unencryptedsecretkeyhere',
            'safe_field' => 'visible_value',
        ];

        $this->auditLogger->record(
            \BsCms\Auth\Actor::system(),
            'user.updated',
            'user',
            'Updated sensitive fields',
            $sensitiveDiff
        );

        $stmt = $pdo->query("SELECT diff FROM `{$table}` ORDER BY id DESC LIMIT 1");
        $this->assertNotFalse($stmt);
        $storedDiff = (string) $stmt->fetchColumn();

        $this->assertStringNotContainsString('SuperSecretPass123!', $storedDiff);
        $this->assertStringNotContainsString('bs_tok_supersecretvalue999', $storedDiff);
        $this->assertStringNotContainsString('unencryptedsecretkeyhere', $storedDiff);
        $this->assertStringContainsString('visible_value', $storedDiff);
        $this->assertStringContainsString('[REDACTED]', $storedDiff);
    }
}
