<?php

declare(strict_types=1);

namespace BsCms\PendingChanges;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Auth\SystemRoles;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Content\EntryRepository;
use BsCms\Database\Connection;
use BsCms\Operations\ConfirmTokenService;
use BsCms\Operations\Context;
use BsCms\Operations\Dispatcher;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\OperationMeta;
use BsCms\PendingChanges\Exceptions\ConflictException;
use BsCms\PendingChanges\Exceptions\NotFoundException;
use BsCms\Redirects\RedirectRepository;
use BsCms\Security\Ulid;
use PDO;
use Throwable;

final class PendingChangesService
{
    private ?Dispatcher $dispatcher = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Authorizer $authorizer,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?EntryRepository $entryRepository = null,
        private readonly ?RedirectRepository $redirectRepository = null,
        private readonly ?ConfirmTokenService $confirmTokens = null
    ) {
    }

    public function setDispatcher(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Create a pending change record from an intercepted live-affecting operation in approval_required mode.
     *
     * @param array<string, mixed> $input
     */
    public function createFromOperation(
        string $operation,
        array $input,
        Context $ctx,
        OperationMeta $meta
    ): string {
        $actor = $ctx->actor ?? Actor::anonymous();
        $id = Ulid::generate();
        $nowUtc = gmdate('Y-m-d H:i:s.v');
        $expiresAt = gmdate('Y-m-d H:i:s.v', time() + (7 * 86400)); // 7-day expiry

        $actorLabel = $actor->getDisplayLabel();
        $onBehalfOf = $actor->onBehalfOf ?? $actor->id;
        $oauthGrantId = $actor->type === Actor::TYPE_OAUTH ? $actor->id : null;
        $apiTokenId = $actor->type === Actor::TYPE_API_TOKEN ? $actor->id : null;

        // Determine subject type and subject id
        $subjectType = 'operation';
        $subjectId = null;

        if (isset($input['collection']) && is_string($input['collection'])) {
            $subjectType = 'entry';
            $subjectId = isset($input['id']) && is_string($input['id']) ? $input['id'] : null;
        } elseif (str_contains($operation, 'redirect')) {
            $subjectType = 'redirect';
            $subjectId = isset($input['from_path']) && is_string($input['from_path']) ? $input['from_path'] : null;
        } elseif (str_contains($operation, 'term')) {
            $subjectType = 'term';
            $subjectId = isset($input['id']) && is_string($input['id']) ? $input['id'] : ($input['name'] ?? null);
        } elseif (str_contains($operation, 'media')) {
            $subjectType = 'media';
            $subjectId = isset($input['id']) && is_string($input['id']) ? $input['id'] : null;
        } elseif ($meta->isSchema) {
            $subjectType = 'schema';
            $subjectId = isset($input['handle']) && is_string($input['handle']) ? $input['handle'] : null;
        }

        // Generate descriptive diff
        $diff = $this->buildDiff($operation, $input);

        $table = $this->connection->prefixTable('pending_changes');
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare("INSERT INTO `{$table}` (
            `id`, `actor_type`, `actor_label`, `oauth_grant_id`, `api_token_id`, `on_behalf_of`,
            `operation`, `subject_type`, `subject_id`, `payload`, `diff`, `status`,
            `decided_by`, `decided_at`, `decision_note`, `expires_at`, `created_at`
        ) VALUES (
            :id, :actor_type, :actor_label, :oauth_grant_id, :api_token_id, :on_behalf_of,
            :operation, :subject_type, :subject_id, :payload, :diff, 'pending',
            NULL, NULL, NULL, :expires_at, :created_at
        )");

        $stmt->execute([
            'id' => $id,
            'actor_type' => $actor->type,
            'actor_label' => $actorLabel,
            'oauth_grant_id' => $oauthGrantId,
            'api_token_id' => $apiTokenId,
            'on_behalf_of' => $onBehalfOf,
            'operation' => $operation,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => json_encode($input, JSON_THROW_ON_ERROR),
            'diff' => json_encode($diff, JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt,
            'created_at' => $nowUtc,
        ]);

        // In-app notifications to Admins & Owners
        $this->notifyPrivilegedUsers($id, $operation, $actorLabel);

        // Audit log entry
        if ($this->auditLogger !== null) {
            try {
                $this->auditLogger->record(
                    actor: $actor,
                    action: 'pending_change.created',
                    subject: 'pending_changes',
                    summary: "AI agent submitted operation '{$operation}' for human approval (pending change {$id})",
                    diff: [
                        'pending_change_id' => $id,
                        'operation' => $operation,
                        'actor_label' => $actorLabel,
                        'on_behalf_of' => $onBehalfOf,
                    ],
                    result: 'success',
                    requestId: $ctx->requestId,
                    ip: $ctx->ip,
                    userAgent: $ctx->userAgent,
                    subjectId: $id
                );
            } catch (Throwable) {
                // Ignore audit logger error
            }
        }

        return $id;
    }

    /**
     * List pending changes with filtering, automatic expiry, and pagination.
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        // 1. Auto-expire stale records
        $this->expireStaleRecords();

        $table = $this->connection->prefixTable('pending_changes');
        $pdo = $this->connection->getPdo();

        $where = [];
        $params = [];

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $where[] = "`status` = :status";
            $params['status'] = $filters['status'];
        }

        if (isset($filters['operation']) && is_string($filters['operation']) && $filters['operation'] !== '') {
            $where[] = "`operation` = :operation";
            $params['operation'] = $filters['operation'];
        }

        if (isset($filters['subject_type']) && is_string($filters['subject_type']) && $filters['subject_type'] !== '') {
            $where[] = "`subject_type` = :subject_type";
            $params['subject_type'] = $filters['subject_type'];
        }

        if (isset($filters['on_behalf_of']) && is_string($filters['on_behalf_of']) && $filters['on_behalf_of'] !== '') {
            $where[] = "`on_behalf_of` = :on_behalf_of";
            $params['on_behalf_of'] = $filters['on_behalf_of'];
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Items
        $limitClamped = max(1, min(100, $limit));
        $offsetClamped = max(0, $offset);

        $stmt = $pdo->prepare("SELECT * FROM `{$table}` {$whereSql} ORDER BY `created_at` DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limitClamped, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offsetClamped, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = array_map([PendingChangePresenter::class, 'present'], $rows);

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Get a single pending change record by ID.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $table = $this->connection->prefixTable('pending_changes');
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($record)) {
            return null;
        }

        // Check if expired
        if ($record['status'] === 'pending' && strtotime((string) $record['expires_at']) < time()) {
            $pdo->prepare("UPDATE `{$table}` SET `status` = 'expired' WHERE `id` = :id")->execute(['id' => $id]);
            $record['status'] = 'expired';
        }

        return PendingChangePresenter::present($record);
    }

    /**
     * Approve and apply a pending change using the human approver's authority through the Dispatcher.
     *
     * @return array<string, mixed>
     * @throws NotFoundException
     * @throws ConflictException
     * @throws ForbiddenException
     */
    public function approve(
        string $id,
        Actor $approver,
        ?string $note = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): array {
        $table = $this->connection->prefixTable('pending_changes');
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($record)) {
            throw new NotFoundException("Pending change '{$id}' not found.");
        }

        // Status checks
        if ($record['status'] === 'approved') {
            throw new ConflictException("Pending change '{$id}' has already been approved.");
        }

        if ($record['status'] === 'rejected') {
            throw new ConflictException("Pending change '{$id}' was rejected and cannot be approved.");
        }

        if ($record['status'] === 'expired' || strtotime((string) $record['expires_at']) < time()) {
            if ($record['status'] !== 'expired') {
                $pdo->prepare("UPDATE `{$table}` SET `status` = 'expired' WHERE `id` = :id")->execute(['id' => $id]);
            }
            throw new ConflictException("Pending change '{$id}' has expired.");
        }

        $operation = (string) $record['operation'];
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $record['payload'], true);

        // 1. Authorize approving user against the operation
        $this->authorizeApprover($approver, $operation, $payload);

        // 2. Validate target conflict (stale state detection)
        $this->assertNoTargetConflict($operation, $payload);

        // 3. Re-apply the operation through Dispatcher as the approving human
        if ($this->dispatcher === null) {
            throw new ConflictException("Dispatcher is not configured to execute approved operations.");
        }

        $approverCtx = new Context(
            actor: $approver,
            requestId: Ulid::generate(),
            ip: $ip,
            userAgent: $userAgent,
            clock: new \DateTimeImmutable(),
            connection: $this->connection
        );

        if ($this->confirmTokens !== null) {
            $operationObj = $this->dispatcher->getOperation($operation, $approverCtx);
            $meta = $operationObj::describe();
            if ($meta->destructive || ($meta->bulk && isset($payload['ids']) && is_array($payload['ids']) && count($payload['ids']) > 10)) {
                $token = $this->confirmTokens->generate($approver->id, $operation, $payload);
                $approverCtx = $approverCtx->withConfirmToken($token);
            }
        }

        $this->dispatcher->run($operation, $payload, $approverCtx);

        // 4. Mark pending change as approved
        $nowUtc = gmdate('Y-m-d H:i:s.v');
        $upd = $pdo->prepare("UPDATE `{$table}`
            SET `status` = 'approved',
                `decided_by` = :decided_by,
                `decided_at` = :decided_at,
                `decision_note` = :note
            WHERE `id` = :id");
        $upd->execute([
            'id' => $id,
            'decided_by' => $approver->id,
            'decided_at' => $nowUtc,
            'note' => $note,
        ]);

        // 5. Audit log
        if ($this->auditLogger !== null) {
            try {
                $this->auditLogger->record(
                    actor: $approver,
                    action: 'pending_change.approved',
                    subject: 'pending_changes',
                    summary: "Approved and applied pending change '{$id}' (operation: {$operation})",
                    diff: [
                        'pending_change_id' => $id,
                        'operation' => $operation,
                        'approver_id' => $approver->id,
                        'decision_note' => $note,
                    ],
                    result: 'success',
                    requestId: $approverCtx->requestId,
                    ip: $ip,
                    userAgent: $userAgent,
                    subjectId: $id
                );
            } catch (Throwable) {
                // Ignore audit logger error
            }
        }

        $record['status'] = 'approved';
        $record['decided_by'] = $approver->id;
        $record['decided_at'] = $nowUtc;
        $record['decision_note'] = $note;

        return PendingChangePresenter::present($record);
    }

    /**
     * Reject a pending change.
     *
     * @return array<string, mixed>
     * @throws NotFoundException
     * @throws ConflictException
     * @throws ForbiddenException
     */
    public function reject(
        string $id,
        Actor $rejecter,
        ?string $note = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): array {
        $table = $this->connection->prefixTable('pending_changes');
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($record)) {
            throw new NotFoundException("Pending change '{$id}' not found.");
        }

        if ($record['status'] !== 'pending') {
            throw new ConflictException("Pending change '{$id}' is already {$record['status']}.");
        }

        $nowUtc = gmdate('Y-m-d H:i:s.v');
        $upd = $pdo->prepare("UPDATE `{$table}`
            SET `status` = 'rejected',
                `decided_by` = :decided_by,
                `decided_at` = :decided_at,
                `decision_note` = :note
            WHERE `id` = :id");
        $upd->execute([
            'id' => $id,
            'decided_by' => $rejecter->id,
            'decided_at' => $nowUtc,
            'note' => $note,
        ]);

        if ($this->auditLogger !== null) {
            try {
                $this->auditLogger->record(
                    actor: $rejecter,
                    action: 'pending_change.rejected',
                    subject: 'pending_changes',
                    summary: "Rejected pending change '{$id}' (operation: {$record['operation']})",
                    diff: [
                        'pending_change_id' => $id,
                        'operation' => $record['operation'],
                        'rejecter_id' => $rejecter->id,
                        'decision_note' => $note,
                    ],
                    result: 'success',
                    ip: $ip,
                    userAgent: $userAgent,
                    subjectId: $id
                );
            } catch (Throwable) {
                // Ignore audit logger error
            }
        }

        $record['status'] = 'rejected';
        $record['decided_by'] = $rejecter->id;
        $record['decided_at'] = $nowUtc;
        $record['decision_note'] = $note;

        return PendingChangePresenter::present($record);
    }

    /**
     * Expire all pending changes that have passed their expiration timestamp.
     */
    public function expireStaleRecords(): int
    {
        $table = $this->connection->prefixTable('pending_changes');
        $pdo = $this->connection->getPdo();
        $nowUtc = gmdate('Y-m-d H:i:s.v');

        $stmt = $pdo->prepare("UPDATE `{$table}` SET `status` = 'expired' WHERE `status` = 'pending' AND `expires_at` < :now");
        $stmt->execute(['now' => $nowUtc]);

        return $stmt->rowCount();
    }

    /**
     * Authorize human approver to ensure they hold permissions to execute the underlying operation.
     *
     * @param array<string, mixed> $payload
     * @throws ForbiddenException
     */
    private function authorizeApprover(Actor $approver, string $operation, array $payload): void
    {
        if ($approver->isSystem() || $approver->hasRole(SystemRoles::ROLE_OWNER)) {
            return;
        }

        if (str_starts_with($operation, 'publish_') || str_starts_with($operation, 'unpublish_') || str_starts_with($operation, 'schedule_')) {
            $col = (string) ($payload['collection'] ?? '*');
            $dec = $this->authorizer->can($approver, 'publish', new Subject('collection', $col));
            if (!$dec->allowed) {
                throw new ForbiddenException("Approver does not have permission to publish content in collection '{$col}': {$dec->reason}");
            }
        } elseif (str_starts_with($operation, 'delete_')) {
            $col = (string) ($payload['collection'] ?? '*');
            $dec = $this->authorizer->can($approver, 'delete', new Subject('collection', $col));
            if (!$dec->allowed) {
                throw new ForbiddenException("Approver does not have permission to delete content in collection '{$col}': {$dec->reason}");
            }
        } elseif (str_starts_with($operation, 'update_')) {
            $col = (string) ($payload['collection'] ?? '*');
            $dec = $this->authorizer->can($approver, 'update', new Subject('collection', $col));
            if (!$dec->allowed) {
                throw new ForbiddenException("Approver does not have permission to update content in collection '{$col}': {$dec->reason}");
            }
        } elseif (str_contains($operation, 'redirect')) {
            $dec = $this->authorizer->can($approver, 'create', new Subject('redirect', '*'));
            if (!$dec->allowed) {
                throw new ForbiddenException("Approver does not have permission to manage redirects: {$dec->reason}");
            }
        } elseif (str_contains($operation, 'schema')) {
            $dec = $this->authorizer->can($approver, 'manage', new Subject('schema', '*'));
            if (!$dec->allowed) {
                throw new ForbiddenException("Approver does not have permission to modify schema: {$dec->reason}");
            }
        }
    }

    /**
     * Check for stale target / conflict before applying an approved change.
     *
     * @param array<string, mixed> $payload
     * @throws ConflictException
     */
    private function assertNoTargetConflict(string $operation, array $payload): void
    {
        if ($this->entryRepository !== null && isset($payload['collection'], $payload['id'])) {
            $collection = (string) $payload['collection'];
            $id = (string) $payload['id'];
            $entry = $this->entryRepository->find($collection, $id);

            if ($entry === null) {
                throw new ConflictException("Target entry '{$id}' in collection '{$collection}' no longer exists.");
            }

            if (isset($payload['version']) && is_int($payload['version'])) {
                $currentVersion = (int) ($entry['version'] ?? 1);
                if ($currentVersion > $payload['version']) {
                    throw new ConflictException("Conflict: target entry has been updated to version {$currentVersion} since this change was requested (expected version {$payload['version']}).");
                }
            }
        }

        if ($this->redirectRepository !== null && $operation === 'create_redirect') {
            $fromPath = (string) ($payload['from_path'] ?? '');
            if ($fromPath !== '' && $this->redirectRepository->match($fromPath) !== null) {
                throw new ConflictException("Conflict: redirect for path '{$fromPath}' already exists.");
            }
        }
    }

    /**
     * Build structured human-readable diff payload for review.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function buildDiff(string $operation, array $input): array
    {
        if ($operation === 'publish_entry') {
            return [
                'action' => 'publish',
                'collection' => $input['collection'] ?? null,
                'id' => $input['id'] ?? null,
                'target_status' => 'published',
            ];
        }

        if ($operation === 'unpublish_entry') {
            return [
                'action' => 'unpublish',
                'collection' => $input['collection'] ?? null,
                'id' => $input['id'] ?? null,
                'target_status' => 'draft',
            ];
        }

        if ($operation === 'schedule_entry') {
            return [
                'action' => 'schedule',
                'collection' => $input['collection'] ?? null,
                'id' => $input['id'] ?? null,
                'publish_at' => $input['publish_at'] ?? null,
            ];
        }

        if ($operation === 'create_redirect') {
            return [
                'action' => 'create_redirect',
                'from_path' => $input['from_path'] ?? null,
                'to_path' => $input['to_path'] ?? null,
                'status_code' => $input['status_code'] ?? 301,
            ];
        }

        if ($operation === 'delete_entry') {
            return [
                'action' => 'delete',
                'collection' => $input['collection'] ?? null,
                'id' => $input['id'] ?? null,
            ];
        }

        return $input;
    }

    /**
     * Notify Admin and Owner users of a new pending change awaiting review.
     */
    private function notifyPrivilegedUsers(string $pendingId, string $operation, string $actorLabel): void
    {
        try {
            $usersTable = $this->connection->prefixTable('users');
            $userRolesTable = $this->connection->prefixTable('user_roles');
            $rolesTable = $this->connection->prefixTable('roles');
            $notifTable = $this->connection->prefixTable('notifications');
            $pdo = $this->connection->getPdo();

            // Find all active owners and admins
            $stmt = $pdo->query("SELECT DISTINCT u.`id`
                FROM `{$usersTable}` u
                JOIN `{$userRolesTable}` ur ON u.`id` = ur.`user_id`
                JOIN `{$rolesTable}` r ON ur.`role_id` = r.`id`
                WHERE r.`handle` IN ('owner', 'admin') AND u.`status` = 'active' AND u.`deleted_at` IS NULL");

            $userIds = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            $nowUtc = gmdate('Y-m-d H:i:s.v');

            $insertStmt = $pdo->prepare("INSERT INTO `{$notifTable}`
                (`id`, `user_id`, `type`, `title`, `body`, `link`, `read_at`, `created_at`)
                VALUES (:id, :user_id, 'ai_change_pending', :title, :body, :link, NULL, :created_at)");

            foreach ($userIds as $uid) {
                $insertStmt->execute([
                    'id' => Ulid::generate(),
                    'user_id' => $uid,
                    'title' => "Pending approval: {$operation} requested by {$actorLabel}",
                    'body' => "An AI agent requested to execute '{$operation}'. Please review and approve or reject.",
                    'link' => "/admin/pending-changes/{$pendingId}",
                    'created_at' => $nowUtc,
                ]);
            }
        } catch (Throwable) {
            // Notifications must not block transaction
        }
    }
}
