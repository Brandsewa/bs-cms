<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Seo\SeoResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PublicSeoController extends BaseController
{
    public function __construct(
        private readonly SeoResolver $resolver
    ) {
    }

    /**
     * GET /api/v1/public/seo/resolve?path=...
     */
    public function resolve(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $path = (string) ($params['path'] ?? '');

        if ($path === '') {
            return $this->problem(422, 'Validation Failed', "The 'path' query parameter is required.");
        }

        $resolved = $this->resolver->resolvePath($path);
        if ($resolved === null) {
            return $this->problem(404, 'Not Found', "No content found for path '{$path}'.");
        }

        return $this->json([
            'path' => $path,
            'entity_type' => $resolved['entity_type'],
            'entity_id' => $resolved['entity_id'],
            'seo_resolved' => $resolved['seo_resolved'],
            'structured_data' => $resolved['structured_data'],
        ], 200, [
            'Cache-Control' => 'public, max-age=60, stale-while-revalidate=600',
        ]);
    }
}
