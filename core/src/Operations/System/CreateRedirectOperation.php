<?php

declare(strict_types=1);

namespace BsCms\Operations\System;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;
use BsCms\Redirects\RedirectPresenter;
use BsCms\Redirects\RedirectRepository;

final class CreateRedirectOperation implements Operation
{
    public function __construct(
        private readonly RedirectRepository $redirectRepository,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'create_redirect';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Create a URL redirect rule',
            inputSchema: [
                'type' => 'object',
                'required' => ['from_path', 'to_path'],
                'properties' => [
                    'from_path' => [
                        'type' => 'string',
                        'maxLength' => 1024,
                        'description' => 'Source path (starting with /)',
                    ],
                    'to_path' => [
                        'type' => 'string',
                        'maxLength' => 2048,
                        'description' => 'Destination path or URL',
                    ],
                    'status_code' => [
                        'type' => 'integer',
                        'enum' => [301, 302, 307, 308],
                        'default' => 301,
                    ],
                    'is_prefix' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'Match path as a prefix pattern',
                    ],
                ],
                'additionalProperties' => false,
            ],
            mutates: true,
            idempotent: true,
            aiPolicy: OperationMeta::AI_POLICY_DRAFT_ONLY_BLOCKED
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required to create redirect.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'create', new Subject('redirect', '*'));
        if (!$decision->allowed) {
            $siteDecision = $this->authorizer->can($ctx->actor, 'create', new Subject('site', 'redirects'));
            if (!$siteDecision->allowed) {
                throw new ForbiddenException("Insufficient permissions to create redirect: {$decision->reason}");
            }
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $fromPath = (string) ($input['from_path'] ?? '');
        $toPath = (string) ($input['to_path'] ?? '');
        $statusCode = (int) ($input['status_code'] ?? 301);
        $isPrefix = (bool) ($input['is_prefix'] ?? false);

        if ($fromPath === '' || $toPath === '') {
            throw new ValidationException('Both from_path and to_path must be specified.');
        }

        $actor = $ctx->actor ?? \BsCms\Auth\Actor::anonymous();
        $userId = $actor->onBehalfOf ?? $actor->id;

        $created = $this->redirectRepository->create([
            'from_path' => $fromPath,
            'to_path' => $toPath,
            'status_code' => $statusCode,
            'is_prefix' => $isPrefix,
            'is_active' => true,
        ], $userId);

        return Result::created([
            'redirect' => RedirectPresenter::present($created),
            'content_trust' => 'untrusted',
        ]);
    }
}
