<?php

declare(strict_types=1);

namespace BsCms\Migrations;

use BsCms\Database\Migration;

/**
 * Migration 0009: Create OAuth 2.1 authorization server and pending changes tables.
 * Adheres strictly to docs/01-data-model.md Sections 1 and 5.
 *
 * Tables created:
 * - oauth_clients
 * - oauth_grants
 * - oauth_authorization_codes
 * - oauth_access_tokens
 * - oauth_refresh_tokens
 * - pending_changes
 */
final class CreateOauthAndPendingChangesTables extends Migration
{
    public function up(): void
    {
        $oauthClients = $this->connection->prefixTable('oauth_clients');
        $oauthGrants = $this->connection->prefixTable('oauth_grants');
        $oauthAuthCodes = $this->connection->prefixTable('oauth_authorization_codes');
        $oauthAccessTokens = $this->connection->prefixTable('oauth_access_tokens');
        $oauthRefreshTokens = $this->connection->prefixTable('oauth_refresh_tokens');
        $pendingChanges = $this->connection->prefixTable('pending_changes');
        $users = $this->connection->prefixTable('users');
        $roles = $this->connection->prefixTable('roles');

        $pdo = $this->connection->getPdo();

        // 1. oauth_clients
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$oauthClients}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `client_id` VARCHAR(255) NOT NULL,
            `name` VARCHAR(190) NOT NULL,
            `redirect_uris` JSON NOT NULL,
            `is_public` TINYINT(1) NOT NULL DEFAULT 1,
            `client_secret_hash` VARCHAR(255) NULL,
            `registration_method` VARCHAR(20) NOT NULL,
            `metadata` JSON NULL,
            `created_at` DATETIME(3) NOT NULL,
            `revoked_at` DATETIME(3) NULL,
            UNIQUE KEY `uq_oauth_client` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 2. oauth_grants
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$oauthGrants}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `user_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `client_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `role_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `scopes` JSON NOT NULL,
            `ai_mode` VARCHAR(20) NOT NULL DEFAULT 'draft_only',
            `created_at` DATETIME(3) NOT NULL,
            `last_used_at` DATETIME(3) NULL,
            `revoked_at` DATETIME(3) NULL,
            FOREIGN KEY (`user_id`) REFERENCES `{$users}` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`client_id`) REFERENCES `{$oauthClients}` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`role_id`) REFERENCES `{$roles}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 3. oauth_authorization_codes
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$oauthAuthCodes}` (
            `code_hash` CHAR(64) PRIMARY KEY,
            `grant_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `code_challenge` VARCHAR(128) NOT NULL,
            `code_challenge_method` VARCHAR(10) NOT NULL DEFAULT 'S256',
            `redirect_uri` VARCHAR(2048) NOT NULL,
            `resource` VARCHAR(2048) NULL,
            `expires_at` DATETIME(3) NOT NULL,
            `used_at` DATETIME(3) NULL,
            FOREIGN KEY (`grant_id`) REFERENCES `{$oauthGrants}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 4. oauth_access_tokens
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$oauthAccessTokens}` (
            `token_hash` CHAR(64) PRIMARY KEY,
            `grant_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `expires_at` DATETIME(3) NOT NULL,
            `revoked_at` DATETIME(3) NULL,
            KEY `ix_oat_grant` (`grant_id`),
            FOREIGN KEY (`grant_id`) REFERENCES `{$oauthGrants}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 5. oauth_refresh_tokens
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$oauthRefreshTokens}` (
            `token_hash` CHAR(64) PRIMARY KEY,
            `access_token_hash` CHAR(64) NULL,
            `grant_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `expires_at` DATETIME(3) NOT NULL,
            `rotated_at` DATETIME(3) NULL,
            `revoked_at` DATETIME(3) NULL,
            KEY `ix_ort_grant` (`grant_id`),
            FOREIGN KEY (`grant_id`) REFERENCES `{$oauthGrants}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 6. pending_changes
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$pendingChanges}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `actor_type` VARCHAR(16) NOT NULL,
            `actor_label` VARCHAR(190) NOT NULL,
            `oauth_grant_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `api_token_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `on_behalf_of` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `operation` VARCHAR(64) NOT NULL,
            `subject_type` VARCHAR(32) NOT NULL,
            `subject_id` VARCHAR(64) NULL,
            `payload` JSON NOT NULL,
            `diff` JSON NULL,
            `status` VARCHAR(12) NOT NULL DEFAULT 'pending',
            `decided_by` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `decided_at` DATETIME(3) NULL,
            `decision_note` VARCHAR(500) NULL,
            `expires_at` DATETIME(3) NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            KEY `ix_pending_status` (`status`, `created_at`),
            KEY `ix_pending_behalf` (`on_behalf_of`),
            FOREIGN KEY (`oauth_grant_id`) REFERENCES `{$oauthGrants}` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`on_behalf_of`) REFERENCES `{$users}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    public function down(): void
    {
        $oauthClients = $this->connection->prefixTable('oauth_clients');
        $oauthGrants = $this->connection->prefixTable('oauth_grants');
        $oauthAuthCodes = $this->connection->prefixTable('oauth_authorization_codes');
        $oauthAccessTokens = $this->connection->prefixTable('oauth_access_tokens');
        $oauthRefreshTokens = $this->connection->prefixTable('oauth_refresh_tokens');
        $pendingChanges = $this->connection->prefixTable('pending_changes');

        $pdo = $this->connection->getPdo();

        $pdo->exec("DROP TABLE IF EXISTS `{$pendingChanges}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$oauthRefreshTokens}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$oauthAccessTokens}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$oauthAuthCodes}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$oauthGrants}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$oauthClients}`;");
    }
}
