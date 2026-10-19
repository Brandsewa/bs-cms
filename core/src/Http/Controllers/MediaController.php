<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Media\ChunkUploadManager;
use BsCms\Media\MediaInUseException;
use BsCms\Media\MediaRepository;
use BsCms\Operations\Exceptions\ValidationException;
use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

class MediaController extends BaseController
{
    public function __construct(
        private readonly MediaRepository $mediaRepository,
        private readonly ChunkUploadManager $chunkManager,
        private readonly Authorizer $authorizer,
        private readonly string $tmpDir
    ) {
    }

    /**
     * GET /api/v1/media
     */
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('media', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $params = $request->getQueryParams();
        $filters = [];
        if (isset($params['folder_id'])) {
            $filters['folder_id'] = $params['folder_id'];
        }
        if (isset($params['search']) && trim((string) $params['search']) !== '') {
            $filters['search'] = trim((string) $params['search']);
        }
        if (isset($params['type']) && trim((string) $params['type']) !== '') {
            $filters['type'] = trim((string) $params['type']);
        }

        $page = isset($params['page']) ? max(1, (int) $params['page']) : 1;
        $perPage = isset($params['per_page']) ? max(1, min(100, (int) $params['per_page'])) : 50;

        $result = $this->mediaRepository->list($filters, $page, $perPage);
        return $this->json($result);
    }

    /**
     * GET /api/v1/media/{id}
     *
     * @param array<string, mixed> $args
     */
    public function get(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('media', $id));
        if ($permError !== null) {
            return $permError;
        }

        $media = $this->mediaRepository->findById($id);
        if ($media === null) {
            return $this->problem(404, 'Not Found', "Media item '{$id}' not found.");
        }

        return $this->json($media);
    }

    /**
     * POST /api/v1/media
     */
    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('media', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $actor = $this->getActor($request);
        $uploadedFiles = $request->getUploadedFiles();

        /** @var UploadedFileInterface|null $file */
        $file = $uploadedFiles['file'] ?? null;
        if ($file === null && !empty($uploadedFiles)) {
            // Pick first uploaded file
            $file = reset($uploadedFiles);
        }

        if ($file === null) {
            return $this->problem(422, 'Validation Failed', 'No file was provided in the upload request.', null, [
                'file' => ['File is required.']
            ]);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->problem(422, 'Upload Failed', "File upload error code: {$file->getError()}");
        }

        $clientFilename = $file->getClientFilename() ?? 'upload.bin';
        $tempPath = tempnam($this->tmpDir, 'bs_upload_');
        if ($tempPath === false) {
            return $this->problem(500, 'Internal Server Error', 'Could not create temporary file for upload.');
        }

        try {
            $stream = $file->getStream();
            $target = fopen($tempPath, 'wb');
            if ($target === false) {
                throw new InvalidArgumentException("Cannot open temp file for writing.");
            }
            while (!$stream->eof()) {
                fwrite($target, $stream->read(65536));
            }
            fclose($target);

            /** @var array<string, mixed> $body */
            $body = (array) ($request->getParsedBody() ?? []);
            $folderId = isset($body['folder_id']) && (string) $body['folder_id'] !== '' ? (string) $body['folder_id'] : null;

            $metadata = [
                'title' => isset($body['title']) ? (string) $body['title'] : null,
                'alt_text' => isset($body['alt_text']) ? (string) $body['alt_text'] : null,
                'caption' => isset($body['caption']) ? (string) $body['caption'] : null,
                'focal_x' => isset($body['focal_x']) ? (float) $body['focal_x'] : null,
                'focal_y' => isset($body['focal_y']) ? (float) $body['focal_y'] : null,
            ];

            $media = $this->mediaRepository->createFromUpload(
                $tempPath,
                $clientFilename,
                $folderId,
                $actor->id,
                $metadata
            );

            return $this->json($media, 201);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = [];
            foreach ($e->errors as $k => $v) {
                $errors[$k] = is_array($v) ? array_map('strval', $v) : [(string) $v];
            }
            return $this->problem(422, 'Validation Failed', $e->getMessage(), null, $errors);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->problem(422, 'Validation Failed', $e->getMessage());
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'media_upload');
            return $this->problem(500, 'Internal Server Error', 'Failed to upload media file.');
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * POST /api/v1/media/chunks/init
     */
    public function initChunk(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('media', '*'));
        if ($permError !== null) {
            return $permError;
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $originalName = (string) ($body['original_name'] ?? '');
        $totalChunks = (int) ($body['total_chunks'] ?? 0);
        $totalSize = (int) ($body['total_size'] ?? 0);
        $folderId = isset($body['folder_id']) && (string) $body['folder_id'] !== '' ? (string) $body['folder_id'] : null;

        try {
            $initData = $this->chunkManager->initiate($originalName, $totalChunks, $totalSize, $folderId);
            return $this->json($initData, 201);
        } catch (InvalidArgumentException $e) {
            return $this->problem(422, 'Validation Failed', $e->getMessage());
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'media_chunk_init');
            return $this->problem(500, 'Internal Server Error', 'Failed to initiate chunked upload.');
        }
    }

    /**
     * POST /api/v1/media/chunks/{id}
     *
     * @param array<string, mixed> $args
     */
    public function uploadChunk(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('media', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $params = $request->getQueryParams();
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $chunkIndex = isset($body['chunk_index']) ? (int) $body['chunk_index'] : (isset($params['chunk_index']) ? (int) $params['chunk_index'] : -1);

        $uploadedFiles = $request->getUploadedFiles();
        /** @var UploadedFileInterface|null $file */
        $file = $uploadedFiles['chunk'] ?? ($uploadedFiles['file'] ?? null);

        if ($file === null && !empty($uploadedFiles)) {
            $file = reset($uploadedFiles);
        }

        $tempPath = tempnam($this->tmpDir, 'bs_chunk_');
        if ($tempPath === false) {
            return $this->problem(500, 'Internal Server Error', 'Could not create temporary file for chunk.');
        }

        try {
            if ($file !== null) {
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    return $this->problem(422, 'Upload Failed', "Chunk upload error code: {$file->getError()}");
                }
                $stream = $file->getStream();
                $target = fopen($tempPath, 'wb');
                if ($target === false) {
                    throw new InvalidArgumentException("Cannot open temp chunk file.");
                }
                while (!$stream->eof()) {
                    fwrite($target, $stream->read(65536));
                }
                fclose($target);
            } else {
                // Read from request body stream
                $bodyStream = $request->getBody();
                $target = fopen($tempPath, 'wb');
                if ($target === false) {
                    throw new InvalidArgumentException("Cannot open temp chunk file.");
                }
                while (!$bodyStream->eof()) {
                    fwrite($target, $bodyStream->read(65536));
                }
                fclose($target);
            }

            $res = $this->chunkManager->saveChunk($id, $chunkIndex, $tempPath);
            return $this->json($res);
        } catch (InvalidArgumentException $e) {
            return $this->problem(422, 'Validation Failed', $e->getMessage());
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'media_chunk_save');
            return $this->problem(500, 'Internal Server Error', 'Failed to save chunk.');
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * GET /api/v1/media/chunks/{id}
     *
     * @param array<string, mixed> $args
     */
    public function chunkStatus(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('media', '*'));
        if ($permError !== null) {
            return $permError;
        }

        try {
            $status = $this->chunkManager->status($id);
            return $this->json($status);
        } catch (InvalidArgumentException $e) {
            return $this->problem(404, 'Not Found', $e->getMessage());
        }
    }

    /**
     * POST /api/v1/media/chunks/{id}/assemble
     *
     * @param array<string, mixed> $args
     */
    public function assembleChunk(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'create', new Subject('media', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $actor = $this->getActor($request);
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $assembled = $this->chunkManager->assemble($id);
            $assembledPath = $assembled['assembled_path'];
            $originalName = $assembled['original_name'];
            $folderId = $assembled['folder_id'] ?? (isset($body['folder_id']) ? (string) $body['folder_id'] : null);

            $metadata = [
                'title' => isset($body['title']) ? (string) $body['title'] : null,
                'alt_text' => isset($body['alt_text']) ? (string) $body['alt_text'] : null,
                'caption' => isset($body['caption']) ? (string) $body['caption'] : null,
                'focal_x' => isset($body['focal_x']) ? (float) $body['focal_x'] : null,
                'focal_y' => isset($body['focal_y']) ? (float) $body['focal_y'] : null,
            ];

            $media = $this->mediaRepository->createFromUpload(
                $assembledPath,
                $originalName,
                $folderId,
                $actor->id,
                $metadata
            );

            @unlink($assembledPath);

            return $this->json($media, 201);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = [];
            foreach ($e->errors as $k => $v) {
                $errors[$k] = is_array($v) ? array_map('strval', $v) : [(string) $v];
            }
            return $this->problem(422, 'Validation Failed', $e->getMessage(), null, $errors);
        } catch (InvalidArgumentException $e) {
            return $this->problem(422, 'Validation Failed', $e->getMessage());
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'media_chunk_assemble');
            return $this->problem(500, 'Internal Server Error', 'Failed to assemble chunked upload: ' . $e->getMessage());
        }
    }

    /**
     * PATCH /api/v1/media/{id}
     *
     * @param array<string, mixed> $args
     */
    public function update(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $existing = $this->mediaRepository->findById($id);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Media item '{$id}' not found.");
        }

        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('media', $id), $existing);
        if ($permError !== null) {
            return $permError;
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $updated = $this->mediaRepository->update($id, $body);
            return $this->json($updated);
        } catch (InvalidArgumentException $e) {
            return $this->problem(422, 'Validation Failed', $e->getMessage());
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'media_update');
            return $this->problem(500, 'Internal Server Error', 'Failed to update media item.');
        }
    }

    /**
     * DELETE /api/v1/media/{id}
     *
     * @param array<string, mixed> $args
     */
    public function delete(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $existing = $this->mediaRepository->findById($id, true);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Media item '{$id}' not found.");
        }

        $permError = $this->requirePermission($this->authorizer, $request, 'delete', new Subject('media', $id), $existing);
        if ($permError !== null) {
            return $permError;
        }

        $params = $request->getQueryParams();
        $force = isset($params['force']) && in_array(strtolower((string) $params['force']), ['1', 'true', 'yes'], true);

        try {
            $this->mediaRepository->delete($id, $force);
            return $this->json(['message' => "Media item '{$id}' deleted successfully."]);
        } catch (MediaInUseException $e) {
            return $this->problem(409, 'Conflict', $e->getMessage(), null, [
                'usages' => array_map(static fn ($u) => (string) ($u['collection_handle'] ?? $u['collection_id']) . ':' . (string) $u['entry_id'], $e->usages)
            ]);
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'media_delete');
            return $this->problem(500, 'Internal Server Error', 'Failed to delete media item.');
        }
    }

    /**
     * GET /api/v1/media/{id}/usage
     *
     * @param array<string, mixed> $args
     */
    public function getUsage(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('media', $id));
        if ($permError !== null) {
            return $permError;
        }

        $usages = $this->mediaRepository->getUsage($id);
        return $this->json(['usages' => $usages]);
    }

    /**
     * POST /api/v1/media/{id}/replace
     *
     * @param array<string, mixed> $args
     */
    public function replace(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $existing = $this->mediaRepository->findById($id);
        if ($existing === null) {
            return $this->problem(404, 'Not Found', "Media item '{$id}' not found.");
        }

        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('media', $id), $existing);
        if ($permError !== null) {
            return $permError;
        }

        $actor = $this->getActor($request);
        $uploadedFiles = $request->getUploadedFiles();
        /** @var UploadedFileInterface|null $file */
        $file = $uploadedFiles['file'] ?? null;
        if ($file === null && !empty($uploadedFiles)) {
            $file = reset($uploadedFiles);
        }

        if ($file === null) {
            return $this->problem(422, 'Validation Failed', 'No file was provided in the upload request.', null, [
                'file' => ['File is required.']
            ]);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->problem(422, 'Upload Failed', "File upload error code: {$file->getError()}");
        }

        $clientFilename = $file->getClientFilename() ?? 'upload.bin';
        $tempPath = tempnam($this->tmpDir, 'bs_replace_');
        if ($tempPath === false) {
            return $this->problem(500, 'Internal Server Error', 'Could not create temporary file for replace.');
        }

        try {
            $stream = $file->getStream();
            $target = fopen($tempPath, 'wb');
            if ($target === false) {
                throw new InvalidArgumentException("Cannot open temp file for writing.");
            }
            while (!$stream->eof()) {
                fwrite($target, $stream->read(65536));
            }
            fclose($target);

            $replaced = $this->mediaRepository->replace($id, $tempPath, $clientFilename, $actor->id);
            return $this->json($replaced);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = [];
            foreach ($e->errors as $k => $v) {
                $errors[$k] = is_array($v) ? array_map('strval', $v) : [(string) $v];
            }
            return $this->problem(422, 'Validation Failed', $e->getMessage(), null, $errors);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->problem(422, 'Validation Failed', $e->getMessage());
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'media_replace');
            return $this->problem(500, 'Internal Server Error', 'Failed to replace media file.');
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * POST /api/v1/media/bulk
     */
    public function bulk(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $action = (string) ($body['action'] ?? '');
        $permAction = ($action === 'delete') ? 'delete' : 'update';

        $permError = $this->requirePermission($this->authorizer, $request, $permAction, new Subject('media', '*'));
        if ($permError !== null) {
            return $permError;
        }

        /** @var list<string> $ids */
        $ids = isset($body['ids']) && is_array($body['ids']) ? array_values(array_map('strval', $body['ids'])) : [];
        if (empty($ids)) {
            return $this->problem(422, 'Validation Failed', 'Media IDs array is required.', null, [
                'ids' => ['At least one media ID must be specified.']
            ]);
        }

        try {
            $result = $this->mediaRepository->bulk($action, $ids, $body);
            return $this->json($result);
        } catch (InvalidArgumentException $e) {
            return $this->problem(422, 'Validation Failed', $e->getMessage());
        } catch (Throwable $e) {
            $this->logServerError($request, $e, 'media_bulk');
            return $this->problem(500, 'Internal Server Error', 'Failed to process bulk media action.');
        }
    }

    /**
     * GET /api/v1/public/media/{id}/file
     * GET /api/v1/media/{id}/file
     * Stream media file with full security headers (X-Content-Type-Options: nosniff, X-Frame-Options: DENY, etc.)
     *
     * @param array<string, mixed> $args
     */
    public function streamFile(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $media = $this->mediaRepository->findById($id);
        if ($media === null) {
            return $this->problem(404, 'Not Found', "Media item '{$id}' not found.");
        }

        $params = $request->getQueryParams();
        $variant = isset($params['variant']) ? (string) $params['variant'] : null;
        $path = $this->mediaRepository->getFilePath($id, $variant);

        if ($path === null || !file_exists($path)) {
            return $this->problem(404, 'Not Found', "Media file for item '{$id}' not found on disk.");
        }

        $mime = (string) ($media['mime'] ?? 'application/octet-stream');
        $size = (int) filesize($path);
        $contentHash = (string) ($media['content_hash'] ?? hash_file('sha256', $path));
        $etag = '"' . $contentHash . '"';

        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '' && ($ifNoneMatch === $etag || $ifNoneMatch === '*')) {
            return new Response(304, [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        }

        $disposition = in_array($mime, ['application/pdf', 'text/plain', 'text/csv', 'application/zip'], true)
            ? 'attachment'
            : 'inline';
        $filename = (string) ($media['original_name'] ?? basename($path));
        $cleanFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return $this->problem(500, 'Internal Server Error', 'Unable to open media file stream.');
        }

        return new Response(
            200,
            [
                'Content-Type' => $mime,
                'Content-Length' => (string) $size,
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Content-Disposition' => "{$disposition}; filename=\"{$cleanFilename}\"",
            ],
            new Stream($stream)
        );
    }
}
