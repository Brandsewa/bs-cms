<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Taxonomy\TaxonomyRepository;
use BsCms\Taxonomy\TermPresenter;
use BsCms\Taxonomy\TermRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class TaxonomiesController extends BaseController
{
    public function __construct(
        private readonly TaxonomyRepository $taxonomies,
        private readonly TermRepository $terms,
        private readonly Authorizer $authorizer
    ) {
    }

    /**
     * GET /api/v1/taxonomies
     */
    public function listTaxonomies(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('taxonomy', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $all = $this->taxonomies->all();
        return $this->json($all);
    }

    /**
     * GET /api/v1/taxonomies/{handle}
     *
     * @param array<string, mixed> $args
     */
    public function getTaxonomy(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('taxonomy', $handle));
        if ($permError !== null) {
            return $permError;
        }

        $taxonomy = $this->taxonomies->findByHandle($handle);
        if ($taxonomy === null) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found.");
        }

        return $this->json($taxonomy);
    }

    /**
     * POST /api/v1/taxonomies
     */
    public function createTaxonomy(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('taxonomy', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $body = (array) $request->getParsedBody();
        $handle = trim((string) ($body['handle'] ?? ''));

        if ($handle === '' || !preg_match('/^[a-z0-9_]+$/', $handle)) {
            return $this->problem(422, 'Unprocessable Entity', "Invalid handle '{$handle}'.", null, [
                'handle' => ["Handle must be non-empty and contain only lowercase alphanumeric characters and underscores."],
            ]);
        }

        if ($this->taxonomies->findByHandle($handle) !== null) {
            return $this->problem(409, 'Conflict', "Taxonomy with handle '{$handle}' already exists.");
        }

        try {
            $created = $this->taxonomies->create($body);
            return $this->json($created, 201);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage());
        }
    }

    /**
     * PATCH /api/v1/taxonomies/{handle}
     *
     * @param array<string, mixed> $args
     */
    public function updateTaxonomy(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('taxonomy', $handle));
        if ($permError !== null) {
            return $permError;
        }

        $existing = $this->taxonomies->findByHandle($handle);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found.");
        }

        $body = (array) $request->getParsedBody();
        try {
            $updated = $this->taxonomies->update($handle, $body);
            return $this->json($updated);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage());
        }
    }

    /**
     * DELETE /api/v1/taxonomies/{handle}
     *
     * @param array<string, mixed> $args
     */
    public function deleteTaxonomy(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'delete', new Subject('taxonomy', $handle));
        if ($permError !== null) {
            return $permError;
        }

        $existing = $this->taxonomies->findByHandle($handle);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found.");
        }

        try {
            $this->taxonomies->delete($handle);
            return $this->json(['message' => "Taxonomy '{$handle}' deleted."]);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage());
        }
    }

    /**
     * GET /api/v1/taxonomies/{handle}/terms (tree listing)
     *
     * @param array<string, mixed> $args
     */
    public function listTerms(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('taxonomy', $handle));
        if ($permError !== null) {
            return $permError;
        }

        $taxonomy = $this->taxonomies->findByHandle($handle);
        if ($taxonomy === null) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found.");
        }

        $params = $request->getQueryParams();
        $parentId = isset($params['parent_id']) && $params['parent_id'] !== '' ? (string) $params['parent_id'] : null;

        $terms = $this->terms->listTree((string) $taxonomy['id'], $parentId);
        return $this->json($terms);
    }

    /**
     * GET /api/v1/taxonomies/{handle}/terms/{id}
     *
     * @param array<string, mixed> $args
     */
    public function getTerm(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $termId = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('taxonomy', $handle));
        if ($permError !== null) {
            return $permError;
        }

        $taxonomy = $this->taxonomies->findByHandle($handle);
        if ($taxonomy === null) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found.");
        }

        $term = $this->terms->findById($termId);
        if ($term === null || $term['taxonomy_id'] !== $taxonomy['id']) {
            return $this->problem(404, 'Not Found', "Term '{$termId}' not found.");
        }

        return $this->json($term);
    }

    /**
     * PATCH /api/v1/taxonomies/{handle}/terms/{id}
     *
     * @param array<string, mixed> $args
     */
    public function updateTerm(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $termId = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('taxonomy', $handle));
        if ($permError !== null) {
            return $permError;
        }

        $taxonomy = $this->taxonomies->findByHandle($handle);
        if ($taxonomy === null) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found.");
        }

        $existing = $this->terms->findById($termId);
        if ($existing === null || $existing['taxonomy_id'] !== $taxonomy['id']) {
            return $this->problem(404, 'Not Found', "Term '{$termId}' not found.");
        }

        $body = (array) $request->getParsedBody();
        try {
            $updated = $this->terms->update($termId, $body);
            return $this->json($updated);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage());
        }
    }

    /**
     * POST /api/v1/taxonomies/{handle}/terms
     *
     * @param array<string, mixed> $args
     */
    public function createTerm(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('taxonomy', $handle));
        if ($permError !== null) {
            return $permError;
        }

        $taxonomy = $this->taxonomies->findByHandle($handle);
        if ($taxonomy === null) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found.");
        }

        $body = (array) $request->getParsedBody();
        try {
            $created = $this->terms->create((string) $taxonomy['id'], $body);
            return $this->json($created, 201);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage());
        }
    }

    /**
     * POST /api/v1/taxonomies/{handle}/terms/{id}/move
     *
     * @param array<string, mixed> $args
     */
    public function moveTerm(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $termId = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('taxonomy', $handle));
        if ($permError !== null) {
            return $permError;
        }

        $taxonomy = $this->taxonomies->findByHandle($handle);
        if ($taxonomy === null) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found.");
        }

        $body = (array) $request->getParsedBody();
        $newParentId = !empty($body['parent_id']) ? (string) $body['parent_id'] : null;
        $newPosition = isset($body['position']) ? (int) $body['position'] : null;

        try {
            $moved = $this->terms->move($termId, $newParentId, $newPosition);
            return $this->json($moved);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage());
        }
    }

    /**
     * POST /api/v1/taxonomies/{handle}/terms/{id}/merge
     *
     * @param array<string, mixed> $args
     */
    public function mergeTerm(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $termId = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('taxonomy', $handle));
        if ($permError !== null) {
            return $permError;
        }

        $taxonomy = $this->taxonomies->findByHandle($handle);
        if ($taxonomy === null) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found.");
        }

        $body = (array) $request->getParsedBody();
        $targetTermId = (string) ($body['target_term_id'] ?? '');

        if ($targetTermId === '') {
            return $this->problem(422, 'Unprocessable Entity', "Target term ID is required for merge.");
        }

        try {
            $this->terms->merge($termId, $targetTermId);
            return $this->json(['message' => "Term '{$termId}' merged into '{$targetTermId}'."]);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage());
        }
    }

    /**
     * DELETE /api/v1/taxonomies/{handle}/terms/{id}
     *
     * @param array<string, mixed> $args
     */
    public function deleteTerm(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $termId = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        $permError = $this->requirePermission($this->authorizer, $request, 'delete', new Subject('taxonomy', $handle));
        if ($permError !== null) {
            return $permError;
        }

        $taxonomy = $this->taxonomies->findByHandle($handle);
        if ($taxonomy === null) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found.");
        }

        $params = $request->getQueryParams();
        $reassignTo = isset($params['reassign_to']) && $params['reassign_to'] !== '' ? (string) $params['reassign_to'] : null;

        try {
            $this->terms->delete($termId, $reassignTo);
            return $this->json(['message' => "Term '{$termId}' deleted."]);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage());
        }
    }

    /**
     * GET /api/v1/public/taxonomies/{handle}/terms
     * Public terms endpoint presenting terms via TermPresenter::forPublic.
     *
     * @param array<string, mixed> $args
     */
    public function publicListTerms(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $taxonomy = $this->taxonomies->findByHandle($handle);

        if ($taxonomy === null || empty($taxonomy['has_public_archive'])) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found or archive is private.");
        }

        $params = $request->getQueryParams();
        $parentId = isset($params['parent_id']) && $params['parent_id'] !== '' ? (string) $params['parent_id'] : null;

        $terms = $this->terms->listTree((string) $taxonomy['id'], $parentId);
        $presented = array_map([TermPresenter::class, 'forPublic'], $terms);

        return $this->json($presented);
    }
}
