<?php

declare(strict_types=1);

namespace BsCms\Routing;

/**
 * RouteCollection: collects route definitions.
 */
final class RouteCollection
{
    /** @var list<Route> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable|string $handler): Route
    {
        $route = new Route($method, $pattern, $handler);
        $this->routes[] = $route;
        return $route;
    }

    public function get(string $pattern, callable|string $handler): Route
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|string $handler): Route
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable|string $handler): Route
    {
        return $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, callable|string $handler): Route
    {
        return $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable|string $handler): Route
    {
        return $this->add('DELETE', $pattern, $handler);
    }

    /**
     * @return list<Route>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
