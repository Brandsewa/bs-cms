<?php

declare(strict_types=1);

namespace BsCms\Kernel;

/**
 * Base contract for registering services into the application container.
 */
interface ServiceProvider
{
    /**
     * Register service bindings and factories into the container.
     */
    public function register(Container $container): void;

    /**
     * Optional boot logic after all providers have registered.
     */
    public function boot(Container $container): void;
}
