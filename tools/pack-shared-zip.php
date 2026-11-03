<?php
/**
 * Packs the shared-hosting release zip (WordPress-style docroot layout).
 *
 * Usage: php tools/pack-shared-zip.php <zip-file> <staging-dir>
 * Both paths should be relative to the CWD (the build/ dir) — ZipArchive on
 * Windows fails to close a zip at a spaced absolute path.
 *
 * Staging layout (zip root = docroot):
 *   index.php, .htaccess, core/...
 * plus two UNIX symlink entries pointing into core/public:
 *   admin -> core/public/admin
 *   media -> core/public/media
 */
if (count($argv) < 3) {
    fwrite(STDERR, "usage: php pack-shared-zip.php <zip-file> <staging-dir>\n");
    exit(1);
}

$zipFile = $argv[1];
$stage = rtrim($argv[2], '/\\');

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "cannot open $zipFile\n");
    exit(1);
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($it as $file) {
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($stage) + 1));
    $zip->addFile($file->getPathname(), $path);
}

// Symlink entries: unzip on the Linux host recreates them as real symlinks.
$symlinkTargets = [
    'admin' => 'core/public/admin',
    'media' => 'core/public/media',
    'site-assets' => 'core/public/site-assets',
];
foreach ($symlinkTargets as $entry => $target) {
    $zip->addFromString($entry, $target);
    $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, (0120000 << 16) | 0775);
}

if ($zip->close() !== true) {
    fwrite(STDERR, "cannot close $zipFile\n");
    exit(1);
}
echo "packed: $zipFile\n";
