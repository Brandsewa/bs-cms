<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Content;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Authz\Authorizer;
use BsCms\Authz\PermissionCompiler;
use BsCms\Users\RoleRepository;
use BsCms\Content\EntryRepository;
use BsCms\Content\HierarchyValidator;
use BsCms\Content\Query\QueryParser;
use BsCms\Content\RelationManager;
use BsCms\Content\SlugService;
use BsCms\Content\VersionManager;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Http\Controllers\BulkEntriesController;
use BsCms\Http\Controllers\CollectionsController;
use BsCms\Http\Controllers\EntriesController;
use BsCms\Http\Controllers\JobsController;
use BsCms\Http\Controllers\SavedViewsController;
use BsCms\Http\Controllers\SingletonsController;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use BsCms\Operations\ConfirmTokenService;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use BsCms\Schema\SchemaApplier;
use BsCms\Schema\SchemaLock;
use BsCms\Schema\SchemaPlanner;
use BsCms\Security\Ulid;
use PDO;
use PHPUnit\Framework\TestCase;

final class ContentRestApiIntegrationTest extends TestCase
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
    private ConfirmTokenService $confirmTokens;
    private Authorizer $authorizer;
    private AuditLogger $auditLogger;

    private CollectionsController $collectionsController;
    private EntriesController $entriesController;
    private BulkEntriesController $bulkController;
    private SingletonsController $singletonsController;
    private SavedViewsController $savedViewsController;
    private JobsController $jobsController;

    private Actor $ownerActor;
    private Actor $authorActor;
    private string $ownerId;
    private string $authorId;

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

        $this->dbName = 'bs_api_' . bin2hex(random_bytes(4));
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

        $this->tempDir = sys_get_temp_dir() . '/bs_content_api_' . bin2hex(random_bytes(6));
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
        $this->confirmTokens = new ConfirmTokenService('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef', $this->connection);

        $compiler = new PermissionCompiler($this->connection);
        $this->authorizer = new Authorizer($this->connection, $compiler);

        $planner = new SchemaPlanner($this->connection, $this->collectionRegistry, $this->fieldTypeRegistry);
        $lock = new SchemaLock($this->connection);
        $applier = new SchemaApplier($this->connection, $this->collectionRegistry, $lock);

        $queryParser = new QueryParser();

        $this->collectionsController = new CollectionsController(
            $this->connection,
            $this->collectionRegistry,
            $planner,
            $applier,
            $this->authorizer,
            $this->confirmTokens,
            $this->auditLogger
        );

        $this->entriesController = new EntriesController(
            $this->collectionRegistry,
            $this->repository,
            $queryParser,
            $this->versionManager,
            $this->authorizer,
            $this->auditLogger,
            $this->confirmTokens
        );

        $this->bulkController = new BulkEntriesController(
            $this->connection,
            $this->collectionRegistry,
            $this->repository,
            $this->authorizer,
            $this->confirmTokens,
            $this->auditLogger
        );

        $this->singletonsController = new SingletonsController(
            $this->connection,
            $this->collectionRegistry,
            $this->repository,
            $this->authorizer,
            $this->auditLogger,
            $this->confirmTokens
        );

        $this->savedViewsController = new SavedViewsController(
            $this->connection,
            $this->collectionRegistry
        );

        $this->jobsController = new JobsController($this->connection);

        $roleRepo = new RoleRepository($this->connection);
        $ownerRole = $roleRepo->findByHandle('owner');
        $this->assertNotNull($ownerRole);
        $authorRole = $roleRepo->findByHandle('author');
        $this->assertNotNull($authorRole);

        // Actors
        $this->ownerId = Ulid::generate();
        $this->ownerActor = new Actor(
            type: 'user',
            id: $this->ownerId,
            label: 'Owner User',
            onBehalfOf: null,
            roleIds: [$ownerRole['id']],
            scopes: ['*']
        );

        $this->authorId = Ulid::generate();
        $this->authorActor = new Actor(
            type: 'user',
            id: $this->authorId,
            label: 'Author User',
            onBehalfOf: null,
            roleIds: [$authorRole['id']],
            scopes: ['*']
        );

        // Seed initial collections
        $articlesDef = [
            'handle' => 'articles',
            'kind' => 'collection',
            'label_singular' => 'Article',
            'label_plural' => 'Articles',
            'features' => ['drafts' => true, 'versions' => true, 'hierarchy' => true],
            'fields' => [
                'headline' => ['handle' => 'headline', 'type' => 'text', 'is_filterable' => true, 'is_sortable' => true],
                'summary' => ['handle' => 'summary', 'type' => 'text', 'is_filterable' => false],
            ],
        ];
        $applier->apply($planner->plan($articlesDef), $articlesDef);

        $siteSettingsDef = [
            'handle' => 'site_config',
            'kind' => 'single',
            'label_singular' => 'Site Config',
            'label_plural' => 'Site Config',
            'fields' => [
                'tagline' => ['handle' => 'tagline', 'type' => 'text'],
            ],
        ];
        $applier->apply($planner->plan($siteSettingsDef), $siteSettingsDef);
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
        Actor $actor,
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
        return $req
            ->withQueryParams($queryParams)
            ->withParsedBody($body)
            ->withAttribute('actor', $actor)
            ->withAttribute('request_id', Ulid::generate());
    }

    public function testCollectionsCrudAndDestructiveTwoStepConfirmation(): void
    {
        // 1. Create collection
        $createReq = $this->makeRequest('POST', '/api/v1/collections', $this->ownerActor, [
            'handle' => 'categories',
            'kind' => 'collection',
            'label_singular' => 'Category',
            'label_plural' => 'Categories',
            'fields' => [
                'name' => ['handle' => 'name', 'type' => 'text', 'is_filterable' => true],
            ],
        ]);
        $res = $this->collectionsController->create($createReq);
        $this->assertSame(201, $res->getStatusCode());

        // 2. Destructive modification requires confirmation
        $updateReq = $this->makeRequest('PUT', '/api/v1/collections/categories', $this->ownerActor, [
            'fields' => [], // Removing name field => data loss hazard
        ]);
        $res409 = $this->collectionsController->update($updateReq, ['handle' => 'categories']);
        $this->assertSame(409, $res409->getStatusCode());
        $body409 = json_decode((string) $res409->getBody(), true);
        $this->assertNotEmpty($body409['confirm_token']);
        $token = $body409['confirm_token'];

        // 3. Confirm destructive change
        $confirmReq = $this->makeRequest('PUT', '/api/v1/collections/categories', $this->ownerActor, [
            'fields' => [],
            'confirm_token' => $token,
        ]);
        $res200 = $this->collectionsController->update($confirmReq, ['handle' => 'categories']);
        $this->assertSame(200, $res200->getStatusCode());

        // 4. Delete collection requires confirmation
        $delReq = $this->makeRequest('DELETE', '/api/v1/collections/categories', $this->ownerActor);
        $del409 = $this->collectionsController->delete($delReq, ['handle' => 'categories']);
        $this->assertSame(409, $del409->getStatusCode());
        $delToken = json_decode((string) $del409->getBody(), true)['confirm_token'];

        $delConfirmReq = $this->makeRequest('DELETE', '/api/v1/collections/categories', $this->ownerActor, [
            'confirm_token' => $delToken,
        ]);
        $del200 = $this->collectionsController->delete($delConfirmReq, ['handle' => 'categories']);
        $this->assertSame(200, $del200->getStatusCode(), (string) $del200->getBody());
        $this->assertFalse($this->collectionRegistry->has('categories'));
    }

    public function testEntriesCrudAndUnindexedFilterRejection(): void
    {
        // 1. Create entry
        $createReq = $this->makeRequest('POST', '/api/v1/collections/articles/entries', $this->ownerActor, [
            'title' => 'Article One',
            'headline' => 'Breaking News',
            'summary' => 'Short summary',
            'status' => 'published',
        ]);
        $res = $this->entriesController->create($createReq, ['handle' => 'articles']);
        $this->assertSame(201, $res->getStatusCode());
        $entry = json_decode((string) $res->getBody(), true);
        $id = $entry['id'];

        // 2. Query with indexed filter -> 200
        $listReq = $this->makeRequest('GET', '/api/v1/collections/articles/entries', $this->ownerActor, [], [
            'filter' => ['headline' => ['eq' => 'Breaking News']],
        ]);
        $listRes = $this->entriesController->list($listReq, ['handle' => 'articles']);
        $this->assertSame(200, $listRes->getStatusCode());
        $listData = json_decode((string) $listRes->getBody(), true);
        $this->assertCount(1, $listData['data']);

        // 3. Query with unindexed filter -> 422 rejected
        $unindexedReq = $this->makeRequest('GET', '/api/v1/collections/articles/entries', $this->ownerActor, [], [
            'filter' => ['summary' => ['eq' => 'Short summary']],
        ]);
        $unindexedRes = $this->entriesController->list($unindexedReq, ['handle' => 'articles']);
        $this->assertSame(422, $unindexedRes->getStatusCode());
        $unindexedBody = json_decode((string) $unindexedRes->getBody(), true);
        $this->assertStringContainsString('is not indexed', $unindexedBody['detail']);

        // 4. Update entry with version check
        $updateReq = $this->makeRequest('PATCH', "/api/v1/collections/articles/entries/{$id}", $this->ownerActor, [
            'headline' => 'Updated News',
            'version' => 1,
        ]);
        $updateRes = $this->entriesController->update($updateReq, ['handle' => 'articles', 'id' => $id]);
        $this->assertSame(200, $updateRes->getStatusCode());
        $updated = json_decode((string) $updateRes->getBody(), true);
        $this->assertSame(2, $updated['version']);

        // 5. Versions list and compare
        $versionsReq = $this->makeRequest('GET', "/api/v1/collections/articles/entries/{$id}/versions", $this->ownerActor);
        $versionsRes = $this->entriesController->listVersions($versionsReq, ['handle' => 'articles', 'id' => $id]);
        $this->assertSame(200, $versionsRes->getStatusCode());
        $versions = json_decode((string) $versionsRes->getBody(), true);
        $this->assertCount(2, $versions);

        // 6. Duplicate entry
        $dupReq = $this->makeRequest('POST', "/api/v1/collections/articles/entries/{$id}/duplicate", $this->ownerActor);
        $dupRes = $this->entriesController->duplicate($dupReq, ['handle' => 'articles', 'id' => $id]);
        $this->assertSame(201, $dupRes->getStatusCode());
        $duplicated = json_decode((string) $dupRes->getBody(), true);
        $this->assertNotSame($id, $duplicated['id']);
        $this->assertStringContainsString('copy', $duplicated['slug']);

        // 7. Soft delete and restore
        $delReq = $this->makeRequest('DELETE', "/api/v1/collections/articles/entries/{$id}", $this->ownerActor);
        $delRes = $this->entriesController->delete($delReq, ['handle' => 'articles', 'id' => $id]);
        $this->assertSame(200, $delRes->getStatusCode());

        // 7b. Test trashed filter modes: exclude (default), only, with
        // Default excludes trashed
        $listDefaultReq = $this->makeRequest('GET', '/api/v1/collections/articles/entries', $this->ownerActor);
        $listDefaultRes = $this->entriesController->list($listDefaultReq, ['handle' => 'articles']);
        $this->assertSame(200, $listDefaultRes->getStatusCode());
        $listDefaultData = json_decode((string) $listDefaultRes->getBody(), true);
        $this->assertCount(1, $listDefaultData['data']); // only the duplicated active entry
        $this->assertNotSame($id, $listDefaultData['data'][0]['id']);

        // trashed=only returns ONLY soft-deleted entries
        $listOnlyReq = $this->makeRequest('GET', '/api/v1/collections/articles/entries', $this->ownerActor, [], ['trashed' => 'only']);
        $listOnlyRes = $this->entriesController->list($listOnlyReq, ['handle' => 'articles']);
        $this->assertSame(200, $listOnlyRes->getStatusCode());
        $listOnlyData = json_decode((string) $listOnlyRes->getBody(), true);
        $this->assertCount(1, $listOnlyData['data']);
        $this->assertSame($id, $listOnlyData['data'][0]['id']);

        // trashed=with returns BOTH active and soft-deleted entries
        $listWithReq = $this->makeRequest('GET', '/api/v1/collections/articles/entries', $this->ownerActor, [], ['trashed' => 'with']);
        $listWithRes = $this->entriesController->list($listWithReq, ['handle' => 'articles']);
        $this->assertSame(200, $listWithRes->getStatusCode());
        $listWithData = json_decode((string) $listWithRes->getBody(), true);
        $this->assertCount(2, $listWithData['data']);

        // trashed=true is backward-compatible alias of with
        $listTrueReq = $this->makeRequest('GET', '/api/v1/collections/articles/entries', $this->ownerActor, [], ['trashed' => 'true']);
        $listTrueRes = $this->entriesController->list($listTrueReq, ['handle' => 'articles']);
        $this->assertSame(200, $listTrueRes->getStatusCode());
        $listTrueData = json_decode((string) $listTrueRes->getBody(), true);
        $this->assertCount(2, $listTrueData['data']);

        // 7c. Test GET /api/v1/collections/{handle}/counts
        $countsReq = $this->makeRequest('GET', '/api/v1/collections/articles/counts', $this->ownerActor);
        $countsRes = $this->entriesController->counts($countsReq, ['handle' => 'articles']);
        $this->assertSame(200, $countsRes->getStatusCode());
        $countsData = json_decode((string) $countsRes->getBody(), true);
        $this->assertSame(1, $countsData['all']); // 1 non-deleted (the duplicate)
        $this->assertSame(1, $countsData['trash']); // 1 deleted ($id)
        $this->assertSame(1, $countsData['published']);
        $this->assertSame(0, $countsData['draft']);

        $restoreReq = $this->makeRequest('POST', "/api/v1/collections/articles/entries/{$id}/restore", $this->ownerActor);
        $restoreRes = $this->entriesController->restore($restoreReq, ['handle' => 'articles', 'id' => $id]);
        $this->assertSame(200, $restoreRes->getStatusCode());

        // After restore: trash is 0, all is 2
        $countsAfterReq = $this->makeRequest('GET', '/api/v1/collections/articles/counts', $this->ownerActor);
        $countsAfterRes = $this->entriesController->counts($countsAfterReq, ['handle' => 'articles']);
        $countsAfterData = json_decode((string) $countsAfterRes->getBody(), true);
        $this->assertSame(2, $countsAfterData['all']);
        $this->assertSame(0, $countsAfterData['trash']);

        // 8. Status transitions: unpublish, publish, archive
        $unpubReq = $this->makeRequest('POST', "/api/v1/collections/articles/entries/{$id}/unpublish", $this->ownerActor, ['version' => 3]);
        $unpubRes = $this->entriesController->unpublish($unpubReq, ['handle' => 'articles', 'id' => $id]);
        $this->assertSame(200, $unpubRes->getStatusCode());
        $unpub = json_decode((string) $unpubRes->getBody(), true);
        $this->assertSame('draft', $unpub['status']);

        $pubReq = $this->makeRequest('POST', "/api/v1/collections/articles/entries/{$id}/publish", $this->ownerActor, ['version' => 4]);
        $pubRes = $this->entriesController->publish($pubReq, ['handle' => 'articles', 'id' => $id]);
        $this->assertSame(200, $pubRes->getStatusCode());
        $pub = json_decode((string) $pubRes->getBody(), true);
        $this->assertSame('published', $pub['status']);

        $archReq = $this->makeRequest('POST', "/api/v1/collections/articles/entries/{$id}/archive", $this->ownerActor, ['version' => 5]);
        $archRes = $this->entriesController->archive($archReq, ['handle' => 'articles', 'id' => $id]);
        $this->assertSame(200, $archRes->getStatusCode());
        $arch = json_decode((string) $archRes->getBody(), true);
        $this->assertSame('archived', $arch['status']);

        // 9. Compare versions
        $compReq = $this->makeRequest('GET', "/api/v1/collections/articles/entries/{$id}/compare", $this->ownerActor, [], [
            'from' => 1,
            'to' => 2,
        ]);
        $compRes = $this->entriesController->compare($compReq, ['handle' => 'articles', 'id' => $id]);
        $this->assertSame(200, $compRes->getStatusCode());
        $comp = json_decode((string) $compRes->getBody(), true);
        $this->assertArrayHasKey('diff', $comp);
    }

    public function testSchemaPlanAndApplyEndpoints(): void
    {
        // 1. Plan new collection
        $planReq = $this->makeRequest('POST', '/api/v1/schema/plan', $this->ownerActor, [
            'handle' => 'products',
            'kind' => 'collection',
            'label_singular' => 'Product',
            'label_plural' => 'Products',
            'fields' => [
                'sku' => ['handle' => 'sku', 'type' => 'text', 'is_filterable' => true],
            ],
        ]);
        $planRes = $this->collectionsController->plan($planReq);
        $this->assertSame(200, $planRes->getStatusCode());
        $planData = json_decode((string) $planRes->getBody(), true);
        $this->assertSame('proposed', $planData['status']);
        $changeId = $planData['change_id'];

        // 2. Changes list
        $changesReq = $this->makeRequest('GET', '/api/v1/schema/changes', $this->ownerActor);
        $changesRes = $this->collectionsController->changes($changesReq);
        $this->assertSame(200, $changesRes->getStatusCode());
        $changes = json_decode((string) $changesRes->getBody(), true);
        $this->assertNotEmpty($changes);

        // 3. Apply change
        $applyReq = $this->makeRequest('POST', "/api/v1/schema/apply/{$changeId}", $this->ownerActor);
        $applyRes = $this->collectionsController->applyChange($applyReq, ['change_id' => $changeId]);
        $this->assertSame(200, $applyRes->getStatusCode());
        $applyData = json_decode((string) $applyRes->getBody(), true);
        $this->assertTrue($applyData['success']);
    }

    public function testBulkOperationsAndTwoStepConfirmationForLargeSets(): void
    {
        // Create 12 entries
        $ids = [];
        for ($i = 1; $i <= 12; $i++) {
            $e = $this->repository->create('articles', [
                'title' => "Article {$i}",
                'status' => 'draft',
            ], 'user', 'Owner', $this->ownerId);
            $ids[] = $e['id'];
        }

        // Bulk publish without confirmation token -> 409
        $bulkReq = $this->makeRequest('POST', '/api/v1/collections/articles/entries/bulk', $this->ownerActor, [
            'operation' => 'publish',
            'ids' => $ids,
        ]);
        $res409 = $this->bulkController->bulk($bulkReq, ['handle' => 'articles']);
        $this->assertSame(409, $res409->getStatusCode());
        $body409 = json_decode((string) $res409->getBody(), true);
        $token = $body409['confirm_token'];

        // Submit with token -> 200
        $confirmReq = $this->makeRequest('POST', '/api/v1/collections/articles/entries/bulk', $this->ownerActor, [
            'operation' => 'publish',
            'ids' => $ids,
            'confirm_token' => $token,
        ]);
        $res200 = $this->bulkController->bulk($confirmReq, ['handle' => 'articles']);
        $this->assertSame(200, $res200->getStatusCode());
        $body200 = json_decode((string) $res200->getBody(), true);
        $this->assertSame(12, $body200['processed']);
        $this->assertSame(0, $body200['failed']);
        $this->assertSame(12, $body200['total']);
        $this->assertCount(12, $body200['items']);

        // Test partial failure shape (valid ID + non-existent ID)
        $partialReq = $this->makeRequest('POST', '/api/v1/collections/articles/entries/bulk', $this->ownerActor, [
            'operation' => 'publish',
            'ids' => [$ids[0], '01NONEXISTENT00000000000000'],
        ]);
        $partialRes = $this->bulkController->bulk($partialReq, ['handle' => 'articles']);
        $this->assertSame(200, $partialRes->getStatusCode());
        $partialBody = json_decode((string) $partialRes->getBody(), true);
        $this->assertTrue($partialBody['success']);
        $this->assertSame('publish', $partialBody['operation']);
        $this->assertSame(1, $partialBody['processed']);
        $this->assertSame(1, $partialBody['failed']);
        $this->assertSame(2, $partialBody['total']);
        $this->assertCount(2, $partialBody['items']);
        $this->assertSame('success', $partialBody['items'][0]['status']);
        $this->assertSame('failed', $partialBody['items'][1]['status']);
        $this->assertSame('Entry not found', $partialBody['items'][1]['error']);
    }

    public function testSingletonsControllerGetAndUpdate(): void
    {
        // 1. Initial get (empty singleton skeleton)
        $getReq = $this->makeRequest('GET', '/api/v1/singletons/site_config', $this->ownerActor);
        $getRes = $this->singletonsController->get($getReq, ['handle' => 'site_config']);
        $this->assertSame(200, $getRes->getStatusCode());

        // 2. Update singleton
        $updateReq = $this->makeRequest('PUT', '/api/v1/singletons/site_config', $this->ownerActor, [
            'title' => 'My Site',
            'tagline' => 'The best CMS in the world',
        ]);
        $updateRes = $this->singletonsController->update($updateReq, ['handle' => 'site_config']);
        $this->assertSame(200, $updateRes->getStatusCode());
        $updated = json_decode((string) $updateRes->getBody(), true);
        $this->assertSame('The best CMS in the world', $updated['tagline']);
    }

    public function testSavedViewsUserIsolation(): void
    {
        // 1. Create saved view for owner
        $createReq = $this->makeRequest('POST', '/api/v1/saved-views', $this->ownerActor, [
            'name' => 'Owner View',
            'collection' => 'articles',
            'filters' => ['status' => 'published'],
        ]);
        $createRes = $this->savedViewsController->create($createReq);
        $this->assertSame(201, $createRes->getStatusCode());
        $view = json_decode((string) $createRes->getBody(), true);
        $viewId = $view['id'];

        // 2. Author cannot access owner's saved view -> 404
        $authGetReq = $this->makeRequest('GET', "/api/v1/saved-views/{$viewId}", $this->authorActor);
        $authGetRes = $this->savedViewsController->get($authGetReq, ['id' => $viewId]);
        $this->assertSame(404, $authGetRes->getStatusCode());

        // 3. Owner can access it -> 200
        $ownerGetReq = $this->makeRequest('GET', "/api/v1/saved-views/{$viewId}", $this->ownerActor);
        $ownerGetRes = $this->savedViewsController->get($ownerGetReq, ['id' => $viewId]);
        $this->assertSame(200, $ownerGetRes->getStatusCode());
    }

    public function testJobsEndpoint(): void
    {
        $prefix = $this->connection->getPrefix();
        $this->connection->execute(
            "INSERT INTO `{$prefix}jobs` (`queue`, `type`, `payload`, `status`, `attempts`, `max_attempts`, `available_at`, `created_at`)
             VALUES ('default', 'test_job', '{}', 'completed', 1, 3, NOW(), NOW())"
        );
        $jobId = (string) $this->connection->getPdo()->lastInsertId();

        $req = $this->makeRequest('GET', "/api/v1/jobs/{$jobId}", $this->ownerActor);
        $res = $this->jobsController->get($req, ['id' => $jobId]);
        $this->assertSame(200, $res->getStatusCode());
        $job = json_decode((string) $res->getBody(), true);
        $this->assertSame('completed', $job['status']);
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
