<?php

declare(strict_types=1);

namespace BsCms\Dashboard;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Auth\SystemRoles;
use BsCms\Database\Connection;
use BsCms\Health\SiteHealthService;
use BsCms\Kernel\Config;
use BsCms\PendingChanges\PendingChangesService;
use BsCms\Schema\CollectionRegistry;
use PDO;

/**
 * Service aggregating role-aware metrics, queues, health, and status for the admin dashboard.
 * Executes fast, indexed, bounded queries with SQL-level user scoping.
 */
final class DashboardService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CollectionRegistry $collectionRegistry,
        private readonly PendingChangesService $pendingChangesService,
        private readonly SiteHealthService $siteHealthService,
        private readonly AuditLogger $auditLogger,
        private readonly Config $config
    ) {
    }

    /**
     * Compute dashboard aggregate summary tailored to the actor's permissions and role.
     *
     * @return array<string, mixed>
     */
    public function getDashboardData(Actor $actor): array
    {
        $pdo = $this->connection->getPdo();
        $prefix = $this->connection->getPrefix();
        $roleHandles = [];
        $unresolvedIds = [];
        foreach ($actor->roleIds as $r) {
            match ($r) {
                SystemRoles::ROLE_OWNER, 'owner' => $roleHandles[] = 'owner',
                SystemRoles::ROLE_ADMIN, 'admin' => $roleHandles[] = 'admin',
                SystemRoles::ROLE_DEVELOPER, 'developer' => $roleHandles[] = 'developer',
                SystemRoles::ROLE_EDITOR, 'editor' => $roleHandles[] = 'editor',
                SystemRoles::ROLE_AUTHOR, 'author' => $roleHandles[] = 'author',
                SystemRoles::ROLE_CONTRIBUTOR, 'contributor' => $roleHandles[] = 'contributor',
                SystemRoles::ROLE_VIEWER, 'viewer' => $roleHandles[] = 'viewer',
                default => $unresolvedIds[] = $r,
            };
        }
        if (!empty($unresolvedIds)) {
            $inClause = implode(',', array_fill(0, count($unresolvedIds), '?'));
            $rolesTable = $prefix . 'roles';
            $stmt = $pdo->prepare("SELECT `handle` FROM `{$rolesTable}` WHERE `id` IN ({$inClause})");
            $stmt->execute($unresolvedIds);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $roleHandles[] = (string) $row['handle'];
            }
        }

        // Role levels
        $isOwnerOrAdmin = $actor->isSystem()
            || in_array('owner', $roleHandles, true)
            || in_array('admin', $roleHandles, true)
            || in_array('developer', $roleHandles, true)
            || (empty($actor->roleIds) && $actor->isUser());

        $isEditor = in_array('editor', $roleHandles, true) && !$isOwnerOrAdmin;
        $isAuthor = in_array('author', $roleHandles, true) && !$isOwnerOrAdmin && !$isEditor;
        $isContributor = in_array('contributor', $roleHandles, true) && !$isOwnerOrAdmin && !$isEditor && !$isAuthor;
        $isViewer = in_array('viewer', $roleHandles, true) && !$isOwnerOrAdmin && !$isEditor && !$isAuthor && !$isContributor;

        // SQL-level authorship isolation for authors and contributors
        $filterByUserId = ($isAuthor || $isContributor)
            ? ($actor->onBehalfOf ?? $actor->id)
            : null;

        // 1. Discover valid collection tables
        $validHandles = $this->getValidCollectionHandles($pdo, $prefix);

        // 2. Aggregate Counts
        $counts = $this->aggregateCounts($pdo, $prefix, $validHandles, $filterByUserId, $isOwnerOrAdmin);

        // 3. Content queues (recent edits, drafts waiting, scheduled items)
        $recentEdits = $this->getRecentEdits($pdo, $prefix, $validHandles, $filterByUserId);
        $draftsWaiting = $this->getDraftsWaiting($pdo, $prefix, $validHandles, $filterByUserId);
        $scheduledItems = $this->getScheduledItems($pdo, $prefix, $validHandles, $filterByUserId);

        // 4. Pending AI changes (Admin/Owner only)
        $pendingAiChanges = $isOwnerOrAdmin
            ? $this->getPendingAiChanges($pdo)
            : [];

        // 5. Site Health
        $siteHealth = $this->getSiteHealthSummary();

        // 6. Recent Activity / Audit Log (Admin/Owner only; omitted for Editor, Author, Contributor, Viewer)
        $recentActivity = $isOwnerOrAdmin
            ? $this->getRecentActivity($pdo)
            : null;

        // 7. System Status Telemetry (Admin/Owner only; omitted for Editor, Author, Contributor, Viewer)
        $systemStatus = $isOwnerOrAdmin
            ? $this->getSystemStatus($pdo)
            : null;

        // 8. Onboarding checklist
        $onboarding = $this->getOnboardingStatus($pdo, $prefix, $validHandles, $systemStatus);

        // Assemble response matching docs/ui/BACKEND-REQUESTS.md Item 8 contract exactly
        $userName = $actor->userName;
        if (($userName === null || $userName === '') && ($actor->onBehalfOf !== null || $actor->isUser())) {
            $targetUserId = $actor->onBehalfOf ?? $actor->id;
            $uTable = $prefix . 'users';
            $uStmt = $pdo->prepare("SELECT `name` FROM `{$uTable}` WHERE `id` = ? LIMIT 1");
            $uStmt->execute([$targetUserId]);
            $dbName = $uStmt->fetchColumn();
            if (is_string($dbName) && trim($dbName) !== '') {
                $userName = trim($dbName);
            }
        }
        if ($userName === null || $userName === '') {
            $userName = $actor->label !== '' ? $actor->label : 'Administrator';
        }

        $environment = (string) $this->config->get('app.env', 'production');

        $result = [
            'greeting' => [
                'user_name' => $userName,
                'environment' => $environment,
            ],
            'counts' => [
                'drafts' => $counts['drafts'],
                'scheduled' => $counts['scheduled'],
                'pending_ai_changes' => $counts['pending_ai_changes'],
            ],
            'recent_edits' => $recentEdits,
            'drafts_waiting' => $draftsWaiting,
            'scheduled_items' => $scheduledItems,
            'pending_ai_changes' => $pendingAiChanges,
            'site_health' => $siteHealth,
        ];

        if ($recentActivity !== null) {
            $result['recent_activity'] = $recentActivity;
        }

        if ($systemStatus !== null) {
            $result['system_status'] = $systemStatus;
        }

        $result['onboarding'] = $onboarding;

        return $result;
    }

    /**
     * @return list<string>
     */
    private function getValidCollectionHandles(PDO $pdo, string $prefix): array
    {
        $all = $this->collectionRegistry->all();
        $handles = [];
        foreach ($all as $h => $def) {
            $kind = (string) ($def['kind'] ?? 'collection');
            if ($kind === 'collection') {
                $handles[] = (string) $h;
            }
        }

        if (empty($handles)) {
            $handles = ['posts', 'pages'];
        }

        $stmt = $pdo->query("SHOW TABLES LIKE '{$prefix}c_%'");
        $tables = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

        $valid = [];
        foreach ($handles as $h) {
            if (in_array($prefix . 'c_' . $h, $tables, true)) {
                $valid[] = $h;
            }
        }

        return $valid;
    }

    /**
     * @param list<string> $validHandles
     * @return array{drafts: int, scheduled: int, pending_ai_changes: int}
     */
    private function aggregateCounts(
        PDO $pdo,
        string $prefix,
        array $validHandles,
        ?string $filterByUserId,
        bool $isOwnerOrAdmin
    ): array {
        $drafts = 0;
        $scheduled = 0;

        if (!empty($validHandles)) {
            $queries = [];
            $params = [];
            foreach ($validHandles as $i => $h) {
                $table = $prefix . 'c_' . $h;
                $userClauseDraft = '';
                $userClauseSched = '';
                if ($filterByUserId !== null) {
                    $userClauseDraft = " AND `created_by` = :uid_d_{$i}";
                    $userClauseSched = " AND `created_by` = :uid_s_{$i}";
                    $params["uid_d_{$i}"] = $filterByUserId;
                    $params["uid_s_{$i}"] = $filterByUserId;
                }
                $queries[] = "SELECT 'draft' AS `type`, COUNT(*) AS `c` FROM `{$table}` WHERE `deleted_at` IS NULL AND `status` = 'draft'{$userClauseDraft}";
                $queries[] = "SELECT 'scheduled' AS `type`, COUNT(*) AS `c` FROM `{$table}` WHERE `deleted_at` IS NULL AND `status` = 'scheduled'{$userClauseSched}";
            }

            $sql = implode(' UNION ALL ', $queries);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['type'] === 'draft') {
                    $drafts += (int) $row['c'];
                } elseif ($row['type'] === 'scheduled') {
                    $scheduled += (int) $row['c'];
                }
            }
        }

        $pendingAi = 0;
        if ($isOwnerOrAdmin) {
            $table = $this->connection->prefixTable('pending_changes');
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE `status` = 'pending'");
                if ($stmt !== false) {
                    $pendingAi = (int) $stmt->fetchColumn();
                }
            } catch (\Throwable) {
            }
        }

        return [
            'drafts' => $drafts,
            'scheduled' => $scheduled,
            'pending_ai_changes' => $pendingAi,
        ];
    }

    /**
     * @param list<string> $validHandles
     * @return list<array{id: string, collection: string, title: string, status: string, updated_at: string, updated_by?: string}>
     */
    private function getRecentEdits(
        PDO $pdo,
        string $prefix,
        array $validHandles,
        ?string $filterByUserId
    ): array {
        if (empty($validHandles)) {
            return [];
        }

        $usersTable = $this->connection->prefixTable('users');
        $subqueries = [];
        $params = [];

        foreach ($validHandles as $i => $h) {
            $table = $prefix . 'c_' . $h;
            $userClause = '';
            if ($filterByUserId !== null) {
                $userClause = " AND c.`created_by` = :re_uid_{$i}";
                $params["re_uid_{$i}"] = $filterByUserId;
            }
            $subqueries[] = "(SELECT c.`id`, '{$h}' AS `collection`, c.`title`, c.`status`, c.`updated_at`, COALESCE(u.`name`, c.`updated_by`) AS `updated_by`
                             FROM `{$table}` c
                             LEFT JOIN `{$usersTable}` u ON u.`id` = c.`updated_by`
                             WHERE c.`deleted_at` IS NULL{$userClause}
                             ORDER BY c.`updated_at` DESC LIMIT 10)";
        }

        $sql = implode(' UNION ALL ', $subqueries) . " ORDER BY `updated_at` DESC LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $edits = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $edits[] = [
                'id' => (string) $row['id'],
                'collection' => (string) $row['collection'],
                'title' => (string) $row['title'],
                'status' => (string) $row['status'],
                'updated_at' => (string) date('c', strtotime((string) $row['updated_at'])),
                'updated_by' => $row['updated_by'] !== null ? (string) $row['updated_by'] : 'System',
            ];
        }

        return $edits;
    }

    /**
     * @param list<string> $validHandles
     * @return list<array{id: string, collection: string, title: string, status: string, updated_at: string}>
     */
    private function getDraftsWaiting(
        PDO $pdo,
        string $prefix,
        array $validHandles,
        ?string $filterByUserId
    ): array {
        if (empty($validHandles)) {
            return [];
        }

        $subqueries = [];
        $params = [];

        foreach ($validHandles as $i => $h) {
            $table = $prefix . 'c_' . $h;
            $userClause = '';
            if ($filterByUserId !== null) {
                $userClause = " AND `created_by` = :dw_uid_{$i}";
                $params["dw_uid_{$i}"] = $filterByUserId;
            }
            $subqueries[] = "(SELECT `id`, '{$h}' AS `collection`, `title`, `status`, `updated_at`
                             FROM `{$table}`
                             WHERE `deleted_at` IS NULL AND `status` = 'draft'{$userClause}
                             ORDER BY `updated_at` DESC LIMIT 5)";
        }

        $sql = implode(' UNION ALL ', $subqueries) . " ORDER BY `updated_at` DESC LIMIT 5";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $drafts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $drafts[] = [
                'id' => (string) $row['id'],
                'collection' => (string) $row['collection'],
                'title' => (string) $row['title'],
                'status' => 'draft',
                'updated_at' => (string) date('c', strtotime((string) $row['updated_at'])),
            ];
        }

        return $drafts;
    }

    /**
     * @param list<string> $validHandles
     * @return list<array{id: string, collection: string, title: string, scheduled_at: string}>
     */
    private function getScheduledItems(
        PDO $pdo,
        string $prefix,
        array $validHandles,
        ?string $filterByUserId
    ): array {
        if (empty($validHandles)) {
            return [];
        }

        $subqueries = [];
        $params = [];

        foreach ($validHandles as $i => $h) {
            $table = $prefix . 'c_' . $h;
            $userClause = '';
            if ($filterByUserId !== null) {
                $userClause = " AND `created_by` = :sc_uid_{$i}";
                $params["sc_uid_{$i}"] = $filterByUserId;
            }
            $subqueries[] = "(SELECT `id`, '{$h}' AS `collection`, `title`, `scheduled_at`
                             FROM `{$table}`
                             WHERE `deleted_at` IS NULL AND `status` = 'scheduled'{$userClause}
                             ORDER BY `scheduled_at` ASC LIMIT 5)";
        }

        $sql = implode(' UNION ALL ', $subqueries) . " ORDER BY `scheduled_at` ASC LIMIT 5";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $scheduled = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $scheduled[] = [
                'id' => (string) $row['id'],
                'collection' => (string) $row['collection'],
                'title' => (string) $row['title'],
                'scheduled_at' => (string) date('c', strtotime((string) $row['scheduled_at'])),
            ];
        }

        return $scheduled;
    }

    /**
     * @return list<array{id: string, actor_label: string, action: string, target_collection: ?string, target_id: ?string, created_at: string}>
     */
    private function getPendingAiChanges(PDO $pdo): array
    {
        $table = $this->connection->prefixTable('pending_changes');
        $items = [];
        try {
            $stmt = $pdo->prepare(
                "SELECT `id`, `actor_label`, `operation` AS `action`, `subject_type` AS `target_collection`, `subject_id` AS `target_id`, `created_at`
                 FROM `{$table}`
                 WHERE `status` = 'pending'
                 ORDER BY `created_at` DESC
                 LIMIT 5"
            );
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $items[] = [
                    'id' => (string) $row['id'],
                    'actor_label' => (string) $row['actor_label'],
                    'action' => (string) $row['action'],
                    'target_collection' => $row['target_collection'] !== null ? (string) $row['target_collection'] : null,
                    'target_id' => $row['target_id'] !== null ? (string) $row['target_id'] : null,
                    'created_at' => (string) date('c', strtotime((string) $row['created_at'])),
                ];
            }
        } catch (\Throwable) {
        }

        return $items;
    }

    /**
     * @return array{status: string, total_issues: int, errors: int, warnings: int, scanned_at?: string, top_issues?: list<array{rule_id: string, message: string, severity: string}>}
     */
    private function getSiteHealthSummary(): array
    {
        $summary = [
            'status' => 'healthy',
            'total_issues' => 0,
            'errors' => 0,
            'warnings' => 0,
            'scanned_at' => date('c'),
            'top_issues' => [],
        ];

        try {
            $scan = $this->siteHealthService->scan();
            $sum = $scan['summary'] ?? [];
            $summary['status'] = (string) ($sum['status'] ?? 'healthy');
            $summary['total_issues'] = (int) ($sum['total'] ?? 0);
            $summary['errors'] = (int) ($sum['errors'] ?? 0);
            $summary['warnings'] = (int) ($sum['warnings'] ?? 0);
            if (isset($scan['scanned_at']) && is_string($scan['scanned_at'])) {
                $summary['scanned_at'] = date('c', strtotime($scan['scanned_at']));
            }

            $top = [];
            $issues = $scan['issues'] ?? [];
            foreach (array_slice($issues, 0, 3) as $issue) {
                $top[] = [
                    'rule_id' => (string) ($issue['rule_id'] ?? ''),
                    'message' => (string) ($issue['message'] ?? ''),
                    'severity' => (string) ($issue['severity'] ?? 'warn'),
                ];
            }
            $summary['top_issues'] = $top;
        } catch (\Throwable) {
        }

        return $summary;
    }

    /**
     * @return list<array{id: string, action: string, actor: string, target: string, created_at: string}>
     */
    private function getRecentActivity(PDO $pdo): array
    {
        $table = $this->connection->prefixTable('audit_log');
        $activity = [];

        try {
            $stmt = $pdo->prepare(
                "SELECT `id`, `action`, COALESCE(`actor_label`, `actor_type`) AS `actor`, COALESCE(`summary`, `subject_id`, `subject_type`) AS `target`, `created_at`
                 FROM `{$table}`
                 ORDER BY `id` DESC
                 LIMIT 10"
            );
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $activity[] = [
                    'id' => (string) $row['id'],
                    'action' => (string) $row['action'],
                    'actor' => (string) $row['actor'],
                    'target' => (string) $row['target'],
                    'created_at' => (string) date('c', strtotime((string) $row['created_at'])),
                ];
            }
        } catch (\Throwable) {
        }

        return $activity;
    }

    /**
     * @return array{cron: string, mail: string, disk_usage_percent: int, php_version: string}
     */
    private function getSystemStatus(PDO $pdo): array
    {
        // 1. Cron status
        $cron = 'healthy';
        $jobsTable = $this->connection->prefixTable('jobs');
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$jobsTable}` WHERE `status` = 'failed'");
            if ($stmt !== false && (int) $stmt->fetchColumn() > 0) {
                $cron = 'warning';
            }
        } catch (\Throwable) {
        }

        // 2. Mail status
        $mail = 'healthy';
        $settingsTable = $this->connection->prefixTable('settings');
        try {
            $stmt = $pdo->prepare("SELECT `value` FROM `{$settingsTable}` WHERE `key` = 'email'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val === false || $val === null || $val === '') {
                $mail = 'unconfigured';
            } else {
                $cfg = json_decode((string) $val, true);
                if (empty($cfg['host']) && empty($cfg['from_email'])) {
                    $mail = 'unconfigured';
                }
            }

            if ($mail !== 'unconfigured') {
                $logTable = $this->connection->prefixTable('email_log');
                $failStmt = $pdo->query("SELECT COUNT(*) FROM `{$logTable}` WHERE `status` = 'failed' AND `created_at` > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                if ($failStmt !== false && (int) $failStmt->fetchColumn() > 0) {
                    $mail = 'error';
                }
            }
        } catch (\Throwable) {
        }

        // 3. Disk usage percent (coarse integer percentage; no paths leaked!)
        $diskUsage = 24;
        try {
            $total = @disk_total_space('.');
            $free = @disk_free_space('.');
            if ($total !== false && $free !== false && $total > 0) {
                $diskUsage = (int) round((1 - ($free / $total)) * 100);
            }
        } catch (\Throwable) {
        }

        // 4. PHP version (major.minor.release only)
        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;

        return [
            'cron' => $cron,
            'mail' => $mail,
            'disk_usage_percent' => max(0, min(100, $diskUsage)),
            'php_version' => $phpVersion,
        ];
    }

    /**
     * @param list<string> $validHandles
     * @param array{cron: string, mail: string, disk_usage_percent: int, php_version: string}|null $systemStatus
     * @return array{completed: boolean, steps: array{create_page: boolean, write_post: boolean, site_details: boolean, configure_email: boolean, connect_astro: boolean, connect_ai: boolean}}
     */
    private function getOnboardingStatus(
        PDO $pdo,
        string $prefix,
        array $validHandles,
        ?array $systemStatus
    ): array {
        $createPage = false;
        $writePost = false;
        $siteDetails = false;
        $configureEmail = false;
        $connectAstro = false;
        $connectAi = false;

        try {
            if (in_array('pages', $validHandles, true)) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM `{$prefix}c_pages` WHERE `deleted_at` IS NULL");
                $createPage = $stmt !== false && (int) $stmt->fetchColumn() > 0;
            }
            if (in_array('posts', $validHandles, true)) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM `{$prefix}c_posts` WHERE `deleted_at` IS NULL");
                $writePost = $stmt !== false && (int) $stmt->fetchColumn() > 0;
            }

            // Site details
            $settingsTable = $this->connection->prefixTable('settings');
            $stmt = $pdo->prepare("SELECT `value` FROM `{$settingsTable}` WHERE `key` = 'site'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null && $val !== '') {
                $siteDetails = true;
            }

            // Email configuration
            if ($systemStatus !== null && $systemStatus['mail'] !== 'unconfigured') {
                $configureEmail = true;
            } else {
                $stmt = $pdo->prepare("SELECT `value` FROM `{$settingsTable}` WHERE `key` = 'email'");
                $stmt->execute();
                $val = $stmt->fetchColumn();
                if ($val !== false && $val !== null && $val !== '') {
                    $cfg = json_decode((string) $val, true);
                    $configureEmail = !empty($cfg['host']) || !empty($cfg['from_email']);
                }
            }

            // Astro integration
            $btTable = $this->connection->prefixTable('build_tokens');
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$btTable}`");
            $connectAstro = $stmt !== false && (int) $stmt->fetchColumn() > 0;

            // AI connection
            $ocTable = $this->connection->prefixTable('oauth_clients');
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$ocTable}`");
            $connectAi = $stmt !== false && (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
        }

        $completed = $createPage && $writePost && $siteDetails && $configureEmail && $connectAstro && $connectAi;

        return [
            'completed' => $completed,
            'steps' => [
                'create_page' => $createPage,
                'write_post' => $writePost,
                'site_details' => $siteDetails,
                'configure_email' => $configureEmail,
                'connect_astro' => $connectAstro,
                'connect_ai' => $connectAi,
            ],
        ];
    }
}
