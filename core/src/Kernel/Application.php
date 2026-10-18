<?php

declare(strict_types=1);

namespace BsCms\Kernel;

use BsCms\Support\Clock;

/**
 * Main application kernel managing lifecycle, base paths, and service registration.
 */
final class Application
{
    public const VERSION = '0.1.0';

    private string $basePath;
    private ?string $storagePath = null;
    private Container $container;
    private Config $config;
    private Environment $environment;
    private bool $booted = false;

    /** @var list<ServiceProvider> */
    private array $providers = [];

    public function __construct(string $basePath, ?string $storagePath = null)
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        $this->storagePath = $storagePath !== null ? rtrim(str_replace('\\', '/', $storagePath), '/') : null;
        $this->container = new Container();

        // Bind core singletons
        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);

        // Load configuration and environment early
        $this->config = Config::load(
            $this->configPath(),
            $this->storagePath('config.php'),
            $this->storagePath('cache/config.php')
        );
        $this->container->instance(Config::class, $this->config);

        $envName = (string) $this->config->get('app.env', 'production');
        $debug = (bool) $this->config->get('app.debug', false);
        $this->environment = new Environment($envName, $debug);
        $this->container->instance(Environment::class, $this->environment);
    }

    public static function create(string $basePath): self
    {
        return new self($basePath);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getEnvironment(): Environment
    {
        return $this->environment;
    }

    public function isInstalled(): bool
    {
        return is_file($this->storagePath('installed.lock'));
    }

    public function basePath(?string $path = null): string
    {
        return $path === null ? $this->basePath : $this->basePath . '/' . ltrim($path, '/');
    }

    public function configPath(?string $path = null): string
    {
        return $this->basePath('config' . ($path ? '/' . ltrim($path, '/') : ''));
    }

    public function storagePath(?string $path = null): string
    {
        $base = $this->storagePath ?? $this->basePath('storage');
        return $path === null ? $base : $base . '/' . ltrim($path, '/');
    }

    public function publicPath(?string $path = null): string
    {
        return $this->basePath('public' . ($path ? '/' . ltrim($path, '/') : ''));
    }

    /**
     * Register a service provider.
     */
    public function register(ServiceProvider $provider): self
    {
        $provider->register($this->container);
        $this->providers[] = $provider;

        if ($this->booted) {
            $provider->boot($this->container);
        }

        return $this;
    }

    /**
     * Boot all registered service providers and apply baseline PHP runtime settings.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Always enforce UTC timezone globally
        date_default_timezone_set(Clock::utcZone()->getName());

        // Error reporting rules
        if ($this->environment->isProduction()) {
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
            ini_set('display_errors', '0');
        } else {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        }

        foreach ($this->providers as $provider) {
            $provider->boot($this->container);
        }

        $this->booted = true;
    }
}
