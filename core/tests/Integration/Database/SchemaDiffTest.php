<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Database;

use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration schema-diff test that verifies created tables, column names,
 * and key constraints match docs/01-data-model.md.
 *
 * Uses real Connection built from credentials (no injected PDO).
 * Fails (does not skip) in CI when DB_HOST is set.
 */
final class SchemaDiffTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private PDO $adminPdo;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';
        $this->dbName = 'bs_schema_' . bin2hex(random_bytes(4));

        try {
            $this->adminPdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $this->adminPdo->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            if (getenv('DB_HOST') !== false) {
                $this->fail('Database configured via DB_HOST but unreachable: ' . $e->getMessage());
            }
            $this->markTestSkipped('No local MySQL/MariaDB available.');
        }

        $this->connection = new Connection([
            'host' => $host,
            'port' => $port,
            'database' => $this->dbName,
            'username' => $user,
            'password' => $pass,
            'prefix' => 'bs_',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo) && $this->dbName !== '') {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }
        parent::tearDown();
    }

    public function testMigrationsMatchSpecSchema(): void
    {
        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $executed = $migrator->migrate();

        $this->assertNotEmpty($executed);

        $pdo = $this->connection->getPdo();

        // 1. Verify migrations table columns
        $this->assertTableColumns($pdo, 'bs_migrations', [
            'id', 'namespace', 'name', 'batch', 'applied_at'
        ]);

        // 2. Verify settings table columns
        $this->assertTableColumns($pdo, 'bs_settings', [
            'handle', 'value', 'is_secret', 'updated_by', 'updated_at'
        ]);

        // 3. Verify jobs table columns
        $this->assertTableColumns($pdo, 'bs_jobs', [
            'id', 'queue', 'type', 'payload', 'status', 'attempts', 'max_attempts',
            'available_at', 'reserved_at', 'last_error', 'created_at'
        ]);

        // 4. Verify rate_limits table columns
        $this->assertTableColumns($pdo, 'bs_rate_limits', [
            'bucket', 'window_start', 'hits'
        ]);

        // 5. Verify users table columns
        $this->assertTableColumns($pdo, 'bs_users', [
            'id', 'email', 'email_verified_at', 'password_hash', 'name', 'avatar_media_id',
            'status', 'locale', 'timezone', 'totp_secret_enc', 'totp_enabled_at', 'totp_last_step',
            'recovery_codes_hash', 'failed_logins', 'locked_until', 'last_login_at',
            'last_login_ip', 'created_at', 'updated_at', 'deleted_at'
        ]);

        // 6. Verify roles table columns
        $this->assertTableColumns($pdo, 'bs_roles', [
            'id', 'handle', 'name', 'description', 'is_system', 'is_ai', 'created_at', 'updated_at'
        ]);

        // 7. Verify user_roles table columns
        $this->assertTableColumns($pdo, 'bs_user_roles', [
            'user_id', 'role_id'
        ]);

        // 8. Verify role_permissions table columns
        $this->assertTableColumns($pdo, 'bs_role_permissions', [
            'id', 'role_id', 'subject_type', 'subject_key', 'action', 'effect',
            'fields', 'conditions', 'created_at'
        ]);

        // 9. Verify sessions table columns
        $this->assertTableColumns($pdo, 'bs_sessions', [
            'id', 'user_id', 'token_hash', 'csrf_secret', 'ip', 'user_agent',
            'mfa_passed_at', 'created_at', 'last_seen_at', 'expires_at', 'revoked_at'
        ]);

        // 10. Verify password_resets table columns
        $this->assertTableColumns($pdo, 'bs_password_resets', [
            'id', 'user_id', 'token_hash', 'requested_ip', 'expires_at', 'used_at', 'created_at'
        ]);

        // 11. Verify email_verifications table columns
        $this->assertTableColumns($pdo, 'bs_email_verifications', [
            'id', 'user_id', 'new_email', 'token_hash', 'expires_at', 'used_at', 'created_at'
        ]);

        // 12. Verify invitations table columns
        $this->assertTableColumns($pdo, 'bs_invitations', [
            'id', 'email', 'role_id', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'revoked_at', 'created_at'
        ]);

        // 13. Verify api_tokens table columns
        $this->assertTableColumns($pdo, 'bs_api_tokens', [
            'id', 'user_id', 'role_id', 'name', 'token_prefix', 'token_hash', 'scopes',
            'ai_mode', 'last_used_at', 'last_used_ip', 'expires_at', 'revoked_at', 'created_at'
        ]);

        // 14. Verify audit_log table columns
        $this->assertTableColumns($pdo, 'bs_audit_log', [
            'id', 'request_id', 'actor_type', 'actor_id', 'actor_label', 'on_behalf_of',
            'action', 'subject_type', 'subject_id', 'collection_key', 'summary', 'diff',
            'ip', 'user_agent', 'result', 'created_at'
        ]);

        // 15. Verify email_log table columns
        $this->assertTableColumns($pdo, 'bs_email_log', [
            'id', 'to_email', 'template', 'subject', 'status', 'provider',
            'provider_message_id', 'error', 'related_user_id', 'created_at', 'sent_at'
        ]);

        // 16. Verify notifications table columns
        $this->assertTableColumns($pdo, 'bs_notifications', [
            'id', 'user_id', 'type', 'title', 'body', 'link', 'read_at', 'created_at'
        ]);

        // Verify ULID columns are ascii_bin
        $colStmt = $pdo->prepare("SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'bs_users' AND COLUMN_NAME = 'id'");
        $colStmt->execute(['db' => $this->dbName]);
        $idCol = $colStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($idCol);
        $this->assertSame('ascii', strtolower((string) ($idCol['CHARACTER_SET_NAME'] ?? $idCol['character_set_name'])));
        $this->assertSame('ascii_bin', strtolower((string) ($idCol['COLLATION_NAME'] ?? $idCol['collation_name'])));
    }

    /**
     * @param list<string> $expectedColumns
     */
    private function assertTableColumns(PDO $pdo, string $table, array $expectedColumns): void
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        /** @var list<string> $cols */
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($expectedColumns as $expected) {
            $this->assertContains($expected, $cols, "Table {$table} must contain column {$expected}");
        }
    }
}
