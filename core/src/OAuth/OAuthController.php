<?php

declare(strict_types=1);

namespace BsCms\OAuth;

use BsCms\Auth\Actor;
use BsCms\Auth\PasswordHasher;
use BsCms\Database\Connection;
use BsCms\Http\Controllers\BaseController;
use BsCms\Http\JsonResponse;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * OAuthController: OAuth 2.1 authorization server endpoints for AI agents and MCP clients.
 *
 * Implements:
 * - RFC 9728 OAuth 2.0 Protected Resource Metadata
 * - RFC 8414 OAuth 2.0 Authorization Server Metadata
 * - RFC 7591 Dynamic Client Registration Protocol (with strict URI validation and IP cap)
 * - RFC 7636 Proof Key for Code Exchange (PKCE S256 mandatory)
 * - Session-bound consent with CSRF and step-up confirmation
 * - Refresh token rotation with reuse detection revoking the entire grant
 */
final class OAuthController extends BaseController
{
    private string $stateFile;

    public function __construct(
        ?string $stateFile = null,
        private readonly ?Connection $connection = null,
        private readonly ?PasswordHasher $passwordHasher = null
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
        // 1. Enforce IP rate limit cap (maximum 10 registrations per IP per hour)
        $ip = $this->resolveClientIp($request);
        $state = $this->loadState();
        $now = time();
        $window = 3600;

        /** @var list<int> $ipTimestamps */
        $ipTimestamps = array_values(array_filter(
            $state['ip_registrations'][$ip] ?? [],
            fn (int $ts): bool => ($now - $ts) < $window
        ));

        if (count($ipTimestamps) >= 10) {
            return $this->oauthError(429, 'rate_limit_exceeded', 'Registration limit reached for this IP address (maximum 10 per hour).');
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

        $ipTimestamps[] = $now;
        $state['ip_registrations'][$ip] = $ipTimestamps;

        $clientId = 'bs_client_' . bin2hex(random_bytes(12));

        $state['clients'][$clientId] = [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'created_at' => time(),
        ];
        $this->saveState($state);

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

            // Browser request: redirect to /admin/login with return_to parameter
            $returnTo = (string) $request->getUri();
            $loginUrl = '/admin/login?return_to=' . urlencode($returnTo);
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

        $stateData = $this->loadState();
        if (!isset($stateData['clients'][$clientId])) {
            return $this->oauthError(400, 'unauthorized_client', 'Unknown client_id.');
        }

        $client = $stateData['clients'][$clientId];
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
            $safeClientId = htmlspecialchars($clientId, ENT_QUOTES, 'UTF-8');
            $safeClientName = htmlspecialchars($client['client_name'], ENT_QUOTES, 'UTF-8');
            $safeRedirect = htmlspecialchars($redirectUri, ENT_QUOTES, 'UTF-8');
            $safeChallenge = htmlspecialchars($codeChallenge, ENT_QUOTES, 'UTF-8');
            $safeState = htmlspecialchars($state, ENT_QUOTES, 'UTF-8');
            $safeUserName = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
            $safeUserId = htmlspecialchars($userId, ENT_QUOTES, 'UTF-8');
            $safeCsrf = htmlspecialchars($csrfSecret, ENT_QUOTES, 'UTF-8');

            $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authorize AI Connector - BS CMS</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; max-width: 480px; width: 100%; padding: 32px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5); }
        h2 { margin-top: 0; font-size: 1.5rem; color: #38bdf8; }
        p { color: #94a3b8; font-size: 0.95rem; line-height: 1.5; }
        .highlight { color: #f1f5f9; font-weight: 600; }
        .badge { display: inline-block; padding: 4px 10px; background: #0284c7; color: white; border-radius: 6px; font-size: 0.8rem; font-weight: bold; }
        .user-box { background: #0f172a; padding: 12px; border-radius: 8px; margin: 16px 0; border: 1px solid #334155; font-size: 0.9rem; }
        label { display: block; margin: 16px 0 8px; font-weight: 500; font-size: 0.9rem; }
        select, input[type="password"] { width: 100%; padding: 10px; background: #0f172a; border: 1px solid #475569; color: #f8fafc; border-radius: 6px; font-size: 0.95rem; box-sizing: border-box; }
        .btn-group { display: flex; gap: 12px; margin-top: 24px; }
        button { flex: 1; padding: 12px; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: opacity 0.2s; }
        .btn-primary { background: #0284c7; color: white; }
        .btn-deny { background: #475569; color: white; }
        .btn-primary:hover, .btn-deny:hover { opacity: 0.9; }
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
            return new Response(200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
            ], $html);
        }

        // Issue authorization code strictly bound to user and roles
        $code = 'bs_code_' . bin2hex(random_bytes(16));
        $grantId = 'bs_grant_' . bin2hex(random_bytes(12));

        $stateData['auth_codes'][$code] = [
            'code' => $code,
            'grant_id' => $grantId,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'user_id' => $userId,
            'user_name' => $userName,
            'user_roles' => $userRoles,
            'ai_mode' => $aiMode,
            'scopes' => ['mcp:read', 'mcp:write'],
            'expires_at' => time() + 300,
        ];
        $this->saveState($stateData);

        $sep = str_contains($redirectUri, '?') ? '&' : '?';
        $location = $redirectUri . $sep . 'code=' . urlencode($code);
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
        $state = $this->loadState();

        if ($grantType === 'authorization_code') {
            $code = (string) ($data['code'] ?? '');
            $codeVerifier = (string) ($data['code_verifier'] ?? '');

            if (!isset($state['auth_codes'][$code])) {
                return $this->oauthError(400, 'invalid_grant', 'Authorization code is invalid or expired.');
            }

            $authCode = $state['auth_codes'][$code];
            unset($state['auth_codes'][$code]); // Strictly single-use

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
            $accessToken = 'bs_at_' . bin2hex(random_bytes(24));
            $refreshToken = 'bs_rt_' . bin2hex(random_bytes(24));

            $state['grants'][$grantId] = [
                'grant_id' => $grantId,
                'client_id' => $authCode['client_id'],
                'user_id' => $authCode['user_id'] ?? null,
                'user_name' => $authCode['user_name'] ?? null,
                'user_roles' => $authCode['user_roles'] ?? [],
                'ai_mode' => $authCode['ai_mode'],
                'scopes' => $authCode['scopes'] ?? ['mcp:read', 'mcp:write'],
                'status' => 'active',
                'created_at' => time(),
            ];

            $state['access_tokens'][$accessToken] = [
                'token' => $accessToken,
                'grant_id' => $grantId,
                'client_id' => $authCode['client_id'],
                'user_id' => $authCode['user_id'] ?? null,
                'user_name' => $authCode['user_name'] ?? null,
                'user_roles' => $authCode['user_roles'] ?? [],
                'ai_mode' => $authCode['ai_mode'],
                'scopes' => $authCode['scopes'] ?? ['mcp:read', 'mcp:write'],
                'expires_at' => time() + 3600,
            ];

            $state['refresh_tokens'][$refreshToken] = [
                'token' => $refreshToken,
                'grant_id' => $grantId,
                'client_id' => $authCode['client_id'],
                'status' => 'active',
                'expires_at' => time() + (30 * 86400),
            ];

            $this->saveState($state);

            return $this->json([
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => $refreshToken,
                'scope' => 'mcp:read mcp:write',
            ], 200, [
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'no-store',
            ]);
        }

        if ($grantType === 'refresh_token') {
            $refreshToken = (string) ($data['refresh_token'] ?? '');

            // 1. Check for token reuse (if token was already rotated/revoked)
            if (isset($state['revoked_refresh_tokens'][$refreshToken])) {
                $revokedMeta = $state['revoked_refresh_tokens'][$refreshToken];
                $grantId = $revokedMeta['grant_id'];

                // SECURITY: REUSE DETECTED -> Revoke entire grant and all active tokens!
                $this->revokeEntireGrant($state, $grantId);
                $this->saveState($state);

                return $this->oauthError(400, 'invalid_grant', 'Refresh token reuse detected: the entire authorization grant has been revoked.');
            }

            if (!isset($state['refresh_tokens'][$refreshToken])) {
                return $this->oauthError(400, 'invalid_grant', 'Refresh token not found.');
            }

            $currentRt = $state['refresh_tokens'][$refreshToken];
            $grantId = $currentRt['grant_id'];

            if (!isset($state['grants'][$grantId]) || $state['grants'][$grantId]['status'] !== 'active') {
                return $this->oauthError(400, 'invalid_grant', 'Grant has been revoked.');
            }

            // Rotate: revoke the used refresh token and remember it in revoked list
            unset($state['refresh_tokens'][$refreshToken]);
            $state['revoked_refresh_tokens'][$refreshToken] = [
                'token' => $refreshToken,
                'grant_id' => $grantId,
                'revoked_at' => time(),
            ];

            // Issue new pair
            $newAccessToken = 'bs_at_' . bin2hex(random_bytes(24));
            $newRefreshToken = 'bs_rt_' . bin2hex(random_bytes(24));

            $grant = $state['grants'][$grantId];

            $state['access_tokens'][$newAccessToken] = [
                'token' => $newAccessToken,
                'grant_id' => $grantId,
                'client_id' => $grant['client_id'],
                'user_id' => $grant['user_id'] ?? null,
                'user_name' => $grant['user_name'] ?? null,
                'user_roles' => $grant['user_roles'] ?? [],
                'ai_mode' => $grant['ai_mode'],
                'scopes' => $grant['scopes'] ?? ['mcp:read', 'mcp:write'],
                'expires_at' => time() + 3600,
            ];

            $state['refresh_tokens'][$newRefreshToken] = [
                'token' => $newRefreshToken,
                'grant_id' => $grantId,
                'client_id' => $grant['client_id'],
                'status' => 'active',
                'expires_at' => time() + (30 * 86400),
            ];

            $this->saveState($state);

            return $this->json([
                'access_token' => $newAccessToken,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => $newRefreshToken,
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
        $token = isset($data['token']) && is_string($data['token']) ? $data['token'] : '';

        if ($token !== '') {
            $state = $this->loadState();
            if (isset($state['access_tokens'][$token])) {
                unset($state['access_tokens'][$token]);
            }
            if (isset($state['refresh_tokens'][$token])) {
                $grantId = $state['refresh_tokens'][$token]['grant_id'];
                $this->revokeEntireGrant($state, $grantId);
            }
            $this->saveState($state);
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
        $state = $this->loadState();
        if (isset($state['access_tokens'][$token])) {
            $at = $state['access_tokens'][$token];
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
                    'token' => $tok,
                    'grant_id' => $grantId,
                    'revoked_at' => time(),
                ];
            }
        }
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
     *   clients: array<string, array{client_id: string, client_name: string, redirect_uris: list<string>, created_at: int}>,
     *   auth_codes: array<string, array{code: string, grant_id: string, client_id: string, redirect_uri: string, code_challenge: string, user_id?: string|null, user_name?: string|null, user_roles?: list<string>, ai_mode: string, scopes?: list<string>, expires_at: int}>,
     *   grants: array<string, array{grant_id: string, client_id: string, user_id?: string|null, user_name?: string|null, user_roles?: list<string>, ai_mode: string, scopes?: list<string>, status: string, created_at: int}>,
     *   access_tokens: array<string, array{token: string, grant_id: string, client_id: string, user_id?: string|null, user_name?: string|null, user_roles?: list<string>, ai_mode: string, scopes?: list<string>, expires_at: int}>,
     *   refresh_tokens: array<string, array{token: string, grant_id: string, client_id: string, status: string, expires_at: int}>,
     *   revoked_refresh_tokens: array<string, array{token: string, grant_id: string, revoked_at: int}>,
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
