<?php

declare(strict_types=1);

namespace BsCms\Blocks;

final class BlockRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $blocks = [];

    public function __construct(
        private readonly string $storageDir
    ) {
        $this->loadFromDisk();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->blocks;
    }

    public function has(string $name): bool
    {
        return isset($this->blocks[$name]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        return $this->blocks[$name] ?? null;
    }

    /**
     * Register a block definition and persist to disk.
     *
     * @param array<string, mixed> $definition
     */
    public function register(array $definition): void
    {
        $name = (string) ($definition['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('Block definition requires a valid name.');
        }

        $this->blocks[$name] = $definition;
        $this->persistBlock($name, $definition);
    }

    /**
     * Sync blocks from a given directory (e.g. starter/src/blocks).
     *
     * @return int Number of blocks synced
     */
    public function syncFromDirectory(string $sourceDir): int
    {
        if (!is_dir($sourceDir)) {
            return 0;
        }

        $count = 0;
        $files = glob(rtrim($sourceDir, '/\\') . '/*.json') ?: [];
        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['name'])) {
                continue;
            }
            $this->register($data);
            $count++;
        }

        return $count;
    }

    private function loadFromDisk(): void
    {
        if (!is_dir($this->storageDir)) {
            return;
        }

        $files = glob(rtrim($this->storageDir, '/\\') . '/*.json') ?: [];
        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data['name'])) {
                $this->blocks[(string) $data['name']] = $data;
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function persistBlock(string $name, array $definition): void
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name);
        $target = rtrim($this->storageDir, '/\\') . '/' . $safeName . '.json';
        file_put_contents($target, json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
