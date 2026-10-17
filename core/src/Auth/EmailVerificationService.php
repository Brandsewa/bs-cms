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
 * EmailVerificationService: handles initial email address verification and secure email changes
 * with security notifications sent to former email addresses.
 */
final class EmailVerificationService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UserRepository $users,
        private readonly AuditLogger $auditLogger,
        private readonly Mailer $mailer,
        private readonly TemplateRenderer $templates,
        private readonly string $adminBaseUrl = 'http://localhost:8080'
    ) {
    }

    /**
     * Send email verification or email change request.
     */
    public function sendVerification(
        string $userId,
        ?string $newEmail = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): string {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new RuntimeException("User '{$userId}' not found.");
        }

        $targetEmail = $newEmail !== null ? strtolower(trim($newEmail)) : (string) $user['email'];

        // If changing email, check that target email is not already taken
        if ($newEmail !== null && strtolower(trim($newEmail)) !== strtolower((string) $user['email'])) {
            $existing = $this->users->findByEmail($targetEmail);
            if ($existing !== null && (string) $existing['id'] !== $userId) {
                throw new RuntimeException("Email address '{$targetEmail}' is already registered.");
            }
        }

        $pdo = $this->connection->getPdo();
        $verTable = $this->connection->prefixTable('email_verifications');
        $now = Clock::sql(Clock::now());

        // Invalidate prior unused tokens
        $invStmt = $pdo->prepare("UPDATE `{$verTable}` SET `used_at` = ? WHERE `user_id` = ? AND `used_at` IS NULL");
        $invStmt->execute([$now, $userId]);

        // Generate 32-byte cryptographically secure token
        $rawBytes = random_bytes(32);
        $rawToken = rtrim(strtr(base64_encode($rawBytes), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $rawToken);

        $id = Ulid::generate();
        $expiresAt = Clock::sql(Clock::now()->modify('+24 hours'));

        $insStmt = $pdo->prepare(
            "INSERT INTO `{$verTable}` (`id`, `user_id`, `new_email`, `token_hash`, `expires_at`, `used_at`, `created_at`)
             VALUES (?, ?, ?, ?, ?, NULL, ?)"
        );
        $insStmt->execute([$id, $userId, $newEmail !== null ? $targetEmail : null, $tokenHash, $expiresAt, $now]);

        // Render and send email
        $actionUrl = rtrim($this->adminBaseUrl, '/') . '/auth/email/verify?token=' . $rawToken;
        $tpl = $this->templates->render('verify_email', [
            'name' => (string) ($user['name'] ?? 'there'),
            'action_url' => $actionUrl,
            'site_name' => 'BS CMS',
        ]);

        $message = new MailMessage(
            to: $targetEmail,
            subject: $tpl['subject'],
            htmlBody: $tpl['html'],
            textBody: $tpl['text'],
            template: 'verify_email',
            priority: MailMessage::PRIORITY_HIGH,
            relatedUserId: $userId
        );

        $this->mailer->send($message, true);

        $this->auditLogger->record(
            Actor::user($userId, (string) $user['email']),
            'auth.verification_sent',
            new Subject('user', $userId),
            "Sent verification token to '{$targetEmail}'",
            null,
            'success',
            $ip,
            $userAgent
        );

        return $rawToken;
    }

    /**
     * Verify email with token.
     */
    public function verify(string $rawToken, ?string $ip = null, ?string $userAgent = null): bool
    {
        if (trim($rawToken) === '') {
            return false;
        }

        $tokenHash = hash('sha256', trim($rawToken));
        $verTable = $this->connection->prefixTable('email_verifications');
        $now = Clock::sql(Clock::now());

        $stmt = $this->connection->getPdo()->prepare(
            "SELECT * FROM `{$verTable}` WHERE `token_hash` = ? AND `used_at` IS NULL AND `expires_at` > ?"
        );
        $stmt->execute([$tokenHash, $now]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        $userId = (string) $row['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null) {
            return false;
        }

        $oldEmail = (string) $user['email'];
        $newEmail = $row['new_email'] !== null ? (string) $row['new_email'] : null;

        if ($newEmail !== null && strtolower($newEmail) !== strtolower($oldEmail)) {
            // Update email address and mark verified
            $this->users->update($userId, [
                'email' => $newEmail,
                'email_verified_at' => $now,
            ]);

            // Notify former email address of security-sensitive change
            try {
                $tpl = $this->templates->render('email_changed_notice', [
                    'name' => (string) ($user['name'] ?? 'there'),
                    'site_name' => 'BS CMS',
                ]);
                $msg = new MailMessage(
                    to: $oldEmail,
                    subject: $tpl['subject'],
                    htmlBody: $tpl['html'],
                    textBody: $tpl['text'],
                    template: 'email_changed_notice',
                    priority: MailMessage::PRIORITY_HIGH,
                    relatedUserId: $userId
                );
                $this->mailer->send($msg, true);
            } catch (\Throwable) {
                // Notice delivery failure should not break verification
            }
        } else {
            $this->users->update($userId, [
                'email_verified_at' => $now,
            ]);
        }

        // Mark token used
        $upStmt = $this->connection->getPdo()->prepare("UPDATE `{$verTable}` SET `used_at` = ? WHERE `id` = ?");
        $upStmt->execute([$now, $row['id']]);

        $this->auditLogger->record(
            Actor::user($userId, $newEmail ?? $oldEmail),
            'auth.email_verified',
            new Subject('user', $userId),
            "Email verified for user '{$userId}'",
            null,
            'success',
            $ip,
            $userAgent
        );

        return true;
    }
}
