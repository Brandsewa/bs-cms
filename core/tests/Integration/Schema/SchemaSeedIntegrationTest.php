<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Schema;

use BsCms\Cli\Console;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Kernel\Application;
use BsCms\Schema\CollectionRegistry;
use PDO;
use PHPUnit\Framework\TestCase;

final class SchemaSeedIntegrationTest extends TestCase
{
    private Connection $connection;
    private string $dbName;
    private string $prefix;
    private string $tempDir;
    private PDO $adminPdo;
    private Application $app;
    private Console $console;

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

        $this->dbName = 'bs_seed_' . bin2hex(random_bytes(4));
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

        $this->tempDir = sys_get_temp_dir() . '/bs_seed_test_' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir . '/storage/keys', 0755, true);
        @mkdir($this->tempDir . '/storage/cache', 0755, true);

        // Mock config for Console
        $configContent = "<?php\nreturn [\n  'database' => [\n    'host' => '{$host}',\n    'port' => {$port},\n    'database' => '{$this->dbName}',\n    'username' => '{$user}',\n    'password' => '{$pass}',\n    'prefix' => '{$this->prefix}',\n  ],\n  'app' => [\n    'key' => '01234567890123456789012345678901',\n    'environment' => 'testing',\n    'debug' => true,\n  ],\n];\n";
        file_put_contents($this->tempDir . '/storage/config.php', $configContent);

        $this->app = new Application(dirname(__DIR__, 3), $this->tempDir . '/storage');
        $this->console = new Console($this->app);
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

    public function testFreshInstallSeedsBuiltInCollectionsIdempotently(): void
    {
        // 1. Run migrations first
        $migrator = new Migrator($this->connection);
        $migrator->registerNamespace('core', dirname(__DIR__, 3) . '/migrations');
        $migrator->migrate();

        // 2. Run schema:seed
        ob_start();
        $code1 = $this->console->run(['bin/bs', 'schema:seed']);
        $out1 = ob_get_clean();
        $this->assertSame(0, $code1, "schema:seed should exit with 0: {$out1}");

        // Verify tables exist
        $tables = [
            'bs_c_pages',
            'bs_c_posts',
            'bs_c_authors',
            'bs_c_site_settings',
            'bs_c_navigation',
        ];
        foreach ($tables as $tbl) {
            $stmt = $this->connection->execute("SHOW TABLES LIKE '{$tbl}'");
            $this->assertSame($tbl, $stmt->fetchColumn(), "Table {$tbl} must exist after seed");
        }

        // Verify registry can load all seeded collections and their Phase 4 fields
        $cacheFile = $this->tempDir . '/storage/cache/schema.php';
        $registry = new CollectionRegistry($this->connection, $cacheFile);
        $this->assertTrue($registry->has('pages'));
        $this->assertTrue($registry->has('posts'));
        $this->assertTrue($registry->has('authors'));
        $this->assertTrue($registry->has('site_settings'));
        $this->assertTrue($registry->has('navigation'));

        $postsDef = $registry->get('posts');
        $this->assertArrayHasKey('body', $postsDef['fields']);
        $this->assertArrayHasKey('featured_image', $postsDef['fields']);
        $this->assertArrayHasKey('categories', $postsDef['fields']);
        $this->assertArrayHasKey('tags', $postsDef['fields']);
        $this->assertArrayHasKey('faq', $postsDef['fields']);
        $this->assertArrayHasKey('key_facts', $postsDef['fields']);

        $pagesDef = $registry->get('pages');
        $this->assertArrayHasKey('blocks', $pagesDef['fields']);
        $this->assertArrayHasKey('featured_image', $pagesDef['fields']);

        $authorsDef = $registry->get('authors');
        $this->assertArrayHasKey('avatar', $authorsDef['fields']);

        // Verify built-in taxonomies and default terms exist
        $taxRepo = new \BsCms\Taxonomy\TaxonomyRepository($this->connection);
        $termRepo = new \BsCms\Taxonomy\TermRepository($this->connection);

        $categories = $taxRepo->findByHandle('categories');
        $this->assertNotNull($categories, "Categories taxonomy must be seeded");
        $this->assertSame(1, (int) $categories['hierarchical']);
        $uncategorized = $termRepo->findBySlug((string) $categories['id'], 'uncategorized');
        $this->assertNotNull($uncategorized, "Default term 'uncategorized' must be seeded");
        $this->assertSame('Uncategorized', $uncategorized['name']);

        $tags = $taxRepo->findByHandle('tags');
        $this->assertNotNull($tags, "Tags taxonomy must be seeded");
        $this->assertSame(0, (int) $tags['hierarchical']);

        // Test minimal input creation for every built-in collection
        $fieldRegistry = new \BsCms\Schema\FieldTypeRegistry();
        $slugService = new \BsCms\Content\SlugService($this->connection);
        $hierarchyValidator = new \BsCms\Content\HierarchyValidator($this->connection);
        $versionManager = new \BsCms\Content\VersionManager($this->connection);
        $relationManager = new \BsCms\Content\RelationManager($this->connection);
        $entryRepo = new \BsCms\Content\EntryRepository(
            $this->connection,
            $registry,
            $fieldRegistry,
            $slugService,
            $hierarchyValidator,
            $versionManager,
            $relationManager
        );

        // 1. Post with title only
        $post = $entryRepo->create('posts', ['title' => 'Minimal Post Title'], 'system', 'test');
        $this->assertNotEmpty($post['id']);
        $this->assertSame('Minimal Post Title', $post['title']);

        // 2. Page with title only (template defaults to 'default')
        $page = $entryRepo->create('pages', ['title' => 'Minimal Page Title'], 'system', 'test');
        $this->assertNotEmpty($page['id']);
        $this->assertSame('Minimal Page Title', $page['title']);
        $this->assertSame('default', $page['data']['template'] ?? null);

        // 3. Author with name only
        $author = $entryRepo->create('authors', ['name' => 'Jane Minimalist'], 'system', 'test');
        $this->assertNotEmpty($author['id']);
        $this->assertSame('Jane Minimalist', $author['title']);

        // 3. Run schema:seed a second time (idempotency test - no changes, no crash)
        ob_start();
        $code2 = $this->console->run(['bin/bs', 'schema:seed']);
        $out2 = ob_get_clean();
        $this->assertSame(0, $code2);
        $this->assertStringContainsString('already up to date', (string) $out2);

        // 4. Test rollback, re-migrate, re-seed
        $migrator->rollback();
        $migrator->migrate();

        ob_start();
        $code3 = $this->console->run(['bin/bs', 'schema:seed']);
        $out3 = ob_get_clean();
        $this->assertSame(0, $code3);

        foreach ($tables as $tbl) {
            $stmt = $this->connection->execute("SHOW TABLES LIKE '{$tbl}'");
            $this->assertSame($tbl, $stmt->fetchColumn(), "Table {$tbl} must exist after re-seed");
        }
    }
}
