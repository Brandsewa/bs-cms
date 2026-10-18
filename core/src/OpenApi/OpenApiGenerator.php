<?php

declare(strict_types=1);

namespace BsCms\OpenApi;

use BsCms\Auth\Actor;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;

/**
 * Dynamically generates OpenAPI 3.1 specification for BS CMS REST API v1.
 * Reflects built-in endpoints and dynamic collections/fields from the schema registry,
 * optionally filtered by the requesting actor's permissions.
 */
final class OpenApiGenerator
{
    public function __construct(
        private readonly CollectionRegistry $collectionRegistry,
        private readonly FieldTypeRegistry $fieldTypeRegistry,
        private readonly ?Authorizer $authorizer = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(?Actor $actor = null): array
    {
        $collections = $this->collectionRegistry->all();

        // If actor is provided and authorizer is wired, filter out collections actor has no read access to
        if ($actor !== null && $this->authorizer !== null) {
            $filtered = [];
            foreach ($collections as $handle => $col) {
                if ($this->authorizer->can($actor, 'read', new Subject('collection', (string) $handle))->isAllowed()) {
                    $filtered[$handle] = $col;
                }
            }
            $collections = $filtered;
        }

        $paths = $this->buildStaticPaths();

        foreach ($collections as $handle => $collection) {
            $colPaths = $this->buildCollectionPaths((string) $handle, $collection);
            foreach ($colPaths as $path => $item) {
                $paths[$path] = $item;
            }
        }

        $components = $this->buildComponents($collections);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'BS CMS REST API',
                'version' => '1.0.0',
                'description' => 'Autonomous, ultra-fast content and schema REST API for BS CMS.',
            ],
            'servers' => [
                [
                    'url' => '/api/v1',
                    'description' => 'Current API Base Path',
                ],
            ],
            'paths' => $paths,
            'components' => $components,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildStaticPaths(): array
    {
        return [
            '/auth/login' => [
                'post' => [
                    'summary' => 'Log in with credentials',
                    'tags' => ['Authentication'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['email', 'password'],
                                    'properties' => [
                                        'email' => ['type' => 'string', 'format' => 'email'],
                                        'password' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Login successful or 2FA challenge required',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'oneOf' => [
                                            ['$ref' => '#/components/schemas/AuthSuccessResponse'],
                                            ['$ref' => '#/components/schemas/Auth2faChallengeRequiredResponse'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        '401' => ['description' => 'Invalid credentials or locked account'],
                    ],
                ],
            ],
            '/auth/logout' => [
                'post' => [
                    'summary' => 'Log out current session',
                    'tags' => ['Authentication'],
                    'responses' => [
                        '200' => [
                            'description' => 'Logged out successfully',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/StatusResponse'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/auth/session' => [
                'get' => [
                    'summary' => 'Get current authenticated session information',
                    'tags' => ['Authentication'],
                    'responses' => [
                        '200' => [
                            'description' => 'Active session details',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/SessionResponse'],
                                ],
                            ],
                        ],
                        '401' => ['description' => 'Unauthenticated'],
                    ],
                ],
            ],
            '/auth/2fa/challenge' => [
                'post' => [
                    'summary' => 'Complete 2FA challenge using TOTP or recovery code',
                    'description' => 'Takes code for either a 6-digit TOTP code or a single-use recovery code. Submitting recovery_code field is rejected.',
                    'tags' => ['Authentication'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['challenge_token', 'code'],
                                    'properties' => [
                                        'challenge_token' => ['type' => 'string'],
                                        'code' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => '2FA verified, session activated',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/AuthSuccessResponse'],
                                ],
                            ],
                        ],
                        '401' => ['description' => 'Invalid or replayed 2FA code'],
                        '422' => ['description' => 'Validation error (e.g. recovery_code field provided)'],
                    ],
                ],
            ],
            '/me' => [
                'get' => [
                    'summary' => 'Get current authenticated user profile',
                    'tags' => ['Account'],
                    'responses' => [
                        '200' => [
                            'description' => 'Profile details',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/User'],
                                ],
                            ],
                        ],
                    ],
                ],
                'put' => [
                    'summary' => 'Update user profile',
                    'tags' => ['Account'],
                    'responses' => [
                        '200' => [
                            'description' => 'Profile updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/User'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/me/preferences' => [
                'get' => [
                    'summary' => 'Get current authenticated user UI preferences',
                    'tags' => ['Account'],
                    'responses' => [
                        '200' => [
                            'description' => 'User UI preferences',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/UserPreferences'],
                                ],
                            ],
                        ],
                    ],
                ],
                'patch' => [
                    'summary' => 'Update current authenticated user UI preferences',
                    'tags' => ['Account'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/UserPreferences'],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Updated user UI preferences',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/UserPreferences'],
                                ],
                            ],
                        ],
                        '413' => ['description' => 'Payload exceeds maximum allowed preferences size of 4KB'],
                        '422' => ['description' => 'Validation error (invalid preference values)'],
                    ],
                ],
            ],
            '/users' => [
                'get' => [
                    'summary' => 'List all users',
                    'tags' => ['Users'],
                    'responses' => [
                        '200' => [
                            'description' => 'List of sanitized users without secrets',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/UserListResponse'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/roles' => [
                'get' => [
                    'summary' => 'List all roles',
                    'tags' => ['Roles'],
                    'responses' => [
                        '200' => [
                            'description' => 'List of roles',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/RoleListResponse'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/tokens' => [
                'get' => [
                    'summary' => 'List active API tokens',
                    'tags' => ['Tokens'],
                    'responses' => [
                        '200' => [
                            'description' => 'List of API tokens',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/TokenListResponse'],
                                ],
                            ],
                        ],
                    ],
                ],
                'post' => [
                    'summary' => 'Create a new API token',
                    'tags' => ['Tokens'],
                    'responses' => [
                        '201' => [
                            'description' => 'Token created',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/TokenCreatedResponse'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/tokens/{id}' => [
                'delete' => [
                    'summary' => 'Revoke API token',
                    'tags' => ['Tokens'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Token revoked',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/StatusResponse'],
                                ],
                            ],
                        ],
                        '404' => ['description' => 'Token not found or already revoked'],
                    ],
                ],
            ],
            '/audit' => [
                'get' => [
                    'summary' => 'List immutable audit log entries',
                    'tags' => ['Audit'],
                    'responses' => [
                        '200' => [
                            'description' => 'Audit log entries',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/AuditListResponse'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/audit/{id}' => [
                'get' => [
                    'summary' => 'Get single audit log entry',
                    'tags' => ['Audit'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Audit log entry details',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/AuditEntry'],
                                ],
                            ],
                        ],
                        '404' => ['description' => 'Audit entry not found'],
                    ],
                ],
            ],
            '/collections' => [
                'get' => [
                    'summary' => 'List registered collections',
                    'tags' => ['Schema'],
                    'responses' => [
                        '200' => [
                            'description' => 'Collection definitions list',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'object'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'post' => [
                    'summary' => 'Create a new collection schema',
                    'tags' => ['Schema'],
                    'responses' => [
                        '201' => [
                            'description' => 'Collection created',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['type' => 'object'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/collections/{handle}' => [
                'get' => [
                    'summary' => 'Get collection schema definition',
                    'tags' => ['Schema'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Collection schema',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['type' => 'object'],
                                ],
                            ],
                        ],
                    ],
                ],
                'put' => [
                    'summary' => 'Alter collection schema definition',
                    'tags' => ['Schema'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Collection schema altered',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['type' => 'object'],
                                ],
                            ],
                        ],
                        '409' => ['description' => 'Confirmation required for destructive alteration'],
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete collection (drop table and remove from registry)',
                    'tags' => ['Schema'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Collection deleted',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/StatusResponse'],
                                ],
                            ],
                        ],
                        '409' => ['description' => 'Confirmation required'],
                    ],
                ],
            ],
            '/schema/plan' => [
                'post' => [
                    'summary' => 'Plan collection changes and produce migration proposal',
                    'tags' => ['Schema'],
                    'responses' => [
                        '200' => [
                            'description' => 'Proposed plan details and change_id',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'change_id' => ['type' => 'string'],
                                            'destructive' => ['type' => 'boolean'],
                                            'operations' => ['type' => 'array', 'items' => ['type' => 'object']],
                                        ],
                                        'required' => ['change_id'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/schema/apply/{change_id}' => [
                'post' => [
                    'summary' => 'Apply proposed schema change',
                    'tags' => ['Schema'],
                    'parameters' => [
                        ['name' => 'change_id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Schema change applied successfully',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/StatusResponse'],
                                ],
                            ],
                        ],
                        '409' => ['description' => 'Confirmation required for data loss or already applied'],
                    ],
                ],
            ],
            '/schema/changes' => [
                'get' => [
                    'summary' => 'List schema change history',
                    'tags' => ['Schema'],
                    'responses' => [
                        '200' => [
                            'description' => 'Schema changes history',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
                                        ],
                                        'required' => ['items'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/saved-views' => [
                'get' => [
                    'summary' => 'List saved views for current user',
                    'tags' => ['Views'],
                    'responses' => [
                        '200' => [
                            'description' => 'User saved views',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
                                        ],
                                        'required' => ['items'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'post' => [
                    'summary' => 'Create a saved view',
                    'tags' => ['Views'],
                    'responses' => [
                        '201' => [
                            'description' => 'Saved view created',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['type' => 'object'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/saved-views/{id}' => [
                'get' => [
                    'summary' => 'Get saved view details',
                    'tags' => ['Views'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Saved view details',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['type' => 'object'],
                                ],
                            ],
                        ],
                    ],
                ],
                'put' => [
                    'summary' => 'Update saved view',
                    'tags' => ['Views'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Saved view updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['type' => 'object'],
                                ],
                            ],
                        ],
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete saved view',
                    'tags' => ['Views'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Saved view deleted',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/StatusResponse'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/jobs/{id}' => [
                'get' => [
                    'summary' => 'Check background job progress and status',
                    'tags' => ['Jobs'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Job status payload',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'id' => ['type' => 'string'],
                                            'type' => ['type' => 'string'],
                                            'status' => ['type' => 'string', 'enum' => ['pending', 'running', 'completed', 'failed']],
                                            'progress' => ['type' => 'integer'],
                                            'result' => ['type' => 'object', 'nullable' => true],
                                            'error' => ['type' => 'string', 'nullable' => true],
                                        ],
                                        'required' => ['id', 'status'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/webhooks' => [
                'get' => [
                    'summary' => 'List configured webhooks',
                    'tags' => ['Webhooks'],
                    'responses' => [
                        '200' => [
                            'description' => 'Webhook configurations',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
                                        ],
                                        'required' => ['items'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'post' => [
                    'summary' => 'Create a webhook',
                    'tags' => ['Webhooks'],
                    'responses' => [
                        '201' => [
                            'description' => 'Webhook created',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['type' => 'object'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/webhooks/{id}' => [
                'get' => [
                    'summary' => 'Get webhook details',
                    'tags' => ['Webhooks'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Webhook details',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['type' => 'object'],
                                ],
                            ],
                        ],
                    ],
                ],
                'patch' => [
                    'summary' => 'Update webhook details',
                    'tags' => ['Webhooks'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Webhook updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['type' => 'object'],
                                ],
                            ],
                        ],
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete webhook',
                    'tags' => ['Webhooks'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Webhook deleted',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/StatusResponse'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/webhooks/{id}/test' => [
                'post' => [
                    'summary' => 'Send test ping delivery for webhook',
                    'tags' => ['Webhooks'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Test delivery executed',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'status' => ['type' => 'string'],
                                            'http_code' => ['type' => 'integer'],
                                            'response_body' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/webhooks/{id}/deliveries' => [
                'get' => [
                    'summary' => 'List recent deliveries for webhook',
                    'tags' => ['Webhooks'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Delivery history list',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
                                        ],
                                        'required' => ['items'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $collection
     * @return array<string, array<string, mixed>>
     */
    private function buildCollectionPaths(string $handle, array $collection): array
    {
        $kind = (string) ($collection['kind'] ?? 'collection');
        $tag = 'Collection: ' . ($collection['label_plural'] ?? ucfirst($handle));
        $entrySchemaRef = "#/components/schemas/{$handle}_Entry";

        if ($kind === 'single') {
            return [
                "/singletons/{$handle}" => [
                    'get' => [
                        'summary' => "Get singleton {$handle}",
                        'tags' => ['Singletons'],
                        'responses' => [
                            '200' => [
                                'description' => "Singleton {$handle} content",
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => $entrySchemaRef],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'put' => [
                        'summary' => "Update singleton {$handle}",
                        'tags' => ['Singletons'],
                        'responses' => [
                            '200' => [
                                'description' => "Singleton {$handle} updated",
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => $entrySchemaRef],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'patch' => [
                        'summary' => "Partially update singleton {$handle}",
                        'tags' => ['Singletons'],
                        'responses' => [
                            '200' => [
                                'description' => "Singleton {$handle} updated",
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => $entrySchemaRef],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return [
            "/collections/{$handle}/entries" => [
                'get' => [
                    'summary' => "List entries for {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'search', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                        ['name' => 'sort', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                        ['name' => 'page[size]', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 25]],
                        ['name' => 'page[after]', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'List of entries with pagination metadata',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'items' => [
                                                'type' => 'array',
                                                'items' => ['$ref' => $entrySchemaRef],
                                            ],
                                            'meta' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'total' => ['type' => 'integer'],
                                                    'count' => ['type' => 'integer'],
                                                    'cursor' => ['type' => 'string', 'nullable' => true],
                                                ],
                                            ],
                                        ],
                                        'required' => ['items'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'post' => [
                    'summary' => "Create entry in {$handle}",
                    'tags' => [$tag],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => $entrySchemaRef],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Entry created successfully',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => $entrySchemaRef],
                                ],
                            ],
                        ],
                        '422' => ['description' => 'Validation error'],
                    ],
                ],
            ],
            "/collections/{$handle}/entries/bulk" => [
                'post' => [
                    'summary' => "Bulk operation on entries in {$handle}",
                    'tags' => [$tag],
                    'responses' => [
                        '200' => [
                            'description' => 'Bulk operation completed',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'action' => ['type' => 'string'],
                                            'processed' => ['type' => 'integer'],
                                            'results' => ['type' => 'array', 'items' => ['type' => 'object']],
                                        ],
                                        'required' => ['action', 'processed', 'results'],
                                    ],
                                ],
                            ],
                        ],
                        '409' => ['description' => 'Confirmation required if bulk action count > 10'],
                    ],
                ],
            ],
            "/collections/{$handle}/entries/{id}" => [
                'get' => [
                    'summary' => "Get single entry from {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Entry details',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => $entrySchemaRef],
                                ],
                            ],
                        ],
                        '404' => ['description' => 'Entry not found'],
                    ],
                ],
                'put' => [
                    'summary' => "Full replace entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        [
                            'name' => 'If-Match',
                            'in' => 'header',
                            'required' => true,
                            'description' => 'Required current version or ETag to prevent lost updates.',
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Entry updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => $entrySchemaRef],
                                ],
                            ],
                        ],
                        '409' => ['description' => 'Optimistic concurrency version conflict'],
                        '422' => ['description' => 'Validation error (missing required fields or invalid data)'],
                        '428' => ['description' => 'Precondition Required (missing If-Match header)'],
                    ],
                ],
                'patch' => [
                    'summary' => "Partial update entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        [
                            'name' => 'If-Match',
                            'in' => 'header',
                            'required' => true,
                            'description' => 'Required current version or ETag to prevent lost updates.',
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Entry updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => $entrySchemaRef],
                                ],
                            ],
                        ],
                        '409' => ['description' => 'Optimistic concurrency version conflict'],
                        '422' => ['description' => 'Validation error'],
                        '428' => ['description' => 'Precondition Required (missing If-Match header)'],
                    ],
                ],
                'delete' => [
                    'summary' => "Move entry to trash in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Entry moved to trash',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/StatusResponse'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            "/collections/{$handle}/entries/{id}/restore" => [
                'post' => [
                    'summary' => "Restore trashed entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        [
                            'name' => 'If-Match',
                            'in' => 'header',
                            'required' => false,
                            'description' => 'Optional entry version or ETag; verified if provided (409 if stale), acts on current version if omitted.',
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Entry restored from trash',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => $entrySchemaRef],
                                ],
                            ],
                        ],
                        '409' => ['description' => 'Optimistic concurrency version conflict if stale'],
                    ],
                ],
            ],
            "/collections/{$handle}/entries/{id}/purge" => [
                'delete' => [
                    'summary' => "Permanently purge entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        [
                            'name' => 'X-Confirm-Token',
                            'in' => 'header',
                            'required' => false,
                            'description' => 'Two-step confirmation token required for permanent purge.',
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Entry permanently purged',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/StatusResponse'],
                                ],
                            ],
                        ],
                        '403' => ['description' => 'Forbidden (editor or non-permitted role)'],
                        '409' => ['description' => 'Confirmation required (returns confirm_token) or token invalid/consumed'],
                    ],
                ],
            ],
            "/collections/{$handle}/entries/{id}/duplicate" => [
                'post' => [
                    'summary' => "Duplicate entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Entry duplicated as new draft',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => $entrySchemaRef],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            "/collections/{$handle}/entries/{id}/publish" => [
                'post' => [
                    'summary' => "Publish entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        [
                            'name' => 'If-Match',
                            'in' => 'header',
                            'required' => false,
                            'description' => 'Optional entry version or ETag; verified if provided (409 if stale), acts on current version if omitted.',
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Entry published',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => $entrySchemaRef],
                                ],
                            ],
                        ],
                        '409' => ['description' => 'Optimistic concurrency version conflict if stale'],
                    ],
                ],
            ],
            "/collections/{$handle}/entries/{id}/unpublish" => [
                'post' => [
                    'summary' => "Unpublish entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        [
                            'name' => 'If-Match',
                            'in' => 'header',
                            'required' => false,
                            'description' => 'Optional entry version or ETag; verified if provided (409 if stale), acts on current version if omitted.',
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Entry reverted to draft',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => $entrySchemaRef],
                                ],
                            ],
                        ],
                        '409' => ['description' => 'Optimistic concurrency version conflict if stale'],
                    ],
                ],
            ],
            "/collections/{$handle}/entries/{id}/archive" => [
                'post' => [
                    'summary' => "Archive entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        [
                            'name' => 'If-Match',
                            'in' => 'header',
                            'required' => false,
                            'description' => 'Optional entry version or ETag; verified if provided (409 if stale), acts on current version if omitted.',
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Entry archived',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => $entrySchemaRef],
                                ],
                            ],
                        ],
                        '409' => ['description' => 'Optimistic concurrency version conflict if stale'],
                    ],
                ],
            ],
            "/collections/{$handle}/entries/{id}/schedule" => [
                'post' => [
                    'summary' => "Schedule entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        [
                            'name' => 'If-Match',
                            'in' => 'header',
                            'required' => false,
                            'description' => 'Optional entry version or ETag; verified if provided (409 if stale), acts on current version if omitted.',
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Entry scheduled',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => $entrySchemaRef],
                                ],
                            ],
                        ],
                        '409' => ['description' => 'Optimistic concurrency version conflict if stale'],
                        '422' => ['description' => 'Invalid scheduled_at date'],
                    ],
                ],
            ],
            "/collections/{$handle}/entries/{id}/submit-review" => [
                'post' => [
                    'summary' => "Submit entry for review in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        [
                            'name' => 'If-Match',
                            'in' => 'header',
                            'required' => false,
                            'description' => 'Optional entry version or ETag; verified if provided (409 if stale), acts on current version if omitted.',
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Entry submitted for review',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => $entrySchemaRef],
                                ],
                            ],
                        ],
                        '409' => ['description' => 'Optimistic concurrency version conflict if stale'],
                    ],
                ],
            ],
            "/collections/{$handle}/entries/{id}/compare" => [
                'get' => [
                    'summary' => "Compare two versions of entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'from', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
                        ['name' => 'to', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Version difference diff',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'from' => ['type' => 'integer'],
                                            'to' => ['type' => 'integer'],
                                            'diff' => ['type' => 'object'],
                                        ],
                                        'required' => ['from', 'to', 'diff'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            "/collections/{$handle}/entries/{id}/versions" => [
                'get' => [
                    'summary' => "List versions for entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Version history list',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
                                        ],
                                        'required' => ['items'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            "/public/collections/{$handle}/entries" => [
                'get' => [
                    'summary' => "Public read API for published entries in {$handle}",
                    'tags' => ['Public Content API'],
                    'parameters' => [
                        ['name' => 'sort', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                        ['name' => 'page[size]', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Published content entries with Cache-Control and ETag',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'items' => [
                                                'type' => 'array',
                                                'items' => ['$ref' => $entrySchemaRef],
                                            ],
                                            'meta' => ['type' => 'object'],
                                        ],
                                        'required' => ['items'],
                                    ],
                                ],
                            ],
                        ],
                        '304' => ['description' => 'Not Modified if If-None-Match header matches current ETag'],
                    ],
                ],
            ],
            "/public/collections/{$handle}/entries/by-slug/{slug}" => [
                'get' => [
                    'summary' => "Public read entry by slug in {$handle}",
                    'tags' => ['Public Content API'],
                    'parameters' => [
                        ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Published entry with Cache-Control and ETag',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => $entrySchemaRef],
                                ],
                            ],
                        ],
                        '304' => ['description' => 'Not Modified'],
                        '404' => ['description' => 'Entry not found or not published'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $collections
     * @return array<string, mixed>
     */
    private function buildComponents(array $collections): array
    {
        $schemas = [
            'User' => [
                'type' => 'object',
                'required' => ['id', 'email', 'name', 'status', 'roles', 'created_at'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'name' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['active', 'suspended', 'locked', 'invited']],
                    'avatar_media_id' => ['type' => 'string', 'nullable' => true],
                    'locale' => ['type' => 'string'],
                    'timezone' => ['type' => 'string'],
                    'two_factor_enabled' => ['type' => 'boolean'],
                    'roles' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'last_login_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                ],
            ],
            'AuthSuccessResponse' => [
                'type' => 'object',
                'required' => ['status', 'user', 'csrf_token'],
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['success']],
                    'user' => ['$ref' => '#/components/schemas/User'],
                    'csrf_token' => ['type' => 'string'],
                ],
            ],
            'Auth2faChallengeRequiredResponse' => [
                'type' => 'object',
                'required' => ['status', 'user_id', 'challenge_token'],
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['requires_2fa']],
                    'user_id' => ['type' => 'string'],
                    'challenge_token' => ['type' => 'string'],
                ],
            ],
            'SessionResponse' => [
                'type' => 'object',
                'required' => ['status', 'user', 'csrf_token'],
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['authenticated']],
                    'user' => ['$ref' => '#/components/schemas/User'],
                    'csrf_token' => ['type' => 'string'],
                ],
            ],
            'UserPreferences' => [
                'type' => 'object',
                'properties' => [
                    'theme' => ['type' => 'string', 'enum' => ['light', 'dark', 'system']],
                    'density' => ['type' => 'string', 'enum' => ['compact', 'comfortable', 'spacious']],
                    'locale' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
            'UserListResponse' => [
                'type' => 'object',
                'required' => ['items'],
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/User'],
                    ],
                    'next_cursor' => ['type' => 'string', 'nullable' => true],
                ],
            ],
            'Role' => [
                'type' => 'object',
                'required' => ['id', 'handle', 'name', 'permissions'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'handle' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string', 'nullable' => true],
                    'is_system' => ['type' => 'boolean'],
                    'is_ai' => ['type' => 'boolean'],
                    'permissions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['action', 'subject'],
                            'properties' => [
                                'action' => ['type' => 'string'],
                                'subject' => ['type' => 'string'],
                                'fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'nullable' => true],
                                'conditions' => ['type' => 'object', 'nullable' => true],
                                'inverted' => ['type' => 'boolean'],
                            ],
                        ],
                    ],
                ],
            ],
            'RoleListResponse' => [
                'type' => 'object',
                'required' => ['items'],
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/Role'],
                    ],
                ],
            ],
            'ApiToken' => [
                'type' => 'object',
                'required' => ['id', 'name', 'scopes', 'ai_mode', 'created_at'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'role_id' => ['type' => 'string', 'nullable' => true],
                    'scopes' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'ai_mode' => ['type' => 'string', 'enum' => ['read_only', 'draft_only', 'full_access']],
                    'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'last_used_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'TokenCreatedResponse' => [
                'type' => 'object',
                'required' => ['id', 'name', 'plain_text_token', 'scopes', 'ai_mode', 'created_at'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'plain_text_token' => ['type' => 'string'],
                    'role_id' => ['type' => 'string', 'nullable' => true],
                    'scopes' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'ai_mode' => ['type' => 'string'],
                    'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'TokenListResponse' => [
                'type' => 'object',
                'required' => ['items'],
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/ApiToken'],
                    ],
                ],
            ],
            'AuditEntry' => [
                'type' => 'object',
                'required' => ['id', 'request_id', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'summary', 'result', 'created_at'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'request_id' => ['type' => 'string'],
                    'actor_type' => ['type' => 'string'],
                    'actor_id' => ['type' => 'string'],
                    'actor_label' => ['type' => 'string', 'nullable' => true],
                    'on_behalf_of' => ['type' => 'string', 'nullable' => true],
                    'action' => ['type' => 'string'],
                    'subject_type' => ['type' => 'string'],
                    'subject_id' => ['type' => 'string'],
                    'collection_key' => ['type' => 'string', 'nullable' => true],
                    'summary' => ['type' => 'string'],
                    'diff' => ['type' => 'object', 'nullable' => true],
                    'ip' => ['type' => 'string', 'nullable' => true],
                    'user_agent' => ['type' => 'string', 'nullable' => true],
                    'result' => ['type' => 'string'],
                    'created_at' => ['type' => 'string'],
                ],
            ],
            'AuditListResponse' => [
                'type' => 'object',
                'required' => ['items'],
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/AuditEntry'],
                    ],
                    'next_cursor' => ['type' => 'integer', 'nullable' => true],
                ],
            ],
            'StatusResponse' => [
                'type' => 'object',
                'required' => ['status'],
                'properties' => [
                    'status' => ['type' => 'string'],
                ],
            ],
            'ProblemDetails' => [
                'type' => 'object',
                'required' => ['type', 'title', 'status', 'detail'],
                'properties' => [
                    'type' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'integer'],
                    'detail' => ['type' => 'string'],
                    'instance' => ['type' => 'string'],
                    'invalid_params' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'reason' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($collections as $handle => $col) {
            $properties = [
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'in_review', 'scheduled', 'published', 'archived'],
                ],
            ];

            $fields = (array) ($col['fields'] ?? []);
            foreach ($fields as $fieldHandle => $f) {
                $type = (string) ($f['type'] ?? 'text');
                if ($this->fieldTypeRegistry->has($type)) {
                    $fieldType = $this->fieldTypeRegistry->get($type);
                    $config = (array) ($f['config'] ?? []);
                    try {
                        $fieldJsonSchema = $fieldType->jsonSchema($config);
                        $properties[(string) $fieldHandle] = $fieldJsonSchema;
                    } catch (\Throwable) {
                        $properties[(string) $fieldHandle] = ['type' => 'string'];
                    }
                }
            }

            $schemas["{$handle}_Entry"] = [
                'type' => 'object',
                'properties' => $properties,
            ];
        }

        return [
            'schemas' => $schemas,
            'securitySchemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'bstok_*',
                ],
                'cookieAuth' => [
                    'type' => 'apiKey',
                    'in' => 'cookie',
                    'name' => '__Host-bs_session',
                ],
            ],
        ];
    }
}
