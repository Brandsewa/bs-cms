<?php

declare(strict_types=1);

namespace BsCms\OAuth;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Auth\PasswordHasher;
use BsCms\Auth\SystemRoles;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Http\Controllers\BaseController;
use BsCms\Kernel\Config;
use BsCms\Security\Ulid;
use Nyholm\Psr7\Response;
use PDO;
use PDOStatement;
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
 * - RFC 8707 Resource Indicators for OAuth 2.0
 * - RFC 7009 OAuth 2.0 Token Revocation
 * - Grants Management API (GET/DELETE /api/v1/oauth/grants) with strict presenters and audit logging
 * - Session-bound consent with CSRF, role delegation selector, ai_mode options, and password step-up confirmation
 * - Single-origin /admin validation on return_to parameters
 * - Refresh token rotation with reuse detection revoking the entire grant and logging an audit event
 * - Full database persistence (oauth_clients, oauth_grants, oauth_authorization_codes, oauth_access_tokens, oauth_refresh_tokens, rate_limits)
 * - Hashed token storage (SHA-256) with plaintext tokens never stored in the database
 */
class OAuthController extends BaseController
{
    private const ROLE_RANKS = [
        SystemRoles::ROLE_OWNER => 100,
        'owner' => 100,
        SystemRoles::ROLE_ADMIN => 90,
        'admin' => 90,
        SystemRoles::ROLE_DEVELOPER => 80,
        'developer' => 80,
        SystemRoles::ROLE_EDITOR => 70,
        'editor' => 70,
        SystemRoles::ROLE_AI_PUBLISHER => 65,
        'ai_publisher' => 65,
        SystemRoles::ROLE_AUTHOR => 50,
        'author' => 50,
        SystemRoles::ROLE_CONTRIBUTOR => 30,
        'contributor' => 30,
        SystemRoles::ROLE_AI_DRAFT => 25,
        'ai_draft' => 25,
        SystemRoles::ROLE_VIEWER => 10,
        'viewer' => 10,
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly PasswordHasher $passwordHasher,
        private readonly AuditLogger $auditLogger,
        private readonly Authorizer $authorizer,
        private readonly ?Config $config = null
    ) {
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

        $this->cleanupExpiredRateLimits($window);
        $count = $this->getDbIpRegistrationCount($ip, $window);
        if ($count >= $maxRegistrations) {
            return $this->oauthError(429, 'rate_limit_exceeded', "Registration limit reached for this IP address (maximum {$maxRegistrations} per hour).");
        }

        $body = (string) $request->getBody();
        $input = json_decode($body, true);
        if (!is_array($input)) {
            return $this->oauthError(400, 'invalid_client_metadata', 'Malformed JSON payload or non-object root.');
        }

        // 2. Validate client_name type and length (<= 190 characters)
        if (array_key_exists('client_name', $input)) {
            if (!is_string($input['client_name'])) {
                return $this->oauthError(400, 'invalid_client_metadata', 'The client_name field must be a string.');
            }
            $clientName = trim($input['client_name']);
            if (mb_strlen($clientName) > 190) {
                return $this->oauthError(400, 'invalid_client_metadata', 'The client_name field must not exceed 190 characters.');
            }
            if ($clientName === '') {
                return $this->oauthError(400, 'invalid_client_metadata', 'The client_name field must not be empty.');
            }
        } else {
            $clientName = 'AI Client';
        }

        // 3. Validate redirect_uris type, count (<= 10), and string values
        if (!array_key_exists('redirect_uris', $input) || !is_array($input['redirect_uris'])) {
            return $this->oauthError(400, 'invalid_client_metadata', 'The redirect_uris field is required and must be an array of strings.');
        }

        if (count($input['redirect_uris']) === 0) {
            return $this->oauthError(400, 'invalid_redirect_uri', 'At least one redirect URI is required.');
        }

        if (count($input['redirect_uris']) > 10) {
            return $this->oauthError(400, 'invalid_client_metadata', 'Too many redirect URIs. A maximum of 10 is permitted.');
        }

        /** @var list<string> $redirectUris */
        $redirectUris = [];
        foreach ($input['redirect_uris'] as $idx => $uri) {
            if (!is_string($uri)) {
                return $this->oauthError(400, 'invalid_client_metadata', "Redirect URI at index {$idx} must be a string.");
            }
            $trimmedUri = trim($uri);
            if ($trimmedUri === '') {
                return $this->oauthError(400, 'invalid_redirect_uri', "Redirect URI at index {$idx} must not be empty.");
            }
            if (strlen($trimmedUri) > 2048) {
                return $this->oauthError(400, 'invalid_redirect_uri', "Redirect URI at index {$idx} exceeds maximum length of 2048 characters.");
            }
            if (!self::isValidRedirectUri($trimmedUri)) {
                return $this->oauthError(400, 'invalid_redirect_uri', "Invalid redirect URI '{$trimmedUri}'. Must be HTTPS or HTTP loopback (localhost/127.0.0.1/[::1]) with no fragment.");
            }
            $redirectUris[] = $trimmedUri;
        }

        // 4. Validate metadata fields if supplied
        if (isset($input['grant_types']) && !is_array($input['grant_types'])) {
            return $this->oauthError(400, 'invalid_client_metadata', 'The grant_types field must be an array.');
        }
        if (isset($input['response_types']) && !is_array($input['response_types'])) {
            return $this->oauthError(400, 'invalid_client_metadata', 'The response_types field must be an array.');
        }
        if (isset($input['token_endpoint_auth_method']) && !is_string($input['token_endpoint_auth_method'])) {
            return $this->oauthError(400, 'invalid_client_metadata', 'The token_endpoint_auth_method field must be a string.');
        }

        try {
            $clientId = 'bs_client_' . bin2hex(random_bytes(12));
            $clientPk = Ulid::generate();
            $nowUtc = gmdate('Y-m-d H:i:s.v');

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
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'oauth_register');
            return $this->problem(500, 'Internal Server Error', 'An unexpected error occurred during client registration.');
        }
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
     * Must be a relative path strictly matching:
     * - Exactly /admin paths (/admin, /admin/, /admin/..., /admin?...)
     * - /oauth/authorize with non-empty query string (/oauth/authorize?...)
     *
     * Rejects everything else (falling back to '/admin'):
     * - Absolute URLs (https://..., http://...)
     * - Protocol-relative (//...)
     * - Backslashes (/\..., \...)
     * - Path traversal (.. or %2e)
     * - Control characters (raw [\x00-\x1F\x7F] or encoded %0d%0a, %00, %7f)
     * - Other paths (e.g. /api/v1/users)
     */
    public static function sanitizeReturnTo(?string $returnTo): string
    {
        if ($returnTo === null || $returnTo === '') {
            return '/admin';
        }

        $returnTo = trim($returnTo);

        // Reject raw control characters (ASCII 0x00 - 0x1F and 0x7F)
        if (preg_match('/[\x00-\x1F\x7F]/', $returnTo)) {
            return '/admin';
        }

        // Reject backslashes (both single \ and combinations like /\, \\)
        if (str_contains($returnTo, '\\')) {
            return '/admin';
        }

        // Reject protocol-relative URLs (//)
        if (str_starts_with($returnTo, '//')) {
            return '/admin';
        }

        // Reject encoded control characters, CRLF (%0d, %0a), null byte (%00), DEL (%7f)
        // Also reject encoded backslashes (%5c, %5C)
        if (preg_match('/%(?:[01][0-9a-fA-F]|7[fF]|5[cC])/i', $returnTo)) {
            return '/admin';
        }

        // Reject directory traversal (.. or %2e)
        if (str_contains($returnTo, '..') || stripos($returnTo, '%2e') !== false) {
            return '/admin';
        }

        // Must strictly start with a single '/'
        if (!str_starts_with($returnTo, '/')) {
            return '/admin';
        }

        // Split into path and query
        $questionPos = strpos($returnTo, '?');
        $path = $questionPos !== false ? substr($returnTo, 0, $questionPos) : $returnTo;
        $query = $questionPos !== false ? substr($returnTo, $questionPos + 1) : null;

        // 1. Allowed: Exactly /admin paths (/admin, /admin/, /admin/...)
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return $returnTo;
        }

        // 2. Allowed: /oauth/authorize WITH a non-empty query string
        if ($path === '/oauth/authorize' && $query !== null && $query !== '') {
            return $returnTo;
        }

        // Everything else is rejected -> fallback to /admin
        return '/admin';
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

            // Browser request: redirect to /admin/login with sanitized relative return_to parameter
            $currentUri = $request->getUri();
            $path = $currentUri->getPath();
            if ($path === '' || !str_starts_with($path, '/')) {
                $path = '/' . ltrim($path, '/');
            }
            $pathAndQuery = $path;
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
        $userRoles = ($actor instanceof Actor && !empty($actor->roleIds)) ? $actor->roleIds : [];
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

        // RFC 8707 Resource Indicator validation
        $baseUrl = $this->resolveBaseUrl($request);
        $expectedResource = $baseUrl . '/mcp';
        $resource = isset($params['resource']) && is_string($params['resource']) ? trim($params['resource']) : null;
        if ($resource !== null && $resource !== '') {
            if ($resource !== $expectedResource) {
                return $this->oauthError(400, 'invalid_target', "Invalid resource indicator. Expected '{$expectedResource}'.");
            }
        }

        // Resolve available roles for delegation based on user's hierarchy
        $roleInfo = $this->getAvailableRolesForUser($userId, $userRoles);
        $availableRoles = $roleInfo['available_roles'];
        $defaultRoleId = $roleInfo['default_role_id'];

        // 2. Handle POST authorization consent submission
        if ($isPost) {
            // A. CSRF Token verification
            $submittedCsrf = (string) ($params['csrf_token'] ?? $request->getHeaderLine('X-CSRF-Token'));
            if ($csrfSecret === '' || $submittedCsrf === '' || !hash_equals($csrfSecret, $submittedCsrf)) {
                return $this->oauthError(403, 'access_denied', 'Invalid or missing CSRF token.');
            }

            // B. Step-up re-authentication
            $password = (string) ($params['password'] ?? '');
            $usersTable = $this->connection->prefixTable('users');
            $stmt = $this->connection->getPdo()->prepare("SELECT `password_hash` FROM `{$usersTable}` WHERE `id` = :id LIMIT 1");
            $stmt->execute(['id' => $userId]);
            $hash = $stmt->fetchColumn();

            if (!is_string($hash) || $password === '' || !$this->passwordHasher->verify($password, $hash)) {
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

            // D. Validate chosen role
            $chosenRoleId = isset($params['role_id']) && is_string($params['role_id']) && $params['role_id'] !== ''
                ? trim($params['role_id'])
                : $defaultRoleId;

            $allowedRoleIds = array_column($availableRoles, 'id');
            if (!in_array($chosenRoleId, $allowedRoleIds, true)) {
                return $this->oauthError(403, 'access_denied', 'Cannot delegate a role with higher privileges than your current role.');
            }

            // E. Validate ai_mode and full operations confirmation
            if (!in_array($aiMode, ['draft_only', 'approval_required', 'full'], true)) {
                return $this->oauthError(400, 'invalid_request', "Invalid ai_mode '{$aiMode}'. Must be draft_only, approval_required, or full.");
            }

            if ($aiMode === 'full') {
                $confirmFull = (string) ($params['confirm_full_mode'] ?? '');
                if ($confirmFull !== '1') {
                    return $this->oauthError(400, 'invalid_request', 'Full operations mode requires explicit acknowledgement confirmation.');
                }
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
                'available_roles' => $availableRoles,
                'default_role_id' => $defaultRoleId,
                'resource' => $resource,
            ]);

            return new Response(200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
            ], $html);
        }

        // Issue authorization code strictly bound to user and roles
        $plainCode = 'bs_code_' . bin2hex(random_bytes(16));
        $codeHash = hash('sha256', $plainCode);

        $grantPk = Ulid::generate();
        $grantsTable = $this->connection->prefixTable('oauth_grants');
        $authCodesTable = $this->connection->prefixTable('oauth_authorization_codes');
        $nowUtc = gmdate('Y-m-d H:i:s.v');
        $expiresUtc = gmdate('Y-m-d H:i:s.v', time() + 300); // 5 min expiry

        $scopes = ['mcp:read', 'mcp:write'];

        // Create active grant in database
        $clientDbId = $client['id'];
        $grantStmt = $this->connection->getPdo()->prepare("INSERT INTO `{$grantsTable}`
            (`id`, `user_id`, `client_id`, `role_id`, `scopes`, `ai_mode`, `created_at`, `last_used_at`, `revoked_at`)
            VALUES (:id, :user_id, :client_id, :role_id, :scopes, :ai_mode, :created_at, NULL, NULL)");
        $grantStmt->execute([
            'id' => $grantPk,
            'user_id' => $userId,
            'client_id' => $clientDbId,
            'role_id' => $chosenRoleId,
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'ai_mode' => $aiMode,
            'created_at' => $nowUtc,
        ]);

        $this->auditLogger->record(
            actor: $actor instanceof Actor ? $actor : Actor::user($userId, $userName, $userRoles),
            action: 'oauth.grant_created',
            subject: 'oauth_grant',
            summary: "OAuth grant created for client {$client['client_id']} ({$client['name']}) with role {$chosenRoleId} and ai_mode {$aiMode}",
            diff: [
                'grant_id' => $grantPk,
                'client_id' => (string) $client['client_id'],
                'client_name' => (string) $client['name'],
                'user_id' => $userId,
                'role_id' => $chosenRoleId,
                'ai_mode' => $aiMode,
                'scopes' => $scopes,
            ],
            result: 'success',
            ip: $this->resolveClientIp($request),
            subjectId: $grantPk
        );

        // Save authorization code with HASHED code and optional RFC 8707 resource
        $codeStmt = $this->connection->getPdo()->prepare("INSERT INTO `{$authCodesTable}`
            (`code_hash`, `grant_id`, `code_challenge`, `code_challenge_method`, `redirect_uri`, `resource`, `expires_at`, `used_at`)
            VALUES (:code_hash, :grant_id, :code_challenge, 'S256', :redirect_uri, :resource, :expires_at, NULL)");
        $codeStmt->execute([
            'code_hash' => $codeHash,
            'grant_id' => $grantPk,
            'code_challenge' => $codeChallenge,
            'redirect_uri' => $redirectUri,
            'resource' => $resource,
            'expires_at' => $expiresUtc,
        ]);

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

        // RFC 8707 Resource Indicator validation at token endpoint
        $baseUrl = $this->resolveBaseUrl($request);
        $expectedResource = $baseUrl . '/mcp';
        $requestedResource = isset($data['resource']) && is_string($data['resource']) ? trim($data['resource']) : null;
        if ($requestedResource !== null && $requestedResource !== '') {
            if ($requestedResource !== $expectedResource) {
                return $this->oauthError(400, 'invalid_target', "The requested resource indicator is invalid. Expected '{$expectedResource}'.");
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

            // Mark code as used immediately (strictly single-use)
            $nowUtc = gmdate('Y-m-d H:i:s.v');
            $updateStmt = $this->connection->getPdo()->prepare("UPDATE `{$authCodesTable}` SET `used_at` = :now WHERE `code_hash` = :code_hash");
            $updateStmt->execute(['now' => $nowUtc, 'code_hash' => $codeHash]);

            if (strtotime((string) $authCode['expires_at']) < time()) {
                return $this->oauthError(400, 'invalid_grant', 'Authorization code has expired.');
            }

            if ($authCode['grant_revoked_at'] !== null) {
                return $this->oauthError(400, 'invalid_grant', 'Authorization grant has been revoked.');
            }

            // Exact match redirect_uri requirement (RFC 6749 Section 4.1.3 & OAuth 2.1)
            $suppliedRedirectUri = (string) ($data['redirect_uri'] ?? '');
            if ($suppliedRedirectUri === '' || !hash_equals((string) $authCode['redirect_uri'], $suppliedRedirectUri)) {
                return $this->oauthError(400, 'invalid_grant', 'The redirect_uri parameter is missing or does not match the redirect_uri specified in the authorization request.');
            }

            // Client ID verification if supplied
            $suppliedClientId = (string) ($data['client_id'] ?? '');
            if ($suppliedClientId !== '' && !hash_equals((string) $authCode['client_string_id'], $suppliedClientId)) {
                return $this->oauthError(400, 'invalid_grant', 'client_id does not match the authorization code.');
            }

            // RFC 8707 check: if code had a resource, request resource must match if supplied
            if ($authCode['resource'] !== null && $requestedResource !== null && $requestedResource !== (string) $authCode['resource']) {
                return $this->oauthError(400, 'invalid_target', 'Resource indicator does not match authorization code.');
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

        if ($grantType === 'refresh_token') {
            $plainRt = (string) ($data['refresh_token'] ?? '');
            if ($plainRt === '') {
                return $this->oauthError(400, 'invalid_grant', 'Refresh token is missing.');
            }

            $rtHash = hash('sha256', $plainRt);
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
                $this->auditLogger->record(
                    actor: Actor::system(),
                    action: 'oauth.refresh_token_reuse_detected',
                    subject: 'oauth_grant',
                    summary: "Refresh token reuse detected for grant {$grantId}; entire grant revoked.",
                    diff: ['grant_id' => $grantId, 'token_hash' => $rtHash],
                    result: 'failure',
                    ip: $this->resolveClientIp($request),
                    subjectId: $grantId
                );
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

            // Also revoke the previous access token associated with this refresh token
            if ($tokenRow['access_token_hash'] !== null) {
                $atTable = $this->connection->prefixTable('oauth_access_tokens');
                $updAt = $this->connection->getPdo()->prepare("UPDATE `{$atTable}` SET `revoked_at` = :now WHERE `token_hash` = :hash");
                $updAt->execute(['now' => $nowUtc, 'hash' => $tokenRow['access_token_hash']]);
            }

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

            // Update last_used_at on grant
            $updGrant = $this->connection->getPdo()->prepare("UPDATE `{$grantsTable}` SET `last_used_at` = :now WHERE `id` = :id");
            $updGrant->execute(['now' => $nowUtc, 'id' => $grantId]);

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
        $data = [];
        parse_str($body, $data);
        if (empty($data)) {
            $parsed = $request->getParsedBody();
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }
        $token = isset($data['token']) && is_string($data['token']) ? trim($data['token']) : '';

        if ($token !== '') {
            $tokenHash = hash('sha256', $token);
            $nowUtc = gmdate('Y-m-d H:i:s.v');

            $atTable = $this->connection->prefixTable('oauth_access_tokens');
            $rtTable = $this->connection->prefixTable('oauth_refresh_tokens');

            // Revoke access token
            $stmtAt = $this->connection->getPdo()->prepare("UPDATE `{$atTable}` SET `revoked_at` = :now WHERE `token_hash` = :hash");
            $stmtAt->execute(['now' => $nowUtc, 'hash' => $tokenHash]);

            // If refresh token revoked, revoke the whole grant
            $stmtRt = $this->connection->getPdo()->prepare("SELECT `grant_id` FROM `{$rtTable}` WHERE `token_hash` = :hash LIMIT 1");
            $stmtRt->execute(['hash' => $tokenHash]);
            $grantId = $stmtRt->fetchColumn();

            if (is_string($grantId) && $grantId !== '') {
                $this->dbRevokeEntireGrant($grantId);
            }

            $this->auditLogger->record(
                actor: Actor::anonymous(),
                action: 'oauth.token_revoked',
                subject: 'oauth_token',
                summary: 'Token revoked via RFC 7009 endpoint',
                diff: ['token_hash' => $tokenHash],
                result: 'success',
                ip: $this->resolveClientIp($request),
                subjectId: $tokenHash
            );
        }

        return $this->json(['status' => 'revoked'], 200, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * GET /api/v1/oauth/grants
     * Lists active and historical grants. Regular users see own grants; admins/owners see all.
     */
    public function listGrants(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $isAdminOrOwner = $actor->isSystem()
            || $this->authorizer->can($actor, 'manage', new Subject('oauth_grant', 'all'))->allowed
            || $actor->hasRole(SystemRoles::ROLE_OWNER)
            || $actor->hasRole(SystemRoles::ROLE_ADMIN)
            || $actor->hasRole('owner')
            || $actor->hasRole('admin');

        $grantsTable = $this->connection->prefixTable('oauth_grants');
        $clientsTable = $this->connection->prefixTable('oauth_clients');
        $usersTable = $this->connection->prefixTable('users');
        $rolesTable = $this->connection->prefixTable('roles');

        $sql = "SELECT
                g.`id`, g.`client_id`, g.`user_id`, g.`role_id`, g.`scopes`, g.`ai_mode`,
                g.`created_at`, g.`last_used_at`, g.`revoked_at`,
                cl.`client_id` AS client_string_id, cl.`name` AS client_name,
                u.`name` AS user_name,
                r.`handle` AS role_handle
            FROM `{$grantsTable}` g
            JOIN `{$clientsTable}` cl ON g.`client_id` = cl.`id`
            LEFT JOIN `{$usersTable}` u ON g.`user_id` = u.`id`
            LEFT JOIN `{$rolesTable}` r ON g.`role_id` = r.`id`";

        $params = [];
        if (!$isAdminOrOwner) {
            $sql .= " WHERE g.`user_id` = :user_id";
            $params['user_id'] = $actor->id;
        }

        $sql .= " ORDER BY g.`created_at` DESC";

        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $row) {
            $rawScopes = json_decode((string) $row['scopes'], true);
            /** @var list<string> $scopes */
            $scopes = is_array($rawScopes) ? array_values(array_map('strval', $rawScopes)) : [];

            $items[] = [
                'id' => (string) $row['id'],
                'client_id' => (string) $row['client_string_id'],
                'client_name' => (string) $row['client_name'],
                'user_id' => (string) $row['user_id'],
                'user_name' => (string) ($row['user_name'] ?? 'Unknown User'),
                'role_id' => (string) $row['role_id'],
                'role_handle' => (string) ($row['role_handle'] ?? ''),
                'scopes' => $scopes,
                'ai_mode' => (string) $row['ai_mode'],
                'created_at' => (string) $row['created_at'],
                'last_used_at' => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
                'revoked_at' => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
            ];
        }

        return $this->json(['items' => $items]);
    }

    /**
     * DELETE /api/v1/oauth/grants/{id}
     * Revokes a grant and all its associated access/refresh tokens.
     */
    public function revokeGrant(ServerRequestInterface $request): ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $grantId = (string) $request->getAttribute('id', '');

        if ($grantId === '') {
            return $this->problem(404, 'Not Found', 'Grant ID is missing.');
        }

        $grantsTable = $this->connection->prefixTable('oauth_grants');
        $clientsTable = $this->connection->prefixTable('oauth_clients');

        $stmt = $this->connection->getPdo()->prepare("SELECT g.`id`, g.`user_id`, g.`revoked_at`, cl.`client_id` AS client_string_id
            FROM `{$grantsTable}` g
            JOIN `{$clientsTable}` cl ON g.`client_id` = cl.`id`
            WHERE g.`id` = :id LIMIT 1");
        $stmt->execute(['id' => $grantId]);
        $grant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($grant)) {
            return $this->problem(404, 'Not Found', 'OAuth grant not found.');
        }

        $isAdminOrOwner = $actor->isSystem()
            || $this->authorizer->can($actor, 'manage', new Subject('oauth_grant', $grantId))->allowed
            || $actor->hasRole(SystemRoles::ROLE_OWNER)
            || $actor->hasRole(SystemRoles::ROLE_ADMIN)
            || $actor->hasRole('owner')
            || $actor->hasRole('admin');

        if (!$isAdminOrOwner && (string) $grant['user_id'] !== $actor->id) {
            return $this->problem(403, 'Forbidden', 'You do not have permission to revoke this grant.');
        }

        $this->dbRevokeEntireGrant($grantId);

        $clientStringId = (string) $grant['client_string_id'];
        $this->auditLogger->record(
            actor: $actor,
            action: 'oauth.grant_revoked',
            subject: 'oauth_grant',
            summary: "Revoked OAuth grant {$grantId} for client {$clientStringId}",
            diff: [
                'grant_id' => $grantId,
                'client_id' => $clientStringId,
                'user_id' => $grant['user_id'],
            ],
            result: 'success',
            ip: $this->resolveClientIp($request),
            subjectId: $grantId
        );

        return $this->json([
            'status' => 'grant_revoked',
            'id' => $grantId,
        ]);
    }

    /**
     * Verify an access token and return token metadata.
     *
     * @return array{valid: bool, grant_id?: string, client_id?: string, client_name?: string, user_id?: string|int|null, user_name?: string|null, user_roles?: list<string>, role_ids?: list<string>, grant_role_id?: string, user_role_ids?: list<string>, ai_mode?: string, scopes?: list<string>}
     */
    public function verifyAccessToken(string $token): array
    {
        if ($token === '') {
            return ['valid' => false];
        }

        $tokenHash = hash('sha256', $token);

        $atTable = $this->connection->prefixTable('oauth_access_tokens');
        $grantsTable = $this->connection->prefixTable('oauth_grants');
        $clientsTable = $this->connection->prefixTable('oauth_clients');
        $usersTable = $this->connection->prefixTable('users');
        $rolesTable = $this->connection->prefixTable('roles');

        $stmt = $this->connection->getPdo()->prepare("SELECT
                at.`expires_at`, at.`revoked_at` AS token_revoked_at,
                g.`id` AS grant_id, g.`scopes`, g.`ai_mode`, g.`revoked_at` AS grant_revoked_at,
                cl.`client_id` AS client_string_id, cl.`name` AS client_name,
                u.`id` AS user_id, u.`name` AS user_name, u.`status` AS user_status, u.`deleted_at` AS user_deleted_at,
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
            // Check token revocation, grant revocation, and expiration
            if ($row['token_revoked_at'] === null && $row['grant_revoked_at'] === null) {
                if (strtotime((string) $row['expires_at']) >= time()) {
                    // Check user is active and not deleted
                    if ($row['user_id'] !== null && $row['user_deleted_at'] === null && ($row['user_status'] === null || $row['user_status'] === 'active')) {
                        $roleHandle = (string) ($row['role_handle'] ?? 'viewer');
                        $roleId = (string) ($row['role_id'] ?? $roleHandle);
                        $roles = [$roleHandle];
                        $rawScopes = json_decode((string) $row['scopes'], true);
                        /** @var list<string> $scopes */
                        $scopes = is_array($rawScopes) ? array_values(array_map('strval', $rawScopes)) : ['mcp:read', 'mcp:write'];

                        // Query user's current live roles for permission intersection
                        $userRolesTable = $this->connection->prefixTable('user_roles');
                        $uStmt = $this->connection->getPdo()->prepare("SELECT `role_id` FROM `{$userRolesTable}` WHERE `user_id` = :uid");
                        $uStmt->execute(['uid' => $row['user_id']]);
                        /** @var list<string> $userRoleIds */
                        $userRoleIds = array_values(array_map('strval', $uStmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

                        return [
                            'valid' => true,
                            'grant_id' => (string) $row['grant_id'],
                            'client_id' => (string) $row['client_string_id'],
                            'client_name' => (string) ($row['client_name'] ?? 'AI Client'),
                            'user_id' => (string) $row['user_id'],
                            'user_name' => (string) ($row['user_name'] ?? 'User'),
                            'user_roles' => $roles,
                            'role_ids' => [$roleId],
                            'grant_role_id' => $roleId,
                            'user_role_ids' => $userRoleIds,
                            'ai_mode' => (string) ($row['ai_mode'] ?? 'draft_only'),
                            'scopes' => $scopes,
                        ];
                    }
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

    /**
     * Resolve the roles a user holds, their maximum privilege rank, and which roles
     * can be delegated to the AI connector (any role with rank <= user's rank).
     *
     * @param list<string> $userRoles
     * @return array{available_roles: list<array{id: string, handle: string, name: string, rank: int}>, default_role_id: string}
     */
    private function getAvailableRolesForUser(string $userId, array $userRoles): array
    {
        $rolesTable = $this->connection->prefixTable('roles');
        $userRolesTable = $this->connection->prefixTable('user_roles');

        // Query user's actual assigned roles from user_roles
        $stmt = $this->connection->getPdo()->prepare("SELECT r.`id`, r.`handle`, r.`name`
            FROM `{$userRolesTable}` ur
            JOIN `{$rolesTable}` r ON ur.`role_id` = r.`id`
            WHERE ur.`user_id` = :user_id");
        $stmt->execute(['user_id' => $userId]);
        /** @var list<array{id: string, handle: string, name: string}> $userRoleRows */
        $userRoleRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $userRoleIdentifiers = $userRoles;
        foreach ($userRoleRows as $urr) {
            $userRoleIdentifiers[] = (string) $urr['id'];
            $userRoleIdentifiers[] = (string) $urr['handle'];
        }

        // Determine user's maximum rank
        $maxRank = 10; // Viewer level minimum
        foreach ($userRoleIdentifiers as $ident) {
            if (isset(self::ROLE_RANKS[$ident])) {
                $maxRank = max($maxRank, self::ROLE_RANKS[$ident]);
            }
        }

        // Query all system roles from database
        $allRolesStmt = $this->connection->getPdo()->query("SELECT `id`, `handle`, `name` FROM `{$rolesTable}` ORDER BY `id` ASC");
        /** @var list<array{id: string, handle: string, name: string}> $allRoles */
        $allRoles = ($allRolesStmt instanceof PDOStatement) ? $allRolesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $available = [];
        $defaultRoleId = '';
        $highestAssignedRank = -1;

        foreach ($allRoles as $r) {
            $rId = (string) $r['id'];
            $rHandle = (string) $r['handle'];
            $rank = self::ROLE_RANKS[$rId] ?? self::ROLE_RANKS[$rHandle] ?? 30;

            if ($rank <= $maxRank) {
                $available[] = [
                    'id' => $rId,
                    'handle' => $rHandle,
                    'name' => (string) $r['name'],
                    'rank' => $rank,
                ];

                // If user holds this role and it's their highest, set as default
                if (in_array($rId, $userRoleIdentifiers, true) || in_array($rHandle, $userRoleIdentifiers, true)) {
                    if ($rank > $highestAssignedRank) {
                        $highestAssignedRank = $rank;
                        $defaultRoleId = $rId;
                    }
                }
            }
        }

        if ($defaultRoleId === '' && !empty($available)) {
            $defaultRoleId = $available[0]['id'];
        }

        return [
            'available_roles' => $available,
            'default_role_id' => $defaultRoleId,
        ];
    }

    private function dbRevokeEntireGrant(string $grantId): void
    {
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
        $table = $this->connection->prefixTable('rate_limits');
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
        $table = $this->connection->prefixTable('rate_limits');
        $threshold = gmdate('Y-m-d H:i:s', time() - ($window * 2));
        $stmt = $this->connection->getPdo()->prepare("DELETE FROM `{$table}` WHERE `window_start` < :threshold");
        $stmt->execute(['threshold' => $threshold]);
    }

    /**
     * Render the clean, structured consent screen template with role selector and ai_mode.
     *
     * @param array{client_id: string, client_name: string, redirect_uri: string, code_challenge: string, state: string, user_name: string, user_id: string, csrf_token: string, available_roles: list<array{id: string, handle: string, name: string, rank: int}>, default_role_id: string, resource: ?string} $viewData
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
        $safeResource = htmlspecialchars((string) ($viewData['resource'] ?? ''), ENT_QUOTES, 'UTF-8');

        $roleOptionsHtml = '';
        foreach ($viewData['available_roles'] as $r) {
            $rId = htmlspecialchars($r['id'], ENT_QUOTES, 'UTF-8');
            $rName = htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8');
            $rHandle = htmlspecialchars($r['handle'], ENT_QUOTES, 'UTF-8');
            $selected = ($r['id'] === $viewData['default_role_id']) ? ' selected' : '';
            $roleOptionsHtml .= "<option value=\"{$rId}\"{$selected}>{$rName} ({$rHandle})</option>\n";
        }

        $resourceInput = $safeResource !== '' ? "<input type=\"hidden\" name=\"resource\" value=\"{$safeResource}\">\n" : '';

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
            --bs-danger: #ef4444;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bs-bg); color: var(--bs-text); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .card { background: var(--bs-card-bg); border: 1px solid var(--bs-border); border-radius: 12px; max-width: 520px; width: 100%; padding: 32px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5); }
        h2 { margin-top: 0; font-size: 1.5rem; color: #38bdf8; }
        p { color: var(--bs-muted); font-size: 0.95rem; line-height: 1.5; }
        .highlight { color: #f1f5f9; font-weight: 600; }
        .badge { display: inline-block; padding: 4px 10px; background: var(--bs-primary); color: white; border-radius: 6px; font-size: 0.8rem; font-weight: bold; }
        .user-box { background: var(--bs-bg); padding: 12px; border-radius: 8px; margin: 16px 0; border: 1px solid var(--bs-border); font-size: 0.9rem; }
        .scopes-box { background: var(--bs-bg); padding: 12px; border-radius: 8px; margin: 12px 0; border: 1px solid var(--bs-border); font-size: 0.85rem; }
        .scope-item { display: inline-block; background: #1e293b; padding: 2px 8px; border-radius: 4px; border: 1px solid #475569; margin: 2px 4px 2px 0; }
        label { display: block; margin: 14px 0 6px; font-weight: 500; font-size: 0.9rem; color: #e2e8f0; }
        select, input[type="password"] { width: 100%; padding: 10px; background: var(--bs-bg); border: 1px solid #475569; color: var(--bs-text); border-radius: 6px; font-size: 0.95rem; box-sizing: border-box; }
        .warning-box { display: none; margin: 12px 0; padding: 12px; background: rgba(239, 68, 68, 0.15); border: 1px solid var(--bs-danger); border-radius: 8px; }
        .warning-box p { color: #fca5a5; font-size: 0.85rem; margin: 0 0 8px 0; }
        .checkbox-label { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #f8fafc; cursor: pointer; margin: 0; }
        .checkbox-label input { width: auto; margin: 0; }
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

        <div class="scopes-box">
            <span style="color: var(--bs-muted); font-weight: 500;">Requested Scopes:</span><br>
            <span class="scope-item">mcp:read (Read schema, taxonomies, posts)</span>
            <span class="scope-item">mcp:write (Draft and edit content)</span>
        </div>

        <form method="POST" action="/oauth/authorize">
            <input type="hidden" name="csrf_token" value="{$safeCsrf}">
            <input type="hidden" name="client_id" value="{$safeClientId}">
            <input type="hidden" name="redirect_uri" value="{$safeRedirect}">
            <input type="hidden" name="code_challenge" value="{$safeChallenge}">
            <input type="hidden" name="code_challenge_method" value="S256">
            <input type="hidden" name="state" value="{$safeState}">
            <input type="hidden" name="response_type" value="code">
            {$resourceInput}
            <label for="role_id">Act with Role Permissions (Delegated Role):</label>
            <select name="role_id" id="role_id">
                {$roleOptionsHtml}
            </select>

            <label for="ai_mode">Operational Permission Mode:</label>
            <select name="ai_mode" id="ai_mode" onchange="toggleFullWarning(this.value)">
                <option value="draft_only" selected>Draft Only (Safest — Cannot publish or delete)</option>
                <option value="approval_required">Approval Required (Changes require human approval)</option>
                <option value="full">Full Operations (Publish and edit within role)</option>
            </select>

            <div id="full_warning_box" class="warning-box">
                <p><strong>Warning: Full Operations Mode</strong></p>
                <p>This allows the AI connector to create, publish, and modify live content according to the delegated role without manual human approval.</p>
                <label class="checkbox-label" for="confirm_full_mode">
                    <input type="checkbox" name="confirm_full_mode" id="confirm_full_mode" value="1">
                    <span>I explicitly acknowledge and authorize Full Operations mode</span>
                </label>
            </div>

            <label for="password">Confirm Account Password (Step-Up):</label>
            <input type="password" name="password" id="password" required placeholder="Enter your password to authorize">

            <div class="btn-group">
                <button type="submit" name="decision" value="deny" class="btn-deny">Deny</button>
                <button type="submit" name="decision" value="allow" class="btn-primary">Approve & Connect</button>
            </div>
        </form>
    </div>

    <script>
        function toggleFullWarning(val) {
            var box = document.getElementById('full_warning_box');
            var chk = document.getElementById('confirm_full_mode');
            if (val === 'full') {
                box.style.display = 'block';
                chk.required = true;
            } else {
                box.style.display = 'none';
                chk.required = false;
                chk.checked = false;
            }
        }
    </script>
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

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        if (isset($serverParams['REMOTE_ADDR']) && is_string($serverParams['REMOTE_ADDR']) && $serverParams['REMOTE_ADDR'] !== '') {
            return $serverParams['REMOTE_ADDR'];
        }

        return '127.0.0.1';
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
}
