<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Auth\SystemRoles;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Content\Cache\PublicResponseCache;
use BsCms\Schema\IdentifierValidator;
use BsCms\Taxonomy\TaxonomyRepository;
use BsCms\Taxonomy\TermPresenter;
use BsCms\Taxonomy\TermRepository;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class TaxonomiesController extends BaseController
{
    public function __construct(
        private readonly TaxonomyRepository $taxonomies,
        private readonly TermRepository $terms,
        private readonly Authorizer $authorizer,
        private readonly ?PublicResponseCache $cache = null
    ) {
    }

    /**
     * Enforce admin/developer/owner restriction on taxonomy definition mutations.
     */
    private function assertCanManageTaxonomyDefinitions(ServerRequestInterface $request, string $action, string $handle = '*'): ?ResponseInterface
    {
        $actor = $this->getActor($request);
        if (!$actor->isSystem() && !array_intersect($actor->roleIds, [SystemRoles::ROLE_OWNER, SystemRoles::ROLE_ADMIN, SystemRoles::ROLE_DEVELOPER])) {
            return $this->problem(403, 'Forbidden', 'Taxonomy definitions can only be managed by administrators and developers.');
        }

        return $this->requirePermission($this->authorizer, $request, $action, new Subject('taxonomy', $handle));
    }

    /**
     * Check permission on term subject, falling back to taxonomy subject.
     */
    private function checkTermPermission(ServerRequestInterface $request, string $action, string $handle): ?ResponseInterface
    {
        $termError = $this->requirePermission($this->authorizer, $request, $action, new Subject('term', $handle));
        if ($termError === null) {
            return null;
        }

        // Fallback to taxonomy subject if term rule was not found
        return $this->requirePermission($this->authorizer, $request, $action, new Subject('taxonomy', $handle));
    }

    /**
     * Invalidate public cache for taxonomy.
     */
    private function invalidateTaxonomyCache(string $handle): void
    {
        if ($this->cache !== null) {
            $this->cache->invalidateCollection('tax_' . $handle);
        }
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
        $permError = $this->assertCanManageTaxonomyDefinitions($request, 'create', '*');
        if ($permError !== null) {
            return $permError;
        }

        $body = (array) $request->getParsedBody();
        $handle = trim((string) ($body['handle'] ?? ''));

        try {
            IdentifierValidator::validateTaxonomyHandle($handle);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage(), null, [
                'handle' => [$e->getMessage()],
            ]);
        }

        if ($this->taxonomies->findByHandle($handle) !== null) {
            return $this->problem(409, 'Conflict', "Taxonomy with handle '{$handle}' already exists.");
        }

        try {
            $created = $this->taxonomies->create($body);
            $this->invalidateTaxonomyCache($handle);
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
        $permError = $this->assertCanManageTaxonomyDefinitions($request, 'update', $handle);
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
            $this->invalidateTaxonomyCache($handle);
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
        $permError = $this->assertCanManageTaxonomyDefinitions($request, 'delete', $handle);
        if ($permError !== null) {
            return $permError;
        }

        $existing = $this->taxonomies->findByHandle($handle);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found.");
        }

        try {
            $this->taxonomies->delete($handle);
            $this->invalidateTaxonomyCache($handle);
            return $this->json(['message' => "Taxonomy '{$handle}' deleted."]);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage());
        }
    }

    /**
     * POST /api/v1/taxonomies/{handle}/attach
     * Attach a taxonomy to a collection.
     *
     * @param array<string, mixed> $args
     */
    public function attachTaxonomy(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $permError = $this->assertCanManageTaxonomyDefinitions($request, 'update', $handle);
        if ($permError !== null) {
            return $permError;
        }

        $taxonomy = $this->taxonomies->findByHandle($handle);
        if ($taxonomy === null) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found.");
        }

        $body = (array) $request->getParsedBody();
        $colHandle = trim((string) ($body['collection_handle'] ?? ''));
        $fieldKey = trim((string) ($body['field_key'] ?? $handle));
        $required = !empty($body['required']);
        $allowMultiple = array_key_exists('allow_multiple', $body) ? !empty($body['allow_multiple']) : true;
        $position = isset($body['position']) ? (int) $body['position'] : 0;

        if ($colHandle === '') {
            return $this->problem(422, 'Unprocessable Entity', "Collection handle 'collection_handle' is required.");
        }

        try {
            $attached = $this->taxonomies->attach($handle, $colHandle, $fieldKey, $required, $allowMultiple, $position);
            return $this->json($attached, 201);
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
        $permError = $this->checkTermPermission($request, 'read', $handle);
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

        $permError = $this->checkTermPermission($request, 'read', $handle);
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

        $permError = $this->checkTermPermission($request, 'update', $handle);
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
            $this->invalidateTaxonomyCache($handle);
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
        $permError = $this->checkTermPermission($request, 'create', $handle);
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
            $this->invalidateTaxonomyCache($handle);
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

        $permError = $this->checkTermPermission($request, 'update', $handle);
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
            $this->invalidateTaxonomyCache($handle);
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

        $permError = $this->checkTermPermission($request, 'delete', $handle);
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
            $this->invalidateTaxonomyCache($handle);
            return $this->json(['message' => "Term '{$termId}' merged into '{$targetTermId}'."]);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage());
        }
    }

    /**
     * POST /api/v1/taxonomies/{handle}/terms/reorder
     *
     * @param array<string, mixed> $args
     */
    public function reorderTerms(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $permError = $this->checkTermPermission($request, 'update', $handle);
        if ($permError !== null) {
            return $permError;
        }

        $taxonomy = $this->taxonomies->findByHandle($handle);
        if ($taxonomy === null) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found.");
        }

        $body = (array) $request->getParsedBody();
        /** @var list<array{id: string, position: int, parent_id?: string|null}> $items */
        $items = isset($body['terms']) && is_array($body['terms']) ? $body['terms'] : (array) $body;

        try {
            $this->terms->reorder((string) $taxonomy['id'], $items);
            $this->invalidateTaxonomyCache($handle);
            return $this->json(['message' => 'Terms reordered successfully.']);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage());
        }
    }

    /**
     * POST /api/v1/taxonomies/{handle}/terms/bulk
     *
     * @param array<string, mixed> $args
     */
    public function bulkTerms(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? 'delete');

        $requiredPermission = $action === 'delete' ? 'delete' : 'update';
        $permError = $this->checkTermPermission($request, $requiredPermission, $handle);
        if ($permError !== null) {
            return $permError;
        }

        $taxonomy = $this->taxonomies->findByHandle($handle);
        if ($taxonomy === null) {
            return $this->problem(404, 'Not Found', "Taxonomy '{$handle}' not found.");
        }

        /** @var list<string> $termIds */
        $termIds = isset($body['term_ids']) && is_array($body['term_ids']) ? $body['term_ids'] : [];
        $targetParentId = isset($body['target_parent_id']) ? (string) $body['target_parent_id'] : null;
        $reassignTo = isset($body['reassign_to']) ? (string) $body['reassign_to'] : null;

        try {
            $res = $this->terms->bulk((string) $taxonomy['id'], $action, $termIds, $targetParentId, $reassignTo);
            $this->invalidateTaxonomyCache($handle);
            return $this->json($res);
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

        $permError = $this->checkTermPermission($request, 'delete', $handle);
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
            $this->invalidateTaxonomyCache($handle);
            return $this->json(['message' => "Term '{$termId}' deleted."]);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage());
        }
    }

    /**
     * GET /api/v1/public/taxonomies/{handle}/terms
     * Public terms endpoint presenting terms via TermPresenter::forPublic with caching.
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

        $cacheKey = 'terms:' . $handle . ':' . ($parentId ?? 'root');
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');

        if ($this->cache !== null) {
            $cached = $this->cache->get('tax_' . $handle, $cacheKey);
            if ($cached !== null) {
                if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $cached['etag']) {
                    return (new Response(304))
                        ->withHeader('ETag', $cached['etag'])
                        ->withHeader('Cache-Control', 'public, max-age=60, stale-while-revalidate=600');
                }

                return (new Response(200, [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'ETag' => $cached['etag'],
                    'Cache-Control' => 'public, max-age=60, stale-while-revalidate=600',
                ], $cached['body']));
            }
        }

        $terms = $this->terms->listTree((string) $taxonomy['id'], $parentId);
        $presented = array_map([TermPresenter::class, 'forPublic'], $terms);
        $body = json_encode($presented, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $etag = '"' . hash('sha256', $body) . '"';

        if ($this->cache !== null) {
            $this->cache->set('tax_' . $handle, $cacheKey, 200, ['Content-Type' => 'application/json'], $body, $etag, 60);
        }

        if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
            return (new Response(304))
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'public, max-age=60, stale-while-revalidate=600');
        }

        return (new Response(200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=60, stale-while-revalidate=600',
        ], $body));
    }
}
