<?php

declare(strict_types=1);

namespace BsCms\Events;

use BsCms\Log\FileLogger;
use Throwable;

final class EventBus
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /** @var list<callable> */
    private array $globalListeners = [];

    public function __construct(
        private readonly ?FileLogger $logger = null
    ) {
    }

    /**
     * Subscribe a listener callback to a specific event name.
     */
    public function subscribe(string $eventName, callable $listener): void
    {
        if ($eventName === '*') {
            $this->globalListeners[] = $listener;
            return;
        }

        $this->listeners[$eventName][] = $listener;
    }

    /**
     * Subscribe a listener callback to all dispatched events.
     */
    public function subscribeAll(callable $listener): void
    {
        $this->globalListeners[] = $listener;
    }

    /**
     * Dispatch an event to all registered listeners.
     */
    public function dispatch(Event $event): void
    {
        $name = $event->name();

        // 1. Specific listeners
        if (isset($this->listeners[$name])) {
            foreach ($this->listeners[$name] as $listener) {
                try {
                    $listener($event);
                } catch (Throwable $e) {
                    $this->logger?->error("Event listener error for '{$name}': " . $e->getMessage(), [
                        'exception' => $e,
                        'event' => $name,
                    ]);
                }
            }
        }

        // 2. Wildcard / global listeners
        foreach ($this->globalListeners as $listener) {
            try {
                $listener($event);
            } catch (Throwable $e) {
                $this->logger?->error("Global event listener error for '{$name}': " . $e->getMessage(), [
                    'exception' => $e,
                    'event' => $name,
                ]);
            }
        }
    }
}
