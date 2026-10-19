<?php

declare(strict_types=1);

namespace BsCms\Media;

final class MediaPresenter
{
    /** @var list<string> */
    public const RISKY_EXTENSIONS = ['pdf', 'csv', 'txt', 'zip'];

    /** @var list<string> */
    public const RISKY_MIMES = [
        'application/pdf',
        'text/csv',
        'text/plain',
        'application/zip',
        'application/x-zip-compressed',
    ];

    /**
     * Determines whether a media file is a risky type requiring PHP streaming.
     */
    public static function isRiskyType(string $mime, string $extension): bool
    {
        $ext = strtolower(ltrim($extension, '.'));
        $mimeType = strtolower($mime);

        return in_array($ext, self::RISKY_EXTENSIONS, true) || in_array($mimeType, self::RISKY_MIMES, true);
    }

    /**
     * Resolve the public URL for a media item.
     * Risky media types (PDF, CSV, TXT, ZIP) resolve to the PHP streaming route (/api/v1/public/media/{id}/file),
     * while safe raster images keep their direct static path.
     */
    public static function resolveUrl(string $mediaId, string $mime, string $extension, string $storagePath): string
    {
        if (self::isRiskyType($mime, $extension) && $mediaId !== '') {
            return "/api/v1/public/media/{$mediaId}/file";
        }

        return '/' . ltrim($storagePath, '/');
    }

    /**
     * Present media item for API responses with canonical key set.
     *
     * @param array<string, mixed> $media
     * @param list<array<string, mixed>> $variants
     * @return array<string, mixed>
     */
    public static function present(array $media, array $variants = []): array
    {
        $id = (string) ($media['id'] ?? '');
        $mime = (string) ($media['mime'] ?? '');
        $extension = (string) ($media['extension'] ?? '');
        $storagePath = (string) ($media['storage_path'] ?? '');

        $url = self::resolveUrl($id, $mime, $extension, $storagePath);

        $formattedVariants = [];
        foreach ($variants as $var) {
            $formattedVariants[] = [
                'id' => (string) ($var['id'] ?? ''),
                'media_id' => (string) ($var['media_id'] ?? $id),
                'preset' => (string) ($var['preset'] ?? $var['name'] ?? ''),
                'format' => (string) ($var['format'] ?? ''),
                'width' => (int) ($var['width'] ?? 0),
                'height' => (int) ($var['height'] ?? 0),
                'size_bytes' => (int) ($var['size_bytes'] ?? 0),
                'storage_path' => (string) ($var['storage_path'] ?? ''),
                'url' => '/' . ltrim((string) ($var['storage_path'] ?? ''), '/'),
            ];
        }

        return [
            'id' => $id,
            'folder_id' => isset($media['folder_id']) ? (string) $media['folder_id'] : null,
            'filename' => (string) ($media['filename'] ?? ''),
            'original_name' => (string) ($media['original_name'] ?? ''),
            'mime' => $mime,
            'extension' => $extension,
            'size_bytes' => (int) ($media['size_bytes'] ?? 0),
            'width' => isset($media['width']) ? (int) $media['width'] : null,
            'height' => isset($media['height']) ? (int) $media['height'] : null,
            'duration_seconds' => isset($media['duration_seconds']) ? (int) $media['duration_seconds'] : null,
            'content_hash' => (string) ($media['content_hash'] ?? ''),
            'storage_driver' => (string) ($media['storage_driver'] ?? 'local'),
            'storage_path' => $storagePath,
            'url' => $url,
            'alt_text' => $media['alt_text'] ?? null,
            'caption' => $media['caption'] ?? null,
            'title' => $media['title'] ?? null,
            'focal_x' => isset($media['focal_x']) ? (float) $media['focal_x'] : null,
            'focal_y' => isset($media['focal_y']) ? (float) $media['focal_y'] : null,
            'blurhash' => $media['blurhash'] ?? null,
            'dominant_color' => $media['dominant_color'] ?? null,
            'usage_count' => (int) ($media['usage_count'] ?? 0),
            'uploaded_by' => isset($media['uploaded_by']) ? (string) $media['uploaded_by'] : null,
            'variants' => $formattedVariants,
            'created_at' => (string) ($media['created_at'] ?? ''),
            'updated_at' => (string) ($media['updated_at'] ?? ''),
            'deleted_at' => isset($media['deleted_at']) ? (string) $media['deleted_at'] : null,
        ];
    }
}
