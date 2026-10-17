<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Auth;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\ApiTokenService;
use BsCms\Auth\EmailVerificationService;
use BsCms\Auth\InvitationService;
use BsCms\Auth\LoginService;
use BsCms\Auth\PasswordHasher;
use BsCms\Auth\PasswordResetService;
use BsCms\Auth\SessionManager;
use BsCms\Auth\TwoFactorService;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Mail\MailerService;
use BsCms\Mail\NullTransport;
use BsCms\Mail\TemplateRenderer;
use BsCms\Security\Ulid;
use BsCms\Users\RoleRepository;
use BsCms\Users\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthDomainServicesIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private PDO $adminPdo;
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
        $this->dbName = 'bs_authsvc_' . bin2hex(random_bytes(4));

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
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $this->dbName,
            'username' => $user,
            'password' => $pass,
            'charset' => 'utf8mb4',
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
        $this->templates = new TemplateRenderer();
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo) && $this->dbName !== '') {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }

        parent::tearDown();
    }

    public function testLoginSuccessAndLockoutAfterFiveFailures(): void
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

        $password = 'CorrectHorseBatteryStaple123!';
        $hash = $this->hasher->hash($password);
        $userId = $this->users->create([
            'email' => 'login_test@example.com',
            'password_hash' => $hash,
            'name' => 'Login Tester',
            'status' => 'active',
        ]);

        // Non-existent email fails with standard message
        $failUnknown = $loginService->login('nonexistent@example.com', 'SomePassword123!');
        $this->assertSame('failed', $failUnknown['status']);
        $this->assertSame('Email or password is incorrect.', $failUnknown['message']);

        // 4 failed password attempts
        for ($i = 1; $i <= 4; $i++) {
            $res = $loginService->login('login_test@example.com', 'WrongPassword123!');
            $this->assertSame('failed', $res['status']);
        }

        $user = $this->users->findById($userId);
        $this->assertNotNull($user);
        $this->assertSame(4, (int) $user['failed_logins']);
        $this->assertNull($user['locked_until']);

        // 5th failed password attempt triggers account lockout
        $res5 = $loginService->login('login_test@example.com', 'WrongPassword123!');
        $this->assertSame('failed', $res5['status']);

        $userLocked = $this->users->findById($userId);
        $this->assertNotNull($userLocked);
        $this->assertSame(5, (int) $userLocked['failed_logins']);
        $this->assertNotNull($userLocked['locked_until']);

        // Next login attempt is rejected as locked
        $resLocked = $loginService->login('login_test@example.com', $password);
        $this->assertSame('locked', $resLocked['status']);

        // Unlock user
        $this->users->unlock($userId);

        // Successful login
        $resSuccess = $loginService->login('login_test@example.com', $password);
        $this->assertSame('success', $resSuccess['status']);
        $this->assertArrayHasKey('session', $resSuccess);

        $userAfterLogin = $this->users->findById($userId);
        $this->assertNotNull($userAfterLogin);
        $this->assertSame(0, (int) $userAfterLogin['failed_logins']);
        $this->assertNotNull($userAfterLogin['last_login_at']);
    }

    public function testPasswordResetFlow(): void
    {
        $resetService = new PasswordResetService(
            $this->connection,
            $this->users,
            $this->hasher,
            $this->sessions,
            $this->auditLogger,
            $this->mailer,
            $this->templates
        );

        $oldPassword = 'OldSecretPassword123!';
        $userId = $this->users->create([
            'email' => 'reset_test@example.com',
            'password_hash' => $this->hasher->hash($oldPassword),
            'name' => 'Reset Tester',
            'status' => 'active',
        ]);

        // Establish an active session
        $this->sessions->create($userId);
        $this->assertCount(1, $this->sessions->listActiveForUser($userId));

        // Request reset
        $resetService->requestReset('reset_test@example.com');

        // Fetch token hash from DB
        $pdo = $this->connection->getPdo();
        $table = $this->connection->prefixTable('password_resets');
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `user_id` = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertNull($row['used_at']);

        // Reset password with mock token
        // Directly test validateToken & resetPassword with a generated token
        $rawBytes = random_bytes(32);
        $rawToken = rtrim(strtr(base64_encode($rawBytes), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $rawToken);

        $up = $pdo->prepare("UPDATE `{$table}` SET `token_hash` = ? WHERE `id` = ?");
        $up->execute([$tokenHash, $row['id']]);

        $newPassword = 'NewSecretPassword456!';
        $resetOk = $resetService->resetPassword($rawToken, $newPassword);
        $this->assertTrue($resetOk);

        // Verify sessions were revoked
        $this->assertCount(0, $this->sessions->listActiveForUser($userId));

        // Verify new password works
        $updatedUser = $this->users->findById($userId);
        $this->assertNotNull($updatedUser);
        $this->assertTrue($this->hasher->verify($newPassword, (string) $updatedUser['password_hash']));

        // Token cannot be reused
        $this->assertFalse($resetService->resetPassword($rawToken, 'AnotherPassword789!'));
    }

    public function testInvitationFlow(): void
    {
        $invitationService = new InvitationService(
            $this->connection,
            $this->users,
            $this->roles,
            $this->hasher,
            $this->auditLogger,
            $this->mailer,
            $this->templates
        );

        $adminId = $this->users->create([
            'email' => 'admin_inviter@example.com',
            'name' => 'Admin Inviter',
            'status' => 'active',
        ]);

        $editorRole = $this->roles->findByHandle('editor');
        $this->assertNotNull($editorRole);

        $token = $invitationService->invite(
            'new_member@example.com',
            (string) $editorRole['id'],
            $adminId
        );
        $this->assertNotEmpty($token);

        // Validate token
        $inviteData = $invitationService->validateToken($token);
        $this->assertNotNull($inviteData);
        $this->assertSame('new_member@example.com', $inviteData['email']);

        // Accept invitation
        $newUserId = $invitationService->accept($token, 'New Member', 'StrongPassword123!');
        $this->assertNotEmpty($newUserId);

        $newUser = $this->users->findById($newUserId);
        $this->assertNotNull($newUser);
        $this->assertSame('active', $newUser['status']);
        $this->assertSame('New Member', $newUser['name']);

        $roles = $this->users->getRoles($newUserId);
        $this->assertCount(1, $roles);
        $this->assertSame('editor', $roles[0]['handle']);
    }

    public function testEmailVerificationAndChangeFlow(): void
    {
        $verService = new EmailVerificationService(
            $this->connection,
            $this->users,
            $this->auditLogger,
            $this->mailer,
            $this->templates
        );

        $userId = $this->users->create([
            'email' => 'old_email@example.com',
            'name' => 'Email Changer',
            'status' => 'active',
        ]);

        // Request change to new email
        $token = $verService->sendVerification($userId, 'new_email@example.com');
        $this->assertNotEmpty($token);

        // Verify token
        $verified = $verService->verify($token);
        $this->assertTrue($verified);

        $userAfter = $this->users->findById($userId);
        $this->assertNotNull($userAfter);
        $this->assertSame('new_email@example.com', $userAfter['email']);
        $this->assertNotNull($userAfter['email_verified_at']);
    }

    public function testApiTokenLifecycle(): void
    {
        $tokenService = new ApiTokenService($this->connection, $this->auditLogger);

        $userId = $this->users->create([
            'email' => 'token_user@example.com',
            'name' => 'Token User',
            'status' => 'active',
        ]);

        // Create token
        $created = $tokenService->create(
            $userId,
            'CI Deployment Token',
            null,
            ['read:posts', 'write:posts'],
            'approval_required'
        );

        $this->assertArrayHasKey('token', $created);
        $plainToken = (string) $created['token'];
        $this->assertStringStartsWith('bstok_', $plainToken);

        // Authenticate with valid token
        $authResult = $tokenService->authenticate($plainToken, '127.0.0.1');
        $this->assertNotNull($authResult);
        $this->assertSame($userId, $authResult['user_id']);
        $this->assertSame(['read:posts', 'write:posts'], $authResult['scopes']);
        $this->assertSame('approval_required', $authResult['ai_mode']);

        // Revoke token
        $revoked = $tokenService->revoke((string) $created['id'], $userId);
        $this->assertTrue($revoked);

        // Authenticate with revoked token fails
        $this->assertNull($tokenService->authenticate($plainToken));
    }
}
