<?php

declare(strict_types=1);

namespace BsCms\Jobs;

/**
 * Job interface that all background job handlers must implement.
 */
interface Job
{
    /**
     * Handle the job with decoded payload array.
     *
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void;
}
