<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Media;

use BsCms\Media\MediaPresenter;
use PHPUnit\Framework\TestCase;

final class MediaPresenterTest extends TestCase
{
    public function testMediaPresenterExactKeySet(): void
    {
        $raw = [
            'id' => '01JABCDEF1234567890ABCDEF1',
            'folder_id' => null,
            'filename' => '01JABCDEF1234567890ABCDEF1.jpg',
            'original_name' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 123456,
            'width' => 1920,
            'height' => 1080,
            'duration_seconds' => null,
            'content_hash' => 'dummy_hash',
            'storage_driver' => 'local',
            'storage_path' => 'media/photo.jpg',
            'alt_text' => 'A scenic photo',
            'caption' => 'Photo caption',
            'title' => 'Photo Title',
            'focal_x' => 0.5,
            'focal_y' => 0.5,
            'blurhash' => 'dummy_blurhash',
            'dominant_color' => '#123456',
            'usage_count' => 3,
            'uploaded_by' => '01JUSER1234567890ABCDEF123',
            'created_at' => '2026-10-19 12:00:00',
            'updated_at' => '2026-10-19 12:00:00',
            'deleted_at' => null,
        ];

        $variants = [
            [
                'id' => '01JVAR1234567890ABCDEF1234',
                'media_id' => '01JABCDEF1234567890ABCDEF1',
                'preset' => 'thumb',
                'format' => 'webp',
                'width' => 320,
                'height' => 180,
                'size_bytes' => 12345,
                'storage_path' => 'media/variants/thumb.webp',
            ],
        ];

        $presented = MediaPresenter::present($raw, $variants);

        $expectedKeys = [
            'alt_text',
            'blurhash',
            'caption',
            'content_hash',
            'created_at',
            'deleted_at',
            'dominant_color',
            'duration_seconds',
            'extension',
            'filename',
            'focal_x',
            'focal_y',
            'folder_id',
            'height',
            'id',
            'mime',
            'original_name',
            'size_bytes',
            'storage_driver',
            'storage_path',
            'title',
            'updated_at',
            'uploaded_by',
            'url',
            'usage_count',
            'variants',
            'width',
        ];

        $actualKeys = array_keys($presented);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys, 'MediaPresenter keys must match canonical schema exactly.');

        // Raster images should use direct static /media/... path
        $this->assertSame('/media/photo.jpg', $presented['url']);

        // Check variant keys
        $expectedVariantKeys = [
            'format',
            'height',
            'id',
            'media_id',
            'preset',
            'size_bytes',
            'storage_path',
            'url',
            'width',
        ];
        $actualVariantKeys = array_keys($presented['variants'][0]);
        sort($actualVariantKeys);
        $this->assertSame($expectedVariantKeys, $actualVariantKeys);
        $this->assertSame('/media/variants/thumb.webp', $presented['variants'][0]['url']);
    }

    public function testRiskyMediaTypesResolveToPhpStreamingRoute(): void
    {
        $riskyTypes = [
            ['pdf', 'application/pdf', 'document.pdf', 'media/document.pdf'],
            ['csv', 'text/csv', 'data.csv', 'media/data.csv'],
            ['txt', 'text/plain', 'notes.txt', 'media/notes.txt'],
            ['zip', 'application/zip', 'archive.zip', 'media/archive.zip'],
        ];

        foreach ($riskyTypes as [$ext, $mime, $origName, $storagePath]) {
            $id = '01JRISKY' . strtoupper($ext) . '1234567890ABCDEF';
            $raw = [
                'id' => $id,
                'folder_id' => null,
                'filename' => "{$id}.{$ext}",
                'original_name' => $origName,
                'mime' => $mime,
                'extension' => $ext,
                'size_bytes' => 5000,
                'content_hash' => 'hash_' . $ext,
                'storage_path' => $storagePath,
            ];

            $presented = MediaPresenter::present($raw);

            $this->assertSame(
                "/api/v1/public/media/{$id}/file",
                $presented['url'],
                "Risky extension '{$ext}' with mime '{$mime}' must resolve to PHP streaming route."
            );
        }
    }

    public function testSafeImageTypesKeepStaticUrl(): void
    {
        $safeTypes = [
            ['jpg', 'image/jpeg', 'media/photo.jpg'],
            ['png', 'image/png', 'media/image.png'],
            ['webp', 'image/webp', 'media/graphic.webp'],
            ['gif', 'image/gif', 'media/animation.gif'],
            ['avif', 'image/avif', 'media/banner.avif'],
        ];

        foreach ($safeTypes as [$ext, $mime, $storagePath]) {
            $id = '01JSAFE' . strtoupper($ext) . '1234567890ABCDE';
            $raw = [
                'id' => $id,
                'folder_id' => null,
                'filename' => "{$id}.{$ext}",
                'original_name' => "file.{$ext}",
                'mime' => $mime,
                'extension' => $ext,
                'size_bytes' => 5000,
                'content_hash' => 'hash_' . $ext,
                'storage_path' => $storagePath,
            ];

            $presented = MediaPresenter::present($raw);

            $this->assertSame(
                '/' . $storagePath,
                $presented['url'],
                "Safe raster image '{$ext}' must keep static storage path."
            );
        }
    }
}
