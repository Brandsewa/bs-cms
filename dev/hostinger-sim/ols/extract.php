<?php
$zipFile = $argv[1] ?? '';
$targetDir = $argv[2] ?? '';

if (!file_exists($zipFile)) {
    fwrite(STDERR, "Zip file not found: {$zipFile}\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipFile) !== true) {
    fwrite(STDERR, "Failed to open zip: {$zipFile}\n");
    exit(1);
}

if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

$zip->extractTo($targetDir);
$zip->close();
echo "EXTRACTED_OK\n";
