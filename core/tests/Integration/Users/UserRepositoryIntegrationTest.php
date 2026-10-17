<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Users;

use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Security\Ulid;
use BsCms\Users\RoleRepository;
use BsCms\Users\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserRepositoryIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private PDO $adminPdo;
    private UserRepository $users;
    private RoleRepository $roles;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_user_' . bin2hex(random_bytes(4));

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
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo) && $this->dbName !== '') {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }

        parent::tearDown();
    }

    public function testUserCrudAndRoles(): void
    {
        $ownerRole = $this->roles->findByHandle('owner');
        $this->assertNotNull($ownerRole);

        $userId = $this->users->create([
            'email' => 'owner@example.com',
            'name' => 'Owner User',
            'status' => 'active',
        ], [(string) $ownerRole['id']]);

        $user = $this->users->findById($userId);
        $this->assertNotNull($user);
        $this->assertSame('owner@example.com', $user['email']);
        $this->assertSame('Owner User', $user['name']);
        $this->assertSame(1, $this->users->countActiveOwners());

        // Update user
        $this->users->update($userId, ['name' => 'Updated Name']);
        $updated = $this->users->findById($userId);
        $this->assertNotNull($updated);
        $this->assertSame('Updated Name', $updated['name']);

        // Soft delete
        $this->users->delete($userId);
        $this->assertNull($this->users->findById($userId));
        $this->assertNotNull($this->users->findById($userId, true));
        $this->assertSame(0, $this->users->countActiveOwners());
    }

    public function testUserPaginationAndSearch(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->users->create([
                'email' => "user{$i}@example.com",
                'name' => "User {$i}",
                'status' => 'active',
            ]);
        }

        $page1 = $this->users->list([], 'created_at', 'DESC', 3);
        $this->assertCount(3, $page1['items']);
        $this->assertNotNull($page1['next_cursor']);

        $page2 = $this->users->list([], 'created_at', 'DESC', 3, $page1['next_cursor']);
        $this->assertCount(2, $page2['items']);
        $this->assertNull($page2['next_cursor']);

        $search = $this->users->list(['search' => 'user3']);
        $this->assertCount(1, $search['items']);
        $this->assertSame('user3@example.com', $search['items'][0]['email']);
    }
}
