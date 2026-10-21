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
    public const MAX_ACTIVE_PER_ENTRY = 10;
    public const MAX_ACTIVE_PER_USER = 20;

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Generate a new 30-minute preview token for a specific collection and entry.
     * Enforces active token caps per entry and per user.
     *
     * @return array{token: string, expires_at: string}
     */
    public function generateToken(string $collectionId, string $entryId, string $actorId, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): array
    {
        $table = $this->connection->prefixTable('preview_tokens');
        $now = Clock::sql(Clock::now());

        // Check active token cap per entry
        $stmtEntry = $this->connection->execute(
            "SELECT COUNT(*) as cnt FROM `{$table}` WHERE `entry_id` = ? AND `expires_at` > ?",
            [$entryId, $now]
        );
        $entryCount = (int) ($stmtEntry->fetch()['cnt'] ?? 0);
        if ($entryCount >= self::MAX_ACTIVE_PER_ENTRY) {
            throw new RuntimeException("Maximum active preview tokens per entry (" . self::MAX_ACTIVE_PER_ENTRY . ") reached.");
        }

        // Check active token cap per user
        $stmtUser = $this->connection->execute(
            "SELECT COUNT(*) as cnt FROM `{$table}` WHERE `created_by` = ? AND `expires_at` > ?",
            [$actorId, $now]
        );
        $userCount = (int) ($stmtUser->fetch()['cnt'] ?? 0);
        if ($userCount >= self::MAX_ACTIVE_PER_USER) {
            throw new RuntimeException("Maximum active preview tokens per user (" . self::MAX_ACTIVE_PER_USER . ") reached.");
        }

        $rawSecret = bin2hex(random_bytes(24));
        $token = self::TOKEN_PREFIX . $rawSecret;
        $tokenHash = hash('sha256', $token);

        $expiresAtTimestamp = Clock::timestamp() + $ttlSeconds;
        $expiresAt = date('Y-m-d H:i:s.v', $expiresAtTimestamp);

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
     * Find a preview token row by raw token (including expired tokens).
     *
     * @return array<string, mixed>|null
     */
    public function findToken(string $token): ?array
    {
        if (!str_starts_with($token, self::TOKEN_PREFIX)) {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $table = $this->connection->prefixTable('preview_tokens');

        $stmt = $this->connection->execute(
            "SELECT * FROM `{$table}` WHERE `token_hash` = ?",
            [$tokenHash]
        );

        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Purge all expired preview tokens from storage.
     */
    public function purgeExpired(): int
    {
        $table = $this->connection->prefixTable('preview_tokens');
        $now = Clock::sql(Clock::now());

        $stmt = $this->connection->execute(
            "DELETE FROM `{$table}` WHERE `expires_at` <= ?",
            [$now]
        );

        return $stmt->rowCount();
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
