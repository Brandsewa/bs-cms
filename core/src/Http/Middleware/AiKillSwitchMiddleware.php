<?php

declare(strict_types=1);

namespace BsCms\Http\Middleware;

use BsCms\Ai\AiKillSwitchGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * AiKillSwitchMiddleware: Intercepts MCP and OAuth endpoints when AI access is disabled.
 */
final class AiKillSwitchMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AiKillSwitchGuard $guard
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $intercepted = $this->guard->checkRequest($request);
        if ($intercepted !== null) {
            return $intercepted;
        }

        return $handler->handle($request);
    }
}
