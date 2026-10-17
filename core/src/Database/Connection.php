<?php

declare(strict_types=1);

namespace BsCms\Database;

use BsCms\Log\FileLogger;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * PDO Connection manager with strict mode, version compatibility checks,
 * table prefixing, query timings, and slow query warnings.
 */
class Connection
{
    private PDO $pdo;
    private string $prefix;
    private string $serverType; // 'mysql' | 'mariadb'
    private string $serverVersion;
    private ?FileLogger $logger;
    private bool $debug;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config, ?FileLogger $logger = null, bool $debug = false)
    {
        $this->logger = $logger;
        $this->debug = $debug;
        $this->prefix = (string) ($config['prefix'] ?? 'bs_');

        if (isset($config['pdo']) && $config['pdo'] instanceof PDO) {
            $this->pdo = $config['pdo'];
        } else {
            $host = (string) ($config['host'] ?? '127.0.0.1');
            $port = (int) ($config['port'] ?? 3306);
            $database = (string) ($config['database'] ?? '');
            $username = (string) ($config['username'] ?? '');
            $password = (string) ($config['password'] ?? '');
            $charset = (string) ($config['charset'] ?? 'utf8mb4');

            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ];

            try {
                $this->pdo = new PDO($dsn, $username, $password, $options);
            } catch (PDOException $e) {
                throw new RuntimeException("Database connection failed: {$e->getMessage()}", (int) $e->getCode(), $e);
            }
        }

        $this->configureSession();
        $this->detectAndValidateVersion();
    }

    private function configureSession(): void
    {
        // Enforce strict time zone and SQL modes
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }

    private function detectAndValidateVersion(): void
    {
        /** @var string $rawVersion */
        $rawVersion = $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

        if (stripos($rawVersion, 'mariadb') !== false) {
            $this->serverType = 'mariadb';
            // Extract MariaDB version number e.g. 10.6.12-MariaDB
            preg_match('/(\d+\.\d+(\.\d+)?)/', $rawVersion, $matches);
            $this->serverVersion = $matches[1] ?? '10.6.0';
            if (version_compare($this->serverVersion, '10.6.0', '<')) {
                throw new RuntimeException("MariaDB version {$this->serverVersion} is unsupported. BS CMS requires MariaDB >= 10.6.0.");
            }
        } else {
            $this->serverType = 'mysql';
            preg_match('/(\d+\.\d+(\.\d+)?)/', $rawVersion, $matches);
            $this->serverVersion = $matches[1] ?? '8.0.0';
            if (version_compare($this->serverVersion, '8.0.0', '<')) {
                throw new RuntimeException("MySQL version {$this->serverVersion} is unsupported. BS CMS requires MySQL >= 8.0.0.");
            }
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getServerType(): string
    {
        return $this->serverType;
    }

    public function getServerVersion(): string
    {
        return $this->serverVersion;
    }

    /**
     * Prefix and quote a table name: 'users' -> `bs_users`
     */
    public function table(string $name): QueryBuilder
    {
        return (new QueryBuilder($this))->from($name);
    }

    public function prefixTable(string $name): string
    {
        if (str_starts_with($name, $this->prefix)) {
            return $name;
        }
        return $this->prefix . $name;
    }

    /**
     * Execute a callback inside a database transaction with nested savepoints and deadlock retries (up to 3).
     *
     * @template T
     * @param callable(Connection): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $maxAttempts = 3;
        $attempt = 0;

        while (true) {
            ++$attempt;
            try {
                $inTransaction = $this->pdo->inTransaction();
                $savepointName = null;

                if (!$inTransaction) {
                    $this->pdo->beginTransaction();
                } else {
                    $savepointName = 'sp_' . bin2hex(random_bytes(4));
                    $this->pdo->exec("SAVEPOINT {$savepointName}");
                }

                try {
                    $result = $callback($this);

                    if ($savepointName !== null) {
                        $this->pdo->exec("RELEASE SAVEPOINT {$savepointName}");
                    } else {
                        $this->pdo->commit();
                    }

                    return $result;
                } catch (Throwable $e) {
                    if ($savepointName !== null) {
                        $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepointName}");
                    } else {
                        $this->pdo->rollBack();
                    }
                    throw $e;
                }
            } catch (PDOException $e) {
                // MySQL / MariaDB deadlock error code 1213 or lock wait timeout 1205
                $code = (int) ($e->errorInfo[1] ?? 0);
                if (($code === 1213 || $code === 1205) && $attempt < $maxAttempts) {
                    usleep($attempt * 50000); // Backoff 50ms, 100ms
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Execute a statement with timing and logging.
     *
     * @param array<int|string, mixed> $params
     * @return \PDOStatement
     */
    public function execute(string $sql, array $params = []): \PDOStatement
    {
        $start = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $durationMs = (microtime(true) - $start) * 1000;

        if ($this->debug && $this->logger !== null) {
            $this->logger->debug('SQL query executed', [
                'sql' => $sql,
                'params' => $params,
                'duration_ms' => round($durationMs, 2),
            ]);
        }

        if ($durationMs > 200 && $this->logger !== null) {
            $this->logger->warning('Slow SQL query detected (>200ms)', [
                'sql' => $sql,
                'duration_ms' => round($durationMs, 2),
            ]);
        }

        return $stmt;
    }
}
