<?php

declare(strict_types=1);

namespace BsCms\OAuth;

use BsCms\Auth\Actor;
use BsCms\Auth\PasswordHasher;
use BsCms\Auth\SystemRoles;
use BsCms\Database\Connection;
use BsCms\Http\Controllers\BaseController;
use BsCms\Kernel\Config;
use BsCms\Security\Ulid;
use Nyholm\Psr7\Response;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * OAuthController: OAuth 2.1 authorization server endpoints for AI agents and MCP clients.
 *
 * Implements:
 * - RFC 9728 OAuth 2.0 Protected Resource Metadata
 * - RFC 8414 OAuth 2.0 Authorization Server Metadata
 * - RFC 7591 Dynamic Client Registration Protocol (with strict URI validation and configurable IP cap)
 * - RFC 7636 Proof Key for Code Exchange (PKCE S256 mandatory)
 * - Session-bound consent with CSRF and step-up confirmation
 * - Single-origin /admin validation on return_to parameters
 * - Refresh token rotation with reuse detection revoking the entire grant
 * - Database persistence (oauth_clients, oauth_grants, oauth_authorization_codes, oauth_access_tokens, oauth_refresh_tokens)
 * - Hashed token storage (SHA-256) with plaintext tokens never stored in the database
 */
final class OAuthController extends BaseController
{
    private string $stateFile;

    public function __construct(
        ?string $stateFile = null,
        private readonly ?Connection $connection = null,
        private readonly ?PasswordHasher $passwordHasher = null,
        private readonly ?Config $config = null
    ) {
        $this->stateFile = $stateFile ?? (dirname(__DIR__, 2) . '/storage/tmp/oauth_spike_state.json');
    }

    /**
     * GET /.well-known/oauth-protected-resource (RFC 9728)
     */
    public function protectedResource(ServerRequestInterface $request): ResponseInterface
    {
        $baseUrl = $this->resolveBaseUrl($request);

        return $this->json([
            'resource' => $baseUrl . '/mcp',
            'authorization_servers' => [$baseUrl],
            'bearer_methods_supported' => ['header'],
            'resource_documentation' => $baseUrl . '/api/v1/openapi.json',
        ], 200, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function optionsProtectedResource(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'Access-Control-Max-Age' => '86400',
        ]);
    }

    /**
     * GET /.well-known/oauth-authorization-server (RFC 8414)
     */
    public function authorizationServer(ServerRequestInterface $request): ResponseInterface
    {
        $baseUrl = $this->resolveBaseUrl($request);

        return $this->json([
            'issuer' => $baseUrl,
            'authorization_endpoint' => $baseUrl . '/oauth/authorize',
            'token_endpoint' => $baseUrl . '/oauth/token',
            'registration_endpoint' => $baseUrl . '/oauth/register',
            'revocation_endpoint' => $baseUrl . '/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported' => ['mcp:read', 'mcp:write', 'mcp:full'],
        ], 200, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function optionsAuthorizationServer(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'Access-Control-Max-Age' => '86400',
        ]);
    }

    /**
     * POST /oauth/register (RFC 7591 Dynamic Client Registration)
     */
    public function register(ServerRequestInterface $request): ResponseInterface
    {
        // 1. Enforce IP rate limit cap from configuration (default: 5 per hour)
        $ip = $this->resolveClientIp($request);
        $rateLimitConfig = (array) ($this->config?->get('security.oauth.registration_rate_limit', []));
        $maxRegistrations = (int) ($rateLimitConfig['limit'] ?? 5);
        $window = (int) ($rateLimitConfig['window'] ?? 3600);
        $now = time();

        if ($this->connection !== null) {
            $this->cleanupExpiredRateLimits($window);
            $count = $this->getDbIpRegistrationCount($ip, $window);
            if ($count >= $maxRegistrations) {
                return $this->oauthError(429, 'rate_limit_exceeded', "Registration limit reached for this IP address (maximum {$maxRegistrations} per hour).");
            }
        } else {
            $state = $this->loadState();
            /** @var list<int> $ipTimestamps */
            $ipTimestamps = array_values(array_filter(
                $state['ip_registrations'][$ip] ?? [],
                fn (int $ts): bool => ($now - $ts) < $window
            ));

            if (count($ipTimestamps) >= $maxRegistrations) {
                return $this->oauthError(429, 'rate_limit_exceeded', "Registration limit reached for this IP address (maximum {$maxRegistrations} per hour).");
            }
        }

        $body = (string) $request->getBody();
        $input = json_decode($body, true);
        if (!is_array($input)) {
            return $this->oauthError(400, 'invalid_request', 'Malformed JSON payload.');
        }

        $clientName = trim((string) ($input['client_name'] ?? 'AI Client'));
        /** @var list<string> $redirectUris */
        $redirectUris = is_array($input['redirect_uris'] ?? null)
            ? array_values(array_filter(array_map('strval', $input['redirect_uris']), fn ($u) => $u !== ''))
            : [];

        if (empty($redirectUris)) {
            return $this->oauthError(400, 'invalid_redirect_uri', 'At least one redirect URI is required.');
        }

        // 2. Validate redirect URIs: HTTPS or HTTP loopback only, no fragments, no pseudo-schemes
        foreach ($redirectUris as $uri) {
            if (!self::isValidRedirectUri($uri)) {
                return $this->oauthError(400, 'invalid_redirect_uri', "Invalid redirect URI '{$uri}'. Must be HTTPS or HTTP loopback (localhost/127.0.0.1/[::1]) with no fragment.");
            }
        }

        $clientId = 'bs_client_' . bin2hex(random_bytes(12));
        $clientPk = Ulid::generate();
        $nowUtc = gmdate('Y-m-d H:i:s.v');

        if ($this->connection !== null) {
            $this->recordDbIpRegistration($ip);

            $clientsTable = $this->connection->prefixTable('oauth_clients');
            $stmt = $this->connection->getPdo()->prepare("INSERT INTO `{$clientsTable}`
                (`id`, `client_id`, `name`, `redirect_uris`, `is_public`, `client_secret_hash`, `registration_method`, `metadata`, `created_at`, `revoked_at`)
                VALUES (:id, :client_id, :name, :redirect_uris, 1, NULL, 'dynamic', NULL, :created_at, NULL)");
            $stmt->execute([
                'id' => $clientPk,
                'client_id' => $clientId,
                'name' => $clientName,
                'redirect_uris' => json_encode($redirectUris, JSON_THROW_ON_ERROR),
                'created_at' => $nowUtc,
            ]);
        } else {
            /** @var array<string, mixed> $state */
            $state = $this->loadState();
            /** @var list<int> $ipTimestamps */
            $ipTimestamps = array_values(array_filter(
                $state['ip_registrations'][$ip] ?? [],
                fn (int $ts): bool => ($now - $ts) < $window
            ));
            $ipTimestamps[] = $now;
            $state['ip_registrations'][$ip] = $ipTimestamps;

            $state['clients'][$clientId] = [
                'id' => $clientPk,
                'client_id' => $clientId,
                'client_name' => $clientName,
                'redirect_uris' => $redirectUris,
                'created_at' => time(),
            ];
            $this->saveState($state);
        }

        return $this->json([
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Validate redirect URI according to OAuth 2.1 & RFC 6749:
     * - Must be absolute URL.
     * - Must not contain a fragment identifier (#).
     * - Scheme must be https, or http ONLY on loopback interfaces (localhost, 127.0.0.1, [::1]).
     * - Disallow javascript:, data:, vbscript:, file: pseudo-schemes.
     */
    public static function isValidRedirectUri(string $uri): bool
    {
        $uri = trim($uri);
        if ($uri === '' || str_contains($uri, '#')) {
            return false;
        }

        if (preg_match('/^(javascript|data|vbscript|file):/i', $uri)) {
            return false;
        }

        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if ($scheme === 'http') {
            return $host === 'localhost'
                || $host === '127.0.0.1'
                || $host === '::1'
                || $host === '[::1]';
        }

        return $scheme === 'https';
    }

    /**
     * Sanitize return_to URL to prevent open redirection.
     * Must be a same-origin path strictly beginning with /admin.
     */
    public static function sanitizeReturnTo(?string $returnTo): string
    {
        if ($returnTo === null || $returnTo === '') {
            return '/admin';
        }

        $returnTo = trim($returnTo);

        // Reject protocol-relative URLs (//evil.com), control chars, and backslashes
        if (str_starts_with($returnTo, '//') || str_contains($returnTo, '\\') || preg_match('/[\x00-\x1F\x7F]/', $returnTo)) {
            return '/admin';
        }

        // Must start with /admin or /admin/...
        if (!preg_match('#^/admin(/.*|\?.*)?$#', $returnTo)) {
            return '/admin';
        }

        return $returnTo;
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        if (isset($serverParams['REMOTE_ADDR']) && is_string($serverParams['REMOTE_ADDR']) && $serverParams['REMOTE_ADDR'] !== '') {
            return $serverParams['REMOTE_ADDR'];
        }

        return '127.0.0.1';
    }

    public function optionsRegister(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'Access-Control-Max-Age' => '86400',
        ]);
    }

    /**
     * GET /oauth/authorize (Consent screen or redirect)
     */
    public function authorizeGet(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        return $this->processAuthorize($request, $params, false);
    }

    /**
     * POST /oauth/authorize (Consent approval submission)
     */
    public function authorizePost(ServerRequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();
        $params = [];
        parse_str($body, $params);
        if (empty($params)) {
            $parsed = $request->getParsedBody();
            if (is_array($parsed)) {
                $params = $parsed;
            }
        }
        return $this->processAuthorize($request, $params, true);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function processAuthorize(ServerRequestInterface $request, array $params, bool $isPost): ResponseInterface
    {
        // 1. Mandatory authenticated user session check
        $session = $request->getAttribute('session');
        $actor = $request->getAttribute('actor');

        $isUser = ($actor instanceof Actor && $actor->isUser())
            || (is_array($session) && !empty($session['user_id']));

        if (!$isUser) {
            if ($isPost) {
                return $this->oauthError(401, 'unauthorized', 'Authenticated user session required to authorize agent access.');
            }

            $accept = $request->getHeaderLine('Accept');
            if (str_contains($accept, 'application/json')) {
                return $this->oauthError(401, 'unauthorized', 'Authenticated user session required to authorize agent access. Please log in first.');
            }

            // Browser request: redirect to /admin/login with sanitized return_to parameter
            $currentUri = $request->getUri();
            $pathAndQuery = $currentUri->getPath();
            if ($currentUri->getQuery() !== '') {
                $pathAndQuery .= '?' . $currentUri->getQuery();
            }

            // The login page honours ?return_to=<path> and must ensure it is safe
            $loginUrl = '/admin/login?return_to=' . urlencode($pathAndQuery);
            return new Response(302, [
                'Location' => $loginUrl,
                'Cache-Control' => 'no-store',
            ]);
        }

        $userId = $actor instanceof Actor ? $actor->id : (string) ($session['user_id'] ?? '');
        $userName = $actor instanceof Actor ? $actor->label : (string) ($session['name'] ?? 'User');
        /** @var list<string> $userRoles */
        $userRoles = ($actor instanceof Actor && !empty($actor->roleIds)) ? $actor->roleIds : ['editor'];
        $csrfSecret = is_array($session) ? (string) ($session['csrf_secret'] ?? '') : '';

        $clientId = (string) ($params['client_id'] ?? '');
        $redirectUri = (string) ($params['redirect_uri'] ?? '');
        $codeChallenge = (string) ($params['code_challenge'] ?? '');
        $codeChallengeMethod = (string) ($params['code_challenge_method'] ?? '');
        $state = (string) ($params['state'] ?? '');
        $responseType = (string) ($params['response_type'] ?? '');
        $aiMode = (string) ($params['ai_mode'] ?? 'draft_only');

        if ($responseType !== 'code') {
            return $this->oauthError(400, 'unsupported_response_type', 'Only response_type=code is supported.');
        }

        if ($codeChallenge === '' || $codeChallengeMethod !== 'S256') {
            return $this->oauthError(400, 'invalid_request', 'PKCE with S256 code_challenge_method is mandatory.');
        }

        // Fetch client details
        $client = $this->getClientByClientId($clientId);
        if ($client === null) {
            return $this->oauthError(400, 'unauthorized_client', 'Unknown client_id.');
        }

        if (!in_array($redirectUri, $client['redirect_uris'], true)) {
            return $this->oauthError(400, 'invalid_redirect_uri', 'Redirect URI does not match registered URIs.');
        }

        if (!self::isValidRedirectUri($redirectUri)) {
            return $this->oauthError(400, 'invalid_redirect_uri', 'Redirect URI is invalid.');
        }

        // 2. Handle POST authorization consent submission
        if ($isPost) {
            // A. CSRF Token verification
            $submittedCsrf = (string) ($params['csrf_token'] ?? $request->getHeaderLine('X-CSRF-Token'));
            if ($csrfSecret === '' || $submittedCsrf === '' || !hash_equals($csrfSecret, $submittedCsrf)) {
                return $this->oauthError(403, 'access_denied', 'Invalid or missing CSRF token.');
            }

            // B. Step-up re-authentication
            $password = (string) ($params['password'] ?? '');
            $stepUpPassed = false;

            if ($this->connection !== null && $this->passwordHasher !== null) {
                $usersTable = $this->connection->prefixTable('users');
                $stmt = $this->connection->getPdo()->prepare("SELECT `password_hash` FROM `{$usersTable}` WHERE `id` = :id LIMIT 1");
                $stmt->execute(['id' => $userId]);
                $hash = $stmt->fetchColumn();

                if (is_string($hash) && $password !== '' && $this->passwordHasher->verify($password, $hash)) {
                    $stepUpPassed = true;
                }
            } elseif ($password !== '' || isset($session['mfa_passed_at'])) {
                $stepUpPassed = true;
            }

            if (!$stepUpPassed) {
                return $this->oauthError(403, 'access_denied', 'Step-up authentication failed: invalid password confirmation.');
            }

            // C. Decision evaluation
            $decision = (string) ($params['decision'] ?? ($params['approved'] ?? ''));
            if ($decision === 'deny' || $decision === '0') {
                $sep = str_contains($redirectUri, '?') ? '&' : '?';
                $location = $redirectUri . $sep . 'error=access_denied';
                if ($state !== '') {
                    $location .= '&state=' . urlencode($state);
                }
                return new Response(302, [
                    'Location' => $location,
                    'Cache-Control' => 'no-store',
                ]);
            }

            if ($decision !== 'allow' && $decision !== '1') {
                return $this->oauthError(400, 'invalid_request', "Invalid decision '{$decision}'. Must be allow or deny.");
            }
        } else {
            // Render HTML consent screen
            $html = $this->renderConsentScreen([
                'client_id' => $clientId,
                'client_name' => $client['name'],
                'redirect_uri' => $redirectUri,
                'code_challenge' => $codeChallenge,
                'state' => $state,
                'user_name' => $userName,
                'user_id' => $userId,
                'csrf_token' => $csrfSecret,
            ]);

            return new Response(200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
            ], $html);
        }

        // Issue authorization code strictly bound to user and roles
        $plainCode = 'bs_code_' . bin2hex(random_bytes(16));
        $codeHash = hash('sha256', $plainCode);

        // Resolve primary role ID for oauth_grants
        $roleId = $this->resolvePrimaryRoleId($userId, $userRoles);

        if ($this->connection !== null) {
            $grantPk = Ulid::generate();
            $grantsTable = $this->connection->prefixTable('oauth_grants');
            $authCodesTable = $this->connection->prefixTable('oauth_authorization_codes');
            $nowUtc = gmdate('Y-m-d H:i:s.v');
            $expiresUtc = gmdate('Y-m-d H:i:s.v', time() + 300); // 5 min expiry

            // Create or reuse active grant
            $clientDbId = $client['id'];
            $grantStmt = $this->connection->getPdo()->prepare("INSERT INTO `{$grantsTable}`
                (`id`, `user_id`, `client_id`, `role_id`, `scopes`, `ai_mode`, `created_at`, `last_used_at`, `revoked_at`)
                VALUES (:id, :user_id, :client_id, :role_id, :scopes, :ai_mode, :created_at, NULL, NULL)");
            $grantStmt->execute([
                'id' => $grantPk,
                'user_id' => $userId,
                'client_id' => $clientDbId,
                'role_id' => $roleId,
                'scopes' => json_encode(['mcp:read', 'mcp:write'], JSON_THROW_ON_ERROR),
                'ai_mode' => $aiMode,
                'created_at' => $nowUtc,
            ]);

            // Save authorization code with HASHED code
            $codeStmt = $this->connection->getPdo()->prepare("INSERT INTO `{$authCodesTable}`
                (`code_hash`, `grant_id`, `code_challenge`, `code_challenge_method`, `redirect_uri`, `resource`, `expires_at`, `used_at`)
                VALUES (:code_hash, :grant_id, :code_challenge, 'S256', :redirect_uri, NULL, :expires_at, NULL)");
            $codeStmt->execute([
                'code_hash' => $codeHash,
                'grant_id' => $grantPk,
                'code_challenge' => $codeChallenge,
                'redirect_uri' => $redirectUri,
                'expires_at' => $expiresUtc,
            ]);
        } else {
            $grantId = 'bs_grant_' . bin2hex(random_bytes(12));
            $stateData = $this->loadState();
            $stateData['auth_codes'][$plainCode] = [
                'code' => $plainCode,
                'code_hash' => $codeHash,
                'grant_id' => $grantId,
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'code_challenge' => $codeChallenge,
                'user_id' => $userId,
                'user_name' => $userName,
                'user_roles' => $userRoles,
                'role_id' => $roleId,
                'ai_mode' => $aiMode,
                'scopes' => ['mcp:read', 'mcp:write'],
                'expires_at' => time() + 300,
            ];
            $this->saveState($stateData);
        }

        $sep = str_contains($redirectUri, '?') ? '&' : '?';
        $location = $redirectUri . $sep . 'code=' . urlencode($plainCode);
        if ($state !== '') {
            $location .= '&state=' . urlencode($state);
        }

        return new Response(302, [
            'Location' => $location,
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * POST /oauth/token (Code exchange with PKCE & refresh rotation with reuse detection)
     */
    public function token(ServerRequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();
        $contentType = $request->getHeaderLine('Content-Type');
        $data = [];

        if (str_contains($contentType, 'application/json')) {
            $data = json_decode($body, true) ?: [];
        } else {
            parse_str($body, $data);
            if (empty($data)) {
                $parsed = $request->getParsedBody();
                if (is_array($parsed)) {
                    $data = $parsed;
                }
            }
        }

        $grantType = (string) ($data['grant_type'] ?? '');

        if ($grantType === 'authorization_code') {
            $plainCode = (string) ($data['code'] ?? '');
            $codeVerifier = (string) ($data['code_verifier'] ?? '');

            if ($plainCode === '') {
                return $this->oauthError(400, 'invalid_grant', 'Authorization code is missing.');
            }

            $codeHash = hash('sha256', $plainCode);

            if ($this->connection !== null) {
                $authCodesTable = $this->connection->prefixTable('oauth_authorization_codes');
                $grantsTable = $this->connection->prefixTable('oauth_grants');
                $clientsTable = $this->connection->prefixTable('oauth_clients');

                $stmt = $this->connection->getPdo()->prepare("SELECT c.*, g.id AS grant_id, g.client_id AS grant_client_id, g.user_id, g.role_id, g.scopes, g.ai_mode, g.revoked_at AS grant_revoked_at, cl.client_id AS client_string_id
                    FROM `{$authCodesTable}` c
                    JOIN `{$grantsTable}` g ON c.grant_id = g.id
                    JOIN `{$clientsTable}` cl ON g.client_id = cl.id
                    WHERE c.code_hash = :code_hash LIMIT 1");
                $stmt->execute(['code_hash' => $codeHash]);
                $authCode = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!is_array($authCode) || $authCode['used_at'] !== null) {
                    return $this->oauthError(400, 'invalid_grant', 'Authorization code is invalid or has already been used.');
                }

                // Mark code as used immediately (single-use)
                $nowUtc = gmdate('Y-m-d H:i:s.v');
                $updateStmt = $this->connection->getPdo()->prepare("UPDATE `{$authCodesTable}` SET `used_at` = :now WHERE `code_hash` = :code_hash");
                $updateStmt->execute(['now' => $nowUtc, 'code_hash' => $codeHash]);

                if (strtotime((string) $authCode['expires_at']) < time()) {
                    return $this->oauthError(400, 'invalid_grant', 'Authorization code has expired.');
                }

                if ($authCode['grant_revoked_at'] !== null) {
                    return $this->oauthError(400, 'invalid_grant', 'Authorization grant has been revoked.');
                }

                // Verify PKCE S256: BASE64URL(SHA256(verifier)) == challenge
                $calculatedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
                if (!hash_equals((string) $authCode['code_challenge'], $calculatedChallenge)) {
                    return $this->oauthError(400, 'invalid_grant', 'PKCE verification failed: code_verifier does not match code_challenge.');
                }

                $grantId = (string) $authCode['grant_id'];
                $plainAccessToken = 'bs_at_' . bin2hex(random_bytes(24));
                $plainRefreshToken = 'bs_rt_' . bin2hex(random_bytes(24));

                $atHash = hash('sha256', $plainAccessToken);
                $rtHash = hash('sha256', $plainRefreshToken);

                $atExpiresUtc = gmdate('Y-m-d H:i:s.v', time() + 3600);
                $rtExpiresUtc = gmdate('Y-m-d H:i:s.v', time() + (30 * 86400));

                $atTable = $this->connection->prefixTable('oauth_access_tokens');
                $rtTable = $this->connection->prefixTable('oauth_refresh_tokens');

                $insertAt = $this->connection->getPdo()->prepare("INSERT INTO `{$atTable}`
                    (`token_hash`, `grant_id`, `expires_at`, `revoked_at`)
                    VALUES (:token_hash, :grant_id, :expires_at, NULL)");
                $insertAt->execute([
                    'token_hash' => $atHash,
                    'grant_id' => $grantId,
                    'expires_at' => $atExpiresUtc,
                ]);

                $insertRt = $this->connection->getPdo()->prepare("INSERT INTO `{$rtTable}`
                    (`token_hash`, `access_token_hash`, `grant_id`, `expires_at`, `rotated_at`, `revoked_at`)
                    VALUES (:token_hash, :at_hash, :grant_id, :expires_at, NULL, NULL)");
                $insertRt->execute([
                    'token_hash' => $rtHash,
                    'at_hash' => $atHash,
                    'grant_id' => $grantId,
                    'expires_at' => $rtExpiresUtc,
                ]);

                // Update last_used_at on grant
                $updGrant = $this->connection->getPdo()->prepare("UPDATE `{$grantsTable}` SET `last_used_at` = :now WHERE `id` = :id");
                $updGrant->execute(['now' => $nowUtc, 'id' => $grantId]);

                return $this->json([
                    'access_token' => $plainAccessToken,
                    'token_type' => 'Bearer',
                    'expires_in' => 3600,
                    'refresh_token' => $plainRefreshToken,
                    'scope' => 'mcp:read mcp:write',
                ], 200, [
                    'Access-Control-Allow-Origin' => '*',
                    'Cache-Control' => 'no-store',
                ]);
            }

            // File-based fallback for isolated unit testing
            $state = $this->loadState();
            if (!isset($state['auth_codes'][$plainCode])) {
                return $this->oauthError(400, 'invalid_grant', 'Authorization code is invalid or expired.');
            }

            $authCode = $state['auth_codes'][$plainCode];
            unset($state['auth_codes'][$plainCode]); // Strictly single-use

            if (time() > $authCode['expires_at']) {
                $this->saveState($state);
                return $this->oauthError(400, 'invalid_grant', 'Authorization code has expired.');
            }

            // Verify PKCE S256: BASE64URL(SHA256(verifier)) == challenge
            $calculatedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            if (!hash_equals($authCode['code_challenge'], $calculatedChallenge)) {
                $this->saveState($state);
                return $this->oauthError(400, 'invalid_grant', 'PKCE verification failed: code_verifier does not match code_challenge.');
            }

            $grantId = $authCode['grant_id'];
            $plainAccessToken = 'bs_at_' . bin2hex(random_bytes(24));
            $plainRefreshToken = 'bs_rt_' . bin2hex(random_bytes(24));
            $atHash = hash('sha256', $plainAccessToken);
            $rtHash = hash('sha256', $plainRefreshToken);

            $state['grants'][$grantId] = [
                'grant_id' => $grantId,
                'client_id' => $authCode['client_id'],
                'user_id' => $authCode['user_id'] ?? null,
                'user_name' => $authCode['user_name'] ?? null,
                'user_roles' => $authCode['user_roles'] ?? [],
                'role_id' => $authCode['role_id'] ?? null,
                'ai_mode' => $authCode['ai_mode'],
                'scopes' => $authCode['scopes'] ?? ['mcp:read', 'mcp:write'],
                'status' => 'active',
                'created_at' => time(),
            ];

            // Store by token_hash
            $state['access_tokens'][$atHash] = [
                'token_hash' => $atHash,
                'grant_id' => $grantId,
                'client_id' => $authCode['client_id'],
                'user_id' => $authCode['user_id'] ?? null,
                'user_name' => $authCode['user_name'] ?? null,
                'user_roles' => $authCode['user_roles'] ?? [],
                'role_id' => $authCode['role_id'] ?? null,
                'ai_mode' => $authCode['ai_mode'],
                'scopes' => $authCode['scopes'] ?? ['mcp:read', 'mcp:write'],
                'expires_at' => time() + 3600,
            ];

            $state['refresh_tokens'][$rtHash] = [
                'token_hash' => $rtHash,
                'grant_id' => $grantId,
                'client_id' => $authCode['client_id'],
                'status' => 'active',
                'expires_at' => time() + (30 * 86400),
            ];

            $this->saveState($state);

            return $this->json([
                'access_token' => $plainAccessToken,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => $plainRefreshToken,
                'scope' => 'mcp:read mcp:write',
            ], 200, [
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'no-store',
            ]);
        }

        if ($grantType === 'refresh_token') {
            $plainRt = (string) ($data['refresh_token'] ?? '');
            if ($plainRt === '') {
                return $this->oauthError(400, 'invalid_grant', 'Refresh token is missing.');
            }

            $rtHash = hash('sha256', $plainRt);

            if ($this->connection !== null) {
                $rtTable = $this->connection->prefixTable('oauth_refresh_tokens');
                $grantsTable = $this->connection->prefixTable('oauth_grants');
                $nowUtc = gmdate('Y-m-d H:i:s.v');

                $stmt = $this->connection->getPdo()->prepare("SELECT rt.*, g.id AS grant_id, g.revoked_at AS grant_revoked_at
                    FROM `{$rtTable}` rt
                    JOIN `{$grantsTable}` g ON rt.grant_id = g.id
                    WHERE rt.token_hash = :hash LIMIT 1");
                $stmt->execute(['hash' => $rtHash]);
                $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!is_array($tokenRow)) {
                    return $this->oauthError(400, 'invalid_grant', 'Refresh token not found.');
                }

                $grantId = (string) $tokenRow['grant_id'];

                // 1. REUSE DETECTION: If token was already rotated or revoked, revoke entire grant!
                if ($tokenRow['rotated_at'] !== null || $tokenRow['revoked_at'] !== null) {
                    $this->dbRevokeEntireGrant($grantId);
                    return $this->oauthError(400, 'invalid_grant', 'Refresh token reuse detected: the entire authorization grant has been revoked.');
                }

                if ($tokenRow['grant_revoked_at'] !== null) {
                    return $this->oauthError(400, 'invalid_grant', 'Grant has been revoked.');
                }

                if (strtotime((string) $tokenRow['expires_at']) < time()) {
                    return $this->oauthError(400, 'invalid_grant', 'Refresh token has expired.');
                }

                // Mark current refresh token as rotated
                $updRt = $this->connection->getPdo()->prepare("UPDATE `{$rtTable}` SET `rotated_at` = :now WHERE `token_hash` = :hash");
                $updRt->execute(['now' => $nowUtc, 'hash' => $rtHash]);

                // Issue new pair
                $plainNewAt = 'bs_at_' . bin2hex(random_bytes(24));
                $plainNewRt = 'bs_rt_' . bin2hex(random_bytes(24));
                $newAtHash = hash('sha256', $plainNewAt);
                $newRtHash = hash('sha256', $plainNewRt);

                $atTable = $this->connection->prefixTable('oauth_access_tokens');
                $atExpiresUtc = gmdate('Y-m-d H:i:s.v', time() + 3600);
                $rtExpiresUtc = gmdate('Y-m-d H:i:s.v', time() + (30 * 86400));

                $insertAt = $this->connection->getPdo()->prepare("INSERT INTO `{$atTable}`
                    (`token_hash`, `grant_id`, `expires_at`, `revoked_at`)
                    VALUES (:token_hash, :grant_id, :expires_at, NULL)");
                $insertAt->execute([
                    'token_hash' => $newAtHash,
                    'grant_id' => $grantId,
                    'expires_at' => $atExpiresUtc,
                ]);

                $insertRt = $this->connection->getPdo()->prepare("INSERT INTO `{$rtTable}`
                    (`token_hash`, `access_token_hash`, `grant_id`, `expires_at`, `rotated_at`, `revoked_at`)
                    VALUES (:token_hash, :at_hash, :grant_id, :expires_at, NULL, NULL)");
                $insertRt->execute([
                    'token_hash' => $newRtHash,
                    'at_hash' => $newAtHash,
                    'grant_id' => $grantId,
                    'expires_at' => $rtExpiresUtc,
                ]);

                return $this->json([
                    'access_token' => $plainNewAt,
                    'token_type' => 'Bearer',
                    'expires_in' => 3600,
                    'refresh_token' => $plainNewRt,
                    'scope' => 'mcp:read mcp:write',
                ], 200, [
                    'Access-Control-Allow-Origin' => '*',
                    'Cache-Control' => 'no-store',
                ]);
            }

            // File-based fallback
            $state = $this->loadState();

            if (isset($state['revoked_refresh_tokens'][$rtHash])) {
                $revokedMeta = $state['revoked_refresh_tokens'][$rtHash];
                $grantId = $revokedMeta['grant_id'];

                $this->revokeEntireGrant($state, $grantId);
                $this->saveState($state);

                return $this->oauthError(400, 'invalid_grant', 'Refresh token reuse detected: the entire authorization grant has been revoked.');
            }

            if (!isset($state['refresh_tokens'][$rtHash])) {
                return $this->oauthError(400, 'invalid_grant', 'Refresh token not found.');
            }

            $currentRt = $state['refresh_tokens'][$rtHash];
            $grantId = $currentRt['grant_id'];

            if (!isset($state['grants'][$grantId]) || $state['grants'][$grantId]['status'] !== 'active') {
                return $this->oauthError(400, 'invalid_grant', 'Grant has been revoked.');
            }

            unset($state['refresh_tokens'][$rtHash]);
            $state['revoked_refresh_tokens'][$rtHash] = [
                'token_hash' => $rtHash,
                'grant_id' => $grantId,
                'revoked_at' => time(),
            ];

            $plainNewAt = 'bs_at_' . bin2hex(random_bytes(24));
            $plainNewRt = 'bs_rt_' . bin2hex(random_bytes(24));
            $newAtHash = hash('sha256', $plainNewAt);
            $newRtHash = hash('sha256', $plainNewRt);

            $grant = $state['grants'][$grantId];

            $state['access_tokens'][$newAtHash] = [
                'token_hash' => $newAtHash,
                'grant_id' => $grantId,
                'client_id' => $grant['client_id'],
                'user_id' => $grant['user_id'] ?? null,
                'user_name' => $grant['user_name'] ?? null,
                'user_roles' => $grant['user_roles'] ?? [],
                'role_id' => $grant['role_id'] ?? null,
                'ai_mode' => $grant['ai_mode'],
                'scopes' => $grant['scopes'] ?? ['mcp:read', 'mcp:write'],
                'expires_at' => time() + 3600,
            ];

            $state['refresh_tokens'][$newRtHash] = [
                'token_hash' => $newRtHash,
                'grant_id' => $grantId,
                'client_id' => $grant['client_id'],
                'status' => 'active',
                'expires_at' => time() + (30 * 86400),
            ];

            $this->saveState($state);

            return $this->json([
                'access_token' => $plainNewAt,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => $plainNewRt,
                'scope' => 'mcp:read mcp:write',
            ], 200, [
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'no-store',
            ]);
        }

        return $this->oauthError(400, 'unsupported_grant_type', 'Grant type must be authorization_code or refresh_token.');
    }

    public function optionsToken(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'Access-Control-Max-Age' => '86400',
        ]);
    }

    /**
     * POST /oauth/revoke (RFC 7009 Token Revocation)
     */
    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();
        parse_str($body, $data);
        $token = isset($data['token']) && is_string($data['token']) ? trim($data['token']) : '';

        if ($token !== '') {
            $tokenHash = hash('sha256', $token);
            $nowUtc = gmdate('Y-m-d H:i:s.v');

            if ($this->connection !== null) {
                $atTable = $this->connection->prefixTable('oauth_access_tokens');
                $rtTable = $this->connection->prefixTable('oauth_refresh_tokens');

                // Revoke access token
                $stmtAt = $this->connection->getPdo()->prepare("UPDATE `{$atTable}` SET `revoked_at` = :now WHERE `token_hash` = :hash");
                $stmtAt->execute(['now' => $nowUtc, 'hash' => $tokenHash]);

                // If refresh token revoked, revoke the whole grant
                $stmtRt = $this->connection->getPdo()->prepare("SELECT `grant_id` FROM `{$rtTable}` WHERE `token_hash` = :hash LIMIT 1");
                $stmtRt->execute(['hash' => $tokenHash]);
                $grantId = $stmtRt->fetchColumn();

                if (is_string($grantId)) {
                    $this->dbRevokeEntireGrant($grantId);
                }
            } else {
                $state = $this->loadState();
                if (isset($state['access_tokens'][$tokenHash])) {
                    unset($state['access_tokens'][$tokenHash]);
                }
                if (isset($state['refresh_tokens'][$tokenHash])) {
                    $grantId = $state['refresh_tokens'][$tokenHash]['grant_id'];
                    $this->revokeEntireGrant($state, $grantId);
                }
                $this->saveState($state);
            }
        }

        return $this->json(['status' => 'revoked'], 200, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Verify an access token and return token metadata.
     *
     * @return array{valid: bool, client_id?: string, user_id?: string|int|null, user_name?: string|null, user_roles?: list<string>, role_ids?: list<string>, ai_mode?: string, scopes?: list<string>}
     */
    public function verifyAccessToken(string $token): array
    {
        if ($token === '') {
            return ['valid' => false];
        }

        $tokenHash = hash('sha256', $token);

        if ($this->connection !== null) {
            $atTable = $this->connection->prefixTable('oauth_access_tokens');
            $grantsTable = $this->connection->prefixTable('oauth_grants');
            $clientsTable = $this->connection->prefixTable('oauth_clients');
            $usersTable = $this->connection->prefixTable('users');
            $rolesTable = $this->connection->prefixTable('roles');

            $stmt = $this->connection->getPdo()->prepare("SELECT
                    at.`expires_at`, at.`revoked_at` AS token_revoked_at,
                    g.`id` AS grant_id, g.`scopes`, g.`ai_mode`, g.`revoked_at` AS grant_revoked_at,
                    cl.`client_id` AS client_string_id,
                    u.`id` AS user_id, u.`name` AS user_name,
                    r.`handle` AS role_handle, r.`id` AS role_id
                FROM `{$atTable}` at
                JOIN `{$grantsTable}` g ON at.`grant_id` = g.`id`
                JOIN `{$clientsTable}` cl ON g.`client_id` = cl.`id`
                LEFT JOIN `{$usersTable}` u ON g.`user_id` = u.`id`
                LEFT JOIN `{$rolesTable}` r ON g.`role_id` = r.`id`
                WHERE at.`token_hash` = :hash LIMIT 1");
            $stmt->execute(['hash' => $tokenHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (is_array($row)) {
                if ($row['token_revoked_at'] === null && $row['grant_revoked_at'] === null) {
                    if (strtotime((string) $row['expires_at']) >= time()) {
                        $roleHandle = (string) ($row['role_handle'] ?? 'viewer');
                        $roleId = (string) ($row['role_id'] ?? $roleHandle);
                        $roles = [$roleHandle];
                        $rawScopes = json_decode((string) $row['scopes'], true);
                        /** @var list<string> $scopes */
                        $scopes = is_array($rawScopes) ? array_values(array_map('strval', $rawScopes)) : ['mcp:read', 'mcp:write'];

                        return [
                            'valid' => true,
                            'client_id' => (string) $row['client_string_id'],
                            'user_id' => (string) ($row['user_id'] ?? ''),
                            'user_name' => (string) ($row['user_name'] ?? 'User'),
                            'user_roles' => $roles,
                            'role_ids' => [$roleId],
                            'ai_mode' => (string) ($row['ai_mode'] ?? 'draft_only'),
                            'scopes' => $scopes,
                        ];
                    }
                }
            }

            return ['valid' => false];
        }

        // File-based state fallback
        $state = $this->loadState();
        if (isset($state['access_tokens'][$tokenHash])) {
            $at = $state['access_tokens'][$tokenHash];
            if (time() <= $at['expires_at']) {
                $grantId = $at['grant_id'];
                if (isset($state['grants'][$grantId]) && $state['grants'][$grantId]['status'] === 'active') {
                    /** @var list<string> $roles */
                    $roles = $at['user_roles'] ?? $state['grants'][$grantId]['user_roles'] ?? [];
                    /** @var list<string> $scopes */
                    $scopes = $at['scopes'] ?? $state['grants'][$grantId]['scopes'] ?? ['mcp:read', 'mcp:write'];
                    return [
                        'valid' => true,
                        'client_id' => $at['client_id'],
                        'user_id' => $at['user_id'] ?? $state['grants'][$grantId]['user_id'] ?? null,
                        'user_name' => $at['user_name'] ?? $state['grants'][$grantId]['user_name'] ?? null,
                        'user_roles' => $roles,
                        'role_ids' => $roles,
                        'ai_mode' => $at['ai_mode'],
                        'scopes' => $scopes,
                    ];
                }
            }
        }

        return ['valid' => false];
    }

    /**
     * @return array{id: string, client_id: string, name: string, redirect_uris: list<string>}|null
     */
    private function getClientByClientId(string $clientId): ?array
    {
        if ($this->connection !== null) {
            $clientsTable = $this->connection->prefixTable('oauth_clients');
            $stmt = $this->connection->getPdo()->prepare("SELECT `id`, `client_id`, `name`, `redirect_uris` FROM `{$clientsTable}` WHERE `client_id` = :client_id AND `revoked_at` IS NULL LIMIT 1");
            $stmt->execute(['client_id' => $clientId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $rawUris = json_decode((string) $row['redirect_uris'], true);
                /** @var list<string> $uris */
                $uris = is_array($rawUris) ? array_values(array_map('strval', $rawUris)) : [];
                return [
                    'id' => (string) $row['id'],
                    'client_id' => (string) $row['client_id'],
                    'name' => (string) $row['name'],
                    'redirect_uris' => $uris,
                ];
            }
            return null;
        }

        $stateData = $this->loadState();
        if (isset($stateData['clients'][$clientId])) {
            $c = $stateData['clients'][$clientId];
            return [
                'id' => (string) ($c['id'] ?? $clientId),
                'client_id' => (string) $c['client_id'],
                'name' => (string) ($c['client_name'] ?? $c['name'] ?? 'AI Client'),
                'redirect_uris' => $c['redirect_uris'],
            ];
        }

        return null;
    }


    /**
     * @param list<string> $userRoles
     */
    private function resolvePrimaryRoleId(string $userId, array $userRoles): string
    {
        if ($this->connection !== null) {
            $rolesTable = $this->connection->prefixTable('roles');
            $userRolesTable = $this->connection->prefixTable('user_roles');

            // Find first role ID matching user roles
            $stmt = $this->connection->getPdo()->prepare("SELECT r.`id` FROM `{$userRolesTable}` ur JOIN `{$rolesTable}` r ON ur.`role_id` = r.`id` WHERE ur.`user_id` = :user_id LIMIT 1");
            $stmt->execute(['user_id' => $userId]);
            $roleId = $stmt->fetchColumn();

            if (is_string($roleId) && $roleId !== '') {
                return $roleId;
            }

            // Fallback by role handle if direct mapping not in user_roles table
            if (!empty($userRoles)) {
                $firstHandle = $userRoles[0];
                $handleStmt = $this->connection->getPdo()->prepare("SELECT `id` FROM `{$rolesTable}` WHERE `handle` = :handle LIMIT 1");
                $handleStmt->execute(['handle' => $firstHandle]);
                $foundId = $handleStmt->fetchColumn();
                if (is_string($foundId) && $foundId !== '') {
                    return $foundId;
                }
            }

            return SystemRoles::ROLE_EDITOR;
        }

        return SystemRoles::ROLE_EDITOR;
    }

    private function dbRevokeEntireGrant(string $grantId): void
    {
        if ($this->connection === null) {
            return;
        }

        $nowUtc = gmdate('Y-m-d H:i:s.v');
        $grantsTable = $this->connection->prefixTable('oauth_grants');
        $atTable = $this->connection->prefixTable('oauth_access_tokens');
        $rtTable = $this->connection->prefixTable('oauth_refresh_tokens');

        $pdo = $this->connection->getPdo();
        $stmtG = $pdo->prepare("UPDATE `{$grantsTable}` SET `revoked_at` = :now WHERE `id` = :id");
        $stmtG->execute(['now' => $nowUtc, 'id' => $grantId]);

        $stmtA = $pdo->prepare("UPDATE `{$atTable}` SET `revoked_at` = :now WHERE `grant_id` = :id");
        $stmtA->execute(['now' => $nowUtc, 'id' => $grantId]);

        $stmtR = $pdo->prepare("UPDATE `{$rtTable}` SET `revoked_at` = :now WHERE `grant_id` = :id");
        $stmtR->execute(['now' => $nowUtc, 'id' => $grantId]);
    }

    /**
     * Rate limiting helper using rate_limits DB table.
     */
    private function getDbIpRegistrationCount(string $ip, int $window): int
    {
        if ($this->connection === null) {
            return 0;
        }

        $table = $this->connection->prefixTable('rate_limits');
        $threshold = gmdate('Y-m-d H:i:s', time() - $window);

        $stmt = $this->connection->getPdo()->prepare("SELECT SUM(`hits`) FROM `{$table}` WHERE `bucket` = :bucket AND `window_start` >= :threshold");
        $stmt->execute([
            'bucket' => 'oauth_reg:' . $ip,
            'threshold' => $threshold,
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function recordDbIpRegistration(string $ip): void
    {
        if ($this->connection === null) {
            return;
        }

        $table = $this->connection->prefixTable('rate_limits');
        // Align to minute bucket
        $windowStart = gmdate('Y-m-d H:i:00');
        $bucket = 'oauth_reg:' . $ip;

        $stmt = $this->connection->getPdo()->prepare("INSERT INTO `{$table}` (`bucket`, `window_start`, `hits`)
            VALUES (:bucket, :window_start, 1)
            ON DUPLICATE KEY UPDATE `hits` = `hits` + 1");
        $stmt->execute([
            'bucket' => $bucket,
            'window_start' => $windowStart,
        ]);
    }

    private function cleanupExpiredRateLimits(int $window): void
    {
        if ($this->connection === null) {
            return;
        }

        $table = $this->connection->prefixTable('rate_limits');
        $threshold = gmdate('Y-m-d H:i:s', time() - ($window * 2));
        $stmt = $this->connection->getPdo()->prepare("DELETE FROM `{$table}` WHERE `window_start` < :threshold");
        $stmt->execute(['threshold' => $threshold]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function revokeEntireGrant(array &$state, string $grantId): void
    {
        if (isset($state['grants'][$grantId])) {
            $state['grants'][$grantId]['status'] = 'revoked';
        }

        foreach ($state['access_tokens'] as $tok => $data) {
            if ($data['grant_id'] === $grantId) {
                unset($state['access_tokens'][$tok]);
            }
        }

        foreach ($state['refresh_tokens'] as $tok => $data) {
            if ($data['grant_id'] === $grantId) {
                unset($state['refresh_tokens'][$tok]);
                $state['revoked_refresh_tokens'][$tok] = [
                    'token_hash' => $tok,
                    'grant_id' => $grantId,
                    'revoked_at' => time(),
                ];
            }
        }
    }

    /**
     * Render the clean, structured consent screen template.
     *
     * @param array{client_id: string, client_name: string, redirect_uri: string, code_challenge: string, state: string, user_name: string, user_id: string, csrf_token: string} $viewData
     */
    private function renderConsentScreen(array $viewData): string
    {
        $safeClientId = htmlspecialchars($viewData['client_id'], ENT_QUOTES, 'UTF-8');
        $safeClientName = htmlspecialchars($viewData['client_name'], ENT_QUOTES, 'UTF-8');
        $safeRedirect = htmlspecialchars($viewData['redirect_uri'], ENT_QUOTES, 'UTF-8');
        $safeChallenge = htmlspecialchars($viewData['code_challenge'], ENT_QUOTES, 'UTF-8');
        $safeState = htmlspecialchars($viewData['state'], ENT_QUOTES, 'UTF-8');
        $safeUserName = htmlspecialchars($viewData['user_name'], ENT_QUOTES, 'UTF-8');
        $safeUserId = htmlspecialchars($viewData['user_id'], ENT_QUOTES, 'UTF-8');
        $safeCsrf = htmlspecialchars($viewData['csrf_token'], ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authorize AI Connector - BS CMS</title>
    <style>
        :root {
            --bs-bg: #0f172a;
            --bs-card-bg: #1e293b;
            --bs-border: #334155;
            --bs-text: #f8fafc;
            --bs-muted: #94a3b8;
            --bs-primary: #0284c7;
            --bs-primary-hover: #0369a1;
            --bs-secondary: #475569;
            --bs-secondary-hover: #334155;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bs-bg); color: var(--bs-text); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .card { background: var(--bs-card-bg); border: 1px solid var(--bs-border); border-radius: 12px; max-width: 480px; width: 100%; padding: 32px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5); }
        h2 { margin-top: 0; font-size: 1.5rem; color: #38bdf8; }
        p { color: var(--bs-muted); font-size: 0.95rem; line-height: 1.5; }
        .highlight { color: #f1f5f9; font-weight: 600; }
        .badge { display: inline-block; padding: 4px 10px; background: var(--bs-primary); color: white; border-radius: 6px; font-size: 0.8rem; font-weight: bold; }
        .user-box { background: var(--bs-bg); padding: 12px; border-radius: 8px; margin: 16px 0; border: 1px solid var(--bs-border); font-size: 0.9rem; }
        label { display: block; margin: 16px 0 8px; font-weight: 500; font-size: 0.9rem; }
        select, input[type="password"] { width: 100%; padding: 10px; background: var(--bs-bg); border: 1px solid #475569; color: var(--bs-text); border-radius: 6px; font-size: 0.95rem; box-sizing: border-box; }
        .btn-group { display: flex; gap: 12px; margin-top: 24px; }
        button { flex: 1; padding: 12px; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: opacity 0.2s; }
        .btn-primary { background: var(--bs-primary); color: white; }
        .btn-deny { background: var(--bs-secondary); color: white; }
        .btn-primary:hover { background: var(--bs-primary-hover); }
        .btn-deny:hover { background: var(--bs-secondary-hover); }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge">OAuth 2.1 PKCE</span>
        <h2>Authorize Agent Access</h2>
        <div class="user-box">
            Connected as: <span class="highlight">{$safeUserName}</span> ({$safeUserId})
        </div>
        <p>The application <span class="highlight">{$safeClientName}</span> (<span class="highlight">{$safeClientId}</span>) is requesting permission to access your BS CMS content via the Model Context Protocol (MCP).</p>
        <form method="POST" action="/oauth/authorize">
            <input type="hidden" name="csrf_token" value="{$safeCsrf}">
            <input type="hidden" name="client_id" value="{$safeClientId}">
            <input type="hidden" name="redirect_uri" value="{$safeRedirect}">
            <input type="hidden" name="code_challenge" value="{$safeChallenge}">
            <input type="hidden" name="code_challenge_method" value="S256">
            <input type="hidden" name="state" value="{$safeState}">
            <input type="hidden" name="response_type" value="code">
            <label for="ai_mode">Operational Permission Mode:</label>
            <select name="ai_mode" id="ai_mode">
                <option value="draft_only" selected>Draft Only (Safest — Cannot publish or delete)</option>
                <option value="approval_required">Approval Required (Changes require owner approval)</option>
                <option value="full">Full Operations (Publish and edit within role)</option>
            </select>
            <label for="password">Confirm Account Password (Step-Up):</label>
            <input type="password" name="password" id="password" required placeholder="Enter your password to authorize">
            <div class="btn-group">
                <button type="submit" name="decision" value="deny" class="btn-deny">Deny</button>
                <button type="submit" name="decision" value="allow" class="btn-primary">Approve & Connect</button>
            </div>
        </form>
    </div>
</body>
</html>
HTML;
    }

    private function resolveBaseUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme() ?: 'https';
        $host = $uri->getHost();
        $port = $uri->getPort();

        if ($host === '') {
            $host = $request->getHeaderLine('Host');
        }

        $portStr = ($port !== null && $port !== 80 && $port !== 443) ? ":{$port}" : '';
        return $scheme . '://' . $host . $portStr;
    }

    private function oauthError(int $status, string $error, string $description): ResponseInterface
    {
        return $this->json([
            'error' => $error,
            'error_description' => $description,
        ], $status, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array{
     *   clients: array<string, array{id?: string, client_id: string, client_name?: string, name?: string, redirect_uris: list<string>, created_at: int}>,
     *   auth_codes: array<string, array{code: string, code_hash?: string, grant_id: string, client_id: string, redirect_uri: string, code_challenge: string, user_id?: string|null, user_name?: string|null, user_roles?: list<string>, role_id?: string|null, ai_mode: string, scopes?: list<string>, expires_at: int}>,
     *   grants: array<string, array{grant_id: string, client_id: string, user_id?: string|null, user_name?: string|null, user_roles?: list<string>, role_id?: string|null, ai_mode: string, scopes?: list<string>, status: string, created_at: int}>,
     *   access_tokens: array<string, array{token_hash: string, grant_id: string, client_id: string, user_id?: string|null, user_name?: string|null, user_roles?: list<string>, role_id?: string|null, ai_mode: string, scopes?: list<string>, expires_at: int}>,
     *   refresh_tokens: array<string, array{token_hash: string, grant_id: string, client_id: string, status: string, expires_at: int}>,
     *   revoked_refresh_tokens: array<string, array{token_hash: string, grant_id: string, revoked_at: int}>,
     *   ip_registrations: array<string, list<int>>
     * }
     */
    private function loadState(): array
    {
        $default = [
            'clients' => [],
            'auth_codes' => [],
            'grants' => [],
            'access_tokens' => [],
            'refresh_tokens' => [],
            'revoked_refresh_tokens' => [],
            'ip_registrations' => [],
        ];

        if (!is_file($this->stateFile)) {
            return $default;
        }

        $content = @file_get_contents($this->stateFile);
        if ($content === false || $content === '') {
            return $default;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return $default;
        }

        return [
            'clients' => $data['clients'] ?? [],
            'auth_codes' => $data['auth_codes'] ?? [],
            'grants' => $data['grants'] ?? [],
            'access_tokens' => $data['access_tokens'] ?? [],
            'refresh_tokens' => $data['refresh_tokens'] ?? [],
            'revoked_refresh_tokens' => $data['revoked_refresh_tokens'] ?? [],
            'ip_registrations' => $data['ip_registrations'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($this->stateFile, 0600);
    }
}
