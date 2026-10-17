<?php

declare(strict_types=1);

namespace BsCms\Kernel;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Lightweight, zero-reflection DI container driven exclusively by explicit closure factories.
 */
final class Container
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, bool> */
    private array $singletons = [];

    /** @var array<string, bool> */
    private array $loading = [];

    /**
     * Register a shared singleton service factory.
     *
     * @param string $id
     * @param Closure(self): mixed $factory
     */
    public function singleton(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        $this->singletons[$id] = true;
        unset($this->instances[$id]);
    }

    /**
     * Bind a transient factory (invoked fresh on every call).
     *
     * @param string $id
     * @param Closure(self): mixed $factory
     */
    public function bind(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        $this->singletons[$id] = false;
        unset($this->instances[$id]);
    }

    /**
     * Directly set an existing instance into the container.
     */
    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
        $this->singletons[$id] = true;
    }

    /**
     * Check if a service identifier is registered or instantiated.
     */
    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    /**
     * Resolve a service by identifier.
     *
     * @template T
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new InvalidArgumentException("Service not registered in container: {$id}");
        }

        if (isset($this->loading[$id])) {
            throw new RuntimeException("Circular dependency detected resolving service: {$id}");
        }

        $this->loading[$id] = true;

        try {
            $instance = ($this->factories[$id])($this);

            if ($this->singletons[$id] ?? true) {
                $this->instances[$id] = $instance;
            }

            return $instance;
        } finally {
            unset($this->loading[$id]);
        }
    }

    /**
     * Clear all resolved instances.
     */
    public function flush(): void
    {
        $this->instances = [];
    }
}
