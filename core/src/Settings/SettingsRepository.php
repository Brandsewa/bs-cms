<?php

declare(strict_types=1);

namespace BsCms\Settings;

use BsCms\Cache\CacheInterface;
use BsCms\Database\Connection;
use BsCms\Security\Crypto;
use BsCms\Support\Clock;
use BsCms\Support\Json;
use RuntimeException;

/**
 * SettingsRepository: typed get/set on the `settings` table.
 * Encrypts `is_secret` values with Crypto (libsodium secretbox),
 * caches non-secret settings per request and in CacheInterface.
 */
final class SettingsRepository
{
    private Connection $connection;
    private ?Crypto $crypto;
    private ?CacheInterface $cache;
    /** @var array<string, mixed> Request-scoped in-memory cache */
    private array $runtimeCache = [];

    public function __construct(Connection $connection, ?Crypto $crypto = null, ?CacheInterface $cache = null)
    {
        $this->connection = $connection;
        $this->crypto = $crypto;
        $this->cache = $cache;
    }

    /**
     * Retrieve a setting value by handle.
     *
     * @param mixed $default Returned if handle does not exist.
     */
    public function get(string $handle, mixed $default = null): mixed
    {
        if (array_key_exists($handle, $this->runtimeCache)) {
            return $this->runtimeCache[$handle];
        }

        // Check external cache if configured
        if ($this->cache !== null && $this->cache->has("setting:{$handle}")) {
            $cached = $this->cache->get("setting:{$handle}");
            $this->runtimeCache[$handle] = $cached;
            return $cached;
        }

        $row = $this->connection->table('settings')
            ->where('handle', '=', $handle)
            ->first();

        if ($row === null) {
            return $default;
        }

        /** @var string $rawJson */
        $rawJson = is_string($row['value']) ? $row['value'] : Json::encode($row['value']);
        $value = Json::decode($rawJson);
        $isSecret = (bool) $row['is_secret'];

        if ($isSecret) {
            if ($this->crypto === null) {
                throw new RuntimeException('Cannot decrypt secret setting without Crypto service.');
            }
            if (is_string($value)) {
                $value = $this->crypto->decrypt($value);
            }
        } else {
            // Cache non-secret settings in cache interface
            $this->cache?->set("setting:{$handle}", $value, 3600);
        }

        $this->runtimeCache[$handle] = $value;
        return $value;
    }

    /**
     * Set a setting value.
     */
    public function set(string $handle, mixed $value, bool $isSecret = false, ?string $updatedBy = null): void
    {
        $storedValue = $value;

        if ($isSecret) {
            if ($this->crypto === null) {
                throw new RuntimeException('Cannot encrypt secret setting without Crypto service.');
            }
            $storedValue = $this->crypto->encrypt(is_string($value) ? $value : Json::encode($value));
        }

        $now = Clock::sql(Clock::now());
        $jsonPayload = Json::encode($storedValue);

        $exists = $this->connection->table('settings')->where('handle', '=', $handle)->count() > 0;

        if ($exists) {
            $this->connection->table('settings')
                ->where('handle', '=', $handle)
                ->update([
                    'value' => $jsonPayload,
                    'is_secret' => $isSecret ? 1 : 0,
                    'updated_by' => $updatedBy,
                    'updated_at' => $now,
                ]);
        } else {
            $this->connection->table('settings')->insert([
                'handle' => $handle,
                'value' => $jsonPayload,
                'is_secret' => $isSecret ? 1 : 0,
                'updated_by' => $updatedBy,
                'updated_at' => $now,
            ]);
        }

        // Invalidate caches
        unset($this->runtimeCache[$handle]);
        $this->cache?->delete("setting:{$handle}");
    }

    public function delete(string $handle): bool
    {
        $deleted = $this->connection->table('settings')->where('handle', '=', $handle)->delete();
        unset($this->runtimeCache[$handle]);
        $this->cache?->delete("setting:{$handle}");
        return $deleted > 0;
    }
}
