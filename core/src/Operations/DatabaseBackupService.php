<?php

declare(strict_types=1);

namespace BsCms\Operations;

use BsCms\Database\Connection;

final class DatabaseBackupService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $backupsDir
    ) {
    }

    /**
     * Creates a full SQL database backup using PDO without exec.
     *
     * @return array{file: string, size_bytes: int, tables: list<string>}
     */
    public function createBackup(?string $name = null): array
    {
        if (!is_dir($this->backupsDir)) {
            mkdir($this->backupsDir, 0755, true);
        }

        $now = date('Ymd_His');
        $filename = ($name ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $name) : 'backup') . "_{$now}.sql";
        $filePath = rtrim($this->backupsDir, '/\\') . '/' . $filename;

        $pdo = $this->connection->getPdo();
        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open backup file for writing: {$filePath}");
        }

        fwrite($handle, "-- BS CMS Database Backup\n");
        fwrite($handle, "-- Generated at: " . gmdate('Y-m-d H:i:s') . " UTC\n");
        fwrite($handle, "-- Strict PDO Dump (Zero Exec)\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tablesStmt = $pdo->query("SHOW TABLES");
        $tables = $tablesStmt !== false ? $tablesStmt->fetchAll(\PDO::FETCH_COLUMN) : [];

        foreach ($tables as $table) {
            $table = (string) $table;

            // Table structure
            $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
            if ($createStmt !== false) {
                $row = $createStmt->fetch(\PDO::FETCH_NUM);
                if (!empty($row[1])) {
                    fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                    fwrite($handle, $row[1] . ";\n\n");
                }
            }

            // Batched data dump
            $selectStmt = $pdo->prepare("SELECT * FROM `{$table}`");
            $selectStmt->execute();

            $batchSize = 200;
            $rows = [];
            while ($row = $selectStmt->fetch(\PDO::FETCH_ASSOC)) {
                $rows[] = $row;
                if (count($rows) >= $batchSize) {
                    $this->writeInsertBatch($handle, $table, $rows, $pdo);
                    $rows = [];
                }
            }
            if (!empty($rows)) {
                $this->writeInsertBatch($handle, $table, $rows, $pdo);
            }
            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        return [
            'file' => $filePath,
            'size_bytes' => filesize($filePath) ?: 0,
            'tables' => $tables,
        ];
    }

    /**
     * Restore database from an SQL backup file using PDO statements.
     */
    public function restoreBackup(string $filePath): int
    {
        if (!is_file($filePath)) {
            throw new \InvalidArgumentException("Backup file does not exist: {$filePath}");
        }

        $sql = file_get_contents($filePath);
        if ($sql === false) {
            throw new \RuntimeException("Could not read backup file: {$filePath}");
        }

        $pdo = $this->connection->getPdo();
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");

        // Split queries safely by semicolon line breaks
        $statements = preg_split('/;\s*[\r\n]+/', $sql);
        $executed = 0;

        foreach ($statements as $stmt) {
            $stmt = trim((string) $stmt);
            if ($stmt === '' || str_starts_with($stmt, '--') || str_starts_with($stmt, '/*')) {
                continue;
            }
            $pdo->exec($stmt);
            $executed++;
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");
        return $executed;
    }

    /**
     * @param resource $handle
     * @param list<array<string, mixed>> $rows
     */
    private function writeInsertBatch($handle, string $table, array $rows, \PDO $pdo): void
    {
        if (empty($rows)) {
            return;
        }

        $columns = array_keys($rows[0]);
        $escapedColumns = array_map(fn($c) => "`{$c}`", $columns);
        $colList = implode(', ', $escapedColumns);

        $valRows = [];
        foreach ($rows as $r) {
            $vals = [];
            foreach ($columns as $c) {
                $val = $r[$c];
                if ($val === null) {
                    $vals[] = 'NULL';
                } elseif (is_int($val) || is_float($val)) {
                    $vals[] = (string) $val;
                } else {
                    $vals[] = $pdo->quote((string) $val);
                }
            }
            $valRows[] = '(' . implode(', ', $vals) . ')';
        }

        fwrite($handle, "INSERT INTO `{$table}` ({$colList}) VALUES\n  " . implode(",\n  ", $valRows) . ";\n");
    }
}
