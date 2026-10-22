<?php

declare(strict_types=1);

namespace BsCms\Auth;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Mail\Mailer;
use BsCms\Mail\MailMessage;
use BsCms\Mail\TemplateRenderer;
use BsCms\Security\Crypto;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use BsCms\Users\UserRepository;
use RuntimeException;

/**
 * LoginService: orchestrates credential verification, anti-enumeration timing equality,
 * account lockout backoff, 2FA challenge resolution, session creation, and audit logging.
 */
final class LoginService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $auditLogger,
        private readonly ?Mailer $mailer = null,
        private readonly ?TemplateRenderer $templates = null,
        private readonly ?Crypto $crypto = null,
        private readonly ?\BsCms\Settings\SettingsRepository $settings = null
    ) {
    }

    /**
     * Authenticate with email and password.
     *
     * @return array<string, mixed>
     */
    public function login(
        string $email,
        string $password,
        ?string $ip = null,
        ?string $userAgent = null,
        bool $rememberMe = false,
        ?string $requestId = null
    ): array {
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Anti-enumeration: execute dummy argon2id verification to equalize timing
            $this->hasher->dummyVerify();

            $this->auditLogger->record(
                actor: Actor::system(),
                action: 'auth.login_failed',
                subject: new Subject('auth', 'login'),
                summary: "Login attempt for non-existent email '{$email}'",
                result: 'failure',
                requestId: $requestId,
                ip: $ip,
                userAgent: $userAgent
            );

            return [
                'status' => 'failed',
                'message' => 'Email or password is incorrect.',
            ];
        }

        // Check if account is suspended
        if ($user['status'] === 'suspended') {
            return [
                'status' => 'suspended',
                'message' => 'This account has been suspended. Please contact support.',
            ];
        }

        // Check if account is locked
        if ($user['locked_until'] !== null) {
            $lockTime = strtotime((string) $user['locked_until']);
            if ($lockTime > time()) {
                return [
                    'status' => 'locked',
                    'message' => 'Account is temporarily locked due to too many failed attempts.',
                    'locked_until' => $user['locked_until'],
                ];
            }
        }

        $passwordHash = (string) ($user['password_hash'] ?? '');
        $valid = $this->hasher->verify($password, $passwordHash);

        if (!$valid) {
            $failedLogins = (int) $user['failed_logins'] + 1;
            $lockedUntil = null;

            $secPolicy = [];
            if ($this->settings !== null) {
                try {
                    $sec = $this->settings->get('security_settings', []);
                    if (is_array($sec)) {
                        $secPolicy = $sec;
                    }
                } catch (\Throwable) {
                    $secPolicy = [];
                }
            }

            $maxAttempts = isset($secPolicy['login_max_attempts']) && is_numeric($secPolicy['login_max_attempts'])
                ? (int) $secPolicy['login_max_attempts']
                : 5;
            $decayMinutes = isset($secPolicy['login_decay_minutes']) && is_numeric($secPolicy['login_decay_minutes'])
                ? (int) $secPolicy['login_decay_minutes']
                : 15;

            if ($failedLogins >= $maxAttempts) {
                // Configured lockout decay window in minutes
                $delaySeconds = $decayMinutes * 60;
                $lockedUntil = Clock::sql(Clock::now()->modify("+{$delaySeconds} seconds"));
            }

            $this->users->update((string) $user['id'], [
                'failed_logins' => $failedLogins,
                'locked_until' => $lockedUntil,
            ]);

            $this->auditLogger->record(
                actor: Actor::system(),
                action: 'auth.login_failed',
                subject: new Subject('user', (string) $user['id']),
                summary: "Failed password verification for user '{$user['email']}' (attempt {$failedLogins})",
                result: 'failure',
                requestId: $requestId,
                ip: $ip,
                userAgent: $userAgent,
                subjectId: (string) $user['id']
            );

            return [
                'status' => 'failed',
                'message' => 'Email or password is incorrect.',
            ];
        }

        // Rehash password if algorithm settings upgraded
        if ($this->hasher->needsRehash($passwordHash)) {
            $this->users->update((string) $user['id'], [
                'password_hash' => $this->hasher->hash($password),
            ]);
        }

        // Check if 2FA is required
        if (!empty($user['totp_enabled_at'])) {
            $challengeToken = $this->create2faChallenge((string) $user['id']);
            return [
                'status' => 'requires_2fa',
                'user_id' => (string) $user['id'],
                'challenge_token' => $challengeToken,
            ];
        }

        // Check if 2FA policy is enforced for this user or role
        if ($this->settings !== null) {
            $sec = $this->settings->get('security_settings', []);
            $policy = $sec['two_factor_policy'] ?? 'optional';
            $isEnforced = false;

            if ($policy === 'mandatory_all') {
                $isEnforced = true;
            } elseif ($policy === 'mandatory_roles') {
                $enforcedRoles = is_array($sec['two_factor_enforced_roles'] ?? null) ? $sec['two_factor_enforced_roles'] : [];
                $userRoles = array_map(static fn (array $r): string => (string) ($r['id'] ?? ''), $this->users->getRoles((string) $user['id']));
                foreach ($userRoles as $roleId) {
                    if (in_array($roleId, $enforcedRoles, true)) {
                        $isEnforced = true;
                        break;
                    }
                }
            }

            if ($isEnforced) {
                return [
                    'status' => 'requires_2fa_setup',
                    'message' => 'Two-factor authentication is required by administrator security policy.',
                    'user_id' => (string) $user['id'],
                ];
            }
        }

        // Complete normal login
        return $this->completeLogin($user, $ip, $userAgent, false, $rememberMe, $requestId);
    }

    /**
     * Complete login after successful 2FA code or recovery code.
     *
     * @return array<string, mixed>
     */
    public function verify2faChallenge(
        string $challengeToken,
        string $code,
        ?string $ip = null,
        ?string $userAgent = null,
        bool $rememberMe = false,
        ?string $requestId = null
    ): array {
        $userId = $this->resolve2faChallenge($challengeToken);
        if ($userId === null) {
            return [
                'status' => 'failed',
                'message' => 'Invalid or expired two-factor challenge.',
            ];
        }

        $user = $this->users->findById($userId);
        if ($user === null || $user['status'] === 'suspended') {
            return [
                'status' => 'failed',
                'message' => 'User not found or suspended.',
            ];
        }

        // Attempt TOTP verification
        $totpSecretEnc = $user['totp_secret_enc'] !== null ? (string) $user['totp_secret_enc'] : null;
        $totpPassed = false;

        if ($totpSecretEnc !== null && strlen(trim($code)) === 6 && ctype_digit(trim($code))) {
            try {
                $plainSecret = ($this->crypto !== null)
                    ? $this->twoFactor->decryptSecret($totpSecretEnc, $this->crypto)
                    : $totpSecretEnc;
            } catch (\Throwable) {
                $plainSecret = $totpSecretEnc;
            }

            $lastStep = !empty($user['totp_last_step']) ? (int) $user['totp_last_step'] : null;

            try {
                $verifiedStep = $this->twoFactor->verifyCode($plainSecret, trim($code), $lastStep);
            } catch (\Throwable) {
                $verifiedStep = null;
            }

            if ($verifiedStep !== null) {
                // Atomic conditional update: reject any code whose step is <= stored step
                $usersTable = $this->connection->prefixTable('users');
                $stmt = $this->connection->getPdo()->prepare(
                    "UPDATE `{$usersTable}` SET `totp_last_step` = ? WHERE `id` = ? AND (`totp_last_step` IS NULL OR `totp_last_step` < ?)"
                );
                $stmt->execute([$verifiedStep, $user['id'], $verifiedStep]);
                $totpPassed = $stmt->rowCount() > 0;
            }
        }

        // If TOTP did not pass, attempt recovery code verification
        $recoveryUsed = false;
        if (!$totpPassed && !empty($user['recovery_codes_hash'])) {
            /** @var array<int, string> $hashes */
            $hashes = is_array($user['recovery_codes_hash'])
                ? $user['recovery_codes_hash']
                : (Json::decode((string) $user['recovery_codes_hash']) ?? []);

            $rec = new RecoveryCodes();
            /** @var list<string> $storedList */
            $storedList = array_values($hashes);
            $remaining = $rec->consume(trim($code), $storedList);

            if ($remaining !== null) {
                $recoveryUsed = true;
                $this->users->update((string) $user['id'], [
                    'recovery_codes_hash' => $remaining,
                ]);
            }
        }

        if (!$totpPassed && !$recoveryUsed) {
            $this->record2faAttempt($challengeToken);

            $this->auditLogger->record(
                actor: Actor::system(),
                action: 'auth.2fa_failed',
                subject: new Subject('user', (string) $user['id']),
                summary: "Failed 2FA code verification for user '{$user['email']}'",
                result: 'failure',
                requestId: $requestId,
                ip: $ip,
                userAgent: $userAgent,
                subjectId: (string) $user['id']
            );

            return [
                'status' => 'failed',
                'message' => 'Invalid two-factor code or recovery code.',
            ];
        }

        // Verification succeeded: consume single-use challenge token
        $this->consume2faChallenge($challengeToken);

        return $this->completeLogin($user, $ip, $userAgent, true, $rememberMe, $requestId);
    }

    /**
     * Finish login process: reset failures, update login IP, send notice if new IP, create session.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function completeLogin(
        array $user,
        ?string $ip,
        ?string $userAgent,
        bool $mfaPassed,
        bool $rememberMe,
        ?string $requestId = null
    ): array {
        $userId = (string) $user['id'];
        $now = Clock::sql(Clock::now());
        $lastIp = $user['last_login_ip'] !== null ? (string) $user['last_login_ip'] : null;

        // Reset lockout and record login stats
        $this->users->update($userId, [
            'failed_logins' => 0,
            'locked_until' => null,
            'last_login_at' => $now,
            'last_login_ip' => $ip,
        ]);

        // Create user session
        $session = $this->sessions->create($userId, $ip, $userAgent, $rememberMe, $mfaPassed ? Clock::now() : null);

        // Audit log
        $this->auditLogger->record(
            actor: Actor::user($userId, (string) $user['email']),
            action: 'auth.login',
            subject: new Subject('user', $userId),
            summary: "User signed in" . ($mfaPassed ? ' with 2FA' : ''),
            result: 'success',
            requestId: $requestId,
            ip: $ip,
            userAgent: $userAgent,
            subjectId: $userId
        );

        // If login is from a new IP and mailer is available, send new login notification
        if ($this->mailer !== null && $this->templates !== null && $lastIp !== null && $ip !== null && $lastIp !== $ip) {
            try {
                $tpl = $this->templates->render('new_login_notice', [
                    'name' => (string) ($user['name'] ?? 'there'),
                    'ip' => $ip,
                    'user_agent' => $userAgent ?? 'Unknown Browser',
                    'site_name' => 'BS CMS',
                ]);
                $msg = new MailMessage(
                    to: (string) $user['email'],
                    subject: $tpl['subject'],
                    htmlBody: $tpl['html'],
                    textBody: $tpl['text'],
                    template: 'new_login_notice',
                    priority: MailMessage::PRIORITY_NORMAL,
                    relatedUserId: $userId
                );
                $this->mailer->send($msg, false);
            } catch (\Throwable) {
                // Email delivery failure should never prevent successful login
            }
        }

        return [
            'status' => 'success',
            'user' => $user,
            'session' => $session,
        ];
    }

    /**
     * Create a 5-minute temporary challenge token for 2FA.
     */
    private function create2faChallenge(string $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $bucket = "2fa:{$hash}:{$userId}";
        $now = Clock::sql(Clock::now());

        $rateTable = $this->connection->prefixTable('rate_limits');
        $stmt = $this->connection->getPdo()->prepare(
            "INSERT INTO `{$rateTable}` (`bucket`, `window_start`, `hits`)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE `hits` = 1"
        );
        $stmt->execute([$bucket, $now]);

        return $token . '.' . $userId;
    }

    /**
     * Resolve 2FA challenge token without consuming it.
     */
    private function resolve2faChallenge(string $challengeToken): ?string
    {
        $parts = explode('.', $challengeToken, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$token, $userId] = $parts;
        $hash = hash('sha256', $token);
        $bucket = "2fa:{$hash}:{$userId}";

        $rateTable = $this->connection->prefixTable('rate_limits');
        $pdo = $this->connection->getPdo();
        $cutoff = Clock::sql(Clock::now()->modify('-5 minutes'));

        $stmt = $pdo->prepare("SELECT `hits` FROM `{$rateTable}` WHERE `bucket` = ? AND `window_start` >= ?");
        $stmt->execute([$bucket, $cutoff]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        // Limit maximum wrong attempts on a challenge token to 5
        if ((int) $row['hits'] >= 5) {
            $del = $pdo->prepare("DELETE FROM `{$rateTable}` WHERE `bucket` = ?");
            $del->execute([$bucket]);
            return null;
        }

        return $userId;
    }

    private function consume2faChallenge(string $challengeToken): void
    {
        $parts = explode('.', $challengeToken, 2);
        if (count($parts) !== 2) {
            return;
        }
        [$token, $userId] = $parts;
        $hash = hash('sha256', $token);
        $bucket = "2fa:{$hash}:{$userId}";

        $rateTable = $this->connection->prefixTable('rate_limits');
        $del = $this->connection->getPdo()->prepare("DELETE FROM `{$rateTable}` WHERE `bucket` = ?");
        $del->execute([$bucket]);
    }

    private function record2faAttempt(string $challengeToken): void
    {
        $parts = explode('.', $challengeToken, 2);
        if (count($parts) !== 2) {
            return;
        }
        [$token, $userId] = $parts;
        $hash = hash('sha256', $token);
        $bucket = "2fa:{$hash}:{$userId}";

        $rateTable = $this->connection->prefixTable('rate_limits');
        $up = $this->connection->getPdo()->prepare("UPDATE `{$rateTable}` SET `hits` = `hits` + 1 WHERE `bucket` = ?");
        $up->execute([$bucket]);
    }
}
