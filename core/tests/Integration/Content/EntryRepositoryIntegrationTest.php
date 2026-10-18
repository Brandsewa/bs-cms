<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Content;

use BsCms\Content\EntryRepository;
use BsCms\Content\HierarchyValidator;
use BsCms\Content\Jobs\PublishScheduledJob;
use BsCms\Content\OptimisticConcurrencyException;
use BsCms\Content\RelationManager;
use BsCms\Content\SlugService;
use BsCms\Content\VersionManager;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use BsCms\Schema\SchemaApplier;
use BsCms\Schema\SchemaLock;
use BsCms\Schema\SchemaPlanner;
use BsCms\Support\Clock;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class EntryRepositoryIntegrationTest extends TestCase
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
    private RelationManager $relationManager;
    private HierarchyValidator $hierarchyValidator;
    private PublishScheduledJob $scheduledJob;

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

        $this->dbName = 'bs_ent_' . bin2hex(random_bytes(4));
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

        $this->tempDir = sys_get_temp_dir() . '/bs_entries_' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0755, true);

        // Run migrations
        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $migrator->migrate();

        $cacheFile = $this->tempDir . '/cache/schema.php';
        $this->collectionRegistry = new CollectionRegistry($this->connection, $cacheFile);
        $this->fieldTypeRegistry = new FieldTypeRegistry();

        $slugService = new SlugService($this->connection);
        $this->hierarchyValidator = new HierarchyValidator($this->connection);
        $this->versionManager = new VersionManager($this->connection);
        $this->relationManager = new RelationManager($this->connection);

        $this->repository = new EntryRepository(
            $this->connection,
            $this->collectionRegistry,
            $this->fieldTypeRegistry,
            $slugService,
            $this->hierarchyValidator,
            $this->versionManager,
            $this->relationManager
        );

        $this->scheduledJob = new PublishScheduledJob(
            $this->connection,
            $this->collectionRegistry
        );

        // Create test collections via schema planner & applier
        $planner = new SchemaPlanner($this->connection, $this->collectionRegistry, $this->fieldTypeRegistry);
        $lock = new SchemaLock($this->connection);
        $applier = new SchemaApplier($this->connection, $this->collectionRegistry, $lock);

        $postsDef = [
            'handle' => 'posts',
            'kind' => 'collection',
            'label_singular' => 'Post',
            'label_plural' => 'Posts',
            'features' => ['drafts' => true, 'versions' => true, 'hierarchy' => true],
            'fields' => [
                'headline' => ['handle' => 'headline', 'type' => 'text', 'is_filterable' => true],
                'views' => ['handle' => 'views', 'type' => 'number', 'config' => ['precision' => 0]],
            ],
        ];
        $applier->apply($planner->plan($postsDef), $postsDef);

        $singletonDef = [
            'handle' => 'site_settings',
            'kind' => 'single',
            'label_singular' => 'Site Settings',
            'label_plural' => 'Site Settings',
            'fields' => [
                'site_name' => ['handle' => 'site_name', 'type' => 'text'],
            ],
        ];
        $applier->apply($planner->plan($singletonDef), $singletonDef);
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

    private function removeDirectory(string $path): void
    {
        $files = glob($path . '/*') ?: [];
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDirectory($file) : @unlink($file);
        }
        @rmdir($path);
    }

    public function testEntryCrudAndOptimisticConcurrency(): void
    {
        // 1. Create entry
        $entry = $this->repository->create('posts', [
            'title' => 'My First Post',
            'headline' => 'Breaking News',
            'views' => 100,
            'status' => 'draft',
        ]);

        $this->assertNotEmpty($entry['id']);
        $this->assertSame('my-first-post', $entry['slug']);
        $this->assertSame('draft', $entry['status']);
        $this->assertSame(1, $entry['version']);
        $this->assertSame(100, $entry['data']['views']);

        // 2. Successful update with expected version
        $updated = $this->repository->update('posts', $entry['id'], [
            'headline' => 'Updated Headline',
            'views' => 150,
        ], 1);

        $this->assertSame(2, $updated['version']);
        $this->assertSame(150, $updated['data']['views']);

        // 3. Optimistic concurrency conflict
        $this->expectException(OptimisticConcurrencyException::class);
        $this->expectExceptionCode(409);
        $this->repository->update('posts', $entry['id'], ['views' => 200], 1); // Stale version 1
    }

    public function testSoftDeleteAndRestoreWithSlugSuffix(): void
    {
        $entry = $this->repository->create('posts', [
            'title' => 'Temporary Post',
            'status' => 'draft',
        ]);
        $origSlug = $entry['slug'];
        $id = $entry['id'];

        // Soft delete
        $this->repository->delete('posts', $id, false);
        $this->assertNull($this->repository->find('posts', $id)); // Hidden from regular find

        $trashed = $this->repository->find('posts', $id, true);
        $this->assertNotNull($trashed);
        $this->assertStringStartsWith($origSlug . '-deleted-', $trashed['slug']);

        // Create new entry using original slug
        $reused = $this->repository->create('posts', [
            'title' => 'Temporary Post',
            'status' => 'draft',
        ]);
        $this->assertSame($origSlug, $reused['slug']);

        // Restore original entry
        $restored = $this->repository->restore('posts', $id);
        $this->assertNotSame($trashed['slug'], $restored['slug']);
    }

    public function testHierarchyCycleRejection(): void
    {
        $entry1 = $this->repository->create('posts', ['title' => 'Page 1']);
        $entry2 = $this->repository->create('posts', ['title' => 'Page 2', 'parent_id' => $entry1['id']]);

        // Attempting to set parent of Page 1 to Page 2 should trigger cycle detection
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cycle detected in hierarchy tree');
        $this->repository->update('posts', $entry1['id'], ['parent_id' => $entry2['id']]);
    }

    public function testHierarchySelfParentRejection(): void
    {
        $entry = $this->repository->create('posts', ['title' => 'Standalone Page']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An entry cannot be its own parent');
        $this->repository->update('posts', $entry['id'], ['parent_id' => $entry['id']]);
    }

    public function testSingletonEnforcement(): void
    {
        // First creation succeeds
        $single = $this->repository->create('site_settings', [
            'title' => 'Site Config',
            'site_name' => 'My CMS Site',
        ]);
        $this->assertSame('Site Config', $single['title']);

        // Second creation must be blocked
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already has a record');
        $this->repository->create('site_settings', [
            'title' => 'Duplicate Config',
            'site_name' => 'Should Fail',
        ]);
    }

    public function testScheduledPublishingJob(): void
    {
        // Future scheduled entry
        $futureTime = date('Y-m-d H:i:s.v', time() + 3600);
        $this->repository->create('posts', [
            'title' => 'Future Post',
            'status' => 'scheduled',
            'scheduled_at' => $futureTime,
        ]);

        // Due scheduled entry (simulated in DB)
        $pastTime = date('Y-m-d H:i:s.v', time() - 60);
        $entryDue = $this->repository->create('posts', [
            'title' => 'Due Post',
            'status' => 'scheduled',
            'scheduled_at' => $futureTime,
        ]);
        // Update scheduled_at to past in DB directly
        $this->connection->execute(
            "UPDATE `{$this->prefix}c_posts` SET `scheduled_at` = ? WHERE `id` = ?",
            [$pastTime, $entryDue['id']]
        );

        $published = $this->scheduledJob->handle();
        $this->assertSame(1, $published);

        $fresh = $this->repository->find('posts', $entryDue['id']);
        $this->assertNotNull($fresh);
        $this->assertSame('published', $fresh['status']);
        $this->assertNull($fresh['scheduled_at']);
        $this->assertNotNull($fresh['published_at']);
    }
}
