<?php

declare(strict_types=1);

namespace BsCms\Plugins;

use BsCms\Database\Connection;
use BsCms\Security\Ulid;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class PluginManager
{
    /** @var array<string, PluginInterface> */
    private array $registeredPlugins = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?ContactFormPlugin $contactFormPlugin = null
    ) {
        if ($this->contactFormPlugin !== null) {
            $this->registerPlugin($this->contactFormPlugin);
        } else {
            $this->registerPlugin(new ContactFormPlugin($this->connection));
        }
    }

    public function registerPlugin(PluginInterface $plugin): void
    {
        $this->registeredPlugins[$plugin->getHandle()] = $plugin;
    }

    public function getPluginInstance(string $handle): ?PluginInterface
    {
        return $this->registeredPlugins[$handle] ?? null;
    }

    /**
     * Return catalog of marketplace plugins enriched with DB installation state.
     *
     * @return list<array<string, mixed>>
     */
    public function getCatalog(): array
    {
        $installed = $this->getInstalledMap();

        $catalog = [
            [
                'handle' => ContactFormPlugin::HANDLE,
                'name' => 'Contact Form & Inquiries',
                'version' => '1.0.0',
                'description' => 'Build responsive contact forms, embed them on any page or section via blocks or snippets, and view incoming submissions directly in your CMS.',
                'author' => 'BS CMS Official',
                'category' => 'Forms & Feedback',
                'icon' => 'mail',
                'is_first_party' => true,
                'features' => [
                    'Visual form field builder',
                    'Page section block integration',
                    'Anti-spam honeypot defense',
                    'Inquiry inbox with read/unread statuses',
                ],
            ],
            [
                'handle' => 'seo-pro',
                'name' => 'SEO & Discoverability Suite',
                'version' => '1.2.0',
                'description' => 'Automated JSON-LD schemas, OpenGraph previews, XML sitemaps chunking, and AI crawler directives.',
                'author' => 'BS CMS Official',
                'category' => 'SEO & Crawlers',
                'icon' => 'sparkles',
                'is_first_party' => true,
                'features' => [
                    'Real-time SERP preview',
                    'IndexNow ping automation',
                    'Dynamic OpenGraph generation',
                ],
            ],
            [
                'handle' => 'web-analytics',
                'name' => 'Privacy-Focused Analytics',
                'version' => '0.9.5',
                'description' => 'Lightweight, cookie-less website analytics and page-view metrics without third-party tracking scripts.',
                'author' => 'Community',
                'category' => 'Analytics',
                'icon' => 'activity',
                'is_first_party' => false,
                'features' => [
                    'Zero cookie banner required',
                    'Under 1 KB tracking footprint',
                    'Top referrers and UTM tracking',
                ],
            ],
        ];

        $results = [];
        foreach ($catalog as $item) {
            $handle = $item['handle'];
            $inst = $installed[$handle] ?? null;

            $item['is_installed'] = $inst !== null;
            $item['is_enabled'] = $inst !== null ? (bool) $inst['is_enabled'] : false;
            $item['installed_version'] = $inst !== null ? (string) $inst['version'] : null;
            $item['installed_at'] = $inst !== null ? (string) $inst['installed_at'] : null;
            $item['config'] = $inst !== null && isset($inst['config']) ? $inst['config'] : null;

            $results[] = $item;
        }

        return $results;
    }

    /**
     * Get installed plugin record by handle.
     *
     * @return array<string, mixed>|null
     */
    public function getInstalledPlugin(string $handle): ?array
    {
        $pluginsTable = $this->connection->prefixTable('plugins');
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare("SELECT * FROM `{$pluginsTable}` WHERE `handle` = ? LIMIT 1");
        $stmt->execute([$handle]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydratePlugin($row);
    }

    /**
     * Check if a plugin is installed and actively enabled.
     */
    public function isPluginEnabled(string $handle): bool
    {
        $plugin = $this->getInstalledPlugin($handle);
        return $plugin !== null && (bool) $plugin['is_enabled'];
    }

    /**
     * Install a plugin from the catalog.
     *
     * @return array<string, mixed>
     */
    public function install(string $handle): array
    {
        $existing = $this->getInstalledPlugin($handle);
        if ($existing !== null) {
            return $existing;
        }

        $catalog = $this->getCatalog();
        $target = null;
        foreach ($catalog as $cat) {
            if ($cat['handle'] === $handle) {
                $target = $cat;
                break;
            }
        }

        if ($target === null) {
            throw new InvalidArgumentException("Plugin '{$handle}' not found in marketplace catalog.");
        }

        $id = Ulid::generate();
        $version = (string) $target['version'];
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $pluginsTable = $this->connection->prefixTable('plugins');

        $defaultConfig = [];
        $instance = $this->getPluginInstance($handle);
        if ($instance !== null) {
            $defaultConfig = $instance->getDefaultConfig();
        }

        $stmt = $this->connection->getPdo()->prepare("INSERT INTO `{$pluginsTable}`
            (`id`, `handle`, `version`, `is_enabled`, `config`, `installed_at`)
            VALUES (?, ?, ?, 1, ?, ?)");

        $stmt->execute([
            $id,
            $handle,
            $version,
            json_encode($defaultConfig, JSON_THROW_ON_ERROR),
            $now,
        ]);

        // Boot plugin if registered
        if ($instance !== null) {
            $instance->boot();
        }

        return (array) $this->getInstalledPlugin($handle);
    }

    /**
     * Uninstall a plugin.
     */
    public function uninstall(string $handle): void
    {
        $pluginsTable = $this->connection->prefixTable('plugins');
        $stmt = $this->connection->getPdo()->prepare("DELETE FROM `{$pluginsTable}` WHERE `handle` = ?");
        $stmt->execute([$handle]);
    }

    /**
     * Toggle plugin active state.
     *
     * @return array<string, mixed>
     */
    public function toggle(string $handle, ?bool $enabled = null): array
    {
        $plugin = $this->getInstalledPlugin($handle);
        if ($plugin === null) {
            // Auto-install if not installed yet
            $plugin = $this->install($handle);
        }

        $targetState = $enabled ?? !((bool) $plugin['is_enabled']);
        $pluginsTable = $this->connection->prefixTable('plugins');

        $stmt = $this->connection->getPdo()->prepare("UPDATE `{$pluginsTable}` SET `is_enabled` = ? WHERE `handle` = ?");
        $stmt->execute([$targetState ? 1 : 0, $handle]);

        if ($targetState) {
            $instance = $this->getPluginInstance($handle);
            if ($instance !== null) {
                $instance->boot();
            }
        }

        return (array) $this->getInstalledPlugin($handle);
    }

    /**
     * Update plugin configuration.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function updateConfig(string $handle, array $config): array
    {
        $plugin = $this->getInstalledPlugin($handle);
        if ($plugin === null) {
            throw new RuntimeException("Plugin '{$handle}' is not installed.");
        }

        $mergedConfig = array_merge((array) ($plugin['config'] ?? []), $config);
        $pluginsTable = $this->connection->prefixTable('plugins');

        $stmt = $this->connection->getPdo()->prepare("UPDATE `{$pluginsTable}` SET `config` = ? WHERE `handle` = ?");
        $stmt->execute([json_encode($mergedConfig, JSON_THROW_ON_ERROR), $handle]);

        return (array) $this->getInstalledPlugin($handle);
    }

    /**
     * Boot all installed and enabled plugins.
     */
    public function boot(): void
    {
        $pluginsTable = $this->connection->prefixTable('plugins');
        $pdo = $this->connection->getPdo();

        // Check if plugins table exists before querying (in case migrations haven't run)
        try {
            $stmt = $pdo->query("SELECT `handle`, `is_enabled` FROM `{$pluginsTable}` WHERE `is_enabled` = 1");
            $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                $handle = (string) $row['handle'];
                $instance = $this->getPluginInstance($handle);
                if ($instance !== null) {
                    $instance->boot();
                }
            }
        } catch (\Throwable) {
            // Table may not exist during early boot or installation
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getInstalledMap(): array
    {
        $pluginsTable = $this->connection->prefixTable('plugins');
        $pdo = $this->connection->getPdo();

        try {
            $stmt = $pdo->query("SELECT * FROM `{$pluginsTable}`");
            $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            $map = [];
            foreach ($rows as $row) {
                $h = (string) $row['handle'];
                $map[$h] = $this->hydratePlugin($row);
            }
            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydratePlugin(array $row): array
    {
        $config = null;
        if (isset($row['config']) && is_string($row['config'])) {
            try {
                $config = json_decode($row['config'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $config = null;
            }
        } elseif (isset($row['config']) && is_array($row['config'])) {
            $config = $row['config'];
        }

        return [
            'id' => (string) $row['id'],
            'handle' => (string) $row['handle'],
            'version' => (string) $row['version'],
            'is_enabled' => (bool) $row['is_enabled'],
            'config' => $config,
            'installed_at' => (string) $row['installed_at'],
        ];
    }
}
