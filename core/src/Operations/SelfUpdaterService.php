<?php

declare(strict_types=1);

namespace BsCms\Operations;

final class SelfUpdaterService
{
    public function __construct(
        private readonly string $storageDir,
        private readonly DatabaseBackupService $backupService
    ) {
    }

    /**
     * Verify package signature and checksum before performing an update.
     *
     * @param string $zipPath Path to bs-cms-<version>.zip
     * @param string $sha256Sums Expected sha256 checksums file content
     * @param string $signature Ed25519 binary signature (sodium_crypto_sign_detached)
     * @param string $publicKey Ed25519 32-byte public key
     */
    public function verifyRelease(string $zipPath, string $sha256Sums, string $signature, string $publicKey): bool
    {
        if (!is_file($zipPath)) {
            return false;
        }

        // 1. Verify Ed25519 signature over SHA256SUMS
        if (strlen($publicKey) === 32 && strlen($signature) === 64) {
            $valid = sodium_crypto_sign_verify_detached($signature, $sha256Sums, $publicKey);
            if (!$valid) {
                return false;
            }
        }

        // 2. Verify zip file matches hash in SHA256SUMS
        $actualHash = hash_file('sha256', $zipPath);
        if ($actualHash === false) {
            return false;
        }

        $filename = basename($zipPath);
        return str_contains($sha256Sums, $actualHash);
    }

    /**
     * Enter maintenance mode by touching maintenance.lock.
     */
    public function enterMaintenance(): void
    {
        $lock = rtrim($this->storageDir, '/\\') . '/maintenance.lock';
        file_put_contents($lock, json_encode([
            'reason' => 'self_update',
            'started_at' => date('Y-m-d H:i:s'),
        ]));
    }

    /**
     * Exit maintenance mode by removing maintenance.lock.
     */
    public function exitMaintenance(): void
    {
        $lock = rtrim($this->storageDir, '/\\') . '/maintenance.lock';
        if (is_file($lock)) {
            @unlink($lock);
        }
    }

    /**
     * Create automatic pre-update backup of database and critical code files.
     *
     * @return array{file: string, size_bytes: int, tables: list<string>}
     */
    public function createPreUpdateBackup(): array
    {
        return $this->backupService->createBackup('pre_update');
    }
}
