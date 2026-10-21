<?php

declare(strict_types=1);

$configPath = '/var/www/vhosts/localhost/core/config.php';
$prefix = 'bs_';
$dbHost = 'db';
$dbPort = 3306;
$dbName = 'bs_cms';
$dbUser = 'bs_user';
$dbPass = 'bs_password';

if (file_exists($configPath)) {
    $cfg = require $configPath;
    $db = $cfg['database'] ?? [];
    if (!empty($db['prefix'])) $prefix = $db['prefix'];
    if (!empty($db['host'])) $dbHost = $db['host'];
    if (!empty($db['port'])) $dbPort = (int)$db['port'];
    if (!empty($db['database'])) $dbName = $db['database'];
    if (!empty($db['username'])) $dbUser = $db['username'];
    if (!empty($db['password'])) $dbPass = $db['password'];
}

$pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName}", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$tokenPrefix = bin2hex(random_bytes(4));
$tokenSecret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$token = "bsbuild_{$tokenPrefix}_{$tokenSecret}";
$hash = hash('sha256', $token);

$id = bin2hex(random_bytes(13));
$now = date('Y-m-d H:i:s');
$tableName = "{$prefix}build_tokens";

$stmt = $pdo->prepare("INSERT INTO `{$tableName}` (`id`, `name`, `token_prefix`, `token_hash`, `last_used_at`, `revoked_at`, `created_at`) VALUES (?, ?, ?, ?, NULL, NULL, ?)");
$stmt->execute([$id, 'sim-build-site', $tokenPrefix, $hash, $now]);

echo $token;
