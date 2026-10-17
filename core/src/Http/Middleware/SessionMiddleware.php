<?php

declare(strict_types=1);

namespace BsCms\Http\Middleware;

use BsCms\Auth\Actor;
use BsCms\Auth\SessionManager;
use BsCms\Database\Connection;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves session cookie, verifies session validity, and populates session and actor request attributes.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionManager $sessionManager,
        private readonly Connection $connection
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var array<string, string> $cookies */
        $cookies = $request->getCookieParams();
        $token = $this->sessionManager->extractToken($cookies);

        if ($token !== null) {
            $session = $this->sessionManager->findValidSession($token);
            if ($session !== null) {
                $userId = (string) $session['user_id'];
                $rolesTable = $this->connection->prefixTable('user_roles');
                $stmt = $this->connection->getPdo()->prepare("SELECT `role_id` FROM `{$rolesTable}` WHERE `user_id` = :user_id");
                $stmt->execute(['user_id' => $userId]);
                /** @var list<string> $roleIds */
                $roleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

                $actor = Actor::user($userId, (string) $session['name'], $roleIds);

                $request = $request
                    ->withAttribute('session', $session)
                    ->withAttribute('actor', $actor);
            }
        }

        return $handler->handle($request);
    }
}
