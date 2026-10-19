<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Mcp;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Auth\ApiTokenService;
use BsCms\Auth\SessionManager;
use BsCms\Kernel\Environment;
use BsCms\Database\Connection;
use BsCms\Http\Kernel;
use BsCms\Http\Middleware\AuthenticateMiddleware;
use BsCms\Http\Middleware\CsrfMiddleware;
use BsCms\Http\Middleware\ErrorHandlerMiddleware;
use BsCms\Http\Middleware\JsonBodyMiddleware;
use BsCms\Http\Middleware\RequestIdMiddleware;
use BsCms\Http\Middleware\SessionMiddleware;
use BsCms\Mcp\McpController;
use BsCms\OAuth\OAuthController;
use BsCms\Routing\ApiRoutes;
use BsCms\Routing\RouteCollection;
use BsCms\Routing\Router;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class McpHttpIntegrationTest extends TestCase
{
    private Kernel $kernel;
    private string $stateFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateFile = sys_get_temp_dir() . '/bs_mcp_integ_' . bin2hex(random_bytes(6)) . '.json';

        $oauthController = new OAuthController($this->stateFile);
        $mcpController = new McpController(null, $oauthController, null);

        $routes = new RouteCollection();
        $routes->get('/mcp', [$mcpController, 'handleGet']);
        $routes->post('/mcp', [$mcpController, 'handlePost']);
        $routes->options('/mcp', [$mcpController, 'handleOptions']);

        $routes->get('/.well-known/oauth-protected-resource', [$oauthController, 'protectedResource']);
        $routes->options('/.well-known/oauth-protected-resource', [$oauthController, 'optionsProtectedResource']);
        $routes->get('/.well-known/oauth-authorization-server', [$oauthController, 'authorizationServer']);
        $routes->options('/.well-known/oauth-authorization-server', [$oauthController, 'optionsAuthorizationServer']);
        $routes->post('/oauth/register', [$oauthController, 'register']);
        $routes->options('/oauth/register', [$oauthController, 'optionsRegister']);
        $routes->get('/oauth/authorize', [$oauthController, 'authorizeGet']);
        $routes->post('/oauth/authorize', [$oauthController, 'authorizePost']);
        $routes->post('/oauth/token', [$oauthController, 'token']);
        $routes->options('/oauth/token', [$oauthController, 'optionsToken']);
        $routes->post('/oauth/revoke', [$oauthController, 'revoke']);

        $router = new Router($routes);
        $this->kernel = new Kernel($router);

        $env = new Environment(Environment::TESTING, true);
        $this->kernel->addMiddleware(new RequestIdMiddleware());
        $this->kernel->addMiddleware(new ErrorHandlerMiddleware($env));
        $this->kernel->addMiddleware(new JsonBodyMiddleware());
        $this->kernel->addMiddleware(new AuthenticateMiddleware(null));
        $this->kernel->addMiddleware(new CsrfMiddleware(null));
    }

    protected function tearDown(): void
    {
        if (is_file($this->stateFile)) {
            @unlink($this->stateFile);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|string|null $body
     * @param array<string, mixed> $attributes
     */
    private function request(
        string $method,
        string $uri,
        array $headers = [],
        mixed $body = null,
        array $attributes = []
    ): ResponseInterface {
        $parsedUri = new Uri($uri);
        $serverRequest = new ServerRequest($method, $parsedUri, $headers);

        foreach ($attributes as $key => $value) {
            $serverRequest = $serverRequest->withAttribute($key, $value);
        }

        if ($body !== null) {
            $content = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : (string) $body;
            $stream = fopen('php://memory', 'r+');
            self::assertIsResource($stream);
            fwrite($stream, $content);
            rewind($stream);
            $serverRequest = $serverRequest->withBody(new \Nyholm\Psr7\Stream($stream));
            if (!isset($headers['Content-Type']) && is_array($body)) {
                $serverRequest = $serverRequest->withHeader('Content-Type', 'application/json');
            }
        }

        return $this->kernel->handle($serverRequest);
    }

    public function testProtectedResourceMetadataConformsToRfc9728(): void
    {
        $res = $this->request('GET', 'https://example.com/.well-known/oauth-protected-resource');
        $this->assertSame(200, $res->getStatusCode());

        $body = (string) $res->getBody();
        $json = json_decode($body, true);
        $this->assertIsArray($json);
        $this->assertSame('https://example.com/mcp', $json['resource']);
        $this->assertSame(['https://example.com'], $json['authorization_servers']);
        $this->assertSame('*', $res->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testAuthorizationServerMetadataConformsToRfc8414(): void
    {
        $res = $this->request('GET', 'https://example.com/.well-known/oauth-authorization-server');
        $this->assertSame(200, $res->getStatusCode());

        $body = (string) $res->getBody();
        $json = json_decode($body, true);
        $this->assertIsArray($json);
        $this->assertSame('https://example.com', $json['issuer']);
        $this->assertSame('https://example.com/oauth/authorize', $json['authorization_endpoint']);
        $this->assertSame('https://example.com/oauth/token', $json['token_endpoint']);
        $this->assertSame('https://example.com/oauth/register', $json['registration_endpoint']);
        $this->assertSame(['S256'], $json['code_challenge_methods_supported']);
        $this->assertSame(['code'], $json['response_types_supported']);
    }

    public function testGetMcpReturns405MethodNotAllowed(): void
    {
        $res = $this->request('GET', 'https://example.com/mcp');
        $this->assertSame(405, $res->getStatusCode());
        $this->assertSame('POST', $res->getHeaderLine('Allow'));

        $body = (string) $res->getBody();
        $json = json_decode($body, true);
        $this->assertIsArray($json);
        $this->assertSame(405, $json['status']);
        $this->assertStringContainsString('POST', $json['detail']);
    }

    public function testOptionsMcpReturnsCorsPreflightHeaders(): void
    {
        $res = $this->request('OPTIONS', 'https://example.com/mcp');
        $this->assertSame(204, $res->getStatusCode());
        $this->assertSame('*', $res->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('POST', $res->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('Authorization', $res->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('WWW-Authenticate', $res->getHeaderLine('Access-Control-Expose-Headers'));
        $this->assertStringContainsString('Mcp-Session-Id', $res->getHeaderLine('Access-Control-Expose-Headers'));
    }

    public function testUnauthenticatedPostMcpReturns401WithResourceMetadataHeader(): void
    {
        $res = $this->request('POST', 'https://example.com/mcp', [
            'Content-Type' => 'application/json',
        ], [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $this->assertSame(401, $res->getStatusCode());
        $this->assertStringContainsString(
            'resource_metadata="https://example.com/.well-known/oauth-protected-resource"',
            $res->getHeaderLine('WWW-Authenticate')
        );

        $body = (string) $res->getBody();
        $json = json_decode($body, true);
        $this->assertIsArray($json);
        $this->assertSame(-32000, $json['error']['code']);
    }

    public function testFullOauth2FlowPkceAndReuseDetectionRevokingGrant(): void
    {
        // 1. Dynamic Client Registration (RFC 7591)
        $regRes = $this->request('POST', 'https://example.com/oauth/register', [
            'Content-Type' => 'application/json',
        ], [
            'client_name' => 'Claude Web Connector',
            'redirect_uris' => ['https://claude.ai/api/mcp/oauth/callback'],
        ]);

        $this->assertSame(201, $regRes->getStatusCode());
        $regJson = json_decode((string) $regRes->getBody(), true);
        $this->assertIsArray($regJson);
        $clientId = $regJson['client_id'];
        $this->assertStringStartsWith('bs_client_', $clientId);

        // 2. PKCE code generation
        $codeVerifier = bin2hex(random_bytes(32)); // 64 chars
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $csrfSecret = 'flow_csrf_secret_abc';
        $sessionAttrs = [
            'actor' => Actor::user('usr_admin_flow', 'Admin Flow', ['admin']),
            'session' => [
                'user_id' => 'usr_admin_flow',
                'name' => 'Admin Flow',
                'csrf_secret' => $csrfSecret,
            ],
        ];

        // 3. Authorization request (GET renders consent HTML)
        $consentRes = $this->request('GET', "https://example.com/oauth/authorize?client_id={$clientId}&redirect_uri=https://claude.ai/api/mcp/oauth/callback&code_challenge={$codeChallenge}&code_challenge_method=S256&response_type=code", [], null, $sessionAttrs);
        $this->assertSame(200, $consentRes->getStatusCode());
        $this->assertStringContainsString('Authorize Agent Access', (string) $consentRes->getBody());

        // 4. Submit consent (POST)
        $authRes = $this->request('POST', 'https://example.com/oauth/authorize', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/api/mcp/oauth/callback',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'decision' => 'allow',
            'csrf_token' => $csrfSecret,
            'password' => 'secret-stepup',
            'state' => 'xyz987',
            'ai_mode' => 'draft_only',
        ]), $sessionAttrs);

        $this->assertSame(302, $authRes->getStatusCode());
        $location = $authRes->getHeaderLine('Location');
        $this->assertStringContainsString('https://claude.ai/api/mcp/oauth/callback', $location);
        $this->assertStringContainsString('state=xyz987', $location);

        $query = parse_url($location, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $queryParams);
        $code = isset($queryParams['code']) && is_string($queryParams['code']) ? $queryParams['code'] : '';
        $this->assertNotEmpty($code);

        // 5. Code exchange (wrong verifier fails)
        $wrongTokenRes = $this->request('POST', 'https://example.com/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => 'invalid-verifier',
        ]));
        $this->assertSame(400, $wrongTokenRes->getStatusCode());

        // Re-authorize to get fresh single-use code
        $authRes2 = $this->request('POST', 'https://example.com/oauth/authorize', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/api/mcp/oauth/callback',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'decision' => 'allow',
            'csrf_token' => $csrfSecret,
            'password' => 'secret-stepup',
        ]), $sessionAttrs);
        $location2 = $authRes2->getHeaderLine('Location');
        $query2 = parse_url($location2, PHP_URL_QUERY);
        parse_str(is_string($query2) ? $query2 : '', $queryParams2);
        $code2 = isset($queryParams2['code']) && is_string($queryParams2['code']) ? $queryParams2['code'] : '';

        // Exchange with valid verifier
        $tokenRes = $this->request('POST', 'https://example.com/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code2,
            'code_verifier' => $codeVerifier,
        ]));

        $this->assertSame(200, $tokenRes->getStatusCode());
        $tokenJson = json_decode((string) $tokenRes->getBody(), true);
        $this->assertIsArray($tokenJson);
        $accessToken = $tokenJson['access_token'];
        $refreshToken = $tokenJson['refresh_token'];
        $this->assertStringStartsWith('bs_at_', $accessToken);
        $this->assertStringStartsWith('bs_rt_', $refreshToken);

        // 6. Refresh token rotation (valid exchange)
        $refreshRes = $this->request('POST', 'https://example.com/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]));

        $this->assertSame(200, $refreshRes->getStatusCode());
        $refreshJson = json_decode((string) $refreshRes->getBody(), true);
        $this->assertIsArray($refreshJson);
        $newAccessToken = $refreshJson['access_token'];
        $newRefreshToken = $refreshJson['refresh_token'];
        $this->assertNotSame($accessToken, $newAccessToken);
        $this->assertNotSame($refreshToken, $newRefreshToken);

        // 7. Refresh token REUSE DETECTION -> revokes entire grant!
        $reuseRes = $this->request('POST', 'https://example.com/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken, // old, already rotated refresh token
        ]));

        $this->assertSame(400, $reuseRes->getStatusCode());
        $reuseJson = json_decode((string) $reuseRes->getBody(), true);
        $this->assertIsArray($reuseJson);
        $this->assertSame('invalid_grant', $reuseJson['error']);
        $this->assertStringContainsString('reuse detected', $reuseJson['error_description']);

        // 8. Confirm that because grant was revoked, the latest access token is NOW REJECTED on MCP!
        $mcpRejectedRes = $this->request('POST', 'https://example.com/mcp', [
            'Authorization' => 'Bearer ' . $newAccessToken,
            'Content-Type' => 'application/json',
        ], [
            'jsonrpc' => '2.0',
            'id' => 100,
            'method' => 'tools/list',
        ]);
        $this->assertSame(401, $mcpRejectedRes->getStatusCode());
    }

    public function testProtocolVersionNegotiationAndSessionTracking(): void
    {
        $headers = [
            'Authorization' => 'Bearer bs_tok_test_version_negotiation',
            'Content-Type' => 'application/json',
        ];

        // 1. Client sends 2024-11-05
        $res1 = $this->request('POST', 'https://example.com/mcp', $headers, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05'],
        ]);
        $this->assertSame(200, $res1->getStatusCode());
        $json1 = json_decode((string) $res1->getBody(), true);
        $this->assertSame('2024-11-05', $json1['result']['protocolVersion']);
        $sessionId = $res1->getHeaderLine('Mcp-Session-Id');
        $this->assertNotEmpty($sessionId);

        // 2. Client sends 2025-03-26
        $res2 = $this->request('POST', 'https://example.com/mcp', $headers, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26'],
        ]);
        $this->assertSame(200, $res2->getStatusCode());
        $json2 = json_decode((string) $res2->getBody(), true);
        $this->assertSame('2025-03-26', $json2['result']['protocolVersion']);

        // 3. Client sends 2025-06-18 with existing Mcp-Session-Id
        $res3 = $this->request('POST', 'https://example.com/mcp', array_merge($headers, [
            'Mcp-Session-Id' => $sessionId,
        ]), [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18'],
        ]);
        $this->assertSame(200, $res3->getStatusCode());
        $json3 = json_decode((string) $res3->getBody(), true);
        $this->assertSame('2025-06-18', $json3['result']['protocolVersion']);
        $this->assertSame($sessionId, $res3->getHeaderLine('Mcp-Session-Id'));

        // 4. tools/call with list_posts
        $callRes = $this->request('POST', 'https://example.com/mcp', array_merge($headers, [
            'Mcp-Session-Id' => $sessionId,
        ]), [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_posts',
                'arguments' => ['limit' => 2],
            ],
        ]);
        $this->assertSame(200, $callRes->getStatusCode());
        $callJson = json_decode((string) $callRes->getBody(), true);
        $this->assertIsArray($callJson);
        $content = json_decode((string) $callJson['result']['content'][0]['text'], true);
        $this->assertArrayHasKey('posts', $content);
    }

    public function testUnauthenticatedAuthorizeGetRedirectsOrReturns401(): void
    {
        $uri = 'https://example.com/oauth/authorize?client_id=any&redirect_uri=http://localhost:8080/cb&response_type=code';

        // 1. Browser HTML request -> redirects to /admin/login
        $htmlRes = $this->request('GET', $uri, ['Accept' => 'text/html']);
        $this->assertSame(302, $htmlRes->getStatusCode());
        $location = $htmlRes->getHeaderLine('Location');
        $this->assertStringStartsWith('/admin/login?return_to=', $location);

        // 2. API / JSON request -> returns 401 Unauthorized
        $jsonRes = $this->request('GET', $uri, ['Accept' => 'application/json']);
        $this->assertSame(401, $jsonRes->getStatusCode());
    }

    public function testUnauthenticatedAuthorizePostReturns401(): void
    {
        $res = $this->request('POST', 'https://example.com/oauth/authorize', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => 'any',
            'redirect_uri' => 'http://localhost:8080/cb',
            'response_type' => 'code',
            'decision' => 'allow',
        ]));

        $this->assertSame(401, $res->getStatusCode());
        $json = json_decode((string) $res->getBody(), true);
        $this->assertIsArray($json);
        $this->assertSame('unauthorized', $json['error'] ?? ($json['title'] ?? ''));
    }

    public function testAuthorizePostWithoutCsrfReturns403(): void
    {
        // Register client
        $regRes = $this->request('POST', 'https://example.com/oauth/register', [
            'Content-Type' => 'application/json',
        ], [
            'client_name' => 'Test App',
            'redirect_uris' => ['http://127.0.0.1:8080/callback'],
        ]);
        $this->assertSame(201, $regRes->getStatusCode());
        $regJson = json_decode((string) $regRes->getBody(), true);
        $clientId = $regJson['client_id'];

        $sessionAttrs = [
            'session' => [
                'id' => 'sess_123',
                'user_id' => 'usr_alice',
                'name' => 'Alice Admin',
                'csrf_secret' => 'valid_csrf_token_secret',
                'mfa_passed_at' => null,
            ],
            'actor' => Actor::user('usr_alice', 'Alice Admin', ['editor']),
        ];

        // POST without csrf_token
        $resNoCsrf = $this->request('POST', 'https://example.com/oauth/authorize', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'http://127.0.0.1:8080/callback',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'decision' => 'allow',
            'password' => 'correct-password',
        ]), $sessionAttrs);

        $this->assertSame(403, $resNoCsrf->getStatusCode());

        // POST with wrong csrf_token
        $resWrongCsrf = $this->request('POST', 'https://example.com/oauth/authorize', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'http://127.0.0.1:8080/callback',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'decision' => 'allow',
            'password' => 'correct-password',
            'csrf_token' => 'invalid_csrf_token',
        ]), $sessionAttrs);

        $this->assertSame(403, $resWrongCsrf->getStatusCode());
    }

    public function testAuthorizePostWithStepUpAndUserBinding(): void
    {
        $regRes = $this->request('POST', 'https://example.com/oauth/register', [
            'Content-Type' => 'application/json',
        ], [
            'client_name' => 'Authorized App',
            'redirect_uris' => ['http://127.0.0.1:8080/callback'],
        ]);
        $regJson = json_decode((string) $regRes->getBody(), true);
        $clientId = $regJson['client_id'];

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $sessionAttrs = [
            'session' => [
                'id' => 'sess_456',
                'user_id' => 'usr_bob',
                'name' => 'Bob Editor',
                'csrf_secret' => 'valid_csrf_token_secret',
                'mfa_passed_at' => null,
            ],
            'actor' => Actor::user('usr_bob', 'Bob Editor', ['editor']),
        ];

        // Valid consent submission with CSRF and step-up password
        $authRes = $this->request('POST', 'https://example.com/oauth/authorize', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'http://127.0.0.1:8080/callback',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'decision' => 'allow',
            'csrf_token' => 'valid_csrf_token_secret',
            'password' => 'correct-password',
            'ai_mode' => 'draft_only',
        ]), $sessionAttrs);

        $this->assertSame(302, $authRes->getStatusCode());
        $location = $authRes->getHeaderLine('Location');
        $this->assertStringContainsString('http://127.0.0.1:8080/callback?code=', $location);

        $query = parse_url($location, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $queryParams);
        $rawCode = $queryParams['code'] ?? '';
        $code = is_string($rawCode) ? $rawCode : '';
        $this->assertNotEmpty($code);

        // Exchange code for token
        $tokenRes = $this->request('POST', 'https://example.com/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => 'http://127.0.0.1:8080/callback',
        ]));

        $this->assertSame(200, $tokenRes->getStatusCode());
        $tokenData = json_decode((string) $tokenRes->getBody(), true);
        $accessToken = $tokenData['access_token'];

        // Call MCP with this token -> user is editor, list_posts succeeds
        $mcpRes = $this->request('POST', 'https://example.com/mcp', [
            'Content-Type' => 'application/json',
            'Authorization' => "Bearer {$accessToken}",
        ], [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_posts',
                'arguments' => ['limit' => 2],
            ],
        ]);

        $this->assertSame(200, $mcpRes->getStatusCode());
        $mcpJson = json_decode((string) $mcpRes->getBody(), true);
        $this->assertArrayNotHasKey('error', $mcpJson);
        $this->assertArrayHasKey('result', $mcpJson);
    }

    public function testHardcodedSpikeTokenIsRejected(): void
    {
        $res = $this->request('POST', 'https://example.com/mcp', [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer spike-test-token',
        ], [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $this->assertSame(401, $res->getStatusCode());
        $this->assertStringContainsString('WWW-Authenticate', implode(', ', array_keys($res->getHeaders())));
    }

    public function testRegisterRejectsInvalidAndEvilRedirectUris(): void
    {
        // 1. javascript:alert(1)
        $res1 = $this->request('POST', 'https://example.com/oauth/register', [
            'Content-Type' => 'application/json',
        ], [
            'client_name' => 'Evil JS Client',
            'redirect_uris' => ['javascript:alert(1)'],
        ]);
        $this->assertSame(400, $res1->getStatusCode());
        $json1 = json_decode((string) $res1->getBody(), true);
        $this->assertSame('invalid_redirect_uri', $json1['error']);

        // 2. http:// on non-loopback domain
        $res2 = $this->request('POST', 'https://example.com/oauth/register', [
            'Content-Type' => 'application/json',
        ], [
            'client_name' => 'Insecure HTTP Client',
            'redirect_uris' => ['http://evil.example/cb'],
        ]);
        $this->assertSame(400, $res2->getStatusCode());
        $json2 = json_decode((string) $res2->getBody(), true);
        $this->assertSame('invalid_redirect_uri', $json2['error']);

        // 3. URI with fragment (#)
        $res3 = $this->request('POST', 'https://example.com/oauth/register', [
            'Content-Type' => 'application/json',
        ], [
            'client_name' => 'Fragment Client',
            'redirect_uris' => ['https://evil.example/cb#frag'],
        ]);
        $this->assertSame(400, $res3->getStatusCode());
        $json3 = json_decode((string) $res3->getBody(), true);
        $this->assertSame('invalid_redirect_uri', $json3['error']);

        // 4. Valid URIs are accepted
        $resValid = $this->request('POST', 'https://example.com/oauth/register', [
            'Content-Type' => 'application/json',
        ], [
            'client_name' => 'Valid Client',
            'redirect_uris' => [
                'http://127.0.0.1:8080/cb',
                'http://localhost:3000/callback',
                'https://claude.ai/api/mcp/oauth/callback',
            ],
        ]);
        $this->assertSame(201, $resValid->getStatusCode());
    }

    public function testRegisterEnforcesIpRateLimitCap(): void
    {
        $serverParams = ['REMOTE_ADDR' => '198.51.100.99'];

        // Register 5 times from the same IP (within configured limit of 5 per hour)
        for ($i = 0; $i < 5; ++$i) {
            $parsedUri = new Uri('https://example.com/oauth/register');
            $stream = fopen('php://memory', 'r+');
            self::assertIsResource($stream);
            fwrite($stream, (string) json_encode([
                'client_name' => "Batch Client {$i}",
                'redirect_uris' => ['http://127.0.0.1:8080/cb'],
            ]));
            rewind($stream);
            $req = (new ServerRequest('POST', $parsedUri, ['Content-Type' => 'application/json'], new \Nyholm\Psr7\Stream($stream), '1.1', $serverParams));
            $res = $this->kernel->handle($req);
            $this->assertSame(201, $res->getStatusCode());
        }

        // 6th registration from the same IP MUST return 429
        $parsedUri = new Uri('https://example.com/oauth/register');
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, (string) json_encode([
            'client_name' => 'Overflow Client',
            'redirect_uris' => ['http://127.0.0.1:8080/cb'],
        ]));
        rewind($stream);
        $req = (new ServerRequest('POST', $parsedUri, ['Content-Type' => 'application/json'], new \Nyholm\Psr7\Stream($stream), '1.1', $serverParams));
        $res = $this->kernel->handle($req);

        $this->assertSame(429, $res->getStatusCode());
        $json = json_decode((string) $res->getBody(), true);
        $this->assertSame('rate_limit_exceeded', $json['error']);
    }


    public function testAuthorizeEnforcesExactRedirectUriMatch(): void
    {
        $regRes = $this->request('POST', 'https://example.com/oauth/register', [
            'Content-Type' => 'application/json',
        ], [
            'client_name' => 'Strict Match App',
            'redirect_uris' => ['https://claude.ai/api/mcp/oauth/callback'],
        ]);
        $this->assertSame(201, $regRes->getStatusCode());
        $regJson = json_decode((string) $regRes->getBody(), true);
        $clientId = $regJson['client_id'];

        $sessionAttrs = [
            'session' => [
                'id' => 'sess_strict',
                'user_id' => 'usr_admin',
                'name' => 'Admin User',
                'csrf_secret' => 'csrf_token_secret',
                'mfa_passed_at' => null,
            ],
            'actor' => Actor::user('usr_admin', 'Admin User', ['owner']),
        ];

        // Mismatched / prefix / substring redirect URI MUST be rejected with 400
        $resMismatch = $this->request('POST', 'https://example.com/oauth/authorize', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/api/mcp/oauth/callback/extra',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'decision' => 'allow',
            'csrf_token' => 'csrf_token_secret',
            'password' => 'secret',
        ]), $sessionAttrs);

        $this->assertSame(400, $resMismatch->getStatusCode());
        $json = json_decode((string) $resMismatch->getBody(), true);
        $this->assertSame('invalid_redirect_uri', $json['error']);
    }

    public function testSanitizeReturnToOnlyAcceptsSameOriginAdminPaths(): void
    {
        // Valid /admin paths
        $this->assertSame('/admin', OAuthController::sanitizeReturnTo('/admin'));
        $this->assertSame('/admin/', OAuthController::sanitizeReturnTo('/admin/'));
        $this->assertSame('/admin/dashboard', OAuthController::sanitizeReturnTo('/admin/dashboard'));
        $this->assertSame('/admin/oauth/authorize?client_id=123', OAuthController::sanitizeReturnTo('/admin/oauth/authorize?client_id=123'));
        $this->assertSame('/admin?page=1', OAuthController::sanitizeReturnTo('/admin?page=1'));

        // External URLs -> Fallback to /admin
        $this->assertSame('/admin', OAuthController::sanitizeReturnTo('https://evil.example.com'));
        $this->assertSame('/admin', OAuthController::sanitizeReturnTo('http://evil.example.com/admin'));
        $this->assertSame('/admin', OAuthController::sanitizeReturnTo('//evil.example.com/admin'));
        $this->assertSame('/admin', OAuthController::sanitizeReturnTo('javascript:alert(1)'));
        $this->assertSame('/admin', OAuthController::sanitizeReturnTo('data:text/html,evil'));

        // Non-admin paths -> Fallback to /admin
        $this->assertSame('/admin', OAuthController::sanitizeReturnTo('/'));
        $this->assertSame('/admin', OAuthController::sanitizeReturnTo('/api/v1/posts'));
        $this->assertSame('/admin', OAuthController::sanitizeReturnTo('/public/index.php'));
        $this->assertSame('/admin', OAuthController::sanitizeReturnTo('/administrator'));

        // Path traversal and tricky backslashes -> Fallback to /admin
        $this->assertSame('/admin', OAuthController::sanitizeReturnTo('/admin/..\\evil'));
        $this->assertSame('/admin', OAuthController::sanitizeReturnTo('\\\\evil.com\\admin'));
        $this->assertSame('/admin', OAuthController::sanitizeReturnTo(null));
        $this->assertSame('/admin', OAuthController::sanitizeReturnTo(''));
    }
}
