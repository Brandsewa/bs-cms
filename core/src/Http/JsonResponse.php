<?php

declare(strict_types=1);

namespace BsCms\Http;

use BsCms\Support\Json;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;

/**
 * Factory for creating PSR-7 JSON responses.
 */
final class JsonResponse
{
    /**
     * Create a PSR-7 Response containing JSON data.
     *
     * @param mixed $data
     * @param int $status
     * @param array<string, string|list<string>> $headers
     * @return ResponseInterface
     */
    public static function create(
        mixed $data,
        int $status = 200,
        array $headers = []
    ): ResponseInterface {
        $json = Json::encode($data);
        $stream = Stream::create($json);

        $response = new Response($status, [], $stream);
        $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
