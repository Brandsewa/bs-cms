<?php

declare(strict_types=1);

namespace BsCms\Migrations;

use BsCms\Database\Migration;

/**
 * Migration 0002: Identity, access, audit, email log, and notifications.
 * Adheres strictly to docs/01-data-model.md Sections 1, 5, and 7.
 */
final class CreateAuthAndUserTables extends Migration
{
    public function up(): void
    {
        $users = $this->connection->prefixTable('users');
        $roles = $this->connection->prefixTable('roles');
        $userRoles = $this->connection->prefixTable('user_roles');
        $rolePermissions = $this->connection->prefixTable('role_permissions');
        $sessions = $this->connection->prefixTable('sessions');
        $passwordResets = $this->connection->prefixTable('password_resets');
        $emailVerifications = $this->connection->prefixTable('email_verifications');
        $invitations = $this->connection->prefixTable('invitations');
        $apiTokens = $this->connection->prefixTable('api_tokens');
        $auditLog = $this->connection->prefixTable('audit_log');
        $emailLog = $this->connection->prefixTable('email_log');
        $notifications = $this->connection->prefixTable('notifications');

        $pdo = $this->connection->getPdo();

        // 1. users
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$users}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `email` VARCHAR(190) NOT NULL,
            `email_verified_at` DATETIME(3) NULL,
            `password_hash` VARCHAR(255) NULL,
            `name` VARCHAR(190) NOT NULL,
            `avatar_media_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `locale` VARCHAR(10) NOT NULL DEFAULT 'en',
            `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
            `totp_secret_enc` VARBINARY(512) NULL,
            `totp_enabled_at` DATETIME(3) NULL,
            `recovery_codes_hash` JSON NULL,
            `failed_logins` SMALLINT NOT NULL DEFAULT 0,
            `locked_until` DATETIME(3) NULL,
            `last_login_at` DATETIME(3) NULL,
            `last_login_ip` VARCHAR(45) NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            `deleted_at` DATETIME(3) NULL,
            UNIQUE KEY `uq_users_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 2. roles
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$roles}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `handle` VARCHAR(64) NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `description` VARCHAR(500) NULL,
            `is_system` TINYINT(1) NOT NULL DEFAULT 0,
            `is_ai` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            UNIQUE KEY `uq_roles_key` (`handle`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 3. user_roles
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$userRoles}` (
            `user_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `role_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            PRIMARY KEY (`user_id`, `role_id`),
            FOREIGN KEY (`user_id`) REFERENCES `{$users}` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`role_id`) REFERENCES `{$roles}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 4. role_permissions
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$rolePermissions}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `role_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `subject_type` VARCHAR(32) NOT NULL,
            `subject_key` VARCHAR(120) NOT NULL,
            `action` VARCHAR(32) NOT NULL,
            `effect` VARCHAR(5) NOT NULL DEFAULT 'allow',
            `fields` JSON NULL,
            `conditions` JSON NULL,
            `created_at` DATETIME(3) NOT NULL,
            FOREIGN KEY (`role_id`) REFERENCES `{$roles}` (`id`) ON DELETE CASCADE,
            KEY `ix_perm_role` (`role_id`, `subject_type`, `subject_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 5. sessions
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$sessions}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `user_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `csrf_secret` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `ip` VARCHAR(45) NULL,
            `user_agent` VARCHAR(255) NULL,
            `mfa_passed_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            `last_seen_at` DATETIME(3) NOT NULL,
            `expires_at` DATETIME(3) NOT NULL,
            `revoked_at` DATETIME(3) NULL,
            UNIQUE KEY `uq_sessions_token` (`token_hash`),
            KEY `ix_sessions_user` (`user_id`),
            FOREIGN KEY (`user_id`) REFERENCES `{$users}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 6. password_resets
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$passwordResets}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `user_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `requested_ip` VARCHAR(45) NULL,
            `expires_at` DATETIME(3) NOT NULL,
            `used_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            UNIQUE KEY `uq_pwreset_token` (`token_hash`),
            FOREIGN KEY (`user_id`) REFERENCES `{$users}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 7. email_verifications
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$emailVerifications}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `user_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `new_email` VARCHAR(190) NULL,
            `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `expires_at` DATETIME(3) NOT NULL,
            `used_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            UNIQUE KEY `uq_emailver_token` (`token_hash`),
            FOREIGN KEY (`user_id`) REFERENCES `{$users}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 8. invitations
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$invitations}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `email` VARCHAR(190) NOT NULL,
            `role_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `invited_by` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `expires_at` DATETIME(3) NOT NULL,
            `accepted_at` DATETIME(3) NULL,
            `revoked_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            UNIQUE KEY `uq_invite_token` (`token_hash`),
            KEY `ix_invite_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 9. api_tokens
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$apiTokens}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `user_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `role_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `name` VARCHAR(120) NOT NULL,
            `token_prefix` CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `scopes` JSON NULL,
            `ai_mode` VARCHAR(20) NOT NULL DEFAULT 'draft_only',
            `last_used_at` DATETIME(3) NULL,
            `last_used_ip` VARCHAR(45) NULL,
            `expires_at` DATETIME(3) NULL,
            `revoked_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            UNIQUE KEY `uq_apitoken_hash` (`token_hash`),
            FOREIGN KEY (`user_id`) REFERENCES `{$users}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 10. audit_log
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$auditLog}` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `request_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `actor_type` VARCHAR(16) NOT NULL,
            `actor_id` VARCHAR(64) NULL,
            `actor_label` VARCHAR(190) NULL,
            `on_behalf_of` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `action` VARCHAR(64) NOT NULL,
            `subject_type` VARCHAR(32) NOT NULL,
            `subject_id` VARCHAR(64) NULL,
            `collection_key` VARCHAR(64) NULL,
            `summary` VARCHAR(500) NULL,
            `diff` JSON NULL,
            `ip` VARCHAR(45) NULL,
            `user_agent` VARCHAR(255) NULL,
            `result` VARCHAR(12) NOT NULL DEFAULT 'success',
            `created_at` DATETIME(3) NOT NULL,
            KEY `ix_audit_time` (`created_at`),
            KEY `ix_audit_subject` (`subject_type`, `subject_id`),
            KEY `ix_audit_actor` (`actor_type`, `actor_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 11. email_log
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$emailLog}` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `to_email` VARCHAR(190) NOT NULL,
            `template` VARCHAR(64) NOT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `status` VARCHAR(12) NOT NULL,
            `provider` VARCHAR(24) NULL,
            `provider_message_id` VARCHAR(190) NULL,
            `error` VARCHAR(500) NULL,
            `related_user_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `created_at` DATETIME(3) NOT NULL,
            `sent_at` DATETIME(3) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 12. notifications
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$notifications}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `user_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `type` VARCHAR(48) NOT NULL,
            `title` VARCHAR(190) NOT NULL,
            `body` VARCHAR(500) NULL,
            `link` VARCHAR(512) NULL,
            `read_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            KEY `ix_notif_user` (`user_id`, `read_at`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    public function down(): void
    {
        $users = $this->connection->prefixTable('users');
        $roles = $this->connection->prefixTable('roles');
        $userRoles = $this->connection->prefixTable('user_roles');
        $rolePermissions = $this->connection->prefixTable('role_permissions');
        $sessions = $this->connection->prefixTable('sessions');
        $passwordResets = $this->connection->prefixTable('password_resets');
        $emailVerifications = $this->connection->prefixTable('email_verifications');
        $invitations = $this->connection->prefixTable('invitations');
        $apiTokens = $this->connection->prefixTable('api_tokens');
        $auditLog = $this->connection->prefixTable('audit_log');
        $emailLog = $this->connection->prefixTable('email_log');
        $notifications = $this->connection->prefixTable('notifications');

        $pdo = $this->connection->getPdo();

        // Drop in reverse dependency order
        $pdo->exec("DROP TABLE IF EXISTS `{$notifications}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$emailLog}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$auditLog}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$apiTokens}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$invitations}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$emailVerifications}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$passwordResets}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$sessions}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$rolePermissions}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$userRoles}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$roles}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$users}`;");
    }
}
