<?php

declare(strict_types=1);

// BS CMS Front Controller
// Boots the container, runs middleware pipeline, and handles web installer or application requests.

define('BS_START_TIME', microtime(true));

require_once __DIR__ . '/../vendor/autoload.php';

use BsCms\Database\Connection;
use BsCms\Http\JsonResponse;
use BsCms\Http\Kernel;
use BsCms\Http\Middleware\BodyLimitMiddleware;
use BsCms\Http\Middleware\ErrorHandlerMiddleware;
use BsCms\Http\Middleware\HttpsEnforcerMiddleware;
use BsCms\Http\Middleware\JsonBodyMiddleware;
use BsCms\Http\Middleware\MaintenanceModeMiddleware;
use BsCms\Http\Middleware\RateLimitMiddleware;
use BsCms\Http\Middleware\RequestIdMiddleware;
use BsCms\Http\Middleware\SecurityHeadersMiddleware;
use BsCms\Install\InstallerController;
use BsCms\Jobs\Worker;
use BsCms\Kernel\Application;
use BsCms\Routing\RouteCollection;
use BsCms\Routing\Router;
use BsCms\Security\Crypto;
use BsCms\Security\RateLimiter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

$rootDir = dirname(__DIR__);
$storageDir = $rootDir . '/storage';
$isInstalled = is_file($storageDir . '/installed.lock');

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator(
    $psr17Factory,
    $psr17Factory,
    $psr17Factory,
    $psr17Factory
);
$request = $creator->fromGlobals();

$uriPath = $request->getUri()->getPath();

// If not installed, route to InstallerController (except /health when testing before install if needed, or route everything before install to installer)
if (!$isInstalled) {
    // If /health is pinged before install, allow basic liveness check or route to installer
    if ($request->getUri()->getPath() === '/health' && $request->getMethod() === 'GET') {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-Robots-Tag: noindex, nofollow');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    $installer = new InstallerController($rootDir, $storageDir);
    $response = $installer->handle($request);
    sendResponse($response);
    exit;
}

// Installed: boot application kernel
$app = new Application($rootDir);
$container = $app->getContainer();
$config = $app->getConfig();
$env = $app->getEnvironment();

// Setup routes
$routes = new RouteCollection();
$router = new Router($routes);

// Build middleware pipeline
$kernel = new Kernel($router, __DIR__ . '/admin/index.html');
$kernel->addMiddleware(new ErrorHandlerMiddleware($env));
$kernel->addMiddleware(new RequestIdMiddleware());
$kernel->addMiddleware(new SecurityHeadersMiddleware());
$kernel->addMiddleware(new HttpsEnforcerMiddleware($env));
$kernel->addMiddleware(new MaintenanceModeMiddleware($storageDir));
$kernel->addMiddleware(new BodyLimitMiddleware(2 * 1024 * 1024));
$kernel->addMiddleware(new JsonBodyMiddleware());

$limiter = new RateLimiter(60, 60, [], function () use ($config): ?Connection {
    $dbConfig = (array) $config->get('database', []);
    if (!empty($dbConfig['database'])) {
        try {
            return new Connection($dbConfig);
        } catch (\Throwable $e) {
            return null;
        }
    }
    return null;
});
$kernel->addMiddleware(new RateLimitMiddleware($limiter));

// Handle request
$response = $kernel->handle($request);
sendResponse($response);

// Terminate with lazy background runner hook (max 5 s)
Kernel::terminate(function () use ($container) {
    if ($container->has(Connection::class)) {
        /** @var Connection $db */
        $db = $container->get(Connection::class);
        $worker = new Worker($db, $container);
        $worker->run(5);
    }
});

function sendResponse(\Psr\Http\Message\ResponseInterface $response): void
{
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header(sprintf('%s: %s', $name, $value), false);
        }
    }
    echo (string) $response->getBody();
}
