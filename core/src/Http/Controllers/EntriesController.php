<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Content\EntryRepository;
use BsCms\Content\OptimisticConcurrencyException;
use BsCms\Content\Presenters\EntryPresenter;
use BsCms\Content\Query\QueryParser;
use BsCms\Content\Query\QueryValidationException;
use BsCms\Content\VersionManager;
use BsCms\Database\Connection;
use BsCms\Events\EventBus;
use BsCms\Events\GenericEvent;
use BsCms\Operations\ConfirmTokenService;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Schema\CollectionRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
        private readonly ConfirmTokenService $confirmTokenService,
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

            // Present entries using EntryPresenter::forAuthenticated
            $result['data'] = array_map(function (array $item) use ($actor, $collection): array {
                return EntryPresenter::forAuthenticated($item, $collection, $actor, $this->authorizer);
            }, $result['data']);

            return $this->json($result);
        } catch (QueryValidationException $e) {
            return $this->problem(422, 'Query Validation Error', $e->getMessage(), null, $e->getErrors());
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'EntriesController::list');
            $msg = ($e instanceof \PDOException) ? 'A database error occurred while querying entries.' : $e->getMessage();
            return $this->problem(500, 'Query Error', $msg);
        }
    }

    /**
     * Aggregate collection counts per status and trash.
     *
     * @param array<string, mixed> $args
     */
    public function counts(ServerRequestInterface $request, array $args = []): ResponseInterface
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

        try {
            $counts = $this->repository->getCounts($handle, $userId, $ownerOnly);
            return $this->json($counts);
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'EntriesController::counts');
            $msg = ($e instanceof \PDOException) ? 'A database error occurred while aggregating counts.' : $e->getMessage();
            return $this->problem(500, 'Query Error', $msg);
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
        $collection = $this->registry->get($handle);
        $presented = EntryPresenter::forAuthenticated($entry, $collection, $actor, $this->authorizer, !empty($entry['deleted_at']));

        return $this->json($presented);
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

        $effectiveEntity = array_merge(['status' => 'draft'], $body);
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('collection', $handle), $effectiveEntity);
        if ($permError !== null) {
            return $permError;
        }

        // Check if publish action is requested and permitted
        $status = $body['status'] ?? 'draft';
        if ($status === 'published') {
            $pubCheck = $this->authorizer->can($actor, 'publish', new Subject('collection', $handle), $effectiveEntity);
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

            $collection = $this->registry->get($handle);
            $presented = EntryPresenter::forAuthenticated($created, $collection, $actor, $this->authorizer);
            return $this->json($presented, 201);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errs */
            $errs = [];
            foreach ($e->errors as $field => $messages) {
                $errs[$field] = is_array($messages) ? $messages : [(string) $messages];
            }
            return $this->problem(422, 'Validation Error', $e->getMessage(), null, $errs);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(422, 'Validation Error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'EntriesController::create');
            $msg = ($e instanceof \PDOException) ? 'A database error occurred while creating entry.' : $e->getMessage();
            return $this->problem(500, 'Create Error', $msg);
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function update(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        return $this->handleUpdate($request, $args, false, false);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function patch(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        return $this->handleUpdate($request, $args, true, false);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function handleUpdate(
        ServerRequestInterface $request,
        array $args,
        bool $isPatch,
        bool $isStateChange = false
    ): ResponseInterface {
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

        $body = $this->getBody($request);

        // Concurrency: require If-Match header (or version/expected_version in body) for content updates (428 Precondition Required if missing).
        // For state-change endpoints (publish, unpublish, archive, schedule, submit-review), If-Match is optional (header only).
        $ifMatch = trim($request->getHeaderLine('If-Match'));
        if ($ifMatch === '' && !$isStateChange) {
            $bodyVersion = $body['expected_version'] ?? ($body['version'] ?? null);
            if ($bodyVersion !== null) {
                $ifMatch = (string) $bodyVersion;
            }
        }

        if ($ifMatch === '') {
            if (!$isStateChange) {
                return $this->problem(428, 'Precondition Required', "An 'If-Match' header containing the entity's current version or ETag is required to prevent lost updates.");
            }
            $expectedVersionInt = (int) $existing['version'];
        } else {
            $expectedVersion = trim($ifMatch, '"');
            if (!is_numeric($expectedVersion)) {
                return $this->problem(428, 'Precondition Required', "Invalid 'If-Match' header format.");
            }

            $expectedVersionInt = (int) $expectedVersion;
            if ((int) $existing['version'] !== $expectedVersionInt) {
                $conflictProblem = new \BsCms\Http\ProblemDetails(
                    409,
                    'Conflict',
                    "Entry '{$id}' was modified by another request. Expected version {$expectedVersionInt}, current version is {$existing['version']}.",
                    'about:blank'
                );
                $response = $conflictProblem->toResponse()->withHeader('ETag', '"' . $existing['version'] . '"');
                return $response;
            }
        }

        $actor = $this->getActor($request);

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
                $expectedVersionInt,
                $actor->type,
                $actor->label,
                $userId,
                $isAutosave,
                $isPatch
            );

            $this->auditLogger->record(
                actor: $actor,
                action: $isPatch ? 'entry.patch' : 'entry.update',
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

            $collection = $this->registry->get($handle);
            $presented = EntryPresenter::forAuthenticated($updated, $collection, $actor, $this->authorizer);
            return $this->json($presented)->withHeader('ETag', '"' . $updated['version'] . '"');
        } catch (OptimisticConcurrencyException $e) {
            return $this->problem(409, 'Conflict', $e->getMessage());
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errs */
            $errs = [];
            foreach ($e->errors as $field => $messages) {
                $errs[$field] = is_array($messages) ? $messages : [(string) $messages];
            }
            return $this->problem(422, 'Validation Error', $e->getMessage(), null, $errs);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(422, 'Validation Error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'EntriesController::handleUpdate');
            $msg = ($e instanceof \PDOException) ? 'A database error occurred while updating entry.' : $e->getMessage();
            return $this->problem(500, 'Update Error', $msg);
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
            $this->logServerError($request, $e, 'EntriesController::delete');
            $msg = ($e instanceof \PDOException) ? 'A database error occurred while deleting entry.' : $e->getMessage();
            return $this->problem(500, 'Delete Error', $msg);
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

        // Optional If-Match on restore: check version if provided
        $ifMatch = trim($request->getHeaderLine('If-Match'));
        if ($ifMatch !== '') {
            $expectedVersion = trim($ifMatch, '"');
            if (!is_numeric($expectedVersion) || (int) $existing['version'] !== (int) $expectedVersion) {
                $conflictProblem = new \BsCms\Http\ProblemDetails(
                    409,
                    'Conflict',
                    "Entry '{$id}' was modified by another request. Expected version {$expectedVersion}, current version is {$existing['version']}.",
                    'about:blank'
                );
                return $conflictProblem->toResponse()->withHeader('ETag', '"' . $existing['version'] . '"');
            }
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

            $collection = $this->registry->get($handle);
            $presented = EntryPresenter::forAuthenticated($restored, $collection, $actor, $this->authorizer);
            return $this->json($presented);
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'EntriesController::restore');
            $msg = ($e instanceof \PDOException) ? 'A database error occurred while restoring entry.' : $e->getMessage();
            return $this->problem(500, 'Restore Error', $msg);
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

        $actor = $this->getActor($request);

        $permCheck = $this->authorizer->can($actor, 'purge', new Subject('collection', $handle), $existing);
        if (!$permCheck->allowed) {
            return $this->problem(403, 'Forbidden', $permCheck->reason);
        }

        // Two-step confirmation for entry.purge
        $body = $this->getBody($request);
        $confirmToken = (string) ($body['confirm_token'] ?? $request->getHeaderLine('X-Confirm-Token'));
        $canonicalInput = ['handle' => $handle, 'id' => $id, 'action' => 'purge'];

        if ($confirmToken === '') {
            $token = $this->confirmTokenService->generate($actor->id, 'entry.purge', $canonicalInput);
            return $this->json([
                'type' => 'https://errors.bscms.org/confirmation-required',
                'title' => 'Confirmation Required',
                'status' => 409,
                'detail' => "Purging entry '{$id}' from '{$handle}' permanently requires confirmation.",
                'confirm_token' => $token,
                'handle' => $handle,
                'id' => $id,
            ], 409);
        }

        try {
            $this->confirmTokenService->validateAndConsume($confirmToken, $actor->id, 'entry.purge', $canonicalInput);
        } catch (\Throwable $e) {
            return $this->problem(409, 'Confirmation Failed', $e->getMessage());
        }

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
            $this->logServerError($request, $e, 'EntriesController::purge');
            $msg = ($e instanceof \PDOException) ? 'A database error occurred while purging entry.' : $e->getMessage();
            return $this->problem(500, 'Purge Error', $msg);
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

            $collection = $this->registry->get($handle);
            $presented = EntryPresenter::forAuthenticated($duplicated, $collection, $actor, $this->authorizer);
            return $this->json($presented, 201);
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'EntriesController::duplicate');
            $msg = ($e instanceof \PDOException) ? 'A database error occurred while duplicating entry.' : $e->getMessage();
            return $this->problem(500, 'Duplicate Error', $msg);
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

            $collection = $this->registry->get($handle);
            $presented = EntryPresenter::forAuthenticated($restored, $collection, $actor, $this->authorizer);
            return $this->json($presented);
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'EntriesController::restoreVersion');
            $msg = ($e instanceof \PDOException) ? 'A database error occurred while restoring entry version.' : $e->getMessage();
            return $this->problem(500, 'Restore Version Error', $msg);
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
        return $this->handleUpdate($requestWithStatus, ['handle' => $handle, 'id' => $id], isPatch: true, isStateChange: true);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function unpublish(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $requestWithStatus = $request->withParsedBody(array_merge($this->getBody($request), ['status' => 'draft']));
        return $this->handleUpdate($requestWithStatus, ['handle' => $handle, 'id' => $id], isPatch: true, isStateChange: true);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function archive(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $requestWithStatus = $request->withParsedBody(array_merge($this->getBody($request), ['status' => 'archived']));
        return $this->handleUpdate($requestWithStatus, ['handle' => $handle, 'id' => $id], isPatch: true, isStateChange: true);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function schedule(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $body = $this->getBody($request);

        $scheduledAt = (string) ($body['scheduled_at'] ?? '');
        if ($scheduledAt === '' || strtotime($scheduledAt) === false) {
            return $this->problem(422, 'Invalid Date', 'Field scheduled_at must be a valid future ISO-8601 date-time string.', null, [
                'scheduled_at' => ['A valid scheduled_at timestamp is required.'],
            ]);
        }

        if (strtotime($scheduledAt) <= time()) {
            return $this->problem(422, 'Invalid Date', 'Field scheduled_at must be in the future.', null, [
                'scheduled_at' => ['Scheduled date must be in the future.'],
            ]);
        }

        $requestWithStatus = $request->withParsedBody(array_merge($body, [
            'status' => 'scheduled',
            'scheduled_at' => date('Y-m-d H:i:s', (int) strtotime($scheduledAt)),
        ]));
        return $this->handleUpdate($requestWithStatus, ['handle' => $handle, 'id' => $id], isPatch: true, isStateChange: true);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function submitReview(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $body = $this->getBody($request);

        $requestWithStatus = $request->withParsedBody(array_merge($body, ['status' => 'in_review']));
        return $this->handleUpdate($requestWithStatus, ['handle' => $handle, 'id' => $id], isPatch: true, isStateChange: true);
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

    /**
     * GET /api/v1/collections/{handle}/entries/export?format=csv|json
     */
    public function export(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        if (!$this->registry->has($handle)) {
            return $this->problem(404, 'Not Found', "Collection '{$handle}' not found.");
        }

        $params = $request->getQueryParams();
        $format = strtolower((string) ($params['format'] ?? 'json'));
        if (!in_array($format, ['csv', 'json'], true)) {
            return $this->problem(422, 'Validation Error', "Unsupported export format '{$format}'.");
        }

        // Reuse list() so permission checks, owner scoping and presentation stay identical.
        $entries = [];
        for ($page = 1; $page <= 10; $page++) {
            $resp = $this->list($request->withQueryParams(['limit' => 100, 'page' => $page]), $args);
            if ($resp->getStatusCode() !== 200) {
                return $resp;
            }
            $decoded = json_decode((string) $resp->getBody(), true);
            $rows = is_array($decoded) ? ($decoded['data'] ?? []) : [];
            foreach ($rows as $row) {
                $entries[] = $row;
            }
            if (count($rows) < 100) {
                break;
            }
        }

        if ($format === 'csv') {
            $stream = fopen('php://temp', 'r+');
            if ($stream === false) {
                return $this->problem(500, 'Export Error', 'Failed to allocate export buffer.');
            }

            $safe = static function (mixed $v): string {
                $v = (string) ($v ?? '');
                return $v !== '' && strpbrk($v[0], "=+-@	") !== false ? "'" . $v : $v;
            };
            fputcsv($stream, ['id', 'slug', 'title', 'status', 'published_at', 'updated_at', 'created_at']);
            foreach ($entries as $e) {
                fputcsv($stream, [
                    $safe($e['id'] ?? ''),
                    $safe($e['slug'] ?? ''),
                    $safe($e['title'] ?? ''),
                    $safe($e['status'] ?? ''),
                    $safe($e['published_at'] ?? ''),
                    $safe($e['updated_at'] ?? ''),
                    $safe($e['created_at'] ?? ''),
                ]);
            }
            rewind($stream);
            $csvContent = stream_get_contents($stream) ?: '';
            fclose($stream);

            return new \Nyholm\Psr7\Response(200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"{$handle}-export.csv\"",
            ], $csvContent);
        }

        return $this->json([
            'collection' => $handle,
            'count' => count($entries),
            'data' => $entries,
        ]);
    }
}
