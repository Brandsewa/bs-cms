<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\OpenApi\OpenApiGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class OpenApiController extends BaseController
{
    public function __construct(
        private readonly OpenApiGenerator $generator
    ) {
    }

    public function spec(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->getActor($request);
        $spec = $this->generator->generate($actor);

        return $this->json($spec)
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
