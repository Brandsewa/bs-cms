<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Media;

use BsCms\Media\ChunkUploadManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ChunkUploadManagerTest extends TestCase
{
    private string $tmpDir;
    private ChunkUploadManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/bs_chunk_test_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0755, true);
        $this->manager = new ChunkUploadManager($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testInitiateSessionAndValidateCaps(): void
    {
        // 1. Exceeds total size cap
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Total file size must be between');
        $this->manager->initiate('huge.iso', 20, ChunkUploadManager::MAX_TOTAL_SIZE + 1024);
    }

    public function testChunkLifecycleResumeAndAssemble(): void
    {
        $chunk1Data = "Chunk 1 Content - ";
        $chunk2Data = "Chunk 2 Content - ";
        $chunk3Data = "Chunk 3 End.";
        $expectedTotal = $chunk1Data . $chunk2Data . $chunk3Data;
        $totalSize = strlen($expectedTotal);

        // 1. Initiate
        $init = $this->manager->initiate('test_document.txt', 3, $totalSize);
        $this->assertNotEmpty($init['upload_id']);
        $uploadId = $init['upload_id'];
        $this->assertSame(3, $init['total_chunks']);
        $this->assertSame($totalSize, $init['total_size']);

        // 2. Upload chunk 0
        $tmp0 = $this->tmpDir . '/c0.part';
        file_put_contents($tmp0, $chunk1Data);
        $res0 = $this->manager->saveChunk($uploadId, 0, $tmp0);
        $this->assertSame([0], $res0['uploaded_chunks']);
        $this->assertFalse($res0['is_complete']);

        // 3. Upload chunk 2 (out of order / resume scenario)
        $tmp2 = $this->tmpDir . '/c2.part';
        file_put_contents($tmp2, $chunk3Data);
        $res2 = $this->manager->saveChunk($uploadId, 2, $tmp2);
        $this->assertSame([0, 2], $res2['uploaded_chunks']);
        $this->assertFalse($res2['is_complete']);

        // 4. Status check
        $status = $this->manager->status($uploadId);
        $this->assertSame([0, 2], $status['uploaded_chunks']);
        $this->assertFalse($status['is_complete']);

        // 5. Upload missing chunk 1
        $tmp1 = $this->tmpDir . '/c1.part';
        file_put_contents($tmp1, $chunk2Data);
        $res1 = $this->manager->saveChunk($uploadId, 1, $tmp1);
        $this->assertSame([0, 1, 2], $res1['uploaded_chunks']);
        $this->assertTrue($res1['is_complete']);

        // 6. Assemble
        $assembled = $this->manager->assemble($uploadId);
        $this->assertFileExists($assembled['assembled_path']);
        $this->assertSame($totalSize, $assembled['total_size']);
        $this->assertSame('test_document.txt', $assembled['original_name']);

        $finalContent = file_get_contents($assembled['assembled_path']);
        $this->assertSame($expectedTotal, $finalContent);

        // 7. Verify chunk directory is cleaned up
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/chunks/' . $uploadId);

        @unlink($assembled['assembled_path']);
    }

    public function testCleanupExpiredSessions(): void
    {
        $init = $this->manager->initiate('old.bin', 1, 100);
        $uploadId = $init['upload_id'];
        $metaPath = $this->tmpDir . '/chunks/' . $uploadId . '/meta.json';

        // Touch meta file to 25 hours ago
        touch($metaPath, time() - (25 * 3600));

        $cleaned = $this->manager->cleanupExpired(24);
        $this->assertSame(1, $cleaned);
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/chunks/' . $uploadId);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            if (is_dir($p)) {
                $this->deleteDirectory($p);
            } else {
                @unlink($p);
            }
        }

        @rmdir($dir);
    }
}
