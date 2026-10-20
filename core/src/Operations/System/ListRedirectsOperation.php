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
use BsCms\Redirects\RedirectPresenter;
use BsCms\Redirects\RedirectRepository;

final class ListRedirectsOperation implements Operation
{
    public function __construct(
        private readonly RedirectRepository $redirectRepository,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'list_redirects';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'List configured URL redirect rules',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 100,
                        'default' => 20,
                    ],
                    'page' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'default' => 1,
                    ],
                ],
                'additionalProperties' => false,
            ],
            mutates: false,
            idempotent: true
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required to list redirects.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'read', new Subject('redirect', '*'));
        if (!$decision->allowed) {
            $siteDecision = $this->authorizer->can($ctx->actor, 'read', new Subject('site', 'redirects'));
            if (!$siteDecision->allowed) {
                throw new ForbiddenException("Insufficient permissions to list redirects: {$decision->reason}");
            }
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $limit = max(1, min(100, (int) ($input['limit'] ?? 20)));
        $page = max(1, (int) ($input['page'] ?? 1));

        $result = $this->redirectRepository->list([], $page, $limit);
        $items = [];
        foreach ($result['items'] as $row) {
            $items[] = RedirectPresenter::present($row);
        }

        return Result::ok([
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'content_trust' => 'untrusted',
        ]);
    }
}
