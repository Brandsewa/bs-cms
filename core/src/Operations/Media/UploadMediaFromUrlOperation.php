<?php

declare(strict_types=1);

namespace BsCms\Operations\Media;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Media\MediaPresenter;
use BsCms\Media\MediaRepository;
use BsCms\Media\MediaSecurity;
use BsCms\Operations\Context;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;

final class UploadMediaFromUrlOperation implements Operation
{
    public function __construct(
        private readonly MediaRepository $mediaRepository,
        private readonly Authorizer $authorizer
    ) {
    }

    public static function name(): string
    {
        return 'upload_media_from_url';
    }

    public static function describe(): OperationMeta
    {
        return new OperationMeta(
            summary: 'Download an image or document from a URL and store it in the media library',
            inputSchema: [
                'type' => 'object',
                'required' => ['url'],
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'maxLength' => 2048,
                        'description' => 'Public HTTP/HTTPS URL of the media file to fetch',
                    ],
                    'alt_text' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'description' => 'Optional image alt text for accessibility',
                    ],
                    'caption' => [
                        'type' => 'string',
                        'maxLength' => 500,
                        'description' => 'Optional caption or description',
                    ],
                    'folder_id' => [
                        'type' => 'string',
                        'description' => 'Optional media folder ID',
                    ],
                ],
                'additionalProperties' => false,
            ],
            mutates: true,
            openWorld: true
        );
    }

    public function authorize(Context $ctx, array $input): void
    {
        if ($ctx->actor === null) {
            throw new ForbiddenException('Authentication required to upload media.');
        }

        $decision = $this->authorizer->can($ctx->actor, 'create', new Subject('media', '*'));
        if (!$decision->allowed) {
            throw new ForbiddenException("Insufficient permissions to upload media: {$decision->reason}");
        }
    }

    public function handle(Context $ctx, array $input): Result
    {
        $rawUrl = trim((string) ($input['url'] ?? ''));
        if ($rawUrl === '' || !filter_var($rawUrl, FILTER_VALIDATE_URL)) {
            throw new ValidationException('A valid HTTP/HTTPS URL must be provided.');
        }

        $parsed = parse_url($rawUrl);
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ValidationException("Unsupported URL scheme '{$scheme}'. Only http and https are allowed.");
        }

        $host = (string) ($parsed['host'] ?? '');
        if ($host === '') {
            throw new ValidationException('Invalid host in URL.');
        }

        // Basic SSRF protection: reject obvious private/loopback unless in local testing
        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            // Check if loopback/private IP
            if ($host === 'localhost' || str_starts_with($ip, '127.') || str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.')) {
                // In testing/mock environments, allow if explicitly testing
                if (getenv('APP_ENV') !== 'testing' && getenv('APP_ENV') !== 'dev') {
                    throw new ValidationException('Fetching media from private, loopback, or internal IP addresses is forbidden.');
                }
            }
        }

        // Download to temporary file with timeout and size cap (max 25MB)
        $tempFile = tempnam(sys_get_temp_dir(), 'bs_media_url_');
        if ($tempFile === false) {
            throw new ValidationException('Failed to allocate temporary storage for download.');
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'follow_location' => 1,
                'max_redirects' => 3,
                'user_agent' => 'BS-CMS/1.0 MediaFetcher',
            ],
        ]);

        $in = @fopen($rawUrl, 'rb', false, $context);
        if (!$in) {
            @unlink($tempFile);
            throw new ValidationException("Failed to download media from URL: '{$rawUrl}'.");
        }

        $out = fopen($tempFile, 'wb');
        if (!$out) {
            fclose($in);
            @unlink($tempFile);
            throw new ValidationException('Failed to open temporary file for write.');
        }

        $maxBytes = 25 * 1024 * 1024; // 25 MB cap
        $bytesCopied = 0;
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false) {
                break;
            }
            $bytesCopied += strlen($chunk);
            if ($bytesCopied > $maxBytes) {
                fclose($in);
                fclose($out);
                @unlink($tempFile);
                throw new ValidationException('Downloaded media exceeds maximum allowed size (25MB).');
            }
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);

        // Security check mime type
        $mime = MediaSecurity::detectMimeType($tempFile);
        if ($mime === null || !MediaSecurity::isAllowedMime($mime)) {
            @unlink($tempFile);
            throw new ValidationException("Unsupported or unsafe media mime type '{$mime}'.");
        }

        $path = (string) ($parsed['path'] ?? 'media');
        $pathInfo = pathinfo($path);
        $baseName = $pathInfo['filename'] !== '' ? $pathInfo['filename'] : 'media';
        $ext = isset($pathInfo['extension']) && $pathInfo['extension'] !== '' ? '.' . $pathInfo['extension'] : '.bin';
        $originalName = $baseName . $ext;

        $actor = $ctx->actor ?? \BsCms\Auth\Actor::anonymous();
        $userId = $actor->onBehalfOf ?? $actor->id;
        $folderId = isset($input['folder_id']) && is_string($input['folder_id']) ? $input['folder_id'] : null;

        $created = $this->mediaRepository->createFromUpload($tempFile, $originalName, $userId, $folderId);
        @unlink($tempFile);

        // Update alt text and caption if provided
        $metaUpdate = [];
        if (isset($input['alt_text']) && is_string($input['alt_text'])) {
            $metaUpdate['alt_text'] = $input['alt_text'];
        }
        if (isset($input['caption']) && is_string($input['caption'])) {
            $metaUpdate['caption'] = $input['caption'];
        }
        if (!empty($metaUpdate)) {
            $created = $this->mediaRepository->update((string) $created['id'], $metaUpdate);
        }

        return Result::created([
            'media' => MediaPresenter::present($created),
            'content_trust' => 'untrusted',
        ]);
    }
}
