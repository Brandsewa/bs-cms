<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Content\EntryRepository;
use BsCms\Database\Connection;
use BsCms\Schema\CollectionRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class SingletonsController extends BaseController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CollectionRegistry $registry,
        private readonly EntryRepository $repository,
        private readonly Authorizer $authorizer,
        private readonly AuditLogger $auditLogger
    ) {
    }

    /**
     * @param array<string, mixed> $args
     */
    public function get(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Singleton collection '{$handle}' not found.");
        }

        $collection = $this->registry->get($handle);
        if (($collection['kind'] ?? '') !== 'single') {
            return $this->problem(400, 'Bad Request', "Collection '{$handle}' is not a singleton (kind=single).");
        }

        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('site', $handle));
        if ($permError !== null) {
            $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('collection', $handle));
            if ($permError !== null) {
                return $permError;
            }
        }

        $prefix = $this->connection->getPrefix();
        $tableName = $prefix . 'c_' . $handle;
        $stmt = $this->connection->execute("SELECT * FROM `{$tableName}` LIMIT 1");
        $row = $stmt->fetch();

        if (!$row) {
            // Return empty skeleton
            return $this->json([
                'handle' => $handle,
                'data' => [],
                'status' => 'draft',
            ]);
        }

        if (is_string($row['data'])) {
            $row['data'] = json_decode($row['data'], true) ?? [];
        }
        if (is_string($row['seo'])) {
            $row['seo'] = json_decode($row['seo'], true);
        }

        $actor = $this->getActor($request);
        $filtered = $this->authorizer->filterFields($actor, new Subject('collection', $handle), $row, 'read');

        return $this->json($filtered);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function update(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Singleton collection '{$handle}' not found.");
        }

        $collection = $this->registry->get($handle);
        if (($collection['kind'] ?? '') !== 'single') {
            return $this->problem(400, 'Bad Request', "Collection '{$handle}' is not a singleton (kind=single).");
        }

        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('site', $handle));
        if ($permError !== null) {
            $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('collection', $handle));
            if ($permError !== null) {
                return $permError;
            }
        }

        $actor = $this->getActor($request);
        $userId = $actor->onBehalfOf ?? $actor->id;
        $body = $this->getBody($request);

        $prefix = $this->connection->getPrefix();
        $tableName = $prefix . 'c_' . $handle;
        $stmt = $this->connection->execute("SELECT `id` FROM `{$tableName}` LIMIT 1");
        $existing = $stmt->fetch();

        try {
            if ($existing) {
                $id = (string) $existing['id'];
                $updated = $this->repository->update($handle, $id, $body, 'Updated singleton', null, $actor->type, $actor->label, $userId);
            } else {
                $updated = $this->repository->create($handle, $body, $actor->type, $actor->label, $userId);
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'singleton.update',
                subject: new Subject('collection', $handle),
                summary: "Updated singleton {$handle}",
                diff: ['after' => $updated],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
                subjectId: (string) ($updated['id'] ?? $handle),
                collectionKey: $handle
            );

            $filtered = $this->authorizer->filterFields($actor, new Subject('collection', $handle), $updated, 'read');
            return $this->json($filtered);
        } catch (Throwable $e) {
            return $this->problem(500, 'Singleton Error', $e->getMessage());
        }
    }
}
