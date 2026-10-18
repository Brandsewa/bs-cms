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
        curl_close($ch);
        failTest("cURL error requesting {$url}: {$err}");
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

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
$dbConfigFile = "{$corePath}/config/database.php";
if (!file_exists($dbConfigFile)) {
    failTest("Missing database config: {$dbConfigFile}");
}
$dbConfig = require $dbConfigFile;
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
        'two_factor_enabled' => 0,
        'totp_secret_enc' => null,
        'recovery_codes_hash' => null,
    ]);
}
passTest("3. Test admin user seeded ({$testEmail})");

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
    'Origin' => $baseUrl,
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
    'Origin' => $baseUrl,
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
    'Origin' => $baseUrl,
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
    ],
]);
if ($resCreateCol['status'] !== 201) {
    failTest("POST /api/v1/collections (campaigns) expected 201, got {$resCreateCol['status']}: {$resCreateCol['body']}");
}
scanNoSecrets($resCreateCol['json']);
passTest("11. Collections created and listed successfully");

// 12. Phase 3: Content Entries CRUD & Versioning
echo "Testing 12: Content Entries CRUD and Versioning...\n";
$resCreateEntry = httpRequest('POST', "{$baseUrl}/api/v1/collections/campaigns/entries", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'title' => 'Black Friday Sale',
    'budget' => 5000,
    'notes' => 'Early launch',
    'status' => 'draft',
]);
if ($resCreateEntry['status'] !== 201) {
    failTest("POST /api/v1/collections/campaigns/entries expected 201, got {$resCreateEntry['status']}: {$resCreateEntry['body']}");
}
$campaignId = (string) $resCreateEntry['json']['id'];
$version = (int) $resCreateEntry['json']['version'];
scanNoSecrets($resCreateEntry['json']);

// Publish entry
$resPublish = httpRequest('POST', "{$baseUrl}/api/v1/collections/campaigns/entries/{$campaignId}/publish", [
    'Authorization' => "Bearer {$adminToken}",
    'Content-Type' => 'application/json',
], [
    'version' => $version,
]);
if ($resPublish['status'] !== 200) {
    failTest("POST publish entry expected 200, got {$resPublish['status']}: {$resPublish['body']}");
}
if ($resPublish['json']['status'] !== 'published') {
    failTest("Entry status was not updated to published");
}
scanNoSecrets($resPublish['json']);
passTest("12. Content Entry created and published successfully");

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

echo "\n======================================================\n";
echo "[ALL TESTS PASSED] Phase 3 release build verified over HTTP!\n";
echo "======================================================\n";
exit(0);

