<?php

declare(strict_types=1);

namespace BsCms\Operations\Media;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Media\MediaPresenter;
use BsCms\Media\MediaRepository;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;

final class ListMediaOperation implements Operation
{
    public function __construct(
        private readonly MediaRepository $mediaRepository,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'list_media';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'List media files with metadata and streaming URLs',
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
                    'type' => [
                        'type' => 'string',
                        'description' => 'Filter by mime type or category (image, document, audio, video)',
                    ],
                    'folder_id' => [
                        'type' => 'string',
                        'description' => 'Filter by folder ID',
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
            throw new ForbiddenException('Authentication required to list media.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'read', new Subject('media', '*'));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to list media: {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $limit = max(1, min(100, (int) ($input['limit'] ?? 20)));
        $page = max(1, (int) ($input['page'] ?? 1));

        $filters = [];
        if (isset($input['type']) && is_string($input['type'])) {
            $filters['type'] = $input['type'];
        }
        if (isset($input['folder_id']) && is_string($input['folder_id'])) {
            $filters['folder_id'] = $input['folder_id'];
        }

        $result = $this->mediaRepository->list($filters, $page, $limit);
        $items = [];
        foreach ($result['items'] as $media) {
            $items[] = MediaPresenter::present($media);
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
