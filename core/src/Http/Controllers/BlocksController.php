<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Blocks\BlockRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class BlocksController extends BaseController
{
    public function __construct(
        private readonly BlockRegistry $registry
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $blocks = array_values($this->registry->all());
        return $this->json([
            'data' => $blocks,
            'total' => count($blocks),
        ]);
    }

    public function get(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $name = (string) ($args['name'] ?? $request->getAttribute('name', ''));
        $block = $this->registry->get($name);
        if ($block === null) {
            return $this->problem(404, 'Not Found', "Block definition '{$name}' not found.");
        }

        return $this->json(['data' => $block]);
    }
}
