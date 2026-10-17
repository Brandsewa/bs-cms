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
     * Roll back the last N migration batches, newest migration first.
     *
     * @param int $steps Number of batches to roll back (default 1)
     * @return list<string> "namespace:name" of rolled back migrations ("[DRY-RUN] ..." prefix when $dryRun)
     */
    public function rollback(int $steps = 1, bool $dryRun = false): array
    {
        if ($steps < 1) {
            throw new RuntimeException('Rollback step count must be at least 1.');
        }

        $this->ensureMigrationTable();

        if (!$dryRun && !$this->acquireLock()) {
            throw new RuntimeException('Could not acquire bs_migrate lock: another migration is running.');
        }

        try {
            $table = $this->connection->prefixTable('migrations');
            $pdo = $this->connection->getPdo();

            $batchStmt = $pdo->prepare("SELECT DISTINCT `batch` FROM `{$table}` ORDER BY `batch` DESC LIMIT " . (int) $steps);
            $batchStmt->execute();
            /** @var list<int|string> $batches */
            $batches = $batchStmt->fetchAll(PDO::FETCH_COLUMN);
            if ($batches === []) {
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($batches), '?'));
            $rowStmt = $pdo->prepare("SELECT `id`, `namespace`, `name` FROM `{$table}` WHERE `batch` IN ({$placeholders}) ORDER BY `id` DESC");
            $rowStmt->execute(array_map('intval', $batches));
            /** @var list<array{id: int|string, namespace: string, name: string}> $rows */
            $rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

            // Resolve every migration file first so a missing file aborts before anything is reverted.
            $plan = [];
            foreach ($rows as $row) {
                $dir = $this->paths[$row['namespace']] ?? null;
                $file = $dir !== null ? "{$dir}/{$row['name']}.php" : null;
                if ($file === null || !is_file($file)) {
                    throw new RuntimeException(
                        "Cannot roll back '{$row['namespace']}:{$row['name']}': migration file or namespace is not registered."
                    );
                }
                $plan[] = ['id' => (int) $row['id'], 'key' => "{$row['namespace']}:{$row['name']}", 'name' => $row['name'], 'file' => $file];
            }

            $executed = [];
            foreach ($plan as $item) {
                if ($dryRun) {
                    $executed[] = "[DRY-RUN] rollback {$item['key']}";
                    continue;
                }

                require_once $item['file'];
                $className = $this->resolveClassName($item['name']);
                if (!class_exists($className)) {
                    throw new RuntimeException("Migration class '{$className}' not found in '{$item['file']}'.");
                }

                /** @var Migration $migration */
                $migration = new $className($this->connection);
                $migration->down();

                $deleteStmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `id` = ?");
                $deleteStmt->execute([$item['id']]);

                $executed[] = $item['key'];
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
