<?php

declare(strict_types=1);

// dev/hostinger-sim/seed-users.php: Seeds 5 system role test users into BS CMS database.

require_once '/var/www/vhosts/localhost/core/vendor/autoload.php';

use BsCms\Auth\PasswordHasher;
use BsCms\Database\Connection;
use BsCms\Users\RoleRepository;
use BsCms\Users\UserRepository;

$storageConfig = '/var/www/vhosts/localhost/core/storage/config.php';
if (!file_exists($storageConfig)) {
    $storageConfig = '/var/www/vhosts/localhost/storage/config.php';
}

if (!file_exists($storageConfig)) {
    fwrite(STDERR, "Configuration not found at {$storageConfig}. Install CMS first.\n");
    exit(1);
}

$cfg = require $storageConfig;
$dbConfig = $cfg['database'] ?? [];

$conn = new Connection($dbConfig);
$userRepo = new UserRepository($conn);
$roleRepo = new RoleRepository($conn);
$hasher = new PasswordHasher();

$roles = ['owner', 'admin', 'developer', 'editor', 'author', 'contributor', 'viewer'];
$password = 'TestUser2026!Secure';

echo "=== Seeding 5 System Role Test Users ===\n";

foreach ($roles as $roleHandle) {
    $role = $roleRepo->findByHandle($roleHandle);
    if ($role === null) {
        echo "[WARN] Role '{$roleHandle}' not found in database.\n";
        continue;
    }

    $email = "{$roleHandle}@example.com";
    $name = ucfirst($roleHandle) . " Test User";

    $existing = $userRepo->findByEmail($email);
    if ($existing === null) {
        $userId = $userRepo->create([
            'email' => $email,
            'name' => $name,
            'password_hash' => $hasher->hash($password),
            'status' => 'active',
        ], [$role['id']]);
        echo "[CREATED] User '{$email}' (role: {$roleHandle}, id: {$userId})\n";
    } else {
        $userRepo->update($existing['id'], [
            'password_hash' => $hasher->hash($password),
            'status' => 'active',
            'totp_enabled_at' => null,
            'totp_secret_enc' => null,
        ]);
        echo "[UPDATED] User '{$email}' (role: {$roleHandle}, id: {$existing['id']})\n";
    }
}

echo "=== All 5 test users seeded successfully (Password: {$password}) ===\n";
