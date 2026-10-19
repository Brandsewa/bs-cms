<?php

declare(strict_types=1);

namespace BsCms\Health;

final class SiteHealthPresenter
{
    /**
     * Format a single site health issue with canonical key set.
     *
     * @param array<string, mixed> $raw
     * @return array{
     *     rule_id: string,
     *     severity: string,
     *     category: string,
     *     message: string,
     *     entity_type: string,
     *     entity_id: string,
     *     entity_title: ?string,
     *     fix_hint: string
     * }
     */
    public static function presentIssue(array $raw): array
    {
        return [
            'rule_id' => (string) ($raw['rule_id'] ?? ''),
            'severity' => (string) ($raw['severity'] ?? 'warn'),
            'category' => (string) ($raw['category'] ?? 'seo'),
            'message' => (string) ($raw['message'] ?? ''),
            'entity_type' => (string) ($raw['entity_type'] ?? 'entry'),
            'entity_id' => (string) ($raw['entity_id'] ?? ''),
            'entity_title' => isset($raw['entity_title']) ? (string) $raw['entity_title'] : null,
            'fix_hint' => (string) ($raw['fix_hint'] ?? ''),
        ];
    }

    /**
     * Format the overall health scan report.
     *
     * @param array{
     *     issues: list<array<string, mixed>>,
     *     rules_checked: int,
     *     scanned_at: string
     * } $data
     * @return array{
     *     summary: array{
     *         total: int,
     *         errors: int,
     *         warnings: int,
     *         info: int,
     *         status: string
     *     },
     *     issues: list<array<string, mixed>>,
     *     rules_checked: int,
     *     scanned_at: string
     * }
     */
    public static function presentReport(array $data): array
    {
        $issues = array_map(fn (array $i): array => self::presentIssue($i), $data['issues']);

        $errors = 0;
        $warnings = 0;
        $info = 0;

        foreach ($issues as $issue) {
            match ($issue['severity']) {
                'error' => $errors++,
                'warn', 'warning' => $warnings++,
                default => $info++,
            };
        }

        $status = 'healthy';
        if ($errors > 0) {
            $status = 'critical';
        } elseif ($warnings > 0) {
            $status = 'warning';
        }

        return [
            'summary' => [
                'total' => count($issues),
                'errors' => $errors,
                'warnings' => $warnings,
                'info' => $info,
                'status' => $status,
            ],
            'issues' => $issues,
            'rules_checked' => $data['rules_checked'],
            'scanned_at' => $data['scanned_at'],
        ];
    }
}
