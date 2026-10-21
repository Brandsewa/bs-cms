<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Operations\DatabaseBackupService;
use BsCms\Operations\SelfUpdaterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MaintenanceController extends BaseController
{
    public function __construct(
        private readonly SelfUpdaterService $updater,
        private readonly DatabaseBackupService $backupService,
        private readonly Authorizer $authorizer,
        private readonly string $storageDir
    ) {
    }

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $lockFile = rtrim($this->storageDir, '/\\') . '/maintenance.lock';
        $inMaintenance = is_file($lockFile);
        $details = null;

        if ($inMaintenance) {
            $raw = file_get_contents($lockFile);
            $details = $raw !== false ? json_decode($raw, true) : null;
        }

        return $this->json([
            'maintenance_mode' => $inMaintenance,
            'details' => $details,
        ]);
    }

    public function enable(ServerRequestInterface $request): ResponseInterface
    {
        $perm = $this->requirePermission($this->authorizer, $request, 'update', new Subject('system', 'maintenance'));
        if ($perm !== null) {
            return $perm;
        }

        $this->updater->enterMaintenance();
        return $this->json(['message' => 'Maintenance mode enabled successfully.', 'maintenance_mode' => true]);
    }

    public function disable(ServerRequestInterface $request): ResponseInterface
    {
        $perm = $this->requirePermission($this->authorizer, $request, 'update', new Subject('system', 'maintenance'));
        if ($perm !== null) {
            return $perm;
        }

        $this->updater->exitMaintenance();
        return $this->json(['message' => 'Maintenance mode disabled successfully.', 'maintenance_mode' => false]);
    }

    public function createBackup(ServerRequestInterface $request): ResponseInterface
    {
        $perm = $this->requirePermission($this->authorizer, $request, 'create', new Subject('system', 'backup'));
        if ($perm !== null) {
            return $perm;
        }

        try {
            $result = $this->backupService->createBackup();
            return $this->json([
                'message' => 'Database backup generated successfully.',
                'backup' => [
                    'file' => basename($result['file']),
                    'size_bytes' => $result['size_bytes'],
                    'table_count' => count($result['tables']),
                ],
            ], 201);
        } catch (\Throwable $e) {
            return $this->problem(500, 'Backup Failed', $e->getMessage());
        }
    }
}
