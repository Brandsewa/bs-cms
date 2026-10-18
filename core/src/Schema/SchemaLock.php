<?php

declare(strict_types=1);

namespace BsCms\Schema;

use BsCms\Database\Connection;
use RuntimeException;

final class SchemaLock
{
    private const LOCK_NAME = 'bs_schema';
    private const DEFAULT_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Acquire the named distributed schema lock.
     * Throws RuntimeException if the lock cannot be acquired within the timeout.
     */
    public function acquire(int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): void
    {
        $stmt = $this->connection->execute(
            'SELECT GET_LOCK(?, ?) AS lock_acquired',
            [self::LOCK_NAME, $timeoutSeconds]
        );
        $row = $stmt->fetch();
        $acquired = (int) ($row['lock_acquired'] ?? 0);

        if ($acquired !== 1) {
            throw new RuntimeException(
                sprintf('Failed to acquire schema lock after %d seconds. Another schema operation is in progress.', $timeoutSeconds)
            );
        }
    }

    /**
     * Release the named distributed schema lock.
     */
    public function release(): void
    {
        $this->connection->execute(
            'SELECT RELEASE_LOCK(?) AS lock_released',
            [self::LOCK_NAME]
        );
    }

    /**
     * Execute a callable while holding the distributed schema lock.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function withLock(callable $operation, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): mixed
    {
        $this->acquire($timeoutSeconds);
        try {
            return $operation();
        } finally {
            $this->release();
        }
    }
}
