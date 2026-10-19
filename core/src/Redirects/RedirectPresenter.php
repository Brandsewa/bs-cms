<?php

declare(strict_types=1);

namespace BsCms\Redirects;

final class RedirectPresenter
{
    /**
     * Canonical key set for public or administrative redirect payloads.
     *
     * @param array<string, mixed> $raw
     * @return array{
     *     id: string,
     *     from_path: string,
     *     to_path: string,
     *     status_code: int,
     *     match_type: string,
     *     is_auto: bool,
     *     hits: int,
     *     last_hit_at: ?string,
     *     created_by: ?string,
     *     created_at: string
     * }
     */
    public static function present(array $raw): array
    {
        return [
            'id' => (string) ($raw['id'] ?? ''),
            'from_path' => (string) ($raw['from_path'] ?? ''),
            'to_path' => (string) ($raw['to_path'] ?? ''),
            'status_code' => (int) ($raw['status_code'] ?? 301),
            'match_type' => (string) ($raw['match_type'] ?? 'exact'),
            'is_auto' => (bool) ($raw['is_auto'] ?? false),
            'hits' => (int) ($raw['hits'] ?? 0),
            'last_hit_at' => isset($raw['last_hit_at']) ? (string) $raw['last_hit_at'] : null,
            'created_by' => isset($raw['created_by']) ? (string) $raw['created_by'] : null,
            'created_at' => (string) ($raw['created_at'] ?? ''),
        ];
    }
}
