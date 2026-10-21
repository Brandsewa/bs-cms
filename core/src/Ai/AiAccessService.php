<?php

declare(strict_types=1);

namespace BsCms\Ai;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Settings\SettingsRepository;
use BsCms\Support\Clock;
use PDO;

final class AiAccessService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $auditLogger
    ) {
    }

    public function isAiDisabled(): bool
    {
        return (bool) $this->settings->get('ai_disabled', false);
    }

    /**
     * @return array{ai_disabled: bool, active_grants_count: int, active_ai_tokens_count: int, pending_changes_count: int}
     */
    public function getStatus(): array
    {
        $pdo = $this->connection->getPdo();
        $grantsTable = $this->connection->prefixTable('oauth_grants');
        $apiTokensTable = $this->connection->prefixTable('api_tokens');
        $pendingChangesTable = $this->connection->prefixTable('pending_changes');

        $activeGrantsCount = 0;
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$grantsTable}` WHERE `revoked_at` IS NULL");
            if ($stmt !== false) {
                $activeGrantsCount = (int) $stmt->fetchColumn();
            }
        } catch (\Throwable) {
            $activeGrantsCount = 0;
        }

        $activeAiTokensCount = 0;
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$apiTokensTable}` WHERE `revoked_at` IS NULL AND (`ai_mode` IS NOT NULL OR `scopes` LIKE '%mcp%')");
            if ($stmt !== false) {
                $activeAiTokensCount = (int) $stmt->fetchColumn();
            }
        } catch (\Throwable) {
            $activeAiTokensCount = 0;
        }

        $pendingChangesCount = 0;
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$pendingChangesTable}` WHERE `status` = 'pending'");
            if ($stmt !== false) {
                $pendingChangesCount = (int) $stmt->fetchColumn();
            }
        } catch (\Throwable) {
            $pendingChangesCount = 0;
        }

        return [
            'ai_disabled' => $this->isAiDisabled(),
            'active_grants_count' => $activeGrantsCount,
            'active_ai_tokens_count' => $activeAiTokensCount,
            'pending_changes_count' => $pendingChangesCount,
        ];
    }

    /**
     * Disconnect all AI access in an atomic transaction:
     * - Revoke all active OAuth grants
     * - Revoke all active OAuth access and refresh tokens
     * - Revoke all active AI-mode personal API tokens
     * - Expire / reject all pending changes created by AI
     * - Persist ai_disabled = true in settings
     * - Record audit log for the action + audit log per revoked grant
     *
     * @return array{status: string, ai_disabled: bool, revoked_grants_count: int, revoked_tokens_count: int, expired_pending_changes_count: int}
     */
    public function disconnect(Actor $actor, ?string $ip = null, ?string $userAgent = null): array
    {
        $pdo = $this->connection->getPdo();
        $grantsTable = $this->connection->prefixTable('oauth_grants');
        $accessTokensTable = $this->connection->prefixTable('oauth_access_tokens');
        $refreshTokensTable = $this->connection->prefixTable('oauth_refresh_tokens');
        $apiTokensTable = $this->connection->prefixTable('api_tokens');
        $pendingChangesTable = $this->connection->prefixTable('pending_changes');

        $now = Clock::sql(Clock::now());

        $pdo->beginTransaction();
        try {
            // 1. Fetch active OAuth grants
            $stmt = $pdo->query("SELECT `id`, `client_id`, `user_id` FROM `{$grantsTable}` WHERE `revoked_at` IS NULL");
            /** @var list<array<string, mixed>> $activeGrants */
            $activeGrants = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $revokedGrantsCount = count($activeGrants);

            // 2. Revoke OAuth grants
            $updateGrants = $pdo->prepare("UPDATE `{$grantsTable}` SET `revoked_at` = ? WHERE `revoked_at` IS NULL");
            $updateGrants->execute([$now]);

            // 3. Mark OAuth access & refresh tokens revoked
            try {
                $pdo->exec("UPDATE `{$accessTokensTable}` SET `revoked_at` = '{$now}' WHERE `revoked_at` IS NULL");
            } catch (\Throwable) {
                // Table might be absent in non-oauth environment
            }

            try {
                $pdo->exec("UPDATE `{$refreshTokensTable}` SET `revoked_at` = '{$now}' WHERE `revoked_at` IS NULL");
            } catch (\Throwable) {
                // Table might be absent in non-oauth environment
            }

            // 4. Revoke active AI API tokens
            $stmt = $pdo->query("SELECT `id`, `name`, `user_id` FROM `{$apiTokensTable}` WHERE `revoked_at` IS NULL AND (`ai_mode` IS NOT NULL OR `scopes` LIKE '%mcp%')");
            /** @var list<array<string, mixed>> $activeAiTokens */
            $activeAiTokens = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $revokedTokensCount = count($activeAiTokens);

            $updateTokens = $pdo->prepare("UPDATE `{$apiTokensTable}` SET `revoked_at` = ? WHERE `revoked_at` IS NULL AND (`ai_mode` IS NOT NULL OR `scopes` LIKE '%mcp%')");
            $updateTokens->execute([$now]);

            // 5. Expire / reject AI pending changes so old approvals cannot apply them
            $stmt = $pdo->query("SELECT `id` FROM `{$pendingChangesTable}` WHERE `status` = 'pending'");
            /** @var list<array<string, mixed>> $pendingChanges */
            $pendingChanges = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $expiredChangesCount = count($pendingChanges);

            if ($expiredChangesCount > 0) {
                $updatePending = $pdo->prepare(
                    "UPDATE `{$pendingChangesTable}`
                     SET `status` = 'rejected',
                         `decided_by` = ?,
                         `decided_at` = ?,
                         `decision_note` = 'AI access disconnected by administrator'
                     WHERE `status` = 'pending'"
                );
                $updatePending->execute([$actor->id, $now]);
            }

            // 6. Set ai_disabled = true in settings
            $this->settings->set('ai_disabled', true, false, $actor->id);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // 7. Audit logging outside transaction
        $this->auditLogger->record(
            actor: $actor,
            action: 'ai.disconnected',
            subject: new Subject('settings', 'ai_disabled'),
            summary: "Disconnected all AI access: revoked {$revokedGrantsCount} grants, {$revokedTokensCount} AI tokens, and expired {$expiredChangesCount} pending changes",
            diff: [
                'revoked_grants_count' => $revokedGrantsCount,
                'revoked_tokens_count' => $revokedTokensCount,
                'expired_pending_changes_count' => $expiredChangesCount,
            ],
            result: 'success',
            ip: $ip,
            userAgent: $userAgent,
            subjectId: 'ai_disabled'
        );

        foreach ($activeGrants as $grant) {
            $grantId = (string) $grant['id'];
            $this->auditLogger->record(
                actor: $actor,
                action: 'oauth.grant.revoked',
                subject: new Subject('oauth_grant', $grantId),
                summary: "Revoked OAuth grant '{$grantId}' via AI kill switch",
                result: 'success',
                ip: $ip,
                userAgent: $userAgent,
                subjectId: $grantId
            );
        }

        return [
            'status' => 'disconnected',
            'ai_disabled' => true,
            'revoked_grants_count' => $revokedGrantsCount,
            'revoked_tokens_count' => $revokedTokensCount,
            'expired_pending_changes_count' => $expiredChangesCount,
        ];
    }

    /**
     * Re-enable AI access. Previously revoked grants and tokens remain revoked;
     * clients must re-authorize.
     *
     * @return array{status: string, ai_disabled: bool}
     */
    public function reconnect(Actor $actor, ?string $ip = null, ?string $userAgent = null): array
    {
        $this->settings->set('ai_disabled', false, false, $actor->id);

        $this->auditLogger->record(
            actor: $actor,
            action: 'ai.reconnected',
            subject: new Subject('settings', 'ai_disabled'),
            summary: 'Re-enabled AI access (previously revoked grants and tokens remain revoked; clients must re-authorize)',
            diff: [
                'ai_disabled' => false,
            ],
            result: 'success',
            ip: $ip,
            userAgent: $userAgent,
            subjectId: 'ai_disabled'
        );

        return [
            'status' => 'connected',
            'ai_disabled' => false,
        ];
    }
}
