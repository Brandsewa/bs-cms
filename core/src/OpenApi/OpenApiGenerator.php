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
                        '200' => ['description' => 'Login successful or 2FA challenge required'],
                        '401' => ['description' => 'Invalid credentials or locked account'],
                    ],
                ],
            ],
            '/auth/logout' => [
                'post' => [
                    'summary' => 'Log out current session',
                    'tags' => ['Authentication'],
                    'responses' => ['200' => ['description' => 'Logged out successfully']],
                ],
            ],
            '/auth/session' => [
                'get' => [
                    'summary' => 'Get current authenticated session information',
                    'tags' => ['Authentication'],
                    'responses' => [
                        '200' => ['description' => 'Active session details'],
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
                        '200' => ['description' => '2FA verified, session activated'],
                        '401' => ['description' => 'Invalid or replayed 2FA code'],
                        '422' => ['description' => 'Validation error (e.g. recovery_code field provided)'],
                    ],
                ],
            ],
            '/me' => [
                'get' => [
                    'summary' => 'Get current authenticated user profile',
                    'tags' => ['Account'],
                    'responses' => ['200' => ['description' => 'Profile details']],
                ],
                'put' => [
                    'summary' => 'Update user profile',
                    'tags' => ['Account'],
                    'responses' => ['200' => ['description' => 'Profile updated']],
                ],
            ],
            '/users' => [
                'get' => [
                    'summary' => 'List all users',
                    'tags' => ['Users'],
                    'responses' => ['200' => ['description' => 'List of sanitized users without secrets']],
                ],
            ],
            '/roles' => [
                'get' => [
                    'summary' => 'List all roles',
                    'tags' => ['Roles'],
                    'responses' => ['200' => ['description' => 'List of roles']],
                ],
            ],
            '/tokens' => [
                'get' => [
                    'summary' => 'List active API tokens',
                    'tags' => ['Tokens'],
                    'responses' => ['200' => ['description' => 'List of API tokens']],
                ],
                'post' => [
                    'summary' => 'Create a new API token',
                    'tags' => ['Tokens'],
                    'responses' => ['201' => ['description' => 'Token created']],
                ],
            ],
            '/collections' => [
                'get' => [
                    'summary' => 'List registered collections',
                    'tags' => ['Schema'],
                    'responses' => ['200' => ['description' => 'Collection definitions list']],
                ],
                'post' => [
                    'summary' => 'Create a new collection schema',
                    'tags' => ['Schema'],
                    'responses' => ['201' => ['description' => 'Collection created']],
                ],
            ],
            '/collections/{handle}' => [
                'get' => [
                    'summary' => 'Get collection schema definition',
                    'tags' => ['Schema'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'Collection schema']],
                ],
                'put' => [
                    'summary' => 'Alter collection schema definition',
                    'tags' => ['Schema'],
                    'parameters' => [
                        ['name' => 'handle', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Collection schema altered'],
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
                        '200' => ['description' => 'Collection deleted'],
                        '409' => ['description' => 'Confirmation required'],
                    ],
                ],
            ],
            '/schema/plan' => [
                'post' => [
                    'summary' => 'Plan collection changes and produce migration proposal',
                    'tags' => ['Schema'],
                    'responses' => ['200' => ['description' => 'Proposed plan details and change_id']],
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
                        '200' => ['description' => 'Schema change applied successfully'],
                        '409' => ['description' => 'Confirmation required for data loss or already applied'],
                    ],
                ],
            ],
            '/schema/changes' => [
                'get' => [
                    'summary' => 'List schema change history',
                    'tags' => ['Schema'],
                    'responses' => ['200' => ['description' => 'Schema changes history']],
                ],
            ],
            '/saved-views' => [
                'get' => [
                    'summary' => 'List saved views for current user',
                    'tags' => ['Views'],
                    'responses' => ['200' => ['description' => 'User saved views']],
                ],
                'post' => [
                    'summary' => 'Create a saved view',
                    'tags' => ['Views'],
                    'responses' => ['201' => ['description' => 'Saved view created']],
                ],
            ],
            '/jobs/{id}' => [
                'get' => [
                    'summary' => 'Check background job progress and status',
                    'tags' => ['Jobs'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'Job status payload']],
                ],
            ],
            '/webhooks' => [
                'get' => [
                    'summary' => 'List configured webhooks',
                    'tags' => ['Webhooks'],
                    'responses' => ['200' => ['description' => 'Webhook configurations']],
                ],
                'post' => [
                    'summary' => 'Create a webhook',
                    'tags' => ['Webhooks'],
                    'responses' => ['201' => ['description' => 'Webhook created']],
                ],
            ],
            '/webhooks/{id}' => [
                'get' => [
                    'summary' => 'Get webhook details',
                    'tags' => ['Webhooks'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'Webhook details']],
                ],
                'patch' => [
                    'summary' => 'Update webhook details',
                    'tags' => ['Webhooks'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'Webhook updated']],
                ],
                'delete' => [
                    'summary' => 'Delete webhook',
                    'tags' => ['Webhooks'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'Webhook deleted']],
                ],
            ],
            '/webhooks/{id}/test' => [
                'post' => [
                    'summary' => 'Send test ping delivery for webhook',
                    'tags' => ['Webhooks'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'Test delivery executed']],
                ],
            ],
            '/webhooks/{id}/deliveries' => [
                'get' => [
                    'summary' => 'List recent deliveries for webhook',
                    'tags' => ['Webhooks'],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'Delivery history list']],
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

        if ($kind === 'single') {
            return [
                "/singletons/{$handle}" => [
                    'get' => [
                        'summary' => "Get singleton {$handle}",
                        'tags' => ['Singletons'],
                        'responses' => ['200' => ['description' => "Singleton {$handle} content"]],
                    ],
                    'put' => [
                        'summary' => "Update singleton {$handle}",
                        'tags' => ['Singletons'],
                        'responses' => ['200' => ['description' => "Singleton {$handle} updated"]],
                    ],
                ],
            ];
        }

        $entrySchemaRef = "#/components/schemas/{$handle}_Entry";

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
                        '201' => ['description' => 'Entry created successfully'],
                        '422' => ['description' => 'Validation error'],
                    ],
                ],
            ],
            "/collections/{$handle}/entries/bulk" => [
                'post' => [
                    'summary' => "Bulk operation on entries in {$handle}",
                    'tags' => [$tag],
                    'responses' => [
                        '200' => ['description' => 'Bulk operation completed'],
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
                        '200' => ['description' => 'Entry details'],
                        '404' => ['description' => 'Entry not found'],
                    ],
                ],
                'put' => [
                    'summary' => "Update entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Entry updated'],
                        '409' => ['description' => 'Optimistic concurrency version conflict'],
                        '422' => ['description' => 'Validation error'],
                    ],
                ],
                'delete' => [
                    'summary' => "Move entry to trash in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Entry moved to trash'],
                    ],
                ],
            ],
            "/collections/{$handle}/entries/{id}/restore" => [
                'post' => [
                    'summary' => "Restore trashed entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'Entry restored from trash']],
                ],
            ],
            "/collections/{$handle}/entries/{id}/purge" => [
                'delete' => [
                    'summary' => "Permanently purge entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'Entry permanently purged']],
                ],
            ],
            "/collections/{$handle}/entries/{id}/duplicate" => [
                'post' => [
                    'summary' => "Duplicate entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['201' => ['description' => 'Entry duplicated as new draft']],
                ],
            ],
            "/collections/{$handle}/entries/{id}/publish" => [
                'post' => [
                    'summary' => "Publish entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'Entry published']],
                ],
            ],
            "/collections/{$handle}/entries/{id}/unpublish" => [
                'post' => [
                    'summary' => "Unpublish entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'Entry reverted to draft']],
                ],
            ],
            "/collections/{$handle}/entries/{id}/archive" => [
                'post' => [
                    'summary' => "Archive entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'Entry archived']],
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
                    'responses' => ['200' => ['description' => 'Version difference diff']],
                ],
            ],
            "/collections/{$handle}/entries/{id}/versions" => [
                'get' => [
                    'summary' => "List versions for entry in {$handle}",
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'Version history list']],
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
                        '200' => ['description' => 'Published content entries with Cache-Control and ETag'],
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
                        '200' => ['description' => 'Published entry with Cache-Control and ETag'],
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
        $schemas = [];

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
