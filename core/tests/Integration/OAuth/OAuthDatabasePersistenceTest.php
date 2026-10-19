<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\OAuth;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Auth\PasswordHasher;
use BsCms\Auth\SystemRoles;
use BsCms\Authz\Authorizer;
use BsCms\Authz\PermissionCompiler;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Http\Controllers\AuditController;
use BsCms\Kernel\Config;
use BsCms\OAuth\OAuthController;
use BsCms\Security\Ulid;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test proving Milestone 8.1 & 8.2 OAuth 2.1 implementation:
 * - Real MySQL/MariaDB database with migration 0009 applied
 * - oauth_clients, oauth_grants, oauth_authorization_codes, oauth_access_tokens, oauth_refresh_tokens
 * - Verification that tokens and codes are stored exclusively as SHA-256 hashes (never plaintext)
 * - Verification that refresh token reuse triggers full grant revocation in DB and writes audit entry
 * - RFC 8707 Resource Indicators validation
 * - Dynamic role selection & privilege escalation prevention
 * - ai_mode options and full mode confirmation acknowledgement
 * - Grants API (GET /api/v1/oauth/grants and DELETE /api/v1/oauth/grants/{id}) with strict presenters, non-admin isolation, and audit logging
 */
final class OAuthDatabasePersistenceTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private PDO $adminPdo;
    private PasswordHasher $hasher;
    private AuditLogger $auditLogger;
    private Authorizer $authorizer;
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : 'root';

        try {
            $this->adminPdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\Throwable $e) {
            if (getenv('DB_HOST') !== false) {
                $this->fail('Database configured via DB_HOST but unreachable: ' . $e->getMessage());
            }
            $this->markTestSkipped('No local MySQL/MariaDB available for database persistence test.');
        }

        $this->dbName = 'bs_oauth_' . bin2hex(random_bytes(4));
        $this->adminPdo->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->connection = new Connection([
            'host' => $host,
            'port' => $port,
            'database' => $this->dbName,
            'username' => $user,
            'password' => $pass,
            'prefix' => 'bs_',
        ]);

        // Run migrations
        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $migrator->migrate();

        $this->hasher = new PasswordHasher();
        $this->auditLogger = new AuditLogger($this->connection);
        $this->authorizer = new Authorizer($this->connection, new PermissionCompiler($this->connection));
        $this->config = new Config([
            'security' => [
                'oauth' => [
                    'registration_rate_limit' => [
                        'limit' => 5,
                        'window' => 3600,
                    ],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo) && $this->dbName !== '') {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }
        parent::tearDown();
    }

    public function testTokensAreStoredExclusivelyHashedInDatabase(): void
    {
        $pdo = $this->connection->getPdo();
        $usersTable = $this->connection->prefixTable('users');
        $userRolesTable = $this->connection->prefixTable('user_roles');
        $clientsTable = $this->connection->prefixTable('oauth_clients');
        $grantsTable = $this->connection->prefixTable('oauth_grants');
        $codesTable = $this->connection->prefixTable('oauth_authorization_codes');
        $atTable = $this->connection->prefixTable('oauth_access_tokens');
        $rtTable = $this->connection->prefixTable('oauth_refresh_tokens');
        $auditTable = $this->connection->prefixTable('audit_log');

        // 1. Seed a test user
        $userId = Ulid::generate();
        $passwordHash = $this->hasher->hash('secret-password');
        $nowUtc = gmdate('Y-m-d H:i:s.v');

        $stmt = $pdo->prepare("INSERT INTO `{$usersTable}` (`id`, `email`, `password_hash`, `name`, `status`, `created_at`, `updated_at`)
            VALUES (:id, 'alice@example.com', :hash, 'Alice Admin', 'active', :created_at, :updated_at)");
        $stmt->execute([
            'id' => $userId,
            'hash' => $passwordHash,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ]);

        $roleStmt = $pdo->prepare("INSERT INTO `{$userRolesTable}` (`user_id`, `role_id`) VALUES (:user_id, :role_id)");
        $roleStmt->execute([
            'user_id' => $userId,
            'role_id' => SystemRoles::ROLE_ADMIN,
        ]);

        $controller = new OAuthController($this->connection, $this->hasher, $this->auditLogger, $this->authorizer, $this->config);

        // 2. Dynamic client registration
        $regReq = new ServerRequest('POST', new Uri('https://example.com/oauth/register'), [
            'Content-Type' => 'application/json',
        ]);
        $regReq->getBody()->write((string) json_encode([
            'client_name' => 'Database Test Client',
            'redirect_uris' => ['https://claude.ai/api/mcp/oauth/callback'],
        ]));
        $regReq->getBody()->rewind();

        $regRes = $controller->register($regReq);
        $this->assertSame(201, $regRes->getStatusCode());
        $regJson = json_decode((string) $regRes->getBody(), true);
        $clientId = $regJson['client_id'];

        // Assert client stored in database
        $clientDbStmt = $pdo->prepare("SELECT * FROM `{$clientsTable}` WHERE `client_id` = :client_id");
        $clientDbStmt->execute(['client_id' => $clientId]);
        $clientRow = $clientDbStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($clientRow);
        $this->assertSame('Database Test Client', $clientRow['name']);

        // 3. Authorize and issue authorization code
        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $authReq = (new ServerRequest('POST', new Uri('https://example.com/oauth/authorize'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]))->withAttribute('session', [
            'id' => 'sess_1',
            'user_id' => $userId,
            'name' => 'Alice Admin',
            'csrf_secret' => 'csrf_valid_secret',
        ])->withAttribute('actor', Actor::user($userId, 'Alice Admin', ['admin']));

        $authReq->getBody()->write(http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/api/mcp/oauth/callback',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'decision' => 'allow',
            'csrf_token' => 'csrf_valid_secret',
            'password' => 'secret-password',
            'ai_mode' => 'draft_only',
        ]));
        $authReq->getBody()->rewind();

        $authRes = $controller->authorizePost($authReq);
        $this->assertSame(302, $authRes->getStatusCode());
        $authLocation = $authRes->getHeaderLine('Location');
        $this->assertStringContainsString('code=', $authLocation);

        $authQuery = parse_url($authLocation, PHP_URL_QUERY);
        parse_str(is_string($authQuery) ? $authQuery : '', $authParams);
        $rawCode = $authParams['code'] ?? '';
        $plainAuthCode = is_string($rawCode) ? $rawCode : '';
        $this->assertNotEmpty($plainAuthCode);
        $this->assertStringStartsWith('bs_code_', $plainAuthCode);

        // Assert authorization code is HASHED in the database (never plaintext)
        $codeHash = hash('sha256', $plainAuthCode);
        $codeStmt = $pdo->prepare("SELECT * FROM `{$codesTable}` WHERE `code_hash` = :hash");
        $codeStmt->execute(['hash' => $codeHash]);
        $codeRow = $codeStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($codeRow);
        $this->assertSame($codeHash, $codeRow['code_hash']);

        // Assert plaintext code DOES NOT exist anywhere in database
        $plaintextCheck = $pdo->prepare("SELECT COUNT(*) FROM `{$codesTable}` WHERE `code_hash` = :plain");
        $plaintextCheck->execute(['plain' => $plainAuthCode]);
        $this->assertSame(0, (int) $plaintextCheck->fetchColumn(), 'Plaintext code must NEVER be stored in the database');

        // 4. Token exchange (PKCE)
        $tokenReq = new ServerRequest('POST', new Uri('https://example.com/oauth/token'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);
        $tokenReq->getBody()->write(http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $plainAuthCode,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => 'https://claude.ai/api/mcp/oauth/callback',
        ]));
        $tokenReq->getBody()->rewind();

        $tokenRes = $controller->token($tokenReq);
        $this->assertSame(200, $tokenRes->getStatusCode());
        $tokenJson = json_decode((string) $tokenRes->getBody(), true);
        $this->assertIsArray($tokenJson);

        $plainAccessToken = (string) $tokenJson['access_token'];
        $plainRefreshToken = (string) $tokenJson['refresh_token'];
        $this->assertStringStartsWith('bs_at_', $plainAccessToken);
        $this->assertStringStartsWith('bs_rt_', $plainRefreshToken);

        // Assert access token is stored HASHED
        $atHash = hash('sha256', $plainAccessToken);
        $atStmt = $pdo->prepare("SELECT * FROM `{$atTable}` WHERE `token_hash` = :hash");
        $atStmt->execute(['hash' => $atHash]);
        $atRow = $atStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($atRow);
        $this->assertSame($atHash, $atRow['token_hash']);

        // Assert refresh token is stored HASHED
        $rtHash = hash('sha256', $plainRefreshToken);
        $rtStmt = $pdo->prepare("SELECT * FROM `{$rtTable}` WHERE `token_hash` = :hash");
        $rtStmt->execute(['hash' => $rtHash]);
        $rtRow = $rtStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($rtRow);
        $this->assertSame($rtHash, $rtRow['token_hash']);

        // Confirm plain tokens do NOT exist in tables
        $plainAtCheck = $pdo->prepare("SELECT COUNT(*) FROM `{$atTable}` WHERE `token_hash` = :plain");
        $plainAtCheck->execute(['plain' => $plainAccessToken]);
        $this->assertSame(0, (int) $plainAtCheck->fetchColumn());

        $plainRtCheck = $pdo->prepare("SELECT COUNT(*) FROM `{$rtTable}` WHERE `token_hash` = :plain");
        $plainRtCheck->execute(['plain' => $plainRefreshToken]);
        $this->assertSame(0, (int) $plainRtCheck->fetchColumn());

        // 5. Verify access token validates properly through verifyAccessToken
        $verification = $controller->verifyAccessToken($plainAccessToken);
        $this->assertTrue($verification['valid']);
        $this->assertSame($clientId, $verification['client_id'] ?? null);
        $this->assertSame($userId, $verification['user_id'] ?? null);
        $this->assertSame('Alice Admin', $verification['user_name'] ?? null);
        $this->assertTrue(isset($verification['user_roles']));
        $this->assertContains('admin', $verification['user_roles']);

        // 6. Refresh token rotation
        $refreshReq = new ServerRequest('POST', new Uri('https://example.com/oauth/token'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);
        $refreshReq->getBody()->write(http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $plainRefreshToken,
        ]));
        $refreshReq->getBody()->rewind();

        $refreshRes = $controller->token($refreshReq);
        $this->assertSame(200, $refreshRes->getStatusCode());
        $refreshJson = json_decode((string) $refreshRes->getBody(), true);
        $newPlainAt = $refreshJson['access_token'];
        $newPlainRt = $refreshJson['refresh_token'];

        // Confirm old refresh token is marked rotated in DB
        $oldRtStmt = $pdo->prepare("SELECT `rotated_at` FROM `{$rtTable}` WHERE `token_hash` = :hash");
        $oldRtStmt->execute(['hash' => $rtHash]);
        $this->assertNotNull($oldRtStmt->fetchColumn(), 'Old refresh token must have rotated_at timestamp set');

        // 7. Security test: REUSE of old refresh token must trigger full grant revocation and write audit entry
        $reuseReq = new ServerRequest('POST', new Uri('https://example.com/oauth/token'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);
        $reuseReq->getBody()->write(http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $plainRefreshToken,
        ]));
        $reuseReq->getBody()->rewind();

        $reuseRes = $controller->token($reuseReq);
        $this->assertSame(400, $reuseRes->getStatusCode());
        $reuseJson = json_decode((string) $reuseRes->getBody(), true);
        $this->assertSame('invalid_grant', $reuseJson['error']);
        $this->assertStringContainsString('reuse detected', $reuseJson['error_description']);

        // Assert grant revoked in database
        $grantStmt = $pdo->prepare("SELECT `revoked_at` FROM `{$grantsTable}` LIMIT 1");
        $grantStmt->execute();
        $this->assertNotNull($grantStmt->fetchColumn(), 'Authorization grant must be revoked in DB upon refresh token reuse');

        // Assert audit entry written for reuse detection
        $auditStmt = $pdo->prepare("SELECT * FROM `{$auditTable}` WHERE `action` = 'oauth.refresh_token_reuse_detected'");
        $auditStmt->execute();
        $auditRow = $auditStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($auditRow);
        $this->assertSame('oauth_grant', $auditRow['subject_type']);

        // New access token must now be invalid
        $newAtVerification = $controller->verifyAccessToken($newPlainAt);
        $this->assertFalse($newAtVerification['valid'], 'Access token must be invalid after grant is revoked');
    }

    public function testGrantsApiPresenterKeySetAndIsolation(): void
    {
        $pdo = $this->connection->getPdo();
        $usersTable = $this->connection->prefixTable('users');
        $userRolesTable = $this->connection->prefixTable('user_roles');
        $clientsTable = $this->connection->prefixTable('oauth_clients');
        $grantsTable = $this->connection->prefixTable('oauth_grants');
        $auditTable = $this->connection->prefixTable('audit_log');

        $nowUtc = gmdate('Y-m-d H:i:s.v');

        // Seed Admin user (Alice)
        $aliceId = Ulid::generate();
        $pdo->prepare("INSERT INTO `{$usersTable}` (`id`, `email`, `password_hash`, `name`, `status`, `created_at`, `updated_at`)
            VALUES (:id, 'alice@corp.com', :hash, 'Alice Admin', 'active', :created_at, :updated_at)")
            ->execute(['id' => $aliceId, 'hash' => $this->hasher->hash('pass1'), 'created_at' => $nowUtc, 'updated_at' => $nowUtc]);
        $pdo->prepare("INSERT INTO `{$userRolesTable}` (`user_id`, `role_id`) VALUES (:u, :r)")
            ->execute(['u' => $aliceId, 'r' => SystemRoles::ROLE_ADMIN]);

        // Seed Regular user (Bob)
        $bobId = Ulid::generate();
        $pdo->prepare("INSERT INTO `{$usersTable}` (`id`, `email`, `password_hash`, `name`, `status`, `created_at`, `updated_at`)
            VALUES (:id, 'bob@corp.com', :hash, 'Bob Viewer', 'active', :created_at, :updated_at)")
            ->execute(['id' => $bobId, 'hash' => $this->hasher->hash('pass2'), 'created_at' => $nowUtc, 'updated_at' => $nowUtc]);
        $pdo->prepare("INSERT INTO `{$userRolesTable}` (`user_id`, `role_id`) VALUES (:u, :r)")
            ->execute(['u' => $bobId, 'r' => SystemRoles::ROLE_VIEWER]);

        // Seed OAuth client
        $clientDbPk = Ulid::generate();
        $clientId = 'bs_client_grants_test';
        $pdo->prepare("INSERT INTO `{$clientsTable}` (`id`, `client_id`, `name`, `redirect_uris`, `is_public`, `registration_method`, `created_at`)
            VALUES (:id, :cid, 'Claude Connector', '[\"https://claude.ai/cb\"]', 1, 'dynamic', :created_at)")
            ->execute(['id' => $clientDbPk, 'cid' => $clientId, 'created_at' => $nowUtc]);

        // Seed 1 grant for Alice and 1 grant for Bob
        $aliceGrantId = Ulid::generate();
        $pdo->prepare("INSERT INTO `{$grantsTable}` (`id`, `user_id`, `client_id`, `role_id`, `scopes`, `ai_mode`, `created_at`)
            VALUES (:id, :u, :c, :r, '[\"mcp:read\",\"mcp:write\"]', 'draft_only', :created_at)")
            ->execute(['id' => $aliceGrantId, 'u' => $aliceId, 'c' => $clientDbPk, 'r' => SystemRoles::ROLE_ADMIN, 'created_at' => $nowUtc]);

        $bobGrantId = Ulid::generate();
        $pdo->prepare("INSERT INTO `{$grantsTable}` (`id`, `user_id`, `client_id`, `role_id`, `scopes`, `ai_mode`, `created_at`)
            VALUES (:id, :u, :c, :r, '[\"mcp:read\"]', 'draft_only', :created_at)")
            ->execute(['id' => $bobGrantId, 'u' => $bobId, 'c' => $clientDbPk, 'r' => SystemRoles::ROLE_VIEWER, 'created_at' => $nowUtc]);

        $controller = new OAuthController($this->connection, $this->hasher, $this->auditLogger, $this->authorizer, $this->config);

        // 1. Alice (admin) lists grants: should see BOTH grants
        $aliceReq = (new ServerRequest('GET', new Uri('/api/v1/oauth/grants')))
            ->withAttribute('actor', Actor::user($aliceId, 'Alice Admin', [SystemRoles::ROLE_ADMIN]));
        $aliceRes = $controller->listGrants($aliceReq);
        $this->assertSame(200, $aliceRes->getStatusCode());
        $aliceJson = json_decode((string) $aliceRes->getBody(), true);
        $this->assertIsArray($aliceJson);
        $this->assertArrayHasKey('items', $aliceJson);
        $this->assertCount(2, $aliceJson['items']);

        // Strict Presenter Key-Set assertion
        $expectedKeys = [
            'id', 'client_id', 'client_name', 'user_id', 'user_name',
            'role_id', 'role_handle', 'scopes', 'ai_mode',
            'created_at', 'last_used_at', 'revoked_at',
        ];
        foreach ($aliceJson['items'] as $item) {
            $actualKeys = array_keys($item);
            sort($actualKeys);
            $sortedExpected = $expectedKeys;
            sort($sortedExpected);
            $this->assertSame($sortedExpected, $actualKeys, 'Grants API presenter must strictly match expected key set');
        }

        // 2. Bob (viewer) lists grants: should see ONLY his own grant (isolation)
        $bobReq = (new ServerRequest('GET', new Uri('/api/v1/oauth/grants')))
            ->withAttribute('actor', Actor::user($bobId, 'Bob Viewer', [SystemRoles::ROLE_VIEWER]));
        $bobRes = $controller->listGrants($bobReq);
        $this->assertSame(200, $bobRes->getStatusCode());
        $bobJson = json_decode((string) $bobRes->getBody(), true);
        $this->assertCount(1, $bobJson['items']);
        $this->assertSame($bobGrantId, $bobJson['items'][0]['id']);

        // 3. Bob attempts to delete Alice's grant: MUST return 403 Forbidden
        $bobDeleteReq = (new ServerRequest('DELETE', new Uri('/api/v1/oauth/grants/' . $aliceGrantId)))
            ->withAttribute('id', $aliceGrantId)
            ->withAttribute('actor', Actor::user($bobId, 'Bob Viewer', [SystemRoles::ROLE_VIEWER]));
        $bobDeleteRes = $controller->revokeGrant($bobDeleteReq);
        $this->assertSame(403, $bobDeleteRes->getStatusCode());

        // 4. Bob deletes his own grant: returns 200 and revokes grant
        $bobDeleteOwnReq = (new ServerRequest('DELETE', new Uri('/api/v1/oauth/grants/' . $bobGrantId)))
            ->withAttribute('id', $bobGrantId)
            ->withAttribute('actor', Actor::user($bobId, 'Bob Viewer', [SystemRoles::ROLE_VIEWER]));
        $bobDeleteOwnRes = $controller->revokeGrant($bobDeleteOwnReq);
        $this->assertSame(200, $bobDeleteOwnRes->getStatusCode());
        $delJson = json_decode((string) $bobDeleteOwnRes->getBody(), true);
        $this->assertSame('grant_revoked', $delJson['status']);
        $this->assertSame($bobGrantId, $delJson['id']);

        // Confirm revoked_at is set in DB
        $chkStmt = $pdo->prepare("SELECT `revoked_at` FROM `{$grantsTable}` WHERE `id` = :id");
        $chkStmt->execute(['id' => $bobGrantId]);
        $this->assertNotNull($chkStmt->fetchColumn());

        // Confirm audit log entry recorded
        $auditStmt = $pdo->prepare("SELECT * FROM `{$auditTable}` WHERE `action` = 'oauth.grant_revoked' AND `subject_id` = :id");
        $auditStmt->execute(['id' => $bobGrantId]);
        $auditRow = $auditStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($auditRow);
        $this->assertSame($bobId, $auditRow['actor_id']);
    }

    public function testRfc8707ResourceIndicatorValidation(): void
    {
        $pdo = $this->connection->getPdo();
        $usersTable = $this->connection->prefixTable('users');
        $userRolesTable = $this->connection->prefixTable('user_roles');
        $clientsTable = $this->connection->prefixTable('oauth_clients');

        $nowUtc = gmdate('Y-m-d H:i:s.v');
        $userId = Ulid::generate();
        $pdo->prepare("INSERT INTO `{$usersTable}` (`id`, `email`, `password_hash`, `name`, `status`, `created_at`, `updated_at`)
            VALUES (:id, 'res@example.com', :hash, 'Res User', 'active', :created_at, :updated_at)")
            ->execute(['id' => $userId, 'hash' => $this->hasher->hash('password123'), 'created_at' => $nowUtc, 'updated_at' => $nowUtc]);
        $pdo->prepare("INSERT INTO `{$userRolesTable}` (`user_id`, `role_id`) VALUES (:u, :r)")
            ->execute(['u' => $userId, 'r' => SystemRoles::ROLE_EDITOR]);

        $clientPk = Ulid::generate();
        $clientId = 'bs_client_rfc8707';
        $pdo->prepare("INSERT INTO `{$clientsTable}` (`id`, `client_id`, `name`, `redirect_uris`, `is_public`, `registration_method`, `created_at`)
            VALUES (:id, :cid, 'Resource Client', '[\"https://claude.ai/cb\"]', 1, 'dynamic', :created_at)")
            ->execute(['id' => $clientPk, 'cid' => $clientId, 'created_at' => $nowUtc]);

        $controller = new OAuthController($this->connection, $this->hasher, $this->auditLogger, $this->authorizer, $this->config);

        // 1. Authorize with mismatched resource indicator: returns 400 invalid_target
        $badReq = (new ServerRequest('GET', new Uri('https://example.com/oauth/authorize?client_id=' . $clientId . '&redirect_uri=https://claude.ai/cb&response_type=code&code_challenge=test&code_challenge_method=S256&resource=https://evil.example/mcp')))
            ->withAttribute('actor', Actor::user($userId, 'Res User', [SystemRoles::ROLE_EDITOR]));
        $badRes = $controller->authorizeGet($badReq);
        $this->assertSame(400, $badRes->getStatusCode());
        $badJson = json_decode((string) $badRes->getBody(), true);
        $this->assertSame('invalid_target', $badJson['error']);

        // 2. Authorize with valid resource indicator: returns 200 HTML consent screen
        $goodReq = (new ServerRequest('GET', new Uri('https://example.com/oauth/authorize?client_id=' . $clientId . '&redirect_uri=https://claude.ai/cb&response_type=code&code_challenge=test&code_challenge_method=S256&resource=https://example.com/mcp')))
            ->withAttribute('actor', Actor::user($userId, 'Res User', [SystemRoles::ROLE_EDITOR]));
        $goodRes = $controller->authorizeGet($goodReq);
        $this->assertSame(200, $goodRes->getStatusCode());
        $this->assertStringContainsString('Authorize Agent Access', (string) $goodRes->getBody());
    }

    public function testRoleDelegationAndFullModeConfirmation(): void
    {
        $pdo = $this->connection->getPdo();
        $usersTable = $this->connection->prefixTable('users');
        $userRolesTable = $this->connection->prefixTable('user_roles');
        $clientsTable = $this->connection->prefixTable('oauth_clients');

        $nowUtc = gmdate('Y-m-d H:i:s.v');
        $viewerId = Ulid::generate();
        $pdo->prepare("INSERT INTO `{$usersTable}` (`id`, `email`, `password_hash`, `name`, `status`, `created_at`, `updated_at`)
            VALUES (:id, 'viewer@example.com', :hash, 'Viewer Only', 'active', :created_at, :updated_at)")
            ->execute(['id' => $viewerId, 'hash' => $this->hasher->hash('viewpass'), 'created_at' => $nowUtc, 'updated_at' => $nowUtc]);
        $pdo->prepare("INSERT INTO `{$userRolesTable}` (`user_id`, `role_id`) VALUES (:u, :r)")
            ->execute(['u' => $viewerId, 'r' => SystemRoles::ROLE_VIEWER]);

        $clientPk = Ulid::generate();
        $clientId = 'bs_client_priv_test';
        $pdo->prepare("INSERT INTO `{$clientsTable}` (`id`, `client_id`, `name`, `redirect_uris`, `is_public`, `registration_method`, `created_at`)
            VALUES (:id, :cid, 'Priv Client', '[\"https://claude.ai/cb\"]', 1, 'dynamic', :created_at)")
            ->execute(['id' => $clientPk, 'cid' => $clientId, 'created_at' => $nowUtc]);

        $controller = new OAuthController($this->connection, $this->hasher, $this->auditLogger, $this->authorizer, $this->config);

        $sessionAttrs = [
            'id' => 'sess_priv',
            'user_id' => $viewerId,
            'name' => 'Viewer Only',
            'csrf_secret' => 'csrf_token_priv',
        ];

        // 1. Viewer attempts to delegate ADMIN role: MUST be rejected with 403 access_denied
        $escReq = (new ServerRequest('POST', new Uri('https://example.com/oauth/authorize'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]))->withAttribute('session', $sessionAttrs)
           ->withAttribute('actor', Actor::user($viewerId, 'Viewer Only', [SystemRoles::ROLE_VIEWER]));

        $escReq->getBody()->write(http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/cb',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'decision' => 'allow',
            'csrf_token' => 'csrf_token_priv',
            'password' => 'viewpass',
            'role_id' => SystemRoles::ROLE_ADMIN, // Escalation attempt!
            'ai_mode' => 'draft_only',
        ]));
        $escReq->getBody()->rewind();

        $escRes = $controller->authorizePost($escReq);
        $this->assertSame(403, $escRes->getStatusCode());
        $escJson = json_decode((string) $escRes->getBody(), true);
        $this->assertSame('access_denied', $escJson['error']);

        // 2. ai_mode=full WITHOUT confirm_full_mode=1: MUST be rejected with 400
        $fullReq = (new ServerRequest('POST', new Uri('https://example.com/oauth/authorize'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]))->withAttribute('session', $sessionAttrs)
           ->withAttribute('actor', Actor::user($viewerId, 'Viewer Only', [SystemRoles::ROLE_VIEWER]));

        $fullReq->getBody()->write(http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/cb',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'decision' => 'allow',
            'csrf_token' => 'csrf_token_priv',
            'password' => 'viewpass',
            'role_id' => SystemRoles::ROLE_VIEWER,
            'ai_mode' => 'full', // No confirm_full_mode
        ]));
        $fullReq->getBody()->rewind();

        $fullRes = $controller->authorizePost($fullReq);
        $this->assertSame(400, $fullRes->getStatusCode());
        $fullJson = json_decode((string) $fullRes->getBody(), true);
        $this->assertSame('invalid_request', $fullJson['error']);
        $this->assertStringContainsString('confirmation', $fullJson['error_description']);

        // 3. ai_mode=full WITH confirm_full_mode=1: Succeeds and redirects with code
        $fullGoodReq = (new ServerRequest('POST', new Uri('https://example.com/oauth/authorize'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]))->withAttribute('session', $sessionAttrs)
           ->withAttribute('actor', Actor::user($viewerId, 'Viewer Only', [SystemRoles::ROLE_VIEWER]));

        $fullGoodReq->getBody()->write(http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/cb',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'decision' => 'allow',
            'csrf_token' => 'csrf_token_priv',
            'password' => 'viewpass',
            'role_id' => SystemRoles::ROLE_VIEWER,
            'ai_mode' => 'full',
            'confirm_full_mode' => '1',
        ]));
        $fullGoodReq->getBody()->rewind();

        $fullGoodRes = $controller->authorizePost($fullGoodReq);
        $this->assertSame(302, $fullGoodRes->getStatusCode());
        $this->assertStringContainsString('code=', $fullGoodRes->getHeaderLine('Location'));
    }

    public function testTokenEndpointRejectsMismatchedRedirectUri(): void
    {
        $pdo = $this->connection->getPdo();
        $usersTable = $this->connection->prefixTable('users');
        $userRolesTable = $this->connection->prefixTable('user_roles');
        $clientsTable = $this->connection->prefixTable('oauth_clients');

        $nowUtc = gmdate('Y-m-d H:i:s.v');
        $userId = Ulid::generate();
        $pdo->prepare("INSERT INTO `{$usersTable}` (`id`, `email`, `password_hash`, `name`, `status`, `created_at`, `updated_at`)
            VALUES (:id, 'redirmismatch@example.com', :hash, 'Redir User', 'active', :created_at, :updated_at)")
            ->execute(['id' => $userId, 'hash' => $this->hasher->hash('password123'), 'created_at' => $nowUtc, 'updated_at' => $nowUtc]);
        $pdo->prepare("INSERT INTO `{$userRolesTable}` (`user_id`, `role_id`) VALUES (:u, :r)")
            ->execute(['u' => $userId, 'r' => SystemRoles::ROLE_EDITOR]);

        $clientPk = Ulid::generate();
        $clientId = 'bs_client_redir_match';
        $pdo->prepare("INSERT INTO `{$clientsTable}` (`id`, `client_id`, `name`, `redirect_uris`, `is_public`, `registration_method`, `created_at`)
            VALUES (:id, :cid, 'Redir Client', '[\"http://127.0.0.1:8123/cb\", \"http://127.0.0.1:9999/cb\"]', 1, 'dynamic', :created_at)")
            ->execute(['id' => $clientPk, 'cid' => $clientId, 'created_at' => $nowUtc]);

        $controller = new OAuthController($this->connection, $this->hasher, $this->auditLogger, $this->authorizer, $this->config);

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // 1. Authorize with redirect_uri = http://127.0.0.1:8123/cb
        $authReq = (new ServerRequest('POST', new Uri('https://example.com/oauth/authorize'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]))->withAttribute('session', [
            'id' => 'sess_redir',
            'user_id' => $userId,
            'name' => 'Redir User',
            'csrf_secret' => 'csrf_token_redir',
        ])->withAttribute('actor', Actor::user($userId, 'Redir User', [SystemRoles::ROLE_EDITOR]));

        $authReq->getBody()->write(http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'http://127.0.0.1:8123/cb',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'decision' => 'allow',
            'csrf_token' => 'csrf_token_redir',
            'password' => 'password123',
            'role_id' => SystemRoles::ROLE_EDITOR,
            'ai_mode' => 'draft_only',
        ]));
        $authReq->getBody()->rewind();

        $authRes = $controller->authorizePost($authReq);
        $this->assertSame(302, $authRes->getStatusCode());
        $authLoc = $authRes->getHeaderLine('Location');
        $this->assertStringStartsWith('http://127.0.0.1:8123/cb?', $authLoc);

        $query1 = parse_url($authLoc, PHP_URL_QUERY);
        parse_str(is_string($query1) ? $query1 : '', $params);
        $rawCode1 = $params['code'] ?? '';
        $code = is_string($rawCode1) ? $rawCode1 : '';
        $this->assertNotEmpty($code);

        // 2. Token request with mismatched redirect_uri (http://127.0.0.1:9999/cb): MUST be rejected with 400 invalid_grant
        $badTokenReq = (new ServerRequest('POST', new Uri('https://example.com/oauth/token'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]));
        $badTokenReq->getBody()->write(http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => 'http://127.0.0.1:9999/cb', // Differs from authorization redirect_uri!
        ]));
        $badTokenReq->getBody()->rewind();

        $badTokenRes = $controller->token($badTokenReq);
        $this->assertSame(400, $badTokenRes->getStatusCode());
        $badTokenJson = json_decode((string) $badTokenRes->getBody(), true);
        $this->assertSame('invalid_grant', $badTokenJson['error']);
        $this->assertStringContainsString('redirect_uri', $badTokenJson['error_description']);

        // 3. Issue a fresh code for matching redirect_uri: MUST succeed with 200 OK
        $codeVerifier2 = bin2hex(random_bytes(32));
        $codeChallenge2 = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier2, true)), '+/', '-_'), '=');

        $authReq2 = (new ServerRequest('POST', new Uri('https://example.com/oauth/authorize'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]))->withAttribute('session', [
            'id' => 'sess_redir2',
            'user_id' => $userId,
            'name' => 'Redir User',
            'csrf_secret' => 'csrf_token_redir',
        ])->withAttribute('actor', Actor::user($userId, 'Redir User', [SystemRoles::ROLE_EDITOR]));

        $authReq2->getBody()->write(http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'http://127.0.0.1:8123/cb',
            'code_challenge' => $codeChallenge2,
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'decision' => 'allow',
            'csrf_token' => 'csrf_token_redir',
            'password' => 'password123',
            'role_id' => SystemRoles::ROLE_EDITOR,
            'ai_mode' => 'draft_only',
        ]));
        $authReq2->getBody()->rewind();

        $authRes2 = $controller->authorizePost($authReq2);
        $query2 = parse_url($authRes2->getHeaderLine('Location'), PHP_URL_QUERY);
        parse_str(is_string($query2) ? $query2 : '', $params2);
        $rawCode2 = $params2['code'] ?? '';
        $code2 = is_string($rawCode2) ? $rawCode2 : '';

        $goodTokenReq = (new ServerRequest('POST', new Uri('https://example.com/oauth/token'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]));
        $goodTokenReq->getBody()->write(http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code2,
            'code_verifier' => $codeVerifier2,
            'redirect_uri' => 'http://127.0.0.1:8123/cb', // Exact match!
        ]));
        $goodTokenReq->getBody()->rewind();

        $goodTokenRes = $controller->token($goodTokenReq);
        $this->assertSame(200, $goodTokenRes->getStatusCode());
        $goodJson = json_decode((string) $goodTokenRes->getBody(), true);
        $this->assertArrayHasKey('access_token', $goodJson);
    }

    public function testRegistrationValidatesOversizedAndNonStringInputs(): void
    {
        $controller = new OAuthController($this->connection, $this->hasher, $this->auditLogger, $this->authorizer, $this->config);

        // 1. Oversized client_name (300 characters): returns 400 invalid_client_metadata
        $oversizedReq = new ServerRequest('POST', new Uri('https://example.com/oauth/register'), [
            'Content-Type' => 'application/json',
        ]);
        $oversizedReq->getBody()->write((string) json_encode([
            'client_name' => str_repeat('A', 300),
            'redirect_uris' => ['https://claude.ai/cb'],
        ]));
        $oversizedReq->getBody()->rewind();

        $oversizedRes = $controller->register($oversizedReq);
        $this->assertSame(400, $oversizedRes->getStatusCode());
        $oversizedJson = json_decode((string) $oversizedRes->getBody(), true);
        $this->assertSame('invalid_client_metadata', $oversizedJson['error']);
        $this->assertStringContainsString('190', $oversizedJson['error_description']);

        // 2. Non-string client_name (e.g. integer or array): returns 400 invalid_client_metadata
        $nonStringNameReq = new ServerRequest('POST', new Uri('https://example.com/oauth/register'), [
            'Content-Type' => 'application/json',
        ]);
        $nonStringNameReq->getBody()->write((string) json_encode([
            'client_name' => ['not', 'a', 'string'],
            'redirect_uris' => ['https://claude.ai/cb'],
        ]));
        $nonStringNameReq->getBody()->rewind();

        $nonStringNameRes = $controller->register($nonStringNameReq);
        $this->assertSame(400, $nonStringNameRes->getStatusCode());
        $nonStringNameJson = json_decode((string) $nonStringNameRes->getBody(), true);
        $this->assertSame('invalid_client_metadata', $nonStringNameJson['error']);

        // 3. Non-string element in redirect_uris: returns 400 invalid_client_metadata
        $nonStringUriReq = new ServerRequest('POST', new Uri('https://example.com/oauth/register'), [
            'Content-Type' => 'application/json',
        ]);
        $nonStringUriReq->getBody()->write((string) json_encode([
            'client_name' => 'Valid Name',
            'redirect_uris' => [12345],
        ]));
        $nonStringUriReq->getBody()->rewind();

        $nonStringUriRes = $controller->register($nonStringUriReq);
        $this->assertSame(400, $nonStringUriRes->getStatusCode());
        $nonStringUriJson = json_decode((string) $nonStringUriRes->getBody(), true);
        $this->assertSame('invalid_client_metadata', $nonStringUriJson['error']);

        // 4. Non-array redirect_uris: returns 400 invalid_client_metadata
        $nonArrayUriReq = new ServerRequest('POST', new Uri('https://example.com/oauth/register'), [
            'Content-Type' => 'application/json',
        ]);
        $nonArrayUriReq->getBody()->write((string) json_encode([
            'client_name' => 'Valid Name',
            'redirect_uris' => 'https://claude.ai/cb',
        ]));
        $nonArrayUriReq->getBody()->rewind();

        $nonArrayUriRes = $controller->register($nonArrayUriReq);
        $this->assertSame(400, $nonArrayUriRes->getStatusCode());
        $nonArrayUriJson = json_decode((string) $nonArrayUriRes->getBody(), true);
        $this->assertSame('invalid_client_metadata', $nonArrayUriJson['error']);

        // 5. Too many redirect_uris (> 10): returns 400 invalid_client_metadata
        $tooManyUris = [];
        for ($i = 0; $i < 11; $i++) {
            $tooManyUris[] = "https://example{$i}.com/cb";
        }
        $tooManyReq = new ServerRequest('POST', new Uri('https://example.com/oauth/register'), [
            'Content-Type' => 'application/json',
        ]);
        $tooManyReq->getBody()->write((string) json_encode([
            'client_name' => 'Valid Name',
            'redirect_uris' => $tooManyUris,
        ]));
        $tooManyReq->getBody()->rewind();

        $tooManyRes = $controller->register($tooManyReq);
        $this->assertSame(400, $tooManyRes->getStatusCode());
        $tooManyJson = json_decode((string) $tooManyRes->getBody(), true);
        $this->assertSame('invalid_client_metadata', $tooManyJson['error']);

        // 6. Oversized redirect_uri (> 2048 characters): returns 400 invalid_redirect_uri
        $oversizedUri = 'https://example.com/cb?' . str_repeat('a', 2050);
        $oversizedUriReq = new ServerRequest('POST', new Uri('https://example.com/oauth/register'), [
            'Content-Type' => 'application/json',
        ]);
        $oversizedUriReq->getBody()->write((string) json_encode([
            'client_name' => 'Valid Name',
            'redirect_uris' => [$oversizedUri],
        ]));
        $oversizedUriReq->getBody()->rewind();

        $oversizedUriRes = $controller->register($oversizedUriReq);
        $this->assertSame(400, $oversizedUriRes->getStatusCode());
        $oversizedUriJson = json_decode((string) $oversizedUriRes->getBody(), true);
        $this->assertSame('invalid_redirect_uri', $oversizedUriJson['error']);

        // 7. Exactly 190-character client_name and valid redirect_uri: returns 201 Created
        $boundaryReq = new ServerRequest('POST', new Uri('https://example.com/oauth/register'), [
            'Content-Type' => 'application/json',
        ]);
        $boundaryReq->getBody()->write((string) json_encode([
            'client_name' => str_repeat('B', 190),
            'redirect_uris' => ['https://claude.ai/cb'],
        ]));
        $boundaryReq->getBody()->rewind();

        $boundaryRes = $controller->register($boundaryReq);
        $this->assertSame(201, $boundaryRes->getStatusCode());
        $boundaryJson = json_decode((string) $boundaryRes->getBody(), true);
        $this->assertSame(str_repeat('B', 190), $boundaryJson['client_name']);
    }

    public function testAuditLogCapturesAllFourOAuthActions(): void
    {
        $pdo = $this->connection->getPdo();
        $usersTable = $this->connection->prefixTable('users');
        $userRolesTable = $this->connection->prefixTable('user_roles');
        $clientsTable = $this->connection->prefixTable('oauth_clients');

        $nowUtc = gmdate('Y-m-d H:i:s.v');
        $adminId = Ulid::generate();
        $pdo->prepare("INSERT INTO `{$usersTable}` (`id`, `email`, `password_hash`, `name`, `status`, `created_at`, `updated_at`)
            VALUES (:id, 'audit-admin@example.com', :hash, 'Audit Admin', 'active', :created_at, :updated_at)")
            ->execute(['id' => $adminId, 'hash' => $this->hasher->hash('adminpass'), 'created_at' => $nowUtc, 'updated_at' => $nowUtc]);
        $pdo->prepare("INSERT INTO `{$userRolesTable}` (`user_id`, `role_id`) VALUES (:u, :r)")
            ->execute(['u' => $adminId, 'r' => SystemRoles::ROLE_ADMIN]);

        $clientPk = Ulid::generate();
        $clientId = 'bs_client_audit_test';
        $pdo->prepare("INSERT INTO `{$clientsTable}` (`id`, `client_id`, `name`, `redirect_uris`, `is_public`, `registration_method`, `created_at`)
            VALUES (:id, :cid, 'Audit Client', '[\"https://claude.ai/cb\"]', 1, 'dynamic', :created_at)")
            ->execute(['id' => $clientPk, 'cid' => $clientId, 'created_at' => $nowUtc]);

        $controller = new OAuthController($this->connection, $this->hasher, $this->auditLogger, $this->authorizer, $this->config);
        $auditController = new AuditController($this->auditLogger, $this->authorizer);

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // Action 1: oauth.grant_created (via authorizePost)
        $authReq = (new ServerRequest('POST', new Uri('https://example.com/oauth/authorize'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]))->withAttribute('session', [
            'id' => 'sess_audit',
            'user_id' => $adminId,
            'name' => 'Audit Admin',
            'csrf_secret' => 'csrf_token_audit',
        ])->withAttribute('actor', Actor::user($adminId, 'Audit Admin', [SystemRoles::ROLE_ADMIN]));

        $authReq->getBody()->write(http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/cb',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'decision' => 'allow',
            'csrf_token' => 'csrf_token_audit',
            'password' => 'adminpass',
            'role_id' => SystemRoles::ROLE_ADMIN,
            'ai_mode' => 'draft_only',
        ]));
        $authReq->getBody()->rewind();

        $authRes = $controller->authorizePost($authReq);
        $this->assertSame(302, $authRes->getStatusCode());
        $query3 = parse_url($authRes->getHeaderLine('Location'), PHP_URL_QUERY);
        parse_str(is_string($query3) ? $query3 : '', $authParams);
        $rawAuthCode = $authParams['code'] ?? '';
        $authCode = is_string($rawAuthCode) ? $rawAuthCode : '';

        // Exchange for token pair
        $tokenReq = new ServerRequest('POST', new Uri('https://example.com/oauth/token'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);
        $tokenReq->getBody()->write(http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $authCode,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => 'https://claude.ai/cb',
        ]));
        $tokenReq->getBody()->rewind();

        $tokenRes = $controller->token($tokenReq);
        $this->assertSame(200, $tokenRes->getStatusCode());
        $tokenJson = json_decode((string) $tokenRes->getBody(), true);
        $accessToken = (string) $tokenJson['access_token'];
        $refreshToken = (string) $tokenJson['refresh_token'];

        // Action 2: oauth.token_revoked (via POST /oauth/revoke)
        $revokeReq = (new ServerRequest('POST', new Uri('https://example.com/oauth/revoke'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]));
        $revokeReq->getBody()->write(http_build_query([
            'token' => $accessToken,
            'token_type_hint' => 'access_token',
        ]));
        $revokeReq->getBody()->rewind();

        $revokeRes = $controller->revoke($revokeReq);
        $this->assertSame(200, $revokeRes->getStatusCode());

        // Rotate refresh token to get an active token and a rotated predecessor
        $rotateReq = (new ServerRequest('POST', new Uri('https://example.com/oauth/token'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]));
        $rotateReq->getBody()->write(http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ]));
        $rotateReq->getBody()->rewind();
        $rotateRes = $controller->token($rotateReq);
        $this->assertSame(200, $rotateRes->getStatusCode());

        // Action 3: oauth.refresh_token_reuse_detected (replay already rotated refresh token)
        $reuseReq = (new ServerRequest('POST', new Uri('https://example.com/oauth/token'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]));
        $reuseReq->getBody()->write(http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken, // REUSE!
        ]));
        $reuseReq->getBody()->rewind();
        $reuseRes = $controller->token($reuseReq);
        $this->assertSame(400, $reuseRes->getStatusCode());

        // Create another grant to test grant_revoked via DELETE /api/v1/oauth/grants/{id}
        $codeVerifier3 = bin2hex(random_bytes(32));
        $codeChallenge3 = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier3, true)), '+/', '-_'), '=');

        $authReq3 = (new ServerRequest('POST', new Uri('https://example.com/oauth/authorize'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]))->withAttribute('session', [
            'id' => 'sess_audit3',
            'user_id' => $adminId,
            'name' => 'Audit Admin',
            'csrf_secret' => 'csrf_token_audit',
        ])->withAttribute('actor', Actor::user($adminId, 'Audit Admin', [SystemRoles::ROLE_ADMIN]));

        $authReq3->getBody()->write(http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/cb',
            'code_challenge' => $codeChallenge3,
            'code_challenge_method' => 'S256',
            'response_type' => 'code',
            'decision' => 'allow',
            'csrf_token' => 'csrf_token_audit',
            'password' => 'adminpass',
            'role_id' => SystemRoles::ROLE_ADMIN,
            'ai_mode' => 'draft_only',
        ]));
        $authReq3->getBody()->rewind();

        $authRes3 = $controller->authorizePost($authReq3);
        $this->assertSame(302, $authRes3->getStatusCode());

        // Get the newly created grant
        $listReq = (new ServerRequest('GET', new Uri('https://example.com/api/v1/oauth/grants')))
            ->withAttribute('actor', Actor::user($adminId, 'Audit Admin', [SystemRoles::ROLE_ADMIN]));
        $listRes = $controller->listGrants($listReq);
        $listJson = json_decode((string) $listRes->getBody(), true);
        $grantToRevoke = $listJson['items'][0]['id'];

        // Action 4: oauth.grant_revoked (via DELETE /api/v1/oauth/grants/{id})
        $delReq = (new ServerRequest('DELETE', new Uri('https://example.com/api/v1/oauth/grants/' . $grantToRevoke)))
            ->withAttribute('id', $grantToRevoke)
            ->withAttribute('actor', Actor::user($adminId, 'Audit Admin', [SystemRoles::ROLE_ADMIN]));
        $delRes = $controller->revokeGrant($delReq);
        $this->assertSame(200, $delRes->getStatusCode());

        // Verify all 4 actions appear in GET /api/v1/audit
        $auditListReq = (new ServerRequest('GET', new Uri('https://example.com/api/v1/audit?limit=50')))
            ->withAttribute('actor', Actor::user($adminId, 'Audit Admin', [SystemRoles::ROLE_ADMIN]));
        $auditListRes = $auditController->list($auditListReq);
        $this->assertSame(200, $auditListRes->getStatusCode());
        $auditJson = json_decode((string) $auditListRes->getBody(), true);
        $actions = array_column($auditJson['items'], 'action');

        $this->assertContains('oauth.grant_created', $actions, 'Audit trail must contain oauth.grant_created');
        $this->assertContains('oauth.token_revoked', $actions, 'Audit trail must contain oauth.token_revoked');
        $this->assertContains('oauth.grant_revoked', $actions, 'Audit trail must contain oauth.grant_revoked');
        $this->assertContains('oauth.refresh_token_reuse_detected', $actions, 'Audit trail must contain oauth.refresh_token_reuse_detected');
    }
}
