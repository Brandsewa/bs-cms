<?php

declare(strict_types=1);

namespace BsCms\Ai;

use BsCms\Settings\SettingsRepository;
use BsCms\Support\Json;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * AiKillSwitchGuard: Centralized guard enforcing ai_disabled across all MCP and OAuth paths.
 *
 * Rules:
 * - If ai_disabled is false: all requests proceed unimpeded.
 * - If ai_disabled is true:
 *   - /oauth/revoke: ALWAYS ALLOWED so connected clients can clean up.
 *   - /mcp (all methods, including initialize): rejected with JSON-RPC error -32000 and clear message, no data.
 *   - /oauth/authorize (GET and POST): rejected with HTTP 403 access_denied.
 *   - /oauth/token (authorization_code and refresh_token grants): rejected with HTTP 403 access_denied.
 *   - /oauth/register: rejected with HTTP 403 access_denied.
 */
final class AiKillSwitchGuard
{
    public function __construct(
        private readonly ?SettingsRepository $settings = null
    ) {
    }

    public function isAiDisabled(): bool
    {
        if ($this->settings === null) {
            return false;
        }

        return (bool) $this->settings->get('ai_disabled', false);
    }

    public function checkRequest(ServerRequestInterface $request): ?ResponseInterface
    {
        if (!$this->isAiDisabled()) {
            return null;
        }

        $path = $request->getUri()->getPath();

        // 1. /oauth/revoke must keep working so clients can clean up
        if ($path === '/oauth/revoke') {
            return null;
        }

        // 2. /mcp: all methods (including initialize) return JSON-RPC error -32000
        if ($path === '/mcp' || str_starts_with($path, '/mcp/')) {
            $id = $this->extractJsonRpcId($request);

            return new Response(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Expose-Headers' => 'WWW-Authenticate, Mcp-Session-Id',
                'Cache-Control' => 'no-store',
            ], Json::encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32000,
                    'message' => 'AI access is disabled by administrator.',
                ],
            ]));
        }

        // 3. OAuth endpoints: /oauth/authorize, /oauth/token, /oauth/register
        $blockedOAuth = ['/oauth/authorize', '/oauth/token', '/oauth/register'];
        if (in_array($path, $blockedOAuth, true)) {
            return new Response(403, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'no-store',
            ], Json::encode([
                'error' => 'access_denied',
                'error_description' => 'AI access is disabled by administrator.',
            ]));
        }

        return null;
    }

    private function extractJsonRpcId(ServerRequestInterface $request): mixed
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && array_key_exists('id', $parsed)) {
            return $parsed['id'];
        }

        $body = (string) $request->getBody();
        if ($body !== '') {
            try {
                $data = json_decode($body, true);
                if (is_array($data) && array_key_exists('id', $data)) {
                    return $data['id'];
                }
            } catch (\Throwable) {
                // Ignore parse failures
            }
        }

        return null;
    }
}
