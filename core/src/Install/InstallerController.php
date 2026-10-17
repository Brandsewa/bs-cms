<?php

declare(strict_types=1);

namespace BsCms\Install;

use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Http\ProblemDetails;
use BsCms\Security\Crypto;
use BsCms\Security\HttpClient;
use BsCms\Security\Random;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Minimal server-rendered HTML installer controller.
 * Enforces:
 * - CSRF token generation and validation on every form.
 * - Standard security headers sent on all responses.
 * - HTML-escaping of all values, errors, and exceptions.
 * - Automatic HTTP private-path self-probe before installation (Mode A / Mode B validation).
 * - Writing app key to storage/keys/app.key (referenced by config.php).
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
            return $this->htmlResponse(404, '<h1>404 Not Found</h1><p>BS CMS is already installed.</p>');
        }

        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        if ($path === '/install' || $path === '/install/' || $path === '/install/requirements') {
            return $this->showRequirements($request);
        }

        if ($path === '/install/database' && $method === 'POST') {
            return $this->processDatabase($request);
        }

        if ($path === '/install/execute' && $method === 'POST') {
            return $this->executeInstallation($request);
        }

        return $this->htmlResponse(404, '<h1>404 Not Found</h1>');
    }

    public function showRequirements(ServerRequestInterface $request): ResponseInterface
    {
        $isHttps = $request->getUri()->getScheme() === 'https';
        $checks = Requirements::check($this->storageDir, $isHttps);
        $cliPhp = Requirements::detectCliPhpBinary();
        $csrfToken = $this->getOrCreateCsrfToken();
        $uri = $request->getUri();
        $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'http';
        $host = $uri->getHost() !== '' ? $uri->getHost() : 'localhost';
        $port = $uri->getPort();
        $defaultUrl = $scheme . '://' . $host;
        if ($port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $defaultUrl .= ':' . $port;
        }

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>BS CMS Web Installer</title>';
        $html .= '<style>body{font-family:system-ui,sans-serif;max-width:700px;margin:2rem auto;padding:0 1rem;line-height:1.5;}';
        $html .= '.pass{color:#15803d;}.fail{color:#b91c1c;font-weight:bold;}.warn{color:#b45309;}';
        $html .= '.card{border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;margin-bottom:1.5rem;}';
        $html .= 'input,select{width:100%;padding:0.5rem;margin:0.25rem 0 1rem;box-sizing:border-box;}';
        $html .= 'button{background:#2563eb;color:white;padding:0.75rem 1.5rem;border:none;border-radius:4px;cursor:pointer;}';
        $html .= '</style></head><body>';
        $html .= '<h1>BS CMS Setup</h1>';

        $html .= '<div class="card"><h2>1. System Requirements</h2><ul>';
        $html .= '<li>PHP Version: <span class="' . htmlspecialchars($checks['php_version']['status']) . '">' . htmlspecialchars($checks['php_version']['current']) . '</span></li>';
        $html .= '<li>Storage Writable: <span class="' . htmlspecialchars($checks['storage_writable']['status']) . '">' . ($checks['storage_writable']['writable'] ? 'Yes' : 'No') . '</span></li>';

        foreach ($checks['extensions'] as $ext => $res) {
            $statusText = $res['loaded'] ? 'Loaded' : ($res['required'] ? 'Missing (Required)' : 'Missing (Optional)');
            $html .= '<li>Extension ' . htmlspecialchars($ext) . ': <span class="' . htmlspecialchars($res['status']) . '">' . htmlspecialchars($statusText) . '</span>';
            if ($res['hint'] !== null) {
                $html .= '<br><small style="color:#6b7280;">' . htmlspecialchars($res['hint']) . '</small>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        $html .= '<h3>CLI PHP Detection</h3><p>Detected PHP binary: <code>' . htmlspecialchars($cliPhp['path']) . '</code> (' . htmlspecialchars($cliPhp['type']) . ')</p>';
        if ($cliPhp['warning'] !== null) {
            $html .= '<p class="warn"><small>' . htmlspecialchars($cliPhp['warning']) . '</small></p>';
        }
        $html .= '</div>';

        if (!$checks['can_install']) {
            $html .= '<p class="fail">Please resolve the requirements above to continue installation.</p></body></html>';
            return $this->htmlResponse(200, $html);
        }

        // Database and Site form with CSRF
        $html .= '<div class="card"><h2>2. Site & Database Configuration</h2>';
        $html .= '<form method="POST" action="/install/database">';
        $html .= '<input type="hidden" name="_csrf" value="' . htmlspecialchars($csrfToken) . '">';
        $html .= '<label>Site URL (Admin & API Base): <input name="site_url" value="' . htmlspecialchars($defaultUrl) . '" required></label>';
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

        return $this->htmlResponse(200, $html);
    }

    public function processDatabase(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string> $body */
        $body = (array) $request->getParsedBody();

        // 1. Verify CSRF
        $csrfError = $this->verifyCsrf($body['_csrf'] ?? null);
        if ($csrfError !== null) {
            return $csrfError;
        }

        // 2. Perform automated private-path probe
        $probeResult = $this->probePrivatePaths($request);
        if (!$probeResult['passed']) {
            $errorHtml = '<h1>Security Violation: Private Files Exposed</h1>';
            $errorHtml .= '<p>The automated private-path probe discovered that private files are accessible via HTTP:</p>';
            $errorHtml .= '<ul>';
            foreach ($probeResult['exposed'] as $exposedPath) {
                $errorHtml .= '<li><code>' . htmlspecialchars($exposedPath) . '</code></li>';
            }
            $errorHtml .= '</ul>';
            $errorHtml .= '<p>Installation cannot proceed until private directories are protected. Please point your document root to <code>public/</code> (Mode A) or apply Mode B hardened <code>.htaccess</code>.</p>';
            $errorHtml .= '<p><a href="/install">Back to Setup</a></p>';
            return $this->htmlResponse(403, $errorHtml);
        }

        $host = $body['db_host'] ?? '127.0.0.1';
        $port = (int) ($body['db_port'] ?? 3306);
        $dbname = $body['db_name'] ?? '';
        $user = $body['db_user'] ?? '';
        $pass = $body['db_password'] ?? '';
        $prefix = $body['db_prefix'] ?? 'bs_';
        $cliPhp = $body['cli_php'] ?? 'php';
        $siteUrl = $body['site_url'] ?? 'http://localhost';

        try {
            $conn = new Connection([
                'host' => $host,
                'port' => $port,
                'database' => $dbname,
                'username' => $user,
                'password' => $pass,
                'prefix' => $prefix,
            ]);

            // Test privileges: CREATE and DROP table
            $testTable = $conn->prefixTable('install_test');
            $conn->getPdo()->exec("CREATE TABLE IF NOT EXISTS `{$testTable}` (`id` INT) ENGINE=InnoDB;");
            $conn->getPdo()->exec("DROP TABLE IF EXISTS `{$testTable}`;");
        } catch (Throwable $e) {
            $msg = htmlspecialchars($e->getMessage());
            return $this->htmlResponse(400, "<h1>Database Connection Failed</h1><p>{$msg}</p><p><a href=\"/install\">Back</a></p>");
        }

        // Store credentials server-side in a short-lived temp file (never echo password back to HTML)
        $tmpDir = $this->storageDir . '/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0700, true);
        }
        $stageData = [
            'db_host' => $host,
            'db_port' => $port,
            'db_name' => $dbname,
            'db_user' => $user,
            'db_password' => $pass,
            'db_prefix' => $prefix,
            'cli_php' => $cliPhp,
            'site_url' => $siteUrl,
            'created_at' => time(),
        ];
        file_put_contents($tmpDir . '/install_stage.tmp', Json::encode($stageData), LOCK_EX);
        @chmod($tmpDir . '/install_stage.tmp', 0600);

        $csrfToken = $this->getOrCreateCsrfToken();

        // Step 3: Owner Account creation form without echoing DB password
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Create Owner Account - BS CMS</title>';
        $html .= '<style>body{font-family:system-ui,sans-serif;max-width:700px;margin:2rem auto;padding:0 1rem;line-height:1.5;}';
        $html .= '.card{border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;margin-bottom:1.5rem;}';
        $html .= 'input{width:100%;padding:0.5rem;margin:0.25rem 0 1rem;box-sizing:border-box;}';
        $html .= 'button{background:#2563eb;color:white;padding:0.75rem 1.5rem;border:none;border-radius:4px;cursor:pointer;}';
        $html .= '.pass{color:#15803d;font-weight:bold;}';
        $html .= '</style></head><body>';
        $html .= '<h1>Database Connected &amp; Verified</h1>';
        $html .= '<p>Server: ' . htmlspecialchars($conn->getServerType()) . ' ' . htmlspecialchars($conn->getServerVersion()) . '</p>';
        $html .= '<p>Database: <code>' . htmlspecialchars($dbname) . '</code> (Host: <code>' . htmlspecialchars($host) . '</code>, User: <code>' . htmlspecialchars($user) . '</code>)</p>';
        $html .= '<p>Site URL: <code>' . htmlspecialchars($siteUrl) . '</code></p>';
        $html .= '<p>Private Path Security Probe: <span class="pass">PASSED</span></p>';

        $html .= '<div class="card"><h2>3. Create Owner Account</h2>';
        $html .= '<p>The owner account holds irrevocable system administrator permissions.</p>';
        $html .= '<form method="POST" action="/install/execute">';
        $html .= '<input type="hidden" name="_csrf" value="' . htmlspecialchars($csrfToken) . '">';
        $html .= '<label>Owner Name: <input name="owner_name" value="Administrator" required></label>';
        $html .= '<label>Owner Email: <input type="email" name="owner_email" placeholder="owner@example.com" required></label>';
        $html .= '<label>Owner Password: <input type="password" name="owner_password" id="owner_password" required minlength="12" maxlength="256"></label>';
        $html .= '<div id="strength-meter" style="margin-top:-0.5rem;margin-bottom:1rem;font-size:0.875rem;color:#4b5563;">Password policy: At least 12 characters. Common passwords are blocked.</div>';
        $html .= '<button type="submit">Run Migrations &amp; Finish Installation</button>';
        $html .= '</form>';
        $html .= '<script>';
        $html .= 'const pwd = document.getElementById("owner_password");';
        $html .= 'const meter = document.getElementById("strength-meter");';
        $html .= 'if (pwd && meter) {';
        $html .= '  pwd.addEventListener("input", () => {';
        $html .= '    const val = pwd.value;';
        $html .= '    if (val.length === 0) {';
        $html .= '      meter.textContent = "Password policy: At least 12 characters. Common passwords are blocked.";';
        $html .= '      meter.style.color = "#4b5563";';
        $html .= '    } else if (val.length < 12) {';
        $html .= '      meter.textContent = "Too short (" + val.length + "/12 characters)";';
        $html .= '      meter.style.color = "#b91c1c";';
        $html .= '    } else {';
        $html .= '      meter.textContent = "Length acceptable (" + val.length + " chars). Unique passphrase recommended.";';
        $html .= '      meter.style.color = "#15803d";';
        $html .= '    }';
        $html .= '  });';
        $html .= '}';
        $html .= '</script>';
        $html .= '</div></body></html>';

        return $this->htmlResponse(200, $html);
    }

    public function executeInstallation(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string> $body */
        $body = (array) $request->getParsedBody();

        // 1. Verify CSRF
        $csrfError = $this->verifyCsrf($body['_csrf'] ?? null);
        if ($csrfError !== null) {
            return $csrfError;
        }

        // 2. Perform automated private-path probe again to guarantee safety
        $probeResult = $this->probePrivatePaths($request);
        if (!$probeResult['passed']) {
            return $this->htmlResponse(403, '<h1>Security Probe Failed</h1><p>Private paths are exposed via HTTP. Installation aborted.</p>');
        }

        // Validate owner inputs
        $ownerName = trim($body['owner_name'] ?? '');
        $ownerEmail = trim($body['owner_email'] ?? '');
        $ownerPassword = (string) ($body['owner_password'] ?? '');

        if ($ownerName === '') {
            return ProblemDetails::create(400, 'Bad Request', 'Owner name cannot be empty.')->toResponse();
        }
        if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            return ProblemDetails::create(400, 'Bad Request', 'A valid owner email address is required.')->toResponse();
        }

        $hasher = new \BsCms\Auth\PasswordHasher();
        $policyViolation = $hasher->validatePolicy($ownerPassword);
        if ($policyViolation !== null) {
            return ProblemDetails::create(400, 'Bad Request', $policyViolation)->toResponse();
        }

        // Read staging data from short-lived server-side file
        $stageFile = $this->storageDir . '/tmp/install_stage.tmp';
        if (!is_file($stageFile)) {
            return ProblemDetails::create(
                400,
                'Bad Request',
                'Installation session expired. Please return to /install and configure the database again.'
            )->toResponse();
        }

        /** @var array{db_host: string, db_port: int, db_name: string, db_user: string, db_password: string, db_prefix: string, cli_php: string, site_url: string} $stageData */
        $stageData = Json::decode((string) file_get_contents($stageFile));
        @unlink($stageFile);

        // 3. Generate app key and write to storage/keys/app.key
        $keysDir = $this->storageDir . '/keys';
        if (!is_dir($keysDir)) {
            @mkdir($keysDir, 0700, true);
        }
        $appKey = Crypto::generateKey();
        file_put_contents($keysDir . '/app.key', $appKey);
        @chmod($keysDir . '/app.key', 0600);

        // 4. Write storage/config.php
        $config = [
            'database' => [
                'host' => $stageData['db_host'],
                'port' => (int) $stageData['db_port'],
                'database' => $stageData['db_name'],
                'username' => $stageData['db_user'],
                'password' => $stageData['db_password'],
                'prefix' => $stageData['db_prefix'],
            ],
            'app' => [
                'key_file' => 'keys/app.key',
                'key' => $appKey,
                'url' => $stageData['site_url'],
                'public_url' => $stageData['site_url'],
                'environment' => 'production',
                'debug' => false,
                'cli_php' => $stageData['cli_php'],
            ],
        ];

        $configContent = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        file_put_contents($this->storageDir . '/config.php', $configContent);
        @chmod($this->storageDir . '/config.php', 0600);

        // 5. Connect and run migrations
        $conn = new Connection($config['database']);
        $migrator = new Migrator($conn);
        $migrator->registerNamespace('core', $this->rootDir . '/migrations');
        $migrator->migrate();

        // 6. Create Owner User & Assign Owner Role
        $userRepo = new \BsCms\Users\UserRepository($conn);
        $roleRepo = new \BsCms\Users\RoleRepository($conn);

        $ownerRole = $roleRepo->findByHandle('owner');
        if ($ownerRole === null) {
            throw new \RuntimeException("Seeded 'owner' role not found after migrations.");
        }

        $ownerId = $userRepo->create([
            'email' => $ownerEmail,
            'password_hash' => $hasher->hash($ownerPassword),
            'name' => $ownerName,
            'status' => 'active',
            'email_verified_at' => null, // Email verification skipped until SMTP configured; banner shown
        ], [$ownerRole['id']]);

        // Record audit entry for installation
        $auditLogger = new \BsCms\Audit\AuditLogger($conn);
        $auditLogger->record(
            \BsCms\Auth\Actor::system(),
            'user.created',
            'user',
            "Initial owner account created during installation: {$ownerEmail}",
            null,
            'success',
            null,
            null,
            null,
            $ownerId
        );

        // 7. Write installed.lock
        file_put_contents($this->storageDir . '/installed.lock', Clock::sql(Clock::now()));

        // Clean up install CSRF token
        @unlink($this->storageDir . '/install_csrf.tmp');

        // 8. Show completion screen with cron command and owner confirmation
        $cliPhp = htmlspecialchars($config['app']['cli_php']);
        $cronLine = "* * * * * {$cliPhp} {$this->rootDir}/bin/bs jobs:run >> /dev/null 2>&1";

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Installation Complete</title></head><body>';
        $html .= '<h1>BS CMS Installed Successfully!</h1>';
        $html .= '<p>Owner account <strong>' . htmlspecialchars($ownerEmail) . '</strong> created with role <strong>Owner</strong>.</p>';
        $html .= '<p><em>Note: Email verification is deferred until SMTP is configured in Settings.</em></p>';
        $html .= '<p>Add the following line to your host cron schedule:</p>';
        $html .= '<pre style="background:#f3f4f6;padding:1rem;border-radius:4px;">' . htmlspecialchars($cronLine) . '</pre>';
        $html .= '<p><a href="/admin">Go to Admin Dashboard</a></p>';
        $html .= '</body></html>';

        return $this->htmlResponse(200, $html);
    }

    /**
     * Run HTTP probe checking private paths.
     *
     * @return array{passed: bool, exposed: list<string>}
     */
    public function probePrivatePaths(ServerRequestInterface $request): array
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'http';
        $host = $uri->getHost() !== '' ? $uri->getHost() : '127.0.0.1';
        $port = $uri->getPort();
        $base = $scheme . '://' . $host . ($port !== null ? ':' . $port : '');

        $paths = ['/storage/.probe', '/config/app.php', '/vendor/autoload.php', '/.env'];
        $exposed = [];

        // Write temporary probe file into storage
        $probeFile = $this->storageDir . '/.probe';
        file_put_contents($probeFile, 'bs_cms_probe_test');

        try {
            // Check each path
            $client = new HttpClient(2, 4096, 0);
            foreach ($paths as $path) {
                try {
                    $url = $base . $path;
                    $resp = $client->get($url);
                    if ($resp['status'] === 200) {
                        $exposed[] = $path;
                    }
                } catch (Throwable $e) {
                    // Refused or blocked confirms path is not reachable
                }
            }
        } finally {
            @unlink($probeFile);
        }

        return [
            'passed' => empty($exposed),
            'exposed' => $exposed,
        ];
    }

    private function getOrCreateCsrfToken(): string
    {
        $tokenFile = $this->storageDir . '/install_csrf.tmp';
        if (is_file($tokenFile)) {
            $token = trim((string) file_get_contents($tokenFile));
            if ($token !== '') {
                return $token;
            }
        }

        $token = Random::hex(32);
        file_put_contents($tokenFile, $token);
        return $token;
    }

    private function verifyCsrf(?string $submittedToken): ?ResponseInterface
    {
        $expected = $this->getOrCreateCsrfToken();
        if ($submittedToken === null || !hash_equals($expected, $submittedToken)) {
            return \BsCms\Http\ProblemDetails::create(
                403,
                'Forbidden',
                'Invalid or missing CSRF token. Please refresh the setup page and try again.',
                'https://errors.bscms.org/forbidden'
            )->toResponse();
        }
        return null;
    }

    private function htmlResponse(int $status, string $html): ResponseInterface
    {
        $resp = new Response($status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
        $resp->getBody()->write($html);
        return $resp;
    }
}
