<?php

declare(strict_types=1);

namespace BsCms\Auth;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use DateTimeInterface;
use PDO;

/**
 * ApiTokenService: manages personal access tokens and programmatic API keys.
 * Raw secret is returned once on generation and stored only as a SHA-256 hash at rest.
 */
final class ApiTokenService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AuditLogger $auditLogger
    ) {
    }

    /**
     * Create an API token for a user.
     *
     * @param array<int, string> $scopes
     * @return array<string, mixed>
     */
    public function create(
        string $userId,
        string $name,
        ?string $roleId = null,
        array $scopes = ['*'],
        string $aiMode = 'draft_only',
        ?DateTimeInterface $expiresAt = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): array {
        $id = Ulid::generate();
        $prefix = bin2hex(random_bytes(4)); // 8 ascii characters
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $plainToken = "bstok_{$prefix}_{$secret}";
        $tokenHash = hash('sha256', $plainToken);

        $now = Clock::sql(Clock::now());
        $expiresAtSql = $expiresAt !== null ? Clock::sql(Clock::from($expiresAt)) : null;

        $tokensTable = $this->connection->prefixTable('api_tokens');
        $stmt = $this->connection->getPdo()->prepare(
            "INSERT INTO `{$tokensTable}` (
                `id`, `user_id`, `role_id`, `name`, `token_prefix`, `token_hash`,
                `scopes`, `ai_mode`, `last_used_at`, `last_used_ip`, `expires_at`, `revoked_at`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, NULL, ?)"
        );

        $stmt->execute([
            $id,
            $userId,
            $roleId,
            $name,
            $prefix,
            $tokenHash,
            Json::encode($scopes),
            $aiMode,
            $expiresAtSql,
            $now,
        ]);

        $this->auditLogger->record(
            Actor::user($userId),
            'token.created',
            new Subject('api_token', $id),
            "Created API token '{$name}' (prefix: {$prefix})",
            null,
            'success',
            $ip,
            $userAgent
        );

        return [
            'id' => $id,
            'name' => $name,
            'token' => $plainToken,
            'token_prefix' => $prefix,
            'scopes' => $scopes,
            'ai_mode' => $aiMode,
            'expires_at' => $expiresAtSql,
            'created_at' => $now,
        ];
    }

    /**
     * Authenticate a bearer token.
     *
     * @return array<string, mixed>|null
     */
    public function authenticate(string $plainToken, ?string $ip = null): ?array
    {
        if (!str_starts_with($plainToken, 'bstok_')) {
            return null;
        }

        $parts = explode('_', $plainToken, 3);
        if (count($parts) !== 3) {
            return null;
        }

        $prefix = $parts[1];
        $tokenHash = hash('sha256', $plainToken);
        $tokensTable = $this->connection->prefixTable('api_tokens');
        $now = Clock::sql(Clock::now());

        $sql = "SELECT * FROM `{$tokensTable}`
                WHERE `token_prefix` = ? AND `revoked_at` IS NULL AND (`expires_at` IS NULL OR `expires_at` > ?)";

        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute([$prefix, $now]);
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if (hash_equals((string) $row['token_hash'], $tokenHash)) {
                // Update last used timestamp
                $upStmt = $this->connection->getPdo()->prepare(
                    "UPDATE `{$tokensTable}` SET `last_used_at` = ?, `last_used_ip` = ? WHERE `id` = ?"
                );
                $upStmt->execute([$now, $ip, $row['id']]);

                if (isset($row['scopes']) && is_string($row['scopes'])) {
                    $row['scopes'] = Json::decode($row['scopes']) ?? [];
                }

                unset($row['token_hash']);
                return $row;
            }
        }

        return null;
    }

    /**
     * Revoke an active API token.
     */
    public function revoke(string $tokenId, string $actorUserId, ?string $ip = null, ?string $userAgent = null): bool
    {
        $tokensTable = $this->connection->prefixTable('api_tokens');
        $now = Clock::sql(Clock::now());

        $stmt = $this->connection->getPdo()->prepare(
            "UPDATE `{$tokensTable}` SET `revoked_at` = ? WHERE `id` = ? AND `revoked_at` IS NULL"
        );
        $stmt->execute([$now, $tokenId]);

        $affected = $stmt->rowCount() > 0;
        if ($affected) {
            $this->auditLogger->record(
                Actor::user($actorUserId),
                'token.revoked',
                new Subject('api_token', $tokenId),
                "Revoked API token '{$tokenId}'",
                null,
                'success',
                $ip,
                $userAgent
            );
        }

        return $affected;
    }

    /**
     * List all unrevoked tokens for a given user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(string $userId): array
    {
        $tokensTable = $this->connection->prefixTable('api_tokens');
        $sql = "SELECT `id`, `user_id`, `role_id`, `name`, `token_prefix`, `scopes`, `ai_mode`,
                       `last_used_at`, `last_used_ip`, `expires_at`, `created_at`
                FROM `{$tokensTable}`
                WHERE `user_id` = ? AND `revoked_at` IS NULL
                ORDER BY `created_at` DESC";

        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute([$userId]);
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            if (isset($row['scopes']) && is_string($row['scopes'])) {
                $row['scopes'] = Json::decode($row['scopes']) ?? [];
            }
        }

        return $rows;
    }
}
