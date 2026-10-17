<?php

declare(strict_types=1);

namespace BsCms\Jobs;

use BsCms\Settings\SettingsRepository;
use BsCms\Support\Clock;
use Closure;

/**
 * Scheduler: coordinates code-defined recurring background tasks
 * (`prune`, `publish_scheduled`, `integrity_check`) with last-run timestamps in settings.
 */
final class Scheduler
{
    private SettingsRepository $settings;
    /** @var array<string, array{interval_seconds: int, callback: Closure}> */
    private array $tasks = [];

    public function __construct(SettingsRepository $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Register a recurring task.
     */
    public function register(string $name, int $intervalSeconds, Closure $callback): void
    {
        $this->tasks[$name] = [
            'interval_seconds' => max(60, $intervalSeconds),
            'callback' => $callback,
        ];
    }

    /**
     * Run all due registered tasks.
     *
     * @return list<string> List of executed task names
     */
    public function runDue(): array
    {
        $now = Clock::timestamp();
        $executed = [];

        foreach ($this->tasks as $name => $task) {
            $settingKey = "scheduler_last_run_{$name}";
            $lastRun = (int) $this->settings->get($settingKey, 0);

            if (($now - $lastRun) >= $task['interval_seconds']) {
                ($task['callback'])();
                $this->settings->set($settingKey, $now);
                $executed[] = $name;
            }
        }

        return $executed;
    }
}
