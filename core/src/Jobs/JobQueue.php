<?php

declare(strict_types=1);

namespace BsCms\Jobs;

use BsCms\Database\Connection;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use DateTimeInterface;

/**
 * JobQueue: pushes jobs to the `bs_jobs` database table.
 */
final class JobQueue
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param string $type FQCN or registered job type identifier
     * @param array<string, mixed> $payload
     * @param int|DateTimeInterface|null $delay Delay in seconds or specific execution time
     */
    public function push(
        string $type,
        array $payload = [],
        int|DateTimeInterface|null $delay = null,
        string $queue = 'default',
        int $maxAttempts = 5
    ): bool {
        $now = Clock::now();

        if ($delay instanceof DateTimeInterface) {
            $availableAt = Clock::from($delay);
        } elseif (is_int($delay) && $delay > 0) {
            $availableAt = $now->modify("+{$delay} seconds");
        } else {
            $availableAt = $now;
        }

        return $this->connection->table('jobs')->insert([
            'queue' => $queue,
            'type' => $type,
            'payload' => Json::encode($payload),
            'status' => 'queued',
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'available_at' => Clock::sql($availableAt),
            'reserved_at' => null,
            'last_error' => null,
            'created_at' => Clock::sql($now),
        ]);
    }
}
