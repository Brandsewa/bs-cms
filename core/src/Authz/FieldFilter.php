<?php

declare(strict_types=1);

namespace BsCms\Authz;

/**
 * Filters data fields according to role permission field rules (allow/deny lists for read and write).
 */
final class FieldFilter
{
    /**
     * Filter associative data array according to field specifications for the given operation ('read' or 'write').
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $fieldsRule
     * @return array<string, mixed>
     */
    public function filter(array $data, ?array $fieldsRule, string $operation = 'read'): array
    {
        if ($fieldsRule === null || empty($fieldsRule)) {
            return $data;
        }

        // Check if config has operation-specific keys ('read' or 'write')
        $config = $fieldsRule;
        if (isset($fieldsRule[$operation]) && is_array($fieldsRule[$operation])) {
            /** @var array<string, mixed> $config */
            $config = $fieldsRule[$operation];
        }

        /** @var list<string>|null $allowList */
        $allowList = isset($config['allow']) && is_array($config['allow']) ? $config['allow'] : null;
        /** @var list<string>|null $denyList */
        $denyList = isset($config['deny']) && is_array($config['deny']) ? $config['deny'] : null;

        $filtered = $data;

        // Apply allow list: keep only allowed keys
        if ($allowList !== null) {
            $allowMap = array_flip($allowList);
            $filtered = array_intersect_key($filtered, $allowMap);
        }

        // Apply deny list: remove denied keys
        if ($denyList !== null) {
            foreach ($denyList as $deniedKey) {
                unset($filtered[$deniedKey]);
            }
        }

        return $filtered;
    }
}
