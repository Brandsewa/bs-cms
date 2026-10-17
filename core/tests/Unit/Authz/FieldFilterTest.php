<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Authz;

use BsCms\Authz\FieldFilter;
use PHPUnit\Framework\TestCase;

final class FieldFilterTest extends TestCase
{
    private FieldFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new FieldFilter();
    }

    public function testFilterReturnsUnchangedWhenNoRules(): void
    {
        $data = ['title' => 'Hello', 'body' => 'World', 'salary' => 1000];
        $this->assertSame($data, $this->filter->filter($data, null));
    }

    public function testAllowListKeepsOnlySpecifiedFields(): void
    {
        $data = ['title' => 'Hello', 'body' => 'World', 'secret' => '123'];
        $rule = ['allow' => ['title', 'body']];

        $result = $this->filter->filter($data, $rule);
        $this->assertSame(['title' => 'Hello', 'body' => 'World'], $result);
    }

    public function testDenyListRemovesSpecifiedFields(): void
    {
        $data = ['title' => 'Hello', 'secret' => '123'];
        $rule = ['deny' => ['secret']];

        $result = $this->filter->filter($data, $rule);
        $this->assertSame(['title' => 'Hello'], $result);
    }

    public function testOperationSpecificRules(): void
    {
        $data = ['title' => 'Hello', 'password' => 'secret', 'draft' => true];
        $rule = [
            'read' => ['deny' => ['password']],
            'write' => ['allow' => ['title', 'draft']],
        ];

        $readResult = $this->filter->filter($data, $rule, 'read');
        $this->assertArrayNotHasKey('password', $readResult);
        $this->assertArrayHasKey('title', $readResult);
        $this->assertArrayHasKey('draft', $readResult);

        $writeResult = $this->filter->filter($data, $rule, 'write');
        $this->assertSame(['title' => 'Hello', 'draft' => true], $writeResult);
    }
}
