<?php

declare(strict_types=1);

namespace BsCms\Operations\Media;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Media\MediaPresenter;
use BsCms\Media\MediaRepository;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;

final class UpdateMediaOperation implements Operation
{
    public function __construct(
        private readonly MediaRepository $mediaRepository,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'update_media';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Update media alt text, caption, or folder',
            inputSchema: [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'Media ID (ULID)',
                    ],
                    'alt_text' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'description' => 'Accessibility alt text for images',
                    ],
                    'caption' => [
                        'type' => 'string',
                        'maxLength' => 500,
                        'description' => 'Media caption or description',
                    ],
                    'folder_id' => [
                        'type' => 'string',
                        'description' => 'Folder ID (or null for root)',
                    ],
                ],
                'additionalProperties' => false,
            ],
            mutates: true
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required to update media.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'update', new Subject('media', '*'));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to update media: {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $id = (string) ($input['id'] ?? '');
        $existing = $this->mediaRepository->findById($id);
        if ($existing === null) {
            throw new ValidationException("Media item '{$id}' not found.");
        }

        $updateData = [];
        if (array_key_exists('alt_text', $input) && is_string($input['alt_text'])) {
            $updateData['alt_text'] = $input['alt_text'];
        }
        if (array_key_exists('caption', $input) && is_string($input['caption'])) {
            $updateData['caption'] = $input['caption'];
        }
        if (array_key_exists('folder_id', $input)) {
            $updateData['folder_id'] = $input['folder_id'];
        }

        $updated = $this->mediaRepository->update($id, $updateData);

        return Result::ok([
            'media' => MediaPresenter::present($updated),
            'content_trust' => 'untrusted',
        ]);
    }
}
