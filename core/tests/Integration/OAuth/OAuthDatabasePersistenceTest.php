<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\OAuth;

use BsCms\Auth\Actor;
use BsCms\Auth\PasswordHasher;
use BsCms\Auth\SystemRoles;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Kernel\Config;
use BsCms\OAuth\OAuthController;
use BsCms\Security\Ulid;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test proving Milestone 8.1 database persistence:
 * - Real MySQL/MariaDB database with migration 0009 applied
 * - oauth_clients, oauth_grants, oauth_authorization_codes, oauth_access_tokens, oauth_refresh_tokens
 * - Verification that tokens and codes are stored exclusively as SHA-256 hashes (never plaintext)
 * - Verification that refresh token reuse triggers full grant revocation in DB
 */
final class OAuthDatabasePersistenceTest extends TestCase
{
    private Connection $connection;
    private string $dbName = '';
    private PDO $adminPdo;
    private PasswordHasher $hasher;
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';

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

        $controller = new OAuthController(null, $this->connection, $this->hasher, $this->config);

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
        $this->assertIsString($authQuery);
        parse_str($authQuery, $authParams);
        $rawCode = $authParams['code'] ?? '';
        $this->assertIsString($rawCode);
        $plainCode = $rawCode;
        $this->assertStringStartsWith('bs_code_', $plainCode);

        // Verify plainCode does NOT exist in oauth_authorization_codes table
        $plainCodeCheck = $pdo->prepare("SELECT COUNT(*) FROM `{$codesTable}` WHERE `code_hash` = :plain");
        $plainCodeCheck->execute(['plain' => $plainCode]);
        $this->assertSame(0, (int) $plainCodeCheck->fetchColumn(), 'Plaintext code must NEVER be stored in the database');

        // Verify SHA-256 hash exists in oauth_authorization_codes table
        $codeHash = hash('sha256', $plainCode);
        $hashedCodeCheck = $pdo->prepare("SELECT COUNT(*) FROM `{$codesTable}` WHERE `code_hash` = :hash");
        $hashedCodeCheck->execute(['hash' => $codeHash]);
        $this->assertSame(1, (int) $hashedCodeCheck->fetchColumn(), 'SHA-256 hash of the code must exist in the database');

        // 4. Token exchange (code -> access_token + refresh_token)
        $tokenReq = new ServerRequest('POST', new Uri('https://example.com/oauth/token'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);
        $tokenReq->getBody()->write(http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $plainCode,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => 'https://claude.ai/api/mcp/oauth/callback',
        ]));
        $tokenReq->getBody()->rewind();

        $tokenRes = $controller->token($tokenReq);
        $this->assertSame(200, $tokenRes->getStatusCode());
        $tokenJson = json_decode((string) $tokenRes->getBody(), true);

        $plainAccessToken = (string) $tokenJson['access_token'];
        $plainRefreshToken = (string) $tokenJson['refresh_token'];
        $this->assertStringStartsWith('bs_at_', $plainAccessToken);
        $this->assertStringStartsWith('bs_rt_', $plainRefreshToken);

        // Verify plaintext tokens do NOT exist in database
        $plainAtCheck = $pdo->prepare("SELECT COUNT(*) FROM `{$atTable}` WHERE `token_hash` = :plain");
        $plainAtCheck->execute(['plain' => $plainAccessToken]);
        $this->assertSame(0, (int) $plainAtCheck->fetchColumn(), 'Plaintext access token must NEVER be stored in the database');

        $plainRtCheck = $pdo->prepare("SELECT COUNT(*) FROM `{$rtTable}` WHERE `token_hash` = :plain");
        $plainRtCheck->execute(['plain' => $plainRefreshToken]);
        $this->assertSame(0, (int) $plainRtCheck->fetchColumn(), 'Plaintext refresh token must NEVER be stored in the database');

        // Verify SHA-256 hashes DO exist in database
        $atHash = hash('sha256', $plainAccessToken);
        $rtHash = hash('sha256', $plainRefreshToken);

        $atHashCheck = $pdo->prepare("SELECT COUNT(*) FROM `{$atTable}` WHERE `token_hash` = :hash");
        $atHashCheck->execute(['hash' => $atHash]);
        $this->assertSame(1, (int) $atHashCheck->fetchColumn(), 'Hashed access token must exist in the database');

        $rtHashCheck = $pdo->prepare("SELECT COUNT(*) FROM `{$rtTable}` WHERE `token_hash` = :hash");
        $rtHashCheck->execute(['hash' => $rtHash]);
        $this->assertSame(1, (int) $rtHashCheck->fetchColumn(), 'Hashed refresh token must exist in the database');

        // 5. Verify token verification works against DB
        $verification = $controller->verifyAccessToken($plainAccessToken);
        $this->assertTrue($verification['valid']);
        $this->assertTrue(isset($verification['client_id']));
        $this->assertSame($clientId, $verification['client_id']);
        $this->assertTrue(isset($verification['user_id']));
        $this->assertSame($userId, $verification['user_id']);
        $this->assertTrue(isset($verification['user_name']));
        $this->assertSame('Alice Admin', $verification['user_name']);
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

        // 7. Security test: REUSE of old refresh token must trigger full grant revocation
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

        // New access token must now be invalid
        $newAtVerification = $controller->verifyAccessToken($newPlainAt);
        $this->assertFalse($newAtVerification['valid'], 'Access token must be invalid after grant is revoked');
    }
}
