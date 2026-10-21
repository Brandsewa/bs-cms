<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller for retrieving registered block definitions.
 */
class BlocksController extends BaseController
{
    /**
     * Return registered block definitions.
     */
    public function getBlocks(ServerRequestInterface $request): ResponseInterface
    {
        $registryPath = dirname(__DIR__, 2) . '/data/blocks.json';
        if (file_exists($registryPath)) {
            $raw = file_get_contents($registryPath);
            $blocks = json_decode($raw, true) ?: [];
        } else {
            $blocks = [];
        }

        return $this->jsonResponse($blocks);
    }
}
