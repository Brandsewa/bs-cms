<?php

declare(strict_types=1);

/**
 * BS CMS Phase 8 Step 8.0: Spike B - Stateless MCP on Shared Hosting
 *
 * Demonstrates:
 * 1. Stateless Streamable HTTP transport over POST /mcp (JSON-RPC 2.0, protocol 2024-11-05).
 * 2. GET /mcp returns 405 Method Not Allowed (zero SSE, zero long-lived processes).
 * 3. 401 Unauthorized with RFC 9728 WWW-Authenticate: Bearer resource_metadata="..." header.
 * 4. RFC 9728 Protected Resource Metadata (/.well-known/oauth-protected-resource).
 * 5. RFC 8414 Authorization Server Metadata (/.well-known/oauth-authorization-server).
 * 6. RFC 7591 Dynamic Client Registration (/oauth/register).
 * 7. OAuth 2.1 PKCE S256 authorization code exchange (/oauth/authorize & /oauth/token).
 * 8. Real query execution against DB if available, fallback mock data if standalone.
 */

namespace BsCms\Spike;

final class McpSpikeHandler
{
    public const PROTOCOL_VERSION = '2024-11-05';

    /** @var array<string, array{client_id: string, client_name: string, redirect_uris: list<string>}> */
    private static array $clients = [];

    /** @var array<string, array{code: string, client_id: string, redirect_uri: string, code_challenge: string, expires_at: int}> */
    private static array $authCodes = [];

    /** @var array<string, array{token: string, client_id: string, role: string, mode: string, expires_at: int}> */
    private static array $accessTokens = [];

    /** @var array<string, array{refresh_token: string, client_id: string, access_token: string}> */
    private static array $refreshTokens = [];

    /**
     * Process request and return a structured response.
     *
     * @param array<string, mixed> $server
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function process(array $server, string $rawBody): array
    {
        $startTime = microtime(true);
        $startMem = memory_get_usage();

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $scheme = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') || ($server['SERVER_PORT'] ?? '') === '443' ? 'https' : 'http';
        $host = (string) ($server['HTTP_HOST'] ?? '127.0.0.1');
        $baseUrl = $scheme . '://' . $host;

        // Route matching
        if ($path === '/.well-known/oauth-protected-resource' && $method === 'GET') {
            return self::buildJsonResponse(200, [
                'resource' => $baseUrl . '/mcp',
                'authorization_servers' => [$baseUrl],
            ], $startTime, $startMem);
        }

        if ($path === '/.well-known/oauth-authorization-server' && $method === 'GET') {
            return self::buildJsonResponse(200, [
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
            ], $startTime, $startMem);
        }

        if ($path === '/oauth/register' && $method === 'POST') {
            return self::handleRegistration($rawBody, $startTime, $startMem);
        }

        if ($path === '/oauth/authorize') {
            return self::handleAuthorize($method, $server, $startTime, $startMem);
        }

        if ($path === '/oauth/token' && $method === 'POST') {
            return self::handleToken($rawBody, $server, $startTime, $startMem);
        }

        if ($path === '/mcp' || str_ends_with($path, 'spike-b-mcp.php')) {
            if ($method === 'GET') {
                return [
                    'status' => 405,
                    'headers' => [
                        'Content-Type' => 'application/problem+json; charset=utf-8',
                        'Allow' => 'POST',
                        'Cache-Control' => 'no-store',
                    ],
                    'body' => (string) json_encode([
                        'type' => 'https://errors.bscms.org/method-not-allowed',
                        'title' => 'Method Not Allowed',
                        'status' => 405,
                        'detail' => 'Stateless MCP transport requires HTTP POST with JSON-RPC body. Server-Sent Events (SSE) and long-lived GET streaming are not used on shared hosting.',
                    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                ];
            }

            if ($method === 'POST') {
                return self::handleMcpJsonRpc($server, $rawBody, $baseUrl, $startTime, $startMem);
            }
        }

        // 404 Not Found
        return [
            'status' => 404,
            'headers' => [
                'Content-Type' => 'application/problem+json; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
            'body' => (string) json_encode([
                'type' => 'https://errors.bscms.org/not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => "No route found for path '{$path}'",
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];
    }

    /**
     * Dynamic Client Registration (RFC 7591)
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function handleRegistration(string $rawBody, float $startTime, int $startMem): array
    {
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            return self::buildJsonResponse(400, ['error' => 'invalid_request', 'error_description' => 'Malformed JSON body.'], $startTime, $startMem);
        }

        $clientName = (string) ($input['client_name'] ?? 'AI Client');
        /** @var list<string> $redirectUris */
        $redirectUris = is_array($input['redirect_uris'] ?? null)
            ? array_values(array_filter(array_map('strval', $input['redirect_uris']), fn ($u) => $u !== ''))
            : [];

        $clientId = 'bs_client_' . bin2hex(random_bytes(12));
        self::$clients[$clientId] = [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
        ];

        self::saveState();

        return self::buildJsonResponse(201, [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], $startTime, $startMem);
    }

    /**
     * Authorization consent & code issuance (RFC 6749 / OAuth 2.1 with PKCE)
     *
     * @param array<string, mixed> $server
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function handleAuthorize(string $method, array $server, float $startTime, int $startMem): array
    {
        self::loadState();

        $params = $method === 'POST' ? ($_POST ?: []) : ($_GET ?: []);
        $clientId = (string) ($params['client_id'] ?? '');
        $redirectUri = (string) ($params['redirect_uri'] ?? '');
        $codeChallenge = (string) ($params['code_challenge'] ?? '');
        $codeChallengeMethod = (string) ($params['code_challenge_method'] ?? '');
        $state = (string) ($params['state'] ?? '');
        $responseType = (string) ($params['response_type'] ?? '');

        if ($responseType !== 'code') {
            return self::buildJsonResponse(400, ['error' => 'unsupported_response_type', 'error_description' => 'Only response_type=code is supported.'], $startTime, $startMem);
        }

        if ($codeChallenge === '' || $codeChallengeMethod !== 'S256') {
            return self::buildJsonResponse(400, ['error' => 'invalid_request', 'error_description' => 'PKCE with S256 code_challenge_method is required.'], $startTime, $startMem);
        }

        // If GET, render simple consent form
        if ($method === 'GET' && empty($params['approved'])) {
            $safeClientId = htmlspecialchars($clientId, ENT_QUOTES, 'UTF-8');
            $safeRedirect = htmlspecialchars($redirectUri, ENT_QUOTES, 'UTF-8');
            $safeChallenge = htmlspecialchars($codeChallenge, ENT_QUOTES, 'UTF-8');
            $safeState = htmlspecialchars($state, ENT_QUOTES, 'UTF-8');

            $html = <<<HTML
<!DOCTYPE html>
<html>
<head><title>Authorize MCP Client - BS CMS</title></head>
<body style="font-family: system-ui, sans-serif; max-width: 500px; margin: 40px auto; padding: 20px; border: 1px solid #ccc; border-radius: 8px;">
    <h2>Authorize AI Agent</h2>
    <p>Client <strong>{$safeClientId}</strong> wants to connect to BS CMS MCP Server.</p>
    <form method="POST" action="/oauth/authorize">
        <input type="hidden" name="client_id" value="{$safeClientId}">
        <input type="hidden" name="redirect_uri" value="{$safeRedirect}">
        <input type="hidden" name="code_challenge" value="{$safeChallenge}">
        <input type="hidden" name="code_challenge_method" value="S256">
        <input type="hidden" name="state" value="{$safeState}">
        <input type="hidden" name="response_type" value="code">
        <label>Mode:
            <select name="ai_mode">
                <option value="draft_only" selected>Draft Only (Safest - Default)</option>
                <option value="approval_required">Approval Required</option>
                <option value="full">Full Operations</option>
            </select>
        </label><br><br>
        <button type="submit" name="approved" value="1" style="background:#2563eb; color:white; padding:8px 16px; border:none; border-radius:4px; cursor:pointer;">Authorize Agent</button>
    </form>
</body>
</html>
HTML;
            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
                'body' => $html,
            ];
        }

        // Issue authorization code
        $code = 'bs_code_' . bin2hex(random_bytes(16));
        self::$authCodes[$code] = [
            'code' => $code,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'expires_at' => time() + 300,
        ];
        self::saveState();

        $sep = str_contains($redirectUri, '?') ? '&' : '?';
        $location = $redirectUri . $sep . 'code=' . urlencode($code);
        if ($state !== '') {
            $location .= '&state=' . urlencode($state);
        }

        return [
            'status' => 302,
            'headers' => [
                'Location' => $location,
                'Cache-Control' => 'no-store',
            ],
            'body' => '',
        ];
    }

    /**
     * Code exchange for token with PKCE S256 verification (RFC 7636)
     *
     * @param array<string, mixed> $server
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function handleToken(string $rawBody, array $server, float $startTime, int $startMem): array
    {
        self::loadState();

        // Parse form-urlencoded or JSON
        $data = [];
        $contentType = (string) ($server['CONTENT_TYPE'] ?? '');
        if (str_contains($contentType, 'application/json')) {
            $data = json_decode($rawBody, true) ?: [];
        } else {
            parse_str($rawBody, $data);
            if (empty($data)) {
                $data = $_POST;
            }
        }

        $grantType = (string) ($data['grant_type'] ?? '');

        if ($grantType === 'authorization_code') {
            $code = (string) ($data['code'] ?? '');
            $codeVerifier = (string) ($data['code_verifier'] ?? '');

            if (!isset(self::$authCodes[$code])) {
                return self::buildJsonResponse(400, ['error' => 'invalid_grant', 'error_description' => 'Authorization code is invalid or expired.'], $startTime, $startMem);
            }

            $authCodeData = self::$authCodes[$code];
            unset(self::$authCodes[$code]); // single-use code

            if (time() > $authCodeData['expires_at']) {
                self::saveState();
                return self::buildJsonResponse(400, ['error' => 'invalid_grant', 'error_description' => 'Authorization code expired.'], $startTime, $startMem);
            }

            // Verify PKCE S256: BASE64URL(SHA256(code_verifier)) == code_challenge
            $calculatedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            if (!hash_equals($authCodeData['code_challenge'], $calculatedChallenge)) {
                self::saveState();
                return self::buildJsonResponse(400, ['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed: code_verifier does not match code_challenge.'], $startTime, $startMem);
            }

            $accessToken = 'bs_at_' . bin2hex(random_bytes(24));
            $refreshToken = 'bs_rt_' . bin2hex(random_bytes(24));

            self::$accessTokens[$accessToken] = [
                'token' => $accessToken,
                'client_id' => $authCodeData['client_id'],
                'role' => 'ai_draft',
                'mode' => 'draft_only',
                'expires_at' => time() + 3600,
            ];

            self::$refreshTokens[$refreshToken] = [
                'refresh_token' => $refreshToken,
                'client_id' => $authCodeData['client_id'],
                'access_token' => $accessToken,
            ];

            self::saveState();

            return self::buildJsonResponse(200, [
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => $refreshToken,
                'scope' => 'mcp:read',
            ], $startTime, $startMem);
        }

        if ($grantType === 'refresh_token') {
            $refreshToken = (string) ($data['refresh_token'] ?? '');
            if (!isset(self::$refreshTokens[$refreshToken])) {
                return self::buildJsonResponse(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid refresh token.'], $startTime, $startMem);
            }

            $rtData = self::$refreshTokens[$refreshToken];
            unset(self::$refreshTokens[$refreshToken]); // Rotate refresh token

            $newAccessToken = 'bs_at_' . bin2hex(random_bytes(24));
            $newRefreshToken = 'bs_rt_' . bin2hex(random_bytes(24));

            self::$accessTokens[$newAccessToken] = [
                'token' => $newAccessToken,
                'client_id' => $rtData['client_id'],
                'role' => 'ai_draft',
                'mode' => 'draft_only',
                'expires_at' => time() + 3600,
            ];

            self::$refreshTokens[$newRefreshToken] = [
                'refresh_token' => $newRefreshToken,
                'client_id' => $rtData['client_id'],
                'access_token' => $newAccessToken,
            ];

            self::saveState();

            return self::buildJsonResponse(200, [
                'access_token' => $newAccessToken,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => $newRefreshToken,
                'scope' => 'mcp:read',
            ], $startTime, $startMem);
        }

        return self::buildJsonResponse(400, ['error' => 'unsupported_grant_type'], $startTime, $startMem);
    }

    /**
     * Stateless MCP Streamable HTTP JSON-RPC handler (POST /mcp)
     *
     * @param array<string, mixed> $server
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function handleMcpJsonRpc(array $server, string $rawBody, string $baseUrl, float $startTime, int $startMem): array
    {
        self::loadState();

        // 1. Authentication check
        $authHeader = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $token = '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = trim(substr($authHeader, 7));
        }

        // Accept: test spike token, static PAT, or issued OAuth access token
        $isAuthenticated = false;
        if ($token === 'spike-test-token' || str_starts_with($token, 'bs_tok_')) {
            $isAuthenticated = true;
        } elseif (isset(self::$accessTokens[$token]) && time() <= self::$accessTokens[$token]['expires_at']) {
            $isAuthenticated = true;
        }

        if (!$isAuthenticated) {
            return [
                'status' => 401,
                'headers' => [
                    'WWW-Authenticate' => 'Bearer resource_metadata="' . $baseUrl . '/.well-known/oauth-protected-resource"',
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Cache-Control' => 'no-store',
                ],
                'body' => (string) json_encode([
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => [
                        'code' => -32000,
                        'message' => 'Unauthorized. Provide Bearer token via Authorization header.',
                        'data' => [
                            'resource_metadata' => $baseUrl . '/.well-known/oauth-protected-resource',
                        ],
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ];
        }

        // 2. Parse JSON-RPC payload
        $request = json_decode($rawBody, true);
        if (!is_array($request) || ($request['jsonrpc'] ?? '') !== '2.0') {
            return self::buildJsonRpcResponse([
                'jsonrpc' => '2.0',
                'id' => is_array($request) ? ($request['id'] ?? null) : null,
                'error' => [
                    'code' => -32600,
                    'message' => 'Invalid Request: jsonrpc must be "2.0"',
                ],
            ], $startTime, $startMem);
        }

        $id = $request['id'] ?? null;
        $method = (string) ($request['method'] ?? '');
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        // 3. Dispatch JSON-RPC methods
        switch ($method) {
            case 'initialize':
                return self::buildJsonRpcResponse([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'protocolVersion' => self::PROTOCOL_VERSION,
                        'capabilities' => [
                            'tools' => [
                                'listChanged' => false,
                            ],
                            'resources' => [
                                'subscribe' => false,
                                'listChanged' => false,
                            ],
                        ],
                        'serverInfo' => [
                            'name' => 'bs-cms',
                            'version' => '1.0.0',
                        ],
                    ],
                ], $startTime, $startMem);

            case 'notifications/initialized':
                if ($id === null) {
                    return [
                        'status' => 204,
                        'headers' => ['Cache-Control' => 'no-store'],
                        'body' => '',
                    ];
                }
                return self::buildJsonRpcResponse([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => new \stdClass(),
                ], $startTime, $startMem);

            case 'ping':
                return self::buildJsonRpcResponse([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => new \stdClass(),
                ], $startTime, $startMem);

            case 'tools/list':
                return self::buildJsonRpcResponse([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'tools' => [
                            [
                                'name' => 'ping',
                                'description' => 'Ping the BS CMS server to verify connectivity and latency.',
                                'inputSchema' => [
                                    'type' => 'object',
                                    'properties' => new \stdClass(),
                                ],
                            ],
                            [
                                'name' => 'list_posts',
                                'description' => 'List published blog posts from BS CMS with title, slug, and status.',
                                'inputSchema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'limit' => [
                                            'type' => 'integer',
                                            'description' => 'Maximum number of posts to return (1-50).',
                                            'default' => 10,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], $startTime, $startMem);

            case 'tools/call':
                $toolName = (string) ($params['name'] ?? '');
                $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

                if ($toolName === 'ping') {
                    return self::buildJsonRpcResponse([
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => [
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => 'pong',
                                ],
                            ],
                        ],
                    ], $startTime, $startMem);
                }

                if ($toolName === 'list_posts') {
                    $limit = max(1, min(50, (int) ($arguments['limit'] ?? 10)));
                    $posts = self::queryPosts($limit);

                    return self::buildJsonRpcResponse([
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => [
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => json_encode(['posts' => $posts], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                                ],
                            ],
                        ],
                    ], $startTime, $startMem);
                }

                return self::buildJsonRpcResponse([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => [
                        'code' => -32601,
                        'message' => "Method not found: Unknown tool '{$toolName}'",
                    ],
                ], $startTime, $startMem);

            default:
                return self::buildJsonRpcResponse([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => [
                        'code' => -32601,
                        'message' => "Method not found: '{$method}'",
                    ],
                ], $startTime, $startMem);
        }
    }

    /**
     * Query posts from database if configured, or return sample posts.
     *
     * @return list<array{id: string, title: string, slug: string, status: string, published_at: string}>
     */
    private static function queryPosts(int $limit): array
    {
        $configFile = dirname(__DIR__) . '/core/storage/config.php';
        if (is_file($configFile)) {
            try {
                $config = require $configFile;
                if (is_array($config) && isset($config['database']['host'])) {
                    $db = $config['database'];
                    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']};charset=utf8mb4";
                    $pdo = new \PDO($dsn, $db['username'], $db['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                    $prefix = (string) ($db['prefix'] ?? 'bs_');
                    $table = $prefix . 'c_posts';
                    $stmt = $pdo->prepare("SELECT id, slug, status, published_at, data FROM `{$table}` WHERE status = 'published' ORDER BY published_at DESC LIMIT ?");
                    $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
                    $stmt->execute();
                    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                    $result = [];
                    foreach ($rows as $row) {
                        $data = json_decode((string) $row['data'], true);
                        $title = is_array($data) ? (string) ($data['title'] ?? $row['slug']) : (string) $row['slug'];
                        $result[] = [
                            'id' => (string) $row['id'],
                            'title' => $title,
                            'slug' => (string) $row['slug'],
                            'status' => (string) $row['status'],
                            'published_at' => (string) $row['published_at'],
                        ];
                    }
                    if (!empty($result)) {
                        return $result;
                    }
                }
            } catch (\Throwable $e) {
                // Fallback to sample data if DB query fails in spike
            }
        }

        // Default spike dataset
        return [
            [
                'id' => '01JAZZ80000000000000000001',
                'title' => 'Getting Started with BS CMS and MCP',
                'slug' => 'getting-started-with-bs-cms-and-mcp',
                'status' => 'published',
                'published_at' => '2026-10-18 10:00:00',
            ],
            [
                'id' => '01JAZZ80000000000000000002',
                'title' => 'Stateless Streamable HTTP on Shared Hosting',
                'slug' => 'stateless-streamable-http-on-shared-hosting',
                'status' => 'published',
                'published_at' => '2026-10-18 11:30:00',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function buildJsonResponse(int $status, array $payload, float $startTime, int $startMem): array
    {
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $memPeakKb = round((memory_get_peak_usage() - $startMem) / 1024, 2);

        return [
            'status' => $status,
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Mcp-Execution-Time-Ms' => (string) $durationMs,
                'X-Mcp-Memory-Peak-Kb' => (string) $memPeakKb,
            ],
            'body' => (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function buildJsonRpcResponse(array $payload, float $startTime, int $startMem): array
    {
        return self::buildJsonResponse(200, $payload, $startTime, $startMem);
    }

    private static function saveState(): void
    {
        $tempFile = sys_get_temp_dir() . '/bs_mcp_spike_state.json';
        file_put_contents($tempFile, json_encode([
            'clients' => self::$clients,
            'authCodes' => self::$authCodes,
            'accessTokens' => self::$accessTokens,
            'refreshTokens' => self::$refreshTokens,
        ]));
    }

    private static function loadState(): void
    {
        $tempFile = sys_get_temp_dir() . '/bs_mcp_spike_state.json';
        if (is_file($tempFile)) {
            $data = json_decode((string) file_get_contents($tempFile), true);
            if (is_array($data)) {
                self::$clients = $data['clients'] ?? [];
                self::$authCodes = $data['authCodes'] ?? [];
                self::$accessTokens = $data['accessTokens'] ?? [];
                self::$refreshTokens = $data['refreshTokens'] ?? [];
            }
        }
    }
}

// Standalone execution if requested directly over HTTP web server
if (PHP_SAPI !== 'cli' && !defined('BS_CMS_TEST_RUNNER')) {
    $rawInput = file_get_contents('php://input') ?: '';
    $res = McpSpikeHandler::process($_SERVER, $rawInput);
    http_response_code($res['status']);
    foreach ($res['headers'] as $name => $value) {
        header(sprintf('%s: %s', $name, $value), false);
    }
    echo $res['body'];
}
