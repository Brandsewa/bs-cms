<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Redirects\RedirectRepository;
use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class RedirectsController extends BaseController
{
    public function __construct(
        private readonly RedirectRepository $repository,
        private readonly Authorizer $authorizer,
        private readonly AuditLogger $auditLogger
    ) {
    }

    /**
     * GET /api/v1/redirects
     */
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('redirects', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $params = $request->getQueryParams();
        $filters = [];
        if (isset($params['search']) && trim((string) $params['search']) !== '') {
            $filters['search'] = trim((string) $params['search']);
        }
        if (isset($params['match_type']) && trim((string) $params['match_type']) !== '') {
            $filters['match_type'] = trim((string) $params['match_type']);
        }
        if (isset($params['is_auto']) && $params['is_auto'] !== '') {
            $filters['is_auto'] = $params['is_auto'];
        }
        if (isset($params['status_code']) && $params['status_code'] !== '') {
            $filters['status_code'] = (int) $params['status_code'];
        }

        $page = isset($params['page']) ? max(1, (int) $params['page']) : 1;
        $perPage = isset($params['per_page']) ? max(1, min(100, (int) $params['per_page'])) : 50;

        $result = $this->repository->list($filters, $page, $perPage);
        return $this->json($result);
    }

    /**
     * GET /api/v1/redirects/{id}
     *
     * @param array<string, mixed> $args
     */
    public function get(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('redirects', $id));
        if ($permError !== null) {
            return $permError;
        }

        $redirect = $this->repository->findById($id);
        if ($redirect === null) {
            return $this->problem(404, 'Not Found', "Redirect item '{$id}' not found.");
        }

        return $this->json($redirect);
    }

    /**
     * POST /api/v1/redirects
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('redirects', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $body = $this->getBody($request);
        $actor = $this->getActor($request);

        try {
            $redirect = $this->repository->create($body, $actor->id);

            $this->auditLogger->record(
                actor: $actor,
                action: 'redirect.create',
                subject: new Subject('redirects', (string) $redirect['id']),
                summary: "Created redirect from '{$redirect['from_path']}' to '{$redirect['to_path']}'",
                diff: ['after' => $redirect],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null
            );

            return $this->json($redirect, 201);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errs */
            $errs = [];
            foreach ($e->errors as $field => $messages) {
                $errs[$field] = is_array($messages) ? $messages : [(string) $messages];
            }
            return $this->problem(422, 'Validation Failed', $e->getMessage(), null, $errs);
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'redirect_create');
            return $this->problem(500, 'Internal Server Error', 'Failed to create redirect.');
        }
    }

    /**
     * PATCH /api/v1/redirects/{id}
     *
     * @param array<string, mixed> $args
     */
    public function update(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('redirects', $id));
        if ($permError !== null) {
            return $permError;
        }

        $body = $this->getBody($request);
        $actor = $this->getActor($request);

        try {
            $old = $this->repository->findById($id);
            if ($old === null) {
                return $this->problem(404, 'Not Found', "Redirect item '{$id}' not found.");
            }

            $updated = $this->repository->update($id, $body);

            $this->auditLogger->record(
                actor: $actor,
                action: 'redirect.update',
                subject: new Subject('redirects', $id),
                summary: "Updated redirect '{$id}'",
                diff: ['before' => $old, 'after' => $updated],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null
            );

            return $this->json($updated);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errs */
            $errs = [];
            foreach ($e->errors as $field => $messages) {
                $errs[$field] = is_array($messages) ? $messages : [(string) $messages];
            }
            return $this->problem(422, 'Validation Failed', $e->getMessage(), null, $errs);
        } catch (InvalidArgumentException $e) {
            return $this->problem(404, 'Not Found', $e->getMessage());
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'redirect_update');
            return $this->problem(500, 'Internal Server Error', 'Failed to update redirect.');
        }
    }

    /**
     * DELETE /api/v1/redirects/{id}
     *
     * @param array<string, mixed> $args
     */
    public function delete(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'delete', new Subject('redirects', $id));
        if ($permError !== null) {
            return $permError;
        }

        $redirect = $this->repository->findById($id);
        if ($redirect === null) {
            return $this->problem(404, 'Not Found', "Redirect item '{$id}' not found.");
        }

        $actor = $this->getActor($request);
        $this->repository->delete($id);

        $this->auditLogger->record(
            actor: $actor,
            action: 'redirect.delete',
            subject: new Subject('redirects', $id),
            summary: "Deleted redirect from '{$redirect['from_path']}' to '{$redirect['to_path']}'",
            diff: ['before' => $redirect],
            requestId: (string) $request->getAttribute('request_id', ''),
            ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null
        );

        return $this->json(['success' => true, 'id' => $id]);
    }

    /**
     * POST /api/v1/redirects/bulk
     */
    public function bulk(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $action = (string) ($body['action'] ?? '');
        $permAction = ($action === 'delete') ? 'delete' : 'update';

        $permError = $this->requirePermission($this->authorizer, $request, $permAction, new Subject('redirects', '*'));
        if ($permError !== null) {
            return $permError;
        }

        /** @var list<string> $ids */
        $ids = isset($body['ids']) && is_array($body['ids']) ? array_values(array_map('strval', $body['ids'])) : [];
        if (empty($ids)) {
            return $this->problem(422, 'Validation Failed', 'Redirect IDs array is required.', null, [
                'ids' => ['At least one redirect ID must be specified.']
            ]);
        }

        if ($action === 'delete') {
            $affected = $this->repository->bulkDelete($ids);
            return $this->json(['action' => 'delete', 'affected' => $affected]);
        }

        return $this->problem(422, 'Invalid Action', "Unsupported bulk action '{$action}'. Supported: 'delete'.");
    }

    /**
     * POST /api/v1/redirects/import
     */
    public function import(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('redirects', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $csvContent = '';
        $uploadedFiles = $request->getUploadedFiles();
        if (!empty($uploadedFiles)) {
            $file = reset($uploadedFiles);
            $csvContent = (string) $file->getStream()->getContents();
        } else {
            $body = $this->getBody($request);
            $csvContent = (string) ($body['csv'] ?? $body['content'] ?? '');
            if ($csvContent === '') {
                $rawBody = (string) $request->getBody();
                if (str_contains($rawBody, 'from_path') || str_contains($rawBody, ',')) {
                    $csvContent = $rawBody;
                }
            }
        }

        if (trim($csvContent) === '') {
            return $this->problem(422, 'Validation Failed', 'CSV content or file is required.', null, [
                'csv' => ['CSV payload cannot be empty.']
            ]);
        }

        $actor = $this->getActor($request);
        $result = $this->repository->importCsv($csvContent, $actor->id);

        return $this->json($result);
    }

    /**
     * GET /api/v1/redirects/export
     */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('redirects', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $csv = $this->repository->exportCsv();
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return $this->problem(500, 'Internal Server Error', 'Unable to generate CSV export.');
        }
        fwrite($stream, $csv);
        rewind($stream);

        return new Response(
            200,
            [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="redirects.csv"',
                'Cache-Control' => 'no-store',
            ],
            new \Nyholm\Psr7\Stream($stream)
        );
    }

    /**
     * GET /api/v1/public/redirects/match?path=...
     * Public redirect matching endpoint.
     */
    public function match(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $path = (string) ($params['path'] ?? '');

        if ($path === '') {
            return $this->problem(422, 'Validation Failed', "The 'path' query parameter is required.");
        }

        $matched = $this->repository->match($path);
        if ($matched === null) {
            return $this->problem(404, 'No Redirect Match', "No active redirect matches path '{$path}'.");
        }

        return $this->json($matched, 200, [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * GET /api/v1/public/redirects
     * Returns all redirects for Astro static publishing / .htaccess generation.
     */
    public function publicList(ServerRequestInterface $request): ResponseInterface
    {
        $redirects = $this->repository->listForPublic();
        return $this->json([
            'items' => $redirects,
            'total' => count($redirects),
        ], 200, [
            'Cache-Control' => 'public, max-age=60, stale-while-revalidate=600',
        ]);
    }
}
