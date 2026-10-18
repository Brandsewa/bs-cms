<?php

declare(strict_types=1);

namespace BsCms\Media;

use BsCms\Database\Connection;
use BsCms\Security\Ulid;
use GdImage;

final class MediaProcessor
{
    /**
     * Presets configuration: width, height, mode (crop vs fit).
     *
     * @var array<string, array{width: int, height: int, crop: bool}>
     */
    public const PRESETS = [
        'thumbnail' => ['width' => 150, 'height' => 150, 'crop' => true],
        'medium' => ['width' => 800, 'height' => 800, 'crop' => false],
        'large' => ['width' => 1600, 'height' => 1600, 'crop' => false],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $mediaBaseDir
    ) {
    }

    /**
     * Process image to compute metadata and generate responsive variants.
     *
     * @return array{width: ?int, height: ?int, dominant_color: ?string, variants: list<array<string, mixed>>}
     */
    public function processImage(string $mediaId, string $storedPath, string $mime): array
    {
        $result = [
            'width' => null,
            'height' => null,
            'dominant_color' => null,
            'variants' => [],
        ];

        if (!str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            return $result;
        }

        if (!extension_loaded('gd') || !file_exists($storedPath)) {
            return $result;
        }

        $imageInfo = @getimagesize($storedPath);
        if ($imageInfo === false) {
            return $result;
        }

        $origWidth = $imageInfo[0];
        $origHeight = $imageInfo[1];
        $result['width'] = $origWidth;
        $result['height'] = $origHeight;

        $srcImage = $this->createImageFromFile($storedPath, $mime);
        if ($srcImage === null) {
            return $result;
        }

        // 1. Dominant color extraction via 1x1 resampling
        $result['dominant_color'] = $this->extractDominantColor($srcImage);

        // 2. Generate variants
        $variantsDir = rtrim($this->mediaBaseDir, '/\\') . '/variants';
        if (!is_dir($variantsDir)) {
            mkdir($variantsDir, 0755, true);
        }

        $formats = ['webp'];
        $origExt = match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        if ($origExt !== 'webp') {
            $formats[] = $origExt;
        }

        $variantsTable = $this->connection->prefixTable('media_variants');

        foreach (self::PRESETS as $presetName => $presetConf) {
            // Only generate variant if original is larger than preset or for thumbnail
            if (!$presetConf['crop'] && $origWidth <= $presetConf['width'] && $origHeight <= $presetConf['height']) {
                continue;
            }

            $resized = $this->resizeImage($srcImage, $origWidth, $origHeight, $presetConf['width'], $presetConf['height'], $presetConf['crop']);
            if ($resized === null) {
                continue;
            }

            $varWidth = imagesx($resized);
            $varHeight = imagesy($resized);

            foreach ($formats as $fmt) {
                $variantFileName = "{$mediaId}_{$presetName}.{$fmt}";
                $variantDiskPath = "{$variantsDir}/{$variantFileName}";
                $variantRelPath = "media/variants/{$variantFileName}";

                $saved = $this->saveImageFormat($resized, $variantDiskPath, $fmt);
                if (!$saved || !file_exists($variantDiskPath)) {
                    continue;
                }

                $varSize = (int) filesize($variantDiskPath);
                $varId = Ulid::generate();

                $this->connection->execute(
                    "INSERT INTO `{$variantsTable}` (`id`, `media_id`, `preset`, `format`, `width`, `height`, `size_bytes`, `storage_path`)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE `width` = VALUES(`width`), `height` = VALUES(`height`), `size_bytes` = VALUES(`size_bytes`), `storage_path` = VALUES(`storage_path`)",
                    [$varId, $mediaId, $presetName, $fmt, $varWidth, $varHeight, $varSize, $variantRelPath]
                );

                $result['variants'][] = [
                    'id' => $varId,
                    'media_id' => $mediaId,
                    'preset' => $presetName,
                    'format' => $fmt,
                    'width' => $varWidth,
                    'height' => $varHeight,
                    'size_bytes' => $varSize,
                    'storage_path' => $variantRelPath,
                ];
            }
        }

        return $result;
    }

    private function createImageFromFile(string $path, string $mime): ?GdImage
    {
        try {
            $img = match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($path),
                'image/png' => @imagecreatefrompng($path),
                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
                'image/gif' => @imagecreatefromgif($path),
                default => false,
            };

            return $img instanceof GdImage ? $img : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resizeImage(GdImage $src, int $srcW, int $srcH, int $targetW, int $targetH, bool $crop): ?GdImage
    {
        if ($srcW <= 0 || $srcH <= 0) {
            return null;
        }

        if ($crop) {
            // Thumbnail square / fixed aspect crop
            $cropW = $targetW >= 1 ? $targetW : 1;
            $cropH = $targetH >= 1 ? $targetH : 1;
            $dst = imagecreatetruecolor($cropW, $cropH);
            if ($dst === false) {
                return null;
            }

            // Alpha preservation
            imagealphablending($dst, false);
            imagesavealpha($dst, true);

            $ratioSrc = $srcW / $srcH;
            $ratioDst = $cropW / $cropH;

            if ($ratioSrc > $ratioDst) {
                // Source is wider: crop sides
                $newW = (int) round($srcH * $ratioDst);
                $srcX = (int) round(($srcW - $newW) / 2);
                $srcY = 0;
                $srcCropW = $newW;
                $srcCropH = $srcH;
            } else {
                // Source is taller: crop top/bottom
                $newH = (int) round($srcW / $ratioDst);
                $srcX = 0;
                $srcY = (int) round(($srcH - $newH) / 2);
                $srcCropW = $srcW;
                $srcCropH = $newH;
            }

            imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $cropW, $cropH, $srcCropW, $srcCropH);
            return $dst;
        }

        // Proportional fit (inside box targetW x targetH)
        $ratio = min($targetW / $srcW, $targetH / $srcH);
        $computedW = (int) round($srcW * $ratio);
        $computedH = (int) round($srcH * $ratio);
        $finalW = $computedW >= 1 ? $computedW : 1;
        $finalH = $computedH >= 1 ? $computedH : 1;

        $dst = imagecreatetruecolor($finalW, $finalH);
        if ($dst === false) {
            return null;
        }

        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $finalW, $finalH, $srcW, $srcH);
        return $dst;
    }

    private function saveImageFormat(GdImage $image, string $targetPath, string $format): bool
    {
        try {
            return match ($format) {
                'webp' => function_exists('imagewebp') ? imagewebp($image, $targetPath, 85) : false,
                'png' => imagepng($image, $targetPath, 8),
                'gif' => imagegif($image, $targetPath),
                default => imagejpeg($image, $targetPath, 85),
            };
        } catch (\Throwable) {
            return false;
        }
    }

    private function extractDominantColor(GdImage $srcImage): ?string
    {
        try {
            $tiny = imagecreatetruecolor(1, 1);
            if ($tiny === false) {
                return null;
            }

            imagecopyresampled($tiny, $srcImage, 0, 0, 0, 0, 1, 1, imagesx($srcImage), imagesy($srcImage));
            $rgb = imagecolorat($tiny, 0, 0);

            if ($rgb === false) {
                return null;
            }

            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;

            return sprintf('#%02x%02x%02x', $r, $g, $b);
        } catch (\Throwable) {
            return null;
        }
    }
}
