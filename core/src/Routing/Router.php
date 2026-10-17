<?php

declare(strict_types=1);

namespace BsCms\Routing;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

/**
 * FastRoute wrapper with route caching support.
 */
final class Router
{
    private RouteCollection $routes;
    private ?Dispatcher $dispatcher = null;

    public function __construct(RouteCollection $routes)
    {
        $this->routes = $routes;
    }

    /**
     * Dispatch an incoming HTTP method and URI.
     *
     * @return array{status: int, handler?: callable|string, params?: array<string, string>, allowed_methods?: list<string>}
     */
    public function dispatch(string $method, string $uri): array
    {
        if ($this->dispatcher === null) {
            $this->dispatcher = simpleDispatcher(function (RouteCollector $r) {
                foreach ($this->routes->getRoutes() as $route) {
                    $r->addRoute($route->getMethod(), $route->getPattern(), $route->getHandler());
                }
            });
        }

        // Strip query string (?foo=bar) and decode URI
        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }
        $uri = rawurldecode($uri);

        $routeInfo = $this->dispatcher->dispatch(strtoupper($method), $uri);

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                return ['status' => 404];

            case Dispatcher::METHOD_NOT_ALLOWED:
                /** @var list<string> $allowed */
                $allowed = $routeInfo[1];
                return ['status' => 405, 'allowed_methods' => $allowed];

            case Dispatcher::FOUND:
                /** @var callable|string $handler */
                $handler = $routeInfo[1];
                /** @var array<string, string> $vars */
                $vars = $routeInfo[2];
                return [
                    'status' => 200,
                    'handler' => $handler,
                    'params' => $vars,
                ];

            default:
                return ['status' => 404];
        }
    }
}
