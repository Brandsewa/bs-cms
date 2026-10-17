<?php

declare(strict_types=1);

namespace BsCms\Jobs;

use BsCms\Database\Connection;
use BsCms\Kernel\Container;
use BsCms\Log\FileLogger;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use PDO;
use Throwable;

/**
 * Worker: reserves jobs optimistically, runs them within strict time and memory budgets,
 * implements exponential backoff on failure, and releases stuck jobs.
 */
final class Worker
{
    private Connection $connection;
    private Container $container;
    private ?FileLogger $logger;

    public function __construct(Connection $connection, Container $container, ?FileLogger $logger = null)
    {
        $this->connection = $connection;
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Run jobs from the queue until time budget expires, memory threshold is reached, or queue is empty.
     *
     * @param int $maxTimeSeconds Time budget in seconds (5 s for lazy runner, 50 s for cron)
     * @param string $queue Target queue name
     * @return int Number of successfully processed jobs
     */
    public function run(int $maxTimeSeconds = 50, string $queue = 'default'): int
    {
        $startTime = microtime(true);
        $processed = 0;

        // Step 1: Release stuck jobs (running > 10 minutes)
        $this->releaseStuckJobs();

        while (true) {
            // Check time budget
            if ((microtime(true) - $startTime) >= $maxTimeSeconds) {
                break;
            }

            // Check memory guard (stop at 80% of memory_limit)
            if ($this->isMemoryExceeded()) {
                $this->logger?->warning('Worker stopping: memory limit guard reached (80%).');
                break;
            }

            // Reserve next available job
            $job = $this->reserveNextJob($queue);
            if ($job === null) {
                // No jobs due
                break;
            }

            $success = $this->processJob($job);
            if ($success) {
                ++$processed;
            }
        }

        return $processed;
    }

    /**
     * Optimistically reserve the next due job:
     * Picks lowest available_at and atomically updates status to 'running'.
     *
     * @return array<string, mixed>|null
     */
    public function reserveNextJob(string $queue = 'default'): ?array
    {
        $pdo = $this->connection->getPdo();
        $jobsTable = $this->connection->prefixTable('jobs');
        $now = Clock::sql(Clock::now());

        // Find candidate job
        $sql = "SELECT `id` FROM `{$jobsTable}`
                WHERE `status` = 'queued'
                  AND `queue` = ?
                  AND `available_at` <= ?
                ORDER BY `available_at` ASC, `id` ASC
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$queue, $now]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            return null;
        }

        // Optimistic reservation: update only if still queued
        $updateSql = "UPDATE `{$jobsTable}`
                      SET `status` = 'running', `reserved_at` = ?
                      WHERE `id` = ? AND `status` = 'queued'";

        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([$now, $id]);

        if ($updateStmt->rowCount() === 0) {
            // Another worker grabbed it concurrently
            return null;
        }

        // Fetch full job data
        $fetchStmt = $pdo->prepare("SELECT * FROM `{$jobsTable}` WHERE `id` = ?");
        $fetchStmt->execute([$id]);
        /** @var array<string, mixed>|false $job */
        $job = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        return $job !== false ? $job : null;
    }

    /**
     * Process a reserved job row.
     *
     * @param array<string, mixed> $job
     */
    public function processJob(array $job): bool
    {
        $id = $job['id'];
        $type = (string) $job['type'];
        $payloadRaw = (string) $job['payload'];
        $payload = Json::decode($payloadRaw);
        if (!is_array($payload)) {
            $payload = [];
        }

        $attempts = (int) $job['attempts'] + 1;
        $maxAttempts = (int) $job['max_attempts'];
        $jobsTable = $this->connection->prefixTable('jobs');
        $pdo = $this->connection->getPdo();

        try {
            // Instantiate handler via container if bound, otherwise new $type
            /** @var Job $handler */
            $handler = $this->container->has($type)
                ? $this->container->get($type)
                : (class_exists($type) ? new $type() : throw new \RuntimeException("Job handler class '{$type}' does not exist."));

            $handler->handle($payload);

            // Mark job done
            $doneSql = "UPDATE `{$jobsTable}`
                        SET `status` = 'done', `attempts` = ?, `last_error` = NULL
                        WHERE `id` = ?";
            $pdo->prepare($doneSql)->execute([$attempts, $id]);

            return true;
        } catch (Throwable $e) {
            $errorMsg = sprintf("%s: %s\n%s", get_class($e), $e->getMessage(), $e->getTraceAsString());
            $this->logger?->error("Job {$id} ({$type}) failed on attempt {$attempts}: {$e->getMessage()}");

            if ($attempts >= $maxAttempts) {
                // Permanently failed
                $failSql = "UPDATE `{$jobsTable}`
                            SET `status` = 'failed', `attempts` = ?, `last_error` = ?
                            WHERE `id` = ?";
                $pdo->prepare($failSql)->execute([$attempts, $errorMsg, $id]);
            } else {
                // Exponential backoff: 2^attempts * 10 seconds (e.g. 20s, 40s, 80s, 160s)
                $delaySeconds = (int) (pow(2, $attempts) * 10);
                $retryAt = Clock::sql(Clock::now()->modify("+{$delaySeconds} seconds"));

                $retrySql = "UPDATE `{$jobsTable}`
                             SET `status` = 'queued', `attempts` = ?, `available_at` = ?, `reserved_at` = NULL, `last_error` = ?
                             WHERE `id` = ?";
                $pdo->prepare($retrySql)->execute([$attempts, $retryAt, $errorMsg, $id]);
            }

            return false;
        }
    }

    /**
     * Release jobs stuck in 'running' status for more than 10 minutes.
     */
    public function releaseStuckJobs(): int
    {
        $cutoff = Clock::sql(Clock::now()->modify('-10 minutes'));
        $jobsTable = $this->connection->prefixTable('jobs');

        $sql = "UPDATE `{$jobsTable}`
                SET `status` = 'queued', `reserved_at` = NULL
                WHERE `status` = 'running' AND `reserved_at` <= ?";

        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }

    /**
     * Check if memory usage exceeds 80% of PHP memory_limit.
     */
    public function isMemoryExceeded(): bool
    {
        $memoryLimit = (string) ini_get('memory_limit');
        if ($memoryLimit === '-1' || $memoryLimit === '') {
            return false;
        }

        $limitBytes = $this->parseMemoryBytes((string) $memoryLimit);
        $currentBytes = memory_get_usage(true);

        return ($currentBytes / $limitBytes) >= 0.80;
    }

    private function parseMemoryBytes(string $val): int
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int) substr($val, 0, -1);

        return match ($last) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $val,
        };
    }
}
