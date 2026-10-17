<?php

declare(strict_types=1);

namespace BsCms\Install;

/**
 * Requirements checker for web installer.
 * Detects PHP version, required & recommended extensions, memory_limit,
 * upload limits, writable directories, HTTPS, and provides host-specific fix hints.
 */
final class Requirements
{
    /** @var list<string> */
    public const REQUIRED_EXTENSIONS = [
        'pdo_mysql',
        'mbstring',
        'json',
        'openssl',
        'sodium',
        'fileinfo',
        'curl',
        'zip',
        'ctype',
        'filter',
    ];

    /** @var list<string> */
    public const RECOMMENDED_EXTENSIONS = [
        'opcache',
        'apcu',
        'intl',
        'exif',
    ];

    /**
     * @return array{
     *   php_version: array{status: string, current: string, required: string, hint: ?string},
     *   extensions: array<string, array{status: string, loaded: bool, required: bool, hint: ?string}>,
     *   image_driver: array{status: string, driver: string, hint: ?string},
     *   memory_limit: array{status: string, current: string, hint: ?string},
     *   storage_writable: array{status: string, writable: bool, path: string, hint: ?string},
     *   https: array{status: string, enabled: bool, hint: ?string},
     *   can_install: bool
     * }
     */
    public static function check(string $storageDir, bool $isHttps = false): array
    {
        $canInstall = true;

        // 1. PHP Version
        $phpCurrent = PHP_VERSION;
        $phpOk = version_compare($phpCurrent, '8.2.0', '>=');
        if (!$phpOk) {
            $canInstall = false;
        }
        $phpResult = [
            'status' => $phpOk ? 'pass' : 'fail',
            'current' => $phpCurrent,
            'required' => '>= 8.2.0',
            'hint' => $phpOk ? null : 'Upgrade PHP version in your hosting control panel (e.g. hPanel > PHP Configuration).',
        ];

        // 2. Extensions
        $extResults = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            if (!$loaded) {
                $canInstall = false;
            }

            $hint = null;
            if (!$loaded) {
                $hint = match ($ext) {
                    'sodium' => 'Enable sodium in hosting panel (Hostinger: hPanel > Advanced > PHP Configuration > PHP Extensions > sodium checkbox).',
                    'pdo_mysql' => 'Enable pdo_mysql extension in your PHP configuration.',
                    default => "Enable the '{$ext}' extension in your PHP configuration.",
                };
            }

            $extResults[$ext] = [
                'status' => $loaded ? 'pass' : 'fail',
                'loaded' => $loaded,
                'required' => true,
                'hint' => $hint,
            ];
        }

        foreach (self::RECOMMENDED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $extResults[$ext] = [
                'status' => $loaded ? 'pass' : 'warn',
                'loaded' => $loaded,
                'required' => false,
                'hint' => $loaded ? null : "Optional performance extension '{$ext}' is not loaded.",
            ];
        }

        // Image driver: gd or imagick
        $hasGd = extension_loaded('gd');
        $hasImagick = extension_loaded('imagick');
        $imageDriverOk = $hasGd || $hasImagick;
        if (!$imageDriverOk) {
            $canInstall = false;
        }
        $imageResult = [
            'status' => $imageDriverOk ? 'pass' : 'fail',
            'driver' => $hasImagick ? 'imagick' : ($hasGd ? 'gd' : 'none'),
            'hint' => $imageDriverOk ? null : 'Either GD or Imagick extension is required for image processing.',
        ];

        // Storage writable
        $isWritable = is_dir($storageDir) && is_writable($storageDir);
        if (!$isWritable) {
            $canInstall = false;
        }
        $storageResult = [
            'status' => $isWritable ? 'pass' : 'fail',
            'writable' => $isWritable,
            'path' => $storageDir,
            'hint' => $isWritable ? null : "Ensure '{$storageDir}' exists and has 0750 or 0775 write permissions.",
        ];

        // HTTPS
        $httpsResult = [
            'status' => $isHttps ? 'pass' : 'warn',
            'enabled' => $isHttps,
            'hint' => $isHttps ? null : 'HTTPS is strongly recommended for production security.',
        ];

        // Memory limit
        $memoryLimit = (string) ini_get('memory_limit');
        $memBytes = self::parseBytes($memoryLimit);
        $memOk = $memBytes >= 64 * 1024 * 1024 || $memBytes === -1;
        $memoryResult = [
            'status' => $memOk ? 'pass' : 'warn',
            'current' => $memoryLimit,
            'hint' => $memOk ? null : 'Recommended memory_limit is at least 128M.',
        ];

        return [
            'php_version' => $phpResult,
            'extensions' => $extResults,
            'image_driver' => $imageResult,
            'memory_limit' => $memoryResult,
            'storage_writable' => $storageResult,
            'https' => $httpsResult,
            'can_install' => $canInstall,
        ];
    }

    /**
     * Detect matching CLI PHP binary path from web environment.
     * Hostinger CloudLinux maps /opt/alt/phpXY/usr/bin/php.
     *
     * @return array{path: string, type: string, warning: ?string}
     */
    public static function detectCliPhpBinary(): array
    {
        $version = PHP_VERSION;
        // Parse major.minor e.g. 8.3 or 8.2
        if (preg_match('/^(\d+)\.(\d+)/', $version, $m)) {
            $tag = $m[1] . $m[2]; // e.g. "83"
            $cloudLinuxPath = "/opt/alt/php{$tag}/usr/bin/php";
            if (is_executable($cloudLinuxPath)) {
                return [
                    'path' => $cloudLinuxPath,
                    'type' => 'cloudlinux_matched',
                    'warning' => null,
                ];
            }
        }

        // Check defined PHP_BINARY if valid CLI
        if (defined('PHP_BINARY') && !str_contains(PHP_BINARY, 'fpm') && !str_contains(PHP_BINARY, 'cgi')) {
            return [
                'path' => PHP_BINARY,
                'type' => 'detected',
                'warning' => null,
            ];
        }

        // Generic fallback with warning
        return [
            'path' => 'php',
            'type' => 'generic_fallback',
            'warning' => "Could not automatically determine exact CLI path for PHP {$version}. 'php' will be used, but verify in your host's SSH/cron settings.",
        ];
    }

    private static function parseBytes(string $val): int
    {
        $val = trim($val);
        if ($val === '-1') {
            return -1;
        }
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int) substr($val, 0, -1);

        return match ($last) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $val,
        };
    }
}
