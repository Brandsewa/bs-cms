<?php

declare(strict_types=1);

namespace BsCms\Operations\System;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;
use BsCms\Settings\SettingsRepository;

final class GetDeployStatusOperation implements Operation
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ?SettingsRepository $settings = null
    ) {
    }

    public static function name(): string
    {
        return 'get_deploy_status';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Get latest deploy status and build log summary',
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
            throw new ForbiddenException('Authentication required to get deploy status.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'read', new Subject('site', 'deploy'));
        if (!$decision->allowed) {
            $colDecision = $this->authorizer->can($ctx->actor, 'read', new Subject('collection', '*'));
            if (!$colDecision->allowed) {
                throw new ForbiddenException("Insufficient permissions to check deploy status: {$decision->reason}");
            }
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $deployData = null;
        if ($this->settings !== null) {
            $deployData = $this->settings->get('site.last_deploy', null);
        }

        if (!is_array($deployData)) {
            $deployData = [
                'id' => 'dep_initial',
                'status' => 'idle',
                'message' => 'No active or recent deployments',
                'deployed_at' => null,
            ];
        }

        return Result::ok([
            'deploy' => $deployData,
            'content_trust' => 'untrusted',
        ]);
    }
}
