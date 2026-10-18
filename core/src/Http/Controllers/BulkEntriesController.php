<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Content\EntryRepository;
use BsCms\Database\Connection;
use BsCms\Operations\ConfirmTokenService;
use BsCms\Schema\CollectionRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class BulkEntriesController extends BaseController
{
    private const ALLOWED_OPERATIONS = [
        'publish',
        'unpublish',
        'trash',
        'purge',
        'set_author',
        'duplicate',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly CollectionRegistry $registry,
        private readonly EntryRepository $repository,
        private readonly Authorizer $authorizer,
        private readonly ConfirmTokenService $confirmTokenService,
        private readonly AuditLogger $auditLogger
    ) {
    }

    /**
     * @param array<string, mixed> $args
     */
    public function bulk(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? '');
        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $body = $this->getBody($request);
        $operation = (string) ($body['operation'] ?? '');
        $ids = (array) ($body['ids'] ?? []);
        $params = (array) ($body['params'] ?? []);

        if (!in_array($operation, self::ALLOWED_OPERATIONS, true)) {
            return $this->problem(422, 'Invalid Operation', "Bulk operation '{$operation}' is not supported.", null, [
                'operation' => ["Operation must be one of: " . implode(', ', self::ALLOWED_OPERATIONS)],
            ]);
        }

        if (empty($ids)) {
            return $this->problem(422, 'Empty IDs', 'At least one entry ID must be provided.', null, [
                'ids' => ['Entry IDs array cannot be empty.'],
            ]);
        }

        $actor = $this->getActor($request);

        // Required action permission mapping
        $requiredAction = match ($operation) {
            'publish', 'unpublish' => 'publish',
            'trash', 'purge' => 'delete',
            'set_author' => 'update',
            'duplicate' => 'create',
        };

        $permError = $this->requirePermission($this->authorizer, $request, $requiredAction, new Subject('collection', $handle));
        if ($permError !== null) {
            return $permError;
        }

        // Two-step confirmation for bulk operations with > 10 items
        if (count($ids) > 10) {
            $confirmToken = (string) ($body['confirm_token'] ?? $request->getHeaderLine('X-Confirm-Token'));
            $canonicalInput = [
                'operation' => $operation,
                'ids' => array_values(array_map('strval', $ids)),
                'params' => $params,
            ];

            if ($confirmToken === '') {
                $token = $this->confirmTokenService->generate($actor->id, 'entry.bulk.' . $operation, $canonicalInput);
                return $this->json([
                    'type' => 'https://errors.bscms.org/confirmation-required',
                    'title' => 'Confirmation Required',
                    'status' => 409,
                    'detail' => 'Bulk operation on ' . count($ids) . ' items requires confirmation.',
                    'confirm_token' => $token,
                    'count' => count($ids),
                    'operation' => $operation,
                ], 409);
            }

            try {
                $this->confirmTokenService->validateAndConsume($confirmToken, $actor->id, 'entry.bulk.' . $operation, $canonicalInput);
            } catch (Throwable $e) {
                return $this->problem(409, 'Confirmation Invalid', $e->getMessage());
            }
        }

        $userId = $actor->onBehalfOf ?? $actor->id;
        $processed = 0;
        $failed = 0;
        $now = date('Y-m-d H:i:s.v');
        $prefix = $this->connection->getPrefix();
        $tableName = $prefix . 'c_' . $handle;

        foreach ($ids as $idStr) {
            $id = (string) $idStr;
            $entry = $this->repository->find($handle, $id, true);
            if ($entry === null) {
                $failed++;
                continue;
            }

            // Per-entry authorization check
            $check = $this->authorizer->can($actor, $requiredAction, new Subject('collection', $handle), $entry);
            if (!$check->allowed) {
                $failed++;
                continue;
            }

            try {
                switch ($operation) {
                    case 'publish':
                        $this->repository->update($handle, $id, ['status' => 'published', 'published_at' => $now], 'Bulk published', null, $actor->type, $actor->label, $userId);
                        break;
                    case 'unpublish':
                        $this->repository->update($handle, $id, ['status' => 'draft'], 'Bulk unpublished', null, $actor->type, $actor->label, $userId);
                        break;
                    case 'trash':
                        $this->repository->delete($handle, $id, false);
                        break;
                    case 'purge':
                        $this->repository->delete($handle, $id, true);
                        break;
                    case 'set_author':
                        $newAuthor = (string) ($params['author_id'] ?? $userId);
                        $this->connection->execute("UPDATE `{$tableName}` SET `author_id` = ?, `updated_at` = ? WHERE `id` = ?", [$newAuthor, $now, $id]);
                        break;
                    case 'duplicate':
                        $this->repository->duplicate($handle, $id, $actor->type, $actor->label, $userId);
                        break;
                }
                $processed++;
            } catch (Throwable) {
                $failed++;
            }
        }

        $this->auditLogger->record(
            actor: $actor,
            action: 'entry.bulk.' . $operation,
            subject: new Subject('collection', $handle),
            summary: "Bulk {$operation} on {$handle} (processed: {$processed}, failed: {$failed})",
            diff: ['after' => ['operation' => $operation, 'processed' => $processed, 'failed' => $failed]],
            requestId: (string) $request->getAttribute('request_id', ''),
            ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
            subjectId: null,
            collectionKey: $handle
        );

        return $this->json([
            'success' => true,
            'operation' => $operation,
            'processed' => $processed,
            'failed' => $failed,
            'total' => count($ids),
        ]);
    }
}
