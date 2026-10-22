<?php

declare(strict_types=1);

namespace BsCms\CrawlerPolicy;

use BsCms\Database\Connection;
use RuntimeException;

final class CrawlerPolicyService
{
    /** @var list<array<string, mixed>>|null */
    private ?array $catalogue = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly RobotsGenerator $generator,
        private readonly string $cataloguePath
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getBotCatalogue(): array
    {
        if ($this->catalogue !== null) {
            return $this->catalogue;
        }

        if (!is_file($this->cataloguePath)) {
            return [];
        }

        $raw = file_get_contents($this->cataloguePath);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        $list = [];
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (is_array($item)) {
                    $row = [];
                    foreach ($item as $k => $v) {
                        $row[(string) $k] = $v;
                    }
                    $list[] = $row;
                }
            }
        }
        $this->catalogue = $list;
        return $this->catalogue;
    }

    /**
     * @return array<string, mixed>
     */
    public function getActiveVersion(): array
    {
        $table = $this->connection->prefixTable('crawler_policy_versions');
        $stmt = $this->connection->getPdo()->query("SELECT * FROM `{$table}` WHERE `is_active` = 1 ORDER BY `created_at` DESC LIMIT 1");
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;

        if ($row !== false) {
            $row['policy'] = json_decode((string) $row['policy'], true);
            return $row;
        }

        // Return default balanced preset if no version is saved yet
        $defaultPolicy = [
            'preset' => 'balanced',
            'bot_rules' => [],
            'disallow_paths' => [],
            'sitemap_url' => '/sitemap.xml',
        ];
        $robots = $this->generator->generate($defaultPolicy, $this->getBotCatalogue(), 'production', 1);

        return [
            'id' => '00000000000000000000000000',
            'preset' => 'balanced',
            'policy' => $defaultPolicy,
            'robots_txt' => $robots,
            'is_active' => 1,
            'changed_by' => null,
            'change_note' => 'Default system policy',
            'created_at' => date('Y-m-d H:i:s.000'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVersions(int $limit = 20): array
    {
        $table = $this->connection->prefixTable('crawler_policy_versions');
        $stmt = $this->connection->getPdo()->prepare("SELECT `id`, `preset`, `change_note`, `is_active`, `changed_by`, `created_at` FROM `{$table}` ORDER BY `created_at` DESC LIMIT ?");
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<string, mixed> Created version
     */
    public function saveVersion(
        array $policy,
        string $environment = 'production',
        ?string $changedBy = null,
        ?string $changeNote = null
    ): array {
        $preset = (string) ($policy['preset'] ?? 'balanced');
        $catalogue = $this->getBotCatalogue();

        if (!in_array($preset, ['balanced', 'open', 'private', 'custom'], true)) {
            throw new \InvalidArgumentException("Unknown preset '" . substr($preset, 0, 32) . "'.");
        }
        $paths = $policy['disallow_paths'] ?? [];
        if (!is_array($paths) || count($paths) > 200) {
            throw new \InvalidArgumentException('disallow_paths must be a list of at most 200 paths.');
        }
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || $path[0] !== '/' || strlen($path) > 200 || preg_match('/[[:cntrl:][:space:]#]/', $path) === 1) {
                throw new \InvalidArgumentException('Each disallow path must start with "/", be at most 200 characters, and contain no spaces, control characters or "#".');
            }
        }
        if ($preset !== 'private' && in_array('/', $paths, true)) {
            throw new \InvalidArgumentException('Disallowing "/" for all crawlers is only allowed with the private preset.');
        }
        $rules = $policy['bot_rules'] ?? [];
        if (!is_array($rules) || count($rules) > 200) {
            throw new \InvalidArgumentException('bot_rules must be a map of bot token to allow/disallow.');
        }
        foreach ($rules as $token => $decision) {
            if (!is_string($token) || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $token) !== 1 || !in_array($decision, ['allow', 'disallow'], true)) {
                throw new \InvalidArgumentException('bot_rules must map a bot token to "allow" or "disallow".');
            }
        }

        // Count existing versions to increment policy version tag
        $table = $this->connection->prefixTable('crawler_policy_versions');
        $pdo = $this->connection->getPdo();
        $cntStmt = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
        $verNum = ($cntStmt !== false ? (int) $cntStmt->fetchColumn() : 0) + 1;

        $robotsTxt = $this->generator->generate($policy, $catalogue, $environment, $verNum);
        $lintErrors = $this->generator->lint($policy, $robotsTxt, $environment);

        if (!empty($lintErrors)) {
            throw new RuntimeException("Crawler policy validation failed: " . implode("; ", $lintErrors));
        }

        $id = $this->generateUlid();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        $pdo->beginTransaction();
        try {
            // Deactivate existing versions
            $pdo->exec("UPDATE `{$table}` SET `is_active` = 0 WHERE `is_active` = 1");

            $insert = $pdo->prepare("INSERT INTO `{$table}` (`id`, `preset`, `policy`, `robots_txt`, `changed_by`, `change_note`, `is_active`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
            $insert->execute([
                $id,
                $preset,
                json_encode($policy, JSON_UNESCAPED_SLASHES),
                $robotsTxt,
                $changedBy,
                $changeNote,
                $now,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'id' => $id,
            'preset' => $preset,
            'policy' => $policy,
            'robots_txt' => $robotsTxt,
            'is_active' => 1,
            'changed_by' => $changedBy,
            'change_note' => $changeNote,
            'created_at' => $now,
        ];
    }

    /**
     * Rollback to a specific version.
     *
     * @return array<string, mixed>
     */
    public function rollbackTo(string $versionId, ?string $userId = null): array
    {
        $table = $this->connection->prefixTable('crawler_policy_versions');
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `id` = ?");
        $stmt->execute([$versionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException("Crawler policy version '{$versionId}' not found.");
        }

        $policy = json_decode((string) $row['policy'], true);
        return $this->saveVersion($policy, 'production', $userId, "Rollback to version {$versionId}");
    }

    private function generateUlid(): string
    {
        $time = (int) (microtime(true) * 1000);
        $timeChars = '';
        $encoding = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        for ($i = 9; $i >= 0; $i--) {
            $timeChars = $encoding[$time % 32] . $timeChars;
            $time = intdiv($time, 32);
        }

        $random = '';
        for ($i = 0; $i < 16; $i++) {
            $random .= $encoding[random_int(0, 31)];
        }

        return $timeChars . $random;
    }
}
