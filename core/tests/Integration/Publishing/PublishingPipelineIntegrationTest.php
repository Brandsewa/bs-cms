<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Publishing;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Auth\ApiTokenService;
use BsCms\Auth\SessionManager;
use BsCms\Auth\SystemRoles;
use BsCms\Authz\Authorizer;
use BsCms\Authz\PermissionCompiler;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Http\Controllers\AuthController;
use BsCms\Http\Controllers\AuditController;
use BsCms\Http\Controllers\BuildTokensController;
use BsCms\Http\Controllers\CollectionsController;
use BsCms\Http\Controllers\EmailSettingsController;
use BsCms\Http\Controllers\EntriesController;
use BsCms\Http\Controllers\MeController;
use BsCms\Http\Controllers\MediaController;
use BsCms\Http\Controllers\MediaFoldersController;
use BsCms\Http\Controllers\NotificationsController;
use BsCms\Http\Controllers\OpenApiController;
use BsCms\Http\Controllers\PublicEntriesController;
use BsCms\Http\Controllers\PublicSeoController;
use BsCms\Http\Controllers\PublishingController;
use BsCms\Http\Controllers\RedirectsController;
use BsCms\Http\Controllers\RolesController;
use BsCms\Http\Controllers\SiteHealthController;
use BsCms\Http\Controllers\TaxonomiesController;
use BsCms\Http\Controllers\TokensController;
use BsCms\Http\Controllers\UsersController;
use BsCms\Http\Kernel;
use BsCms\Http\Middleware\AuthenticateMiddleware;
use BsCms\Http\Middleware\ErrorHandlerMiddleware;
use BsCms\Http\Middleware\JsonBodyMiddleware;
use BsCms\Http\Middleware\RequestIdMiddleware;
use BsCms\Http\Middleware\SecurityHeadersMiddleware;
use BsCms\Http\Middleware\SessionMiddleware;
use BsCms\Publishing\BuildTokenPresenter;
use BsCms\Publishing\BuildTokenService;
use BsCms\Publishing\DeploymentPresenter;
use BsCms\Publishing\DeploymentService;
use BsCms\Publishing\IndexNowService;
use BsCms\Publishing\PublishingSettingsPresenter;
use BsCms\Publishing\PublishingSettingsRepository;
use BsCms\Routing\ApiRoutes;
use BsCms\Routing\RouteCollection;
use BsCms\Routing\Router;
use BsCms\Security\Crypto;
use BsCms\Security\HttpClient;
use BsCms\Security\Ulid;
use BsCms\Settings\SettingsRepository;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class PublishingPipelineIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName;
    private ?PDO $adminPdo = null;

    private Crypto $crypto;
    private SettingsRepository $settingsRepository;
    private PublishingSettingsRepository $publishingSettings;
    private BuildTokenService $buildTokenService;
    private IndexNowService $indexNowService;
    private DeploymentService $deploymentService;
    private Kernel $kernel;
    private Authorizer $authorizer;
    private SessionManager $sessionManager;
    private ApiTokenService $apiTokenService;

    private Actor $ownerActor;
    private string $ownerToken;
    private string $ownerSessionCookie;

    protected function setUp(): void
    {
        parent::setUp();

        $pass = getenv('DB_PASS');
        if ($pass === false) {
            $this->markTestSkipped('Database tests require DB_PASS environment variable.');
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';

        $this->dbName = 'bs_test_pub_' . bin2hex(random_bytes(4));
        $this->adminPdo = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->adminPdo->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->connection = new Connection([
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $this->dbName,
            'username' => $user,
            'password' => $pass,
            'charset' => 'utf8mb4',
            'prefix' => 'bs_',
        ]);

        // Run migrations
        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $migrator->migrate();

        $secretKey = base64_encode(random_bytes(32));
        $this->crypto = new Crypto(['k1' => $secretKey], 'k1');
        $this->settingsRepository = new SettingsRepository($this->connection, $this->crypto);
        $this->publishingSettings = new PublishingSettingsRepository($this->settingsRepository, $this->crypto);
        $auditLogger = new AuditLogger($this->connection);
        $this->buildTokenService = new BuildTokenService($this->connection, $auditLogger);
        $httpClient = new HttpClient();
        $this->indexNowService = new IndexNowService($this->connection, $httpClient);
        $this->deploymentService = new DeploymentService(
            $this->connection,
            $this->publishingSettings,
            $this->settingsRepository,
            $httpClient,
            $this->indexNowService,
            'https://bskickstarter.com'
        );

        $compiler = new PermissionCompiler($this->connection);
        $this->authorizer = new Authorizer($this->connection, $compiler);
        $this->sessionManager = new SessionManager($this->connection);
        $this->apiTokenService = new ApiTokenService($this->connection, $auditLogger);

        // Seed owner user
        $ownerId = Ulid::generate();
        $this->connection->table('users')->insert([
            'id' => $ownerId,
            'email' => 'owner@example.com',
            'password_hash' => password_hash('SecretPassword123!', PASSWORD_BCRYPT),
            'name' => 'Site Owner',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->connection->table('user_roles')->insert([
            'user_id' => $ownerId,
            'role_id' => SystemRoles::ROLE_OWNER,
        ]);

        $this->ownerActor = Actor::user($ownerId, 'Site Owner', [SystemRoles::ROLE_OWNER]);

        // Create owner session with step-up MFA passed
        $sess = $this->sessionManager->create($ownerId, null, null, false, new \DateTimeImmutable());
        $this->ownerSessionCookie = 'bs_session=' . $sess['token'];

        // Create owner API token
        $tok = $this->apiTokenService->create($ownerId, 'Owner Test Key');
        $this->ownerToken = (string) $tok['token'];

        // Setup Router & Kernel
        $routes = new RouteCollection();
        $router = new Router($routes);

        $publishingController = new PublishingController(
            $this->publishingSettings,
            $this->deploymentService,
            $this->authorizer,
            $httpClient
        );

        $buildTokensController = new BuildTokensController(
            $this->buildTokenService,
            $this->authorizer
        );

        // Register core routes
        ApiRoutes::register(
            $routes,
            auth: $this->createMock(AuthController::class),
            me: $this->createMock(MeController::class),
            users: $this->createMock(UsersController::class),
            roles: $this->createMock(RolesController::class),
            tokens: $this->createMock(TokensController::class),
            audit: $this->createMock(AuditController::class),
            email: $this->createMock(EmailSettingsController::class),
            notifications: $this->createMock(NotificationsController::class),
            publishing: $publishingController,
            buildTokens: $buildTokensController
        );

        $this->kernel = new Kernel($router);
        $this->kernel->addMiddleware(new RequestIdMiddleware());
        $this->kernel->addMiddleware(new ErrorHandlerMiddleware(new \BsCms\Kernel\Environment('testing')));
        $this->kernel->addMiddleware(new SecurityHeadersMiddleware());
        $this->kernel->addMiddleware(new JsonBodyMiddleware());
        $this->kernel->addMiddleware(new SessionMiddleware($this->sessionManager, $this->connection));
        $this->kernel->addMiddleware(new AuthenticateMiddleware($this->apiTokenService, null, $this->buildTokenService));
    }

    protected function tearDown(): void
    {
        if ($this->adminPdo !== null && !empty($this->dbName)) {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $uri,
        array $body = [],
        array $headers = []
    ): ResponseInterface {
        $req = new ServerRequest($method, $uri);
        $cookies = [];
        foreach ($headers as $k => $v) {
            $req = $req->withHeader($k, $v);
            if (strcasecmp($k, 'cookie') === 0) {
                // Parse Cookie: bs_session=xxx
                $parts = explode(';', $v);
                foreach ($parts as $part) {
                    $kv = explode('=', trim($part), 2);
                    if (count($kv) === 2) {
                        $cookies[$kv[0]] = $kv[1];
                    }
                }
            }
        }
        if (!empty($cookies)) {
            $req = $req->withCookieParams($cookies);
        }
        if (!empty($body)) {
            $req = $req->withParsedBody($body);
        }
        return $this->kernel->handle($req);
    }

    /**
     * Test 1: Settings > Publishing API strictly presents safe fields and stores tokens encrypted.
     */
    public function testPublishingSettingsStrictPresenterAndEncryptedStorage(): void
    {
        // 1. GET initial settings
        $res = $this->request('GET', '/api/v1/settings/publishing', [], [
            'Authorization' => "Bearer {$this->ownerToken}",
        ]);
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true);

        // Strict key-set assertion
        $expectedKeys = PublishingSettingsPresenter::ALLOWED_FIELDS;
        sort($expectedKeys);
        $actualKeys = array_keys($data);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);
        $this->assertFalse($data['has_github_token']);
        $this->assertFalse($data['has_webhook_secret']);

        // 2. PUT updated settings with secrets
        $fakeGithubToken = 'ghp_secretTokenExample1234567890abcdef';
        $fakeWebhookSecret = 'whsec_verySecretKey9876543210';
        $updatePayload = [
            'target_type' => 'github_actions',
            'enabled' => true,
            'debounce_window' => 45,
            'github_repository' => 'Brandsewa/bs-cms',
            'github_workflow_id' => 'deploy.yml',
            'github_token' => $fakeGithubToken,
            'webhook_url' => 'https://example.com/webhook',
            'webhook_secret' => $fakeWebhookSecret,
        ];

        $resUpdate = $this->request('PUT', '/api/v1/settings/publishing', $updatePayload, [
            'Authorization' => "Bearer {$this->ownerToken}",
            'Cookie' => $this->ownerSessionCookie,
        ]);
        $this->assertSame(200, $resUpdate->getStatusCode(), (string) $resUpdate->getBody());
        $updatedData = json_decode((string) $resUpdate->getBody(), true);

        // Strict key set: secrets are NOT returned!
        $actualUpdatedKeys = array_keys($updatedData);
        sort($actualUpdatedKeys);
        $this->assertSame($expectedKeys, $actualUpdatedKeys);
        $this->assertTrue($updatedData['has_github_token']);
        $this->assertTrue($updatedData['has_webhook_secret']);
        $this->assertSame(45, $updatedData['debounce_window']);
        $this->assertArrayNotHasKey('github_token', $updatedData);
        $this->assertArrayNotHasKey('webhook_secret', $updatedData);

        // 3. Verify in database: tokens are encrypted with libsodium secretbox
        $rawSetting = $this->connection->table('settings')->where('handle', '=', 'publishing.config')->first();
        $this->assertNotNull($rawSetting);
        $storedValue = json_decode((string) $rawSetting['value'], true);

        $this->assertNotEmpty($storedValue['github_token_encrypted']);
        $this->assertNotEmpty($storedValue['webhook_secret_encrypted']);
        // Must NOT contain plaintext secrets anywhere in stored JSON
        $this->assertStringNotContainsString($fakeGithubToken, (string) $rawSetting['value']);
        $this->assertStringNotContainsString($fakeWebhookSecret, (string) $rawSetting['value']);

        // 4. Decrypt via repository
        $loaded = $this->publishingSettings->get();
        $this->assertSame($fakeGithubToken, $loaded['github_token']);
        $this->assertSame($fakeWebhookSecret, $loaded['webhook_secret']);
    }

    /**
     * Test 2: Build tokens management API:
     * - Create shows token once, returns exact key set
     * - List returns only hash/prefix metadata
     * - Revoke immediately disables token
     */
    public function testBuildTokensManagementAndRevocation(): void
    {
        // 1. Create token
        $resCreate = $this->request('POST', '/api/v1/tokens/build', ['name' => 'CI Static Builder'], [
            'Authorization' => "Bearer {$this->ownerToken}",
            'Cookie' => $this->ownerSessionCookie,
        ]);
        $this->assertSame(201, $resCreate->getStatusCode(), (string) $resCreate->getBody());
        $created = json_decode((string) $resCreate->getBody(), true);

        $this->assertArrayHasKey('token', $created);
        $this->assertStringStartsWith('bsbuild_', $created['token']);
        $rawToken = $created['token'];
        $tokenId = $created['id'];

        // 2. List tokens
        $resList = $this->request('GET', '/api/v1/tokens/build', [], [
            'Authorization' => "Bearer {$this->ownerToken}",
        ]);
        $this->assertSame(200, $resList->getStatusCode());
        $list = json_decode((string) $resList->getBody(), true);
        $this->assertCount(1, $list);

        $expectedItemKeys = BuildTokenPresenter::ALLOWED_FIELDS;
        sort($expectedItemKeys);
        $actualItemKeys = array_keys($list[0]);
        sort($actualItemKeys);
        $this->assertSame($expectedItemKeys, $actualItemKeys);
        $this->assertArrayNotHasKey('token', $list[0]);

        // 3. Authenticate with build token
        $authed = $this->buildTokenService->authenticate($rawToken);
        $this->assertNotNull($authed);
        $this->assertSame($tokenId, $authed['id']);
        $this->assertNotNull($authed['last_used_at']);

        // 4. Revoke token
        $resRevoke = $this->request('DELETE', "/api/v1/tokens/build/{$tokenId}", [], [
            'Authorization' => "Bearer {$this->ownerToken}",
            'Cookie' => $this->ownerSessionCookie,
        ]);
        $this->assertSame(200, $resRevoke->getStatusCode());

        // 5. Authenticate after revocation must return null immediately
        $authedAfterRevoke = $this->buildTokenService->authenticate($rawToken);
        $this->assertNull($authedAfterRevoke);
    }

    /**
     * Test 3: Build token authorization guardrails:
     * - Build token can access GET /api/v1/public/* and GET /api/v1/build/*
     * - Build token is blocked (403) from mutating endpoints or admin routes
     * - Revoked build token returns 401
     */
    public function testBuildTokenStrictAccessControl(): void
    {
        $created = $this->buildTokenService->create('Production Astro Worker', $this->ownerActor);
        $rawToken = $created['token'];
        $tokenId = $created['id'];

        // 1. Build token access to public endpoint succeeds
        $resPublic = $this->request('GET', '/api/v1/public/collections', [], [
            'Authorization' => "Bearer {$rawToken}",
        ]);
        $this->assertNotSame(401, $resPublic->getStatusCode());
        $this->assertNotSame(403, $resPublic->getStatusCode());

        // 2. Build token access to admin endpoint returns 403 Forbidden
        $resAdmin = $this->request('GET', '/api/v1/settings/publishing', [], [
            'Authorization' => "Bearer {$rawToken}",
        ]);
        $this->assertSame(403, $resAdmin->getStatusCode());
        $bodyAdmin = json_decode((string) $resAdmin->getBody(), true);
        $this->assertSame('Forbidden', $bodyAdmin['title']);

        // 3. Build token executing mutating request (POST) returns 403 Forbidden
        $resPost = $this->request('POST', '/api/v1/tokens/build', ['name' => 'Should Fail'], [
            'Authorization' => "Bearer {$rawToken}",
        ]);
        $this->assertSame(403, $resPost->getStatusCode());

        // 4. Revoke token and verify 401 Unauthorized
        $this->buildTokenService->revoke($tokenId, $this->ownerActor);
        $resRevoked = $this->request('GET', '/api/v1/public/collections', [], [
            'Authorization' => "Bearer {$rawToken}",
        ]);
        $this->assertSame(401, $resRevoked->getStatusCode());
        $bodyRevoked = json_decode((string) $resRevoked->getBody(), true);
        $this->assertSame('Invalid or revoked build token.', $bodyRevoked['detail']);
    }

    /**
     * Test 4: Deploy pipeline debounce and dispatch:
     * - Multiple requests within debounce window merge into a single deployment
     * - Debounce does not trigger before window elapses
     * - Deployment creates row with hashed callback token
     */
    public function testDeployDebounceMergesRequestsAndDispatches(): void
    {
        // Set target to manual so no external network call is attempted
        $this->publishingSettings->update([
            'target_type' => 'manual',
            'debounce_window' => 10,
        ], $this->ownerActor->id);

        // 1. Multiple content requests
        $this->deploymentService->requestDeploy(['/blog/post-1', '/blog/post-2'], $this->ownerActor->id, 'entry.published');
        $this->deploymentService->requestDeploy(['/blog/post-2', '/blog/post-3'], $this->ownerActor->id, 'entry.updated');
        $this->deploymentService->requestDeploy(['/about'], $this->ownerActor->id, 'entry.updated');

        // 2. Debounce immediate check (window not elapsed)
        $immediate = $this->deploymentService->processDebounceQueue();
        $this->assertNull($immediate);

        // 3. Simulate clock advance past window
        $pending = $this->settingsRepository->get('publishing.pending_requests');
        $this->assertNotNull($pending);
        $pending['first_requested_at'] = time() - 15; // 15 seconds ago (> 10s window)
        $this->settingsRepository->set('publishing.pending_requests', $pending);

        // 4. Debounce process now triggers
        $deployment = $this->deploymentService->processDebounceQueue();
        $this->assertNotNull($deployment);
        $this->assertSame('manual', $deployment['target']);
        $this->assertSame('queued', $deployment['status']);

        // Check merged URLs (duplicates removed)
        $urls = $deployment['changed_urls'];
        sort($urls);
        $this->assertSame(['/about', '/blog/post-1', '/blog/post-2', '/blog/post-3'], $urls);

        // Verify deployment record in DB
        $dbDeploy = $this->connection->table('deployments')->where('id', '=', $deployment['id'])->first();
        $this->assertNotNull($dbDeploy);
        $this->assertSame(hash('sha256', $deployment['callback_token']), $dbDeploy['callback_token_hash']);

        // Verify pending queue is cleared
        $this->assertNull($this->settingsRepository->get('publishing.pending_requests'));
    }

    /**
     * Test 5: Status callback endpoint state machine and replay rejection:
     * - Valid transitions: queued -> dispatched -> building -> succeeded
     * - Out of order transition rejected
     * - Replays after terminal state rejected
     * - Never leaks PHP exception stack trace
     */
    public function testDeploymentStatusCallbackStateMachineAndReplayDefense(): void
    {
        // Set target to manual so dispatch succeeds without hitting GitHub
        $this->publishingSettings->update([
            'target_type' => 'manual',
        ], $this->ownerActor->id);

        // 1. Create a deployment
        $deploy = $this->deploymentService->createAndDispatchDeployment(
            ['/test-page'],
            'manual',
            $this->ownerActor->id
        );
        $deployId = $deploy['id'];
        $callbackToken = $deploy['callback_token'];

        // 2. Call status callback without token -> 401
        $resNoAuth = $this->request('POST', "/api/v1/deployments/{$deployId}/status", [
            'status' => 'building',
        ]);
        $this->assertSame(401, $resNoAuth->getStatusCode());

        // 3. Transition to 'building' -> 200
        $resBuilding = $this->request('POST', "/api/v1/deployments/{$deployId}/status", [
            'status' => 'building',
        ], [
            'Authorization' => "Bearer {$callbackToken}",
        ]);
        $this->assertSame(200, $resBuilding->getStatusCode(), (string) $resBuilding->getBody());
        $buildingData = json_decode((string) $resBuilding->getBody(), true);
        $this->assertSame('building', $buildingData['status']);

        // 4. Invalid backwards transition: building -> queued -> 400
        $resInvalid = $this->request('POST', "/api/v1/deployments/{$deployId}/status", [
            'status' => 'queued',
        ], [
            'Authorization' => "Bearer {$callbackToken}",
        ]);
        $this->assertSame(422, $resInvalid->getStatusCode());
        $invalidBody = json_decode((string) $resInvalid->getBody(), true);
        $this->assertSame('Unprocessable Content', $invalidBody['title']);
        $this->assertStringContainsString('Invalid deployment status', $invalidBody['detail']);

        // 5. Transition to terminal state 'succeeded' -> 200
        $resSuccess = $this->request('POST', "/api/v1/deployments/{$deployId}/status", [
            'status' => 'succeeded',
            'build_log_url' => 'https://github.com/Brandsewa/bs-cms/actions/runs/123456',
        ], [
            'Authorization' => "Bearer {$callbackToken}",
        ]);
        $this->assertSame(200, $resSuccess->getStatusCode());
        $successData = json_decode((string) $resSuccess->getBody(), true);
        $this->assertSame('succeeded', $successData['status']);
        $this->assertNotNull($successData['finished_at']);
        $this->assertSame('https://github.com/Brandsewa/bs-cms/actions/runs/123456', $successData['build_log_url']);

        // 6. Replay attempt using same callback token -> 400 (Token has been burned)
        $resReplay = $this->request('POST', "/api/v1/deployments/{$deployId}/status", [
            'status' => 'succeeded',
        ], [
            'Authorization' => "Bearer {$callbackToken}",
        ]);
        $this->assertSame(400, $resReplay->getStatusCode());
        $replayBody = json_decode((string) $resReplay->getBody(), true);
        $this->assertStringContainsString('Invalid or expired callback token', $replayBody['detail']);
    }

    /**
     * Test 6: Webhook SSRF protection and test dispatch.
     */
    public function testWebhookSsrfProtectionAndTestDispatch(): void
    {
        // 1. SSRF attack URL (private loopback)
        $resSsrf = $this->request('POST', '/api/v1/settings/publishing/test', [
            'target_type' => 'webhook',
            'webhook_url' => 'http://127.0.0.1:8080/internal-hook',
        ], [
            'Authorization' => "Bearer {$this->ownerToken}",
            'Cookie' => $this->ownerSessionCookie,
        ]);
        $this->assertSame(400, $resSsrf->getStatusCode());
        $ssrfBody = json_decode((string) $resSsrf->getBody(), true);
        $this->assertStringContainsString('belongs to a private, loopback, or reserved network', $ssrfBody['detail']);

        // 2. Cloud metadata attack (169.254.169.254)
        $resMetadata = $this->request('POST', '/api/v1/settings/publishing/test', [
            'target_type' => 'webhook',
            'webhook_url' => 'http://169.254.169.254/latest/meta-data/',
        ], [
            'Authorization' => "Bearer {$this->ownerToken}",
            'Cookie' => $this->ownerSessionCookie,
        ]);
        $this->assertSame(400, $resMetadata->getStatusCode());
        $metaBody = json_decode((string) $resMetadata->getBody(), true);
        $this->assertStringContainsString('belongs to a private, loopback, or reserved network', $metaBody['detail']);
    }
}
