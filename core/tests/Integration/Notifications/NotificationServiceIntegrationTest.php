<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Notifications;

use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Notifications\NotificationService;
use BsCms\Security\Ulid;
use PDO;
use PHPUnit\Framework\TestCase;

final class NotificationServiceIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private PDO $adminPdo;
    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_notif_' . bin2hex(random_bytes(4));

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

        $this->service = new NotificationService($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo) && $this->dbName !== '') {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }

        parent::tearDown();
    }

    public function testNotificationLifecycle(): void
    {
        $userId = Ulid::generate();

        $id1 = $this->service->notify($userId, 'info', 'First notification', 'Body 1', '/link/1');
        $id2 = $this->service->notify($userId, 'warning', 'Second notification', 'Body 2');
        $id3 = $this->service->notify($userId, 'alert', 'Third notification');

        $this->assertSame(3, $this->service->unreadCount($userId));

        // Get notifications
        $result = $this->service->getForUser($userId, false, 2);
        $this->assertCount(2, $result['items']);
        $this->assertSame(3, $result['unread_count']);
        $this->assertNotNull($result['next_cursor']);

        // Next page
        $result2 = $this->service->getForUser($userId, false, 2, $result['next_cursor']);
        $this->assertCount(1, $result2['items']);

        // Mark single as read
        $updated = $this->service->markAsRead($userId, [$id1]);
        $this->assertSame(1, $updated);
        $this->assertSame(2, $this->service->unreadCount($userId));

        // Mark all as read
        $allUpdated = $this->service->markAllAsRead($userId);
        $this->assertSame(2, $allUpdated);
        $this->assertSame(0, $this->service->unreadCount($userId));
    }
}
