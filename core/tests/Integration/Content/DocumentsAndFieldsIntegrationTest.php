<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Content;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Authz\Authorizer;
use BsCms\Authz\PermissionCompiler;
use BsCms\Content\EntryRepository;
use BsCms\Content\HierarchyValidator;
use BsCms\Content\Query\QueryParser;
use BsCms\Content\RelationManager;
use BsCms\Content\SlugService;
use BsCms\Content\VersionManager;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Http\Controllers\EntriesController;
use BsCms\Operations\ConfirmTokenService;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use BsCms\Schema\SchemaApplier;
use BsCms\Schema\SchemaLock;
use BsCms\Schema\SchemaPlanner;
use BsCms\Security\Ulid;
use BsCms\Users\RoleRepository;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;

final class DocumentsAndFieldsIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName;
    private string $prefix;
    private string $tempDir;
    private PDO $adminPdo;

    private CollectionRegistry $collectionRegistry;
    private FieldTypeRegistry $fieldTypeRegistry;
    private EntryRepository $repository;
    private EntriesController $entriesController;
    private Actor $ownerActor;

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

        $this->dbName = 'bs_doc_' . bin2hex(random_bytes(4));
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

        $this->tempDir = sys_get_temp_dir() . '/bs_doc_api_' . bin2hex(random_bytes(6));
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
        $versionManager = new VersionManager($this->connection);
        $relationManager = new RelationManager($this->connection);

        $this->repository = new EntryRepository(
            $this->connection,
            $this->collectionRegistry,
            $this->fieldTypeRegistry,
            $slugService,
            $hierarchyValidator,
            $versionManager,
            $relationManager
        );

        $auditLogger = new AuditLogger($this->connection);
        $confirmTokens = new ConfirmTokenService('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef', $this->connection);

        $compiler = new PermissionCompiler($this->connection);
        $authorizer = new Authorizer($this->connection, $compiler);

        $planner = new SchemaPlanner($this->connection, $this->collectionRegistry, $this->fieldTypeRegistry);
        $lock = new SchemaLock($this->connection);
        $applier = new SchemaApplier($this->connection, $this->collectionRegistry, $lock);

        $queryParser = new QueryParser();

        $this->entriesController = new EntriesController(
            $this->collectionRegistry,
            $this->repository,
            $queryParser,
            $versionManager,
            $authorizer,
            $auditLogger,
            $confirmTokens
        );

        $roleRepo = new RoleRepository($this->connection);
        $ownerRole = $roleRepo->findByHandle('owner');
        $this->assertNotNull($ownerRole);

        $this->ownerActor = new Actor(
            type: 'user',
            id: Ulid::generate(),
            label: 'Owner User',
            onBehalfOf: null,
            roleIds: [$ownerRole['id']],
            scopes: ['*']
        );

        // Apply a collection with richtext, media, and taxonomy fields
        $postsDef = [
            'handle' => 'posts',
            'kind' => 'collection',
            'label_singular' => 'Post',
            'label_plural' => 'Posts',
            'fields' => [
                'body' => [
                    'handle' => 'body',
                    'type' => 'richtext',
                    'config' => ['profile' => 'article'],
                    'required' => true,
                ],
                'featured_image' => [
                    'handle' => 'featured_image',
                    'type' => 'media',
                    'config' => ['multiple' => false],
                ],
                'gallery' => [
                    'handle' => 'gallery',
                    'type' => 'media',
                    'config' => ['multiple' => true],
                ],
                'tags' => [
                    'handle' => 'tags',
                    'type' => 'taxonomy',
                    'config' => ['taxonomy' => 'tags', 'multiple' => true],
                ],
            ],
        ];

        $applier->apply($planner->plan($postsDef), $postsDef);
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo, $this->dbName)) {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if (is_array($files)) {
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
            @rmdir($this->tempDir);
        }
    }

    public function testRichtextAndMediaEntryLifecycle(): void
    {
        $validMediaUlid1 = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $validMediaUlid2 = '01ARZ3NDEKTSV4RRFFQ69G5FA2';

        $mediaTable = $this->connection->prefixTable('media');
        $now = date('Y-m-d H:i:s');
        foreach ([$validMediaUlid1, $validMediaUlid2] as $mId) {
            $this->connection->execute(
                "INSERT INTO `{$mediaTable}` (
                    `id`, `filename`, `original_name`, `mime`, `extension`, `size_bytes`, `content_hash`,
                    `storage_driver`, `storage_path`, `usage_count`, `uploaded_by`, `created_at`, `updated_at`
                ) VALUES (?, 'test.png', 'test.png', 'image/png', 'png', 100, ?, 'local', 'media/test.png', 0, ?, ?, ?)",
                [$mId, hash('sha256', $mId), $this->ownerActor->id, $now, $now]
            );
        }

        // 1. Create entry with body_markdown
        $req1 = (new ServerRequest('POST', '/api/v1/collections/posts/entries'))
            ->withAttribute('actor', $this->ownerActor)
            ->withParsedBody([
                'title' => 'First Rich Post',
                'body_markdown' => "## Subheading\n\nThis is a test post with **bold** text and [internal link](/docs/welcome).\n\n:::callout info\nUseful tip here\n:::",
                'featured_image' => $validMediaUlid1,
                'gallery' => [$validMediaUlid1, $validMediaUlid2],
            ]);

        $res1 = $this->entriesController->create($req1, ['handle' => 'posts']);
        $this->assertSame(201, $res1->getStatusCode());
        $body1 = json_decode((string) $res1->getBody(), true);
        $this->assertIsArray($body1);
        $this->assertSame('First Rich Post', $body1['title']);
        $this->assertIsArray($body1['body']);
        $this->assertSame('doc', $body1['body']['type']);
        $this->assertArrayHasKey('body_markdown', $body1);
        $this->assertStringContainsString('## Subheading', $body1['body_markdown']);
        $this->assertSame($validMediaUlid1, $body1['featured_image']);
        $this->assertSame([$validMediaUlid1, $validMediaUlid2], $body1['gallery']);

        // 2. Search text includes extracted document content
        $entryId = $body1['id'];
        $rawEntry = $this->repository->find('posts', $entryId);
        $this->assertNotNull($rawEntry);
        $this->assertStringContainsString('Subheading', (string) $rawEntry['search_text']);
        $this->assertStringContainsString('bold', (string) $rawEntry['search_text']);

        // 3. Test validation error for invalid media ULID
        $reqInvalidMedia = (new ServerRequest('POST', '/api/v1/collections/posts/entries'))
            ->withAttribute('actor', $this->ownerActor)
            ->withParsedBody([
                'title' => 'Invalid Media Post',
                'body_markdown' => 'Some text',
                'featured_image' => 'not-a-valid-ulid',
            ]);

        $resInvalidMedia = $this->entriesController->create($reqInvalidMedia, ['handle' => 'posts']);
        $this->assertSame(422, $resInvalidMedia->getStatusCode());
        $errBody = json_decode((string) $resInvalidMedia->getBody(), true);
        $this->assertArrayHasKey('errors', $errBody);
        $this->assertArrayHasKey('featured_image', $errBody['errors']);

        // 4. Test validation error for unsafe URL in document
        $reqUnsafeDoc = (new ServerRequest('POST', '/api/v1/collections/posts/entries'))
            ->withAttribute('actor', $this->ownerActor)
            ->withParsedBody([
                'title' => 'Unsafe URL Post',
                'body' => [
                    'type' => 'doc',
                    'content' => [
                        [
                            'type' => 'paragraph',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => 'Click me',
                                    'marks' => [
                                        ['type' => 'link', 'attrs' => ['href' => 'javascript:alert(document.cookie)']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $resUnsafeDoc = $this->entriesController->create($reqUnsafeDoc, ['handle' => 'posts']);
        $this->assertSame(422, $resUnsafeDoc->getStatusCode());
        $errDoc = json_decode((string) $resUnsafeDoc->getBody(), true);
        $this->assertArrayHasKey('errors', $errDoc);
        $this->assertArrayHasKey('body', $errDoc['errors']);
    }
}
