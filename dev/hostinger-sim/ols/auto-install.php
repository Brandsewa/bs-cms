<?php

declare(strict_types=1);

// dev/hostinger-sim/ols/auto-install.php: Automates database install & test user setup.

$url = $argv[1] ?? 'http://127.0.0.1';
$siteUrl = $argv[2] ?? $url;
$dbHost = $argv[3] ?? 'db';
$dbPort = $argv[4] ?? '3306';
$dbName = $argv[5] ?? 'bs_cms';
$dbUser = $argv[6] ?? 'bs_user';
$dbPass = $argv[7] ?? 'bs_password';

echo "=== Automating BS CMS Web Installation against {$url} (site_url: {$siteUrl}) ===\n";

function request(string $method, string $targetUrl, array $data = []): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $targetUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($res, 0, $headerSize);
    $body = substr($res, $headerSize);
    curl_close($ch);
    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

// 1. Fetch requirements page to get CSRF token
$step1 = request('GET', "{$url}/install");
if ($step1['status'] !== 200) {
    fwrite(STDERR, "[FAIL] /install returned {$step1['status']}: {$step1['body']}\n");
    exit(1);
}

preg_match('/name="_csrf" value="([^"]+)"/', $step1['body'], $m);
$csrf = $m[1] ?? '';
if ($csrf === '') {
    fwrite(STDERR, "[FAIL] Could not extract CSRF token from /install\n");
    exit(1);
}
echo "[OK] Extracted install CSRF token: {$csrf}\n";

// 2. Submit database settings
$step2 = request('POST', "{$url}/install/database", [
    '_csrf' => $csrf,
    'site_url' => $siteUrl,
    'db_host' => $dbHost,
    'db_port' => $dbPort,
    'db_name' => $dbName,
    'db_user' => $dbUser,
    'db_password' => $dbPass,
    'db_prefix' => 'bs_',
    'cli_php' => 'php',
]);

if ($step2['status'] !== 200) {
    fwrite(STDERR, "[FAIL] /install/database returned {$step2['status']}: {$step2['body']}\n");
    exit(1);
}
echo "[OK] Database connection verified\n";

// 3. Execute installation
$step3 = request('POST', "{$url}/install/execute", [
    '_csrf' => $csrf,
    'owner_name' => 'Release Admin',
    'owner_email' => 'admin_release@example.com',
    'owner_password' => 'AdminPass2026!Secure',
]);

if ($step3['status'] !== 200) {
    fwrite(STDERR, "[FAIL] /install/execute returned {$step3['status']}: {$step3['body']}\n");
    exit(1);
}
echo "[OK] BS CMS installation completed successfully\n";
