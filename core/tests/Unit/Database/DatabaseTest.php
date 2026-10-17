<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Database;

use BsCms\Database\Connection;
use BsCms\Database\QueryBuilder;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    private function createMockConnection(): Connection
    {
        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('getAttribute')->with(PDO::ATTR_SERVER_VERSION)->willReturn('8.0.35-MySQL');

        return new Connection([
            'pdo' => $mockPdo,
            'prefix' => 'bs_',
        ]);
    }

    public function testQueryBuilderSelectCompilation(): void
    {
        $connection = $this->createMockConnection();
        $qb = (new QueryBuilder($connection))
            ->from('users')
            ->select(['id', 'email', 'status'])
            ->where('status', '=', 'active')
            ->whereIn('role_id', ['01JABC', '01JDEF'])
            ->orderBy('created_at', 'DESC')
            ->limit(10)
            ->offset(20);

        $sqlData = $qb->toSql();

        $expectedSql = 'SELECT `id`, `email`, `status` FROM `bs_users` WHERE `status` = ? AND `role_id` IN (?, ?) ORDER BY `created_at` DESC LIMIT 10 OFFSET 20';
        $this->assertSame($expectedSql, $sqlData['sql']);
        $this->assertSame(['active', '01JABC', '01JDEF'], $sqlData['params']);
    }

    public function testKeysetCursorPredicateCompilation(): void
    {
        $connection = $this->createMockConnection();
        $qb = (new QueryBuilder($connection))
            ->from('c_posts')
            ->whereKeyset('published_at', '2026-01-01 12:00:00.000', 'id', '01JXYZ', 'DESC');

        $sqlData = $qb->toSql();

        $expectedSql = 'SELECT * FROM `bs_c_posts` WHERE (`published_at` < ? OR (`published_at` = ? AND `id` < ?))';
        $this->assertSame($expectedSql, $sqlData['sql']);
        $this->assertSame(['2026-01-01 12:00:00.000', '2026-01-01 12:00:00.000', '01JXYZ'], $sqlData['params']);
    }

    public function testInvalidIdentifierThrowsException(): void
    {
        $connection = $this->createMockConnection();
        $qb = new QueryBuilder($connection);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SQL identifier');
        $qb->from('users; DROP TABLE users; --');
    }

    public function testInvalidOperatorThrowsException(): void
    {
        $connection = $this->createMockConnection();
        $qb = (new QueryBuilder($connection))->from('users');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SQL operator');
        $qb->where('status', 'INVALID_OP', 'test');
    }
}
