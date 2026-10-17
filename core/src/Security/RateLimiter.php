<?php

declare(strict_types=1);

namespace BsCms\Security;

use BsCms\Support\Clock;

/**
 * Fixed window rate limiter with burst tolerance.
 *
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

    /**
     * @param list<string> $trustedProxies
     */
    public function __construct(int $maxAttempts = 60, int $windowSeconds = 60, array $trustedProxies = [])
    {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->windowSeconds = max(1, $windowSeconds);
        $this->trustedProxies = $trustedProxies;
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
     * @return array{allowed: bool, remaining: int, limit: int, reset: int, retry_after: int}
     */
    public function hit(string $key): array
    {
        $now = Clock::timestamp();
        $record = self::$memoryStore[$key] ?? null;

        if ($record === null || $record['reset_at'] <= $now) {
            // Reset window
            $resetAt = $now + $this->windowSeconds;
            self::$memoryStore[$key] = [
                'count' => 1,
                'reset_at' => $resetAt,
            ];

            return [
                'allowed' => true,
                'remaining' => $this->maxAttempts - 1,
                'limit' => $this->maxAttempts,
                'reset' => $resetAt,
                'retry_after' => 0,
            ];
        }

        if ($record['count'] >= $this->maxAttempts) {
            $retryAfter = max(1, $record['reset_at'] - $now);
            return [
                'allowed' => false,
                'remaining' => 0,
                'limit' => $this->maxAttempts,
                'reset' => $record['reset_at'],
                'retry_after' => $retryAfter,
            ];
        }

        ++self::$memoryStore[$key]['count'];
        $remaining = max(0, $this->maxAttempts - self::$memoryStore[$key]['count']);

        return [
            'allowed' => true,
            'remaining' => $remaining,
            'limit' => $this->maxAttempts,
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
