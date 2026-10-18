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
use BsCms\Users\RoleRepository;
use BsCms\Users\UserRepository;
use PDO;
use RuntimeException;

/**
 * InvitationService: handles user invitations, 7-day token issuance and revocation,
 * high-priority invitation emails, and account onboarding.
 */
final class InvitationService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly PasswordHasher $hasher,
        private readonly AuditLogger $auditLogger,
        private readonly Mailer $mailer,
        private readonly TemplateRenderer $templates,
        private readonly string $adminBaseUrl = 'http://localhost:8080'
    ) {
    }

    /**
     * Issue an invitation to join the CMS.
     */
    public function invite(
        string $email,
        string $roleId,
        string $invitedByUserId,
        ?string $ip = null,
        ?string $userAgent = null
    ): string {
        $cleanEmail = strtolower(trim($email));

        $existingUser = $this->users->findByEmail($cleanEmail);
        if ($existingUser !== null && $existingUser['status'] === 'active') {
            throw new RuntimeException("An active user with email '{$cleanEmail}' already exists.");
        }

        $role = $this->roles->findById($roleId);
        if ($role === null) {
            throw new RuntimeException("Role '{$roleId}' does not exist.");
        }

        $inviter = $this->users->findById($invitedByUserId);
        $inviterName = $inviter !== null ? (string) ($inviter['name'] ?? 'An administrator') : 'An administrator';

        $pdo = $this->connection->getPdo();
        $invitesTable = $this->connection->prefixTable('invitations');
        $now = Clock::sql(Clock::now());

        // Revoke any previous pending invitations for this email
        $revStmt = $pdo->prepare(
            "UPDATE `{$invitesTable}` SET `revoked_at` = ? WHERE `email` = ? AND `accepted_at` IS NULL AND `revoked_at` IS NULL"
        );
        $revStmt->execute([$now, $cleanEmail]);

        // Generate 32-byte cryptographically secure token
        $rawBytes = random_bytes(32);
        $rawToken = rtrim(strtr(base64_encode($rawBytes), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $rawToken);

        $id = Ulid::generate();
        $expiresAt = Clock::sql(Clock::now()->modify('+7 days'));

        $insStmt = $pdo->prepare(
            "INSERT INTO `{$invitesTable}` (`id`, `email`, `role_id`, `token_hash`, `invited_by`, `expires_at`, `accepted_at`, `revoked_at`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?)"
        );
        $insStmt->execute([$id, $cleanEmail, $roleId, $tokenHash, $invitedByUserId, $expiresAt, $now]);

        // If user record doesn't exist yet, create a pending_invite user record
        if ($existingUser === null) {
            $this->users->create([
                'email' => $cleanEmail,
                'name' => $cleanEmail,
                'status' => 'pending_invite',
            ], [$roleId]);
        }

        // Send high priority email
        $actionUrl = rtrim($this->adminBaseUrl, '/') . '/auth/invitations/' . $rawToken . '/accept';
        $tpl = $this->templates->render('invitation', [
            'name' => '',
            'inviter_name' => $inviterName,
            'role_name' => (string) $role['name'],
            'action_url' => $actionUrl,
            'site_name' => 'BS CMS',
        ]);

        $message = new MailMessage(
            to: $cleanEmail,
            subject: $tpl['subject'],
            htmlBody: $tpl['html'],
            textBody: $tpl['text'],
            template: 'invitation',
            priority: MailMessage::PRIORITY_HIGH
        );

        $this->mailer->send($message, true);

        $this->auditLogger->record(
            actor: Actor::user($invitedByUserId, $inviter !== null ? (string) $inviter['email'] : ''),
            action: 'user.invited',
            subject: new Subject('invitation', $id),
            summary: "Invited '{$cleanEmail}' with role '{$role['name']}'",
            result: 'success',
            ip: $ip,
            userAgent: $userAgent,
            subjectId: $id
        );

        return $rawToken;
    }

    /**
     * Validate a raw invitation token.
     *
     * @return array<string, mixed>|null
     */
    public function validateToken(string $rawToken): ?array
    {
        if (trim($rawToken) === '') {
            return null;
        }

        $tokenHash = hash('sha256', trim($rawToken));
        $invitesTable = $this->connection->prefixTable('invitations');
        $rolesTable = $this->connection->prefixTable('roles');
        $now = Clock::sql(Clock::now());

        $sql = "SELECT i.*, r.name as role_name, r.handle as role_handle
                FROM `{$invitesTable}` i
                INNER JOIN `{$rolesTable}` r ON r.id = i.role_id
                WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > ?";

        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute([$tokenHash, $now]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Accept invitation, setting name and password.
     */
    public function accept(
        string $rawToken,
        string $name,
        string $password,
        ?string $ip = null,
        ?string $userAgent = null
    ): string {
        $invite = $this->validateToken($rawToken);
        if ($invite === null) {
            throw new RuntimeException("Invalid, expired, or revoked invitation token.");
        }

        $this->hasher->validatePolicy($password);

        $email = (string) $invite['email'];
        $roleId = (string) $invite['role_id'];
        $newHash = $this->hasher->hash($password);
        $now = Clock::sql(Clock::now());

        $user = $this->users->findByEmail($email);
        if ($user !== null) {
            $userId = (string) $user['id'];
            $this->users->update($userId, [
                'name' => $name,
                'password_hash' => $newHash,
                'status' => 'active',
                'email_verified_at' => $now,
            ]);
            $this->users->setRoles($userId, [$roleId]);
        } else {
            $userId = $this->users->create([
                'email' => $email,
                'name' => $name,
                'password_hash' => $newHash,
                'status' => 'active',
                'email_verified_at' => $now,
            ], [$roleId]);
        }

        // Mark invitation as accepted
        $invitesTable = $this->connection->prefixTable('invitations');
        $upStmt = $this->connection->getPdo()->prepare(
            "UPDATE `{$invitesTable}` SET `accepted_at` = ? WHERE `id` = ?"
        );
        $upStmt->execute([$now, $invite['id']]);

        $this->auditLogger->record(
            actor: Actor::user($userId, $email),
            action: 'invitation.accepted',
            subject: new Subject('user', $userId),
            summary: "User '{$email}' accepted invitation and created account",
            result: 'success',
            ip: $ip,
            userAgent: $userAgent,
            subjectId: $userId
        );

        return $userId;
    }

    /**
     * Revoke an active invitation.
     */
    public function revoke(string $invitationId, string $actorUserId, ?string $ip = null, ?string $userAgent = null): bool
    {
        $invitesTable = $this->connection->prefixTable('invitations');
        $now = Clock::sql(Clock::now());

        $stmt = $this->connection->getPdo()->prepare(
            "UPDATE `{$invitesTable}` SET `revoked_at` = ? WHERE `id` = ? AND `accepted_at` IS NULL AND `revoked_at` IS NULL"
        );
        $stmt->execute([$now, $invitationId]);

        $affected = $stmt->rowCount() > 0;
        if ($affected) {
            $this->auditLogger->record(
                actor: Actor::user($actorUserId),
                action: 'invitation.revoked',
                subject: new Subject('invitation', $invitationId),
                summary: "Revoked invitation '{$invitationId}'",
                result: 'success',
                ip: $ip,
                userAgent: $userAgent,
                subjectId: $invitationId
            );
        }

        return $affected;
    }

    /**
     * Resend an invitation.
     */
    public function resend(string $invitationId, string $actorUserId, ?string $ip = null, ?string $userAgent = null): string
    {
        $invitesTable = $this->connection->prefixTable('invitations');
        $stmt = $this->connection->getPdo()->prepare("SELECT * FROM `{$invitesTable}` WHERE `id` = ?");
        $stmt->execute([$invitationId]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException("Invitation '{$invitationId}' not found.");
        }

        return $this->invite(
            (string) $row['email'],
            (string) $row['role_id'],
            $actorUserId,
            $ip,
            $userAgent
        );
    }
}
