<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Schema;

use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use BsCms\Schema\SchemaApplier;
use BsCms\Schema\SchemaLock;
use BsCms\Schema\SchemaPlanner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaEngineIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName;
    private string $prefix;
    private string $tempDir;
    private PDO $adminPdo;
    private CollectionRegistry $registry;
    private FieldTypeRegistry $fieldTypeRegistry;
    private SchemaPlanner $planner;
    private SchemaLock $lock;
    private SchemaApplier $applier;

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

        $this->dbName = 'bs_se_' . bin2hex(random_bytes(4));
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

        $this->tempDir = sys_get_temp_dir() . '/bs_schema_' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0755, true);

        // Run core migrations
        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $migrator->migrate();

        $cacheFile = $this->tempDir . '/cache/schema.php';
        $this->registry = new CollectionRegistry($this->connection, $cacheFile);
        $this->fieldTypeRegistry = new FieldTypeRegistry();
        $this->planner = new SchemaPlanner($this->connection, $this->registry, $this->fieldTypeRegistry);
        $this->lock = new SchemaLock($this->connection);
        $this->applier = new SchemaApplier($this->connection, $this->registry, $this->lock);
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

    public function testCreateCollectionWithPlannerAndApplier(): void
    {
        $target = [
            'handle' => 'articles',
            'kind' => 'collection',
            'label_singular' => 'Article',
            'label_plural' => 'Articles',
            'fields' => [
                'headline' => [
                    'handle' => 'headline',
                    'label' => 'Headline',
                    'type' => 'text',
                    'required' => true,
                    'is_filterable' => true,
                    'is_sortable' => true,
                    'config' => ['max_length' => 200],
                ],
                'view_count' => [
                    'handle' => 'view_count',
                    'label' => 'View Count',
                    'type' => 'number',
                    'is_filterable' => true,
                    'is_sortable' => true,
                    'config' => ['precision' => 0],
                ],
            ],
        ];

        $plan = $this->planner->plan($target);
        $this->assertCount(1, $plan->sqlStatements);
        $this->assertStringContainsString('CREATE TABLE', $plan->sqlStatements[0]);
        $this->assertStringContainsString('`bs_c_articles`', $plan->sqlStatements[0]);
        $this->assertStringContainsString('`headline` VARCHAR(200) GENERATED ALWAYS AS', $plan->sqlStatements[0]);

        $this->applier->apply($plan, $target, null, 'cli');

        // Verify registry loaded collection and fields
        $this->assertTrue($this->registry->has('articles'));
        $col = $this->registry->get('articles');
        $this->assertSame('articles', $col['handle']);
        $this->assertSame(1, (int) $col['schema_version']);
        $this->assertArrayHasKey('headline', $col['fields']);
        $this->assertArrayHasKey('view_count', $col['fields']);

        // Verify table exists in DB and can insert a test row
        $stmt = $this->connection->execute("SHOW TABLES LIKE 'bs_c_articles'");
        $this->assertSame('bs_c_articles', $stmt->fetchColumn());
    }

    public function testAlterCollectionAddFilterableField(): void
    {
        $initialTarget = [
            'handle' => 'products',
            'label_singular' => 'Product',
            'label_plural' => 'Products',
            'fields' => [
                'sku' => [
                    'handle' => 'sku',
                    'label' => 'SKU',
                    'type' => 'text',
                    'is_filterable' => false,
                    'config' => ['max_length' => 50],
                ],
            ],
        ];

        $plan1 = $this->planner->plan($initialTarget);
        $this->applier->apply($plan1, $initialTarget);

        // Alter: make sku filterable and add price
        $updatedTarget = [
            'handle' => 'products',
            'label_singular' => 'Product',
            'label_plural' => 'Products',
            'fields' => [
                'sku' => [
                    'handle' => 'sku',
                    'label' => 'SKU',
                    'type' => 'text',
                    'is_filterable' => true,
                    'is_sortable' => true,
                    'config' => ['max_length' => 50],
                ],
                'price' => [
                    'handle' => 'price',
                    'label' => 'Price',
                    'type' => 'number',
                    'is_filterable' => true,
                    'is_sortable' => true,
                    'config' => ['precision' => 2],
                ],
            ],
        ];

        $plan2 = $this->planner->plan($updatedTarget);
        $this->assertNotEmpty($plan2->sqlStatements);
        $this->applier->apply($plan2, $updatedTarget);

        $col = $this->registry->get('products');
        $this->assertSame(2, (int) $col['schema_version']);
        $this->assertTrue($col['fields']['sku']['is_filterable']);
        $this->assertArrayHasKey('price', $col['fields']);
    }

    public function testConcurrentSchemaApplyBlockedByLock(): void
    {
        // Second connection to test concurrent GET_LOCK block
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';

        $conn2 = new Connection([
            'host' => $host,
            'port' => $port,
            'database' => $this->dbName,
            'username' => $user,
            'password' => $pass,
            'prefix' => $this->prefix,
        ]);

        $lock1 = new SchemaLock($this->connection);
        $lock2 = new SchemaLock($conn2);

        $lock1->acquire(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to acquire schema lock');
        $lock2->acquire(1); // Should timeout after 1 second
    }

    public function testFailedDdlRollsBackAndLeavesRegistryUnchanged(): void
    {
        $target = [
            'handle' => 'books',
            'label_singular' => 'Book',
            'label_plural' => 'Books',
            'fields' => [
                'isbn' => [
                    'handle' => 'isbn',
                    'label' => 'ISBN',
                    'type' => 'text',
                ],
            ],
        ];

        // Create an invalid schema plan with bad SQL syntax to force failure
        $plan = new \BsCms\Schema\SchemaPlan(
            \BsCms\Security\Ulid::generate(),
            'books',
            [['type' => 'create_collection']],
            ['CREATE TABLE `INVALID SYNTAX SHOULD FAIL` (('],
            ['data_loss' => false, 'long_running' => false, 'locks_table' => false]
        );

        try {
            $this->applier->apply($plan, $target);
            $this->fail('Expected applier to throw on invalid SQL');
        } catch (\Throwable) {
            // Expected
        }

        // Verify registry does not contain 'books'
        $this->assertFalse($this->registry->has('books'));

        // Verify schema_changes row was marked failed
        $stmt = $this->connection->execute(
            "SELECT `status` FROM `{$this->prefix}schema_changes` WHERE `id` = ?",
            [$plan->id]
        );
        $this->assertSame('failed', $stmt->fetchColumn());
    }
}
