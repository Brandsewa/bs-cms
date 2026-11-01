<?php
declare(strict_types=1);

// One-off sim config: switch the publishing pipeline target to 'manual'.
// The sim has no GitHub Actions credentials, so the default github_actions
// target records a FAILED deployment on every publish (the red banner).
// 'manual' is the designed local workflow: dispatch is a no-op and the
// operator builds via `dev/sim site`.

// Run from inside the sim container with the file placed at
// /var/www/vhosts/localhost/core/set-sim-manual-deploy.php:
//   docker exec <ols> php /var/www/vhosts/localhost/core/set-sim-manual-deploy.php

require_once __DIR__ . '/vendor/autoload.php';

use BsCms\Kernel\Application;
use BsCms\Kernel\CoreServiceProvider;
use BsCms\Publishing\PublishingSettingsRepository;

$app = new Application(__DIR__);
if (!$app->isInstalled()) {
    fwrite(STDERR, "not installed\n");
    exit(1);
}
$app->register(new CoreServiceProvider($app));
$app->boot();

/** @var PublishingSettingsRepository $repo */
$repo = $app->getContainer()->get(PublishingSettingsRepository::class);
$current = $repo->get();
$updated = $repo->update(array_merge($current, ['target_type' => 'manual', 'enabled' => true]));
echo "target_type={$updated['target_type']} enabled=" . var_export((bool) ($updated['enabled'] ?? false), true) . "\n";
