<?php

declare(strict_types=1);

namespace BsCms\PendingChanges;

/**
 * Strict output presenter for pending changes records.
 * Strips internal tokens/hashes and presents a deterministic schema.
 */
final class PendingChangePresenter
{
    /**
     * @param array<string, mixed> $record
     * @return array{
     *     id: string,
     *     actor_type: string,
     *     actor_label: string,
     *     oauth_grant_id: ?string,
     *     api_token_id: ?string,
     *     on_behalf_of: string,
     *     operation: string,
     *     subject_type: string,
     *     subject_id: ?string,
     *     payload: array<string, mixed>,
     *     diff: ?array<string, mixed>,
     *     status: string,
     *     decided_by: ?string,
     *     decided_at: ?string,
     *     decision_note: ?string,
     *     expires_at: string,
     *     created_at: string
     * }
     */
    public static function present(array $record): array
    {
        $payload = [];
        if (isset($record['payload'])) {
            if (is_array($record['payload'])) {
                $payload = $record['payload'];
            } elseif (is_string($record['payload'])) {
                $decoded = json_decode($record['payload'], true);
                $payload = is_array($decoded) ? $decoded : [];
            }
        }

        $diff = null;
        if (isset($record['diff'])) {
            if (is_array($record['diff'])) {
                $diff = $record['diff'];
            } elseif (is_string($record['diff'])) {
                $decoded = json_decode($record['diff'], true);
                $diff = is_array($decoded) ? $decoded : null;
            }
        }

        return [
            'id' => (string) $record['id'],
            'actor_type' => (string) $record['actor_type'],
            'actor_label' => (string) $record['actor_label'],
            'oauth_grant_id' => isset($record['oauth_grant_id']) ? (string) $record['oauth_grant_id'] : null,
            'api_token_id' => isset($record['api_token_id']) ? (string) $record['api_token_id'] : null,
            'on_behalf_of' => (string) $record['on_behalf_of'],
            'operation' => (string) $record['operation'],
            'subject_type' => (string) $record['subject_type'],
            'subject_id' => isset($record['subject_id']) ? (string) $record['subject_id'] : null,
            'payload' => $payload,
            'diff' => $diff,
            'status' => (string) $record['status'],
            'decided_by' => isset($record['decided_by']) ? (string) $record['decided_by'] : null,
            'decided_at' => isset($record['decided_at']) ? (string) $record['decided_at'] : null,
            'decision_note' => isset($record['decision_note']) ? (string) $record['decision_note'] : null,
            'expires_at' => (string) $record['expires_at'],
            'created_at' => (string) $record['created_at'],
        ];
    }
}
