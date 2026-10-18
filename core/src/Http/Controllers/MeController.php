<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\EmailVerificationService;
use BsCms\Auth\PasswordHasher;
use BsCms\Auth\RecoveryCodes;
use BsCms\Auth\SessionManager;
use BsCms\Auth\TwoFactorService;
use BsCms\Authz\Authorizer;
use BsCms\Authz\PermissionCompiler;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Security\Crypto;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use BsCms\Users\UserPresenter;
use BsCms\Users\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller for self-management: profile, password, email, 2FA, recovery codes,
 * sessions, compiled permissions, and notification preferences.
 */
class MeController extends BaseController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionManager $sessions,
        private readonly PasswordHasher $hasher,
        private readonly TwoFactorService $twoFactor,
        private readonly EmailVerificationService $verificationService,
        private readonly AuditLogger $auditLogger,
        private readonly PermissionCompiler $permissionCompiler,
        private readonly Connection $connection,
        private readonly ?Crypto $crypto = null
    ) {
    }

    public function getProfile(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $userId = $actor->onBehalfOf ?? $actor->id;
        $user = $this->users->findById($userId);

        if ($user === null) {
            return $this->problem(404, 'Not Found', 'User profile not found.');
        }

        return $this->json(UserPresenter::present($user));
    }

    public function updateProfile(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $body = $this->getBody($request);

        $allowedFields = ['name', 'locale', 'timezone', 'avatar_media_id'];
        $updates = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $body)) {
                $updates[$field] = $body[$field];
            }
        }

        if (empty($updates)) {
            return $this->problem(422, 'Unprocessable Content', 'No valid profile fields provided for update.');
        }

        $this->users->update($actor->id, $updates);

        $serverParams = $request->getServerParams();
        $ip = isset($serverParams['REMOTE_ADDR']) && is_string($serverParams['REMOTE_ADDR']) ? $serverParams['REMOTE_ADDR'] : null;
        $userAgent = $request->getHeaderLine('User-Agent') ?: null;
        $requestId = (string) ($request->getAttribute('request_id') ?? '');

        $this->auditLogger->record(
            actor: $actor,
            action: 'user.updated',
            subject: new Subject('user', $actor->id),
            summary: 'Updated profile details',
            diff: $updates,
            requestId: $requestId,
            ip: $ip,
            userAgent: $userAgent,
            subjectId: $actor->id
        );

        return $this->json(['status' => 'profile_updated']);
    }

    public function changePassword(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $body = $this->getBody($request);
        $currentPassword = (string) ($body['current_password'] ?? '');
        $newPassword = (string) ($body['new_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '') {
            return $this->problem(422, 'Unprocessable Content', 'Current password and new password are required.');
        }

        $user = $this->users->findById($actor->id);
        if ($user === null) {
            return $this->problem(404, 'Not Found', 'User not found.');
        }

        if (!$this->hasher->verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
            return $this->problem(401, 'Unauthorized', 'Current password is incorrect.');
        }

        try {
            $this->hasher->validatePolicy($newPassword);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Content', $e->getMessage());
        }

        $newHash = $this->hasher->hash($newPassword);
        $this->users->update($actor->id, ['password_hash' => $newHash]);

        // Revoke other sessions
        /** @var array<string, mixed>|null $session */
        $session = $request->getAttribute('session');
        $currentSessionId = $session !== null && isset($session['id']) ? (string) $session['id'] : null;
        $this->sessions->revokeAllForUser($actor->id, $currentSessionId);

        $serverParams = $request->getServerParams();
        $ip = isset($serverParams['REMOTE_ADDR']) && is_string($serverParams['REMOTE_ADDR']) ? $serverParams['REMOTE_ADDR'] : null;
        $userAgent = $request->getHeaderLine('User-Agent') ?: null;
        $requestId = (string) ($request->getAttribute('request_id') ?? '');

        $this->auditLogger->record(
            actor: $actor,
            action: 'auth.password_changed',
            subject: new Subject('user', $actor->id),
            summary: 'Changed account password',
            requestId: $requestId,
            ip: $ip,
            userAgent: $userAgent,
            subjectId: $actor->id
        );

        return $this->json(['status' => 'password_changed']);
    }

    public function requestEmailChange(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $actor = $this->getActor($request);
        $body = $this->getBody($request);
        $newEmail = (string) ($body['new_email'] ?? '');

        if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->problem(422, 'Unprocessable Content', 'A valid new email address is required.');
        }

        try {
            $this->verificationService->sendVerification($actor->id, $newEmail);
            return $this->json([
                'message' => "Verification link sent to '{$newEmail}'.",
            ]);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Content', $e->getMessage());
        }
    }

    public function setup2fa(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $user = $this->users->findById($actor->id);
        if ($user === null) {
            return $this->problem(404, 'Not Found', 'User not found.');
        }

        $secret = $this->twoFactor->generateSecret();
        $uri = $this->twoFactor->getTotpUri($secret, (string) $user['email']);

        // Store pending secret server-side (encrypted) with totp_enabled_at = null
        $storedSecret = $this->crypto !== null ? $this->twoFactor->encryptSecret($secret, $this->crypto) : $secret;
        $this->users->update($actor->id, [
            'totp_secret_enc' => $storedSecret,
            'totp_enabled_at' => null,
        ]);

        return $this->json([
            'secret' => $secret,
            'otpauth_uri' => $uri,
        ]);
    }

    public function confirm2fa(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $user = $this->users->findById($actor->id);
        if ($user === null) {
            return $this->problem(404, 'Not Found', 'User not found.');
        }

        $body = $this->getBody($request);
        $code = (string) ($body['code'] ?? '');

        if ($code === '') {
            return $this->problem(422, 'Unprocessable Content', 'Verification code is required.');
        }

        // Retrieve pending secret from server-side storage (or fallback to body['secret'] if sent)
        $rawSecret = (string) ($user['totp_secret_enc'] ?? '');
        if ($rawSecret === '' && isset($body['secret']) && is_string($body['secret'])) {
            $rawSecret = $body['secret'];
        }

        if ($rawSecret === '') {
            return $this->problem(400, 'Bad Request', 'No pending 2FA setup found. Please initiate 2FA setup first.');
        }

        try {
            $secret = $this->crypto !== null ? $this->twoFactor->decryptSecret($rawSecret, $this->crypto) : $rawSecret;
        } catch (\Throwable) {
            $secret = $rawSecret;
        }

        $step = $this->twoFactor->verifyCode($secret, $code);
        if ($step === null) {
            return $this->problem(400, 'Bad Request', 'Invalid verification code for the provided secret.');
        }

        // Generate 10 recovery codes
        $rec = new RecoveryCodes();
        $recovery = $rec->generate(10);
        $now = Clock::sql(Clock::now());

        $this->users->update($actor->id, [
            'totp_secret_enc' => $rawSecret,
            'totp_enabled_at' => $now,
            'recovery_codes_hash' => $recovery['hashes'],
        ]);

        $serverParams = $request->getServerParams();
        $ip = isset($serverParams['REMOTE_ADDR']) && is_string($serverParams['REMOTE_ADDR']) ? $serverParams['REMOTE_ADDR'] : null;
        $userAgent = $request->getHeaderLine('User-Agent') ?: null;
        $requestId = (string) ($request->getAttribute('request_id') ?? '');

        $this->auditLogger->record(
            actor: $actor,
            action: 'auth.2fa_enabled',
            subject: new Subject('user', $actor->id),
            summary: 'Enabled two-factor authentication',
            requestId: $requestId,
            ip: $ip,
            userAgent: $userAgent,
            subjectId: $actor->id
        );

        return $this->json([
            'status' => '2fa_enabled',
            'recovery_codes' => $recovery['codes'],
        ]);
    }

    public function disable2fa(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $actor = $this->getActor($request);

        $this->users->update($actor->id, [
            'totp_secret_enc' => null,
            'totp_enabled_at' => null,
            'recovery_codes_hash' => null,
        ]);

        $serverParams = $request->getServerParams();
        $ip = isset($serverParams['REMOTE_ADDR']) && is_string($serverParams['REMOTE_ADDR']) ? $serverParams['REMOTE_ADDR'] : null;
        $userAgent = $request->getHeaderLine('User-Agent') ?: null;
        $requestId = (string) ($request->getAttribute('request_id') ?? '');

        $this->auditLogger->record(
            actor: $actor,
            action: 'auth.2fa_disabled',
            subject: new Subject('user', $actor->id),
            summary: 'Disabled two-factor authentication',
            requestId: $requestId,
            ip: $ip,
            userAgent: $userAgent,
            subjectId: $actor->id
        );

        return $this->json(['status' => '2fa_disabled']);
    }

    public function regenerateRecoveryCodes(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $actor = $this->getActor($request);
        $user = $this->users->findById($actor->id);

        if ($user === null || empty($user['totp_enabled_at'])) {
            return $this->problem(400, 'Bad Request', 'Two-factor authentication is not enabled.');
        }

        $rec = new RecoveryCodes();
        $generated = $rec->generate(10);

        $this->users->update($actor->id, [
            'recovery_codes_hash' => $generated['hashes'],
        ]);

        $serverParams = $request->getServerParams();
        $ip = isset($serverParams['REMOTE_ADDR']) && is_string($serverParams['REMOTE_ADDR']) ? $serverParams['REMOTE_ADDR'] : null;
        $userAgent = $request->getHeaderLine('User-Agent') ?: null;
        $requestId = (string) ($request->getAttribute('request_id') ?? '');

        $this->auditLogger->record(
            actor: $actor,
            action: 'auth.recovery_codes_regenerated',
            subject: new Subject('user', $actor->id),
            summary: 'Regenerated 2FA recovery codes',
            requestId: $requestId,
            ip: $ip,
            userAgent: $userAgent,
            subjectId: $actor->id
        );

        return $this->json([
            'status' => 'recovery_codes_regenerated',
            'recovery_codes' => $generated['codes'],
        ]);
    }

    public function getSessions(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $sessions = $this->sessions->listActiveForUser($actor->id);

        /** @var array<string, mixed>|null $currentSession */
        $currentSession = $request->getAttribute('session');
        $currentId = $currentSession !== null && isset($currentSession['id']) ? $currentSession['id'] : null;

        foreach ($sessions as &$s) {
            $s['is_current'] = $s['id'] === $currentId;
        }

        return $this->json(['items' => $sessions]);
    }

    public function revokeSession(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $sessionId = (string) $request->getAttribute('id', '');

        // Verify session belongs to user
        $sessions = $this->sessions->listActiveForUser($actor->id);
        $owns = false;
        foreach ($sessions as $s) {
            if ($s['id'] === $sessionId) {
                $owns = true;
                break;
            }
        }

        if (!$owns) {
            return $this->problem(404, 'Not Found', 'Session not found.');
        }

        $this->sessions->revoke($sessionId);

        return $this->json(['status' => 'session_revoked']);
    }

    public function getPermissions(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $compiled = $this->permissionCompiler->compile($actor->roleIds);
        $permissions = array_map(fn ($p) => $p->toArray(), $compiled);

        return $this->json([
            'actor_id' => $actor->id,
            'role_ids' => $actor->roleIds,
            'permissions' => $permissions,
        ]);
    }

    public function getNotificationPreferences(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $settingsTable = $this->connection->prefixTable('settings');
        $stmt = $this->connection->getPdo()->prepare(
            "SELECT `value` FROM `{$settingsTable}` WHERE `key` = ?"
        );
        $stmt->execute(["user_notif_prefs:{$actor->id}"]);
        $val = $stmt->fetchColumn();

        $prefs = is_string($val) ? Json::decode($val) : [
            'email_on_login' => true,
            'email_on_role_change' => true,
            'in_app_system_alerts' => true,
        ];

        return $this->json(['preferences' => $prefs]);
    }

    public function updateNotificationPreferences(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $body = $this->getBody($request);
        $preferences = (array) ($body['preferences'] ?? $body);

        $settingsTable = $this->connection->prefixTable('settings');
        $now = Clock::sql(Clock::now());

        $stmt = $this->connection->getPdo()->prepare(
            "INSERT INTO `{$settingsTable}` (`key`, `value`, `is_secret`, `updated_at`, `updated_by`)
             VALUES (?, ?, 0, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`), `updated_by` = VALUES(`updated_by`)"
        );
        $stmt->execute([
            "user_notif_prefs:{$actor->id}",
            Json::encode($preferences),
            $now,
            $actor->id,
        ]);

        return $this->json(['status' => 'preferences_updated']);
    }
}
