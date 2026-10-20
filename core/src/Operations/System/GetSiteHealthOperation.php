<?php

declare(strict_types=1);

namespace BsCms\Operations\System;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Health\SiteHealthPresenter;
use BsCms\Health\SiteHealthService;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;

final class GetSiteHealthOperation implements Operation
{
    public function __construct(
        private readonly SiteHealthService $healthService,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'get_site_health';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Check CMS system health, database, storage, and security status',
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
            throw new ForbiddenException('Authentication required to get site health.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'read', new Subject('site_health', '*'));
        if (!$decision->allowed) {
            // Also check 'system' subject
            $sysDecision = $this->authorizer->can($ctx->actor, 'read', new Subject('system', 'health'));
            if (!$sysDecision->allowed) {
                throw new ForbiddenException("Insufficient permissions to check site health: {$decision->reason}");
            }
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $scan = $this->healthService->scan();
        $presented = SiteHealthPresenter::presentReport($scan);

        return Result::ok([
            'health' => $presented,
            'content_trust' => 'untrusted',
        ]);
    }
}
