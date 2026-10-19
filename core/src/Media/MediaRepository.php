<?php

declare(strict_types=1);

namespace BsCms\Media;

use BsCms\Database\Connection;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class MediaRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MediaProcessor $processor,
        private readonly string $mediaBaseDir
    ) {
        MediaSecurity::ensureHtaccess($this->mediaBaseDir);
    }

    /**
     * Create media entry from uploaded file.
     *
     * @param array{
     *     alt_text?: ?string,
     *     caption?: ?string,
     *     title?: ?string,
     *     focal_x?: ?float,
     *     focal_y?: ?float,
     *     blurhash?: ?string
     * } $metadata
     * @return array<string, mixed>
     */
    public function createFromUpload(
        string $tempFilePath,
        string $originalName,
        ?string $folderId = null,
        ?string $uploadedBy = null,
        array $metadata = []
    ): array {
        if (!file_exists($tempFilePath) || !is_readable($tempFilePath)) {
            throw new InvalidArgumentException("Uploaded file does not exist or is not readable.");
        }

        if ($folderId !== null && trim($folderId) !== '') {
            $folderTable = $this->connection->prefixTable('media_folders');
            $fStmt = $this->connection->execute("SELECT `id` FROM `{$folderTable}` WHERE `id` = ?", [$folderId]);
            if ($fStmt->fetch() === false) {
                throw new InvalidArgumentException("Folder '{$folderId}' does not exist.");
            }
        } else {
            $folderId = null;
        }

        $mime = MediaSecurity::detectMimeType($tempFilePath);
        if ($mime === null || !MediaSecurity::isAllowedMime($mime)) {
            throw new ValidationException("Unsupported or forbidden file MIME type: " . ($mime ?? 'unknown'), [
                'file' => "File format '{$mime}' is not permitted."
            ]);
        }

        $extension = MediaSecurity::getExtensionForMime($mime);
        if ($extension === null) {
            throw new ValidationException("Cannot determine safe extension for file MIME: {$mime}", [
                'file' => "Unsupported file extension."
            ]);
        }

        $mediaId = Ulid::generate();
        $storedFilename = "{$mediaId}.{$extension}";
        $diskPath = rtrim($this->mediaBaseDir, '/\\') . '/' . $storedFilename;
        $storagePath = "media/{$storedFilename}";

        // Sanitize (re-encode images to strip polyglot/payloads) and store file
        MediaSecurity::sanitizeAndStore($tempFilePath, $diskPath, $mime);

        if (!file_exists($diskPath)) {
            throw new RuntimeException("Failed to store media file to disk.");
        }

        $sizeBytes = (int) filesize($diskPath);
        $contentHash = hash_file('sha256', $diskPath);
        if ($contentHash === false) {
            $contentHash = '';
        }

        // Deduplication check: if identical content already exists, return existing media item
        if ($contentHash !== '') {
            $existing = $this->findByContentHash($contentHash);
            if ($existing !== null) {
                @unlink($diskPath);
                $existing['duplicate_of'] = $existing['id'];
                return $existing;
            }
        }

        $now = Clock::sql(Clock::now());
        $cleanOrigName = basename(str_replace(['\\', '/', "\0"], '', $originalName));
        if ($cleanOrigName === '') {
            $cleanOrigName = $storedFilename;
        }

        $title = $metadata['title'] ?? pathinfo($cleanOrigName, PATHINFO_FILENAME);
        $altText = $metadata['alt_text'] ?? null;
        $caption = $metadata['caption'] ?? null;
        $focalX = isset($metadata['focal_x']) ? (float) $metadata['focal_x'] : null;
        $focalY = isset($metadata['focal_y']) ? (float) $metadata['focal_y'] : null;
        $blurhash = $metadata['blurhash'] ?? null;

        $table = $this->connection->prefixTable('media');
        $this->connection->execute(
            "INSERT INTO `{$table}` (
                `id`, `folder_id`, `filename`, `original_name`, `mime`, `extension`,
                `size_bytes`, `width`, `height`, `duration_seconds`, `content_hash`,
                `storage_driver`, `storage_path`, `alt_text`, `caption`, `title`,
                `focal_x`, `focal_y`, `blurhash`, `dominant_color`, `usage_count`,
                `uploaded_by`, `created_at`, `updated_at`, `deleted_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, 'local', ?, ?, ?, ?, ?, ?, ?, NULL, 0, ?, ?, ?, NULL)",
            [
                $mediaId, $folderId, $storedFilename, $cleanOrigName, $mime, $extension,
                $sizeBytes, $contentHash,
                $storagePath, $altText, $caption, $title,
                $focalX, $focalY, $blurhash,
                $uploadedBy, $now, $now
            ]
        );

        // Process images for variants and metadata
        $procResult = $this->processor->processImage($mediaId, $diskPath, $mime);
        $width = $procResult['width'];
        $height = $procResult['height'];
        $dominantColor = $procResult['dominant_color'];

        if ($width !== null || $height !== null || $dominantColor !== null) {
            $this->connection->execute(
                "UPDATE `{$table}` SET `width` = ?, `height` = ?, `dominant_color` = ? WHERE `id` = ?",
                [$width, $height, $dominantColor, $mediaId]
            );
        }

        return $this->findById($mediaId) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id, bool $includeDeleted = false): ?array
    {
        $table = $this->connection->prefixTable('media');
        $sql = "SELECT * FROM `{$table}` WHERE `id` = ?";
        if (!$includeDeleted) {
            $sql .= " AND `deleted_at` IS NULL";
        }

        $stmt = $this->connection->execute($sql, [$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->decorateMedia($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $contentHash): ?array
    {
        $table = $this->connection->prefixTable('media');
        $stmt = $this->connection->execute(
            "SELECT * FROM `{$table}` WHERE `content_hash` = ? AND `deleted_at` IS NULL LIMIT 1",
            [$contentHash]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->decorateMedia($row);
    }

    /**
     * List media items with filtering and pagination.
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $table = $this->connection->prefixTable('media');
        $where = ["`deleted_at` IS NULL"];
        $params = [];

        if (array_key_exists('folder_id', $filters)) {
            $folderId = $filters['folder_id'];
            if ($folderId === null || $folderId === '' || $folderId === 'root') {
                $where[] = "`folder_id` IS NULL";
            } else {
                $where[] = "`folder_id` = ?";
                $params[] = $folderId;
            }
        }

        if (!empty($filters['search'])) {
            $search = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['search']) . '%';
            $where[] = "(`title` LIKE ? OR `original_name` LIKE ? OR `alt_text` LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($filters['type'])) {
            $type = (string) $filters['type'];
            if ($type === 'image') {
                $where[] = "`mime` LIKE 'image/%'";
            } elseif ($type === 'video') {
                $where[] = "`mime` LIKE 'video/%'";
            } elseif ($type === 'audio') {
                $where[] = "`mime` LIKE 'audio/%'";
            } elseif ($type === 'document') {
                $where[] = "(`mime` = 'application/pdf' OR `mime` LIKE 'text/%')";
            }
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $this->connection->execute(
            "SELECT COUNT(*) FROM `{$table}` WHERE {$whereClause}",
            $params
        );
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);
        $offset = ($page - 1) * $perPage;
        $lastPage = max(1, (int) ceil($total / $perPage));

        $stmt = $this->connection->execute(
            "SELECT * FROM `{$table}` WHERE {$whereClause} ORDER BY `created_at` DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->decorateMedia($row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    /**
     * Update media metadata.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $media = $this->findById($id);
        if ($media === null) {
            throw new InvalidArgumentException("Media item '{$id}' not found.");
        }

        $table = $this->connection->prefixTable('media');
        $updates = [];
        $params = [];

        if (array_key_exists('alt_text', $data)) {
            $updates[] = "`alt_text` = ?";
            $params[] = $data['alt_text'] !== null ? trim((string) $data['alt_text']) : null;
        }

        if (array_key_exists('caption', $data)) {
            $updates[] = "`caption` = ?";
            $params[] = $data['caption'] !== null ? trim((string) $data['caption']) : null;
        }

        if (array_key_exists('title', $data)) {
            $updates[] = "`title` = ?";
            $params[] = $data['title'] !== null ? trim((string) $data['title']) : null;
        }

        if (array_key_exists('focal_x', $data)) {
            $updates[] = "`focal_x` = ?";
            $params[] = $data['focal_x'] !== null ? (float) $data['focal_x'] : null;
        }

        if (array_key_exists('focal_y', $data)) {
            $updates[] = "`focal_y` = ?";
            $params[] = $data['focal_y'] !== null ? (float) $data['focal_y'] : null;
        }

        if (array_key_exists('folder_id', $data)) {
            $folderId = $data['folder_id'];
            if ($folderId !== null && (string) $folderId !== '') {
                $folderTable = $this->connection->prefixTable('media_folders');
                $fStmt = $this->connection->execute("SELECT `id` FROM `{$folderTable}` WHERE `id` = ?", [$folderId]);
                if ($fStmt->fetch() === false) {
                    throw new InvalidArgumentException("Folder '{$folderId}' does not exist.");
                }
                $updates[] = "`folder_id` = ?";
                $params[] = (string) $folderId;
            } else {
                $updates[] = "`folder_id` = NULL";
            }
        }

        if (empty($updates)) {
            return $media;
        }

        $now = Clock::sql(Clock::now());
        $updates[] = "`updated_at` = ?";
        $params[] = $now;

        $params[] = $id;
        $sql = "UPDATE `{$table}` SET " . implode(', ', $updates) . " WHERE `id` = ?";
        $this->connection->execute($sql, $params);

        return $this->findById($id) ?? [];
    }

    /**
     * Delete media item.
     * Enforces usage check: refuses delete if in use unless force=true.
     */
    public function delete(string $id, bool $force = false, bool $permanent = false): void
    {
        $media = $this->findById($id, true);
        if ($media === null) {
            return;
        }

        $usageTable = $this->connection->prefixTable('media_usage');
        $usageStmt = $this->connection->execute("SELECT * FROM `{$usageTable}` WHERE `media_id` = ?", [$id]);
        /** @var list<array<string, mixed>> $usages */
        $usages = $usageStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($usages) > 0 && !$force) {
            throw new MediaInUseException(
                "Media '{$id}' is currently referenced by " . count($usages) . " content entry field(s). Use ?force=true to delete anyway.",
                $usages
            );
        }

        $table = $this->connection->prefixTable('media');
        $variantsTable = $this->connection->prefixTable('media_variants');

        if ($force && count($usages) > 0) {
            $this->connection->execute("DELETE FROM `{$usageTable}` WHERE `media_id` = ?", [$id]);
        }

        if ($permanent || $media['deleted_at'] !== null) {
            // Hard purge: delete database records and files from disk
            $this->connection->transaction(function () use ($table, $variantsTable, $usageTable, $id) {
                $this->connection->execute("DELETE FROM `{$usageTable}` WHERE `media_id` = ?", [$id]);
                $this->connection->execute("DELETE FROM `{$variantsTable}` WHERE `media_id` = ?", [$id]);
                $this->connection->execute("DELETE FROM `{$table}` WHERE `id` = ?", [$id]);
            });

            // Remove file and variants from disk
            $storedFilename = (string) $media['filename'];
            $diskPath = rtrim($this->mediaBaseDir, '/\\') . '/' . $storedFilename;
            if (file_exists($diskPath)) {
                @unlink($diskPath);
            }

            $variants = (array) ($media['variants'] ?? []);
            foreach ($variants as $var) {
                $varPath = rtrim($this->mediaBaseDir, '/\\') . '/../' . ltrim((string) $var['storage_path'], '/\\');
                if (file_exists($varPath)) {
                    @unlink($varPath);
                }
            }
        } else {
            // Soft delete
            $now = Clock::sql(Clock::now());
            $this->connection->execute("UPDATE `{$table}` SET `deleted_at` = ?, `updated_at` = ? WHERE `id` = ?", [$now, $now, $id]);
        }
    }

    /**
     * Restore soft-deleted media item.
     *
     * @return array<string, mixed>|null
     */
    public function restore(string $id): ?array
    {
        $table = $this->connection->prefixTable('media');
        $now = Clock::sql(Clock::now());
        $this->connection->execute("UPDATE `{$table}` SET `deleted_at` = NULL, `updated_at` = ? WHERE `id` = ?", [$now, $id]);

        return $this->findById($id);
    }

    /**
     * List all content entries currently using this media.
     *
     * @return list<array<string, mixed>>
     */
    public function getUsage(string $id): array
    {
        $usageTable = $this->connection->prefixTable('media_usage');
        $colTable = $this->connection->prefixTable('collections');

        $stmt = $this->connection->execute(
            "SELECT u.`media_id`, u.`collection_id`, u.`entry_id`, u.`field_key`, c.`handle` AS `collection_handle`, c.`label_singular` AS `collection_name`
             FROM `{$usageTable}` u
             LEFT JOIN `{$colTable}` c ON c.`id` = u.`collection_id`
             WHERE u.`media_id` = ?
             ORDER BY u.`collection_id`, u.`entry_id`",
            [$id]
        );

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Find active media by content hash.
     *
     * @return array<string, mixed>|null
     */
    public function findByContentHash(string $hash): ?array
    {
        $table = $this->connection->prefixTable('media');
        $stmt = $this->connection->execute(
            "SELECT * FROM `{$table}` WHERE `content_hash` = ? AND `deleted_at` IS NULL LIMIT 1",
            [$hash]
        );
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->decorateMedia($row);
    }

    /**
     * Replace media file with a new upload, keeping the existing media ID and relations intact.
     *
     * @return array<string, mixed>
     */
    public function replace(string $id, string $tempFilePath, string $originalName, ?string $updatedBy = null): array
    {
        $media = $this->findById($id);
        if ($media === null) {
            throw new InvalidArgumentException("Media item '{$id}' not found.");
        }

        if (!file_exists($tempFilePath) || !is_readable($tempFilePath)) {
            throw new InvalidArgumentException("Replacement file does not exist or is not readable.");
        }

        $mime = MediaSecurity::detectMimeType($tempFilePath);
        if ($mime === null || !MediaSecurity::isAllowedMime($mime)) {
            throw new ValidationException("Unsupported or forbidden file MIME type: " . ($mime ?? 'unknown'), [
                'file' => "File format '{$mime}' is not permitted."
            ]);
        }

        $extension = MediaSecurity::getExtensionForMime($mime);
        if ($extension === null) {
            throw new ValidationException("Cannot determine safe extension for file MIME: {$mime}", [
                'file' => "Unsupported file extension."
            ]);
        }

        // Delete old disk files and variant records
        $oldStoredFilename = (string) $media['filename'];
        $oldDiskPath = rtrim($this->mediaBaseDir, '/\\') . '/' . $oldStoredFilename;
        if (file_exists($oldDiskPath)) {
            @unlink($oldDiskPath);
        }

        $variantsTable = $this->connection->prefixTable('media_variants');
        $this->connection->execute("DELETE FROM `{$variantsTable}` WHERE `media_id` = ?", [$id]);

        $storedFilename = "{$id}.{$extension}";
        $diskPath = rtrim($this->mediaBaseDir, '/\\') . '/' . $storedFilename;
        $storagePath = "media/{$storedFilename}";

        MediaSecurity::sanitizeAndStore($tempFilePath, $diskPath, $mime);

        $sizeBytes = (int) filesize($diskPath);
        $contentHash = hash_file('sha256', $diskPath);
        if ($contentHash === false) {
            $contentHash = '';
        }

        $cleanOrigName = basename(str_replace(['\\', '/', "\0"], '', $originalName));
        if ($cleanOrigName === '') {
            $cleanOrigName = $storedFilename;
        }

        $now = Clock::sql(Clock::now());
        $table = $this->connection->prefixTable('media');
        $this->connection->execute(
            "UPDATE `{$table}` SET
                `filename` = ?, `original_name` = ?, `mime` = ?, `extension` = ?,
                `size_bytes` = ?, `content_hash` = ?, `storage_path` = ?,
                `updated_at` = ?
             WHERE `id` = ?",
            [$storedFilename, $cleanOrigName, $mime, $extension, $sizeBytes, $contentHash, $storagePath, $now, $id]
        );

        // Process variants if raster image
        $procResult = $this->processor->processImage($id, $diskPath, $mime);
        $width = $procResult['width'];
        $height = $procResult['height'];
        $dominantColor = $procResult['dominant_color'];

        $this->connection->execute(
            "UPDATE `{$table}` SET `width` = ?, `height` = ?, `dominant_color` = ? WHERE `id` = ?",
            [$width, $height, $dominantColor, $id]
        );

        return $this->findById($id) ?? [];
    }

    /**
     * Perform bulk operations on media items.
     *
     * @param list<string> $ids
     * @param array<string, mixed> $options
     * @return array{action: string, affected: int}
     */
    public function bulk(string $action, array $ids, array $options = []): array
    {
        $affected = 0;
        if ($action === 'delete') {
            $force = !empty($options['force']);
            foreach ($ids as $id) {
                if (trim($id) !== '') {
                    $this->delete(trim($id), $force);
                    $affected++;
                }
            }
        } elseif ($action === 'move') {
            $folderId = array_key_exists('folder_id', $options) && $options['folder_id'] !== '' ? (string) $options['folder_id'] : null;
            foreach ($ids as $id) {
                if (trim($id) !== '') {
                    $this->update(trim($id), ['folder_id' => $folderId]);
                    $affected++;
                }
            }
        } else {
            throw new InvalidArgumentException("Unsupported bulk action '{$action}'. Supported actions: 'delete', 'move'.");
        }

        return ['action' => $action, 'affected' => $affected];
    }

    /**
     * Get absolute path on disk for media file or specific variant.
     */
    public function getFilePath(string $id, ?string $variant = null): ?string
    {
        $table = $this->connection->prefixTable('media');
        $stmt = $this->connection->execute(
            "SELECT `storage_path` FROM `{$table}` WHERE `id` = ? AND `deleted_at` IS NULL",
            [$id]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        if ($variant !== null && trim($variant) !== '') {
            $variantsTable = $this->connection->prefixTable('media_variants');
            $vStmt = $this->connection->execute(
                "SELECT `storage_path` FROM `{$variantsTable}` WHERE `media_id` = ? AND `name` = ?",
                [$id, $variant]
            );
            $vRow = $vStmt->fetch(PDO::FETCH_ASSOC);
            if ($vRow !== false && !empty($vRow['storage_path'])) {
                $varFilename = basename((string) $vRow['storage_path']);
                $vPath = rtrim($this->mediaBaseDir, '/\\') . '/variants/' . $varFilename;
                if (file_exists($vPath) && is_readable($vPath)) {
                    return $vPath;
                }
            }
        }

        $filename = basename((string) $row['storage_path']);
        $path = rtrim($this->mediaBaseDir, '/\\') . '/' . $filename;
        if (file_exists($path) && is_readable($path)) {
            return $path;
        }

        return null;
    }

    /**
     * Decorate media row with full URL and responsive variants.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorateMedia(array $row): array
    {
        $row['url'] = '/' . ltrim((string) $row['storage_path'], '/');

        $variantsTable = $this->connection->prefixTable('media_variants');
        $stmt = $this->connection->execute("SELECT * FROM `{$variantsTable}` WHERE `media_id` = ?", [$row['id']]);
        /** @var list<array<string, mixed>> $variants */
        $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($variants as &$var) {
            $var['url'] = '/' . ltrim((string) $var['storage_path'], '/');
        }
        unset($var);

        $row['variants'] = $variants;

        return $row;
    }
}
