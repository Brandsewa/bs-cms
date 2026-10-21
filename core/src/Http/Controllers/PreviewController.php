<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Auth\Actor;
use BsCms\Auth\SystemRoles;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Content\EntryRepository;
use BsCms\Content\Presenters\EntryPresenter;
use BsCms\Publishing\PreviewTokenService;
use BsCms\Publishing\PublishingSettingsRepository;
use BsCms\Schema\CollectionRegistry;
use BsCms\Seo\SeoResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PreviewController extends BaseController
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly EntryRepository $repository,
        private readonly PreviewTokenService $previewTokenService,
        private readonly Authorizer $authorizer,
        private readonly PublishingSettingsRepository $publishingSettings,
        private readonly ?SeoResolver $seoResolver = null
    ) {
    }

    /**
     * Generate preview token for an entry.
     * POST /api/v1/preview/token
     */
    public function createToken(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->getActor($request);
        $body = (array) $request->getParsedBody();

        $collectionHandle = (string) ($body['collection'] ?? $body['collection_handle'] ?? '');
        $entryId = (string) ($body['id'] ?? $body['entry_id'] ?? '');

        if ($collectionHandle === '' || $entryId === '') {
            return $this->problem(422, 'Unprocessable Content', 'Both collection and id are required.');
        }

        if (!$this->registry->has($collectionHandle)) {
            return $this->problem(404, 'Not Found', "Collection '{$collectionHandle}' not found.");
        }

        $collection = $this->registry->get($collectionHandle);
        $collectionId = (string) $collection['id'];

        $entry = $this->repository->find($collectionHandle, $entryId);
        if (!$entry) {
            return $this->problem(404, 'Not Found', "Entry '{$entryId}' not found in '{$collectionHandle}'.");
        }

        // Authorize: require edit (update) permission on the collection/entry (same as editing)
        $permCheck = $this->requirePermission($this->authorizer, $request, 'update', new Subject('collection', $collectionHandle), $entry);
        if ($permCheck !== null) {
            return $permCheck;
        }

        try {
            $tokenResult = $this->previewTokenService->generateToken($collectionId, $entryId, $actor->id);
        } catch (\RuntimeException $e) {
            return $this->problem(429, 'Too Many Requests', $e->getMessage());
        }

        // Build frontend preview URL
        $pubSettings = $this->publishingSettings->get();
        $patterns = (array) ($pubSettings['url_patterns'] ?? []);
        $pattern = (string) ($patterns[$collectionHandle] ?? ($collectionHandle === 'pages' ? '/{slug}/' : "/blog/{slug}/"));
        $slug = (string) ($entry['slug'] ?? '');
        $path = str_replace('{slug}', $slug, $pattern);

        $previewUrl = "/preview/?collection=" . urlencode($collectionHandle) . "&id=" . urlencode($entryId) . "&token=" . urlencode($tokenResult['token']) . "&path=" . urlencode($path);

        return $this->json([
            'token' => $tokenResult['token'],
            'expires_at' => $tokenResult['expires_at'],
            'collection' => $collectionHandle,
            'entry_id' => $entryId,
            'preview_url' => $previewUrl,
        ], 201);
    }

    /**
     * Fetch draft entry content with a valid preview token.
     * GET /api/v1/preview/{collection}/{id}?token=...
     *
     * @param array<string, mixed> $args
     */
    public function getDraft(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $collectionHandle = (string) ($args['collection'] ?? $request->getAttribute('collection', ''));
        $entryId = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        $queryParams = $request->getQueryParams();
        $token = (string) ($queryParams['token'] ?? '');

        if ($token === '') {
            return $this->problem(401, 'Unauthorized', 'Preview token is required.');
        }

        $tokenRow = $this->previewTokenService->validateToken($token);
        if (!$tokenRow) {
            return $this->problem(403, 'Forbidden', 'Invalid or expired preview token.');
        }

        if (!$this->registry->has($collectionHandle)) {
            return $this->problem(404, 'Not Found', "Collection '{$collectionHandle}' not found.");
        }

        $collection = $this->registry->get($collectionHandle);
        $collectionId = (string) $collection['id'];

        if ((string) $tokenRow['collection_id'] !== $collectionId || (string) $tokenRow['entry_id'] !== $entryId) {
            return $this->problem(403, 'Forbidden', 'Preview token does not match requested entry.');
        }

        $entry = $this->repository->find($collectionHandle, $entryId);
        if (!$entry) {
            return $this->problem(404, 'Not Found', "Entry '{$entryId}' not found.");
        }

        $publicEntry = EntryPresenter::forPublic($entry, $collection);
        $publicEntry['status'] = (string) ($entry['status'] ?? 'draft');

        if ($this->seoResolver !== null) {
            $seoRes = $this->seoResolver->resolveForEntity($entry, $collectionHandle === 'posts' ? 'post' : 'page');
            $publicEntry['seo_resolved'] = $seoRes['seo_resolved'];
            $publicEntry['structured_data'] = $seoRes['structured_data'];
        }

        $response = $this->json([
            'data' => $publicEntry,
            'is_preview' => true,
        ]);

        return $response
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    /**
     * Revoke a preview token.
     * DELETE /api/v1/preview/token
     */
    public function revokeToken(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->getActor($request);
        $body = (array) $request->getParsedBody();
        $token = (string) ($body['token'] ?? '');

        if ($token === '') {
            $queryParams = $request->getQueryParams();
            $token = (string) ($queryParams['token'] ?? '');
        }

        if ($token === '') {
            return $this->problem(422, 'Unprocessable Content', 'Token parameter is required.');
        }

        $tokenRow = $this->previewTokenService->findToken($token);
        if (!$tokenRow) {
            return $this->json([
                'revoked' => false,
                'message' => 'Token not found or already revoked.',
            ]);
        }

        // Revocation authorization: only creator or admin/owner can revoke
        $isCreator = ((string) ($tokenRow['created_by'] ?? '')) === $actor->id;
        $isAdminOrOwner = $this->isAdminOrOwner($actor);

        if (!$isCreator && !$isAdminOrOwner) {
            return $this->problem(403, 'Forbidden', 'Permission denied to revoke this preview token.');
        }

        $revoked = $this->previewTokenService->revokeToken($token);

        return $this->json([
            'revoked' => $revoked,
            'message' => $revoked ? 'Preview token revoked.' : 'Token not found or already revoked.',
        ]);
    }

    private function isAdminOrOwner(Actor $actor): bool
    {
        return $actor->isSystem()
            || $actor->hasRole(SystemRoles::ROLE_OWNER)
            || $actor->hasRole(SystemRoles::ROLE_ADMIN)
            || $actor->hasRole('owner')
            || $actor->hasRole('admin');
    }
}
