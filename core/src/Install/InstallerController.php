<?php

declare(strict_types=1);

namespace BsCms\Install;

use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Security\Crypto;
use BsCms\Security\HttpClient;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Minimal server-rendered HTML installer controller (no JS framework).
 */
final class InstallerController
{
    private string $rootDir;
    private string $storageDir;

    public function __construct(string $rootDir, string $storageDir)
    {
        $this->rootDir = rtrim(str_replace('\\', '/', $rootDir), '/');
        $this->storageDir = rtrim(str_replace('\\', '/', $storageDir), '/');
    }

    public function isInstalled(): bool
    {
        return is_file($this->storageDir . '/installed.lock');
    }

    /**
     * Dispatch installer steps.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->isInstalled()) {
            $resp = new Response(404, ['Content-Type' => 'text/html; charset=utf-8']);
            $resp->getBody()->write('<h1>404 Not Found</h1><p>BS CMS is already installed.</p>');
            return $resp;
        }

        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        if ($path === '/install' || $path === '/install/' || $path === '/install/requirements') {
            return $this->showRequirements($request);
        }

        if ($path === '/install/probe' && $method === 'POST') {
            return $this->runPathProbe($request);
        }

        if ($path === '/install/database' && $method === 'POST') {
            return $this->processDatabase($request);
        }

        if ($path === '/install/execute' && $method === 'POST') {
            return $this->executeInstallation($request);
        }

        $resp = new Response(404, ['Content-Type' => 'text/html; charset=utf-8']);
        $resp->getBody()->write('<h1>404 Not Found</h1>');
        return $resp;
    }

    public function showRequirements(ServerRequestInterface $request): ResponseInterface
    {
        $isHttps = $request->getUri()->getScheme() === 'https';
        $checks = Requirements::check($this->storageDir, $isHttps);
        $cliPhp = Requirements::detectCliPhpBinary();

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>BS CMS Web Installer</title>';
        $html .= '<style>body{font-family:system-ui,sans-serif;max-width:700px;margin:2rem auto;padding:0 1rem;line-height:1.5;}';
        $html .= '.pass{color:#15803d;}.fail{color:#b91c1c;font-weight:bold;}.warn{color:#b45309;}';
        $html .= '.card{border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;margin-bottom:1.5rem;}';
        $html .= 'input,select{width:100%;padding:0.5rem;margin:0.25rem 0 1rem;box-sizing:border-box;}';
        $html .= 'button{background:#2563eb;color:white;padding:0.75rem 1.5rem;border:none;border-radius:4px;cursor:pointer;}';
        $html .= '</style></head><body>';
        $html .= '<h1>BS CMS Setup</h1>';

        $html .= '<div class="card"><h2>1. System Requirements</h2><ul>';
        $html .= "<li>PHP Version: <span class=\"{$checks['php_version']['status']}\">{$checks['php_version']['current']}</span></li>";
        $html .= "<li>Storage Writable: <span class=\"{$checks['storage_writable']['status']}\">" . ($checks['storage_writable']['writable'] ? 'Yes' : 'No') . '</span></li>';

        foreach ($checks['extensions'] as $ext => $res) {
            $statusText = $res['loaded'] ? 'Loaded' : ($res['required'] ? 'Missing (Required)' : 'Missing (Optional)');
            $html .= "<li>Extension {$ext}: <span class=\"{$res['status']}\">{$statusText}</span>";
            if ($res['hint'] !== null) {
                $html .= "<br><small style=\"color:#6b7280;\">{$res['hint']}</small>";
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        $html .= "<h3>CLI PHP Detection</h3><p>Detected PHP binary: <code>{$cliPhp['path']}</code> ({$cliPhp['type']})</p>";
        if ($cliPhp['warning'] !== null) {
            $html .= "<p class=\"warn\"><small>{$cliPhp['warning']}</small></p>";
        }
        $html .= '</div>';

        if (!$checks['can_install']) {
            $html .= '<p class="fail">Please resolve the requirements above to continue installation.</p></body></html>';
            $resp = new Response(200, ['Content-Type' => 'text/html; charset=utf-8']);
            $resp->getBody()->write($html);
            return $resp;
        }

        // Database form
        $html .= '<div class="card"><h2>2. Database Configuration</h2>';
        $html .= '<form method="POST" action="/install/database">';
        $html .= '<label>DB Host: <input name="db_host" value="127.0.0.1" required></label>';
        $html .= '<label>DB Port: <input name="db_port" value="3306" required></label>';
        $html .= '<label>DB Name: <input name="db_name" required></label>';
        $html .= '<label>DB User: <input name="db_user" required></label>';
        $html .= '<label>DB Password: <input type="password" name="db_password"></label>';
        $html .= '<label>Table Prefix: <input name="db_prefix" value="bs_" required></label>';
        $html .= '<label>CLI PHP Binary Override: <input name="cli_php" value="' . htmlspecialchars($cliPhp['path']) . '"></label>';
        $html .= '<button type="submit">Verify Database & Continue</button>';
        $html .= '</form></div>';

        $html .= '</body></html>';

        $resp = new Response(200, ['Content-Type' => 'text/html; charset=utf-8']);
        $resp->getBody()->write($html);
        return $resp;
    }

    public function runPathProbe(ServerRequestInterface $request): ResponseInterface
    {
        $baseUri = (string) $request->getUri()->withPath('');
        $privatePaths = ['/storage/.probe', '/config/app.php', '/vendor/autoload.php', '/.env'];
        $vulnerable = [];

        // Check if private files can be retrieved via HTTP
        $httpClient = new HttpClient(2, 4096, 0);

        foreach ($privatePaths as $path) {
            try {
                $targetUrl = rtrim($baseUri, '/') . $path;
                // Probe only if host is resolvable public or local
                $res = $httpClient->get($targetUrl);
                if ($res['status'] === 200) {
                    $vulnerable[] = $path;
                }
            } catch (Throwable $e) {
                // Connection or SSRF rejection confirms file is not publicly served
            }
        }

        if (!empty($vulnerable)) {
            $msg = 'Private paths are accessible via HTTP: ' . implode(', ', $vulnerable) . '. Please ensure document root is set to public/ (Mode A) or apply Mode B .htaccess.';
            $resp = new Response(400, ['Content-Type' => 'application/json']);
            $resp->getBody()->write(Json::encode(['status' => 'fail', 'message' => $msg]));
            return $resp;
        }

        $resp = new Response(200, ['Content-Type' => 'application/json']);
        $resp->getBody()->write(Json::encode(['status' => 'pass']));
        return $resp;
    }

    public function processDatabase(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string> $body */
        $body = (array) $request->getParsedBody();

        $host = $body['db_host'] ?? '127.0.0.1';
        $port = (int) ($body['db_port'] ?? 3306);
        $dbname = $body['db_name'] ?? '';
        $user = $body['db_user'] ?? '';
        $pass = $body['db_password'] ?? '';
        $prefix = $body['db_prefix'] ?? 'bs_';
        $cliPhp = $body['cli_php'] ?? 'php';

        try {
            $conn = new Connection([
                'host' => $host,
                'port' => $port,
                'database' => $dbname,
                'username' => $user,
                'password' => $pass,
                'prefix' => $prefix,
            ]);

            // Test privileges
            $testTable = $conn->prefixTable('install_test');
            $conn->getPdo()->exec("CREATE TABLE IF NOT EXISTS `{$testTable}` (`id` INT) ENGINE=InnoDB;");
            $conn->getPdo()->exec("DROP TABLE IF EXISTS `{$testTable}`;");
        } catch (Throwable $e) {
            $resp = new Response(400, ['Content-Type' => 'text/html; charset=utf-8']);
            $resp->getBody()->write("<h1>Database Test Failed</h1><p>{$e->getMessage()}</p><p><a href=\"/install\">Back</a></p>");
            return $resp;
        }

        // Render step 3: Execution trigger
        $html = '<!DOCTYPE html><html><head><title>Install BS CMS</title></head><body>';
        $html .= '<h1>Database Connected Successfully</h1>';
        $html .= '<p>Server: ' . htmlspecialchars($conn->getServerType()) . ' ' . htmlspecialchars($conn->getServerVersion()) . '</p>';
        $html .= '<form method="POST" action="/install/execute">';
        $html .= '<input type="hidden" name="db_host" value="' . htmlspecialchars($host) . '">';
        $html .= '<input type="hidden" name="db_port" value="' . htmlspecialchars((string) $port) . '">';
        $html .= '<input type="hidden" name="db_name" value="' . htmlspecialchars($dbname) . '">';
        $html .= '<input type="hidden" name="db_user" value="' . htmlspecialchars($user) . '">';
        $html .= '<input type="hidden" name="db_password" value="' . htmlspecialchars($pass) . '">';
        $html .= '<input type="hidden" name="db_prefix" value="' . htmlspecialchars($prefix) . '">';
        $html .= '<input type="hidden" name="cli_php" value="' . htmlspecialchars($cliPhp) . '">';
        $html .= '<button type="submit">Run Migrations & Finish Installation</button>';
        $html .= '</form></body></html>';

        $resp = new Response(200, ['Content-Type' => 'text/html; charset=utf-8']);
        $resp->getBody()->write($html);
        return $resp;
    }

    public function executeInstallation(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string> $body */
        $body = (array) $request->getParsedBody();

        $config = [
            'database' => [
                'host' => $body['db_host'] ?? '127.0.0.1',
                'port' => (int) ($body['db_port'] ?? 3306),
                'database' => $body['db_name'] ?? '',
                'username' => $body['db_user'] ?? '',
                'password' => $body['db_password'] ?? '',
                'prefix' => $body['db_prefix'] ?? 'bs_',
            ],
            'app' => [
                'key' => Crypto::generateKey(),
                'environment' => 'production',
                'debug' => false,
                'cli_php' => $body['cli_php'] ?? 'php',
            ],
        ];

        // 1. Write storage/config.php
        $configContent = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        file_put_contents($this->storageDir . '/config.php', $configContent);
        @chmod($this->storageDir . '/config.php', 0600);

        // 2. Connect and run migrations
        $conn = new Connection($config['database']);
        $migrator = new Migrator($conn);
        $migrator->registerNamespace('core', $this->rootDir . '/migrations');
        $migrator->migrate();

        // 3. Write installed.lock
        file_put_contents($this->storageDir . '/installed.lock', Clock::sql(Clock::now()));

        // Cron instruction
        $cliPhp = $config['app']['cli_php'];
        $cronLine = "* * * * * {$cliPhp} {$this->rootDir}/bin/bs jobs:run >> /dev/null 2>&1";

        $html = '<!DOCTYPE html><html><head><title>Installation Complete</title></head><body>';
        $html .= '<h1>BS CMS Installed Successfully!</h1>';
        $html .= '<p>Setup is complete. Add the following line to your host cron schedule:</p>';
        $html .= '<pre style="background:#f3f4f6;padding:1rem;border-radius:4px;">' . htmlspecialchars($cronLine) . '</pre>';
        $html .= '<p><a href="/admin">Go to Admin Dashboard</a></p>';
        $html .= '</body></html>';

        $resp = new Response(200, ['Content-Type' => 'text/html; charset=utf-8']);
        $resp->getBody()->write($html);
        return $resp;
    }
}
