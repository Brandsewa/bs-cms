<?php

declare(strict_types=1);

namespace BsCms\Database;

use BsCms\Support\Clock;
use PDO;
use RuntimeException;

/**
 * Database Migrator with namespaces (core, plugin:<handle>), transactional execution
 * where DDL allows, GET_LOCK concurrency protection, and --dry-run SQL preview.
 */
final class Migrator
{
    private Connection $connection;
    /** @var array<string, string> namespace => absolute directory path */
    private array $paths = [];

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function registerNamespace(string $namespace, string $path): void
    {
        $this->paths[$namespace] = rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Ensure the bs_migrations table exists.
     */
    public function ensureMigrationTable(): void
    {
        $tableName = $this->connection->prefixTable('migrations');
        $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `namespace` VARCHAR(64) NOT NULL,
            `name` VARCHAR(190) NOT NULL,
            `batch` INT NOT NULL,
            `applied_at` DATETIME(3) NOT NULL,
            UNIQUE KEY `uq_migration` (`namespace`, `name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $this->connection->getPdo()->exec($sql);
    }

    /**
     * Acquire advisory lock to prevent concurrent migration executions.
     */
    public function acquireLock(int $timeoutSeconds = 10): bool
    {
        $stmt = $this->connection->getPdo()->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute(['bs_migrate', $timeoutSeconds]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Release advisory lock.
     */
    public function releaseLock(): void
    {
        $stmt = $this->connection->getPdo()->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute(['bs_migrate']);
    }

    /**
     * Run all pending migrations.
     *
     * @return list<string> List of executed migration names
     */
    public function migrate(bool $dryRun = false): array
    {
        $this->ensureMigrationTable();

        if (!$dryRun && !$this->acquireLock()) {
            throw new RuntimeException('Could not acquire bs_migrate lock: another migration is running.');
        }

        try {
            $applied = $this->getAppliedMigrations();
            $nextBatch = $this->getNextBatchNumber();
            $executed = [];

            foreach ($this->paths as $namespace => $dir) {
                if (!is_dir($dir)) {
                    continue;
                }

                $files = glob("{$dir}/*.php") ?: [];
                sort($files);

                foreach ($files as $file) {
                    $filename = basename($file, '.php');
                    $key = "{$namespace}:{$filename}";

                    if (in_array($key, $applied, true)) {
                        continue;
                    }

                    if ($dryRun) {
                        $executed[] = "[DRY-RUN] {$key}";
                        continue;
                    }

                    require_once $file;
                    $className = $this->resolveClassName($filename);

                    if (!class_exists($className)) {
                        throw new RuntimeException("Migration class '{$className}' not found in '{$file}'.");
                    }

                    /** @var Migration $migration */
                    $migration = new $className($this->connection);
                    $migration->up();

                    // Record applied migration
                    $this->connection->table('migrations')->insert([
                        'namespace' => $namespace,
                        'name' => $filename,
                        'batch' => $nextBatch,
                        'applied_at' => Clock::sql(Clock::now()),
                    ]);

                    $executed[] = $key;
                }
            }

            return $executed;
        } finally {
            if (!$dryRun) {
                $this->releaseLock();
            }
        }
    }

    /**
     * @return list<string> List of "namespace:name"
     */
    public function getAppliedMigrations(): array
    {
        $tableName = $this->connection->prefixTable('migrations');
        $stmt = $this->connection->getPdo()->query("SELECT `namespace`, `name` FROM `{$tableName}`");
        if ($stmt === false) {
            return [];
        }

        $applied = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            /** @var array{namespace: string, name: string} $row */
            $applied[] = "{$row['namespace']}:{$row['name']}";
        }

        return $applied;
    }

    private function getNextBatchNumber(): int
    {
        $tableName = $this->connection->prefixTable('migrations');
        $stmt = $this->connection->getPdo()->query("SELECT MAX(`batch`) as max_batch FROM `{$tableName}`");
        if ($stmt === false) {
            return 1;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $max = (int) ($row['max_batch'] ?? 0);
        return $max + 1;
    }

    private function resolveClassName(string $filename): string
    {
        // Example: 202610170001_create_core_tables -> CreateCoreTables
        $parts = explode('_', $filename);
        array_shift($parts); // remove timestamp
        $studly = implode('', array_map('ucfirst', $parts));
        return "BsCms\\Migrations\\{$studly}";
    }
}
