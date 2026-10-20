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

// Serve Admin SPA (CLI-server static file handling and client-side route fallback to admin/index.html)
if ($uriPath === '/admin' || str_starts_with($uriPath, '/admin/')) {
    // 1. Canonical redirect: /admin -> /admin/
    if ($uriPath === '/admin') {
        http_response_code(301);
        header('Location: /admin/');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('X-Robots-Tag: noindex, nofollow');
        exit;
    }

    // 2. Real asset files vs missing assets (under /admin/assets/ or files with extensions)
    $hasExtension = (bool) preg_match('/\.[a-zA-Z0-9]+$/', $uriPath);
    $isDirectIndex = ($uriPath === '/admin/index.html');

    if (!$isDirectIndex && (str_starts_with($uriPath, '/admin/assets/') || $hasExtension)) {
        $staticFile = __DIR__ . $uriPath;
        if (is_file($staticFile)) {
            if (php_sapi_name() === 'cli-server') {
                return false;
            }
            $ext = strtolower(pathinfo($staticFile, PATHINFO_EXTENSION));
            $mimes = [
                'js' => 'application/javascript; charset=utf-8',
                'css' => 'text/css; charset=utf-8',
                'svg' => 'image/svg+xml',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'ico' => 'image/x-icon',
                'woff2' => 'font/woff2',
                'woff' => 'font/woff',
            ];
            $contentType = $mimes[$ext] ?? 'application/octet-stream';
            http_response_code(200);
            header("Content-Type: {$contentType}");
            header('X-Content-Type-Options: nosniff');
            if (str_starts_with($uriPath, '/admin/assets/')) {
                header('Cache-Control: public, max-age=31536000, immutable');
            } else {
                header('Cache-Control: no-cache');
            }
            readfile($staticFile);
            exit;
        }

        // Missing asset: return 404, never SPA index
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-cache');
        echo "Asset not found: " . htmlspecialchars($uriPath, ENT_QUOTES, 'UTF-8');
        exit;
    }

    // 3. Extension-less client routes (e.g. /admin/, /admin/entries/posts, /admin/index.html)
    // Serve the SPA shell index.html with full security headers
    $adminIndex = __DIR__ . '/admin/index.html';
    if (is_file($adminIndex)) {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-cache');
        readfile($adminIndex);
        exit;
    }
}

// If not installed, route to InstallerController (except /health when testing before install if needed, or route everything before install to installer)
if (!$isInstalled) {
    // If /health is pinged before install, handle liveness and reject non-GET with 405
    if ($request->getUri()->getPath() === '/health') {
        if ($request->getMethod() !== 'GET') {
            http_response_code(405);
            header('Content-Type: application/problem+json; charset=utf-8');
            header('Cache-Control: no-store');
            header('Allow: GET');
            echo json_encode([
                'type' => 'https://errors.bscms.org/method-not-allowed',
                'title' => 'Method Not Allowed',
                'status' => 405,
                'detail' => "Method '" . $request->getMethod() . "' is not allowed for '/health'.",
            ]);
            exit;
        }
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

$app->register(new \BsCms\Kernel\CoreServiceProvider($app));
$app->boot();

// Setup routes
$routes = new RouteCollection();
$router = new Router($routes);

// Register all API routes from controllers resolved from container
\BsCms\Routing\ApiRoutes::register(
    $routes,
    $container->get(\BsCms\Http\Controllers\AuthController::class),
    $container->get(\BsCms\Http\Controllers\MeController::class),
    $container->get(\BsCms\Http\Controllers\UsersController::class),
    $container->get(\BsCms\Http\Controllers\RolesController::class),
    $container->get(\BsCms\Http\Controllers\TokensController::class),
    $container->get(\BsCms\Http\Controllers\AuditController::class),
    $container->get(\BsCms\Http\Controllers\EmailSettingsController::class),
    $container->get(\BsCms\Http\Controllers\NotificationsController::class),
    $container->get(\BsCms\Http\Controllers\CollectionsController::class),
    $container->get(\BsCms\Http\Controllers\EntriesController::class),
    $container->get(\BsCms\Http\Controllers\BulkEntriesController::class),
    $container->get(\BsCms\Http\Controllers\SingletonsController::class),
    $container->get(\BsCms\Http\Controllers\SavedViewsController::class),
    $container->get(\BsCms\Http\Controllers\JobsController::class),
    $container->get(\BsCms\Http\Controllers\WebhooksController::class),
    $container->get(\BsCms\Http\Controllers\PublicEntriesController::class),
    $container->get(\BsCms\Http\Controllers\OpenApiController::class),
    $container->get(\BsCms\Http\Controllers\TaxonomiesController::class),
    $container->get(\BsCms\Http\Controllers\MediaController::class),
    $container->get(\BsCms\Http\Controllers\MediaFoldersController::class),
    $container->get(\BsCms\Http\Controllers\RedirectsController::class),
    $container->get(\BsCms\Http\Controllers\PublicSeoController::class),
    $container->get(\BsCms\Http\Controllers\SiteHealthController::class),
    $container->get(\BsCms\Mcp\McpController::class),
    $container->get(\BsCms\OAuth\OAuthController::class),
    $container->get(\BsCms\Http\Controllers\PendingChangesController::class),
    $container->get(\BsCms\Http\Controllers\DashboardController::class),
    $container->get(\BsCms\Http\Controllers\AiController::class)
);

// Build middleware pipeline
$kernel = new Kernel($router, __DIR__ . '/admin/index.html');
$fileLogger = new \BsCms\Log\FileLogger($storageDir . '/logs/app.log');

// 1. Request ID first so all subsequent middlewares and handlers have request_id
$kernel->addMiddleware(new RequestIdMiddleware());
$kernel->addMiddleware(new ErrorHandlerMiddleware($env, $fileLogger));
$kernel->addMiddleware(new SecurityHeadersMiddleware());
$kernel->addMiddleware(new HttpsEnforcerMiddleware($env));
$kernel->addMiddleware(new MaintenanceModeMiddleware($storageDir));
$kernel->addMiddleware(new BodyLimitMiddleware(2 * 1024 * 1024));
$kernel->addMiddleware(new JsonBodyMiddleware());
$kernel->addMiddleware(new \BsCms\Http\Middleware\AiKillSwitchMiddleware(
    $container->get(\BsCms\Ai\AiKillSwitchGuard::class)
));

// Phase 2 Auth Middleware pipeline: Session -> Authenticate -> RateLimit -> CSRF
/** @var Connection $db */
$db = $container->get(Connection::class);
/** @var \BsCms\Auth\SessionManager $sessionManager */
$sessionManager = $container->get(\BsCms\Auth\SessionManager::class);
/** @var \BsCms\Auth\ApiTokenService $apiTokenService */
$apiTokenService = $container->get(\BsCms\Auth\ApiTokenService::class);

$kernel->addMiddleware(new \BsCms\Http\Middleware\SessionMiddleware($sessionManager, $db));
$kernel->addMiddleware(new \BsCms\Http\Middleware\AuthenticateMiddleware(
    $apiTokenService,
    $container->get(\BsCms\OAuth\OAuthController::class)
));

$limiter = new RateLimiter(60, 60, [], function () use ($container): ?Connection {
    try {
        return $container->get(Connection::class);
    } catch (\Throwable $e) {
        return null;
    }
});
$kernel->addMiddleware(new RateLimitMiddleware($limiter));

$adminOrigin = (string) $config->get('app.url', '');
$kernel->addMiddleware(new \BsCms\Http\Middleware\CsrfMiddleware($adminOrigin !== '' ? $adminOrigin : null));

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
