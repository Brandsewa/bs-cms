<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller for querying immutable audit logs.
 */
class AuditController extends BaseController
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly Authorizer $authorizer
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('audit', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $params = $request->getQueryParams();
        $filters = [];

        if (isset($params['actor_type']) && is_string($params['actor_type'])) {
            $filters['actor_type'] = $params['actor_type'];
        }
        if (isset($params['action']) && is_string($params['action'])) {
            $filters['action'] = $params['action'];
        }
        if (isset($params['subject_type']) && is_string($params['subject_type'])) {
            $filters['subject_type'] = $params['subject_type'];
        }
        if (isset($params['from']) && is_string($params['from'])) {
            $filters['from'] = $params['from'];
        }
        if (isset($params['to']) && is_string($params['to'])) {
            $filters['to'] = $params['to'];
        }

        $limit = isset($params['limit']) ? min(100, max(1, (int) $params['limit'])) : 50;
        $beforeId = isset($params['cursor']) ? (int) $params['cursor'] : null;

        $result = $this->auditLogger->query($filters, $limit, $beforeId);

        $items = array_map(fn ($e) => $e->toArray(), $result['items']);

        return $this->json([
            'items' => $items,
            'next_cursor' => $result['next_cursor'],
        ]);
    }

    public function get(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) $request->getAttribute('id', '0');
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('audit', (string) $id));
        if ($permError !== null) {
            return $permError;
        }

        $entry = $this->auditLogger->findById($id);
        if ($entry === null) {
            return $this->problem(404, 'Not Found', 'Audit log entry not found.');
        }

        return $this->json($entry->toArray());
    }

    /**
     * POST /api/v1/audit/delete — bulk delete audit entries by id.
     * Gated to the audit:delete action (owner-level: audit trails are
     * security-relevant).
     */
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'delete', new Subject('audit', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $body = $this->getJsonBody($request);
        $ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return $this->problem(422, 'Unprocessable Content', 'Provide a non-empty numeric ids array.');
        }
        if (count($ids) > 500) {
            return $this->problem(422, 'Unprocessable Content', 'Delete at most 500 entries per request.');
        }

        $deleted = $this->auditLogger->deleteEntries($ids);
        return $this->json(['deleted' => $deleted, 'ids' => $ids]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getJsonBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }
        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
