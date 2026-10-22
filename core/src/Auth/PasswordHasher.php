<?php

declare(strict_types=1);

namespace BsCms\Auth;

use RuntimeException;

/**
 * Handles password hashing, verification, rehashing checks,
 * dummy verification for timing attack mitigation, and password policy validation.
 */
final class PasswordHasher
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 256;

    /**
     * @var array<string, bool>|null
     */
    private static ?array $commonPasswords = null;

    private static ?string $dummyHash = null;

    private string $algo;

    /** @var array<string, int> */
    private array $options;

    private string $commonPasswordsFile;
    private ?\BsCms\Settings\SettingsRepository $settings = null;

    /**
     * @param array<string, int>|null $options
     */
    public function __construct(
        ?string $algo = null,
        ?array $options = null,
        ?string $commonPasswordsFile = null,
        ?\BsCms\Settings\SettingsRepository $settings = null
    ) {
        $this->settings = $settings;
        if ($algo !== null) {
            $this->algo = $algo;
        } elseif (defined('PASSWORD_ARGON2ID')) {
            $this->algo = PASSWORD_ARGON2ID;
        } else {
            $this->algo = PASSWORD_BCRYPT;
        }

        if ($options !== null) {
            $this->options = $options;
        } elseif (defined('PASSWORD_ARGON2ID') && $this->algo === PASSWORD_ARGON2ID) {
            // Argon2id per spec: memory_cost >= 19456 KiB, time_cost 2, threads 1
            $this->options = [
                'memory_cost' => 19456,
                'time_cost' => 2,
                'threads' => 1,
            ];
        } else {
            $this->options = [
                'cost' => 12,
            ];
        }

        $this->commonPasswordsFile = $commonPasswordsFile ?? dirname(__DIR__, 2) . '/resources/passwords/common_10k.txt';
    }

    public function hash(string $password): string
    {
        return password_hash($password, $this->algo, $this->options);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo, $this->options);
    }

    /**
     * Perform a dummy verification against a pre-computed valid hash.
     * Prevents timing attacks / user enumeration when checking non-existent accounts.
     */
    public function dummyVerify(?string $password = null): void
    {
        if (self::$dummyHash === null) {
            self::$dummyHash = $this->hash('dummy_bs_cms_password_timing_pad_123!');
        }
        $this->verify($password ?? 'dummy_sample_password_to_verify', self::$dummyHash);
    }

    public function setSettings(\BsCms\Settings\SettingsRepository $settings): void
    {
        $this->settings = $settings;
    }

    /**
     * Validate a prospective password against CMS security policy:
     * - Configurable or default min length, maximum 256 characters.
     * - Require uppercase, lowercase, numbers, symbols if configured.
     * - Check against bundled 10k common passwords list.
     * - Returns null if valid, or a descriptive error message if invalid.
     */
    public function validatePolicy(string $password): ?string
    {
        $policy = [];
        if ($this->settings !== null) {
            try {
                $saved = $this->settings->get('security_settings', []);
                if (is_array($saved)) {
                    $policy = $saved;
                }
            } catch (\Throwable) {
                $policy = [];
            }
        }

        $minLength = isset($policy['password_min_length']) && is_numeric($policy['password_min_length'])
            ? (int) $policy['password_min_length']
            : self::MIN_LENGTH;

        $len = strlen($password);
        if ($len < $minLength) {
            return "Password must be at least {$minLength} characters long.";
        }
        if ($len > self::MAX_LENGTH) {
            return 'Password must not exceed 256 characters.';
        }

        if (!empty($policy['password_require_uppercase']) && !preg_match('/[A-Z]/', $password)) {
            return 'Password must contain at least one uppercase letter.';
        }
        if (!empty($policy['password_require_lowercase']) && !preg_match('/[a-z]/', $password)) {
            return 'Password must contain at least one lowercase letter.';
        }
        if (!empty($policy['password_require_numbers']) && !preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one number.';
        }
        if (!empty($policy['password_require_symbols']) && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            return 'Password must contain at least one special symbol.';
        }

        if ($this->isCommonPassword($password)) {
            return 'This password is too common and easily guessed. Please choose a more unique passphrase.';
        }

        return null;
    }

    public function isCommonPassword(string $password): bool
    {
        if (self::$commonPasswords === null) {
            self::$commonPasswords = [];
            if (is_file($this->commonPasswordsFile)) {
                $lines = file($this->commonPasswordsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines !== false) {
                    foreach ($lines as $line) {
                        $trimmed = strtolower(trim($line));
                        if ($trimmed !== '') {
                            self::$commonPasswords[$trimmed] = true;
                        }
                    }
                }
            }
        }

        return isset(self::$commonPasswords[strtolower($password)]);
    }

    public function getAlgo(): string
    {
        return $this->algo;
    }
}
