<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Content\EntryRepository;
use BsCms\Database\Connection;
use BsCms\Operations\ConfirmTokenService;
use BsCms\Operations\Exceptions\ValidationException;
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
        private readonly AuditLogger $auditLogger,
        private readonly ConfirmTokenService $confirmTokenService
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
            // Seed singleton with defaults
            $defaults = [];
            $fields = (array) ($collection['fields'] ?? []);
            foreach ($fields as $fHandle => $fDef) {
                if (isset($fDef['default_value'])) {
                    $defaults[$fHandle] = $fDef['default_value'];
                }
            }
            $titleField = (string) ($collection['title_field'] ?? 'title');
            $defaults[$titleField] = $defaults[$titleField] ?? ($collection['label_singular'] ?? $handle);
            try {
                $row = $this->repository->create($handle, $defaults, 'system', 'installer');
            } catch (Throwable) {
                $row = [
                    'handle' => $handle,
                    'data' => $defaults,
                    'status' => 'published',
                ];
            }
        }

        if (is_string($row['data'])) {
            $row['data'] = json_decode($row['data'], true) ?? [];
        }
        if (is_string($row['seo'])) {
            $row['seo'] = json_decode($row['seo'], true);
        }

        $actor = $this->getActor($request);
        $presented = \BsCms\Content\Presenters\EntryPresenter::forAuthenticated($row, $collection, $actor, $this->authorizer);

        return $this->json($presented);
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
        $stmt = $this->connection->execute("SELECT * FROM `{$tableName}` LIMIT 1");
        $existing = $stmt->fetch();

        // Enforce step-up and two-step confirmation on environment change (production/staging)
        if ($handle === 'site_settings' && isset($body['environment'])) {
            $existingData = $existing && is_string($existing['data']) ? json_decode($existing['data'], true) : [];
            $oldEnv = $existingData['environment'] ?? ($existing['environment'] ?? 'production');
            $newEnv = (string) $body['environment'];

            if ($oldEnv !== $newEnv) {
                $stepUpError = $this->requireStepUp($request);
                if ($stepUpError !== null) {
                    return $stepUpError;
                }

                $confirmToken = (string) ($body['confirm_token'] ?? $request->getHeaderLine('X-Confirm-Token'));
                $canonicalInput = ['handle' => $handle, 'environment' => $newEnv];

                if ($confirmToken === '') {
                    $token = $this->confirmTokenService->generate($actor->id, 'site_settings.environment', $canonicalInput);
                    return $this->json([
                        'type' => 'https://errors.bscms.org/confirmation-required',
                        'title' => 'Confirmation Required',
                        'status' => 409,
                        'detail' => "Changing site environment to '{$newEnv}' requires two-step confirmation.",
                        'confirm_token' => $token,
                    ], 409);
                }

                try {
                    $this->confirmTokenService->validateAndConsume($confirmToken, $actor->id, 'site_settings.environment', $canonicalInput);
                } catch (Throwable $e) {
                    return $this->problem(409, 'Confirmation Failed', $e->getMessage());
                }
            }
        }

        try {
            if ($existing) {
                $id = (string) $existing['id'];
                $updated = $this->repository->update($handle, $id, $body, 'Updated singleton', null, $actor->type, $actor->label, $userId, isAutosave: false, isPatch: true);
            } else {
                $defaults = [];
                $fields = (array) ($collection['fields'] ?? []);
                foreach ($fields as $fHandle => $fDef) {
                    if (isset($fDef['default_value'])) {
                        $defaults[$fHandle] = $fDef['default_value'];
                    }
                }
                $merged = array_merge($defaults, $body);
                $updated = $this->repository->create($handle, $merged, $actor->type, $actor->label, $userId);
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

            $presented = \BsCms\Content\Presenters\EntryPresenter::forAuthenticated($updated, $collection, $actor, $this->authorizer);
            return $this->json($presented);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errs */
            $errs = [];
            foreach ($e->errors as $field => $messages) {
                $errs[$field] = is_array($messages) ? $messages : [(string) $messages];
            }
            return $this->problem(422, 'Validation Error', $e->getMessage(), null, $errs);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(422, 'Validation Error', $e->getMessage());
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'SingletonsController::update');
            $msg = ($e instanceof \PDOException) ? 'A database error occurred while updating singleton.' : $e->getMessage();
            return $this->problem(500, 'Singleton Error', $msg);
        }
    }
}
