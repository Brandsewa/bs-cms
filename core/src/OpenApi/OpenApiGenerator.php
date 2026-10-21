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
            // Taxonomies
            '/taxonomies' => [
                'get' => [
                    'summary' => 'List taxonomies',
                    'tags' => ['Taxonomies'],
                    'responses' => [
                        '200' => [
                            'description' => 'List of taxonomies',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'array',
                                        'items' => ['$ref' => '#/components/schemas/Taxonomy'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'post' => [
                    'summary' => 'Create a taxonomy',
                    'tags' => ['Taxonomies'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['handle', 'name'],
                                    'properties' => [
                                        'handle' => ['type' => 'string'],
                                        'name' => ['type' => 'string'],
                                        'description' => ['type' => 'string', 'nullable' => true],
                                        'is_hierarchical' => ['type' => 'boolean'],
                                        'allow_custom_meta' => ['type' => 'boolean'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Taxonomy created',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Taxonomy'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/taxonomies/{handle}' => [
                'get' => [
                    'summary' => 'Get taxonomy by handle',
                    'tags' => ['Taxonomies'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Taxonomy details',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Taxonomy'],
                                ],
                            ],
                        ],
                    ],
                ],
                'patch' => [
                    'summary' => 'Update taxonomy definition',
                    'tags' => ['Taxonomies'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => ['type' => 'string'],
                                        'description' => ['type' => 'string', 'nullable' => true],
                                        'is_hierarchical' => ['type' => 'boolean'],
                                        'allow_custom_meta' => ['type' => 'boolean'],
                                        'default_term_id' => ['type' => 'string', 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Taxonomy updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Taxonomy'],
                                ],
                            ],
                        ],
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete taxonomy definition',
                    'tags' => ['Taxonomies'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Taxonomy deleted',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'message' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/taxonomies/{handle}/attach' => [
                'post' => [
                    'summary' => 'Attach taxonomy to collection',
                    'tags' => ['Taxonomies'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['collection_handle'],
                                    'properties' => [
                                        'collection_handle' => ['type' => 'string'],
                                        'field_key' => ['type' => 'string'],
                                        'required' => ['type' => 'boolean'],
                                        'allow_multiple' => ['type' => 'boolean'],
                                        'position' => ['type' => 'integer'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Taxonomy attached to collection',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'message' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/taxonomies/{handle}/terms' => [
                'get' => [
                    'summary' => 'List terms tree for taxonomy',
                    'tags' => ['Terms'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'parent_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Terms hierarchy',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'array',
                                        'items' => ['$ref' => '#/components/schemas/Term'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'post' => [
                    'summary' => 'Create term in taxonomy',
                    'tags' => ['Terms'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['name'],
                                    'properties' => [
                                        'name' => ['type' => 'string'],
                                        'slug' => ['type' => 'string'],
                                        'description' => ['type' => 'string', 'nullable' => true],
                                        'parent_id' => ['type' => 'string', 'nullable' => true],
                                        'meta' => ['type' => 'object', 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Term created',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Term'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/taxonomies/{handle}/terms/{id}' => [
                'get' => [
                    'summary' => 'Get term by ID',
                    'tags' => ['Terms'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Term details',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Term'],
                                ],
                            ],
                        ],
                    ],
                ],
                'patch' => [
                    'summary' => 'Update term',
                    'tags' => ['Terms'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => ['type' => 'string'],
                                        'slug' => ['type' => 'string'],
                                        'description' => ['type' => 'string', 'nullable' => true],
                                        'meta' => ['type' => 'object', 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Term updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Term'],
                                ],
                            ],
                        ],
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete term',
                    'tags' => ['Terms'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'reassign_to', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Term deleted',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'message' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/taxonomies/{handle}/terms/{id}/move' => [
                'post' => [
                    'summary' => 'Move term in tree',
                    'tags' => ['Terms'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'parent_id' => ['type' => 'string', 'nullable' => true],
                                        'position' => ['type' => 'integer'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Term moved',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Term'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/taxonomies/{handle}/terms/{id}/merge' => [
                'post' => [
                    'summary' => 'Merge term into target term',
                    'tags' => ['Terms'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['target_term_id'],
                                    'properties' => [
                                        'target_term_id' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Term merged',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'message' => ['type' => 'string'],
                                        ],
                                        'required' => ['message'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/taxonomies/{handle}/terms/reorder' => [
                'post' => [
                    'summary' => 'Reorder terms in taxonomy',
                    'tags' => ['Terms'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'terms' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'required' => ['id', 'position'],
                                                'properties' => [
                                                    'id' => ['type' => 'string'],
                                                    'position' => ['type' => 'integer'],
                                                    'parent_id' => ['type' => 'string', 'nullable' => true],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Terms reordered',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'message' => ['type' => 'string'],
                                        ],
                                        'required' => ['message'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/taxonomies/{handle}/terms/bulk' => [
                'post' => [
                    'summary' => 'Bulk term operations',
                    'tags' => ['Terms'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['action', 'ids'],
                                    'properties' => [
                                        'action' => ['type' => 'string', 'enum' => ['delete']],
                                        'ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                                        'reassign_to' => ['type' => 'string', 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Bulk operation completed',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'affected' => ['type' => 'integer'],
                                            'action' => ['type' => 'string'],
                                        ],
                                        'required' => ['affected', 'action'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/public/taxonomies/{handle}/terms' => [
                'get' => [
                    'summary' => 'Public terms hierarchy listing',
                    'tags' => ['Public'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Public terms tree',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'array',
                                        'items' => ['$ref' => '#/components/schemas/Term'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            // Media Folders
            '/media/folders' => [
                'get' => [
                    'summary' => 'List media folders',
                    'tags' => ['Media Folders'],
                    'parameters' => [
                        ['name' => 'parent_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Media folders list',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'array',
                                        'items' => ['$ref' => '#/components/schemas/MediaFolder'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'post' => [
                    'summary' => 'Create media folder',
                    'tags' => ['Media Folders'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['name'],
                                    'properties' => [
                                        'name' => ['type' => 'string'],
                                        'parent_id' => ['type' => 'string', 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Media folder created',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/MediaFolder'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/media/folders/{id}' => [
                'patch' => [
                    'summary' => 'Update media folder',
                    'tags' => ['Media Folders'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => ['type' => 'string'],
                                        'parent_id' => ['type' => 'string', 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Media folder updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/MediaFolder'],
                                ],
                            ],
                        ],
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete media folder',
                    'tags' => ['Media Folders'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Media folder deleted',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'message' => ['type' => 'string'],
                                        ],
                                        'required' => ['message'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/media/folders/{id}/move' => [
                'post' => [
                    'summary' => 'Move media folder under new parent',
                    'tags' => ['Media Folders'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'parent_id' => ['type' => 'string', 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Media folder moved',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/MediaFolder'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            // Media
            '/media' => [
                'get' => [
                    'summary' => 'List media assets',
                    'tags' => ['Media'],
                    'parameters' => [
                        ['name' => 'folder_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                        ['name' => 'search', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                        ['name' => 'type', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['image', 'video', 'audio', 'document']]],
                        ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 1]],
                        ['name' => 'per_page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 50]],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Paginated media list',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['items', 'total', 'page', 'per_page', 'last_page'],
                                        'properties' => [
                                            'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Media']],
                                            'total' => ['type' => 'integer'],
                                            'page' => ['type' => 'integer'],
                                            'per_page' => ['type' => 'integer'],
                                            'last_page' => ['type' => 'integer'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'post' => [
                    'summary' => 'Upload media file',
                    'tags' => ['Media'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'multipart/form-data' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['file'],
                                    'properties' => [
                                        'file' => ['type' => 'string', 'format' => 'binary'],
                                        'folder_id' => ['type' => 'string', 'nullable' => true],
                                        'title' => ['type' => 'string', 'nullable' => true],
                                        'alt_text' => ['type' => 'string', 'nullable' => true],
                                        'caption' => ['type' => 'string', 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Media asset uploaded',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Media'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/media/bulk' => [
                'post' => [
                    'summary' => 'Bulk operations on media assets',
                    'tags' => ['Media'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['action', 'ids'],
                                    'properties' => [
                                        'action' => ['type' => 'string', 'enum' => ['delete', 'move']],
                                        'ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                                        'folder_id' => ['type' => 'string', 'nullable' => true],
                                        'force' => ['type' => 'boolean'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Bulk operation completed',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'affected' => ['type' => 'integer'],
                                            'action' => ['type' => 'string'],
                                        ],
                                        'required' => ['affected', 'action'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/media/chunks/init' => [
                'post' => [
                    'summary' => 'Initiate chunked upload session',
                    'tags' => ['Media'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['original_name', 'total_chunks', 'total_size'],
                                    'properties' => [
                                        'original_name' => ['type' => 'string', 'description' => 'Original client filename'],
                                        'total_chunks' => ['type' => 'integer', 'description' => 'Total number of chunks'],
                                        'total_size' => ['type' => 'integer', 'description' => 'Total size in bytes'],
                                        'folder_id' => ['type' => 'string', 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Chunk session initiated',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'session_id' => ['type' => 'string'],
                                        ],
                                        'required' => ['session_id'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/media/chunks/{id}' => [
                'get' => [
                    'summary' => 'Get chunked upload progress',
                    'tags' => ['Media'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Chunk status',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'session_id' => ['type' => 'string'],
                                            'received_chunks' => ['type' => 'array', 'items' => ['type' => 'integer']],
                                            'total_chunks' => ['type' => 'integer'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'post' => [
                    'summary' => 'Upload a chunk part',
                    'tags' => ['Media'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'multipart/form-data' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['chunk', 'chunk_index'],
                                    'properties' => [
                                        'chunk' => ['type' => 'string', 'format' => 'binary', 'description' => 'Chunk binary payload'],
                                        'chunk_index' => ['type' => 'integer', 'description' => '0-based sequence index'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Chunk saved',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'status' => ['type' => 'string'],
                                            'chunk_index' => ['type' => 'integer'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/media/chunks/{id}/assemble' => [
                'post' => [
                    'summary' => 'Assemble uploaded chunks into media asset',
                    'tags' => ['Media'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'title' => ['type' => 'string', 'nullable' => true],
                                        'alt_text' => ['type' => 'string', 'nullable' => true],
                                        'caption' => ['type' => 'string', 'nullable' => true],
                                        'folder_id' => ['type' => 'string', 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Media asset assembled and stored',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Media'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/media/{id}' => [
                'get' => [
                    'summary' => 'Get media asset by ID',
                    'tags' => ['Media'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Media asset details',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Media'],
                                ],
                            ],
                        ],
                    ],
                ],
                'patch' => [
                    'summary' => 'Update media asset metadata',
                    'tags' => ['Media'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'title' => ['type' => 'string', 'nullable' => true],
                                        'alt_text' => ['type' => 'string', 'nullable' => true],
                                        'caption' => ['type' => 'string', 'nullable' => true],
                                        'folder_id' => ['type' => 'string', 'nullable' => true],
                                        'focal_x' => ['type' => 'number', 'nullable' => true],
                                        'focal_y' => ['type' => 'number', 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Media asset updated',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Media'],
                                ],
                            ],
                        ],
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete media asset',
                    'tags' => ['Media'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'force', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Media asset deleted',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'message' => ['type' => 'string'],
                                        ],
                                        'required' => ['message'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/media/{id}/replace' => [
                'post' => [
                    'summary' => 'Replace media file keeping ID intact',
                    'tags' => ['Media'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'multipart/form-data' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['file'],
                                    'properties' => [
                                        'file' => ['type' => 'string', 'format' => 'binary'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Media asset replaced',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Media'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/media/{id}/usage' => [
                'get' => [
                    'summary' => 'List content entries referencing media asset',
                    'tags' => ['Media'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Usage list',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'usages' => [
                                                'type' => 'array',
                                                'items' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'collection_id' => ['type' => 'string'],
                                                        'collection_handle' => ['type' => 'string'],
                                                        'collection_name' => ['type' => 'string'],
                                                        'entry_id' => ['type' => 'string'],
                                                        'entry_title' => ['type' => 'string'],
                                                        'field_handle' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'required' => ['usages'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/media/{id}/file' => [
                'get' => [
                    'summary' => 'Stream media asset with authenticated security headers and ETag',
                    'tags' => ['Media'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'variant', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Binary file stream with nosniff and frame protection headers',
                            'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
                        ],
                        '304' => ['description' => 'Not Modified (ETag matched)'],
                        '404' => ['description' => 'Media file not found'],
                    ],
                ],
            ],
            '/public/media/{id}/file' => [
                'get' => [
                    'summary' => 'Publicly stream media asset with CDN bypass security headers and ETag',
                    'tags' => ['Media'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'variant', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Binary file stream with nosniff and frame protection headers',
                            'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
                        ],
                        '304' => ['description' => 'Not Modified (ETag matched)'],
                        '404' => ['description' => 'Media file not found'],
                    ],
                ],
            ],
            '/redirects' => [
                'get' => [
                    'summary' => 'List redirect rules with filtering and pagination',
                    'tags' => ['Redirects'],
                    'parameters' => [
                        ['name' => 'search', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                        ['name' => 'match_type', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['exact', 'prefix']]],
                        ['name' => 'status_code', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                        ['name' => 'is_auto', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']],
                        ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 1]],
                        ['name' => 'per_page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 50]],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Paginated redirects list',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['items', 'total', 'page', 'per_page', 'last_page'],
                                        'properties' => [
                                            'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Redirect']],
                                            'total' => ['type' => 'integer'],
                                            'page' => ['type' => 'integer'],
                                            'per_page' => ['type' => 'integer'],
                                            'last_page' => ['type' => 'integer'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'post' => [
                    'summary' => 'Create a redirect rule (exact or prefix, loops prevented)',
                    'tags' => ['Redirects'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['from_path', 'to_path'],
                                    'properties' => [
                                        'from_path' => ['type' => 'string'],
                                        'to_path' => ['type' => 'string'],
                                        'status_code' => ['type' => 'integer', 'enum' => [301, 302, 307, 308], 'default' => 301],
                                        'match_type' => ['type' => 'string', 'enum' => ['exact', 'prefix'], 'default' => 'exact'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Redirect created',
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Redirect']]],
                        ],
                        '409' => ['description' => 'Redirect conflict - rule with from_path and match_type already exists'],
                        '422' => ['description' => 'Validation failed or circular redirect loop detected'],
                    ],
                ],
            ],
            '/redirects/bulk' => [
                'post' => [
                    'summary' => 'Bulk delete redirects',
                    'tags' => ['Redirects'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['ids'],
                                    'properties' => [
                                        'ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Bulk delete results',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['deleted', 'requested'],
                                        'properties' => [
                                            'deleted' => ['type' => 'integer'],
                                            'requested' => ['type' => 'integer'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/redirects/import' => [
                'post' => [
                    'summary' => 'Import redirect rules from CSV',
                    'tags' => ['Redirects'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'text/csv' => ['schema' => ['type' => 'string']],
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['csv'],
                                    'properties' => [
                                        'csv' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Import summary',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['imported', 'skipped', 'errors'],
                                        'properties' => [
                                            'imported' => ['type' => 'integer'],
                                            'skipped' => ['type' => 'integer'],
                                            'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/redirects/export' => [
                'get' => [
                    'summary' => 'Export all redirect rules as CSV',
                    'tags' => ['Redirects'],
                    'responses' => [
                        '200' => [
                            'description' => 'CSV file containing all redirects',
                            'content' => ['text/csv' => ['schema' => ['type' => 'string']]],
                        ],
                    ],
                ],
            ],
            '/redirects/{id}' => [
                'get' => [
                    'summary' => 'Get redirect by ID',
                    'tags' => ['Redirects'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Redirect rule',
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Redirect']]],
                        ],
                        '404' => ['description' => 'Redirect not found'],
                    ],
                ],
                'patch' => [
                    'summary' => 'Update redirect rule',
                    'tags' => ['Redirects'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'from_path' => ['type' => 'string'],
                                        'to_path' => ['type' => 'string'],
                                        'status_code' => ['type' => 'integer', 'enum' => [301, 302, 307, 308]],
                                        'match_type' => ['type' => 'string', 'enum' => ['exact', 'prefix']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Updated redirect',
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Redirect']]],
                        ],
                        '404' => ['description' => 'Redirect not found'],
                        '409' => ['description' => 'Redirect conflict - rule with from_path and match_type already exists'],
                        '422' => ['description' => 'Validation error or loop detected'],
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete redirect rule',
                    'tags' => ['Redirects'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Deleted successfully',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['deleted', 'id'],
                                        'properties' => [
                                            'deleted' => ['type' => 'boolean'],
                                            'id' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        '404' => ['description' => 'Redirect not found'],
                    ],
                ],
            ],
            '/public/redirects' => [
                'get' => [
                    'summary' => 'List active redirects for Astro build and .htaccess export',
                    'tags' => ['Redirects'],
                    'responses' => [
                        '200' => [
                            'description' => 'Active redirect routes',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['items'],
                                        'properties' => [
                                            'items' => [
                                                'type' => 'array',
                                                'items' => [
                                                    'type' => 'object',
                                                    'required' => ['from_path', 'to_path', 'status_code', 'match_type'],
                                                    'properties' => [
                                                        'from_path' => ['type' => 'string'],
                                                        'to_path' => ['type' => 'string'],
                                                        'status_code' => ['type' => 'integer'],
                                                        'match_type' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/public/redirects/match' => [
                'get' => [
                    'summary' => 'Match path against active redirects and increment hit counter',
                    'tags' => ['Redirects'],
                    'parameters' => [
                        ['name' => 'path', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Matching redirect destination',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['id', 'destination', 'status_code', 'match_type'],
                                        'properties' => [
                                            'id' => ['type' => 'string'],
                                            'destination' => ['type' => 'string'],
                                            'status_code' => ['type' => 'integer'],
                                            'match_type' => ['type' => 'string', 'enum' => ['exact', 'prefix']],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        '404' => ['description' => 'No active redirect for path'],
                    ],
                ],
            ],
            '/public/seo/resolve' => [
                'get' => [
                    'summary' => 'Resolve path to canonical SEO and Schema.org @graph (CDN-fresh up to 60s)',
                    'tags' => ['SEO'],
                    'parameters' => [
                        ['name' => 'path', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Resolved SEO and structured data',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['path', 'entity_type', 'entity_id', 'seo_resolved', 'structured_data'],
                                        'properties' => [
                                            'path' => ['type' => 'string'],
                                            'entity_type' => ['type' => 'string'],
                                            'entity_id' => ['type' => 'string'],
                                            'seo_resolved' => ['$ref' => '#/components/schemas/SeoResolved'],
                                            'structured_data' => [
                                                'type' => 'object',
                                                'required' => ['@context', '@graph'],
                                                'properties' => [
                                                    '@context' => ['type' => 'string'],
                                                    '@graph' => ['type' => 'array', 'items' => ['type' => 'object']],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        '404' => ['description' => 'Path not found'],
                    ],
                ],
            ],
            '/site-health' => [
                'get' => [
                    'summary' => 'Scan content and SEO health rules across collections with bounded pagination',
                    'tags' => ['Site Health'],
                    'responses' => [
                        '200' => [
                            'description' => 'Site health audit report',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/SiteHealthReport'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/site-health/run' => [
                'post' => [
                    'summary' => 'Trigger immediate site health scan',
                    'tags' => ['Site Health'],
                    'responses' => [
                        '200' => [
                            'description' => 'Site health audit report',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/SiteHealthReport'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/dashboard' => [
                'get' => [
                    'summary' => 'Get role-aware aggregate dashboard summary for administrative shell',
                    'tags' => ['Dashboard'],
                    'responses' => [
                        '200' => [
                            'description' => 'Aggregate dashboard metrics, queues, and health status',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/DashboardResponse'],
                                ],
                            ],
                        ],
                        '401' => [
                            'description' => 'Unauthenticated',
                        ],
                        '403' => [
                            'description' => 'Forbidden (OAuth and MCP tokens cannot access dashboard)',
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
            "/collections/{$handle}/counts" => [
                'get' => [
                    'summary' => "Aggregate counts for {$handle} entries by status and trash",
                    'tags' => [$tag],
                    'responses' => [
                        '200' => [
                            'description' => 'Aggregate status counts',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'all' => ['type' => 'integer', 'description' => 'Count of non-deleted entries'],
                                            'published' => ['type' => 'integer', 'description' => 'Count of published entries'],
                                            'draft' => ['type' => 'integer', 'description' => 'Count of draft entries'],
                                            'in_review' => ['type' => 'integer', 'description' => 'Count of in-review entries'],
                                            'archived' => ['type' => 'integer', 'description' => 'Count of archived entries'],
                                            'trash' => ['type' => 'integer', 'description' => 'Count of soft-deleted entries'],
                                        ],
                                        'required' => ['all', 'published', 'draft', 'in_review', 'archived', 'trash'],
                                    ],
                                ],
                            ],
                        ],
                        '403' => ['description' => 'Forbidden'],
                        '404' => ['description' => 'Collection not found'],
                    ],
                ],
            ],
            "/collections/{$handle}/entries" => [
                'get' => [
                    'summary' => "List entries for {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'search', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                        ['name' => 'sort', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                        ['name' => 'page[size]', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 25]],
                        ['name' => 'page[after]', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                        [
                            'name' => 'trashed',
                            'in' => 'query',
                            'required' => false,
                            'description' => 'Filter soft-deleted entries: "exclude" (default, non-deleted only), "only" (soft-deleted only), or "with" / "true" (both).',
                            'schema' => [
                                'type' => 'string',
                                'enum' => ['exclude', 'only', 'with', 'true', 'false'],
                                'default' => 'exclude',
                            ],
                        ],
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
            'Taxonomy' => [
                'type' => 'object',
                'required' => ['id', 'handle', 'name'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'handle' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string', 'nullable' => true],
                    'is_hierarchical' => ['type' => 'boolean'],
                    'allow_custom_meta' => ['type' => 'boolean'],
                    'default_term_id' => ['type' => 'string', 'nullable' => true],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'Term' => [
                'type' => 'object',
                'required' => ['id', 'taxonomy_id', 'name', 'slug', 'path', 'depth', 'position', 'entry_count'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'taxonomy_id' => ['type' => 'string'],
                    'parent_id' => ['type' => 'string', 'nullable' => true],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'description' => ['type' => 'string', 'nullable' => true],
                    'path' => ['type' => 'string'],
                    'depth' => ['type' => 'integer'],
                    'position' => ['type' => 'integer'],
                    'entry_count' => ['type' => 'integer'],
                    'is_locked' => ['type' => 'boolean'],
                    'meta' => ['type' => 'object', 'nullable' => true],
                    'children' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/Term'],
                    ],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'MediaFolder' => [
                'type' => 'object',
                'required' => ['id', 'name', 'path', 'created_at'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'parent_id' => ['type' => 'string', 'nullable' => true],
                    'name' => ['type' => 'string'],
                    'path' => ['type' => 'string'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'Media' => [
                'type' => 'object',
                'required' => ['id', 'filename', 'original_name', 'mime', 'extension', 'size_bytes', 'storage_path', 'url'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'folder_id' => ['type' => 'string', 'nullable' => true],
                    'filename' => ['type' => 'string'],
                    'original_name' => ['type' => 'string'],
                    'mime' => ['type' => 'string'],
                    'extension' => ['type' => 'string'],
                    'size_bytes' => ['type' => 'integer'],
                    'width' => ['type' => 'integer', 'nullable' => true],
                    'height' => ['type' => 'integer', 'nullable' => true],
                    'duration_seconds' => ['type' => 'integer', 'nullable' => true],
                    'content_hash' => ['type' => 'string'],
                    'storage_driver' => ['type' => 'string'],
                    'storage_path' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                    'alt_text' => ['type' => 'string', 'nullable' => true],
                    'caption' => ['type' => 'string', 'nullable' => true],
                    'title' => ['type' => 'string', 'nullable' => true],
                    'focal_x' => ['type' => 'number', 'nullable' => true],
                    'focal_y' => ['type' => 'number', 'nullable' => true],
                    'blurhash' => ['type' => 'string', 'nullable' => true],
                    'dominant_color' => ['type' => 'string', 'nullable' => true],
                    'usage_count' => ['type' => 'integer'],
                    'uploaded_by' => ['type' => 'string', 'nullable' => true],
                    'variants' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'variant_name' => ['type' => 'string'],
                                'width' => ['type' => 'integer'],
                                'height' => ['type' => 'integer'],
                                'format' => ['type' => 'string'],
                                'size_bytes' => ['type' => 'integer'],
                                'storage_path' => ['type' => 'string'],
                                'url' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                    'deleted_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'duplicate_of' => ['type' => 'string', 'nullable' => true],
                ],
            ],
            'Redirect' => [
                'type' => 'object',
                'required' => ['id', 'from_path', 'match_type', 'to_path', 'status_code', 'is_auto', 'hits', 'created_at'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'from_path' => ['type' => 'string'],
                    'match_type' => ['type' => 'string', 'enum' => ['exact', 'prefix']],
                    'to_path' => ['type' => 'string'],
                    'status_code' => ['type' => 'integer', 'enum' => [301, 302, 307, 308]],
                    'is_auto' => ['type' => 'boolean'],
                    'hits' => ['type' => 'integer'],
                    'last_hit_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'created_by' => ['type' => 'string', 'nullable' => true],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'SeoResolved' => [
                'type' => 'object',
                'required' => ['title', 'description', 'canonical', 'robots', 'open_graph', 'twitter'],
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'canonical' => ['type' => 'string'],
                    'robots' => [
                        'type' => 'object',
                        'required' => ['noindex', 'nofollow', 'noarchive', 'max_snippet', 'max_image_preview', 'max_video_preview'],
                        'properties' => [
                            'noindex' => ['type' => 'boolean'],
                            'nofollow' => ['type' => 'boolean'],
                            'noarchive' => ['type' => 'boolean'],
                            'max_snippet' => ['type' => 'integer'],
                            'max_image_preview' => ['type' => 'string'],
                            'max_video_preview' => ['type' => 'integer'],
                        ],
                    ],
                    'open_graph' => [
                        'type' => 'object',
                        'required' => ['title', 'description', 'type', 'url', 'image', 'site_name'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'image' => ['type' => 'string', 'nullable' => true],
                            'site_name' => ['type' => 'string'],
                        ],
                    ],
                    'twitter' => [
                        'type' => 'object',
                        'required' => ['card', 'title', 'description', 'image'],
                        'properties' => [
                            'card' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'image' => ['type' => 'string', 'nullable' => true],
                        ],
                    ],
                ],
            ],
            'SiteHealthReport' => [
                'type' => 'object',
                'required' => ['summary', 'issues', 'rules_checked', 'scanned_at'],
                'properties' => [
                    'summary' => [
                        'type' => 'object',
                        'required' => ['total', 'errors', 'warnings', 'info', 'status'],
                        'properties' => [
                            'total' => ['type' => 'integer'],
                            'errors' => ['type' => 'integer'],
                            'warnings' => ['type' => 'integer'],
                            'info' => ['type' => 'integer'],
                            'status' => ['type' => 'string', 'enum' => ['healthy', 'warning', 'critical']],
                        ],
                    ],
                    'issues' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['rule_id', 'severity', 'category', 'message', 'entity_type', 'entity_id', 'entity_title', 'fix_hint'],
                            'properties' => [
                                'rule_id' => ['type' => 'string'],
                                'severity' => ['type' => 'string', 'enum' => ['error', 'warn', 'info']],
                                'category' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                                'entity_type' => ['type' => 'string'],
                                'entity_id' => ['type' => 'string'],
                                'entity_title' => ['type' => 'string', 'nullable' => true],
                                'fix_hint' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'rules_checked' => ['type' => 'integer'],
                    'scanned_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'DashboardResponse' => [
                'type' => 'object',
                'required' => [
                    'greeting',
                    'counts',
                    'recent_edits',
                    'drafts_waiting',
                    'scheduled_items',
                    'pending_ai_changes',
                    'site_health',
                    'onboarding',
                ],
                'properties' => [
                    'greeting' => [
                        'type' => 'object',
                        'required' => ['user_name', 'environment'],
                        'properties' => [
                            'user_name' => ['type' => 'string'],
                            'environment' => ['type' => 'string'],
                        ],
                    ],
                    'counts' => [
                        'type' => 'object',
                        'required' => ['drafts', 'scheduled', 'pending_ai_changes'],
                        'properties' => [
                            'drafts' => ['type' => 'integer'],
                            'scheduled' => ['type' => 'integer'],
                            'pending_ai_changes' => ['type' => 'integer'],
                        ],
                    ],
                    'recent_edits' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'collection', 'title', 'status', 'updated_at'],
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'collection' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'status' => ['type' => 'string'],
                                'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                                'updated_by' => ['type' => 'string', 'nullable' => true],
                            ],
                        ],
                    ],
                    'drafts_waiting' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'collection', 'title', 'status', 'updated_at'],
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'collection' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'status' => ['type' => 'string'],
                                'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                            ],
                        ],
                    ],
                    'scheduled_items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'collection', 'title', 'scheduled_at'],
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'collection' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'scheduled_at' => ['type' => 'string', 'format' => 'date-time'],
                            ],
                        ],
                    ],
                    'pending_ai_changes' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'actor_label', 'action', 'created_at'],
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'actor_label' => ['type' => 'string'],
                                'action' => ['type' => 'string'],
                                'target_collection' => ['type' => 'string', 'nullable' => true],
                                'target_id' => ['type' => 'string', 'nullable' => true],
                                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                            ],
                        ],
                    ],
                    'site_health' => [
                        'type' => 'object',
                        'required' => ['status', 'total_issues', 'errors', 'warnings'],
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['healthy', 'warning', 'critical']],
                            'total_issues' => ['type' => 'integer'],
                            'errors' => ['type' => 'integer'],
                            'warnings' => ['type' => 'integer'],
                            'scanned_at' => ['type' => 'string', 'format' => 'date-time'],
                            'top_issues' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['rule_id', 'message', 'severity'],
                                    'properties' => [
                                        'rule_id' => ['type' => 'string'],
                                        'message' => ['type' => 'string'],
                                        'severity' => ['type' => 'string', 'enum' => ['error', 'warn', 'info']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'recent_activity' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'action', 'actor', 'target', 'created_at'],
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'action' => ['type' => 'string'],
                                'actor' => ['type' => 'string'],
                                'target' => ['type' => 'string'],
                                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                            ],
                        ],
                    ],
                    'system_status' => [
                        'type' => 'object',
                        'required' => ['cron', 'mail', 'disk_usage_percent', 'php_version'],
                        'properties' => [
                            'cron' => ['type' => 'string', 'enum' => ['healthy', 'warning', 'error', 'disabled']],
                            'mail' => ['type' => 'string', 'enum' => ['healthy', 'warning', 'error', 'unconfigured']],
                            'disk_usage_percent' => ['type' => 'integer'],
                            'php_version' => ['type' => 'string'],
                        ],
                    ],
                    'onboarding' => [
                        'type' => 'object',
                        'required' => ['completed', 'steps'],
                        'properties' => [
                            'completed' => ['type' => 'boolean'],
                            'dismissed' => ['type' => 'boolean'],
                            'steps' => [
                                'type' => 'object',
                                'required' => [
                                    'create_page',
                                    'write_post',
                                    'site_details',
                                    'configure_email',
                                    'connect_astro',
                                    'connect_ai',
                                ],
                                'properties' => [
                                    'create_page' => ['type' => 'boolean'],
                                    'write_post' => ['type' => 'boolean'],
                                    'site_details' => ['type' => 'boolean'],
                                    'configure_email' => ['type' => 'boolean'],
                                    'connect_astro' => ['type' => 'boolean'],
                                    'connect_ai' => ['type' => 'boolean'],
                                ],
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
                'deleted_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
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
