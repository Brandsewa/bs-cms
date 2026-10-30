<?php
declare(strict_types=1);

// Adds InstallerViews to the composer classmap (path-anchored needles).
$base = '/var/www/vhosts/localhost/core/vendor/composer/';

$classmap = $base . 'autoload_classmap.php';
$s = file_get_contents($classmap);
$needle = "/src/Install/Requirements.php',";
$add = "\n    'BsCms\\\\Install\\\\InstallerViews' => \$baseDir . '/src/Install/InstallerViews.php',";
if (strpos($s, 'InstallerViews') === false && strpos($s, $needle) !== false) {
    $s = str_replace($needle, $needle . $add, $s);
    file_put_contents($classmap, $s);
    echo "classmap patched\n";
} else {
    echo "classmap: " . (strpos($s, 'InstallerViews') !== false ? 'already' : 'needle missing') . "\n";
}

$static = $base . 'autoload_static.php';
$s2 = file_get_contents($static);
$needle2 = "/src/Install/Requirements.php' => 2,";
$add2 = "\n        'BsCms\\\\Install\\\\InstallerViews' => 2,";
if (strpos($s2, 'InstallerViews') === false && strpos($s2, $needle2) !== false) {
    $s2 = str_replace($needle2, $needle2 . $add2, $s2);
    file_put_contents($static, $s2);
    echo "static patched\n";
} else {
    echo "static: " . (strpos($s2, 'InstallerViews') !== false ? 'already' : 'needle missing') . "\n";
}
