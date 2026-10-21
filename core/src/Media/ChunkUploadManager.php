<?php

declare(strict_types=1);

namespace BsCms\Media;

use BsCms\Security\Ulid;
use InvalidArgumentException;
use RuntimeException;

final class ChunkUploadManager
{
    public const MAX_TOTAL_SIZE = 104857600; // 100 MB
    public const MAX_CHUNK_SIZE = 10485760;  // 10 MB
    public const MAX_CHUNKS = 1000;

    public function __construct(
        private readonly string $tmpDir
    ) {
    }

    /**
     * Initiate a new chunked upload session.
     *
     * @return array{upload_id: string, total_chunks: int, total_size: int, chunk_size: int}
     */
    public function initiate(string $originalName, int $totalChunks, int $totalSize, ?string $folderId = null): array
    {
        $originalName = trim(basename($originalName));
        if ($originalName === '') {
            throw new InvalidArgumentException("Original filename cannot be empty.");
        }

        if ($totalChunks < 1 || $totalChunks > self::MAX_CHUNKS) {
            throw new InvalidArgumentException("Total chunks must be between 1 and " . self::MAX_CHUNKS . ".");
        }

        if ($totalSize <= 0 || $totalSize > self::MAX_TOTAL_SIZE) {
            throw new InvalidArgumentException("Total file size must be between 1 byte and " . self::MAX_TOTAL_SIZE . " bytes.");
        }

        $uploadId = Ulid::generate();
        $chunkDir = $this->getUploadDir($uploadId);
        if (!is_dir($chunkDir)) {
            mkdir($chunkDir, 0755, true);
        }

        $meta = [
            'upload_id' => $uploadId,
            'session_id' => $uploadId,
            'original_name' => $originalName,
            'total_chunks' => $totalChunks,
            'total_size' => $totalSize,
            'folder_id' => $folderId,
            'created_at' => time(),
        ];

        file_put_contents($chunkDir . '/meta.json', json_encode($meta, JSON_THROW_ON_ERROR));

        return [
            'upload_id' => $uploadId,
            'session_id' => $uploadId,
            'total_chunks' => $totalChunks,
            'total_size' => $totalSize,
            'chunk_size' => self::MAX_CHUNK_SIZE,
        ];
    }

    /**
     * Save an uploaded chunk.
     *
     * @return array{upload_id: string, chunk_index: int, uploaded_chunks: list<int>, is_complete: bool}
     */
    public function saveChunk(string $uploadId, int $chunkIndex, string $tempFilePath): array
    {
        $meta = $this->getMeta($uploadId);
        $totalChunks = (int) $meta['total_chunks'];

        if ($chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            throw new InvalidArgumentException("Invalid chunk index: {$chunkIndex}. Must be 0 to " . ($totalChunks - 1) . ".");
        }

        $chunkSize = filesize($tempFilePath);
        if ($chunkSize === false || $chunkSize > self::MAX_CHUNK_SIZE) {
            throw new InvalidArgumentException("Chunk exceeds maximum chunk size of " . self::MAX_CHUNK_SIZE . " bytes.");
        }

        $chunkDir = $this->getUploadDir($uploadId);
        $chunkFile = sprintf('%s/chunk_%05d.part', $chunkDir, $chunkIndex);

        if (!move_uploaded_file($tempFilePath, $chunkFile) && !rename($tempFilePath, $chunkFile)) {
            if (!copy($tempFilePath, $chunkFile)) {
                throw new RuntimeException("Failed to save chunk {$chunkIndex}.");
            }
            @unlink($tempFilePath);
        }

        $status = $this->status($uploadId);

        return [
            'upload_id' => $uploadId,
            'session_id' => $uploadId,
            'chunk_index' => $chunkIndex,
            'uploaded_chunks' => $status['uploaded_chunks'],
            'is_complete' => $status['is_complete'],
        ];
    }

    /**
     * Check status of a chunked upload.
     *
     * @return array{upload_id: string, total_chunks: int, total_size: int, uploaded_chunks: list<int>, is_complete: bool}
     */
    public function status(string $uploadId): array
    {
        $meta = $this->getMeta($uploadId);
        $chunkDir = $this->getUploadDir($uploadId);
        $totalChunks = (int) $meta['total_chunks'];

        $uploaded = [];
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = sprintf('%s/chunk_%05d.part', $chunkDir, $i);
            if (file_exists($chunkFile)) {
                $uploaded[] = $i;
            }
        }

        return [
            'upload_id' => $uploadId,
            'session_id' => $uploadId,
            'total_chunks' => $totalChunks,
            'total_size' => (int) $meta['total_size'],
            'uploaded_chunks' => $uploaded,
            'is_complete' => count($uploaded) === $totalChunks,
        ];
    }

    /**
     * Assemble chunks into a single file.
     *
     * @return array{assembled_path: string, original_name: string, total_size: int, folder_id: ?string}
     */
    public function assemble(string $uploadId): array
    {
        $meta = $this->getMeta($uploadId);
        $totalChunks = (int) $meta['total_chunks'];
        $expectedSize = (int) $meta['total_size'];
        $chunkDir = $this->getUploadDir($uploadId);

        $assembledPath = rtrim($this->tmpDir, '/\\') . "/assembled_{$uploadId}.tmp";
        $out = fopen($assembledPath, 'wb');
        if ($out === false) {
            throw new RuntimeException("Failed to open assembled file for writing: {$assembledPath}");
        }

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkFile = sprintf('%s/chunk_%05d.part', $chunkDir, $i);
                if (!file_exists($chunkFile)) {
                    throw new RuntimeException("Missing chunk {$i} for upload {$uploadId}.");
                }

                $in = fopen($chunkFile, 'rb');
                if ($in === false) {
                    throw new RuntimeException("Failed to read chunk {$i}.");
                }

                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        $actualSize = filesize($assembledPath);
        if ($actualSize !== $expectedSize) {
            @unlink($assembledPath);
            throw new RuntimeException("Assembled file size ({$actualSize}) does not match expected total size ({$expectedSize}).");
        }

        // Clean up chunk files and directory
        $this->deleteDirectory($chunkDir);

        return [
            'assembled_path' => $assembledPath,
            'original_name' => (string) $meta['original_name'],
            'total_size' => $expectedSize,
            'folder_id' => !empty($meta['folder_id']) ? (string) $meta['folder_id'] : null,
        ];
    }

    /**
     * Clean up expired chunk upload sessions older than specified hours.
     */
    public function cleanupExpired(int $maxAgeHours = 24): int
    {
        $chunksBase = rtrim($this->tmpDir, '/\\') . '/chunks';
        if (!is_dir($chunksBase)) {
            return 0;
        }

        $now = time();
        $cleaned = 0;
        $dirs = glob($chunksBase . '/*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return 0;
        }

        foreach ($dirs as $dir) {
            $metaFile = $dir . '/meta.json';
            $mtime = file_exists($metaFile) ? filemtime($metaFile) : filemtime($dir);
            if ($mtime !== false && ($now - $mtime) > ($maxAgeHours * 3600)) {
                $this->deleteDirectory($dir);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * @return array<string, mixed>
     */
    private function getMeta(string $uploadId): array
    {
        $chunkDir = $this->getUploadDir($uploadId);
        $metaFile = $chunkDir . '/meta.json';

        if (!file_exists($metaFile)) {
            throw new InvalidArgumentException("Chunk upload session '{$uploadId}' not found or expired.");
        }

        $content = file_get_contents($metaFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read upload metadata for {$uploadId}.");
        }

        /** @var array<string, mixed> $meta */
        $meta = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        return $meta;
    }

    private function getUploadDir(string $uploadId): string
    {
        return rtrim($this->tmpDir, '/\\') . '/chunks/' . $uploadId;
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
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
