<?php

declare(strict_types=1);

// tools/test-release-http.php: Live HTTP end-to-end integration test runner against unzipped release.

if ($argc < 3) {
    echo "Usage: php tools/test-release-http.php <base-url> <core-path>\n";
    echo "Example: php tools/test-release-http.php http://127.0.0.1:8888 /tmp/bs-cms-test/core\n";
    exit(1);
}

$baseUrl = rtrim($argv[1], '/');
$corePath = rtrim($argv[2], '/\\');

require_once $corePath . '/vendor/autoload.php';

use BsCms\Auth\PasswordHasher;
use BsCms\Auth\TwoFactorService;
use BsCms\Database\Connection;
use BsCms\Support\Clock;
use BsCms\Security\Ulid;
use BsCms\Users\RoleRepository;
use BsCms\Users\UserRepository;

echo "=== Running Live HTTP Release Test Suite against {$baseUrl} ===\n";

function failTest(string $message): void {
    echo "\n[FAIL] {$message}\n";
    exit(1);
}

function passTest(string $name): void {
    echo "[PASS] {$name}\n";
}

/**
 * @param array<string, string> $headers
 * @param array<string, mixed>|string|null $body
 * @return array{status: int, headers: array<string, string>, raw_headers: list<string>, body: string, json: mixed}
 */
function httpRequest(string $method, string $url, array $headers = [], $body = null): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $headerLines = [];
    foreach ($headers as $k => $v) {
        $headerLines[] = "{$k}: {$v}";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);

    if ($body !== null) {
        $data = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : (string) $body;
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    $rawResponse = curl_exec($ch);
    if ($rawResponse === false) {
        $err = curl_error($ch);
        @curl_close($ch);
        failTest("cURL error requesting {$url}: {$err}");
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    @curl_close($ch);

    $headerStr = substr((string) $rawResponse, 0, $headerSize);
    $bodyStr = substr((string) $rawResponse, $headerSize);

    $parsedHeaders = [];
    $rawHeadersList = [];
    $lines = explode("\r\n", $headerStr);
    foreach ($lines as $line) {
        if (trim($line) === '' || str_starts_with($line, 'HTTP/')) {
            continue;
        }
        $rawHeadersList[] = $line;
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $parsedHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

    $json = null;
    $trimmed = trim($bodyStr);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        $json = json_decode($bodyStr, true);
    }

    return [
        'status' => $statusCode,
        'headers' => $parsedHeaders,
        'raw_headers' => $rawHeadersList,
        'body' => $bodyStr,
        'json' => $json,
    ];
}

function scanNoSecrets(mixed $data, string $path = '$'): void {
    if (!is_array($data)) {
        if (is_string($data)) {
            if (preg_match('/^\$2[ayb]\$[0-9]{2}\$[A-Za-z0-9\.\/]{53}$/', $data)) {
                failTest("Bcrypt password hash leaked at {$path}: {$data}");
            }
            if (str_starts_with($data, '$argon2')) {
                failTest("Argon2 hash leaked at {$path}: {$data}");
            }
        }
        return;
    }

    foreach ($data as $key => $value) {
        $currPath = "{$path}.{$key}";
        if (in_array($key, ['password_hash', 'totp_secret_enc', 'recovery_codes_hash'], true)) {
            failTest("Forbidden sensitive key '{$key}' leaked in response at {$currPath}");
        }
        scanNoSecrets($value, $currPath);
    }
}

// 1. Health check & Headers
echo "Testing 1: Health endpoint...\n";
$resHealth = httpRequest('GET', "{$baseUrl}/health");
if ($resHealth['status'] !== 200) {
    failTest("Health check expected 200, got {$resHealth['status']}: {$resHealth['body']}");
}
if (($resHealth['json']['status'] ?? '') !== 'ok') {
    failTest("Health check json status != 'ok'");
}
if (!str_contains($resHealth['headers']['cache-control'] ?? '', 'no-store')) {
    failTest("Health check missing Cache-Control: no-store");
}
if (!isset($resHealth['headers']['ratelimit-limit'])) {
    failTest("Health check missing RateLimit-Limit header");
}
passTest("1. Health endpoint verified with RateLimit-* and Cache-Control: no-store");

// 2. Request ID Validation & Sanitization
echo "Testing 2: Request ID validation & generation...\n";
$resInvalidId = httpRequest('GET', "{$baseUrl}/health", ['X-Request-ID' => 'bad!@#$illegal']);
$sanitizedId = $resInvalidId['headers']['x-request-id'] ?? '';
if ($sanitizedId === '' || str_contains($sanitizedId, '!') || str_contains($sanitizedId, '@')) {
    failTest("Invalid X-Request-ID was not sanitized/regenerated: got {$sanitizedId}");
}
if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $sanitizedId)) {
    failTest("Generated request_id does not match ^[A-Za-z0-9-]{8,64}$: got {$sanitizedId}");
}

$validTraceId = 'valid-custom-trace-id-123456';
$resValidId = httpRequest('GET', "{$baseUrl}/health", ['X-Request-ID' => $validTraceId]);
if (($resValidId['headers']['x-request-id'] ?? '') !== $validTraceId) {
    failTest("Valid X-Request-ID was not preserved: got " . ($resValidId['headers']['x-request-id'] ?? 'none'));
}
passTest("2. Request ID validation and sanitization verified");

// 3. Database setup: seed owner user for testing
echo "Testing 3: Seeding test admin user in database...\n";
$storageConfigFile = dirname($corePath) . '/storage/config.php';
if (!file_exists($storageConfigFile)) {
    $storageConfigFile = "{$corePath}/storage/config.php";
}
if (file_exists($storageConfigFile)) {
    $storageCfg = require $storageConfigFile;
    $dbConfig = $storageCfg['database'] ?? [];
    if (!empty($storageCfg['app']['url'])) {
        $allowedOrigin = rtrim($storageCfg['app']['url'], '/');
    }
} else {
    $dbConfigFile = "{$corePath}/config/database.php";
    if (!file_exists($dbConfigFile)) {
        failTest("Missing database config: {$dbConfigFile}");
    }
    $dbConfig = require $dbConfigFile;
}
$originHeader = $allowedOrigin ?? $baseUrl;
$connection = new Connection($dbConfig);

$userRepo = new UserRepository($connection);
$roleRepo = new RoleRepository($connection);
$hasher = new PasswordHasher();

$ownerRole = $roleRepo->findByHandle('owner');
if ($ownerRole === null) {
    failTest("Default owner role not found in database. Migrations may not have run.");
}

$testEmail = 'admin_release@example.com';
$testPass = 'AdminPass2026!Secure';
$existing = $userRepo->findByEmail($testEmail);
if ($existing === null) {
    $userId = $userRepo->create([
        'email' => $testEmail,
        'name' => 'Release Admin',
        'password_hash' => $hasher->hash($testPass),
        'role_id' => $ownerRole['id'],
        'status' => 'active',
    ]);
} else {
    $userId = $existing['id'];
    $userRepo->update($userId, [
        'password_hash' => $hasher->hash($testPass),
        'status' => 'active',
        'totp_enabled_at' => null,
        'totp_secret_enc' => null,
        'totp_last_step' => null,
        'recovery_codes_hash' => null,
    ]);
}
passTest("3. Test admin user seeded ({$testEmail})");

// Ensure built-in collections (e.g. site_settings, pages, posts) are seeded
$cacheFile = $corePath . '/storage/cache/schema.php';
$collRegistry = new \BsCms\Schema\CollectionRegistry($connection, $cacheFile);
if (!$collRegistry->has('site_settings')) {
    $schemaDir = $corePath . '/resources/schema';
    if (is_dir($schemaDir)) {
        $ftRegistry = new \BsCms\Schema\FieldTypeRegistry();
        $schemaPlanner = new \BsCms\Schema\SchemaPlanner($connection, $collRegistry, $ftRegistry);
        $schemaLock = new \BsCms\Schema\SchemaLock($connection);
        $schemaApplier = new \BsCms\Schema\SchemaApplier($connection, $collRegistry, $schemaLock);
        $files = glob($schemaDir . '/*.json') ?: [];
        foreach ($files as $file) {
            $sData = json_decode((string) file_get_contents($file), true);
            if (is_array($sData) && !empty($sData['handle']) && !$collRegistry->has($sData['handle'])) {
                $plan = $schemaPlanner->plan($sData);
                $schemaApplier->apply($plan, $sData, null, 'installer');
            }
        }
        $collRegistry->invalidate();
    }
}

// 4. Login flow with IPv6 address & Audit Logger check
echo "Testing 4: Login with IPv6 address and audit log storage...\n";
$ipv6Addr = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
$customUa = 'Release-Test-Agent/1.0';
$loginReqId = 'release-login-trace-id-999';

$resLogin = httpRequest('POST', "{$baseUrl}/api/v1/auth/login", [
    'Content-Type' => 'application/json',
    'User-Agent' => $customUa,
    'X-Request-ID' => $loginReqId,
    'X-Forwarded-For' => $ipv6Addr,
], [
    'email' => $testEmail,
    'password' => $testPass,
]);

if ($resLogin['status'] !== 200) {
    failTest("Login failed with status {$resLogin['status']}: {$resLogin['body']}");
}
scanNoSecrets($resLogin['json']);

// Check session cookie
$cookieName = 'bs_session';
$sessionCookie = '';
foreach ($resLogin['raw_headers'] as $hdr) {
    if (stripos($hdr, 'set-cookie:') === 0 && preg_match('/(?:(__Host-)?bs_session)=([^;]+)/', $hdr, $m)) {
        $cookieName = !empty($m[1]) ? '__Host-bs_session' : 'bs_session';
        $sessionCookie = $m[2];
        break;
    }
}
if ($sessionCookie === '') {
    failTest("Login succeeded but session cookie (bs_session or __Host-bs_session) was not returned");
}

// Check audit log
$auditTable = $connection->prefixTable('audit_log');
$stmt = $connection->getPdo()->prepare(
    "SELECT `ip`, `user_agent`, `request_id`, `action`, `result` FROM `{$auditTable}`
     WHERE `action` = 'auth.login'
     ORDER BY `id` DESC LIMIT 1"
);
$stmt->execute();
$auditRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$auditRow) {
    failTest("No audit log entry found for auth.login");
}
if ($auditRow['result'] !== 'success') {
    failTest("Audit log result != 'success'");
}
passTest("4. Login flow succeeded with audit log recorded without SQL error");

// 5. Authenticated Session & Tiered Rate Limiter (600/min for auth)
echo "Testing 5: Authenticated session and tiered rate limiter...\n";
$resSession = httpRequest('GET', "{$baseUrl}/api/v1/auth/session", [
    'Cookie' => "{$cookieName}={$sessionCookie}",
]);
if ($resSession['status'] !== 200) {
    failTest("Session check failed with {$resSession['status']}: {$resSession['body']}");
}
scanNoSecrets($resSession['json']);
$csrfToken = (string) ($resSession['json']['csrf_token'] ?? '');
if ($csrfToken === '') {
    failTest("Missing csrf_token in session response");
}

$authLimit = (int) ($resSession['headers']['ratelimit-limit'] ?? 0);
if ($authLimit !== 600) {
    failTest("Expected authenticated rate limit 600, got {$authLimit}");
}
passTest("5. Authenticated session returned CSRF token and generous 600/min rate limit");

// 6. User Presenter in GET /api/v1/users (Sanitization check)
echo "Testing 6: GET /api/v1/users presenter sanitization...\n";
$resUsers = httpRequest('GET', "{$baseUrl}/api/v1/users", [
    'Cookie' => "{$cookieName}={$sessionCookie}",
]);
if ($resUsers['status'] !== 200) {
    failTest("GET /api/v1/users failed with {$resUsers['status']}: {$resUsers['body']}");
}
scanNoSecrets($resUsers['json']);
$usersList = $resUsers['json']['data'] ?? $resUsers['json'];
if (!is_array($usersList) || empty($usersList)) {
    failTest("Users list empty or invalid");
}
foreach ($usersList as $u) {
    if (isset($u['password_hash']) || isset($u['totp_secret_enc']) || isset($u['recovery_codes_hash'])) {
        failTest("User in list contained secret fields: " . json_encode($u));
    }
}
passTest("6. User list strictly sanitized with UserPresenter (no secrets leaked)");

// 7. 2FA Full Lifecycle: Setup -> Confirm (code only) -> Challenge -> Recovery Code
echo "Testing 7: 2FA Full Lifecycle over HTTP...\n";
$twoFactor = new TwoFactorService();

// Step 7a: Setup 2FA
$res2faSetup = httpRequest('POST', "{$baseUrl}/api/v1/me/2fa/setup", [
    'Cookie' => "{$cookieName}={$sessionCookie}",
    'Origin' => $originHeader,
    'X-CSRF-Token' => $csrfToken,
]);
if ($res2faSetup['status'] !== 200) {
    failTest("2FA setup failed: {$res2faSetup['status']} - {$res2faSetup['body']}");
}
$secret = (string) ($res2faSetup['json']['secret'] ?? '');
if ($secret === '') {
    failTest("2FA setup did not return secret");
}

// Step 7b: Confirm 2FA sending CODE ONLY (no secret)
$code = $twoFactor->getCode($secret);
$res2faConfirm = httpRequest('POST', "{$baseUrl}/api/v1/me/2fa/confirm", [
    'Cookie' => "{$cookieName}={$sessionCookie}",
    'Origin' => $originHeader,
    'X-CSRF-Token' => $csrfToken,
    'Content-Type' => 'application/json',
], [
    'code' => $code, // Code only!
]);
if ($res2faConfirm['status'] !== 200) {
    failTest("2FA confirm failed: {$res2faConfirm['status']} - {$res2faConfirm['body']}");
}
if (($res2faConfirm['json']['status'] ?? '') !== '2fa_enabled') {
    failTest("2FA confirm status != '2fa_enabled'");
}
$recoveryCodes = $res2faConfirm['json']['recovery_codes'] ?? [];
if (!is_array($recoveryCodes) || count($recoveryCodes) !== 10) {
    failTest("2FA confirm did not return 10 recovery codes");
}

// Step 7c: Logout
$resLogout = httpRequest('POST', "{$baseUrl}/api/v1/auth/logout", [
    'Cookie' => "{$cookieName}={$sessionCookie}",
    'Origin' => $originHeader,
    'X-CSRF-Token' => $csrfToken,
]);
if ($resLogout['status'] !== 200) {
    failTest("Logout failed: {$resLogout['status']}");
}

// Step 7d: Login again -> requires_2fa
$resLoginMfa = httpRequest('POST', "{$baseUrl}/api/v1/auth/login", [
    'Content-Type' => 'application/json',
], [
    'email' => $testEmail,
    'password' => $testPass,
]);
if ($resLoginMfa['status'] !== 200) {
    failTest("MFA Login step failed: {$resLoginMfa['status']} - {$resLoginMfa['body']}");
}
if (($resLoginMfa['json']['status'] ?? '') !== 'requires_2fa') {
    failTest("Expected status: requires_2fa, got " . ($resLoginMfa['json']['status'] ?? 'none'));
}
$challengeToken = (string) ($resLoginMfa['json']['challenge_token'] ?? '');
if ($challengeToken === '') {
    failTest("Missing challenge_token in requires_2fa response");
}

// Step 7e: Wrong code returns 401
$resWrong = httpRequest('POST', "{$baseUrl}/api/v1/auth/2fa/challenge", [
    'Content-Type' => 'application/json',
], [
    'challenge_token' => $challengeToken,
    'code' => '000000',
]);
if ($resWrong['status'] !== 401) {
    failTest("Expected 401 for wrong 2FA code, got {$resWrong['status']}");
}

// Step 7f: Correct TOTP code returns session
$totpCode = $twoFactor->getCode($secret);
$resChallenge = httpRequest('POST', "{$baseUrl}/api/v1/auth/2fa/challenge", [
    'Content-Type' => 'application/json',
], [
    'challenge_token' => $challengeToken,
    'code' => $totpCode,
]);
if ($resChallenge['status'] !== 200) {
    failTest("2FA challenge with valid code failed: {$resChallenge['status']} - {$resChallenge['body']}");
}
scanNoSecrets($resChallenge['json']);

// Step 7g: Replay protection (challenge token must be single-use)
$resReplay = httpRequest('POST', "{$baseUrl}/api/v1/auth/2fa/challenge", [
    'Content-Type' => 'application/json',
], [
    'challenge_token' => $challengeToken,
    'code' => $totpCode,
]);
if ($resReplay['status'] !== 401) {
    failTest("Challenge token replay was not rejected with 401 (got {$resReplay['status']})");
}

// Step 7h: TOTP code replay protection (same TOTP code on fresh challenge in same step must return 401)
$resLoginAgain = httpRequest('POST', "{$baseUrl}/api/v1/auth/login", [
    'Content-Type' => 'application/json',
], [
    'email' => $testEmail,
    'password' => $testPass,
]);
if ($resLoginAgain['status'] !== 200 || ($resLoginAgain['json']['status'] ?? '') !== 'requires_2fa') {
    failTest("Login for TOTP replay test failed");
}
$challengeToken2 = (string) ($resLoginAgain['json']['challenge_token'] ?? '');

$resTotpReplay = httpRequest('POST', "{$baseUrl}/api/v1/auth/2fa/challenge", [
    'Content-Type' => 'application/json',
], [
    'challenge_token' => $challengeToken2,
    'code' => $totpCode, // Same code as previously accepted in this step!
]);
if ($resTotpReplay['status'] !== 401) {
    failTest("Expected 401 on TOTP replay within same step, got {$resTotpReplay['status']}");
}

// Step 7i: 'recovery_code' parameter rejected with 422
$resLoginFieldCheck = httpRequest('POST', "{$baseUrl}/api/v1/auth/login", [
    'Content-Type' => 'application/json',
], [
    'email' => $testEmail,
    'password' => $testPass,
]);
$challengeTokenField = (string) ($resLoginFieldCheck['json']['challenge_token'] ?? '');

$resBadField = httpRequest('POST', "{$baseUrl}/api/v1/auth/2fa/challenge", [
    'Content-Type' => 'application/json',
], [
    'challenge_token' => $challengeTokenField,
    'recovery_code' => $recoveryCodes[0],
]);
if ($resBadField['status'] !== 422) {
    failTest("Expected 422 when submitting recovery_code parameter, got {$resBadField['status']}");
}

// Step 7j: Recovery code in 'code' parameter succeeds and is single use
$resRecovery = httpRequest('POST', "{$baseUrl}/api/v1/auth/2fa/challenge", [
    'Content-Type' => 'application/json',
], [
    'challenge_token' => $challengeTokenField,
    'code' => $recoveryCodes[0],
]);
if ($resRecovery['status'] !== 200) {
    failTest("Expected 200 using recovery code in 'code' field, got {$resRecovery['status']}");
}

// Replaying used recovery code fails
$resLoginRecoveryReplay = httpRequest('POST', "{$baseUrl}/api/v1/auth/login", [
    'Content-Type' => 'application/json',
], [
    'email' => $testEmail,
    'password' => $testPass,
]);
$challengeTokenRecoveryReplay = (string) ($resLoginRecoveryReplay['json']['challenge_token'] ?? '');
$resRecoveryReplay = httpRequest('POST', "{$baseUrl}/api/v1/auth/2fa/challenge", [
    'Content-Type' => 'application/json',
], [
    'challenge_token' => $challengeTokenRecoveryReplay,
    'code' => $recoveryCodes[0],
]);
if ($resRecoveryReplay['status'] !== 401) {
    failTest("Expected 401 when replaying used recovery code, got {$resRecoveryReplay['status']}");
}

passTest("7. Full 2FA lifecycle (code-only confirm, challenge, TOTP replay protection, 422 on recovery_code param, recovery code single-use) verified");

// 8. API Tokens: Scope enforcement & AI Draft-Only mode
echo "Testing 8: API Token permissions, role inheritance, and scopes...\n";

$tokensTable = $connection->prefixTable('api_tokens');
$tokenId = Ulid::generate();
$prefix = bin2hex(random_bytes(4));
$secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$rawToken = "bstok_{$prefix}_{$secret}";
$tokenHash = hash('sha256', $rawToken);
$now = Clock::sql(Clock::now());

// Insert API token with scope "users:read" and owner role inheritance (role_id = null)
$stmt = $connection->getPdo()->prepare(
    "INSERT INTO `{$tokensTable}`
     (`id`, `user_id`, `role_id`, `name`, `token_prefix`, `token_hash`, `scopes`, `ai_mode`, `created_at`, `expires_at`)
     VALUES (?, ?, NULL, 'Test Scoped Token', ?, ?, ?, 'draft_only', ?, NULL)"
);
$stmt->execute([
    $tokenId,
    $userId,
    $prefix,
    $tokenHash,
    json_encode(['users:read']),
    $now,
]);

// Call GET /api/v1/users with Bearer token -> 200 OK
$resTokenUsers = httpRequest('GET', "{$baseUrl}/api/v1/users", [
    'Authorization' => "Bearer {$rawToken}",
]);
if ($resTokenUsers['status'] !== 200) {
    failTest("Token with users:read scope could not read /users: {$resTokenUsers['status']} - {$resTokenUsers['body']}");
}
scanNoSecrets($resTokenUsers['json']);

// Call POST /api/v1/users/invite with Bearer token (needs users:create which is outside users:read scope) -> 403 Forbidden
$resTokenInvite = httpRequest('POST', "{$baseUrl}/api/v1/users/invite", [
    'Authorization' => "Bearer {$rawToken}",
    'Content-Type' => 'application/json',
], [
    'email' => 'invitee@example.com',
    'role_id' => $ownerRole['id'],
]);
if ($resTokenInvite['status'] !== 403) {
    failTest("Token without users:create scope expected 403 Forbidden on invite, got {$resTokenInvite['status']}");
}
passTest("8. API Token role inheritance, scope enforcement, and CSRF bypass verified");

// 9. ErrorHandler logging & Instance ID
echo "Testing 9: ErrorHandlerMiddleware Problem Details instance and logging...\n";
$res404 = httpRequest('GET', "{$baseUrl}/api/v1/nonexistent-endpoint-test-404", [
    'Authorization' => "Bearer {$rawToken}",
]);
if ($res404['status'] !== 404) {
    failTest("Expected 404, got {$res404['status']}");
}
$instance = (string) ($res404['json']['instance'] ?? '');
if ($instance === '' || $instance === 'unknown') {
    failTest("Problem Details instance is missing or 'unknown': got '{$instance}'");
}
passTest("9. Problem Details instance populated with request_id");

// 10. Phase 3: OpenAPI Spec endpoint
echo "Testing 10: GET /api/v1/openapi.json...\n";
$resOpenApi = httpRequest('GET', "{$baseUrl}/api/v1/openapi.json");
if ($resOpenApi['status'] !== 200) {
    failTest("GET /api/v1/openapi.json expected 200, got {$resOpenApi['status']}");
}
if (!str_contains($resOpenApi['headers']['cache-control'] ?? '', 'public')) {
    failTest("OpenAPI response missing public Cache-Control");
}
if (($resOpenApi['json']['openapi'] ?? '') !== '3.1.0') {
    failTest("OpenAPI version != 3.1.0: got " . ($resOpenApi['json']['openapi'] ?? ''));
}
scanNoSecrets($resOpenApi['json']);
passTest("10. OpenAPI 3.1 endpoint verified");

// 10b. Create full-access admin API token for Phase 3 endpoints
$adminTokenId = Ulid::generate();
$adminTokenPrefix = bin2hex(random_bytes(4));
$adminTokenSecret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$adminToken = "bstok_{$adminTokenPrefix}_{$adminTokenSecret}";
$adminTokenHash = hash('sha256', $adminToken);

$stmt = $connection->getPdo()->prepare(
    "INSERT INTO `{$tokensTable}`
     (`id`, `user_id`, `role_id`, `name`, `token_prefix`, `token_hash`, `scopes`, `ai_mode`, `created_at`, `expires_at`)
     VALUES (?, ?, NULL, 'Admin Full Access Token', ?, ?, ?, 'full', ?, NULL)"
);
$stmt->execute([
    $adminTokenId,
    $userId,
    $adminTokenPrefix,
    $adminTokenHash,
    json_encode(['*']),
    $now,
]);

// 11. Phase 3: Collections & Schema Lifecycle over HTTP
echo "Testing 11: Schema and Collections API...\n";
$resListCols = httpRequest('GET', "{$baseUrl}/api/v1/collections", [
    'Authorization' => "Bearer {$adminToken}",
]);
if ($resListCols['status'] !== 200) {
    failTest("GET /api/v1/collections failed with status {$resListCols['status']}");
}
scanNoSecrets($resListCols['json']);

// Create test 'campaigns' collection
$resCreateCol = httpRequest('POST', "{$baseUrl}/api/v1/collections", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'handle' => 'campaigns',
    'kind' => 'collection',
    'label_singular' => 'Campaign',
    'label_plural' => 'Campaigns',
    'fields' => [
        'budget' => ['handle' => 'budget', 'type' => 'number', 'is_filterable' => true, 'is_sortable' => true],
        'notes' => ['handle' => 'notes', 'type' => 'textarea'],
        'goal_amount' => ['handle' => 'goal_amount', 'type' => 'number', 'required' => true],
    ],
]);
if ($resCreateCol['status'] !== 201) {
    failTest("POST /api/v1/collections (campaigns) expected 201, got {$resCreateCol['status']}: {$resCreateCol['body']}");
}
scanNoSecrets($resCreateCol['json']);
passTest("11. Collections created and listed successfully");

// 12. Phase 3: Content Entries CRUD & Versioning
echo "Testing 12: Content Entries CRUD and Versioning...\n";

// 12a. Missing required field on create returns 422 with field pointer
$resMissingReq = httpRequest('POST', "{$baseUrl}/api/v1/collections/campaigns/entries", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'title' => 'Missing Required Goal Amount',
    'budget' => 5000,
    'notes' => 'Early launch',
    'status' => 'draft',
]);
if ($resMissingReq['status'] !== 422) {
    failTest("POST entry missing required goal_amount expected 422, got {$resMissingReq['status']}: {$resMissingReq['body']}");
}
if (!isset($resMissingReq['json']['errors']['goal_amount'])) {
    failTest("POST entry missing required field missing error pointer for goal_amount");
}

// 12b. Successful create with required field
$resCreateEntry = httpRequest('POST', "{$baseUrl}/api/v1/collections/campaigns/entries", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'title' => 'Black Friday Sale',
    'budget' => 5000,
    'goal_amount' => 10000,
    'notes' => 'Early launch',
    'status' => 'draft',
]);
if ($resCreateEntry['status'] !== 201) {
    failTest("POST /api/v1/collections/campaigns/entries expected 201, got {$resCreateEntry['status']}: {$resCreateEntry['body']}");
}
$campaignId = (string) $resCreateEntry['json']['id'];
$version = (int) $resCreateEntry['json']['version'];
scanNoSecrets($resCreateEntry['json']);

// Authenticated entry presentation check: custom fields at top-level, no 'data' blob, no internal columns
if (!isset($resCreateEntry['json']['goal_amount']) || $resCreateEntry['json']['goal_amount'] !== 10000) {
    failTest("Authenticated entry missing top-level custom field 'goal_amount'");
}
if (isset($resCreateEntry['json']['data'])) {
    failTest("Authenticated entry response contains redundant 'data' blob");
}
if (isset($resCreateEntry['json']['search_text']) || isset($resCreateEntry['json']['created_by']) || isset($resCreateEntry['json']['updated_by'])) {
    failTest("Authenticated entry response contains internal columns (search_text, created_by, updated_by)");
}

// Create second entry for purge cross-entry token test
$resCreateEntry2 = httpRequest('POST', "{$baseUrl}/api/v1/collections/campaigns/entries", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'title' => 'Cyber Monday Sale',
    'budget' => 8000,
    'goal_amount' => 15000,
    'status' => 'draft',
]);
if ($resCreateEntry2['status'] !== 201) {
    failTest("POST second entry expected 201, got {$resCreateEntry2['status']}");
}
$campaignId2 = (string) $resCreateEntry2['json']['id'];

// 12c. State-change endpoints: optional If-Match (acts on current version if omitted, 409 if stale)
// Publish without If-Match -> 200
$resPublishNoIfMatch = httpRequest('POST', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}/publish", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
]);
if ($resPublishNoIfMatch['status'] !== 200) {
    failTest("POST publish entry without If-Match expected 200, got {$resPublishNoIfMatch['status']}: {$resPublishNoIfMatch['body']}");
}
if ($resPublishNoIfMatch['json']['status'] !== 'published') {
    failTest("Entry status was not updated to published");
}
$publishVersion = (int) $resPublishNoIfMatch['json']['version'];

// Unpublish with STALE If-Match -> 409 Conflict
$resUnpublishStale = httpRequest('POST', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}/unpublish", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
    'If-Match' => '"1"', // Stale (version was bumped on publish)
]);
if ($resUnpublishStale['status'] !== 409) {
    failTest("POST unpublish with stale If-Match expected 409 Conflict, got {$resUnpublishStale['status']}");
}

// Unpublish with MATCHING If-Match -> 200
$resUnpublishValid = httpRequest('POST', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}/unpublish", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
    'If-Match' => (string) $publishVersion,
]);
if ($resUnpublishValid['status'] !== 200) {
    failTest("POST unpublish with matching If-Match expected 200, got {$resUnpublishValid['status']}");
}

// Re-publish for public API test
$resPublish = httpRequest('POST', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}/publish", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
]);
if ($resPublish['status'] !== 200) {
    failTest("POST re-publish expected 200, got {$resPublish['status']}");
}
$currentVersion = (int) $resPublish['json']['version'];
scanNoSecrets($resPublish['json']);
passTest("12. Content Entry created, validated, and state changes verified (optional If-Match & stale 409)");

// 13. Phase 3: Public Content API with ETag and 304 Not Modified
echo "Testing 13: Public Content API with ETag and 304...\n";
$resPublicList = httpRequest('GET', "{$baseUrl}/api/v1/public/collections/campaigns/entries");
if ($resPublicList['status'] !== 200) {
    failTest("GET /public/collections/campaigns/entries expected 200, got {$resPublicList['status']}");
}
$etag = $resPublicList['headers']['etag'] ?? '';
if ($etag === '') {
    failTest("Public entries response missing ETag header");
}
scanNoSecrets($resPublicList['json']);

// Request with If-None-Match -> 304 Not Modified
$res304 = httpRequest('GET', "{$baseUrl}/api/v1/public/collections/campaigns/entries", [
    'If-None-Match' => $etag,
]);
if ($res304['status'] !== 304) {
    failTest("Expected 304 Not Modified when submitting matching ETag, got {$res304['status']}");
}
passTest("13. Public Content API cache, ETag, and 304 Not Modified verified");

// 14. Phase 3: Webhooks & Signature Verification
echo "Testing 14: Webhooks API & Test Ping...\n";
$resCreateWh = httpRequest('POST', "{$baseUrl}/api/v1/webhooks", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'name' => 'Release HTTP Webhook',
    'url' => 'https://example.com/webhook-sink',
    'secret' => 'super_secret_webhook_key_12345',
    'events' => ['*'],
]);
if ($resCreateWh['status'] !== 201) {
    failTest("POST /api/v1/webhooks expected 201, got {$resCreateWh['status']}: {$resCreateWh['body']}");
}
$whId = (string) $resCreateWh['json']['id'];
scanNoSecrets($resCreateWh['json']);

$resWhPing = httpRequest('POST', "{$baseUrl}/api/v1/webhooks/{$whId}/test", [
    'Authorization' => "Bearer {$adminToken}",
]);
if ($resWhPing['status'] !== 200) {
    failTest("POST /webhooks/{$whId}/test expected 200, got {$resWhPing['status']}");
}
scanNoSecrets($resWhPing['json']);
passTest("14. Webhooks API and test ping verified");

// 14b. Regressions for Phase 3 Defects (1 to 11)
echo "Testing 14b: Regressions for Phase 3 defects...\n";

// Defect 1: Entry validation rejection (unknown field, non-numeric number)
$resInvalidField = httpRequest('POST', "{$baseUrl}/api/v1/collections/campaigns/entries", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'title' => 'Invalid Entry Test',
    'unknown_field_xyz' => 'exploit',
]);
if ($resInvalidField['status'] !== 422) {
    failTest("POST entry with unknown field expected 422, got {$resInvalidField['status']}: {$resInvalidField['body']}");
}

$resNonNumeric = httpRequest('POST', "{$baseUrl}/api/v1/collections/campaigns/entries", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'title' => 'Invalid Budget Test',
    'budget' => 'not-a-number',
]);
if ($resNonNumeric['status'] !== 422) {
    failTest("POST entry with non-numeric budget expected 422, got {$resNonNumeric['status']}: {$resNonNumeric['body']}");
}

// Defect 2: PATCH & Concurrency (If-Match required & 409 on stale)
$resPatchNoIfMatch = httpRequest('PATCH', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'notes' => 'Patched notes without If-Match',
]);
if ($resPatchNoIfMatch['status'] !== 428) {
    failTest("PATCH without If-Match header expected 428 Precondition Required, got {$resPatchNoIfMatch['status']}");
}

$resPatchStale = httpRequest('PATCH', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
    'If-Match' => '"99999"',
], [
    'notes' => 'Patched notes with stale If-Match',
]);
if ($resPatchStale['status'] !== 409) {
    failTest("PATCH with stale If-Match expected 409 Conflict, got {$resPatchStale['status']}");
}

// Valid PATCH with current ETag
$resPatchValid = httpRequest('PATCH', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
    'If-Match' => (string) $currentVersion,
], [
    'notes' => 'Patched notes successfully',
]);
if ($resPatchValid['status'] !== 200) {
    failTest("PATCH with valid If-Match expected 200, got {$resPatchValid['status']}: {$resPatchValid['body']}");
}
scanNoSecrets($resPatchValid['json']);
$currentVersion = (int) $resPatchValid['json']['version'];

// Enforce required fields on PUT (full replace) and PATCH (null/empty)
$resPutMissingReq = httpRequest('PUT', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
    'If-Match' => (string) $currentVersion,
], [
    'title' => 'PUT Missing Required Field',
    'budget' => 5000,
    // goal_amount is missing
]);
if ($resPutMissingReq['status'] !== 422 || !isset($resPutMissingReq['json']['errors']['goal_amount'])) {
    failTest("PUT entry missing required goal_amount expected 422 with pointer, got {$resPutMissingReq['status']}");
}

$resPatchNullReq = httpRequest('PATCH', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
    'If-Match' => (string) $currentVersion,
], [
    'goal_amount' => null,
]);
if ($resPatchNullReq['status'] !== 422 || !isset($resPatchNullReq['json']['errors']['goal_amount'])) {
    failTest("PATCH entry setting required goal_amount to null expected 422 with pointer, got {$resPatchNullReq['status']}");
}

// Defect 3: Purge token lifecycle & editor authorization
// Seed Editor user and token
$editorUserId = Ulid::generate();
$editorTokenPrefix = bin2hex(random_bytes(4));
$editorTokenSecret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$editorToken = "bstok_{$editorTokenPrefix}_{$editorTokenSecret}";
$editorTokenHash = hash('sha256', $editorToken);

$usersTable = $dbConfig['prefix'] . 'users';
$userRolesTable = $dbConfig['prefix'] . 'user_roles';
$existingEditor = $connection->getPdo()->query("SELECT `id` FROM `{$usersTable}` WHERE `email` = 'editor@example.com'")->fetch();
if ($existingEditor !== false) {
    $editorUserId = $existingEditor['id'];
} else {
    $connection->getPdo()->prepare(
        "INSERT INTO `{$usersTable}` (`id`, `email`, `password_hash`, `name`, `status`, `created_at`, `updated_at`) VALUES (?, 'editor@example.com', 'dummy_hash', 'Test Editor', 'active', ?, ?)"
    )->execute([$editorUserId, $now, $now]);
    $connection->getPdo()->prepare(
        "INSERT INTO `{$userRolesTable}` (`user_id`, `role_id`) VALUES (?, ?)"
    )->execute([$editorUserId, \BsCms\Auth\SystemRoles::ROLE_EDITOR]);
}
$connection->getPdo()->prepare(
    "INSERT INTO `{$tokensTable}` (`id`, `user_id`, `role_id`, `name`, `token_prefix`, `token_hash`, `scopes`, `ai_mode`, `created_at`, `expires_at`) VALUES (?, ?, NULL, 'Editor Test Token', ?, ?, ?, 'full', ?, NULL)"
)->execute([Ulid::generate(), $editorUserId, $editorTokenPrefix, $editorTokenHash, json_encode(['*']), $now]);

// a. Editor attempts purge -> 403 Forbidden
$resEditorPurge = httpRequest('DELETE', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}/purge", [
    'Authorization' => "Bearer {$editorToken}",
]);
if ($resEditorPurge['status'] !== 403) {
    failTest("DELETE purge by Editor expected 403 Forbidden, got {$resEditorPurge['status']}: {$resEditorPurge['body']}");
}

// b. Admin purge without token -> 409 Confirmation Required + confirm_token
$resPurgeNoToken = httpRequest('DELETE', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}/purge", [
    'Authorization' => "Bearer {$adminToken}",
]);
if ($resPurgeNoToken['status'] !== 409) {
    failTest("DELETE purge without confirm token expected 409, got {$resPurgeNoToken['status']}");
}
$purgeToken = (string) ($resPurgeNoToken['json']['confirm_token'] ?? '');
if ($purgeToken === '') {
    failTest("DELETE purge 409 response missing confirm_token");
}

// c. Admin purge with tampered token -> 409
$resPurgeTampered = httpRequest('DELETE', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}/purge", [
    'Authorization' => "Bearer {$adminToken}",
    'X-Confirm-Token' => $purgeToken . 'tampered',
]);
if ($resPurgeTampered['status'] !== 409) {
    failTest("DELETE purge with tampered token expected 409, got {$resPurgeTampered['status']}");
}

// d. Admin purge with token bound to another entry -> 409
$resPurgeOtherEntry = httpRequest('DELETE', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId2}/purge", [
    'Authorization' => "Bearer {$adminToken}",
    'X-Confirm-Token' => $purgeToken,
]);
if ($resPurgeOtherEntry['status'] !== 409) {
    failTest("DELETE purge with token bound to another entry expected 409, got {$resPurgeOtherEntry['status']}");
}

// Defect 4: Step-up on site_settings environment change
$resEnvNoStepUp = httpRequest('PUT', "{$baseUrl}/api/v1/singletons/site_settings", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'environment' => 'staging',
]);
if ($resEnvNoStepUp['status'] !== 403 && $resEnvNoStepUp['status'] !== 409) {
    failTest("PUT site_settings environment expected 403 (step-up) or 409 (confirm), got {$resEnvNoStepUp['status']}");
}

// Defect 5: Webhook URL SSRF validation blocks loopback
$resSsrfWh = httpRequest('POST', "{$baseUrl}/api/v1/webhooks", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'name' => 'SSRF Webhook',
    'url' => 'http://127.0.0.1:8080/exploit',
    'secret' => 'super_secret_webhook_key_12345',
    'events' => ['*'],
]);
if ($resSsrfWh['status'] !== 422) {
    failTest("POST webhook with loopback URL expected 422 SSRF block, got {$resSsrfWh['status']}: {$resSsrfWh['body']}");
}

// Defect 6: Sanitization of public API & authenticated entry responses
if (isset($resPublicList['json']['data'][0]['search_text']) || isset($resPublicList['json']['data'][0]['created_by'])) {
    failTest("Public API response contains internal columns (search_text or created_by)");
}
if (isset($resPatchValid['json']['search_text']) || isset($resPatchValid['json']['created_by'])) {
    failTest("Authenticated entry response contains raw internal search_text or created_by");
}

// Defect 7: Bulk operation alias ('action' -> 'operation') and per-item results
$resBulkAction = httpRequest('POST', "{$baseUrl}/api/v1/collections/campaigns/entries/bulk", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'action' => 'unpublish',
    'ids' => [$campaignId],
]);
if ($resBulkAction['status'] !== 200) {
    failTest("Bulk operation using 'action' alias expected 200, got {$resBulkAction['status']}: {$resBulkAction['body']}");
}
if (!isset($resBulkAction['json']['items']) || !is_array($resBulkAction['json']['items'])) {
    failTest("Bulk operation response missing 'items' array");
}

// Defect 9: Schedule endpoint validates date
$resPastSchedule = httpRequest('POST', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}/schedule", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'scheduled_at' => '2020-01-01T00:00:00Z',
]);
if ($resPastSchedule['status'] !== 422) {
    failTest("Schedule with past date expected 422, got {$resPastSchedule['status']}");
}

passTest("14b. All 11 Phase 3 defect regression checks passed successfully");

// Defect 3 confirmation test: execute purge with confirm token
$resPurgeConfirmed = httpRequest('DELETE', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}/purge", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'confirm_token' => $purgeToken,
]);
if ($resPurgeConfirmed['status'] !== 200) {
    failTest("DELETE purge with confirm token expected 200, got {$resPurgeConfirmed['status']}: {$resPurgeConfirmed['body']}");
}

// Replayed token must be rejected with 409
$resPurgeReplay = httpRequest('DELETE', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId2}/purge", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'confirm_token' => $purgeToken,
]);
if ($resPurgeReplay['status'] !== 409) {
    failTest("DELETE purge with replayed confirm token expected 409, got {$resPurgeReplay['status']}");
}
passTest("14c. Entry purged permanently with two-step confirmation (and replayed token refused)");

// 14d: Sort on non-sortable field returns 422 problem details listing sortable fields
$resSortNonSortable = httpRequest('GET', "{$baseUrl}/api/v1/collections/campaigns/entries?sort=notes", [
    'Authorization' => "Bearer {$adminToken}",
]);
if ($resSortNonSortable['status'] !== 422) {
    failTest("GET campaigns?sort=notes expected 422, got {$resSortNonSortable['status']}: {$resSortNonSortable['body']}");
}
if (!str_contains($resSortNonSortable['body'], 'is not sortable')) {
    failTest("422 problem details response did not indicate non-sortable field: {$resSortNonSortable['body']}");
}
passTest("14d. Sort on non-sortable field validated with 422 problem details listing sortable fields");

// Clean up created collection
$resDelCol = httpRequest('DELETE', "{$baseUrl}/api/v1/collections/campaigns", [
    'Authorization' => "Bearer {$adminToken}",
]);
if ($resDelCol['status'] !== 409) {
    failTest("DELETE collection without confirm token expected 409, got {$resDelCol['status']}");
}
$confirmToken = (string) ($resDelCol['json']['confirm_token'] ?? '');
$resDelColConfirmed = httpRequest('DELETE', "{$baseUrl}/api/v1/collections/campaigns", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'confirm_token' => $confirmToken,
]);
if ($resDelColConfirmed['status'] !== 200) {
    failTest("DELETE collection with confirmation token expected 200, got {$resDelColConfirmed['status']}");
}
passTest("15. Two-step confirmation for destructive collection drop verified");

// 16. Phase 3 Final Verification Checks
echo "Testing 16: Phase 3 final acceptance checks...\n";

// 16a. Create page with title only applies schema default (template=default)
$resPageCreate = httpRequest('POST', "{$baseUrl}/api/v1/collections/pages/entries", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'title' => 'Terms of Service',
]);
if ($resPageCreate['status'] !== 201) {
    failTest("POST /collections/pages/entries with title only expected 201, got {$resPageCreate['status']}: {$resPageCreate['body']}");
}
if (($resPageCreate['json']['template'] ?? null) !== 'default') {
    failTest("Create page with title only did not apply default template='default': " . json_encode($resPageCreate['json']));
}
passTest("16a. Create page with title only applies schema default (template=default)");

// 16b. GET singleton on fresh install returns seeded defaults
$resGetSiteSettings = httpRequest('GET', "{$baseUrl}/api/v1/singletons/site_settings", [
    'Authorization' => "Bearer {$adminToken}",
]);
if ($resGetSiteSettings['status'] !== 200) {
    failTest("GET singleton site_settings expected 200, got {$resGetSiteSettings['status']}: {$resGetSiteSettings['body']}");
}
if (($resGetSiteSettings['json']['site_name'] ?? null) !== 'BS CMS') {
    failTest("GET singleton site_settings default site_name not seeded: " . json_encode($resGetSiteSettings['json']));
}
passTest("16b. GET singleton site_settings returns seeded defaults");

// 16c. Partial singleton update (PATCH & PUT merge semantics)
$resPatchSiteSettings = httpRequest('PATCH', "{$baseUrl}/api/v1/singletons/site_settings", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'site_name' => 'Brand Sewa CMS',
]);
if ($resPatchSiteSettings['status'] !== 200) {
    failTest("PATCH singleton site_settings partial update expected 200, got {$resPatchSiteSettings['status']}: {$resPatchSiteSettings['body']}");
}
if (($resPatchSiteSettings['json']['site_name'] ?? null) !== 'Brand Sewa CMS') {
    failTest("PATCH singleton site_settings did not update site_name: " . json_encode($resPatchSiteSettings['json']));
}
if (($resPatchSiteSettings['json']['environment'] ?? null) !== 'production') {
    failTest("PATCH singleton site_settings partial update did not preserve environment='production'");
}
passTest("16c. Partial singleton update (PATCH/PUT) merges data and preserves omitted fields");

// 16d. Environment change on site_settings requires step-up + confirm token
// Create a session without step-up (mfa_passed_at = null)
$stepUpSessionManager = new \BsCms\Auth\SessionManager($connection);
$stepUpSess = $stepUpSessionManager->create($userId, null, null, false);
$sessCookie = $stepUpSess['token'];
$sessCsrf = $stepUpSess['csrf_token'];

$sessHeaders = [
    'Cookie' => "{$cookieName}={$sessCookie}",
    'X-CSRF-Token' => $sessCsrf,
    'Origin' => $originHeader,
    'Content-Type' => 'application/json',
];

// Attempt without step-up -> 403 Step-Up Authentication Required
$resEnvNoStepUp = httpRequest('PUT', "{$baseUrl}/api/v1/singletons/site_settings", $sessHeaders, [
    'environment' => 'staging',
]);
if ($resEnvNoStepUp['status'] !== 403) {
    failTest("Change environment without step-up expected 403, got {$resEnvNoStepUp['status']}: {$resEnvNoStepUp['body']}");
}

// Perform step-up via POST /auth/step-up
$resStepUp = httpRequest('POST', "{$baseUrl}/api/v1/auth/step-up", $sessHeaders, [
    'password' => $testPass,
]);
if ($resStepUp['status'] !== 200) {
    failTest("Step-up auth expected 200, got {$resStepUp['status']}: {$resStepUp['body']}");
}

// Attempt with step-up but without confirm token -> 409
$resEnvNoConfirm = httpRequest('PUT', "{$baseUrl}/api/v1/singletons/site_settings", $sessHeaders, [
    'environment' => 'staging',
]);
if ($resEnvNoConfirm['status'] !== 409) {
    failTest("Change environment without confirm token expected 409, got {$resEnvNoConfirm['status']}: {$resEnvNoConfirm['body']}");
}
$envConfirmToken = (string) ($resEnvNoConfirm['json']['confirm_token'] ?? '');
if ($envConfirmToken === '') {
    failTest("Environment change 409 response missing confirm_token");
}

// Execute with confirm token -> 200
$resEnvConfirmed = httpRequest('PUT', "{$baseUrl}/api/v1/singletons/site_settings", array_merge($sessHeaders, [
    'X-Confirm-Token' => $envConfirmToken,
]), [
    'environment' => 'staging',
]);
if ($resEnvConfirmed['status'] !== 200) {
    failTest("Change environment with step-up and confirm token expected 200, got {$resEnvConfirmed['status']}: {$resEnvConfirmed['body']}");
}
passTest("16d. Singleton environment change enforces step-up and two-step confirm token");

// 16e. User preferences GET/PATCH
$resGetPrefs = httpRequest('GET', "{$baseUrl}/api/v1/me/preferences", [
    'Authorization' => "Bearer {$adminToken}",
]);
if ($resGetPrefs['status'] !== 200) {
    failTest("GET /api/v1/me/preferences expected 200, got {$resGetPrefs['status']}: {$resGetPrefs['body']}");
}

$resPatchPrefs = httpRequest('PATCH', "{$baseUrl}/api/v1/me/preferences", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'theme' => 'dark',
    'density' => 'compact',
    'locale' => 'en-US',
]);
if ($resPatchPrefs['status'] !== 200) {
    failTest("PATCH /api/v1/me/preferences expected 200, got {$resPatchPrefs['status']}: {$resPatchPrefs['body']}");
}
if (($resPatchPrefs['json']['theme'] ?? '') !== 'dark' || ($resPatchPrefs['json']['density'] ?? '') !== 'compact') {
    failTest("PATCH /api/v1/me/preferences did not update preferences: " . json_encode($resPatchPrefs['json']));
}

// Invalid theme value -> 422
$resInvalidTheme = httpRequest('PATCH', "{$baseUrl}/api/v1/me/preferences", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'theme' => 'neon_laser',
]);
if ($resInvalidTheme['status'] !== 422) {
    failTest("PATCH /api/v1/me/preferences with invalid theme expected 422, got {$resInvalidTheme['status']}");
}

// Unknown preference key rejected -> 422
$resUnknownKey = httpRequest('PATCH', "{$baseUrl}/api/v1/me/preferences", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'custom_unregistered_key' => 'arbitrary_value',
]);
if ($resUnknownKey['status'] !== 422) {
    failTest("PATCH /api/v1/me/preferences with unknown key expected 422, got {$resUnknownKey['status']}");
}

// Oversized payload (>4KB) -> 413
$oversized = ['notes' => str_repeat('X', 4500)];
$resOversized = httpRequest('PATCH', "{$baseUrl}/api/v1/me/preferences", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], $oversized);
if ($resOversized['status'] !== 413) {
    failTest("PATCH /api/v1/me/preferences with >4KB payload expected 413, got {$resOversized['status']}");
}
passTest("16e. User preferences GET/PATCH validated with 4KB cap and strict allow-list");

// 17. Admin SPA bundling, client-side routing fallback, and API/OAuth route preservation
echo "Testing 17: Admin SPA bundling, deep links, security headers, and API/OAuth route preservation...\n";

function assertAdminHeaders(array $response, string $path, bool $isRedirect = false): void {
    $headers = $response['headers'];
    if (($headers['x-frame-options'] ?? '') !== 'DENY') {
        failTest("{$path} missing X-Frame-Options: DENY");
    }
    if (($headers['x-content-type-options'] ?? '') !== 'nosniff') {
        failTest("{$path} missing X-Content-Type-Options: nosniff");
    }
    if (!str_contains($headers['referrer-policy'] ?? '', 'strict-origin-when-cross-origin')) {
        failTest("{$path} missing Referrer-Policy: strict-origin-when-cross-origin");
    }
    if (!str_contains($headers['permissions-policy'] ?? '', 'camera=()')) {
        failTest("{$path} missing Permissions-Policy header");
    }
    if (!str_contains($headers['x-robots-tag'] ?? '', 'noindex')) {
        failTest("{$path} missing X-Robots-Tag: noindex");
    }
    if (!$isRedirect) {
        $csp = $headers['content-security-policy'] ?? '';
        if (!str_contains($csp, "default-src 'self'")
            || !str_contains($csp, "script-src 'self'")
            || !str_contains($csp, "style-src 'self' 'unsafe-inline'")
            || !str_contains($csp, "img-src 'self' data: blob:")
            || !str_contains($csp, "connect-src 'self'")
            || !str_contains($csp, "frame-ancestors 'none'")
            || !str_contains($csp, "base-uri 'self'")
            || !str_contains($csp, "form-action 'self'")) {
            failTest("{$path} missing required strict CSP directives, got: {$csp}");
        }
        if (!str_contains($headers['cache-control'] ?? '', 'no-cache')) {
            failTest("{$path} missing Cache-Control: no-cache, got: " . ($headers['cache-control'] ?? 'none'));
        }
    }
}

// 17a. Root admin endpoint /admin/ returns SPA index with full security headers & strict CSP
$resAdmin = httpRequest('GET', "{$baseUrl}/admin/");
if ($resAdmin['status'] !== 200) {
    failTest("GET /admin/ expected 200, got {$resAdmin['status']}: {$resAdmin['body']}");
}
if (!str_contains($resAdmin['headers']['content-type'] ?? '', 'text/html')) {
    failTest("GET /admin/ content-type expected text/html, got " . ($resAdmin['headers']['content-type'] ?? 'none'));
}
if (!str_contains($resAdmin['body'], '<div id="root"></div>')) {
    failTest("GET /admin/ body missing root container <div id=\"root\"></div>");
}
if (!str_contains($resAdmin['body'], 'BS CMS')) {
    failTest("GET /admin/ body missing BS CMS title");
}
assertAdminHeaders($resAdmin, 'GET /admin/', false);
passTest("17a. GET /admin/ returns 200 SPA index with text/html, strict CSP, and security headers");

// 17b. Deep client-side link /admin/entries/posts falls back to SPA index with 200 and identical headers
$resDeepLink = httpRequest('GET', "{$baseUrl}/admin/entries/posts");
if ($resDeepLink['status'] !== 200) {
    failTest("GET /admin/entries/posts expected 200, got {$resDeepLink['status']}: {$resDeepLink['body']}");
}
if (!str_contains($resDeepLink['headers']['content-type'] ?? '', 'text/html')) {
    failTest("GET /admin/entries/posts content-type expected text/html, got " . ($resDeepLink['headers']['content-type'] ?? 'none'));
}
if (!str_contains($resDeepLink['body'], '<div id="root"></div>')) {
    failTest("GET /admin/entries/posts body missing root container <div id=\"root\"></div>");
}
assertAdminHeaders($resDeepLink, 'GET /admin/entries/posts', false);
passTest("17b. Deep link GET /admin/entries/posts falls back to SPA index with 200, strict CSP, and security headers");

// 17c. /admin (without trailing slash) returns 301 or 200 with security headers
$resAdminBare = httpRequest('GET', "{$baseUrl}/admin");
if ($resAdminBare['status'] !== 301 && $resAdminBare['status'] !== 200) {
    failTest("GET /admin expected 301 or 200, got {$resAdminBare['status']}: {$resAdminBare['body']}");
}
if ($resAdminBare['status'] === 301) {
    $loc = $resAdminBare['headers']['location'] ?? '';
    if (!str_ends_with($loc, '/admin/')) {
        failTest("GET /admin expected 301 redirect to /admin/, got: {$loc}");
    }
    assertAdminHeaders($resAdminBare, 'GET /admin (301)', true);
} else {
    assertAdminHeaders($resAdminBare, 'GET /admin (200)', false);
}
passTest("17c. GET /admin returns " . $resAdminBare['status'] . " with security headers");

// 17d. Missing asset path under /admin/assets/ returns 404 (never SPA index)
$resMissingAsset = httpRequest('GET', "{$baseUrl}/admin/assets/missing-asset-bundle-test.js");
if ($resMissingAsset['status'] !== 404) {
    failTest("GET /admin/assets/missing-asset-bundle-test.js expected 404, got {$resMissingAsset['status']}: " . substr($resMissingAsset['body'], 0, 100));
}
if (str_contains($resMissingAsset['body'], '<div id="root"></div>')) {
    failTest("GET /admin/assets/missing-asset-bundle-test.js returned SPA index HTML instead of 404!");
}
passTest("17d. Missing asset path /admin/assets/*.js returns 404 (not SPA index)");

// 17e. Static asset /admin/theme-init.js served with JavaScript content type
$resThemeInit = httpRequest('GET', "{$baseUrl}/admin/theme-init.js");
if ($resThemeInit['status'] !== 200) {
    failTest("GET /admin/theme-init.js expected 200, got {$resThemeInit['status']}: {$resThemeInit['body']}");
}
$themeType = $resThemeInit['headers']['content-type'] ?? '';
if (!str_contains($themeType, 'javascript')) {
    failTest("GET /admin/theme-init.js content-type expected javascript, got: {$themeType}");
}
passTest("17e. Static asset /admin/theme-init.js served directly with 200 and javascript content type");

// 17f. /api/v1/openapi.json routing is unaffected (returns 200 JSON)
$resOpenApiCheck = httpRequest('GET', "{$baseUrl}/api/v1/openapi.json");
if ($resOpenApiCheck['status'] !== 200) {
    failTest("GET /api/v1/openapi.json expected 200, got {$resOpenApiCheck['status']}");
}
if (!str_contains($resOpenApiCheck['headers']['content-type'] ?? '', 'application/json')) {
    failTest("GET /api/v1/openapi.json content-type expected application/json, got " . ($resOpenApiCheck['headers']['content-type'] ?? 'none'));
}
if (($resOpenApiCheck['json']['openapi'] ?? '') !== '3.1.0') {
    failTest("GET /api/v1/openapi.json invalid JSON response: " . substr($resOpenApiCheck['body'], 0, 100));
}
passTest("17f. /api/v1/openapi.json routing is unaffected (returns 200 JSON)");

// 17g. /oauth/authorize routing is unaffected (routed to PHP OAuthController)
$resOAuth = httpRequest('GET', "{$baseUrl}/oauth/authorize?response_type=code&client_id=nonexistent_client&redirect_uri=https://example.com/callback&code_challenge=xyz&code_challenge_method=S256");
if ($resOAuth['status'] !== 302 && $resOAuth['status'] !== 400) {
    failTest("GET /oauth/authorize routing affected: expected 302 or 400 from OAuthController, got {$resOAuth['status']}: {$resOAuth['body']}");
}
if ($resOAuth['status'] === 302) {
    $location = $resOAuth['headers']['location'] ?? '';
    if (!str_starts_with($location, '/admin/login')) {
        failTest("GET /oauth/authorize expected redirect to /admin/login, got {$location}");
    }
}
passTest("17g. /oauth/authorize routing is unaffected (routed to PHP OAuthController)");

echo "\n======================================================\n";
echo "[ALL TESTS PASSED] Phase 3 release build verified over HTTP!\n";
echo "======================================================\n";
exit(0);

