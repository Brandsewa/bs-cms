<?php

declare(strict_types=1);

namespace BsCms\Publishing;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use PDO;

/**
 * Service for managing build tokens used by static site generators (Astro starter).
 */
final class BuildTokenService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ?AuditLogger $auditLogger = null
    ) {
    }

    /**
     * Create a build token.
     * Raw secret is returned ONCE and stored as SHA-256 hash.
     *
     * @return array{id: string, name: string, token: string, token_prefix: string, created_at: string}
     */
    public function create(string $name, ?Actor $actor = null): array
    {
        $id = Ulid::generate();
        $prefix = bin2hex(random_bytes(4)); // 8 chars
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $plainToken = "bsbuild_{$prefix}_{$secret}";
        $hash = hash('sha256', $plainToken);
        $now = Clock::sql(Clock::now());

        $table = $this->connection->prefixTable('build_tokens');
        $stmt = $this->connection->getPdo()->prepare(
            "INSERT INTO `{$table}` (`id`, `name`, `token_prefix`, `token_hash`, `last_used_at`, `revoked_at`, `created_at`)
             VALUES (?, ?, ?, ?, NULL, NULL, ?)"
        );
        $stmt->execute([$id, $name, $prefix, $hash, $now]);

        if ($this->auditLogger !== null && $actor !== null) {
            $this->auditLogger->record(
                actor: $actor,
                action: 'build_token.create',
                subject: new Subject('settings', 'publishing'),
                summary: "Created build token '{$name}' ({$prefix})",
                diff: ['name' => $name, 'token_prefix' => $prefix],
                subjectId: $id
            );
        }

        return [
            'id' => $id,
            'name' => $name,
            'token' => $plainToken,
            'token_prefix' => $prefix,
            'created_at' => $now,
        ];
    }

    /**
     * Authenticate a bearer token. Returns row if valid, or null if invalid/revoked.
     * Updates last_used_at timestamp.
     *
     * @return array<string, mixed>|null
     */
    public function authenticate(string $plainToken): ?array
    {
        if (!str_starts_with($plainToken, 'bsbuild_')) {
            return null;
        }

        $hash = hash('sha256', $plainToken);
        $table = $this->connection->prefixTable('build_tokens');

        $stmt = $this->connection->getPdo()->prepare(
            "SELECT * FROM `{$table}` WHERE `token_hash` = ? AND `revoked_at` IS NULL LIMIT 1"
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        // Update last_used_at asynchronously/best effort
        $now = Clock::sql(Clock::now());
        $updateStmt = $this->connection->getPdo()->prepare(
            "UPDATE `{$table}` SET `last_used_at` = ? WHERE `id` = ?"
        );
        $updateStmt->execute([$now, $row['id']]);

        $row['last_used_at'] = $now;

        return $row;
    }

    /**
     * List all build tokens (active and revoked).
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $table = $this->connection->prefixTable('build_tokens');
        $stmt = $this->connection->getPdo()->query(
            "SELECT `id`, `name`, `token_prefix`, `last_used_at`, `revoked_at`, `created_at`
             FROM `{$table}`
             ORDER BY `created_at` DESC"
        );
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        /** @var list<array<string, mixed>> $result */
        $result = array_values($rows);
        return $result;
    }

    /**
     * Revoke a build token immediately.
     */
    public function revoke(string $id, ?Actor $actor = null): bool
    {
        $table = $this->connection->prefixTable('build_tokens');
        $now = Clock::sql(Clock::now());

        $stmt = $this->connection->getPdo()->prepare(
            "UPDATE `{$table}` SET `revoked_at` = ? WHERE `id` = ? AND `revoked_at` IS NULL"
        );
        $stmt->execute([$now, $id]);

        $affected = $stmt->rowCount();
        if ($affected > 0 && $this->auditLogger !== null && $actor !== null) {
            $this->auditLogger->record(
                actor: $actor,
                action: 'build_token.revoke',
                subject: new Subject('settings', 'publishing'),
                summary: "Revoked build token {$id}",
                subjectId: $id
            );
        }

        return $affected > 0;
    }
}
