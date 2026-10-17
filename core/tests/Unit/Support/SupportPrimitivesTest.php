<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Support;

use BsCms\Support\Arr;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use BsCms\Support\Str;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SupportPrimitivesTest extends TestCase
{
    public function testClockProvidesUtcWithMillisecondPrecision(): void
    {
        $now = Clock::now();
        $this->assertSame('UTC', $now->getTimezone()->getName());

        $sql = Clock::sql($now);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', $sql);

        $iso = Clock::iso8601($now);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $iso);

        $converted = Clock::from(new DateTimeImmutable('2026-10-17 12:00:00', new DateTimeZone('America/New_York')));
        $this->assertSame('UTC', $converted->getTimezone()->getName());
        $this->assertSame('16:00:00', $converted->format('H:i:s'));
    }

    public function testJsonEncodesAndDecodesStrictly(): void
    {
        $data = ['site' => 'BS CMS', 'fast' => true, 'count' => 42];
        $encoded = Json::encode($data);
        $this->assertSame('{"site":"BS CMS","fast":true,"count":42}', $encoded);

        $decoded = Json::decode($encoded);
        $this->assertSame($data, $decoded);

        $this->assertTrue(Json::isValid($encoded));
        $this->assertFalse(Json::isValid('{invalid json'));
    }

    public function testStrSlugAndIdentifierValidation(): void
    {
        $this->assertSame('hello-world-2026', Str::slug('Hello World! 2026'));
        $this->assertSame('simple-post', Str::slug('simple-post'));

        $this->assertTrue(Str::isAscii('ASCII text only 123'));
        $this->assertFalse(Str::isAscii('Tést wïth ümlauts'));

        $this->assertTrue(Str::isValidIdentifier('posts'));
        $this->assertTrue(Str::isValidIdentifier('blog_categories'));
        $this->assertFalse(Str::isValidIdentifier('123starts_with_num'));
        $this->assertFalse(Str::isValidIdentifier('has-dashes'));
        $this->assertFalse(Str::isValidIdentifier('very_long_identifier_exceeding_the_forty_character_limit_for_identifiers'));

        $this->assertTrue(Str::isValidDbIdentifier('bs_c_posts'));
        $this->assertTrue(Str::isValidDbIdentifier('published_at'));
        $this->assertFalse(Str::isValidDbIdentifier('table; drop table users;'));
    }

    public function testArrDotNotationAndRecursiveMerge(): void
    {
        $data = [
            'database' => [
                'connection' => [
                    'host' => 'localhost',
                    'port' => 3306,
                ],
            ],
            'debug' => false,
        ];

        $this->assertSame('localhost', Arr::get($data, 'database.connection.host'));
        $this->assertSame(3306, Arr::get($data, 'database.connection.port'));
        $this->assertNull(Arr::get($data, 'database.connection.password'));
        $this->assertSame('default_val', Arr::get($data, 'database.connection.password', 'default_val'));
        $this->assertFalse(Arr::get($data, 'debug'));

        $this->assertTrue(Arr::has($data, 'database.connection.host'));
        $this->assertFalse(Arr::has($data, 'database.connection.user'));

        Arr::set($data, 'database.connection.user', 'root');
        $this->assertSame('root', Arr::get($data, 'database.connection.user'));

        $override = [
            'database' => [
                'connection' => [
                    'host' => '127.0.0.1',
                ],
            ],
            'new_key' => 'new_value',
        ];

        $merged = Arr::mergeRecursive($data, $override);
        $this->assertSame('127.0.0.1', Arr::get($merged, 'database.connection.host'));
        $this->assertSame(3306, Arr::get($merged, 'database.connection.port'));
        $this->assertSame('root', Arr::get($merged, 'database.connection.user'));
        $this->assertSame('new_value', Arr::get($merged, 'new_key'));
    }
}
