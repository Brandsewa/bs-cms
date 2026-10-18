<?php

declare(strict_types=1);

namespace BsCms\Security;

use BsCms\Database\Connection;
use BsCms\Support\Clock;
use PDO;
use Throwable;

/**
 * Fixed window rate limiter with burst tolerance.
 *
 * Supports APCu (if available and enabled), database table `bs_rate_limits` (fallback for shared hosting),
 * or in-memory array (tests/CLI).
 * Keys on REMOTE_ADDR; X-Forwarded-For is honoured strictly when REMOTE_ADDR matches a trusted proxy list.
 */
final class RateLimiter
{
    /** @var array<string, array{count: int, reset_at: int}> In-memory fallback or local store */
    private static array $memoryStore = [];

    /** @var list<string> */
    private array $trustedProxies;
    private int $maxAttempts;
    private int $windowSeconds;
    /** @var Connection|(\Closure(): ?Connection)|null */
    private Connection|\Closure|null $connection;

    /**
     * @param list<string> $trustedProxies
     * @param Connection|(\Closure(): ?Connection)|null $connection
     */
    public function __construct(
        int $maxAttempts = 60,
        int $windowSeconds = 60,
        array $trustedProxies = [],
        Connection|\Closure|null $connection = null
    ) {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->windowSeconds = max(1, $windowSeconds);
        $this->trustedProxies = $trustedProxies;
        $this->connection = $connection;
    }

    /**
     * Resolve the client IP safely from request server/headers.
     * Spoofed X-Forwarded-For is ignored unless the immediate connecting peer is in $trustedProxies.
     *
     * @param array<string, mixed> $serverParams
     * @param array<string, string> $headers
     */
    public function resolveClientIp(array $serverParams, array $headers = []): string
    {
        $remoteAddr = (string) ($serverParams['REMOTE_ADDR'] ?? '127.0.0.1');

        if (!$this->isTrustedProxy($remoteAddr)) {
            // Not a trusted proxy; REMOTE_ADDR is authoritative
            return $remoteAddr;
        }

        // Trusted proxy: inspect X-Forwarded-For
        $xff = $headers['x-forwarded-for'] ?? $serverParams['HTTP_X_FORWARDED_FOR'] ?? null;
        if (is_string($xff) && trim($xff) !== '') {
            $ips = array_map('trim', explode(',', $xff));
            // First non-trusted IP in the chain from right to left, or the leftmost client IP
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP) && !$this->isTrustedProxy($ip)) {
                    return $ip;
                }
            }
        }

        return $remoteAddr;
    }

    /**
     * Attempt an action for a key.
     *
    /**
     * Attempt an action for a key.
     *
     * @return array{allowed: bool, remaining: int, limit: int, reset: int, retry_after: int}
     */
    public function hit(string $key, ?int $maxAttempts = null, ?int $windowSeconds = null): array
    {
        $limit = $maxAttempts !== null ? max(1, $maxAttempts) : $this->maxAttempts;
        $window = $windowSeconds !== null ? max(1, $windowSeconds) : $this->windowSeconds;

        // 1. APCu driver if available
        if (extension_loaded('apcu') && (php_sapi_name() !== 'cli' || (bool) ini_get('apc.enable_cli'))) {
            return $this->hitApcu($key, $limit, $window);
        }

        // 2. Database driver if connection provided
        if ($this->connection !== null) {
            try {
                return $this->hitDatabase($key, $limit, $window);
            } catch (Throwable $e) {
                // Fall back to memory store on DB error
            }
        }

        // 3. Fallback to in-memory store
        return $this->hitMemory($key, $limit, $window);
    }

    /**
     * @return array{allowed: bool, remaining: int, limit: int, reset: int, retry_after: int}
     */
    private function hitApcu(string $key, int $limit, int $window): array
    {
        $now = Clock::timestamp();
        $apcuKey = "bs_rl:{$key}";
        $resetKey = "bs_rl_reset:{$key}";

        $success = false;
        $currentHits = (int) apcu_inc($apcuKey, 1, $success, $window);

        if (!$success || $currentHits === 1) {
            $resetAt = $now + $window;
            apcu_store($resetKey, $resetAt, $window);
        } else {
            /** @var int $storedReset */
            $storedReset = apcu_fetch($resetKey);
            $resetAt = $storedReset > 0 ? $storedReset : ($now + $window);
        }

        if ($currentHits > $limit) {
            $retryAfter = max(1, $resetAt - $now);
            return [
                'allowed' => false,
                'remaining' => 0,
                'limit' => $limit,
                'reset' => $resetAt,
                'retry_after' => $retryAfter,
            ];
        }

        $remaining = max(0, $limit - $currentHits);
        return [
            'allowed' => true,
            'remaining' => $remaining,
            'limit' => $limit,
            'reset' => $resetAt,
            'retry_after' => 0,
        ];
    }

    /**
     * @return array{allowed: bool, remaining: int, limit: int, reset: int, retry_after: int}
     */
    private function hitDatabase(string $key, int $limit, int $window): array
    {
        if ($this->connection instanceof \Closure) {
            try {
                $this->connection = ($this->connection)();
            } catch (Throwable $e) {
                $this->connection = null;
            }
        }

        $now = Clock::timestamp();
        $table = $this->connection?->prefixTable('rate_limits') ?? 'bs_rate_limits';
        $pdo = $this->connection?->getPdo();
        if ($pdo === null) {
            return $this->hitMemory($key, $limit, $window);
        }

        // Align window to nearest window boundary
        $windowStartTs = $now - ($now % $window);
        $windowStart = date('Y-m-d H:i:s', $windowStartTs);
        $resetAt = $windowStartTs + $window;

        // Upsert hits atomically
        $sql = "INSERT INTO `{$table}` (`bucket`, `window_start`, `hits`)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE `hits` = `hits` + 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$key, $windowStart]);

        // Fetch current hits
        $fetchStmt = $pdo->prepare("SELECT `hits` FROM `{$table}` WHERE `bucket` = ? AND `window_start` = ?");
        $fetchStmt->execute([$key, $windowStart]);
        $currentHits = (int) $fetchStmt->fetchColumn();

        if ($currentHits > $limit) {
            $retryAfter = max(1, $resetAt - $now);
            return [
                'allowed' => false,
                'remaining' => 0,
                'limit' => $limit,
                'reset' => $resetAt,
                'retry_after' => $retryAfter,
            ];
        }

        $remaining = max(0, $limit - $currentHits);
        return [
            'allowed' => true,
            'remaining' => $remaining,
            'limit' => $limit,
            'reset' => $resetAt,
            'retry_after' => 0,
        ];
    }

    /**
     * @return array{allowed: bool, remaining: int, limit: int, reset: int, retry_after: int}
     */
    private function hitMemory(string $key, int $limit, int $window): array
    {
        $now = Clock::timestamp();
        $record = self::$memoryStore[$key] ?? null;

        if ($record === null || $record['reset_at'] <= $now) {
            $resetAt = $now + $window;
            self::$memoryStore[$key] = [
                'count' => 1,
                'reset_at' => $resetAt,
            ];

            return [
                'allowed' => true,
                'remaining' => $limit - 1,
                'limit' => $limit,
                'reset' => $resetAt,
                'retry_after' => 0,
            ];
        }

        if ($record['count'] >= $limit) {
            $retryAfter = max(1, $record['reset_at'] - $now);
            return [
                'allowed' => false,
                'remaining' => 0,
                'limit' => $limit,
                'reset' => $record['reset_at'],
                'retry_after' => $retryAfter,
            ];
        }

        ++self::$memoryStore[$key]['count'];
        $remaining = max(0, $limit - self::$memoryStore[$key]['count']);

        return [
            'allowed' => true,
            'remaining' => $remaining,
            'limit' => $limit,
            'reset' => $record['reset_at'],
            'retry_after' => 0,
        ];
    }

    /**
     * Clear all records in memory (useful for tests).
     */
    public static function clear(): void
    {
        self::$memoryStore = [];
    }

    private function isTrustedProxy(string $ip): bool
    {
        return in_array($ip, $this->trustedProxies, true);
    }
}
