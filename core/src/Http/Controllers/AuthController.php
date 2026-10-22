<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Auth\EmailVerificationService;
use BsCms\Auth\InvitationService;
use BsCms\Auth\LoginService;
use BsCms\Auth\PasswordHasher;
use BsCms\Auth\PasswordResetService;
use BsCms\Auth\RecoveryCodes;
use BsCms\Auth\SessionManager;
use BsCms\Auth\TwoFactorService;
use BsCms\Database\Connection;
use BsCms\Security\Crypto;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use BsCms\Users\UserPresenter;
use BsCms\Users\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller handling human authentication endpoints: login, logout, 2FA challenge,
 * password recovery, invitations, email verification, and step-up authentication.
 */
class AuthController extends BaseController
{
    public function __construct(
        private readonly LoginService $loginService,
        private readonly SessionManager $sessionManager,
        private readonly PasswordResetService $resetService,
        private readonly InvitationService $invitationService,
        private readonly EmailVerificationService $verificationService,
        private readonly UserRepository $userRepository,
        private readonly PasswordHasher $passwordHasher,
        private readonly TwoFactorService $twoFactorService,
        private readonly Connection $connection,
        private readonly ?Crypto $crypto = null
    ) {
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $email = (string) ($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $remember = (bool) ($body['remember_me'] ?? false);

        $ip = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');
        $requestId = (string) ($request->getAttribute('request_id') ?? '');

        $result = $this->loginService->login($email, $password, $ip, $userAgent, $remember, $requestId);

        if ($result['status'] === 'failed') {
            return $this->problem(401, 'Unauthorized', (string) $result['message']);
        }

        if ($result['status'] === 'locked') {
            return $this->problem(423, 'Locked', (string) $result['message']);
        }

        if ($result['status'] === 'suspended') {
            return $this->problem(403, 'Forbidden', (string) $result['message']);
        }

        if ($result['status'] === 'requires_2fa_setup') {
            return $this->problem(403, 'Forbidden', (string) $result['message'], null, [
                'requires_2fa_setup' => true,
                'user_id' => $result['user_id'] ?? null,
            ]);
        }

        if ($result['status'] === 'requires_2fa') {
            return $this->json([
                'status' => 'requires_2fa',
                'user_id' => $result['user_id'],
                'challenge_token' => $result['challenge_token'],
            ]);
        }

        /** @var array<string, mixed> $session */
        $session = $result['session'];
        /** @var array<string, mixed> $user */
        $user = $result['user'];
        $isHttps = $request->getUri()->getScheme() === 'https';

        $ttl = $remember ? SessionManager::REMEMBER_TIMEOUT_SECONDS : SessionManager::ABSOLUTE_TIMEOUT_SECONDS;
        $cookieHeader = $this->sessionManager->buildCookieHeader((string) $session['token'], $ttl, $isHttps);

        return $this->json([
            'status' => 'success',
            'user' => UserPresenter::present($user),
            'csrf_token' => $session['csrf_token'],
        ], 200, ['Set-Cookie' => $cookieHeader]);
    }

    /**
     * POST /api/v1/auth/2fa/challenge
     *
     * Completes a 2FA challenge using a 6-digit TOTP code or a single-use recovery code.
     * Note: The 'recovery_code' field is explicitly rejected (HTTP 422). All 2FA credentials
     * (TOTP or recovery code) must be passed in the 'code' field.
     */
    public function challenge2fa(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);

        if (array_key_exists('recovery_code', $body)) {
            return $this->problem(422, 'Unprocessable Content', "The 'recovery_code' field is not supported. Submit recovery codes in the 'code' field.");
        }

        $challengeToken = (string) ($body['challenge_token'] ?? '');
        $code = (string) ($body['code'] ?? '');
        $remember = (bool) ($body['remember_me'] ?? false);

        if ($challengeToken === '' || $code === '') {
            return $this->problem(422, 'Unprocessable Content', "Both 'challenge_token' and 'code' are required.");
        }

        $ip = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');
        $requestId = (string) ($request->getAttribute('request_id') ?? '');

        $result = $this->loginService->verify2faChallenge($challengeToken, $code, $ip, $userAgent, $remember, $requestId);

        if ($result['status'] === 'failed') {
            return $this->problem(401, 'Unauthorized', (string) $result['message']);
        }

        /** @var array<string, mixed> $session */
        $session = $result['session'];
        /** @var array<string, mixed> $user */
        $user = $result['user'];
        $isHttps = $request->getUri()->getScheme() === 'https';

        $ttl = $remember ? SessionManager::REMEMBER_TIMEOUT_SECONDS : SessionManager::ABSOLUTE_TIMEOUT_SECONDS;
        $cookieHeader = $this->sessionManager->buildCookieHeader((string) $session['token'], $ttl, $isHttps);

        return $this->json([
            'status' => 'success',
            'user' => UserPresenter::present($user),
            'csrf_token' => $session['csrf_token'],
        ], 200, ['Set-Cookie' => $cookieHeader]);
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $session */
        $session = $request->getAttribute('session');
        if ($session !== null && isset($session['id'])) {
            $this->sessionManager->revoke((string) $session['id']);
        }

        $isHttps = $request->getUri()->getScheme() === 'https';
        $clearCookie = $this->sessionManager->buildClearCookieHeader($isHttps);

        return $this->json(['status' => 'logged_out'], 200, ['Set-Cookie' => $clearCookie]);
    }

    public function logoutAll(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $this->sessionManager->revokeAllForUser($actor->id);

        $isHttps = $request->getUri()->getScheme() === 'https';
        $clearCookie = $this->sessionManager->buildClearCookieHeader($isHttps);

        return $this->json(['status' => 'all_sessions_revoked'], 200, ['Set-Cookie' => $clearCookie]);
    }

    public function session(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $user = $this->userRepository->findById($actor->id);
        /** @var array<string, mixed>|null $session */
        $session = $request->getAttribute('session');

        if ($user === null || $session === null) {
            return $this->problem(401, 'Unauthorized', 'Session expired.');
        }

        return $this->json([
            'status' => 'authenticated',
            'user' => UserPresenter::present($user),
            'csrf_token' => $session['csrf_secret'] ?? '',
        ]);
    }

    public function forgotPassword(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $email = (string) ($body['email'] ?? '');

        if ($email !== '') {
            $ip = $this->getClientIp($request);
            $userAgent = $request->getHeaderLine('User-Agent');
            $this->resetService->requestReset($email, $ip, $userAgent);
        }

        return $this->json([
            'message' => 'If that email address exists in our system, we have sent a password reset link.',
        ]);
    }

    public function resetPassword(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $token = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($token === '' || $password === '') {
            return $this->problem(422, 'Unprocessable Content', 'Token and new password are required.');
        }

        $ip = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        try {
            $success = $this->resetService->resetPassword($token, $password, $ip, $userAgent);
            if (!$success) {
                return $this->problem(400, 'Bad Request', 'Invalid or expired password reset token.');
            }
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Content', $e->getMessage());
        }

        return $this->json(['status' => 'password_reset_success']);
    }

    public function validateInvitation(ServerRequestInterface $request): ResponseInterface
    {
        $token = (string) $request->getAttribute('token', '');
        $invite = $this->invitationService->validateToken($token);

        if ($invite === null) {
            return $this->problem(404, 'Not Found', 'Invitation token is invalid, expired, or revoked.');
        }

        return $this->json([
            'email' => $invite['email'],
            'role_name' => $invite['role_name'],
            'expires_at' => $invite['expires_at'],
        ]);
    }

    public function acceptInvitation(ServerRequestInterface $request): ResponseInterface
    {
        $token = (string) $request->getAttribute('token', '');
        $body = $this->getBody($request);
        $name = (string) ($body['name'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($name === '' || $password === '') {
            return $this->problem(422, 'Unprocessable Content', 'Name and password are required.');
        }

        $ip = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        try {
            $userId = $this->invitationService->accept($token, $name, $password, $ip, $userAgent);
            return $this->json(['status' => 'accepted', 'user_id' => $userId]);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Content', $e->getMessage());
        }
    }

    public function verifyEmail(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $params = $request->getQueryParams();
        $token = (string) ($body['token'] ?? ($params['token'] ?? ''));

        if ($token === '') {
            return $this->problem(422, 'Unprocessable Content', 'Verification token is required.');
        }

        $ip = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        $verified = $this->verificationService->verify($token, $ip, $userAgent);
        if (!$verified) {
            return $this->problem(400, 'Bad Request', 'Invalid or expired email verification token.');
        }

        return $this->json(['status' => 'email_verified']);
    }

    public function stepUp(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $body = $this->getBody($request);
        $password = isset($body['password']) ? (string) $body['password'] : null;
        $code = isset($body['code']) ? (string) $body['code'] : null;

        $actor = $this->getActor($request);
        $user = $this->userRepository->findById($actor->id);
        if ($user === null) {
            return $this->problem(401, 'Unauthorized', 'User not found.');
        }

        $passed = false;

        // Try password
        if ($password !== null && $password !== '') {
            $passed = $this->passwordHasher->verify($password, (string) ($user['password_hash'] ?? ''));
        }

        // Try TOTP code
        if (!$passed && $code !== null && $code !== '' && !empty($user['totp_secret_enc'])) {
            $rawSecret = (string) $user['totp_secret_enc'];
            try {
                $plainSecret = ($this->crypto !== null)
                    ? $this->twoFactorService->decryptSecret($rawSecret, $this->crypto)
                    : $rawSecret;
            } catch (\Throwable) {
                $plainSecret = $rawSecret;
            }
            $lastStep = !empty($user['totp_last_step']) ? (int) $user['totp_last_step'] : null;
            try {
                $step = $this->twoFactorService->verifyCode($plainSecret, trim($code), $lastStep);
            } catch (\Throwable) {
                $step = null;
            }
            if ($step !== null) {
                $usersTable = $this->connection->prefixTable('users');
                $stmt = $this->connection->getPdo()->prepare(
                    "UPDATE `{$usersTable}` SET `totp_last_step` = ? WHERE `id` = ? AND (`totp_last_step` IS NULL OR `totp_last_step` < ?)"
                );
                $stmt->execute([$step, $user['id'], $step]);
                $passed = $stmt->rowCount() > 0;
            }
        }

        if (!$passed) {
            return $this->problem(401, 'Unauthorized', 'Invalid step-up authentication credentials.');
        }

        // Update session with mfa_passed_at = now
        /** @var array<string, mixed>|null $session */
        $session = $request->getAttribute('session');
        if ($session !== null && isset($session['id'])) {
            $table = $this->connection->prefixTable('sessions');
            $now = Clock::sql(Clock::now());
            $stmt = $this->connection->getPdo()->prepare("UPDATE `{$table}` SET `mfa_passed_at` = ? WHERE `id` = ?");
            $stmt->execute([$now, $session['id']]);
        }

        return $this->json(['status' => 'step_up_passed']);
    }

    private function getClientIp(ServerRequestInterface $request): ?string
    {
        $serverParams = $request->getServerParams();
        return isset($serverParams['REMOTE_ADDR']) && is_string($serverParams['REMOTE_ADDR'])
            ? $serverParams['REMOTE_ADDR']
            : null;
    }
}
