<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Events;

use BsCms\Events\EventBus;
use BsCms\Events\GenericEvent;
use PHPUnit\Framework\TestCase;

final class EventBusTest extends TestCase
{
    public function testSpecificAndWildcardListeners(): void
    {
        $bus = new EventBus();
        $receivedSpecific = [];
        $receivedGlobal = [];

        $bus->subscribe('entry.created', function (GenericEvent $event) use (&$receivedSpecific) {
            $receivedSpecific[] = $event->payload();
        });

        $bus->subscribeAll(function (GenericEvent $event) use (&$receivedGlobal) {
            $receivedGlobal[] = $event->name();
        });

        $bus->dispatch(new GenericEvent('entry.created', ['id' => '123']));
        $bus->dispatch(new GenericEvent('entry.deleted', ['id' => '456']));

        $this->assertCount(1, $receivedSpecific);
        $this->assertSame('123', $receivedSpecific[0]['id']);

        $this->assertCount(2, $receivedGlobal);
        $this->assertSame(['entry.created', 'entry.deleted'], $receivedGlobal);
    }

    public function testListenerExceptionDoesNotHaltOtherListeners(): void
    {
        $bus = new EventBus();
        $called = false;

        $bus->subscribe('test.event', function () {
            throw new \RuntimeException('Listener failure');
        });

        $bus->subscribe('test.event', function () use (&$called) {
            $called = true;
        });

        $bus->dispatch(new GenericEvent('test.event', []));

        $this->assertTrue($called);
    }
}
