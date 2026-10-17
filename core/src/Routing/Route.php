<?php

declare(strict_types=1);

namespace BsCms\Routing;

/**
 * Route definition holding method, pattern, and handler.
 */
final class Route
{
    private string $method;
    private string $pattern;
    /** @var callable|string */
    private $handler;

    /**
     * @param callable|string $handler
     */
    public function __construct(string $method, string $pattern, callable|string $handler)
    {
        $this->method = strtoupper($method);
        $this->pattern = $pattern;
        $this->handler = $handler;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function getHandler(): callable|string
    {
        return $this->handler;
    }
}
