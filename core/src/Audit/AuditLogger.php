<?php

declare(strict_types=1);

namespace BsCms\Audit;

use BsCms\Auth\Actor;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Log\Redactor;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use DateTimeImmutable;
use PDO;

/**
 * Service for recording and querying security and operational audit logs.
 * Guarantees that secrets and credentials in diff payloads are automatically redacted.
 */
final class AuditLogger
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Record an audit log entry.
     * Sensitive fields in $diff are automatically redacted before storage.
     *
     * @param array<string, mixed>|null $diff
     */
    public function record(
        Actor $actor,
        string $action,
        Subject|string $subject,
        ?string $summary = null,
        ?array $diff = null,
        string $result = 'success',
        ?string $requestId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $subjectId = null,
        ?string $collectionKey = null
    ): int {
        $subjectType = $subject instanceof Subject ? $subject->type : $subject;
        /** @var array<string, mixed>|null $cleanDiff */
        $cleanDiff = $diff !== null ? Redactor::redact($diff) : null;
        $diffJson = $cleanDiff !== null ? json_encode($cleanDiff, JSON_THROW_ON_ERROR) : null;

        $table = $this->connection->prefixTable('audit_log');
        $pdo = $this->connection->getPdo();
        $now = Clock::sql(Clock::now());

        $reqId = $requestId ?? Ulid::generate();

        $stmt = $pdo->prepare("INSERT INTO `{$table}` (
            `request_id`, `actor_type`, `actor_id`, `actor_label`, `on_behalf_of`,
            `action`, `subject_type`, `subject_id`, `collection_key`, `summary`,
            `diff`, `ip`, `user_agent`, `result`, `created_at`
        ) VALUES (
            :request_id, :actor_type, :actor_id, :actor_label, :on_behalf_of,
            :action, :subject_type, :subject_id, :collection_key, :summary,
            :diff, :ip, :user_agent, :result, :created_at
        )");

        $stmt->execute([
            'request_id' => $reqId,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'actor_label' => $actor->label,
            'on_behalf_of' => $actor->onBehalfOf,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'collection_key' => $collectionKey,
            'summary' => $summary,
            'diff' => $diffJson,
            'ip' => $ip,
            'user_agent' => $userAgent !== null ? substr($userAgent, 0, 255) : null,
            'result' => $result,
            'created_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Query audit logs with optional filters and cursor pagination.
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<AuditEntry>, next_cursor: int|null}
     */
    public function query(array $filters = [], int $limit = 50, ?int $beforeId = null): array
    {
        $table = $this->connection->prefixTable('audit_log');
        $conditions = [];
        $params = [];

        if (!empty($filters['actor_type'])) {
            $conditions[] = '`actor_type` = :actor_type';
            $params['actor_type'] = $filters['actor_type'];
        }
        if (!empty($filters['actor_id'])) {
            $conditions[] = '`actor_id` = :actor_id';
            $params['actor_id'] = $filters['actor_id'];
        }
        if (!empty($filters['action'])) {
            $conditions[] = '`action` = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['subject_type'])) {
            $conditions[] = '`subject_type` = :subject_type';
            $params['subject_type'] = $filters['subject_type'];
        }
        if (!empty($filters['subject_id'])) {
            $conditions[] = '`subject_id` = :subject_id';
            $params['subject_id'] = $filters['subject_id'];
        }
        if (!empty($filters['result'])) {
            $conditions[] = '`result` = :result';
            $params['result'] = $filters['result'];
        }
        if ($beforeId !== null) {
            $conditions[] = '`id` < :before_id';
            $params['before_id'] = $beforeId;
        }

        $whereClause = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $fetchLimit = $limit + 1;

        $stmt = $this->connection->getPdo()->prepare("SELECT * FROM `{$table}` {$whereClause} ORDER BY `id` DESC LIMIT {$fetchLimit}");
        $stmt->execute($params);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = [];
        $nextCursor = null;
        foreach ($rows as $r) {
            $diff = null;
            if (!empty($r['diff'])) {
                /** @var array<string, mixed>|null $diff */
                $diff = is_string($r['diff']) ? json_decode($r['diff'], true) : (array) $r['diff'];
            }

            $entry = new AuditEntry(
                id: (int) $r['id'],
                requestId: (string) $r['request_id'],
                actorType: (string) $r['actor_type'],
                actorId: $r['actor_id'] !== null ? (string) $r['actor_id'] : null,
                actorLabel: $r['actor_label'] !== null ? (string) $r['actor_label'] : null,
                onBehalfOf: $r['on_behalf_of'] !== null ? (string) $r['on_behalf_of'] : null,
                action: (string) $r['action'],
                subjectType: (string) $r['subject_type'],
                subjectId: $r['subject_id'] !== null ? (string) $r['subject_id'] : null,
                collectionKey: $r['collection_key'] !== null ? (string) $r['collection_key'] : null,
                summary: $r['summary'] !== null ? (string) $r['summary'] : null,
                diff: $diff,
                ip: $r['ip'] !== null ? (string) $r['ip'] : null,
                userAgent: $r['user_agent'] !== null ? (string) $r['user_agent'] : null,
                result: (string) $r['result'],
                createdAt: new DateTimeImmutable((string) $r['created_at'])
            );
            $items[] = $entry;
            $nextCursor = $entry->id;
        }

        return [
            'items' => $items,
            'next_cursor' => $hasMore ? $nextCursor : null,
        ];
    }

    /**
     * Find an audit entry by its primary key ID.
     */
    public function findById(int $id): ?AuditEntry
    {
        $table = $this->connection->prefixTable('audit_log');
        $stmt = $this->connection->getPdo()->prepare("SELECT * FROM `{$table}` WHERE `id` = :id");
        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $r */
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($r === false) {
            return null;
        }

        $diff = null;
        if (!empty($r['diff'])) {
            /** @var array<string, mixed>|null $diff */
            $diff = is_string($r['diff']) ? json_decode($r['diff'], true) : (array) $r['diff'];
        }

        return new AuditEntry(
            id: (int) $r['id'],
            requestId: (string) $r['request_id'],
            actorType: (string) $r['actor_type'],
            actorId: $r['actor_id'] !== null ? (string) $r['actor_id'] : null,
            actorLabel: $r['actor_label'] !== null ? (string) $r['actor_label'] : null,
            onBehalfOf: $r['on_behalf_of'] !== null ? (string) $r['on_behalf_of'] : null,
            action: (string) $r['action'],
            subjectType: (string) $r['subject_type'],
            subjectId: $r['subject_id'] !== null ? (string) $r['subject_id'] : null,
            collectionKey: $r['collection_key'] !== null ? (string) $r['collection_key'] : null,
            summary: $r['summary'] !== null ? (string) $r['summary'] : null,
            diff: $diff,
            ip: $r['ip'] !== null ? (string) $r['ip'] : null,
            userAgent: $r['user_agent'] !== null ? (string) $r['user_agent'] : null,
            result: (string) $r['result'],
            createdAt: new \DateTimeImmutable((string) $r['created_at'])
        );
    }

    /**
     * Prune audit log entries older than specified retention period (default 180 days).
     */
    public function prune(int $retentionDays = 180): int
    {
        $table = $this->connection->prefixTable('audit_log');
        $cutoff = Clock::sql(Clock::now()->modify("-{$retentionDays} days"));

        $stmt = $this->connection->getPdo()->prepare("DELETE FROM `{$table}` WHERE `created_at` < :cutoff");
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }
}
