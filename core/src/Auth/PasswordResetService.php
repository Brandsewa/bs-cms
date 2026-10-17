<?php

declare(strict_types=1);

namespace BsCms\Auth;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Mail\Mailer;
use BsCms\Mail\MailMessage;
use BsCms\Mail\TemplateRenderer;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use BsCms\Users\UserRepository;
use PDO;
use RuntimeException;

/**
 * PasswordResetService: generates cryptographically secure reset tokens,
 * sends high-priority reset emails, verifies token hashes, and revokes sessions on reset.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly AuditLogger $auditLogger,
        private readonly Mailer $mailer,
        private readonly TemplateRenderer $templates,
        private readonly string $adminBaseUrl = 'http://localhost:8080'
    ) {
    }

    /**
     * Request a password reset link. Anti-enumeration: returns identically whether email exists or not.
     */
    public function requestReset(string $email, ?string $ip = null, ?string $userAgent = null): void
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || $user['status'] === 'suspended') {
            return;
        }

        $userId = (string) $user['id'];
        $pdo = $this->connection->getPdo();
        $resetTable = $this->connection->prefixTable('password_resets');
        $now = Clock::sql(Clock::now());

        // Invalidate prior unused tokens for this user
        $stmt = $pdo->prepare("UPDATE `{$resetTable}` SET `used_at` = ? WHERE `user_id` = ? AND `used_at` IS NULL");
        $stmt->execute([$now, $userId]);

        // Generate 32-byte cryptographically secure token
        $rawBytes = random_bytes(32);
        $rawToken = rtrim(strtr(base64_encode($rawBytes), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $rawToken);

        $id = Ulid::generate();
        $expiresAt = Clock::sql(Clock::now()->modify('+60 minutes'));

        $insStmt = $pdo->prepare(
            "INSERT INTO `{$resetTable}` (`id`, `user_id`, `token_hash`, `requested_ip`, `expires_at`, `used_at`, `created_at`)
             VALUES (?, ?, ?, ?, ?, NULL, ?)"
        );
        $insStmt->execute([$id, $userId, $tokenHash, $ip, $expiresAt, $now]);

        // Send high priority email
        $actionUrl = rtrim($this->adminBaseUrl, '/') . '/auth/password/reset?token=' . $rawToken;
        $tpl = $this->templates->render('password_reset', [
            'name' => (string) ($user['name'] ?? 'there'),
            'action_url' => $actionUrl,
            'site_name' => 'BS CMS',
        ]);

        $message = new MailMessage(
            to: (string) $user['email'],
            subject: $tpl['subject'],
            htmlBody: $tpl['html'],
            textBody: $tpl['text'],
            template: 'password_reset',
            priority: MailMessage::PRIORITY_HIGH,
            relatedUserId: $userId
        );

        $this->mailer->send($message, true);

        $this->auditLogger->record(
            Actor::system(),
            'auth.password_reset_requested',
            new Subject('user', $userId),
            "Password reset link requested for '{$user['email']}'",
            null,
            'success',
            $ip,
            $userAgent
        );
    }

    /**
     * Validate a raw reset token.
     *
     * @return array<string, mixed>|null
     */
    public function validateToken(string $rawToken): ?array
    {
        if (trim($rawToken) === '') {
            return null;
        }

        $tokenHash = hash('sha256', trim($rawToken));
        $resetTable = $this->connection->prefixTable('password_resets');
        $now = Clock::sql(Clock::now());

        $stmt = $this->connection->getPdo()->prepare(
            "SELECT * FROM `{$resetTable}` WHERE `token_hash` = ? AND `used_at` IS NULL AND `expires_at` > ?"
        );
        $stmt->execute([$tokenHash, $now]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Reset password using a valid raw reset token.
     */
    public function resetPassword(string $rawToken, string $newPassword, ?string $ip = null, ?string $userAgent = null): bool
    {
        $reset = $this->validateToken($rawToken);
        if ($reset === null) {
            return false;
        }

        // Validate password against policy
        $this->hasher->validatePolicy($newPassword);

        $userId = (string) $reset['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null) {
            return false;
        }

        $newHash = $this->hasher->hash($newPassword);
        $now = Clock::sql(Clock::now());
        $resetTable = $this->connection->prefixTable('password_resets');

        // Mark token as used
        $stmt = $this->connection->getPdo()->prepare("UPDATE `{$resetTable}` SET `used_at` = ? WHERE `id` = ?");
        $stmt->execute([$now, $reset['id']]);

        // Update password and reset lockout counters
        $this->users->update($userId, [
            'password_hash' => $newHash,
            'failed_logins' => 0,
            'locked_until' => null,
        ]);

        // Revoke all sessions for security
        $this->sessions->revokeAllForUser($userId);

        $this->auditLogger->record(
            Actor::user($userId, (string) $user['email']),
            'auth.password_reset',
            new Subject('user', $userId),
            "Password successfully reset via token",
            null,
            'success',
            $ip,
            $userAgent
        );

        return true;
    }
}
