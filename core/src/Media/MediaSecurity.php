<?php

declare(strict_types=1);

namespace BsCms\Media;

use RuntimeException;

final class MediaSecurity
{
    /**
     * Map of allowed MIME types to their canonical, safe file extension.
     * SVG is intentionally omitted by default due to XSS risk from XML/script tags.
     *
     * @var array<string, string>
     */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/ogg' => 'ogg',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    /**
     * Detect exact MIME type using libmagic file inspection.
     */
    public static function detectMimeType(string $filePath): ?string
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $filePath);
        return is_string($mime) ? strtolower(trim($mime)) : null;
    }

    /**
     * Determine if detected MIME type is in the strict allow-list.
     */
    public static function isAllowedMime(string $mime, bool $allowSvg = false): bool
    {
        if ($allowSvg && $mime === 'image/svg+xml') {
            return true;
        }

        return array_key_exists(strtolower($mime), self::ALLOWED_MIME_TYPES);
    }

    /**
     * Get safe extension derived solely from the detected MIME type.
     */
    public static function getExtensionForMime(string $mime, bool $allowSvg = false): ?string
    {
        $lowered = strtolower($mime);
        if ($allowSvg && $lowered === 'image/svg+xml') {
            return 'svg';
        }

        return self::ALLOWED_MIME_TYPES[$lowered] ?? null;
    }

    /**
     * Re-encode raster images using GD to strip embedded PHP code or comment injections.
     * If not an image or GD unavailable, performs safe binary copy.
     */
    public static function sanitizeAndStore(string $sourcePath, string $targetPath, string $mime): void
    {
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (extension_loaded('gd') && str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            $sanitized = self::reencodeImageWithGd($sourcePath, $targetPath, $mime);
            if ($sanitized) {
                return;
            }
        }

        // For plain text / CSV files, assert no PHP or executable script tags
        if (in_array($mime, ['text/plain', 'text/csv'], true)) {
            $contentSample = file_get_contents($sourcePath, false, null, 0, 8192);
            if (is_string($contentSample) && preg_match('/(<\?php|<\?=|<script|<\%)/i', $contentSample)) {
                throw new RuntimeException("Uploaded text file contains forbidden executable script tags.");
            }
        }

        // Fallback or non-image: copy file directly
        if (!copy($sourcePath, $targetPath)) {
            throw new RuntimeException("Failed to store media file at: {$targetPath}");
        }
    }

    /**
     * Re-encode image via GD library.
     */
    private static function reencodeImageWithGd(string $sourcePath, string $targetPath, string $mime): bool
    {
        try {
            $img = null;
            switch ($mime) {
                case 'image/jpeg':
                    $img = @imagecreatefromjpeg($sourcePath);
                    if ($img !== false) {
                        imagejpeg($img, $targetPath, 90);
                        return true;
                    }
                    break;
                case 'image/png':
                    $img = @imagecreatefrompng($sourcePath);
                    if ($img !== false) {
                        imagealphablending($img, false);
                        imagesavealpha($img, true);
                        imagepng($img, $targetPath, 8);
                        return true;
                    }
                    break;
                case 'image/webp':
                    if (function_exists('imagecreatefromwebp')) {
                        $img = @imagecreatefromwebp($sourcePath);
                        if ($img !== false) {
                            imagewebp($img, $targetPath, 85);
                            return true;
                        }
                    }
                    break;
                case 'image/gif':
                    $img = @imagecreatefromgif($sourcePath);
                    if ($img !== false) {
                        imagegif($img, $targetPath);
                        return true;
                    }
                    break;
            }
        } catch (\Throwable) {
            // If GD throws, return false to trigger safe copy fallback
            return false;
        }

        return false;
    }

    /**
     * Ensure security configuration (.htaccess) exists in media directory for LiteSpeed & Apache.
     */
    public static function ensureHtaccess(string $mediaDir): void
    {
        if (!is_dir($mediaDir)) {
            mkdir($mediaDir, 0755, true);
        }

        $htaccessPath = rtrim($mediaDir, '/\\') . '/.htaccess';
        $content = <<<'HTACCESS'
# Apache & LiteSpeed Security Rules for Media Uploads
# Disable PHP execution completely across all versions
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>

# Remove script handlers so files are never interpreted as CGI/PHP
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .cgi .pl .py .asp .sh .bash .phar .inc
RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .cgi .pl .py .asp .sh .bash .phar .inc
SetHandler none
SetHandler default-handler

# Force safe MIME content types
<IfModule mod_mime.c>
    AddType image/jpeg .jpg .jpeg
    AddType image/png .png
    AddType image/webp .webp
    AddType image/gif .gif
    AddType image/avif .avif
    AddType application/pdf .pdf
    AddType text/plain .txt
    AddType text/csv .csv
    AddType audio/mpeg .mp3
    AddType audio/wav .wav
    AddType audio/ogg .ogg
    AddType video/mp4 .mp4
    AddType video/webm .webm
</IfModule>

# Explicitly deny direct execution of executable extensions
<FilesMatch "\.(php|phtml|php3|php4|php5|php7|php8|phps|cgi|pl|py|asp|sh|bash|phar|inc)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

Options -ExecCGI -Indexes

# Security headers
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    <FilesMatch "\.(pdf|txt|csv|doc|docx|xls|xlsx|zip|tar|gz)$">
        Header set Content-Disposition "attachment"
    </FilesMatch>
</IfModule>
HTACCESS;

        if (!file_exists($htaccessPath) || file_get_contents($htaccessPath) !== $content) {
            file_put_contents($htaccessPath, $content);
        }
    }
}
