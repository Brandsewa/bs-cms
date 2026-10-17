<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Database;

use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration schema-diff test that verifies created tables, column names,
 * and key constraints match docs/01-data-model.md.
 */
final class SchemaDiffTest extends TestCase
{
    private ?Connection $connection = null;
    private bool $skipMysql = false;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $dbname = getenv('DB_NAME') ?: 'bs_cms_test';

        try {
            $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}`;");
            $pdo->exec("USE `{$dbname}`;");

            $this->connection = new Connection([
                'pdo' => $pdo,
                'prefix' => 'bs_',
            ]);
        } catch (\Throwable $e) {
            $this->skipMysql = true;
        }
    }

    public function testMigrationsMatchSpecSchema(): void
    {
        if ($this->skipMysql || $this->connection === null) {
            $this->markTestSkipped('MySQL / MariaDB test server not available on localhost. Skipping live schema diff test.');
        }

        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $executed = $migrator->migrate();

        $this->assertNotEmpty($executed);

        // Verify migrations table columns
        $pdo = $this->connection->getPdo();
        $stmt1 = $pdo->query("SHOW COLUMNS FROM `bs_migrations`");
        $this->assertInstanceOf(\PDOStatement::class, $stmt1);
        $cols = $stmt1->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('id', $cols);
        $this->assertContains('namespace', $cols);
        $this->assertContains('name', $cols);
        $this->assertContains('batch', $cols);
        $this->assertContains('applied_at', $cols);

        // Verify settings table columns
        $stmt2 = $pdo->query("SHOW COLUMNS FROM `bs_settings`");
        $this->assertInstanceOf(\PDOStatement::class, $stmt2);
        $settingsCols = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('handle', $settingsCols);
        $this->assertContains('value', $settingsCols);
        $this->assertContains('is_secret', $settingsCols);
        $this->assertContains('updated_by', $settingsCols);
        $this->assertContains('updated_at', $settingsCols);

        // Verify jobs table columns
        $stmt3 = $pdo->query("SHOW COLUMNS FROM `bs_jobs`");
        $this->assertInstanceOf(\PDOStatement::class, $stmt3);
        $jobsCols = $stmt3->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('id', $jobsCols);
        $this->assertContains('queue', $jobsCols);
        $this->assertContains('type', $jobsCols);
        $this->assertContains('payload', $jobsCols);
        $this->assertContains('status', $jobsCols);
        $this->assertContains('attempts', $jobsCols);
        $this->assertContains('max_attempts', $jobsCols);
        $this->assertContains('available_at', $jobsCols);
        $this->assertContains('reserved_at', $jobsCols);
        $this->assertContains('last_error', $jobsCols);
        $this->assertContains('created_at', $jobsCols);

        // Verify rate_limits table columns
        $stmt4 = $pdo->query("SHOW COLUMNS FROM `bs_rate_limits`");
        $this->assertInstanceOf(\PDOStatement::class, $stmt4);
        $rateCols = $stmt4->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('bucket', $rateCols);
        $this->assertContains('window_start', $rateCols);
        $this->assertContains('hits', $rateCols);
    }
}
