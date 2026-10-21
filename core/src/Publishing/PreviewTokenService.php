<?php

declare(strict_types=1);

namespace BsCms\Publishing;

use BsCms\Database\Connection;
use BsCms\Support\Clock;
use RuntimeException;

final class PreviewTokenService
{
    private const TOKEN_PREFIX = 'bsprev_';
    private const DEFAULT_TTL_SECONDS = 1800; // 30 minutes

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Generate a new 30-minute preview token for a specific collection and entry.
     *
     * @return array{token: string, expires_at: string}
     */
    public function generateToken(string $collectionId, string $entryId, string $actorId, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): array
    {
        $rawSecret = bin2hex(random_bytes(24));
        $token = self::TOKEN_PREFIX . $rawSecret;
        $tokenHash = hash('sha256', $token);

        $now = Clock::sql(Clock::now());
        $expiresAtTimestamp = Clock::timestamp() + $ttlSeconds;
        $expiresAt = date('Y-m-d H:i:s.v', $expiresAtTimestamp);

        $table = $this->connection->prefixTable('preview_tokens');
        $this->connection->execute(
            "INSERT INTO `{$table}` (`token_hash`, `collection_id`, `entry_id`, `created_by`, `expires_at`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$tokenHash, $collectionId, $entryId, $actorId, $expiresAt, $now]
        );

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Revoke a preview token immediately.
     */
    public function revokeToken(string $token): bool
    {
        if (!str_starts_with($token, self::TOKEN_PREFIX)) {
            return false;
        }

        $tokenHash = hash('sha256', $token);
        $table = $this->connection->prefixTable('preview_tokens');
        $stmt = $this->connection->execute(
            "DELETE FROM `{$table}` WHERE `token_hash` = ?",
            [$tokenHash]
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Validate a preview token and return its metadata if valid.
     *
     * @return array<string, mixed>|null
     */
    public function validateToken(string $token): ?array
    {
        if (!str_starts_with($token, self::TOKEN_PREFIX)) {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $table = $this->connection->prefixTable('preview_tokens');

        $now = date('Y-m-d H:i:s.v', Clock::timestamp());
        $stmt = $this->connection->execute(
            "SELECT * FROM `{$table}` WHERE `token_hash` = ? AND `expires_at` > ?",
            [$tokenHash, $now]
        );

        $row = $stmt->fetch();
        return $row ?: null;
    }
}
