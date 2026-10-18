<?php

declare(strict_types=1);

namespace BsCms\Http\Middleware;

use BsCms\Auth\Actor;
use BsCms\Auth\ApiTokenService;
use BsCms\Http\ProblemDetails;
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
        private readonly ?ApiTokenService $tokenService = null
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
    }

    private function addPublic(string $method, string $regex): void
    {
        $this->publicRoutes[] = ['method' => $method, 'regex' => $regex];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $actor = $request->getAttribute('actor');

        // 1. If not authenticated via session, check Authorization: Bearer <token>
        if (($actor === null || !($actor instanceof Actor) || $actor->type === Actor::TYPE_ANONYMOUS) && $this->tokenService !== null) {
            $authHeader = $request->getHeaderLine('Authorization');
            if (str_starts_with($authHeader, 'Bearer ')) {
                $plainToken = trim(substr($authHeader, 7));
                if ($plainToken !== '') {
                    $serverParams = $request->getServerParams();
                    /** @var string|null $clientIp */
                    $clientIp = isset($serverParams['REMOTE_ADDR']) && is_string($serverParams['REMOTE_ADDR']) ? $serverParams['REMOTE_ADDR'] : null;

                    $tokenData = $this->tokenService->authenticate($plainToken, $clientIp);
                    if ($tokenData !== null) {
                        $userId = (string) $tokenData['user_id'];
                        $roleIds = !empty($tokenData['role_id']) ? [(string) $tokenData['role_id']] : [];
                        $aiMode = (string) ($tokenData['ai_mode'] ?? Actor::AI_MODE_DRAFT_ONLY);

                        $tokenActor = Actor::apiToken(
                            (string) $tokenData['id'],
                            (string) $tokenData['name'],
                            $userId,
                            $roleIds,
                            $aiMode
                        );

                        $request = $request
                            ->withAttribute('actor', $tokenActor)
                            ->withAttribute('api_token', $tokenData);
                        $actor = $tokenActor;
                    } else {
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

        $path = $request->getUri()->getPath();
        $normalizedPath = rtrim($path, '/');
        if ($normalizedPath === '') {
            $normalizedPath = '/';
        }
        $method = strtoupper($request->getMethod());

        // 2. Non-API endpoints (e.g. /admin SPA) pass through
        if (!str_starts_with($path, '/api/v1/')) {
            return $handler->handle($request);
        }

        // 3. Public API endpoints pass through
        if ($this->isPublicRoute($method, $normalizedPath)) {
            return $handler->handle($request);
        }

        // 4. Protected API endpoints require an authenticated actor
        if ($actor === null || !($actor instanceof Actor) || $actor->type === Actor::TYPE_ANONYMOUS) {
            return ProblemDetails::create(
                401,
                'Unauthorized',
                'Authentication is required to access this resource.',
                'https://errors.bscms.org/unauthorized'
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
