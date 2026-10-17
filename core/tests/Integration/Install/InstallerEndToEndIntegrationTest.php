<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Install;

use BsCms\Database\Connection;
use BsCms\Install\InstallerController;
use BsCms\Support\Json;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;

final class InstallerEndToEndIntegrationTest extends TestCase
{
    private string $tempStorageDir;
    private string $dbName;
    private ?PDO $adminPdo = null;
    /** @var array{host: string, port: int, database: string, username: string, password: string, prefix: string} */
    private array $dbConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempStorageDir = sys_get_temp_dir() . '/bs_install_e2e_' . bin2hex(random_bytes(6));
        if (!is_dir($this->tempStorageDir)) {
            mkdir($this->tempStorageDir, 0700, true);
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_inst_e2e_' . bin2hex(random_bytes(4));

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

        $this->dbConfig = [
            'host' => $host,
            'port' => $port,
            'database' => $this->dbName,
            'username' => $user,
            'password' => $pass,
            'prefix' => 'bs_',
        ];
    }

    protected function tearDown(): void
    {
        if ($this->adminPdo !== null) {
            try {
                $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
            } catch (\Throwable $e) {
            }
        }

        $this->removeRecursive($this->tempStorageDir);
        parent::tearDown();
    }

    private function removeRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testInstallerFullFlowCreatesOwnerAndAssignsOwnerRole(): void
    {
        $rootDir = dirname(__DIR__, 3);
        $installer = new InstallerController($rootDir, $this->tempStorageDir);

        // 1. Stage database config
        $csrfToken = 'test_inst_csrf_' . bin2hex(random_bytes(8));
        file_put_contents($this->tempStorageDir . '/install_csrf.tmp', $csrfToken);

        $tmpDir = $this->tempStorageDir . '/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0700, true);
        }
        $stageData = [
            'db_host' => $this->dbConfig['host'],
            'db_port' => $this->dbConfig['port'],
            'db_name' => $this->dbConfig['database'],
            'db_user' => $this->dbConfig['username'],
            'db_password' => $this->dbConfig['password'],
            'db_prefix' => $this->dbConfig['prefix'],
            'cli_php' => 'php',
            'site_url' => 'https://example.com',
            'created_at' => time(),
        ];
        file_put_contents($tmpDir . '/install_stage.tmp', Json::encode($stageData));

        // 2. Execute installation with Owner account
        $ownerEmail = 'sysadmin_' . bin2hex(random_bytes(3)) . '@example.com';
        $ownerPass = 'SuperSecretOwnerPassphrase2026!';
        $ownerName = 'Primary Owner';

        $executeRequest = (new ServerRequest('POST', '/install/execute'))
            ->withParsedBody([
                '_csrf' => $csrfToken,
                'owner_name' => $ownerName,
                'owner_email' => $ownerEmail,
                'owner_password' => $ownerPass,
            ]);

        $response = $installer->handle($executeRequest);
        $this->assertSame(200, $response->getStatusCode(), 'Installation execution should return 200 OK: ' . (string) $response->getBody());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('BS CMS Installed Successfully!', $body);
        $this->assertStringContainsString($ownerEmail, $body);

        // 3. Verify installed.lock exists
        $this->assertTrue($installer->isInstalled());
        $this->assertFileExists($this->tempStorageDir . '/installed.lock');
        $this->assertFileExists($this->tempStorageDir . '/config.php');
        $this->assertFileExists($this->tempStorageDir . '/keys/app.key');

        // 4. Verify Database: Owner user and Owner role in DB
        $conn = new Connection($this->dbConfig);
        $pdo = $conn->getPdo();

        $usersTable = $conn->prefixTable('users');
        $rolesTable = $conn->prefixTable('roles');
        $userRolesTable = $conn->prefixTable('user_roles');
        $auditTable = $conn->prefixTable('audit_log');

        $userStmt = $pdo->prepare("SELECT * FROM `{$usersTable}` WHERE email = ?");
        $userStmt->execute([$ownerEmail]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($userRow);
        $this->assertSame($ownerName, $userRow['name']);
        $this->assertSame('active', $userRow['status']);
        $this->assertNull($userRow['email_verified_at'], 'Email verification should be deferred');
        $this->assertTrue(password_verify($ownerPass, (string) $userRow['password_hash']));

        // Check user_roles mapping to owner role
        $roleStmt = $pdo->prepare("SELECT r.handle FROM `{$userRolesTable}` ur JOIN `{$rolesTable}` r ON ur.role_id = r.id WHERE ur.user_id = ?");
        $roleStmt->execute([$userRow['id']]);
        $roles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains('owner', $roles);

        // Check audit log
        $auditStmt = $pdo->prepare("SELECT * FROM `{$auditTable}` WHERE action = 'user.created' AND subject_id = ?");
        $auditStmt->execute([$userRow['id']]);
        $auditRow = $auditStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($auditRow);
        $this->assertStringContainsString('owner', strtolower((string) $auditRow['summary']));

        // 5. Verify subsequent calls to /install return 404 because installed.lock is present
        $subsequentReq = new ServerRequest('GET', '/install');
        $subsequentResp = $installer->handle($subsequentReq);
        $this->assertSame(404, $subsequentResp->getStatusCode());
    }
}
