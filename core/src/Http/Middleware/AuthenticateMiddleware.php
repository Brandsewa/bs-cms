<?php

declare(strict_types=1);

namespace BsCms\Http\Middleware;

use BsCms\Auth\Actor;
use BsCms\Auth\ApiTokenService;
use BsCms\Http\ProblemDetails;
use BsCms\OAuth\OAuthController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * AuthenticateMiddleware:
 * 1. Checks for Authorization: Bearer <token> and authenticates API tokens/OAuth actors.
 * 2. Allows unauthenticated access to public routes (health, installer, public auth endpoints).
 * 3. Enforces authentication on all protected routes (rejecting unauthenticated requests with 401 Problem Details).
 */
final class AuthenticateMiddleware implements MiddlewareInterface
{
    /** @var list<array{method: string, regex: string}> */
    private array $publicRoutes = [];

    public function __construct(
        private readonly ?ApiTokenService $tokenService = null,
        private readonly ?OAuthController $oauthController = null,
        private readonly ?\BsCms\Publishing\BuildTokenService $buildTokenService = null
    ) {
        $this->definePublicRoutes();
    }

    private function definePublicRoutes(): void
    {
        // System and Installer
        $this->addPublic('GET', '#^/health$#');
        $this->addPublic('GET', '#^/install(/.*)?$#');
        $this->addPublic('POST', '#^/install(/.*)?$#');

        // Unauthenticated auth endpoints
        $this->addPublic('POST', '#^/api/v1/auth/login$#');
        $this->addPublic('GET', '#^/api/v1/auth/session$#');
        $this->addPublic('HEAD', '#^/api/v1/auth/session$#');
        $this->addPublic('POST', '#^/api/v1/auth/2fa/challenge$#');
        $this->addPublic('POST', '#^/api/v1/auth/password/forgot$#');
        $this->addPublic('POST', '#^/api/v1/auth/password/reset$#');
        $this->addPublic('GET', '#^/api/v1/auth/invitations/[^/]+$#');
        $this->addPublic('POST', '#^/api/v1/auth/invitations/[^/]+/accept$#');
        $this->addPublic('POST', '#^/api/v1/auth/email/verify$#');

        // Public API & docs
        $this->addPublic('GET', '#^/api/v1/public(/.*)?$#');
        $this->addPublic('HEAD', '#^/api/v1/public(/.*)?$#');
        $this->addPublic('GET', '#^/api/v1/openapi\.json$#');

        // MCP and OAuth endpoints (MCP enforces custom 401 with WWW-Authenticate header)
        $this->addPublic('GET', '#^/\.well-known/oauth-protected-resource$#');
        $this->addPublic('OPTIONS', '#^/\.well-known/oauth-protected-resource$#');
        $this->addPublic('GET', '#^/\.well-known/oauth-authorization-server$#');
        $this->addPublic('OPTIONS', '#^/\.well-known/oauth-authorization-server$#');
        $this->addPublic('POST', '#^/oauth/register$#');
        $this->addPublic('OPTIONS', '#^/oauth/register$#');
        $this->addPublic('GET', '#^/oauth/authorize$#');
        $this->addPublic('POST', '#^/oauth/authorize$#');
        $this->addPublic('POST', '#^/oauth/token$#');
        $this->addPublic('OPTIONS', '#^/oauth/token$#');
        $this->addPublic('POST', '#^/oauth/revoke$#');
        $this->addPublic('GET', '#^/mcp$#');
        $this->addPublic('POST', '#^/mcp$#');
        $this->addPublic('OPTIONS', '#^/mcp$#');

        // Publishing status callback endpoint (authenticates via one-time callback token)
        $this->addPublic('POST', '#^/api/v1/deployments/[^/]+/status$#');
    }

    private function addPublic(string $method, string $regex): void
    {
        $this->publicRoutes[] = ['method' => $method, 'regex' => $regex];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $normalizedPath = rtrim($path, '/');
        if ($normalizedPath === '') {
            $normalizedPath = '/';
        }
        $method = strtoupper($request->getMethod());
        $actor = $request->getAttribute('actor');

        // 1. If not authenticated via session, check Authorization: Bearer <token>
        if (($actor === null || !($actor instanceof Actor) || $actor->type === Actor::TYPE_ANONYMOUS) && ($this->tokenService !== null || $this->buildTokenService !== null)) {
            $authHeader = $request->getHeaderLine('Authorization');
            if (str_starts_with($authHeader, 'Bearer ')) {
                $plainToken = trim(substr($authHeader, 7));
                if ($plainToken !== '') {
                    // Check build token authentication first if token begins with bsbuild_
                    if (str_starts_with($plainToken, 'bsbuild_') && $this->buildTokenService !== null) {
                        $buildRow = $this->buildTokenService->authenticate($plainToken);
                        if ($buildRow !== null) {
                            $tokenActor = Actor::buildToken(
                                (string) $buildRow['id'],
                                (string) $buildRow['name']
                            );
                            $request = $request
                                ->withAttribute('actor', $tokenActor)
                                ->withAttribute('build_token', $buildRow);
                            $actor = $tokenActor;
                        } else {
                            return ProblemDetails::create(
                                401,
                                'Unauthorized',
                                'Invalid or revoked build token.',
                                'https://errors.bscms.org/unauthorized'
                            )->toResponse()->withHeader('Cache-Control', 'no-store');
                        }
                    } elseif ($this->tokenService !== null) {
                        $serverParams = $request->getServerParams();
                        /** @var string|null $clientIp */
                        $clientIp = isset($serverParams['REMOTE_ADDR']) && is_string($serverParams['REMOTE_ADDR']) ? $serverParams['REMOTE_ADDR'] : null;

                        $tokenData = $this->tokenService->authenticate($plainToken, $clientIp);
                        if ($tokenData !== null) {
                            $userId = (string) $tokenData['user_id'];
                            /** @var list<string> $roleIds */
                            $roleIds = !empty($tokenData['effective_role_ids']) ? (array) $tokenData['effective_role_ids'] : (!empty($tokenData['role_id']) ? [(string) $tokenData['role_id']] : []);
                            /** @var list<string> $scopes */
                            $scopes = !empty($tokenData['scopes']) && is_array($tokenData['scopes']) ? array_values($tokenData['scopes']) : ['*'];
                            $aiMode = (string) ($tokenData['ai_mode'] ?? Actor::AI_MODE_DRAFT_ONLY);

                            $tokenActor = Actor::apiToken(
                                (string) $tokenData['id'],
                                (string) $tokenData['name'],
                                $userId,
                                $roleIds,
                                $aiMode,
                                $scopes
                            );

                            $request = $request
                                ->withAttribute('actor', $tokenActor)
                                ->withAttribute('api_token', $tokenData);
                            $actor = $tokenActor;
                        } elseif ($this->oauthController !== null) {
                            $oauthData = $this->oauthController->verifyAccessToken($plainToken);
                            if (!empty($oauthData['valid'])) {
                                /** @var list<string> $userRoleIds */
                                $userRoleIds = (array) ($oauthData['user_role_ids'] ?? ($oauthData['user_roles'] ?? []));
                                /** @var list<string> $scopes */
                                $scopes = (array) ($oauthData['scopes'] ?? ['mcp:read', 'mcp:write']);
                                $tokenActor = Actor::oauth(
                                    (string) ($oauthData['grant_id'] ?? ''),
                                    (string) ($oauthData['client_name'] ?? 'OAuth Client'),
                                    (string) ($oauthData['user_id'] ?? ''),
                                    (string) ($oauthData['grant_role_id'] ?? ($oauthData['role_ids'][0] ?? '')),
                                    $userRoleIds,
                                    (string) ($oauthData['ai_mode'] ?? 'draft_only'),
                                    $scopes,
                                    (string) ($oauthData['user_name'] ?? 'User')
                                );

                                $request = $request
                                    ->withAttribute('actor', $tokenActor)
                                    ->withAttribute('oauth_data', $oauthData);
                                $actor = $tokenActor;
                            } elseif (str_starts_with($path, '/api/v1/') && !$this->isPublicRoute($method, $normalizedPath)) {
                                return ProblemDetails::create(
                                    401,
                                    'Unauthorized',
                                    'Invalid or expired API token.',
                                    'https://errors.bscms.org/unauthorized'
                                )->toResponse()->withHeader('Cache-Control', 'no-store');
                            }
                        } elseif (str_starts_with($path, '/api/v1/') && !$this->isPublicRoute($method, $normalizedPath)) {
                            return ProblemDetails::create(
                                401,
                                'Unauthorized',
                                'Invalid or expired API token.',
                                'https://errors.bscms.org/unauthorized'
                            )->toResponse()->withHeader('Cache-Control', 'no-store');
                        }
                    }
                }
            }
        }

        // 2. Non-API endpoints (e.g. /admin SPA, /mcp, /oauth/*) pass through
        if (!str_starts_with($path, '/api/v1/')) {
            return $handler->handle($request);
        }

        // 3. Public API endpoints pass through
        if ($this->isPublicRoute($method, $normalizedPath)) {
            return $handler->handle($request);
        }

        // 3b. Build tokens are strictly restricted to read-only access for public content
        if ($actor instanceof Actor && $actor->isBuildToken()) {
            if ($method !== 'GET' && $method !== 'HEAD') {
                return ProblemDetails::create(
                    403,
                    'Forbidden',
                    'Build tokens only have read-only access.',
                    'https://errors.bscms.org/forbidden'
                )->toResponse()->withHeader('Cache-Control', 'no-store');
            }

            // Only allow public endpoints, build endpoints, or schema manifests
            $allowedBuildPaths = [
                '#^/api/v1/public/.*$#',
                '#^/api/v1/openapi\.json$#',
            ];
            $isAllowed = false;
            foreach ($allowedBuildPaths as $pattern) {
                if (preg_match($pattern, $normalizedPath) === 1) {
                    $isAllowed = true;
                    break;
                }
            }

            if (!$isAllowed) {
                return ProblemDetails::create(
                    403,
                    'Forbidden',
                    'Build tokens cannot access administrative, draft, or internal endpoints.',
                    'https://errors.bscms.org/forbidden'
                )->toResponse()->withHeader('Cache-Control', 'no-store');
            }
        }

        // 4. Protected API endpoints require an authenticated actor
        if ($actor === null || !($actor instanceof Actor) || $actor->type === Actor::TYPE_ANONYMOUS) {
            $requestId = (string) $request->getAttribute('request_id', 'unknown');
            return ProblemDetails::create(
                401,
                'Unauthorized',
                'Authentication is required to access this resource.',
                'https://errors.bscms.org/unauthorized',
                $requestId
            )->toResponse()->withHeader('Cache-Control', 'no-store');
        }

        return $handler->handle($request);
    }

    private function isPublicRoute(string $method, string $path): bool
    {
        foreach ($this->publicRoutes as $route) {
            if ($route['method'] === $method && preg_match($route['regex'], $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
