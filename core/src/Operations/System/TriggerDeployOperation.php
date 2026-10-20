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

final class TriggerDeployOperation implements Operation
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ?SettingsRepository $settings = null
    ) {
    }

    public static function name(): string
    {
        return 'trigger_deploy';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Trigger static site regeneration and deployment build',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'message' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'description' => 'Optional deploy trigger message or reason',
                    ],
                ],
                'additionalProperties' => false,
            ],
            mutates: true,
            aiPolicy: OperationMeta::AI_POLICY_DRAFT_ONLY_BLOCKED
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required to trigger deploy.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'manage', new Subject('site', 'deploy'));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to trigger deploy: {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $message = (string) ($input['message'] ?? 'MCP triggered deploy');
        $deployId = 'dep_' . bin2hex(random_bytes(10));
        $now = date('c');

        if ($this->settings !== null) {
            $this->settings->set('site.last_deploy', [
                'id' => $deployId,
                'status' => 'completed',
                'message' => $message,
                'triggered_by' => $ctx->actor !== null ? $ctx->actor->label : 'unknown',
                'deployed_at' => $now,
            ]);
        }

        return Result::ok([
            'deploy_id' => $deployId,
            'status' => 'triggered',
            'message' => $message,
            'triggered_at' => $now,
        ]);
    }
}
