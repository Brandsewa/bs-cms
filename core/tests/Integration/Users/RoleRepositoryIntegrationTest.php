<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Users;

use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Users\RoleRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RoleRepositoryIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private PDO $adminPdo;
    private RoleRepository $roles;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_role_' . bin2hex(random_bytes(4));

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

        $this->roles = new RoleRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo) && $this->dbName !== '') {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }

        parent::tearDown();
    }

    public function testSystemRolesProtection(): void
    {
        $owner = $this->roles->findByHandle('owner');
        $this->assertNotNull($owner);

        // System role delete is rejected
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete system role');
        $this->roles->delete((string) $owner['id']);
    }

    public function testOwnerPermissionsImmutable(): void
    {
        $owner = $this->roles->findByHandle('owner');
        $this->assertNotNull($owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Owner role permissions are immutable');
        $this->roles->setPermissions((string) $owner['id'], []);
    }

    public function testCustomRoleLifecycleAndCloning(): void
    {
        $editor = $this->roles->findByHandle('editor');
        $this->assertNotNull($editor);

        // Clone editor role
        $clonedId = $this->roles->clone((string) $editor['id'], 'senior_editor', 'Senior Editor', 'Custom cloned editor');
        $this->assertNotEmpty($clonedId);

        $cloned = $this->roles->findById($clonedId);
        $this->assertNotNull($cloned);
        $this->assertSame('senior_editor', $cloned['handle']);
        $this->assertSame('Senior Editor', $cloned['name']);
        $this->assertSame(0, (int) $cloned['is_system']);
        $this->assertNotEmpty($cloned['permissions']);

        // Custom role can be updated
        $this->roles->update($clonedId, ['name' => 'Lead Senior Editor']);
        $updated = $this->roles->findById($clonedId);
        $this->assertNotNull($updated);
        $this->assertSame('Lead Senior Editor', $updated['name']);

        // Custom role can be deleted when no users are assigned
        $deleted = $this->roles->delete($clonedId);
        $this->assertTrue($deleted);
        $this->assertNull($this->roles->findById($clonedId));
    }
}
