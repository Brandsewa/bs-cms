<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Mcp;

define('BS_CMS_TEST_RUNNER', true);

use BsCms\Spike\McpSpikeHandler;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/spikes/spike-b-mcp.php';

final class McpSpikeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear any previous spike state
        $stateFile = sys_get_temp_dir() . '/bs_mcp_spike_state.json';
        if (is_file($stateFile)) {
            @unlink($stateFile);
        }
    }

    protected function tearDown(): void
    {
        $stateFile = sys_get_temp_dir() . '/bs_mcp_spike_state.json';
        if (is_file($stateFile)) {
            @unlink($stateFile);
        }
        parent::tearDown();
    }

    /**
     * Helper to run McpSpikeHandler and capture headers, status, and output.
     *
     * @param array<string, mixed> $server
     * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
     */
    private function executeHandler(array $server, string $rawBody = ''): array
    {
        $res = McpSpikeHandler::process($server, $rawBody);

        $headers = [];
        foreach ($res['headers'] as $k => $v) {
            $headers[strtolower($k)] = $v;
        }

        $json = json_decode($res['body'], true);

        return [
            'status' => $res['status'],
            'headers' => $headers,
            'body' => $res['body'],
            'json' => $json,
        ];
    }

    public function testProtectedResourceMetadataConformsToRfc9728(): void
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/.well-known/oauth-protected-resource',
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
        ];

        $res = $this->executeHandler($server);

        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertSame('https://example.com/mcp', $res['json']['resource']);
        $this->assertSame(['https://example.com'], $res['json']['authorization_servers']);
    }

    public function testAuthorizationServerMetadataConformsToRfc8414(): void
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/.well-known/oauth-authorization-server',
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
        ];

        $res = $this->executeHandler($server);

        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertSame('https://example.com', $res['json']['issuer']);
        $this->assertSame('https://example.com/oauth/authorize', $res['json']['authorization_endpoint']);
        $this->assertSame('https://example.com/oauth/token', $res['json']['token_endpoint']);
        $this->assertSame('https://example.com/oauth/register', $res['json']['registration_endpoint']);
        $this->assertSame(['S256'], $res['json']['code_challenge_methods_supported']);
        $this->assertSame(['code'], $res['json']['response_types_supported']);
    }

    public function testGetMcpReturns405MethodNotAllowed(): void
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/mcp',
            'HTTP_HOST' => 'example.com',
        ];

        $res = $this->executeHandler($server);

        $this->assertSame(405, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertSame(405, $res['json']['status']);
        $this->assertStringContainsString('POST', $res['json']['detail']);
    }

    public function testUnauthenticatedPostMcpReturns401WithResourceMetadataHeader(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/mcp',
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
        ];

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], JSON_THROW_ON_ERROR);

        $res = $this->executeHandler($server, $body);

        $this->assertSame(401, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertSame(-32000, $res['json']['error']['code']);
        $this->assertArrayHasKey('www-authenticate', $res['headers']);
        $this->assertStringContainsString('resource_metadata="https://example.com/.well-known/oauth-protected-resource"', $res['headers']['www-authenticate']);
    }

    public function testFullOauth2FlowAndPkceExchange(): void
    {
        // 1. Dynamic Client Registration (RFC 7591)
        $regBody = json_encode([
            'client_name' => 'Claude Web Connector',
            'redirect_uris' => ['https://claude.ai/api/mcp/oauth/callback'],
        ], JSON_THROW_ON_ERROR);

        $regRes = $this->executeHandler([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/oauth/register',
            'HTTP_HOST' => 'example.com',
        ], $regBody);

        $this->assertSame(201, $regRes['status']);
        $this->assertIsArray($regRes['json']);
        $clientId = $regRes['json']['client_id'];
        $this->assertStringStartsWith('bs_client_', $clientId);

        // 2. PKCE code challenge generation
        $codeVerifier = bin2hex(random_bytes(32)); // 64 chars
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // 3. Authorization request (reject invalid PKCE)
        $invalidAuthRes = $this->executeHandler([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/oauth/authorize',
            'HTTP_HOST' => 'example.com',
        ], '');
        $this->assertSame(400, $invalidAuthRes['status']);

        // 4. Valid Authorization request
        $_POST = [
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/api/mcp/oauth/callback',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'approved' => '1',
            'state' => 'test-state-123',
        ];

        $authRes = $this->executeHandler([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/oauth/authorize',
            'HTTP_HOST' => 'example.com',
        ], '');

        $this->assertSame(302, $authRes['status']);
        $location = $authRes['headers']['location'] ?? '';
        $this->assertStringContainsString('https://claude.ai/api/mcp/oauth/callback', $location);
        $this->assertStringContainsString('state=test-state-123', $location);

        // Extract code from redirect URL
        $queryStr = parse_url($location, PHP_URL_QUERY);
        parse_str(is_string($queryStr) ? $queryStr : '', $queryParams);
        $code = isset($queryParams['code']) && is_string($queryParams['code']) ? $queryParams['code'] : '';
        $this->assertNotEmpty($code);

        // 5. Token exchange with wrong verifier -> rejected
        $wrongTokenRes = $this->executeHandler([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/oauth/token',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_HOST' => 'example.com',
        ], http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => 'wrong-verifier',
        ]));
        $this->assertSame(400, $wrongTokenRes['status']);
        $this->assertSame('invalid_grant', $wrongTokenRes['json']['error']);

        // Re-authorize to get fresh code (since code is single-use)
        $authRes2 = $this->executeHandler([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/oauth/authorize',
            'HTTP_HOST' => 'example.com',
        ], '');
        $queryStr2 = parse_url($authRes2['headers']['location'], PHP_URL_QUERY);
        parse_str(is_string($queryStr2) ? $queryStr2 : '', $queryParams2);
        $code2 = isset($queryParams2['code']) && is_string($queryParams2['code']) ? $queryParams2['code'] : '';

        // 6. Token exchange with correct verifier -> success
        $tokenRes = $this->executeHandler([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/oauth/token',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_HOST' => 'example.com',
        ], http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code2,
            'code_verifier' => $codeVerifier,
        ]));

        $this->assertSame(200, $tokenRes['status']);
        $this->assertIsArray($tokenRes['json']);
        $accessToken = $tokenRes['json']['access_token'];
        $refreshToken = $tokenRes['json']['refresh_token'];
        $this->assertStringStartsWith('bs_at_', $accessToken);
        $this->assertStringStartsWith('bs_rt_', $refreshToken);
        $this->assertSame(3600, $tokenRes['json']['expires_in']);

        // 7. Refresh token rotation
        $refreshRes = $this->executeHandler([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/oauth/token',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_HOST' => 'example.com',
        ], http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]));

        $this->assertSame(200, $refreshRes['status']);
        $newAccessToken = $refreshRes['json']['access_token'];
        $newRefreshToken = $refreshRes['json']['refresh_token'];
        $this->assertNotSame($accessToken, $newAccessToken);
        $this->assertNotSame($refreshToken, $newRefreshToken);

        // 8. Call MCP with the issued OAuth Access Token
        $mcpRes = $this->executeHandler([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/mcp',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $newAccessToken,
            'HTTP_HOST' => 'example.com',
        ], json_encode([
            'jsonrpc' => '2.0',
            'id' => 42,
            'method' => 'tools/list',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(200, $mcpRes['status']);
        $this->assertIsArray($mcpRes['json']);
        $this->assertSame(42, $mcpRes['json']['id']);
        $this->assertCount(2, $mcpRes['json']['result']['tools']);
    }

    public function testMcpJsonRpcLifecycleAndToolExecution(): void
    {
        $authHeaders = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/mcp',
            'HTTP_AUTHORIZATION' => 'Bearer spike-test-token',
            'HTTP_HOST' => 'example.com',
        ];

        // 1. initialize
        $initRes = $this->executeHandler($authHeaders, json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(200, $initRes['status']);
        $this->assertSame('2024-11-05', $initRes['json']['result']['protocolVersion']);
        $this->assertSame('bs-cms', $initRes['json']['result']['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $initRes['json']['result']['capabilities']);

        // 2. ping
        $pingRes = $this->executeHandler($authHeaders, json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'ping',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(200, $pingRes['status']);
        $this->assertSame(2, $pingRes['json']['id']);

        // 3. tools/list
        $listRes = $this->executeHandler($authHeaders, json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/list',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(200, $listRes['status']);
        $tools = $listRes['json']['result']['tools'];
        $this->assertCount(2, $tools);
        $toolNames = array_column($tools, 'name');
        $this->assertContains('ping', $toolNames);
        $this->assertContains('list_posts', $toolNames);

        // 4. tools/call - ping
        $callPingRes = $this->executeHandler($authHeaders, json_encode([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'ping',
                'arguments' => [],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(200, $callPingRes['status']);
        $this->assertSame('pong', $callPingRes['json']['result']['content'][0]['text']);

        // 5. tools/call - list_posts
        $callPostsRes = $this->executeHandler($authHeaders, json_encode([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_posts',
                'arguments' => ['limit' => 5],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(200, $callPostsRes['status']);
        $postsData = json_decode((string) $callPostsRes['json']['result']['content'][0]['text'], true);
        $this->assertIsArray($postsData);
        $this->assertArrayHasKey('posts', $postsData);
        $this->assertNotEmpty($postsData['posts']);

        // 6. tools/call - unknown tool
        $callUnknownRes = $this->executeHandler($authHeaders, json_encode([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => [
                'name' => 'nonexistent_tool',
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(200, $callUnknownRes['status']);
        $this->assertSame(-32601, $callUnknownRes['json']['error']['code']);
    }

    public function testBenchmarkMcpExecutionAndMemory(): void
    {
        $authHeaders = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/mcp',
            'HTTP_AUTHORIZATION' => 'Bearer spike-test-token',
            'HTTP_HOST' => 'example.com',
        ];

        $toolsListPayload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], JSON_THROW_ON_ERROR);

        $toolsCallPayload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_posts',
                'arguments' => ['limit' => 10],
            ],
        ], JSON_THROW_ON_ERROR);

        $timesList = [];
        $timesCall = [];

        for ($i = 0; $i < 50; $i++) {
            $t0 = microtime(true);
            $resList = $this->executeHandler($authHeaders, $toolsListPayload);
            $timesList[] = (microtime(true) - $t0) * 1000;
            $this->assertSame(200, $resList['status']);

            $t1 = microtime(true);
            $resCall = $this->executeHandler($authHeaders, $toolsCallPayload);
            $timesCall[] = (microtime(true) - $t1) * 1000;
            $this->assertSame(200, $resCall['status']);
        }

        sort($timesList);
        sort($timesCall);

        $p50List = $timesList[(int) (count($timesList) * 0.5)];
        $p95List = $timesList[(int) (count($timesList) * 0.95)];
        $p50Call = $timesCall[(int) (count($timesCall) * 0.5)];
        $p95Call = $timesCall[(int) (count($timesCall) * 0.95)];

        // Assert latency is well within budgets (< 50 ms for in-memory / local execution)
        $this->assertLessThan(50.0, $p50List);
        $this->assertLessThan(100.0, $p95List);
        $this->assertLessThan(50.0, $p50Call);
        $this->assertLessThan(100.0, $p95Call);

        fwrite(STDERR, sprintf("\n[Spike B Benchmark] tools/list: p50=%.3f ms, p95=%.3f ms | tools/call (list_posts): p50=%.3f ms, p95=%.3f ms\n", $p50List, $p95List, $p50Call, $p95Call));
    }
}
