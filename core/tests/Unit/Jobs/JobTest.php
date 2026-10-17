<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Jobs;

use BsCms\Database\Connection;
use BsCms\Database\QueryBuilder;
use BsCms\Jobs\Job;
use BsCms\Jobs\JobQueue;
use BsCms\Jobs\Scheduler;
use BsCms\Jobs\Worker;
use BsCms\Kernel\Container;
use BsCms\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

class TestSampleJob implements Job
{
    public static bool $executed = false;

    public function handle(array $payload): void
    {
        self::$executed = true;
    }
}

final class JobTest extends TestCase
{
    public function testJobQueuePushesJob(): void
    {
        $mockConn = $this->createMock(Connection::class);
        $mockQb = $this->createMock(QueryBuilder::class);

        $mockConn->method('table')->with('jobs')->willReturn($mockQb);
        $mockQb->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (array $data) {
                return $data['type'] === TestSampleJob::class
                    && $data['status'] === 'queued'
                    && $data['queue'] === 'default';
            }))
            ->willReturn(true);

        $queue = new JobQueue($mockConn);
        $success = $queue->push(TestSampleJob::class, ['foo' => 'bar']);
        $this->assertTrue($success);
    }

    public function testSchedulerRunsDueTasks(): void
    {
        $mockSettings = $this->createMock(SettingsRepository::class);
        $mockSettings->method('get')->willReturn(0); // Never run before
        $mockSettings->expects($this->once())->method('set');

        $scheduler = new Scheduler($mockSettings);

        $ran = false;
        $scheduler->register('prune_logs', 3600, function () use (&$ran) {
            $ran = true;
        });

        $due = $scheduler->runDue();
        $this->assertTrue($ran);
        $this->assertContains('prune_logs', $due);
    }
}
