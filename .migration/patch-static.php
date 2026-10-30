<?php
declare(strict_types=1);

$static = '/var/www/vhosts/localhost/core/vendor/composer/autoload_static.php';
$s2 = file_get_contents($static);
$needle2 = "/src/Install/Requirements.php',";
$add2 = "\n        'BsCms\\\\Install\\\\InstallerViews' => __DIR__ . '/../..' . '/src/Install/InstallerViews.php',";
if (strpos($s2, 'InstallerViews') === false && strpos($s2, $needle2) !== false) {
    $s2 = str_replace($needle2, $needle2 . $add2, $s2);
    file_put_contents($static, $s2);
    echo "static patched\n";
} else {
    echo "static: " . (strpos($s2, 'InstallerViews') !== false ? 'already' : 'needle missing') . "\n";
}
