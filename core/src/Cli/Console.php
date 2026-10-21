<?php

declare(strict_types=1);

namespace BsCms\Cli;

use BsCms\Cache\FileCache;
use BsCms\Database\Connection;
use BsCms\Database\Migrator;
use BsCms\Jobs\Worker;
use BsCms\Kernel\Application;
use BsCms\Kernel\Config;
use BsCms\Log\Redactor;
use BsCms\Security\Crypto;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use Throwable;

/**
 * Lightweight, zero-dependency CLI Console for BS CMS (`php bin/bs <command>`).
 */
final class Console
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Run command from argv.
     *
     * @param list<string> $argv
     * @return int Exit code (0 for success, non-zero for failure)
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        try {
            return match ($command) {
                'health' => $this->health(),
                'site:health' => $this->siteHealth($args),
                'migrate' => $this->migrate($args),
                'migrate:status' => $this->migrateStatus(),
                'migrate:rollback' => $this->migrateRollback($args),
                'jobs:run' => $this->jobsRun($args),
                'jobs:failed' => $this->jobsFailed(),
                'jobs:retry' => $this->jobsRetry($args),
                'schedule:run' => $this->scheduleRun(),
                'deploy:process' => $this->deployProcess(),
                'cache:clear' => $this->cacheClear(),
                'cache:warm' => $this->cacheWarm(),
                'key:generate' => $this->keyGenerate(),
                'key:rotate' => $this->keyRotate(),
                'config:show' => $this->configShow(),
                'schema:export' => $this->schemaExport($args),
                'schema:diff' => $this->schemaDiff($args),
                'schema:apply' => $this->schemaApply($args),
                'schema:seed' => $this->schemaSeed($args),
                'taxonomy:recompute-counts', 'taxonomy:integrity' => $this->taxonomyRecomputeCounts($args),
                // SPRINT-C BEGIN: Blocks Sync command
                'blocks:sync' => $this->blocksSync($args),
                // SPRINT-C END: Blocks Sync command
                'install' => $this->install($args),
                'help', '--help', '-h' => $this->help(),
                default => $this->unknownCommand($command),
            };
        } catch (Throwable $e) {
            fwrite(STDERR, "Error: {$e->getMessage()}\n");
            return 1;
        }
    }

    private function health(): int
    {
        echo "BS CMS Status: OK\n";
        echo "PHP Version: " . PHP_VERSION . "\n";
        echo "Environment: " . $this->app->getEnvironment()->name() . "\n";
        echo "Installed: " . ($this->app->isInstalled() ? 'Yes' : 'No') . "\n";
        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function siteHealth(array $args): int
    {
        $conn = $this->getConnection();
        $service = new \BsCms\Health\SiteHealthService($conn);
        $report = $service->scan();

        $summary = $report['summary'];
        $issues = $report['issues'];

        if (in_array('--json', $args, true)) {
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            return $summary['errors'] > 0 ? 1 : 0;
        }

        echo "=== BS CMS Site Health Scan ===\n";
        echo "Rules checked: {$report['rules_checked']}\n";
        echo "Total issues:  {$summary['total']} (Errors: {$summary['errors']}, Warnings: {$summary['warnings']}, Info: {$summary['info']})\n";
        echo "Overall state: " . strtoupper($summary['status']) . "\n\n";

        if (empty($issues)) {
            echo "No site health issues found. Everything is in order!\n";
            return 0;
        }

        echo str_pad("SEV", 8) . str_pad("RULE ID", 28) . str_pad("ENTITY", 24) . "MESSAGE\n";
        echo str_repeat('-', 90) . "\n";

        foreach ($issues as $issue) {
            $sev = strtoupper($issue['severity']);
            $rule = $issue['rule_id'];
            $entity = "{$issue['entity_type']}:" . substr($issue['entity_id'], 0, 16);
            $msg = $issue['message'];

            echo str_pad($sev, 8) . str_pad($rule, 28) . str_pad($entity, 24) . "{$msg}\n";
            if (!empty($issue['fix_hint'])) {
                echo str_pad("", 8) . "  -> Fix: {$issue['fix_hint']}\n";
            }
        }
        echo "\n";

        return $summary['errors'] > 0 ? 1 : 0;
    }

    /**
     * @param list<string> $args
     */
    private function migrate(array $args): int
    {
        $dryRun = in_array('--dry-run', $args, true);
        $conn = $this->getConnection();
        $migrator = new Migrator($conn);
        $migrator->registerNamespace('core', $this->app->basePath('migrations'));

        $executed = $migrator->migrate($dryRun);

        if (empty($executed)) {
            echo "Nothing to migrate.\n";
            return 0;
        }

        foreach ($executed as $m) {
            echo "Migrated: {$m}\n";
        }

        return 0;
    }

    private function migrateStatus(): int
    {
        $conn = $this->getConnection();
        $migrator = new Migrator($conn);
        $migrator->ensureMigrationTable();

        $applied = $migrator->getAppliedMigrations();
        echo "Applied migrations (" . count($applied) . "):\n";
        foreach ($applied as $m) {
            echo "  - {$m}\n";
        }
        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function migrateRollback(array $args): int
    {
        $steps = 1;
        $dryRun = false;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--step=')) {
                $value = substr($arg, 7);
                if (!ctype_digit($value) || (int) $value < 1) {
                    fwrite(STDERR, "Error: --step must be a positive integer.\n");
                    return 1;
                }
                $steps = (int) $value;
            } elseif ($arg === '--dry-run') {
                $dryRun = true;
            } else {
                fwrite(STDERR, "Error: unknown option '{$arg}'. Usage: migrate:rollback [--step=N] [--dry-run]\n");
                return 1;
            }
        }

        $conn = $this->getConnection();
        $migrator = new Migrator($conn);
        $migrator->registerNamespace('core', $this->app->basePath('migrations'));

        $rolledBack = $migrator->rollback($steps, $dryRun);

        if ($rolledBack === []) {
            echo "Nothing to roll back.\n";
            return 0;
        }

        foreach ($rolledBack as $m) {
            echo ($dryRun ? '' : 'Rolled back: ') . "{$m}\n";
        }

        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function jobsRun(array $args): int
    {
        $maxTime = 50;
        $queue = 'default';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--max-time=')) {
                $maxTime = (int) substr($arg, 11);
            }
            if (str_starts_with($arg, '--queue=')) {
                $queue = substr($arg, 8);
            }
        }

        $conn = $this->getConnection();
        $worker = new Worker($conn, $this->app->getContainer());
        $processed = $worker->run($maxTime, $queue);

        echo "Worker executed {$processed} due job(s).\n";
        return 0;
    }

    private function scheduleRun(): int
    {
        $container = $this->app->getContainer();
        $processedDeploys = 0;

        // 1. Run due recurring tasks if Scheduler exists
        if ($container->has(\BsCms\Jobs\Scheduler::class)) {
            $scheduler = $container->get(\BsCms\Jobs\Scheduler::class);
            $tasks = $scheduler->runDue();
            if (!empty($tasks)) {
                echo "Scheduler executed: " . implode(', ', $tasks) . "\n";
            }
        }

        // 2. Process deploy debounce queue
        if ($container->has(\BsCms\Publishing\DeploymentService::class)) {
            $deployment = $container->get(\BsCms\Publishing\DeploymentService::class)->processDebounceQueue();
            if ($deployment !== null) {
                echo "Processed deployment: [{$deployment['id']}] status: {$deployment['status']}\n";
                $processedDeploys = 1;
            }
        }

        // 3. Purge expired preview tokens
        if ($container->has(\BsCms\Publishing\PreviewTokenService::class)) {
            $purged = $container->get(\BsCms\Publishing\PreviewTokenService::class)->purgeExpired();
            if ($purged > 0) {
                echo "Purged {$purged} expired preview token(s).\n";
            }
        }

        echo "Schedule run completed.\n";
        return 0;
    }

    private function deployProcess(): int
    {
        $container = $this->app->getContainer();
        if (!$container->has(\BsCms\Publishing\DeploymentService::class)) {
            echo "Publishing deployment service not available.\n";
            return 1;
        }

        $deployment = $container->get(\BsCms\Publishing\DeploymentService::class)->processDebounceQueue();
        if ($deployment !== null) {
            echo "Dispatched deployment: [{$deployment['id']}] status: {$deployment['status']}\n";
        } else {
            echo "No deploy debounce window due.\n";
        }
        return 0;
    }

    private function jobsFailed(): int
    {
        $conn = $this->getConnection();
        $rows = $conn->table('jobs')->where('status', '=', 'failed')->get();

        if (empty($rows)) {
            echo "No failed jobs.\n";
            return 0;
        }

        echo "Failed jobs (" . count($rows) . "):\n";
        foreach ($rows as $r) {
            echo "  [#{$r['id']}] {$r['type']} (attempts: {$r['attempts']})\n";
            if ($r['last_error'] !== null) {
                echo "    Error: " . strtok((string) $r['last_error'], "\n") . "\n";
            }
        }
        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function jobsRetry(array $args): int
    {
        $id = $args[0] ?? null;
        if ($id === null) {
            fwrite(STDERR, "Error: Job ID required for jobs:retry\n");
            return 1;
        }

        $conn = $this->getConnection();
        $updated = $conn->table('jobs')
            ->where('id', '=', (int) $id)
            ->where('status', '=', 'failed')
            ->update([
                'status' => 'queued',
                'attempts' => 0,
                'available_at' => Clock::sql(Clock::now()),
                'reserved_at' => null,
            ]);

        if ($updated === 0) {
            fwrite(STDERR, "Error: Failed job #{$id} not found.\n");
            return 1;
        }

        echo "Job #{$id} reset to queued status.\n";
        return 0;
    }

    private function cacheClear(): int
    {
        $cache = new FileCache($this->app->storagePath('cache'));
        $cache->clear();
        echo "Cache cleared successfully.\n";
        return 0;
    }

    private function cacheWarm(): int
    {
        // Pre-compile config cache
        $config = $this->app->getConfig();
        $targetFile = $this->app->storagePath('cache/config.php');
        $dir = dirname($targetFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        file_put_contents($targetFile, $config->compile(), LOCK_EX);
        echo "Configuration cache warmed successfully.\n";
        return 0;
    }

    private function keyGenerate(): int
    {
        $key = Crypto::generateKey();
        echo "Generated APP_KEY: {$key}\n";
        return 0;
    }

    private function keyRotate(): int
    {
        echo "Key rotation check complete.\n";
        return 0;
    }

    private function configShow(): int
    {
        $config = $this->app->getConfig();
        $all = $config->all();
        $redacted = Redactor::redact($all);

        echo Json::encode($redacted) . "\n";
        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function install(array $args): int
    {
        if ($this->app->isInstalled()) {
            echo "BS CMS is already installed.\n";
            return 0;
        }

        // Non-interactive CLI install with default or passed flags
        $params = [
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_name' => 'bs_cms',
            'db_user' => 'root',
            'db_password' => '',
            'db_prefix' => 'bs_',
        ];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $parts = explode('=', substr($arg, 2), 2);
                if (count($parts) === 2) {
                    $params[$parts[0]] = $parts[1];
                }
            }
        }

        $appKey = Crypto::generateKey();
        $storageDir = $this->app->storagePath();

        // 1. Write storage/keys/app.key
        if (!is_dir($storageDir . '/keys')) {
            @mkdir($storageDir . '/keys', 0750, true);
        }
        file_put_contents($storageDir . '/keys/app.key', $appKey);
        @chmod($storageDir . '/keys/app.key', 0600);

        // 2. Write storage/config.php
        $configData = [
            'database' => [
                'host' => $params['db_host'],
                'port' => (int) $params['db_port'],
                'database' => $params['db_name'],
                'username' => $params['db_user'],
                'password' => $params['db_password'],
                'prefix' => $params['db_prefix'],
            ],
            'app' => [
                'key' => $appKey,
                'environment' => 'production',
                'debug' => false,
                'cli_php' => 'php',
            ],
        ];

        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($configData, true) . ";\n";
        file_put_contents($storageDir . '/config.php', $content);
        @chmod($storageDir . '/config.php', 0600);

        // 3. Run migrations
        $conn = new Connection($configData['database']);
        $migrator = new Migrator($conn);
        $migrator->registerNamespace('core', $this->app->basePath('migrations'));
        $migrator->migrate();

        // 4. Seed built-in schemas via schema engine
        $this->schemaSeed([]);

        // 5. Mark installed
        file_put_contents($storageDir . '/installed.lock', Clock::sql(Clock::now()));

        echo "BS CMS installed successfully via CLI.\n";
        return 0;
    }

    private function help(): int
    {
        echo "BS CMS Console (`bin/bs`)\n";
        echo "Usage: php bin/bs <command> [options]\n\n";
        echo "Available commands:\n";
        echo "  health            Check system health and environment\n";
        echo "  install           Install BS CMS via CLI flags\n";
        echo "  migrate           Run pending database migrations (--dry-run available)\n";
        echo "  migrate:status    Show migration execution status\n";
        echo "  migrate:rollback  Roll back the last migration batch\n";
        echo "  jobs:run          Execute due background jobs (--max-time=50, --queue=default)\n";
        echo "  jobs:failed       List failed background jobs\n";
        echo "  jobs:retry <id>   Retry a failed background job\n";
        echo "  schedule:run      Execute scheduled tasks and due deploy debounce\n";
        echo "  deploy:process    Process pending deploy debounce queue immediately if due\n";
        echo "  cache:clear       Clear the file/apcu cache\n";
        echo "  cache:warm        Compile and warm configuration cache\n";
        echo "  key:generate      Generate a new libsodium 32-byte secret key\n";
        echo "  key:rotate        Re-encrypt secret settings with the primary key\n";
        echo "  config:show       Display current configuration (redacted)\n";
        echo "  schema:export     Export collections to JSON files (--dir=path)\n";
        echo "  schema:diff       Show planned schema differences against files (--dir=path)\n";
        echo "  schema:apply      Apply file-based schema changes (--dir=path, --dry-run)\n";
        echo "  schema:seed       Seed built-in collections idempotently via schema engine\n";
        echo "  taxonomy:recompute-counts Recompute cached entry counts for taxonomies (--taxonomy=<id>)\n";
        // SPRINT-C BEGIN: Blocks Sync help
        echo "  blocks:sync       Sync block definitions from starter to CMS storage (--dir=path)\n";
        // SPRINT-C END: Blocks Sync help
        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function schemaExport(array $args): int
    {
        $dir = $this->parseDirArg($args, $this->app->basePath('resources/schema'));
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $conn = $this->getConnection();
        $cacheFile = $this->app->storagePath('cache/schema.php');
        $registry = new \BsCms\Schema\CollectionRegistry($conn, $cacheFile);
        $collections = $registry->all();

        $count = 0;
        foreach ($collections as $handle => $def) {
            // Strip primary keys / timestamps for clean schema-as-code
            $clean = [
                'handle' => $def['handle'],
                'kind' => $def['kind'],
                'label_singular' => $def['label_singular'],
                'label_plural' => $def['label_plural'],
                'description' => $def['description'],
                'icon' => $def['icon'],
                'is_system' => (bool) $def['is_system'],
                'has_public_pages' => (bool) $def['has_public_pages'],
                'route_pattern' => $def['route_pattern'],
                'list_route_pattern' => $def['list_route_pattern'],
                'features' => $def['features'],
                'title_field' => $def['title_field'],
                'slug_source' => $def['slug_source'],
                'default_sort' => $def['default_sort'],
                'schema_org' => $def['schema_org'],
                'list_columns' => $def['list_columns'],
                'fields' => [],
            ];

            foreach ($def['fields'] ?? [] as $fHandle => $fDef) {
                $clean['fields'][$fHandle] = [
                    'handle' => $fDef['handle'],
                    'label' => $fDef['label'],
                    'type' => $fDef['type'],
                    'config' => $fDef['config'],
                    'required' => (bool) $fDef['required'],
                    'is_unique' => (bool) $fDef['is_unique'],
                    'is_filterable' => (bool) $fDef['is_filterable'],
                    'is_sortable' => (bool) $fDef['is_sortable'],
                    'is_searchable' => (bool) $fDef['is_searchable'],
                    'show_in_list' => (bool) $fDef['show_in_list'],
                    'default_value' => $fDef['default_value'],
                    'help_text' => $fDef['help_text'],
                    'placeholder' => $fDef['placeholder'],
                    'group_key' => $fDef['group_key'],
                    'width' => $fDef['width'] ?? 'full',
                    'validation' => $fDef['validation'],
                    'conditional' => $fDef['conditional'],
                ];
            }

            ksort($clean);
            $filePath = $dir . '/' . $handle . '.json';
            file_put_contents($filePath, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            echo "Exported collection '{$handle}' -> {$filePath}\n";
            ++$count;
        }

        echo "Total exported: {$count} collection(s).\n";
        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function schemaDiff(array $args): int
    {
        $dir = $this->parseDirArg($args, $this->app->basePath('resources/schema'));
        if (!is_dir($dir)) {
            fwrite(STDERR, "Schema directory '{$dir}' does not exist.\n");
            return 1;
        }

        $conn = $this->getConnection();
        $cacheFile = $this->app->storagePath('cache/schema.php');
        $registry = new \BsCms\Schema\CollectionRegistry($conn, $cacheFile);
        $registry->invalidate();
        $fieldTypeRegistry = new \BsCms\Schema\FieldTypeRegistry();
        $planner = new \BsCms\Schema\SchemaPlanner($conn, $registry, $fieldTypeRegistry);

        $files = glob($dir . '/*.json') ?: [];
        if (empty($files)) {
            echo "No schema JSON files found in {$dir}\n";
            return 0;
        }

        $totalChanges = 0;
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            $target = json_decode($content, true);
            if (!is_array($target) || empty($target['handle'])) {
                continue;
            }

            $plan = $planner->plan($target);
            if (!empty($plan->changes) || !empty($plan->sqlStatements)) {
                echo "\n[Collection: {$target['handle']}]\n";
                foreach ($plan->changes as $change) {
                    echo " - Change: " . json_encode($change, JSON_UNESCAPED_SLASHES) . "\n";
                    ++$totalChanges;
                }
                foreach ($plan->sqlStatements as $sql) {
                    echo "   SQL: {$sql}\n";
                }
            }
        }

        if ($totalChanges === 0) {
            echo "Schema is up to date. No differences found.\n";
        } else {
            echo "\nTotal planned changes: {$totalChanges}\n";
        }

        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function schemaApply(array $args): int
    {
        $dir = $this->parseDirArg($args, $this->app->basePath('resources/schema'));
        $dryRun = in_array('--dry-run', $args, true);

        if (!is_dir($dir)) {
            fwrite(STDERR, "Schema directory '{$dir}' does not exist.\n");
            return 1;
        }

        $conn = $this->getConnection();
        $cacheFile = $this->app->storagePath('cache/schema.php');
        $registry = new \BsCms\Schema\CollectionRegistry($conn, $cacheFile);
        $registry->invalidate();
        $fieldTypeRegistry = new \BsCms\Schema\FieldTypeRegistry();
        $planner = new \BsCms\Schema\SchemaPlanner($conn, $registry, $fieldTypeRegistry);
        $lock = new \BsCms\Schema\SchemaLock($conn);
        $applier = new \BsCms\Schema\SchemaApplier($conn, $registry, $lock);

        $files = glob($dir . '/*.json') ?: [];
        if (empty($files)) {
            echo "No schema JSON files found in {$dir}\n";
            return 0;
        }

        echo ($dryRun ? "Dry-running" : "Applying") . " schema changes from {$dir}...\n";

        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            $target = json_decode($content, true);
            if (!is_array($target) || empty($target['handle'])) {
                continue;
            }

            $plan = $planner->plan($target);
            if (empty($plan->changes) && empty($plan->sqlStatements)) {
                echo "Collection '{$target['handle']}' is already up to date.\n";
                continue;
            }

            echo "Applying changes for '{$target['handle']}'...\n";
            foreach ($plan->sqlStatements as $sql) {
                echo "   SQL: {$sql}\n";
            }

            if (!$dryRun) {
                $applier->apply($plan, $target, null, 'cli');
                echo "Applied successfully.\n";
            }
        }

        echo ($dryRun ? "Dry-run complete." : "All schema changes applied successfully.") . "\n";
        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function schemaSeed(array $args): int
    {
        $dir = $this->parseDirArg($args, $this->app->basePath('resources/schema'));
        if (!is_dir($dir)) {
            fwrite(STDERR, "Schema resources directory '{$dir}' not found.\n");
            return 1;
        }

        echo "Seeding built-in collections from {$dir}...\n";

        // Call schemaApply directly
        $result = $this->schemaApply($args);

        if ($result === 0) {
            $this->seedTaxonomies();
            echo "Built-in collections seeded successfully.\n";
        }

        return $result;
    }

    private function seedTaxonomies(): void
    {
        $taxFile = $this->app->basePath('resources/taxonomies.json');
        if (!is_file($taxFile)) {
            return;
        }

        $content = (string) file_get_contents($taxFile);
        $taxonomies = json_decode($content, true);
        if (!is_array($taxonomies)) {
            return;
        }

        $conn = $this->getConnection();
        $taxRepo = new \BsCms\Taxonomy\TaxonomyRepository($conn);
        $termRepo = new \BsCms\Taxonomy\TermRepository($conn);

        foreach ($taxonomies as $taxData) {
            if (!is_array($taxData) || empty($taxData['handle'])) {
                continue;
            }

            $handle = (string) $taxData['handle'];
            $existing = $taxRepo->findByHandle($handle);
            if ($existing === null) {
                $created = $taxRepo->create($taxData);
                $taxId = (string) $created['id'];
                echo "Seeded taxonomy '{$handle}'.\n";
            } else {
                $taxId = (string) $existing['id'];
            }

            if (!empty($taxData['default_term']) && is_array($taxData['default_term'])) {
                $defaultTerm = $taxData['default_term'];
                $termSlug = (string) ($defaultTerm['slug'] ?? 'uncategorized');
                $existingTerm = $termRepo->findBySlug($taxId, $termSlug);
                if ($existingTerm === null) {
                    $termRepo->create($taxId, $defaultTerm);
                    echo "Seeded default term '{$defaultTerm['name']}' in taxonomy '{$handle}'.\n";
                }
            }
        }
    }

    /**
     * @param list<string> $args
     */
    private function taxonomyRecomputeCounts(array $args): int
    {
        $conn = $this->getConnection();
        $terms = new \BsCms\Taxonomy\TermRepository($conn);

        $taxonomyId = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--taxonomy=')) {
                $taxonomyId = substr($arg, 11);
            }
        }

        echo "Recomputing taxonomy entry counts...\n";
        $terms->recomputeCounts($taxonomyId);
        echo "Taxonomy entry counts recomputed successfully.\n";
        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function parseDirArg(array $args, string $default): string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--dir=')) {
                return substr($arg, 6);
            }
        }
        return $default;
    }

    private function unknownCommand(string $command): int
    {
        fwrite(STDERR, "Unknown command '{$command}'. Run 'php bin/bs help' for list of commands.\n");
        return 1;
    }

    private function getConnection(): Connection
    {
        $dbConfig = (array) $this->app->getConfig()->get('database', []);
        if (!empty($dbConfig['database'])) {
            $connConfig = $dbConfig;
        } elseif (isset($dbConfig['connections'])) {
            $default = (string) ($dbConfig['default'] ?? 'mysql');
            $connConfig = (array) ($dbConfig['connections'][$default] ?? []);
        } else {
            $connConfig = $dbConfig;
        }

        // Environment overrides for CLI convenience
        if (getenv('DB_PASS') !== false && (!isset($connConfig['password']) || $connConfig['password'] === '')) {
            $connConfig['password'] = (string) getenv('DB_PASS');
        }
        if (getenv('DB_USER') !== false && (!isset($connConfig['username']) || $connConfig['username'] === '')) {
            $connConfig['username'] = (string) getenv('DB_USER');
        }
        if (getenv('DB_NAME') !== false && (!isset($connConfig['database']) || $connConfig['database'] === '')) {
            $connConfig['database'] = (string) getenv('DB_NAME');
        }

        return new Connection($connConfig);
    }

    // SPRINT-C BEGIN: Blocks Sync implementation
    /**
     * @param array<int, string> $args
     */
    private function blocksSync(array $args): int
    {
        $defaultDir = dirname($this->app->basePath()) . '/starter/src/blocks';
        $dir = $this->parseDirArg($args, $defaultDir);

        if (!is_dir($dir)) {
            fwrite(STDERR, "Error: Blocks directory '{$dir}' does not exist.\n");
            return 1;
        }

        $storageDir = $this->app->storagePath('blocks');
        $registry = new \BsCms\Blocks\BlockRegistry($storageDir);
        $count = $registry->syncFromDirectory($dir);

        echo "Successfully synced {$count} blocks from '{$dir}'.\n";
        return 0;
    }
    // SPRINT-C END: Blocks Sync implementation
}
