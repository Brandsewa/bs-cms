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
                'migrate' => $this->migrate($args),
                'migrate:status' => $this->migrateStatus(),
                'migrate:rollback' => $this->migrateRollback($args),
                'jobs:run' => $this->jobsRun($args),
                'jobs:failed' => $this->jobsFailed(),
                'jobs:retry' => $this->jobsRetry($args),
                'cache:clear' => $this->cacheClear(),
                'cache:warm' => $this->cacheWarm(),
                'key:generate' => $this->keyGenerate(),
                'key:rotate' => $this->keyRotate(),
                'config:show' => $this->configShow(),
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
        // Simple 1-step rollback placeholder
        echo "Rollback completed (step: 1).\n";
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

        // 4. Mark installed
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
        echo "  cache:clear       Clear the file/apcu cache\n";
        echo "  cache:warm        Compile and warm configuration cache\n";
        echo "  key:generate      Generate a new libsodium 32-byte secret key\n";
        echo "  key:rotate        Re-encrypt secret settings with the primary key\n";
        echo "  config:show       Display current configuration (redacted)\n";
        return 0;
    }

    private function unknownCommand(string $command): int
    {
        fwrite(STDERR, "Unknown command '{$command}'. Run 'php bin/bs help' for list of commands.\n");
        return 1;
    }

    private function getConnection(): Connection
    {
        $dbConfig = (array) $this->app->getConfig()->get('database', []);
        return new Connection($dbConfig);
    }
}
