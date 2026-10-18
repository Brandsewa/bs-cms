<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Content\EntryRepository;
use BsCms\Content\OptimisticConcurrencyException;
use BsCms\Content\Query\QueryParser;
use BsCms\Content\Query\QueryValidationException;
use BsCms\Content\VersionManager;
use BsCms\Database\Connection;
use BsCms\Schema\CollectionRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use BsCms\Events\EventBus;
use BsCms\Events\GenericEvent;
use Throwable;

class EntriesController extends BaseController
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly EntryRepository $repository,
        private readonly QueryParser $queryParser,
        private readonly VersionManager $versionManager,
        private readonly Authorizer $authorizer,
        private readonly AuditLogger $auditLogger,
        private readonly ?EventBus $eventBus = null
    ) {
    }

    /**
     * @param array<string, mixed> $args
     */
    public function list(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $actor = $this->getActor($request);
        $userId = $actor->onBehalfOf ?? $actor->id;

        // Check permissions: test unrestricted first, then owner=self
        $unrestricted = $this->authorizer->can($actor, 'read', new Subject('collection', $handle), [
            'author_id' => '00000000000000000000000000',
            'created_by' => '00000000000000000000000000',
        ])->allowed;

        $ownerOnly = false;
        if (!$unrestricted) {
            $ownerAllowed = $this->authorizer->can($actor, 'read', new Subject('collection', $handle), [
                'author_id' => $userId,
                'created_by' => $userId,
            ])->allowed;

            if (!$ownerAllowed) {
                return $this->problem(403, 'Forbidden', "You do not have permission to read collection '{$handle}'.");
            }
            $ownerOnly = true;
        }

        $collection = $this->registry->get($handle);

        try {
            $queryOptions = $this->queryParser->parse($request, $collection);
            $result = $this->repository->query($handle, $queryOptions, $userId, $ownerOnly);

            // Apply field-level read filtering across results
            $result['data'] = array_map(function (array $item) use ($actor, $handle): array {
                return $this->authorizer->filterFields($actor, new Subject('collection', $handle), $item, 'read');
            }, $result['data']);

            return $this->json($result);
        } catch (QueryValidationException $e) {
            return $this->problem(422, 'Query Validation Error', $e->getMessage(), null, $e->getErrors());
        } catch (Throwable $e) {
            return $this->problem(500, 'Query Error', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function get(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $entry = $this->repository->find($handle, $id, true);
        if ($entry === null) {
            return $this->problem(404, 'Not Found', "Entry '{$id}' not found.");
        }

        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('collection', $handle), $entry);
        if ($permError !== null) {
            return $permError;
        }

        $actor = $this->getActor($request);
        $filtered = $this->authorizer->filterFields($actor, new Subject('collection', $handle), $entry, 'read');

        return $this->json($filtered);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function create(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $actor = $this->getActor($request);
        $body = $this->getBody($request);

        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('collection', $handle), $body);
        if ($permError !== null) {
            return $permError;
        }

        // Check if publish action is requested and permitted
        $status = $body['status'] ?? 'draft';
        if ($status === 'published') {
            $pubCheck = $this->authorizer->can($actor, 'publish', new Subject('collection', $handle), $body);
            if (!$pubCheck->allowed) {
                return $this->problem(403, 'Forbidden', "You do not have permission to publish in collection '{$handle}'.");
            }
        }

        $userId = $actor->onBehalfOf ?? $actor->id;

        try {
            $created = $this->repository->create($handle, $body, $actor->type, $actor->label, $userId);

            $this->auditLogger->record(
                actor: $actor,
                action: 'entry.create',
                subject: new Subject('collection', $handle),
                summary: "Created entry {$created['id']} in {$handle}",
                diff: ['after' => $created],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
                subjectId: (string) $created['id'],
                collectionKey: $handle
            );

            $this->eventBus?->dispatch(new GenericEvent('entry.created', [
                'collection_key' => $handle,
                'entry_id' => $created['id'],
                'data' => $created,
                'actor' => $actor->toArray(),
            ]));
            if (($created['status'] ?? '') === 'published') {
                $this->eventBus?->dispatch(new GenericEvent('entry.published', [
                    'collection_key' => $handle,
                    'entry_id' => $created['id'],
                    'data' => $created,
                    'actor' => $actor->toArray(),
                ]));
            }

            $filtered = $this->authorizer->filterFields($actor, new Subject('collection', $handle), $created, 'read');
            return $this->json($filtered, 201);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(422, 'Validation Error', $e->getMessage());
        } catch (\Throwable $e) {
            return $this->problem(500, 'Create Error', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function update(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $existing = $this->repository->find($handle, $id, true);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Entry '{$id}' not found.");
        }

        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('collection', $handle), $existing);
        if ($permError !== null) {
            return $permError;
        }

        $actor = $this->getActor($request);
        $body = $this->getBody($request);

        // Check publish permission if changing to published
        $newStatus = $body['status'] ?? ($existing['status'] ?? 'draft');
        if ($newStatus === 'published' && ($existing['status'] ?? '') !== 'published') {
            $pubCheck = $this->authorizer->can($actor, 'publish', new Subject('collection', $handle), $existing);
            if (!$pubCheck->allowed) {
                return $this->problem(403, 'Forbidden', "You do not have permission to publish in collection '{$handle}'.");
            }
        }

        $userId = $actor->onBehalfOf ?? $actor->id;
        $changeSummary = isset($body['_change_summary']) && is_string($body['_change_summary']) ? $body['_change_summary'] : null;
        $isAutosave = !empty($body['_autosave']);

        try {
            $updated = $this->repository->update(
                $handle,
                $id,
                $body,
                $changeSummary,
                null,
                $actor->type,
                $actor->label,
                $userId,
                $isAutosave
            );

            $this->auditLogger->record(
                actor: $actor,
                action: 'entry.update',
                subject: new Subject('collection', $handle),
                summary: "Updated entry {$id} in {$handle}",
                diff: ['before' => $existing, 'after' => $updated],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
                subjectId: $id,
                collectionKey: $handle
            );

            $this->eventBus?->dispatch(new GenericEvent('entry.updated', [
                'collection_key' => $handle,
                'entry_id' => $id,
                'data' => $updated,
                'diff' => ['before' => $existing, 'after' => $updated],
                'actor' => $actor->toArray(),
            ]));
            if (($existing['status'] ?? '') !== 'published' && ($updated['status'] ?? '') === 'published') {
                $this->eventBus?->dispatch(new GenericEvent('entry.published', [
                    'collection_key' => $handle,
                    'entry_id' => $id,
                    'data' => $updated,
                    'actor' => $actor->toArray(),
                ]));
            } elseif (($existing['status'] ?? '') === 'published' && ($updated['status'] ?? '') !== 'published') {
                $this->eventBus?->dispatch(new GenericEvent('entry.unpublished', [
                    'collection_key' => $handle,
                    'entry_id' => $id,
                    'actor' => $actor->toArray(),
                ]));
            }

            $filtered = $this->authorizer->filterFields($actor, new Subject('collection', $handle), $updated, 'read');
            return $this->json($filtered);
        } catch (OptimisticConcurrencyException $e) {
            return $this->problem(409, 'Conflict', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->problem(422, 'Validation Error', $e->getMessage());
        } catch (\Throwable $e) {
            return $this->problem(500, 'Update Error', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function delete(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $existing = $this->repository->find($handle, $id, true);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Entry '{$id}' not found.");
        }

        $permError = $this->requirePermission($this->authorizer, $request, 'delete', new Subject('collection', $handle), $existing);
        if ($permError !== null) {
            return $permError;
        }

        $actor = $this->getActor($request);

        try {
            $this->repository->delete($handle, $id, false);

            $this->auditLogger->record(
                actor: $actor,
                action: 'entry.trash',
                subject: new Subject('collection', $handle),
                summary: "Trashed entry {$id} in {$handle}",
                diff: ['before' => $existing],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
                subjectId: $id,
                collectionKey: $handle
            );

            $this->eventBus?->dispatch(new GenericEvent('entry.deleted', [
                'collection_key' => $handle,
                'entry_id' => $id,
                'permanent' => false,
                'actor' => $actor->toArray(),
            ]));

            return $this->json(['success' => true]);
        } catch (\Throwable $e) {
            return $this->problem(500, 'Delete Error', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function restore(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $existing = $this->repository->find($handle, $id, true);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Entry '{$id}' not found.");
        }

        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('collection', $handle), $existing);
        if ($permError !== null) {
            return $permError;
        }

        $actor = $this->getActor($request);

        try {
            $restored = $this->repository->restore($handle, $id);

            $this->auditLogger->record(
                actor: $actor,
                action: 'entry.restore',
                subject: new Subject('collection', $handle),
                summary: "Restored entry {$id} in {$handle}",
                diff: ['after' => $restored],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
                subjectId: $id,
                collectionKey: $handle
            );

            $this->eventBus?->dispatch(new GenericEvent('entry.restored', [
                'collection_key' => $handle,
                'entry_id' => $id,
                'data' => $restored,
                'actor' => $actor->toArray(),
            ]));

            $filtered = $this->authorizer->filterFields($actor, new Subject('collection', $handle), $restored, 'read');
            return $this->json($filtered);
        } catch (\Throwable $e) {
            return $this->problem(500, 'Restore Error', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function purge(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $existing = $this->repository->find($handle, $id, true);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Entry '{$id}' not found.");
        }

        $permError = $this->requirePermission($this->authorizer, $request, 'delete', new Subject('collection', $handle), $existing);
        if ($permError !== null) {
            return $permError;
        }

        $actor = $this->getActor($request);

        try {
            $this->repository->delete($handle, $id, true);

            $this->auditLogger->record(
                actor: $actor,
                action: 'entry.purge',
                subject: new Subject('collection', $handle),
                summary: "Purged entry {$id} from {$handle}",
                diff: ['before' => $existing],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
                subjectId: $id,
                collectionKey: $handle
            );

            $this->eventBus?->dispatch(new GenericEvent('entry.deleted', [
                'collection_key' => $handle,
                'entry_id' => $id,
                'permanent' => true,
                'actor' => $actor->toArray(),
            ]));

            return $this->json(['success' => true]);
        } catch (\Throwable $e) {
            return $this->problem(500, 'Purge Error', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function duplicate(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $existing = $this->repository->find($handle, $id);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Entry '{$id}' not found.");
        }

        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('collection', $handle));
        if ($permError !== null) {
            return $permError;
        }

        $actor = $this->getActor($request);
        $userId = $actor->onBehalfOf ?? $actor->id;

        try {
            $duplicated = $this->repository->duplicate($handle, $id, $actor->type, $actor->label, $userId);

            $this->auditLogger->record(
                actor: $actor,
                action: 'entry.duplicate',
                subject: new Subject('collection', $handle),
                summary: "Duplicated entry {$id} in {$handle} to {$duplicated['id']}",
                diff: ['after' => $duplicated],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
                subjectId: (string) $duplicated['id'],
                collectionKey: $handle
            );

            $filtered = $this->authorizer->filterFields($actor, new Subject('collection', $handle), $duplicated, 'read');
            return $this->json($filtered, 201);
        } catch (Throwable $e) {
            return $this->problem(500, 'Duplicate Error', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function listVersions(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $collection = $this->registry->get($handle);
        $collectionId = (string) $collection['id'];

        $existing = $this->repository->find($handle, $id, true);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Entry '{$id}' not found.");
        }

        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('collection', $handle), $existing);
        if ($permError !== null) {
            return $permError;
        }

        $versions = $this->versionManager->listVersions($collectionId, $id);
        return $this->json($versions);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function getVersion(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $versionNo = (int) ($args['version_id'] ?? $request->getAttribute('version_id', 0));

        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $collection = $this->registry->get($handle);
        $collectionId = (string) $collection['id'];

        $existing = $this->repository->find($handle, $id, true);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Entry '{$id}' not found.");
        }

        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('collection', $handle), $existing);
        if ($permError !== null) {
            return $permError;
        }

        // Compare query param support: /versions/{version_id}?compare_to={other_version}
        $queryParams = $request->getQueryParams();
        if (isset($queryParams['compare_to'])) {
            $otherVersion = (int) $queryParams['compare_to'];
            $diff = $this->versionManager->compare($collectionId, $id, $otherVersion, $versionNo);
            return $this->json(['diff' => $diff, 'from' => $otherVersion, 'to' => $versionNo]);
        }

        $version = $this->versionManager->getVersion($collectionId, $id, $versionNo);
        if ($version === null) {
            return $this->problem(404, 'Not Found', "Version '{$versionNo}' not found.");
        }

        return $this->json($version);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function restoreVersion(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $versionNo = (int) ($args['version_id'] ?? $request->getAttribute('version_id', 0));

        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $collection = $this->registry->get($handle);
        $collectionId = (string) $collection['id'];

        $existing = $this->repository->find($handle, $id, true);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Entry '{$id}' not found.");
        }

        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('collection', $handle), $existing);
        if ($permError !== null) {
            return $permError;
        }

        $targetVersion = $this->versionManager->getVersion($collectionId, $id, $versionNo);
        if ($targetVersion === null) {
            return $this->problem(404, 'Not Found', "Version '{$versionNo}' not found.");
        }

        $actor = $this->getActor($request);
        $userId = $actor->onBehalfOf ?? $actor->id;
        $snapshot = (array) ($targetVersion['snapshot'] ?? []);

        try {
            $restored = $this->repository->update(
                $handle,
                $id,
                $snapshot,
                "Restored from version {$versionNo}",
                null,
                $actor->type,
                $actor->label,
                $userId
            );

            $this->auditLogger->record(
                actor: $actor,
                action: 'entry.version_restore',
                subject: new Subject('collection', $handle),
                summary: "Restored entry {$id} in {$handle} from version {$versionNo}",
                diff: ['before' => $existing, 'after' => $restored],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
                subjectId: $id,
                collectionKey: $handle
            );

            return $this->json($restored);
        } catch (Throwable $e) {
            return $this->problem(500, 'Restore Version Error', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function publish(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $requestWithStatus = $request->withParsedBody(array_merge($this->getBody($request), ['status' => 'published']));
        return $this->update($requestWithStatus, ['handle' => $handle, 'id' => $id]);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function unpublish(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $requestWithStatus = $request->withParsedBody(array_merge($this->getBody($request), ['status' => 'draft']));
        return $this->update($requestWithStatus, ['handle' => $handle, 'id' => $id]);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function archive(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $requestWithStatus = $request->withParsedBody(array_merge($this->getBody($request), ['status' => 'archived']));
        return $this->update($requestWithStatus, ['handle' => $handle, 'id' => $id]);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function compare(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $queryParams = $request->getQueryParams();
        $from = isset($queryParams['from']) ? (int) $queryParams['from'] : 0;
        $to = isset($queryParams['to']) ? (int) $queryParams['to'] : 0;

        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $collection = $this->registry->get($handle);
        $collectionId = (string) $collection['id'];

        $fromVersion = $this->versionManager->getVersion($collectionId, $id, $from);
        $toVersion = $this->versionManager->getVersion($collectionId, $id, $to);

        if (!$fromVersion || !$toVersion) {
            return $this->problem(404, 'Not Found', 'Specified versions for comparison were not found.');
        }

        $diff = [];
        $fromData = is_array($fromVersion['snapshot']) ? $fromVersion['snapshot'] : [];
        $toData = is_array($toVersion['snapshot']) ? $toVersion['snapshot'] : [];

        $allKeys = array_unique(array_merge(array_keys($fromData), array_keys($toData)));
        foreach ($allKeys as $key) {
            $valFrom = $fromData[$key] ?? null;
            $valTo = $toData[$key] ?? null;
            if ($valFrom !== $valTo) {
                $diff[$key] = ['from' => $valFrom, 'to' => $valTo];
            }
        }

        return $this->json([
            'collection' => $handle,
            'entry_id' => $id,
            'from_version' => $from,
            'to_version' => $to,
            'diff' => $diff,
        ]);
    }
}
