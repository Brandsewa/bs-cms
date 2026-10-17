<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit;

use BsCms\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    public function testKernelVersion(): void
    {
        $this->assertSame('0.1.0-dev', Kernel::VERSION);
    }
}
