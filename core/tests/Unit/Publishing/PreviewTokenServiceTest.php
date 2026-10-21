<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Publishing;

use BsCms\Database\Connection;
use BsCms\Publishing\PreviewTokenService;
use BsCms\Support\Clock;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PreviewTokenServiceTest extends TestCase
{
    public function testGenerateTokenEnforcesActiveEntryCap(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('prefixTable')->with('preview_tokens')->willReturn('bs_preview_tokens');

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn(['cnt' => PreviewTokenService::MAX_ACTIVE_PER_ENTRY]);

        $connection->expects($this->once())
            ->method('execute')
            ->with($this->stringContains('SELECT COUNT(*) as cnt FROM `bs_preview_tokens` WHERE `entry_id` = ?'))
            ->willReturn($stmt);

        $service = new PreviewTokenService($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Maximum active preview tokens per entry');

        $service->generateToken('col_123', 'entry_123', 'usr_123');
    }

    public function testGenerateTokenEnforcesActiveUserCap(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('prefixTable')->with('preview_tokens')->willReturn('bs_preview_tokens');

        $stmtEntry = $this->createMock(PDOStatement::class);
        $stmtEntry->method('fetch')->willReturn(['cnt' => 2]);

        $stmtUser = $this->createMock(PDOStatement::class);
        $stmtUser->method('fetch')->willReturn(['cnt' => PreviewTokenService::MAX_ACTIVE_PER_USER]);

        $connection->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $sql) use ($stmtEntry, $stmtUser) {
                if (str_contains($sql, 'WHERE `entry_id` = ?')) {
                    return $stmtEntry;
                }
                if (str_contains($sql, 'WHERE `created_by` = ?')) {
                    return $stmtUser;
                }
                throw new \InvalidArgumentException("Unexpected query: {$sql}");
            });

        $service = new PreviewTokenService($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Maximum active preview tokens per user');

        $service->generateToken('col_123', 'entry_123', 'usr_123');
    }

    public function testGenerateTokenSucceedsWithinCaps(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('prefixTable')->with('preview_tokens')->willReturn('bs_preview_tokens');

        $stmtCount = $this->createMock(PDOStatement::class);
        $stmtCount->method('fetch')->willReturn(['cnt' => 1]);

        $stmtInsert = $this->createMock(PDOStatement::class);

        $connection->expects($this->exactly(3))
            ->method('execute')
            ->willReturnCallback(function (string $sql) use ($stmtCount, $stmtInsert) {
                if (str_contains($sql, 'SELECT COUNT(*)')) {
                    return $stmtCount;
                }
                if (str_contains($sql, 'INSERT INTO `bs_preview_tokens`')) {
                    return $stmtInsert;
                }
                throw new \InvalidArgumentException("Unexpected query: {$sql}");
            });

        $service = new PreviewTokenService($connection);
        $res = $service->generateToken('col_123', 'entry_123', 'usr_123');

        $this->assertStringStartsWith('bsprev_', $res['token']);
        $this->assertNotEmpty($res['expires_at']);
    }

    public function testPurgeExpiredExecutesDelete(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('prefixTable')->with('preview_tokens')->willReturn('bs_preview_tokens');

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(5);

        $connection->expects($this->once())
            ->method('execute')
            ->with($this->stringContains('DELETE FROM `bs_preview_tokens` WHERE `expires_at` <= ?'))
            ->willReturn($stmt);

        $service = new PreviewTokenService($connection);
        $purged = $service->purgeExpired();

        $this->assertSame(5, $purged);
    }
}
