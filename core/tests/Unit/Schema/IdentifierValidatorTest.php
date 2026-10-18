<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Schema;

use BsCms\Schema\IdentifierValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IdentifierValidatorTest extends TestCase
{
    public function testValidHandles(): void
    {
        $this->assertTrue(IdentifierValidator::isValidHandle('posts'));
        $this->assertTrue(IdentifierValidator::isValidHandle('blog_posts'));
        $this->assertTrue(IdentifierValidator::isValidHandle('campaign_2026_spring'));
    }

    public function testInvalidHandles(): void
    {
        $this->assertFalse(IdentifierValidator::isValidHandle('123posts'));
        $this->assertFalse(IdentifierValidator::isValidHandle('posts-tags'));
        $this->assertFalse(IdentifierValidator::isValidHandle('Posts'));
        $this->assertFalse(IdentifierValidator::isValidHandle(''));
    }

    public function testReservedCollectionHandlesThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Collection handle 'users' is reserved");
        IdentifierValidator::validateCollectionHandle('users');
    }

    public function testSystemColumnHandlesThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Field handle 'title' conflicts with a system column");
        IdentifierValidator::validateFieldHandle('title');
    }

    public function testEscapeIdentifierRejectsSqlInjection(): void
    {
        $this->assertSame('`c_posts`', IdentifierValidator::escapeIdentifier('c_posts'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SQL injection danger');
        IdentifierValidator::escapeIdentifier('c_posts; DROP TABLE users;--');
    }
}
