<?php

declare(strict_types=1);

/**
 * BS CMS — Spike B: Stateless MCP Server on Shared Hosting
 * Implements Model Context Protocol over stateless Streamable HTTP (JSON-RPC 2.0).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'jsonrpc' => '2.0',
        'error' => ['code' => -32600, 'message' => 'Method not allowed. MCP requires POST.'],
        'id' => null,
    ]);
    exit;
}

// Optional Auth check for testing unauthenticated vs authenticated handshake
$requireAuth = isset($_GET['auth']) && $_GET['auth'] === '1';
if ($requireAuth) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $metadataUrl = "{$proto}://{$host}/.well-known/oauth-protected-resource";
        header("WWW-Authenticate: Bearer resource_metadata=\"{$metadataUrl}\"");
        echo json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32001, 'message' => 'Unauthorized. Bearer token required.'],
            'id' => null,
        ]);
        exit;
    }
}

$rawInput = file_get_contents('php://input');
if ($rawInput === '' && defined('STDIN')) {
    $rawInput = stream_get_contents(STDIN);
}
$request = json_decode($rawInput, true);


if (!is_array($request) || ($request['jsonrpc'] ?? '') !== '2.0') {
    http_response_code(400);
    echo json_encode([
        'jsonrpc' => '2.0',
        'error' => ['code' => -32700, 'message' => 'Parse error: Valid JSON-RPC 2.0 payload required.'],
        'id' => null,
    ]);
    exit;
}

$method = (string)($request['method'] ?? '');
$id = $request['id'] ?? null;
$params = $request['params'] ?? [];

switch ($method) {
    case 'initialize':
        $response = [
            'jsonrpc' => '2.0',
            'result' => [
                'protocolVersion' => '2024-11-05',
                'serverInfo' => [
                    'name' => 'BS CMS MCP Server',
                    'version' => '0.1.0-spike',
                ],
                'capabilities' => [
                    'tools' => [
                        'listChanged' => false,
                    ],
                ],
            ],
            'id' => $id,
        ];
        break;

    case 'tools/list':
        $response = [
            'jsonrpc' => '2.0',
            'result' => [
                'tools' => [
                    [
                        'name' => 'ping',
                        'description' => 'Verify stateless connectivity to the CMS server.',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => [
                                    'type' => 'string',
                                    'description' => 'Optional ping echo message.',
                                ],
                            ],
                        ],
                    ],
                    [
                        'name' => 'list_posts',
                        'description' => 'List recent posts from BS CMS.',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'limit' => [
                                    'type' => 'integer',
                                    'description' => 'Maximum number of posts to return (1-50).',
                                    'default' => 10,
                                ],
                                'status' => [
                                    'type' => 'string',
                                    'description' => 'Filter by post status (published, draft, scheduled).',
                                    'default' => 'published',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'id' => $id,
        ];
        break;

    case 'tools/call':
        $toolName = (string)($params['name'] ?? '');
        $arguments = (array)($params['arguments'] ?? []);

        if ($toolName === 'ping') {
            $echo = (string)($arguments['message'] ?? 'pong');
            $response = [
                'jsonrpc' => '2.0',
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "Pong! Server time: " . gmdate('Y-m-d H:i:s') . " UTC. Received: " . $echo,
                        ],
                    ],
                ],
                'id' => $id,
            ];
        } elseif ($toolName === 'list_posts') {
            $limit = min(50, max(1, (int)($arguments['limit'] ?? 10)));
            $status = (string)($arguments['status'] ?? 'published');

            // Sample posts (or from DB if configured)
            $posts = [];
            for ($i = 1; $i <= $limit; $i++) {
                $posts[] = [
                    'id' => "01J" . strtoupper(bin2hex(random_bytes(10))),
                    'slug' => "post-{$i}",
                    'title' => "Sample Post {$i} from BS CMS",
                    'status' => $status,
                    'reading_time' => 5,
                    'published_at' => gmdate('Y-m-d H:i:s', time() - ($i * 3600)),
                ];
            }

            $response = [
                'jsonrpc' => '2.0',
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode($posts, JSON_PRETTY_PRINT),
                        ],
                    ],
                ],
                'id' => $id,
            ];
        } else {
            $response = [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32601,
                    'message' => "Tool not found: {$toolName}",
                ],
                'id' => $id,
            ];
        }
        break;

    default:
        $response = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32601,
                'message' => "Method not supported: {$method}",
            ],
            'id' => $id,
        ];
        break;
}

echo json_encode($response, JSON_THROW_ON_ERROR);
