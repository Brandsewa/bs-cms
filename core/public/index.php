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

// Ultra-fast /health endpoint: returns immediately with security headers and RateLimit headers
// Keeps memory footprint well under budget and file loads minimal
if ($uriPath === '/health') {
    $method = $request->getMethod();
    if ($method !== 'GET') {
        http_response_code(405);
        header('Content-Type: application/problem+json; charset=utf-8');
        header('Allow: GET');
        echo json_encode([
            'type' => 'https://errors.bscms.org/method-not-allowed',
            'title' => 'Method Not Allowed',
            'status' => 405,
            'detail' => "Method '{$method}' is not allowed for '/health'.",
        ]);
        exit;
    }

    $limiter = new RateLimiter(60, 60);
    $headers = [];
    foreach ($request->getHeaders() as $name => $values) {
        $headers[strtolower($name)] = implode(', ', $values);
    }
    $clientIp = $limiter->resolveClientIp($request->getServerParams(), $headers);
    $rl = $limiter->hit("req:{$clientIp}");

    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Robots-Tag: noindex, nofollow');
    header('RateLimit-Limit: ' . $rl['limit']);
    header('RateLimit-Remaining: ' . $rl['remaining']);
    header('RateLimit-Reset: ' . $rl['reset']);

    if (!$rl['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . $rl['retry_after']);
        echo json_encode([
            'type' => 'https://errors.bscms.org/rate-limit-exceeded',
            'title' => 'Too Many Requests',
            'status' => 429,
            'detail' => "Rate limit exceeded. Please retry after {$rl['retry_after']} seconds.",
        ]);
        exit;
    }

    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

// If not installed, route to InstallerController
if (!$isInstalled) {
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

$dbConn = null;
$dbConfig = (array) $config->get('database', []);
if (!empty($dbConfig['database'])) {
    try {
        $dbConn = new Connection($dbConfig);
    } catch (\Throwable $e) {
        // Fallback to memory or APCu
    }
}

$limiter = new RateLimiter(60, 60, [], $dbConn);
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
