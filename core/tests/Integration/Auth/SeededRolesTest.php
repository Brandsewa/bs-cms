<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Auth;

use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Migrations\SeedSystemRoles;
use PDO;
use PHPUnit\Framework\TestCase;

final class SeededRolesTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private PDO $adminPdo;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_roles_' . bin2hex(random_bytes(4));

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
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo) && $this->dbName !== '') {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }
        parent::tearDown();
    }

    public function testNineRolesAreSeededCorrectly(): void
    {
        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $migrator->migrate();

        $pdo = $this->connection->getPdo();
        $rolesStmt = $pdo->query("SELECT `id`, `handle`, `name`, `is_system`, `is_ai` FROM `bs_roles` ORDER BY `id` ASC");
        $this->assertInstanceOf(\PDOStatement::class, $rolesStmt);
        $roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(9, $roles);

        $handles = array_column($roles, 'handle');
        $expectedHandles = [
            'owner', 'admin', 'developer', 'editor', 'author', 'contributor', 'viewer', 'ai_draft', 'ai_publisher'
        ];
        $this->assertSame($expectedHandles, $handles);

        // Verify Owner permissions
        $ownerPerms = $this->getPermissionsForRole(SeedSystemRoles::ROLE_OWNER);
        $this->assertCount(1, $ownerPerms);
        $this->assertSame('*', $ownerPerms[0]['subject_type']);
        $this->assertSame('*', $ownerPerms[0]['subject_key']);
        $this->assertSame('*', $ownerPerms[0]['action']);
        $this->assertSame('allow', $ownerPerms[0]['effect']);

        // Verify Author permissions have owner=self condition on updates
        $authorPerms = $this->getPermissionsForRole(SeedSystemRoles::ROLE_AUTHOR);
        $updatePerm = array_values(array_filter($authorPerms, fn ($p) => $p['action'] === 'update' && $p['subject_type'] === 'collection'));
        $this->assertNotEmpty($updatePerm);
        $cond = json_decode((string) $updatePerm[0]['conditions'], true);
        $this->assertSame('self', $cond['owner']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getPermissionsForRole(string $roleId): array
    {
        $stmt = $this->connection->getPdo()->prepare("SELECT * FROM `bs_role_permissions` WHERE `role_id` = :role_id");
        $stmt->execute(['role_id' => $roleId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }
}
