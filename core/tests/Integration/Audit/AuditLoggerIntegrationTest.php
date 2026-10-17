<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Audit;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuditLoggerIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private PDO $adminPdo;
    private AuditLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_aud_' . bin2hex(random_bytes(4));

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

        $this->logger = new AuditLogger($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo) && $this->dbName !== '') {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }
        parent::tearDown();
    }

    public function testRecordAndQueryWithSecretRedaction(): void
    {
        $actor = Actor::user(Ulid::generate(), 'Alice', ['role_owner']);

        // Diff containing secret passwords and tokens
        $diff = [
            'name' => 'Alice Updated',
            'password' => 'SuperSecretPassword123!',
            'api_token' => 'plain_secret_token_abc',
            'nested' => [
                'smtp_password' => 'secret_smtp_pass',
                'description' => 'Regular description',
            ],
        ];

        $insertId = $this->logger->record(
            actor: $actor,
            action: 'user.updated',
            subject: Subject::users('usr_123'),
            summary: 'Updated user credentials',
            diff: $diff,
            result: 'success',
            ip: '127.0.0.1',
            userAgent: 'CLI Agent'
        );

        $this->assertGreaterThan(0, $insertId);

        // Query audit log
        $res = $this->logger->query(['action' => 'user.updated'], 10);
        $this->assertCount(1, $res['items']);

        $entry = $res['items'][0];
        $this->assertSame('user.updated', $entry->action);
        $this->assertSame('users', $entry->subjectType);
        $this->assertSame('Alice', $entry->actorLabel);

        // Verify secrets were redacted in diff!
        $this->assertNotNull($entry->diff);
        $this->assertSame('Alice Updated', $entry->diff['name']);
        $this->assertSame('[REDACTED]', $entry->diff['password']);
        $this->assertSame('[REDACTED]', $entry->diff['api_token']);
        $this->assertSame('[REDACTED]', $entry->diff['nested']['smtp_password']);
        $this->assertSame('Regular description', $entry->diff['nested']['description']);
    }

    public function testQueryFiltersAndPagination(): void
    {
        $actor = Actor::user(Ulid::generate(), 'Bob', ['role_editor']);

        for ($i = 1; $i <= 5; ++$i) {
            $this->logger->record(
                actor: $actor,
                action: "entry.update_{$i}",
                subject: Subject::collection('posts'),
                summary: "Update {$i}"
            );
        }

        // Query with limit 2
        $page1 = $this->logger->query([], 2);
        $this->assertCount(2, $page1['items']);
        $this->assertNotNull($page1['next_cursor']);

        // Query next page with cursor
        $page2 = $this->logger->query([], 2, $page1['next_cursor']);
        $this->assertCount(2, $page2['items']);
        $this->assertNotSame($page1['items'][0]->id, $page2['items'][0]->id);
    }

    public function testPruneRemovesOldEntries(): void
    {
        $actor = Actor::system();
        $table = $this->connection->prefixTable('audit_log');
        $oldDate = Clock::sql(Clock::now()->modify('-200 days'));

        $this->connection->getPdo()->exec("INSERT INTO `{$table}` (
            `request_id`, `actor_type`, `action`, `subject_type`, `result`, `created_at`
        ) VALUES (
            'req_old', 'system', 'cron.test', 'system', 'success', '{$oldDate}'
        )");

        $this->assertSame(1, $this->logger->prune(180));
    }
}
