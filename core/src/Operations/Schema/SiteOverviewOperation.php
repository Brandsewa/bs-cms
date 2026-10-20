<?php

declare(strict_types=1);

namespace BsCms\Operations\Schema;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;
use BsCms\Schema\CollectionRegistry;

final class SiteOverviewOperation implements Operation
{
    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly Authorizer $authorizer,
        private readonly ?Connection $connection = null
    ) {
    }

    public static function name(): string
    {
        return 'site_overview';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Get site overview, collections summary, and system metadata',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [],
                'additionalProperties' => false,
            ],
            mutates: false,
            idempotent: true
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required for site overview.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'read', new Subject('site', 'overview'));
        if (!$decision->allowed) {
            // Fallback check on generic collection read
            $colDecision = $this->authorizer->can($ctx->actor, 'read', new Subject('collection', '*'));
            if (!$colDecision->allowed) {
                throw new ForbiddenException("Insufficient permissions for site overview: {$decision->reason}");
            }
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $collections = $this->registry->all();
        $userCount = 0;

        if ($this->connection !== null) {
            try {
                $usersTable = $this->connection->prefixTable('users');
                $stmt = $this->connection->getPdo()->query("SELECT COUNT(*) FROM `{$usersTable}` WHERE `status` = 'active' AND `deleted_at` IS NULL");
                $userCount = (int) ($stmt ? $stmt->fetchColumn() : 0);
            } catch (\Throwable) {
                $userCount = 1;
            }
        }

        $collectionsSummary = [];
        foreach ($collections as $cHandle => $cDef) {
            $collectionsSummary[] = [
                'handle' => (string) ($cDef['handle'] ?? $cHandle),
                'name' => (string) ($cDef['label_plural'] ?? $cDef['name'] ?? $cHandle),
                'kind' => (string) ($cDef['kind'] ?? 'collection'),
            ];
        }

        return Result::ok([
            'site_title' => 'BS CMS',
            'version' => '1.0.0',
            'collections' => $collectionsSummary,
            'collections_count' => count($collections),
            'active_users' => $userCount,
            'database' => 'connected',
            'environment' => 'production',
            'content_trust' => 'untrusted',
        ]);
    }
}
