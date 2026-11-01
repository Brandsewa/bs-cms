<?php

declare(strict_types=1);

namespace BsCms\Tests\Integration\Database;

use BsCms\Cli\Console;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Kernel\Application;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies migrate / rollback / re-migrate against a real MySQL or MariaDB server.
 * Fails (does not skip) in CI when DB_HOST is provided but the server is unreachable.
 */
final class MigratorRollbackTest extends TestCase
{
    private Connection $connection;
    private string $dbName;
    private string $prefix;
    private string $tempDir;
    private PDO $adminPdo;

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

        $this->dbName = 'bs_rb_' . bin2hex(random_bytes(4));
        $this->prefix = 'rb_';
        $this->adminPdo->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->connection = new Connection([
            'host' => $host,
            'port' => $port,
            'database' => $this->dbName,
            'username' => $user,
            'password' => $pass,
            'prefix' => $this->prefix,
        ]);

        $this->tempDir = sys_get_temp_dir() . '/bs_rollback_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->adminPdo)) {
            $this->adminPdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }
        if (isset($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    private function coreDir(): string
    {
        return dirname(__DIR__, 3) . '/migrations';
    }

    /** @return list<string> */
    private function tables(): array
    {
        $stmt = $this->connection->getPdo()->query('SHOW TABLES');
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        /** @var list<string> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        sort($rows);
        return $rows;
    }

    private function migrator(string ...$dirs): Migrator
    {
        $migrator = new Migrator($this->connection);
        foreach ($dirs as $i => $dir) {
            $migrator->registerNamespace($i === 0 ? 'core' : 'plugin:test' . $i, $dir);
        }
        return $migrator;
    }

    public function testMigrateRollbackAndMigrateAgainRestoresTables(): void
    {
        $migrator = $this->migrator($this->coreDir());

        $migrator->migrate();
        $expected = [
            'rb_api_tokens',
            'rb_audit_log',
            'rb_bot_log',
            'rb_build_tokens',
            'rb_collection_taxonomies',
            'rb_collections',
            'rb_crawler_policy_versions',
            'rb_deployments',
            'rb_email_log',
            'rb_email_verifications',
            'rb_entry_relations',
            'rb_entry_versions',
            'rb_fields',
            'rb_form_definitions',
            'rb_form_submissions',
            'rb_index_notifications',
            'rb_invitations',
            'rb_jobs',
            'rb_media',
            'rb_media_folders',
            'rb_media_usage',
            'rb_media_variants',
            'rb_migrations',
            'rb_notifications',
            'rb_oauth_access_tokens',
            'rb_oauth_authorization_codes',
            'rb_oauth_clients',
            'rb_oauth_grants',
            'rb_oauth_refresh_tokens',
            'rb_password_resets',
            'rb_pending_changes',
            'rb_plugins',
            'rb_preview_tokens',
            'rb_rate_limits',
            'rb_redirects',
            'rb_role_permissions',
            'rb_roles',
            'rb_saved_views',
            'rb_schema_changes',
            'rb_sessions',
            'rb_settings',
            'rb_taxonomies',
            'rb_term_relationships',
            'rb_terms',
            'rb_user_roles',
            'rb_users',
            'rb_webhook_deliveries',
            'rb_webhooks',
        ];
        $this->assertSame($expected, $this->tables());

        $rolledBack = $migrator->rollback();
        $this->assertSame([
            'core:202610310012_replace_pages_blocks_with_puck_content',
            'core:202610310011_create_plugins_and_forms_tables',
            'core:202610220010_update_pages_blocks_field_type_to_blocks',
            'core:202610190009_create_oauth_and_pending_changes_tables',
            'core:202610190008_create_publishing_pipeline_and_discoverability_tables',
            'core:202610180007_create_media_and_redirect_tables',
            'core:202610180006_create_taxonomy_tables',
            'core:202610180005_create_content_engine_tables',
            'core:202610180004_add_totp_last_step_to_users',
            'core:202610170003_seed_system_roles',
            'core:202610170002_create_auth_and_user_tables',
            'core:202610170001_create_core_tables',
        ], $rolledBack);
        $this->assertSame(['rb_migrations'], $this->tables(), 'Core tables must be dropped, only the bookkeeping table remains');
        $this->assertSame([], $migrator->getAppliedMigrations());

        $again = $migrator->migrate();
        $this->assertSame([
            'core:202610170001_create_core_tables',
            'core:202610170002_create_auth_and_user_tables',
            'core:202610170003_seed_system_roles',
            'core:202610180004_add_totp_last_step_to_users',
            'core:202610180005_create_content_engine_tables',
            'core:202610180006_create_taxonomy_tables',
            'core:202610180007_create_media_and_redirect_tables',
            'core:202610190008_create_publishing_pipeline_and_discoverability_tables',
            'core:202610190009_create_oauth_and_pending_changes_tables',
            'core:202610220010_update_pages_blocks_field_type_to_blocks',
            'core:202610310011_create_plugins_and_forms_tables',
            'core:202610310012_replace_pages_blocks_with_puck_content',
        ], $again);
        $this->assertSame($expected, $this->tables());
    }

    public function testDryRunRollbackChangesNothing(): void
    {
        $migrator = $this->migrator($this->coreDir());
        $migrator->migrate();

        $preview = $migrator->rollback(1, true);
        $this->assertSame([
            '[DRY-RUN] rollback core:202610310012_replace_pages_blocks_with_puck_content',
            '[DRY-RUN] rollback core:202610310011_create_plugins_and_forms_tables',
            '[DRY-RUN] rollback core:202610220010_update_pages_blocks_field_type_to_blocks',
            '[DRY-RUN] rollback core:202610190009_create_oauth_and_pending_changes_tables',
            '[DRY-RUN] rollback core:202610190008_create_publishing_pipeline_and_discoverability_tables',
            '[DRY-RUN] rollback core:202610180007_create_media_and_redirect_tables',
            '[DRY-RUN] rollback core:202610180006_create_taxonomy_tables',
            '[DRY-RUN] rollback core:202610180005_create_content_engine_tables',
            '[DRY-RUN] rollback core:202610180004_add_totp_last_step_to_users',
            '[DRY-RUN] rollback core:202610170003_seed_system_roles',
            '[DRY-RUN] rollback core:202610170002_create_auth_and_user_tables',
            '[DRY-RUN] rollback core:202610170001_create_core_tables',
        ], $preview);
        $this->assertContains('rb_settings', $this->tables());
        $this->assertCount(12, $migrator->getAppliedMigrations());
    }

    public function testRollbackWithNothingAppliedReturnsEmptyList(): void
    {
        $migrator = $this->migrator($this->coreDir());
        $this->assertSame([], $migrator->rollback());
    }

    public function testStepRollsBackOnlyTheLastBatch(): void
    {
        file_put_contents($this->tempDir . '/202610180001_create_alpha_items.php', $this->migrationSource('CreateAlphaItems', 'alpha_items'));
        file_put_contents($this->tempDir . '/202610180002_create_beta_items.php', $this->migrationSource('CreateBetaItems', 'beta_items'));

        $migrator = $this->migrator($this->coreDir(), $this->tempDir);

        // Batch 1: core + alpha + beta are applied together; add a third in a later batch.
        $migrator->migrate();
        $this->assertContains('rb_alpha_items', $this->tables());
        $this->assertContains('rb_beta_items', $this->tables());

        file_put_contents($this->tempDir . '/202610180003_create_gamma_items.php', $this->migrationSource('CreateGammaItems', 'gamma_items'));
        $migrator->migrate();
        $this->assertContains('rb_gamma_items', $this->tables());

        // Step 1 reverts only batch 2 (gamma).
        $first = $migrator->rollback(1);
        $this->assertSame(['plugin:test1:202610180003_create_gamma_items'], $first);
        $this->assertNotContains('rb_gamma_items', $this->tables());
        $this->assertContains('rb_alpha_items', $this->tables());

        // Step 1 again reverts batch 1 in reverse order (beta, alpha, core).
        $second = $migrator->rollback(1);
        $this->assertSame([
            'plugin:test1:202610180002_create_beta_items',
            'plugin:test1:202610180001_create_alpha_items',
            'core:202610310012_replace_pages_blocks_with_puck_content',
            'core:202610310011_create_plugins_and_forms_tables',
            'core:202610220010_update_pages_blocks_field_type_to_blocks',
            'core:202610190009_create_oauth_and_pending_changes_tables',
            'core:202610190008_create_publishing_pipeline_and_discoverability_tables',
            'core:202610180007_create_media_and_redirect_tables',
            'core:202610180006_create_taxonomy_tables',
            'core:202610180005_create_content_engine_tables',
            'core:202610180004_add_totp_last_step_to_users',
            'core:202610170003_seed_system_roles',
            'core:202610170002_create_auth_and_user_tables',
            'core:202610170001_create_core_tables',
        ], $second);
        $this->assertSame(['rb_migrations'], $this->tables());
    }

    public function testRollbackAbortsBeforeChangingAnythingWhenAFileIsMissing(): void
    {
        $migrator = $this->migrator($this->coreDir());
        $migrator->migrate();

        $withoutCore = new Migrator($this->connection);
        $withoutCore->registerNamespace('core', $this->tempDir);

        try {
            $withoutCore->rollback();
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not registered', $e->getMessage());
        }

        $this->assertContains('rb_settings', $this->tables());
        $this->assertCount(12, $migrator->getAppliedMigrations());
    }


    public function testRollbackRejectsInvalidStepCount(): void
    {
        $this->expectException(RuntimeException::class);
        $this->migrator($this->coreDir())->rollback(0);
    }


    /**
     * MySQL normalises JSON object key order (by key length, then value), so
     * decoded JSON must be key-sorted recursively before identity asserts.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function sortJsonKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $sorted = [];
        $keys = array_keys($value);
        $sortKeys = static function (array $keys): array {
            usort($keys, static fn (string $a, string $b): int => [strlen($a), $a] <=> [strlen($b), $b]);
            return $keys;
        };
        foreach ($sortKeys($keys) as $k) {
            $sorted[$k] = self::sortJsonKeys($value[$k]);
        }
        return $sorted;
    }

    public function testPuckContentMigrationConvertsLegacyBlocks(): void
    {
        $migrator = $this->migrator($this->coreDir());
        $migrator->migrate();

        $pdo = $this->connection->getPdo();
        $prefix = $this->prefix;
        $collections = "{$prefix}collections";
        $fields = "{$prefix}fields";
        $pages = "{$prefix}c_pages";
        $versions = "{$prefix}entry_versions";
        $now = date('Y-m-d H:i:s.v');

        // Simulate the legacy state: pages collection + blocks field row + content table.
        $pdo->exec("CREATE TABLE `{$pages}` (
            `id` CHAR(26) PRIMARY KEY,
            `data` JSON NOT NULL,
            `updated_at` DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $colId = '01ARZ3NDEKTSV4RRFFQ69G5FC0';
        $stmt = $pdo->prepare("INSERT INTO `{$collections}` (
            `id`, `handle`, `label_singular`, `label_plural`, `table_name`, `features`, `created_at`, `updated_at`
        ) VALUES (?, 'pages', 'Page', 'Pages', 'c_pages', '{}', ?, ?)");
        $stmt->execute([$colId, $now, $now]);

        $stmt = $pdo->prepare("INSERT INTO `{$fields}` (
            `id`, `collection_id`, `handle`, `label`, `type`, `config`, `created_at`, `updated_at`
        ) VALUES (?, ?, 'blocks', 'Page Blocks', 'blocks', '{}', ?, ?)");
        $stmt->execute(['01ARZ3NDEKTSV4RRFFQ69G5FC1', $colId, $now, $now]);

        $seedPage = function (string $id, array $data) use ($pdo, $pages, $now): void {
            $stmt = $pdo->prepare("INSERT INTO `{$pages}` (`id`, `data`, `updated_at`) VALUES (?, ?, ?)");
            $stmt->execute([$id, json_encode($data), $now]);
        };

        $seedPage('01ARZ3NDEKTSV4RRFFQ69G5FPA', [
            'blocks' => [
                ['type' => 'hero', 'heading' => 'Welcome', 'text' => 'Hello', 'cta_label' => 'Go', 'cta_url' => '/go'],
                ['type' => 'text', 'content' => '<p>Body</p>'],
            ],
        ]);
        $seedPage('01ARZ3NDEKTSV4RRFFQ69G5FPB', [
            'blocks' => [
                ['type' => 'callout', 'data' => ['variant' => 'tip', 'title' => 'Tip', 'content' => 'Useful']],
                ['type' => 'faq', 'items' => [['question' => 'Q?', 'answer' => 'A.']]],
                ['type' => 'mystery', 'data' => ['nope' => true]],
            ],
        ]);
        $seedPage('01ARZ3NDEKTSV4RRFFQ69G5FPC', ['title' => 'No blocks here']);
        $seedPage('01ARZ3NDEKTSV4RRFFQ69G5FPD', ['blocks' => 'not-an-array']);

        // A version snapshot embedding legacy blocks data.
        $stmt = $pdo->prepare("INSERT INTO `{$versions}` (
            `id`, `collection_id`, `entry_id`, `version_no`, `status`, `snapshot`, `actor_type`, `created_at`
        ) VALUES (?, ?, ?, 1, 'published', ?, 'user', ?)");
        $stmt->execute([
            '01ARZ3NDEKTSV4RRFFQ69G5FC2',
            $colId,
            '01ARZ3NDEKTSV4RRFFQ69G5FPA',
            json_encode(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FPA', 'title' => 'Home', 'data' => [
                'blocks' => [['type' => 'text', 'data' => ['content' => '<p>v1</p>']]],
            ]]),
            $now,
        ]);

        $migration = new \BsCms\Migrations\ReplacePagesBlocksWithPuckContent($this->connection);
        $migration->up();

        // Fetches a single scalar value from a query.
        $scalar = function (string $sql) use ($pdo): string {
            $stmt = $pdo->query($sql);
            $this->assertInstanceOf(\PDOStatement::class, $stmt);
            $value = $stmt->fetchColumn();
            $this->assertNotFalse($value);
            return (string) $value;
        };

        // Fields table: blocks row replaced by content row (type puck), position preserved.
        $stmt = $pdo->prepare("SELECT `handle`, `type`, `label` FROM `{$fields}` WHERE `collection_id` = ?");
        $stmt->execute([$colId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byHandle = array_column($rows, null, 'handle');
        $this->assertArrayNotHasKey('blocks', $byHandle);
        $this->assertSame(['content', 'puck', 'Page Content'], array_values($byHandle['content'] ?? []));

        // Page A: hero + text converted with deterministic ids.
        $pageA = json_decode($scalar("SELECT `data` FROM `{$pages}` WHERE `id` = '01ARZ3NDEKTSV4RRFFQ69G5FPA'"), true);
        $this->assertArrayNotHasKey('blocks', $pageA);
        // MySQL normalises JSON object key order — compare key-sorted.
        $this->assertSame(self::sortJsonKeys([
            'content' => [
                ['type' => 'Hero', 'props' => ['id' => 'Hero-mig1', 'heading' => 'Welcome', 'text' => 'Hello', 'ctaLabel' => 'Go', 'ctaUrl' => '/go']],
                ['type' => 'RichText', 'props' => ['id' => 'RichText-mig2', 'html' => '<p>Body</p>']],
            ],
            'root' => ['props' => []],
        ]), self::sortJsonKeys($pageA['content']));

        // Page B: admin-style {type, data} callout + faq converted, unknown dropped.
        $pageB = json_decode($scalar("SELECT `data` FROM `{$pages}` WHERE `id` = '01ARZ3NDEKTSV4RRFFQ69G5FPB'"), true);
        $this->assertSame(self::sortJsonKeys([
            ['type' => 'Callout', 'props' => ['id' => 'Callout-mig1', 'variant' => 'tip', 'title' => 'Tip', 'content' => 'Useful']],
            ['type' => 'Faq', 'props' => ['id' => 'Faq-mig2', 'items' => [['question' => 'Q?', 'answer' => 'A.']]]],
        ]), self::sortJsonKeys($pageB['content']['content']));

        // Page C untouched (no blocks key), Page D stripped to an empty PuckData.
        $pageC = json_decode($scalar("SELECT `data` FROM `{$pages}` WHERE `id` = '01ARZ3NDEKTSV4RRFFQ69G5FPC'"), true);
        $this->assertArrayNotHasKey('content', $pageC);
        $pageD = json_decode($scalar("SELECT `data` FROM `{$pages}` WHERE `id` = '01ARZ3NDEKTSV4RRFFQ69G5FPD'"), true);
        $this->assertSame(self::sortJsonKeys(['content' => [], 'root' => ['props' => []]]), self::sortJsonKeys($pageD['content']));

        // Snapshot converted the same way.
        $snapshot = json_decode($scalar("SELECT `snapshot` FROM `{$versions}` WHERE `id` = '01ARZ3NDEKTSV4RRFFQ69G5FC2'"), true);
        $this->assertArrayNotHasKey('blocks', $snapshot['data']);
        $this->assertSame(
            [['type' => 'RichText', 'props' => ['id' => 'RichText-mig1', 'html' => '<p>v1</p>']]],
            self::sortJsonKeys($snapshot['data']['content']['content'])
        );

        // Re-running is idempotent.
        $migration->up();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$fields}` WHERE `collection_id` = ? AND `handle` = 'content'");
        $stmt->execute([$colId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        // down(): restores the blocks field row and removes the content row.
        $migration->down();
        $stmt = $pdo->prepare("SELECT `type` FROM `{$fields}` WHERE `collection_id` = ? AND `handle` = 'blocks'");
        $stmt->execute([$colId]);
        $this->assertSame('blocks', (string) $stmt->fetchColumn());
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$fields}` WHERE `collection_id` = ? AND `handle` = 'content'");
        $stmt->execute([$colId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testCliRollbackActuallyDropsTables(): void
    {
        $appDir = $this->tempDir . '/app';
        mkdir($appDir . '/config', 0755, true);
        mkdir($appDir . '/storage', 0755, true);
        mkdir($appDir . '/migrations', 0755, true);
        file_put_contents($appDir . '/migrations/202610190001_create_cli_probe.php', $this->migrationSource('CreateCliProbe', 'cli_probe'));

        $db = [
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'database' => $this->dbName,
            'username' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
            'prefix' => $this->prefix,
        ];
        file_put_contents($appDir . '/config/database.php', '<?php return ' . var_export($db, true) . ';');
        file_put_contents($appDir . '/config/app.php', "<?php return ['name' => 'T'];");

        $console = new Console(new Application($appDir));

        ob_start();
        $this->assertSame(0, $console->run(['bs', 'migrate']));
        ob_end_clean();
        $this->assertContains('rb_cli_probe', $this->tables());

        ob_start();
        $this->assertSame(0, $console->run(['bs', 'migrate:rollback', '--dry-run']));
        $dry = (string) ob_get_clean();
        $this->assertStringContainsString('[DRY-RUN] rollback core:202610190001_create_cli_probe', $dry);
        $this->assertContains('rb_cli_probe', $this->tables());

        ob_start();
        $this->assertSame(0, $console->run(['bs', 'migrate:rollback', '--step=1']));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('Rolled back: core:202610190001_create_cli_probe', $out);
        $this->assertNotContains('rb_cli_probe', $this->tables());

        ob_start();
        $this->assertSame(0, $console->run(['bs', 'migrate:rollback']));
        $this->assertStringContainsString('Nothing to roll back.', (string) ob_get_clean());

        ob_start();
        $code = $console->run(['bs', 'migrate:rollback', '--step=abc']);
        ob_end_clean();
        $this->assertSame(1, $code);

        foreach (['/config/*', '/migrations/*'] as $pattern) {
            foreach (glob($appDir . $pattern) ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    private function migrationSource(string $class, string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace BsCms\\Migrations;

use BsCms\\Database\\Migration;

final class {$class} extends Migration
{
    public function up(): void
    {
        \$t = \$this->connection->prefixTable('{$table}');
        \$this->connection->getPdo()->exec("CREATE TABLE IF NOT EXISTS `{\$t}` (`id` INT PRIMARY KEY) ENGINE=InnoDB");
    }

    public function down(): void
    {
        \$t = \$this->connection->prefixTable('{$table}');
        \$this->connection->getPdo()->exec("DROP TABLE IF EXISTS `{\$t}`");
    }
}
PHP;
    }
}
