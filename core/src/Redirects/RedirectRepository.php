<?php

declare(strict_types=1);

namespace BsCms\Redirects;

use BsCms\Database\Connection;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Security\Ulid;
use BsCms\Support\Clock;
use InvalidArgumentException;
use PDO;

final class RedirectRepository
{
    private const ALLOWED_STATUS_CODES = [301, 302, 307, 308];
    private const ALLOWED_MATCH_TYPES = ['exact', 'prefix'];

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Create a new redirect rule.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data, ?string $actorId = null): array
    {
        $fromPath = $this->normalizePath((string) ($data['from_path'] ?? ''));
        $toPath = trim((string) ($data['to_path'] ?? ''));
        $statusCode = isset($data['status_code']) ? (int) $data['status_code'] : 301;
        $matchType = isset($data['match_type']) ? strtolower(trim((string) $data['match_type'])) : 'exact';
        $isAuto = !empty($data['is_auto']);

        $this->validateRedirect($fromPath, $toPath, $statusCode, $matchType);

        // Check for circular redirect chains
        $this->detectLoops($fromPath, $toPath);

        $fromHash = hash('sha256', $fromPath);
        $id = Ulid::generate();
        $now = Clock::sql(Clock::now());
        $table = $this->connection->prefixTable('redirects');

        // Check if unique key (from_hash, match_type) already exists
        $existingStmt = $this->connection->execute(
            "SELECT `id` FROM `{$table}` WHERE `from_hash` = ? AND `match_type` = ?",
            [$fromHash, $matchType]
        );
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing !== false) {
            // Update destination and status code of existing redirect
            $this->connection->execute(
                "UPDATE `{$table}` SET `to_path` = ?, `status_code` = ?, `is_auto` = ? WHERE `id` = ?",
                [$toPath, $statusCode, $isAuto ? 1 : 0, $existing['id']]
            );
            $id = (string) $existing['id'];
        } else {
            $this->connection->execute(
                "INSERT INTO `{$table}` (`id`, `from_path`, `match_type`, `to_path`, `status_code`, `is_auto`, `hits`, `last_hit_at`, `created_by`, `created_at`, `from_hash`)
                 VALUES (?, ?, ?, ?, ?, ?, 0, NULL, ?, ?, ?)",
                [$id, $fromPath, $matchType, $toPath, $statusCode, $isAuto ? 1 : 0, $actorId, $now, $fromHash]
            );
        }

        return $this->findById($id) ?? [];
    }

    /**
     * Create an auto-redirect (e.g. from slug or taxonomy move).
     *
     * @return array<string, mixed>|null
     */
    public function createAutoRedirect(string $fromPath, string $toPath, ?string $actorId = null): ?array
    {
        $from = $this->normalizePath($fromPath);
        $to = $this->normalizePath($toPath);
        if ($from === $to) {
            return null;
        }

        try {
            return $this->create([
                'from_path' => $from,
                'to_path' => $to,
                'status_code' => 301,
                'match_type' => 'exact',
                'is_auto' => true,
            ], $actorId);
        } catch (ValidationException) {
            // Silently ignore loop conflicts in auto-redirects
            return null;
        }
    }

    /**
     * Find redirect by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $table = $this->connection->prefixTable('redirects');
        $stmt = $this->connection->execute("SELECT * FROM `{$table}` WHERE `id` = ?", [$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return RedirectPresenter::present($row);
    }

    /**
     * Update an existing redirect.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("Redirect item '{$id}' not found.");
        }

        $fromPath = isset($data['from_path']) ? $this->normalizePath((string) $data['from_path']) : $existing['from_path'];
        $toPath = isset($data['to_path']) ? trim((string) $data['to_path']) : $existing['to_path'];
        $statusCode = isset($data['status_code']) ? (int) $data['status_code'] : $existing['status_code'];
        $matchType = isset($data['match_type']) ? strtolower(trim((string) $data['match_type'])) : $existing['match_type'];
        $isAuto = isset($data['is_auto']) ? (bool) $data['is_auto'] : $existing['is_auto'];

        $this->validateRedirect($fromPath, $toPath, $statusCode, $matchType);
        $this->detectLoops($fromPath, $toPath, $id);

        $fromHash = hash('sha256', $fromPath);
        $table = $this->connection->prefixTable('redirects');

        // Check if another redirect has the same (from_hash, match_type)
        $dupStmt = $this->connection->execute(
            "SELECT `id` FROM `{$table}` WHERE `from_hash` = ? AND `match_type` = ? AND `id` != ?",
            [$fromHash, $matchType, $id]
        );
        if ($dupStmt->fetch() !== false) {
            throw new ValidationException("A redirect with from_path '{$fromPath}' and match_type '{$matchType}' already exists.", [
                'from_path' => ["Redirect for path '{$fromPath}' ({$matchType}) already exists."],
            ]);
        }

        $this->connection->execute(
            "UPDATE `{$table}` SET `from_path` = ?, `match_type` = ?, `to_path` = ?, `status_code` = ?, `is_auto` = ?, `from_hash` = ? WHERE `id` = ?",
            [$fromPath, $matchType, $toPath, $statusCode, $isAuto ? 1 : 0, $fromHash, $id]
        );

        return $this->findById($id) ?? [];
    }

    /**
     * Delete redirect by ID.
     */
    public function delete(string $id): bool
    {
        $table = $this->connection->prefixTable('redirects');
        $stmt = $this->connection->execute("DELETE FROM `{$table}` WHERE `id` = ?", [$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Bulk delete redirects.
     *
     * @param list<string> $ids
     */
    public function bulkDelete(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $table = $this->connection->prefixTable('redirects');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->connection->execute("DELETE FROM `{$table}` WHERE `id` IN ({$placeholders})", $ids);
        return $stmt->rowCount();
    }

    /**
     * List redirects with filtering and pagination.
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $table = $this->connection->prefixTable('redirects');
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $search = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['search']) . '%';
            $where[] = "(`from_path` LIKE ? OR `to_path` LIKE ?)";
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($filters['match_type'])) {
            $where[] = "`match_type` = ?";
            $params[] = (string) $filters['match_type'];
        }

        if (array_key_exists('is_auto', $filters) && $filters['is_auto'] !== null && $filters['is_auto'] !== '') {
            $where[] = "`is_auto` = ?";
            $params[] = !empty($filters['is_auto']) ? 1 : 0;
        }

        if (!empty($filters['status_code'])) {
            $where[] = "`status_code` = ?";
            $params[] = (int) $filters['status_code'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total count
        $countStmt = $this->connection->execute("SELECT COUNT(*) FROM `{$table}` {$whereClause}", $params);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $sql = "SELECT * FROM `{$table}` {$whereClause} ORDER BY `created_at` DESC LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->connection->execute($sql, $params);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = array_map(fn (array $r) => RedirectPresenter::present($r), $rows);
        $lastPage = max(1, (int) ceil($total / $perPage));

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    /**
     * Match a requested path against active redirects.
     *
     * @return array{id: string, destination: string, status_code: int, match_type: string}|null
     */
    public function match(string $rawPath): ?array
    {
        $path = $this->normalizePath($rawPath);
        $hash = hash('sha256', $path);
        $table = $this->connection->prefixTable('redirects');

        // 1. Exact match check
        $exactStmt = $this->connection->execute(
            "SELECT * FROM `{$table}` WHERE `from_hash` = ? AND `match_type` = 'exact' LIMIT 1",
            [$hash]
        );
        $exact = $exactStmt->fetch(PDO::FETCH_ASSOC);
        if ($exact !== false) {
            $this->recordHit((string) $exact['id']);
            return [
                'id' => (string) $exact['id'],
                'destination' => (string) $exact['to_path'],
                'status_code' => (int) $exact['status_code'],
                'match_type' => 'exact',
            ];
        }

        // 2. Prefix match check (longest prefix first)
        $prefixStmt = $this->connection->execute(
            "SELECT * FROM `{$table}` WHERE `match_type` = 'prefix' ORDER BY LENGTH(`from_path`) DESC"
        );
        /** @var list<array<string, mixed>> $prefixes */
        $prefixes = $prefixStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($prefixes as $p) {
            $prefixPath = (string) $p['from_path'];
            if (str_starts_with($path, $prefixPath)) {
                $suffix = substr($path, strlen($prefixPath));
                $toPath = rtrim((string) $p['to_path'], '/') . '/' . ltrim($suffix, '/');
                $toPath = rtrim($toPath, '/');
                if ($toPath === '') {
                    $toPath = '/';
                }

                $this->recordHit((string) $p['id']);
                return [
                    'id' => (string) $p['id'],
                    'destination' => $toPath,
                    'status_code' => (int) $p['status_code'],
                    'match_type' => 'prefix',
                ];
            }
        }

        return null;
    }

    /**
     * Increment hit counter for a redirect.
     */
    public function recordHit(string $id): void
    {
        $table = $this->connection->prefixTable('redirects');
        $now = Clock::sql(Clock::now());
        $this->connection->execute(
            "UPDATE `{$table}` SET `hits` = `hits` + 1, `last_hit_at` = ? WHERE `id` = ?",
            [$now, $id]
        );
    }

    /**
     * Export all redirects to CSV string.
     */
    public function exportCsv(): string
    {
        $table = $this->connection->prefixTable('redirects');
        $stmt = $this->connection->execute(
            "SELECT `from_path`, `to_path`, `status_code`, `match_type`, `is_auto`, `hits` FROM `{$table}` ORDER BY `from_path` ASC"
        );
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            return "from_path,to_path,status_code,match_type,is_auto,hits\n";
        }

        fputcsv($fp, ['from_path', 'to_path', 'status_code', 'match_type', 'is_auto', 'hits']);
        foreach ($rows as $row) {
            fputcsv($fp, [
                $row['from_path'],
                $row['to_path'],
                $row['status_code'],
                $row['match_type'],
                $row['is_auto'],
                $row['hits'],
            ]);
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return $csv !== false ? $csv : '';
    }

    /**
     * Import redirects from CSV content.
     *
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function importCsv(string $csvContent, ?string $actorId = null): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
        if ($lines === false || empty($lines)) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['CSV content is empty.']];
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $header = null;

        foreach ($lines as $lineIdx => $line) {
            if (trim($line) === '') {
                continue;
            }

            $row = str_getcsv($line);
            if ($header === null) {
                $header = array_map(fn ($h) => strtolower(trim((string) $h)), $row);
                continue;
            }

            $mapped = [];
            foreach ($header as $idx => $key) {
                $mapped[$key] = $row[$idx] ?? '';
            }

            $from = (string) ($mapped['from_path'] ?? $mapped['from'] ?? '');
            $to = (string) ($mapped['to_path'] ?? $mapped['to'] ?? '');
            $code = (int) ($mapped['status_code'] ?? $mapped['status'] ?? 301);
            $type = (string) ($mapped['match_type'] ?? $mapped['type'] ?? 'exact');

            if ($from === '' || $to === '') {
                $skipped++;
                $errors[] = "Line " . ($lineIdx + 1) . ": Missing from_path or to_path";
                continue;
            }

            try {
                $this->create([
                    'from_path' => $from,
                    'to_path' => $to,
                    'status_code' => $code,
                    'match_type' => $type,
                    'is_auto' => false,
                ], $actorId);
                $imported++;
            } catch (ValidationException $e) {
                $skipped++;
                $errors[] = "Line " . ($lineIdx + 1) . ": " . $e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Return all active redirects for Astro build and .htaccess export.
     *
     * @return list<array{from_path: string, to_path: string, status_code: int, match_type: string}>
     */
    public function listForPublic(): array
    {
        $table = $this->connection->prefixTable('redirects');
        $stmt = $this->connection->execute(
            "SELECT `from_path`, `to_path`, `status_code`, `match_type` FROM `{$table}` ORDER BY `match_type` ASC, LENGTH(`from_path`) DESC"
        );
        /** @var list<array{from_path: string, to_path: string, status_code: int, match_type: string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Normalize path ensuring leading slash, removing duplicate slashes, trimming trailing slash unless '/'.
     */
    public function normalizePath(string $path): string
    {
        $clean = trim($path);
        // If it starts with http:// or https://, keep as external URL
        if (preg_match('#^https?://#i', $clean)) {
            return $clean;
        }

        $clean = '/' . ltrim($clean, '/');
        // Replace multiple consecutive slashes
        $clean = (string) preg_replace('#/{2,}#', '/', $clean);
        // Trim trailing slash unless path is strictly '/'
        if (strlen($clean) > 1) {
            $clean = rtrim($clean, '/');
        }

        return $clean;
    }

    /**
     * Validate redirect attributes.
     */
    private function validateRedirect(string $fromPath, string $toPath, int $statusCode, string $matchType): void
    {
        $errors = [];

        if ($fromPath === '' || $fromPath === '/') {
            $errors['from_path'][] = "Source path cannot be empty or root '/'";
        }

        if ($toPath === '') {
            $errors['to_path'][] = "Destination path cannot be empty.";
        }

        if (!in_array($statusCode, self::ALLOWED_STATUS_CODES, true)) {
            $errors['status_code'][] = "Status code must be one of: " . implode(', ', self::ALLOWED_STATUS_CODES);
        }

        if (!in_array($matchType, self::ALLOWED_MATCH_TYPES, true)) {
            $errors['match_type'][] = "Match type must be strictly 'exact' or 'prefix'. Regex redirects are not permitted.";
        }

        // Self-loop check
        if ($fromPath === $toPath) {
            $errors['to_path'][] = "Redirect destination cannot be identical to the source path (self-loop).";
        }

        if (!empty($errors)) {
            throw new ValidationException("Invalid redirect configuration.", $errors);
        }
    }

    /**
     * Detect circular loops in redirect chain.
     */
    private function detectLoops(string $fromPath, string $toPath, ?string $excludeId = null): void
    {
        $table = $this->connection->prefixTable('redirects');
        $current = $toPath;
        $visited = [$fromPath => true];
        $depth = 0;

        while ($depth < 10) {
            $depth++;
            $clean = $this->normalizePath($current);
            $hash = hash('sha256', $clean);

            $sql = "SELECT `to_path` FROM `{$table}` WHERE `from_hash` = ?";
            $params = [$hash];
            if ($excludeId !== null) {
                $sql .= " AND `id` != ?";
                $params[] = $excludeId;
            }
            $stmt = $this->connection->execute($sql, $params);
            $next = $stmt->fetchColumn();

            if ($next === false) {
                break;
            }

            $nextTo = (string) $next;
            if (isset($visited[$nextTo]) || $nextTo === $fromPath) {
                throw new ValidationException(
                    "Redirect loop detected: '{$fromPath}' -> '{$toPath}' forms a circular loop with '{$nextTo}'.",
                    ['to_path' => ["Circular redirect loop detected."]]
                );
            }

            $visited[$nextTo] = true;
            $current = $nextTo;
        }

        if ($depth >= 10) {
            throw new ValidationException(
                "Redirect chain is too long (> 5 hops): '{$fromPath}' -> '{$toPath}'.",
                ['to_path' => ["Redirect chain exceeds maximum allowed depth."]]
            );
        }
    }
}
