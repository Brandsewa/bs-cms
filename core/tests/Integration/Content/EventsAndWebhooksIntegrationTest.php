<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Content;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Authz\Authorizer;
use BsCms\Authz\PermissionCompiler;
use BsCms\Content\Cache\PublicResponseCache;
use BsCms\Content\EntryRepository;
use BsCms\Content\HierarchyValidator;
use BsCms\Content\Query\QueryParser;
use BsCms\Content\RelationManager;
use BsCms\Content\SlugService;
use BsCms\Content\VersionManager;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Events\EventBus;
use BsCms\Events\GenericEvent;
use BsCms\Http\Controllers\EntriesController;
use BsCms\Http\Controllers\PublicEntriesController;
use BsCms\Http\Controllers\WebhooksController;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use BsCms\Schema\SchemaApplier;
use BsCms\Schema\SchemaLock;
use BsCms\Schema\SchemaPlanner;
use BsCms\Security\Crypto;
use BsCms\Security\HttpClient;
use BsCms\Security\Ulid;
use BsCms\Webhooks\WebhookDispatcher;
use BsCms\Webhooks\WebhookSignature;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class EventsAndWebhooksIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName;
    private string $prefix;
    private string $tempDir;
    private PDO $adminPdo;

    private CollectionRegistry $collectionRegistry;
    private FieldTypeRegistry $fieldTypeRegistry;
    private EntryRepository $repository;
    private VersionManager $versionManager;
    private Authorizer $authorizer;
    private AuditLogger $auditLogger;
    private Crypto $crypto;
    private EventBus $eventBus;
    private PublicResponseCache $publicCache;
    private WebhookDispatcher $webhookDispatcher;

    private WebhooksController $webhooksController;
    private EntriesController $entriesController;
    private PublicEntriesController $publicEntriesController;

    private Actor $ownerActor;
    private string $ownerId;

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
            $this->markTestSkipped('No local MySQL/MariaDB available.');
        }

        $this->dbName = 'bs_wh_' . bin2hex(random_bytes(4));
        $this->prefix = 'bs_';
        $this->adminPdo->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->connection = new Connection([
            'host' => $host,
            'port' => $port,
            'database' => $this->dbName,
            'username' => $user,
            'password' => $pass,
            'prefix' => $this->prefix,
        ]);

        $this->tempDir = sys_get_temp_dir() . '/bs_events_wh_' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0755, true);

        // Run migrations
        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $migrator->migrate();

        $cacheFile = $this->tempDir . '/cache/schema.php';
        $this->collectionRegistry = new CollectionRegistry($this->connection, $cacheFile);
        $this->fieldTypeRegistry = new FieldTypeRegistry();

        $slugService = new SlugService($this->connection);
        $hierarchyValidator = new HierarchyValidator($this->connection);
        $this->versionManager = new VersionManager($this->connection);
        $relationManager = new RelationManager($this->connection);

        $this->repository = new EntryRepository(
            $this->connection,
            $this->collectionRegistry,
            $this->fieldTypeRegistry,
            $slugService,
            $hierarchyValidator,
            $this->versionManager,
            $relationManager
        );

        $this->auditLogger = new AuditLogger($this->connection);
        $compiler = new PermissionCompiler($this->connection);
        $this->authorizer = new Authorizer($this->connection, $compiler);

        $key = Crypto::generateKey();
        $this->crypto = Crypto::fromAppKey($key);

        $this->eventBus = new EventBus();
        $this->publicCache = new PublicResponseCache($this->tempDir . '/cache/public');
        $httpClient = new HttpClient();
        $this->webhookDispatcher = new WebhookDispatcher($this->connection, $this->crypto, $httpClient, $this->auditLogger);

        // Wire EventBus listeners
        $this->eventBus->subscribeAll(function (GenericEvent $e) {
            $this->webhookDispatcher->onEvent($e);
            $payload = $e->payload();
            $colKey = (string) ($payload['collection_key'] ?? $payload['collection'] ?? '');
            if ($colKey !== '') {
                $this->publicCache->invalidateCollection($colKey);
            }
        });

        $queryParser = new QueryParser();

        $this->entriesController = new EntriesController(
            $this->collectionRegistry,
            $this->repository,
            $queryParser,
            $this->versionManager,
            $this->authorizer,
            $this->auditLogger,
            $this->eventBus
        );

        $this->webhooksController = new WebhooksController(
            $this->connection,
            $this->crypto,
            $this->webhookDispatcher,
            $this->authorizer,
            $this->auditLogger
        );

        $this->publicEntriesController = new PublicEntriesController(
            $this->connection,
            $this->collectionRegistry,
            $this->repository,
            $queryParser,
            $this->publicCache
        );

        $this->seedUsersAndCollections();
    }

    private function seedUsersAndCollections(): void
    {
        $prefix = $this->connection->getPrefix();
        $now = date('Y-m-d H:i:s.v');

        $this->ownerId = Ulid::generate();
        $this->connection->execute(
            "INSERT INTO `{$prefix}users` (`id`, `name`, `email`, `password_hash`, `status`, `created_at`, `updated_at`)
             VALUES (?, 'Owner User', 'owner@example.com', 'hash', 'active', ?, ?)",
            [$this->ownerId, $now, $now]
        );

        $ownerRoleId = Ulid::generate();
        $this->connection->execute(
            "INSERT INTO `{$prefix}roles` (`id`, `name`, `handle`, `is_system`, `created_at`, `updated_at`)
             VALUES (?, 'Owner', 'owner', 1, ?, ?)",
            [$ownerRoleId, $now, $now]
        );

        $this->connection->execute(
            "INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, ?)",
            [$this->ownerId, $ownerRoleId]
        );

        $this->ownerActor = Actor::user($this->ownerId, 'Owner User', [$ownerRoleId]);

        // Create articles collection
        $planner = new SchemaPlanner($this->connection, $this->collectionRegistry, $this->fieldTypeRegistry);
        $lock = new SchemaLock($this->connection);
        $applier = new SchemaApplier($this->connection, $this->collectionRegistry, $lock);

        $articlesDef = [
            'handle' => 'articles',
            'kind' => 'collection',
            'label_singular' => 'Article',
            'label_plural' => 'Articles',
            'has_public_pages' => true,
            'features' => ['drafts' => true, 'versions' => true],
            'fields' => [
                'headline' => ['handle' => 'headline', 'type' => 'text', 'is_filterable' => true, 'is_sortable' => true],
            ],
        ];
        $applier->apply($planner->plan($articlesDef), $articlesDef);
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo, $this->dbName)) {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $queryParams
     * @param array<string, string> $headers
     */
    private function makeRequest(
        string $method,
        string $uri,
        ?Actor $actor = null,
        array $body = [],
        array $queryParams = [],
        array $headers = []
    ): ServerRequestInterface {
        $bodyStream = \Nyholm\Psr7\Stream::create(!empty($body) ? (string) json_encode($body) : '');
        $req = new ServerRequest(
            method: $method,
            uri: $uri,
            headers: array_merge(['Content-Type' => 'application/json'], $headers),
            body: $bodyStream
        );
        $req = $req->withQueryParams($queryParams)
            ->withParsedBody($body)
            ->withAttribute('request_id', Ulid::generate());

        if ($actor !== null) {
            $req = $req->withAttribute('actor', $actor);
        }

        return $req;
    }

    public function testWebhooksCrudAndTestPing(): void
    {
        // 1. Create Webhook
        $createReq = $this->makeRequest('POST', '/api/v1/webhooks', $this->ownerActor, [
            'name' => 'Test Webhook',
            'url' => 'https://example.com/webhook-receiver',
            'secret' => 'super_secret_signing_key_42',
            'events' => ['entry.created', 'entry.published'],
            'collections' => ['articles'],
        ]);

        $res = $this->webhooksController->create($createReq);
        $this->assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $wh = json_decode((string) $res->getBody(), true);
        $this->assertNotEmpty($wh['id']);
        $this->assertTrue($wh['has_secret']);
        $this->assertArrayNotHasKey('secret_enc', $wh);
        $this->assertArrayNotHasKey('secret', $wh);
        $whId = $wh['id'];

        // 2. List Webhooks
        $listReq = $this->makeRequest('GET', '/api/v1/webhooks', $this->ownerActor);
        $listRes = $this->webhooksController->list($listReq);
        $this->assertSame(200, $listRes->getStatusCode());
        $list = json_decode((string) $listRes->getBody(), true);
        $this->assertCount(1, $list);
        $this->assertSame('Test Webhook', $list[0]['name']);

        // 3. Update Webhook
        $updateReq = $this->makeRequest('PATCH', "/api/v1/webhooks/{$whId}", $this->ownerActor, [
            'name' => 'Updated Webhook Name',
        ]);
        $updateRes = $this->webhooksController->update($updateReq, ['id' => $whId]);
        $this->assertSame(200, $updateRes->getStatusCode());
        $updated = json_decode((string) $updateRes->getBody(), true);
        $this->assertSame('Updated Webhook Name', $updated['name']);

        // 4. Test Ping
        $testReq = $this->makeRequest('POST', "/api/v1/webhooks/{$whId}/test", $this->ownerActor);
        $testRes = $this->webhooksController->test($testReq, ['id' => $whId]);
        $this->assertSame(200, $testRes->getStatusCode());
        $delivery = json_decode((string) $testRes->getBody(), true);
        $this->assertSame('webhook.test', $delivery['event']);
        $this->assertNotEmpty($delivery['payload']);

        // Verify deliveries list
        $delListReq = $this->makeRequest('GET', "/api/v1/webhooks/{$whId}/deliveries", $this->ownerActor);
        $delListRes = $this->webhooksController->deliveries($delListReq, ['id' => $whId]);
        $this->assertSame(200, $delListRes->getStatusCode());
        $deliveries = json_decode((string) $delListRes->getBody(), true);
        $this->assertCount(1, $deliveries);

        // 5. Delete Webhook
        $delReq = $this->makeRequest('DELETE', "/api/v1/webhooks/{$whId}", $this->ownerActor);
        $delRes = $this->webhooksController->delete($delReq, ['id' => $whId]);
        $this->assertSame(200, $delRes->getStatusCode());

        // Get -> 404
        $getReq = $this->makeRequest('GET', "/api/v1/webhooks/{$whId}", $this->ownerActor);
        $getRes = $this->webhooksController->get($getReq, ['id' => $whId]);
        $this->assertSame(404, $getRes->getStatusCode());
    }

    public function testAutoDisableAfterConsecutiveFailures(): void
    {
        $prefix = $this->connection->getPrefix();
        $whId = Ulid::generate();
        $secretEnc = $this->crypto->encrypt('wh_secret');
        $now = date('Y-m-d H:i:s.v');

        $this->connection->execute(
            "INSERT INTO `{$prefix}webhooks` (`id`, `name`, `url`, `secret_enc`, `events`, `is_active`, `created_at`)
             VALUES (?, 'Failing Webhook', 'https://example.com/unreachable', ?, '[\"*\"]', 1, ?)",
            [$whId, $secretEnc, $now]
        );

        // Insert 49 failed deliveries
        for ($i = 0; $i < 49; $i++) {
            $this->connection->execute(
                "INSERT INTO `{$prefix}webhook_deliveries` (`webhook_id`, `event`, `payload`, `status`, `attempts`, `created_at`)
                 VALUES (?, 'test', '{}', 'failed', 6, ?)",
                [$whId, $now]
            );
        }

        // Webhook is still active
        $stmt = $this->connection->execute("SELECT `is_active` FROM `{$prefix}webhooks` WHERE `id` = ?", [$whId]);
        $this->assertSame(1, (int) $stmt->fetch()['is_active']);

        // Insert 50th delivery and deliver it (will fail to connect or deliver)
        $deliveryId = $this->webhookDispatcher->queueDelivery($whId, 'test', ['msg' => 'test 50']);

        // Check that webhook was auto-disabled
        $stmt = $this->connection->execute("SELECT `is_active` FROM `{$prefix}webhooks` WHERE `id` = ?", [$whId]);
        $this->assertSame(0, (int) $stmt->fetch()['is_active']);
    }

    public function testPublicEntriesAndTagBasedCacheInvalidation(): void
    {
        // 1. Create published entry
        $createReq = $this->makeRequest('POST', '/api/v1/collections/articles/entries', $this->ownerActor, [
            'title' => 'First Article',
            'headline' => 'Big Story',
            'status' => 'published',
        ]);
        $createRes = $this->entriesController->create($createReq, ['handle' => 'articles']);
        $this->assertSame(201, $createRes->getStatusCode());
        $created = json_decode((string) $createRes->getBody(), true);
        $entryId = $created['id'];
        $slug = $created['slug'];

        // 2. Public list entries (unauthenticated)
        $publicListReq = $this->makeRequest('GET', '/api/v1/public/collections/articles/entries');
        $publicListRes = $this->publicEntriesController->list($publicListReq, ['handle' => 'articles']);
        $this->assertSame(200, $publicListRes->getStatusCode());
        $this->assertTrue($publicListRes->hasHeader('ETag'));
        $this->assertTrue($publicListRes->hasHeader('Cache-Control'));
        $this->assertStringContainsString('max-age=60', $publicListRes->getHeaderLine('Cache-Control'));
        $etag = $publicListRes->getHeaderLine('ETag');

        // 3. Repeated GET with If-None-Match -> 304 Not Modified
        $req304 = $this->makeRequest('GET', '/api/v1/public/collections/articles/entries', null, [], [], [
            'If-None-Match' => $etag,
        ]);
        $res304 = $this->publicEntriesController->list($req304, ['handle' => 'articles']);
        $this->assertSame(304, $res304->getStatusCode());
        $this->assertSame('', (string) $res304->getBody());

        // 4. Public by-slug
        $slugReq = $this->makeRequest('GET', "/api/v1/public/collections/articles/entries/by-slug/{$slug}");
        $slugRes = $this->publicEntriesController->bySlug($slugReq, ['handle' => 'articles', 'slug' => $slug]);
        $this->assertSame(200, $slugRes->getStatusCode());
        $slugData = json_decode((string) $slugRes->getBody(), true);
        $this->assertSame('First Article', $slugData['data']['title']);
        $slugEtag = $slugRes->getHeaderLine('ETag');

        // Repeated by-slug with If-None-Match -> 304
        $slugReq304 = $this->makeRequest('GET', "/api/v1/public/collections/articles/entries/by-slug/{$slug}", null, [], [], [
            'If-None-Match' => $slugEtag,
        ]);
        $slugRes304 = $this->publicEntriesController->bySlug($slugReq304, ['handle' => 'articles', 'slug' => $slug]);
        $this->assertSame(304, $slugRes304->getStatusCode());

        // 5. Update entry -> event bus fires -> invalidates public cache
        $updateReq = $this->makeRequest('PUT', "/api/v1/collections/articles/entries/{$entryId}", $this->ownerActor, [
            'title' => 'Updated Article Headline',
            'headline' => 'Updated Big Story',
        ]);
        $updateRes = $this->entriesController->update($updateReq, ['handle' => 'articles', 'id' => $entryId]);
        $this->assertSame(200, $updateRes->getStatusCode());

        // 6. Public list returns fresh content with a new ETag
        $freshListRes = $this->publicEntriesController->list($publicListReq, ['handle' => 'articles']);
        $this->assertSame(200, $freshListRes->getStatusCode());
        $freshBody = json_decode((string) $freshListRes->getBody(), true);
        $this->assertSame('Updated Article Headline', $freshBody['data'][0]['title']);
        $this->assertNotSame($etag, $freshListRes->getHeaderLine('ETag'));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
