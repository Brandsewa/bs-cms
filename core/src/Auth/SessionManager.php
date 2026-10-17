<?php

declare(strict_types=1);

namespace BsCms\Auth;

use BsCms\Database\Connection;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use DateTimeImmutable;
use PDO;

/**
 * Manages user sessions, secure cookie generation, session rotation,
 * idle and absolute timeouts, and CSRF token generation/verification.
 */
final class SessionManager
{
    public const COOKIE_HOST_PREFIX = '__Host-bs_session';
    public const COOKIE_FALLBACK = 'bs_session';

    public const IDLE_TIMEOUT_SECONDS = 43200;      // 12 hours
    public const ABSOLUTE_TIMEOUT_SECONDS = 1209600; // 14 days
    public const REMEMBER_TIMEOUT_SECONDS = 2592000; // 30 days

    public function __construct(
        private readonly ?Connection $connection = null,
        private readonly int $idleTimeout = self::IDLE_TIMEOUT_SECONDS,
        private readonly int $absoluteTimeout = self::ABSOLUTE_TIMEOUT_SECONDS
    ) {
    }

    private function getConnection(): Connection
    {
        if ($this->connection === null) {
            throw new \RuntimeException('Database connection required for session persistence operations.');
        }
        return $this->connection;
    }

    /**
     * Create a new session for a user.
     *
     * @return array{id: string, token: string, csrf_token: string, expires_at: DateTimeImmutable}
     */
    public function create(
        string $userId,
        ?string $ip = null,
        ?string $userAgent = null,
        bool $remember = false,
        ?DateTimeImmutable $mfaPassedAt = null
    ): array {
        $sessionId = Ulid::generate();
        $plainToken = bin2hex(random_bytes(16)); // 32 hex characters
        $tokenHash = hash('sha256', $plainToken);
        $csrfSecret = bin2hex(random_bytes(16)); // 32 hex characters

        $now = Clock::now();
        $ttl = $remember ? self::REMEMBER_TIMEOUT_SECONDS : $this->absoluteTimeout;
        $expiresAt = $now->modify("+{$ttl} seconds");

        $conn = $this->getConnection();
        $table = $conn->prefixTable('sessions');
        $stmt = $conn->getPdo()->prepare("INSERT INTO `{$table}` (
            `id`, `user_id`, `token_hash`, `csrf_secret`, `ip`, `user_agent`,
            `mfa_passed_at`, `created_at`, `last_seen_at`, `expires_at`, `revoked_at`
        ) VALUES (
            :id, :user_id, :token_hash, :csrf_secret, :ip, :user_agent,
            :mfa_passed_at, :created_at, :last_seen_at, :expires_at, NULL
        )");

        $stmt->execute([
            'id' => $sessionId,
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'csrf_secret' => $csrfSecret,
            'ip' => $ip,
            'user_agent' => $userAgent !== null ? substr($userAgent, 0, 255) : null,
            'mfa_passed_at' => $mfaPassedAt !== null ? Clock::sql($mfaPassedAt) : null,
            'created_at' => Clock::sql($now),
            'last_seen_at' => Clock::sql($now),
            'expires_at' => Clock::sql($expiresAt),
        ]);

        return [
            'id' => $sessionId,
            'token' => $plainToken,
            'csrf_token' => $csrfSecret,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Find and validate an active session by its plain cookie token.
     * Checks token hash, revocation, absolute expiry, and idle expiry.
     *
     * @return array<string, mixed>|null Returns session record with user details, or null if invalid/expired
     */
    public function findValidSession(string $plainToken, ?DateTimeImmutable $currentTime = null): ?array
    {
        $conn = $this->getConnection();
        $tokenHash = hash('sha256', $plainToken);
        $table = $conn->prefixTable('sessions');
        $usersTable = $conn->prefixTable('users');

        $stmt = $conn->getPdo()->prepare("SELECT s.*, u.`status` as user_status, u.`email`, u.`name`
            FROM `{$table}` s
            JOIN `{$usersTable}` u ON u.`id` = s.`user_id`
            WHERE s.`token_hash` = :token_hash AND s.`revoked_at` IS NULL
            LIMIT 1");

        $stmt->execute(['token_hash' => $tokenHash]);
        /** @var array<string, mixed>|false $session */
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($session === false) {
            return null;
        }

        // Check if user is active
        if (($session['user_status'] ?? '') !== 'active') {
            return null;
        }

        $now = $currentTime ?? Clock::now();
        $nowTs = $now->getTimestamp();

        // 1. Absolute expiry check
        $expiresAtTs = (new DateTimeImmutable((string) $session['expires_at']))->getTimestamp();
        if ($nowTs > $expiresAtTs) {
            return null;
        }

        // 2. Idle timeout check
        $lastSeenTs = (new DateTimeImmutable((string) $session['last_seen_at']))->getTimestamp();
        if (($nowTs - $lastSeenTs) > $this->idleTimeout) {
            return null;
        }

        // 3. Update last_seen_at if more than 60 seconds have elapsed
        if (($nowTs - $lastSeenTs) >= 60) {
            $updateStmt = $conn->getPdo()->prepare("UPDATE `{$table}` SET `last_seen_at` = :seen WHERE `id` = :id");
            $updateStmt->execute([
                'seen' => Clock::sql($now),
                'id' => $session['id'],
            ]);
            $session['last_seen_at'] = Clock::sql($now);
        }

        return $session;
    }

    /**
     * Rotate session: revokes current session and issues a new session ID and token,
     * preserving user ID, CSRF secret, and MFA state.
     * Used upon login, 2FA challenge pass, and privilege escalation to prevent session fixation.
     *
     * @return array{id: string, token: string, csrf_token: string, expires_at: DateTimeImmutable}
     */
    public function rotate(string $sessionId, ?string $ip = null, ?string $userAgent = null): array
    {
        $conn = $this->getConnection();
        $table = $conn->prefixTable('sessions');
        $stmt = $conn->getPdo()->prepare("SELECT * FROM `{$table}` WHERE `id` = :id AND `revoked_at` IS NULL LIMIT 1");
        $stmt->execute(['id' => $sessionId]);
        /** @var array<string, mixed>|false $old */
        $old = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($old === false) {
            throw new \RuntimeException("Session '{$sessionId}' not found or already revoked.");
        }

        // Revoke old session
        $this->revoke($sessionId);

        // Create rotated replacement
        $mfaPassed = !empty($old['mfa_passed_at'])
            ? new DateTimeImmutable((string) $old['mfa_passed_at'])
            : null;

        return $this->create(
            userId: (string) $old['user_id'],
            ip: $ip ?? ($old['ip'] !== null ? (string) $old['ip'] : null),
            userAgent: $userAgent ?? ($old['user_agent'] !== null ? (string) $old['user_agent'] : null),
            remember: false,
            mfaPassedAt: $mfaPassed
        );
    }

    /**
     * Mark 2FA as passed for a session.
     */
    public function markMfaPassed(string $sessionId): void
    {
        $conn = $this->getConnection();
        $table = $conn->prefixTable('sessions');
        $now = Clock::sql(Clock::now());
        $stmt = $conn->getPdo()->prepare("UPDATE `{$table}` SET `mfa_passed_at` = :now WHERE `id` = :id");
        $stmt->execute(['now' => $now, 'id' => $sessionId]);
    }

    /**
     * Revoke a single session.
     */
    public function revoke(string $sessionId): void
    {
        $conn = $this->getConnection();
        $table = $conn->prefixTable('sessions');
        $now = Clock::sql(Clock::now());
        $stmt = $conn->getPdo()->prepare("UPDATE `{$table}` SET `revoked_at` = :now WHERE `id` = :id AND `revoked_at` IS NULL");
        $stmt->execute(['now' => $now, 'id' => $sessionId]);
    }

    /**
     * Revoke all sessions for a user, optionally keeping the current one.
     */
    public function revokeAllForUser(string $userId, ?string $exceptSessionId = null): void
    {
        $conn = $this->getConnection();
        $table = $conn->prefixTable('sessions');
        $now = Clock::sql(Clock::now());

        if ($exceptSessionId !== null) {
            $stmt = $conn->getPdo()->prepare("UPDATE `{$table}` SET `revoked_at` = :now WHERE `user_id` = :user_id AND `id` != :except AND `revoked_at` IS NULL");
            $stmt->execute(['now' => $now, 'user_id' => $userId, 'except' => $exceptSessionId]);
        } else {
            $stmt = $conn->getPdo()->prepare("UPDATE `{$table}` SET `revoked_at` = :now WHERE `user_id` = :user_id AND `revoked_at` IS NULL");
            $stmt->execute(['now' => $now, 'user_id' => $userId]);
        }
    }

    /**
     * List all currently active unrevoked sessions for a user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listActiveForUser(string $userId): array
    {
        $conn = $this->getConnection();
        $table = $conn->prefixTable('sessions');
        $now = Clock::sql(Clock::now());

        $stmt = $conn->getPdo()->prepare(
            "SELECT `id`, `user_id`, `ip`, `user_agent`, `mfa_passed_at`, `created_at`, `last_seen_at`, `expires_at`
             FROM `{$table}`
             WHERE `user_id` = :user_id AND `revoked_at` IS NULL AND `expires_at` > :now
             ORDER BY `last_seen_at` DESC"
        );
        $stmt->execute(['user_id' => $userId, 'now' => $now]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Format a Set-Cookie header string for the session cookie.
     */
    public function buildCookieHeader(string $token, int $maxAge, bool $isHttps): string
    {
        $cookieName = $isHttps ? self::COOKIE_HOST_PREFIX : self::COOKIE_FALLBACK;
        $secureFlag = $isHttps ? '; Secure' : '';

        return "{$cookieName}={$token}; Path=/; HttpOnly; SameSite=Lax{$secureFlag}; Max-Age={$maxAge}";
    }

    /**
     * Format a Set-Cookie header string to clear/delete the session cookie.
     */
    public function buildClearCookieHeader(bool $isHttps): string
    {
        $cookieName = $isHttps ? self::COOKIE_HOST_PREFIX : self::COOKIE_FALLBACK;
        $secureFlag = $isHttps ? '; Secure' : '';

        return "{$cookieName}=deleted; Path=/; HttpOnly; SameSite=Lax{$secureFlag}; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT";
    }

    /**
     * Extract session token from cookie params.
     *
     * @param array<string, string> $cookies
     */
    public function extractToken(array $cookies): ?string
    {
        if (isset($cookies[self::COOKIE_HOST_PREFIX])) {
            return $cookies[self::COOKIE_HOST_PREFIX];
        }

        if (isset($cookies[self::COOKIE_FALLBACK])) {
            return $cookies[self::COOKIE_FALLBACK];
        }

        return null;
    }
}
