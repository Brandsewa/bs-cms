<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers\SprintA;

use BsCms\Auth\Actor;
use BsCms\Auth\SystemRoles;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Http\Controllers\BaseController;
use BsCms\Settings\SettingsRepository;
use BsCms\Users\RoleRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller managing platform security settings:
 * - Password policy (min length, require uppercase, require lowercase, require numbers, require symbols)
 * - 2FA enforcement policy (modes: optional, mandatory_all, mandatory_roles; enforced roles)
 * - Session lifetime policy (idle minutes, absolute lifetime hours)
 * - Rate limiting (login max attempts per window, rate limit window seconds)
 * - Allowed registration/login email domains
 */
class SecuritySettingsController extends BaseController
{
    private const SETTING_KEY = 'security_settings';

    private const DEFAULTS = [
        'password_min_length' => 12,
        'password_require_uppercase' => true,
        'password_require_lowercase' => true,
        'password_require_numbers' => true,
        'password_require_symbols' => true,
        'two_factor_policy' => 'optional', // 'optional' | 'mandatory_all' | 'mandatory_roles'
        'two_factor_enforced_roles' => [SystemRoles::ROLE_OWNER, SystemRoles::ROLE_ADMIN],
        'session_idle_timeout_minutes' => 60,
        'session_absolute_lifetime_hours' => 24,
        'login_max_attempts' => 5,
        'login_decay_minutes' => 15,
        'allowed_email_domains' => [],
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Authorizer $authorizer,
        private readonly ?RoleRepository $roles = null
    ) {
    }

    /**
     * GET /api/v1/settings/security
     */
    public function getSettings(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('settings', 'security'));
        if ($permError !== null) {
            return $permError;
        }

        $saved = $this->settings->get(self::SETTING_KEY, []);
        $merged = array_merge(self::DEFAULTS, is_array($saved) ? $saved : []);

        return $this->json($merged);
    }

    /**
     * PUT /api/v1/settings/security
     */
    public function updateSettings(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('settings', 'security'));
        if ($permError !== null) {
            return $permError;
        }

        $body = $this->getBody($request);
        $saved = $this->settings->get(self::SETTING_KEY, []);
        $current = array_merge(self::DEFAULTS, is_array($saved) ? $saved : []);
        $errors = [];

        if (array_key_exists('password_min_length', $body)) {
            $val = $body['password_min_length'];
            if (!is_int($val) && (!is_string($val) || !ctype_digit($val))) {
                $errors['password_min_length'][] = 'Password min length must be an integer between 8 and 128.';
            } else {
                $intVal = (int) $val;
                if ($intVal < 8 || $intVal > 128) {
                    $errors['password_min_length'][] = 'Password min length must be between 8 and 128 characters.';
                } else {
                    $current['password_min_length'] = $intVal;
                }
            }
        }

        if (array_key_exists('password_require_uppercase', $body)) {
            if (!is_bool($body['password_require_uppercase'])) {
                $errors['password_require_uppercase'][] = 'password_require_uppercase must be a boolean.';
            } else {
                $current['password_require_uppercase'] = $body['password_require_uppercase'];
            }
        }
        if (array_key_exists('password_require_lowercase', $body)) {
            if (!is_bool($body['password_require_lowercase'])) {
                $errors['password_require_lowercase'][] = 'password_require_lowercase must be a boolean.';
            } else {
                $current['password_require_lowercase'] = $body['password_require_lowercase'];
            }
        }
        if (array_key_exists('password_require_numbers', $body)) {
            if (!is_bool($body['password_require_numbers'])) {
                $errors['password_require_numbers'][] = 'password_require_numbers must be a boolean.';
            } else {
                $current['password_require_numbers'] = $body['password_require_numbers'];
            }
        }
        if (array_key_exists('password_require_symbols', $body)) {
            if (!is_bool($body['password_require_symbols'])) {
                $errors['password_require_symbols'][] = 'password_require_symbols must be a boolean.';
            } else {
                $current['password_require_symbols'] = $body['password_require_symbols'];
            }
        }

        if (array_key_exists('two_factor_policy', $body)) {
            if (!in_array($body['two_factor_policy'], ['optional', 'mandatory_all', 'mandatory_roles'], true)) {
                $errors['two_factor_policy'][] = "two_factor_policy must be one of: 'optional', 'mandatory_all', 'mandatory_roles'.";
            } else {
                $current['two_factor_policy'] = $body['two_factor_policy'];
            }
        }

        if (array_key_exists('two_factor_enforced_roles', $body)) {
            if (!is_array($body['two_factor_enforced_roles'])) {
                $errors['two_factor_enforced_roles'][] = 'two_factor_enforced_roles must be an array of role identifiers.';
            } else {
                $validatedRoles = [];
                foreach ($body['two_factor_enforced_roles'] as $idx => $roleRef) {
                    if (!is_string($roleRef) || trim($roleRef) === '') {
                        $errors['two_factor_enforced_roles'][] = "Invalid role identifier at index {$idx}.";
                        continue;
                    }
                    $roleStr = trim($roleRef);
                    if ($this->roles !== null) {
                        $found = $this->roles->findById($roleStr) ?? $this->roles->findByHandle($roleStr);
                        if ($found === null) {
                            $errors['two_factor_enforced_roles'][] = "Role '{$roleStr}' does not exist.";
                            continue;
                        }
                        $validatedRoles[] = (string) $found['id'];
                    } else {
                        $validatedRoles[] = $roleStr;
                    }
                }
                $current['two_factor_enforced_roles'] = array_values(array_unique($validatedRoles));
            }
        }

        if (array_key_exists('session_idle_timeout_minutes', $body)) {
            $val = $body['session_idle_timeout_minutes'];
            if (!is_int($val) && (!is_string($val) || !ctype_digit($val))) {
                $errors['session_idle_timeout_minutes'][] = 'session_idle_timeout_minutes must be a positive integer.';
            } else {
                $intVal = (int) $val;
                if ($intVal <= 0 || $intVal > 1440) {
                    $errors['session_idle_timeout_minutes'][] = 'session_idle_timeout_minutes must be greater than 0 (max 1440 minutes).';
                } else {
                    $current['session_idle_timeout_minutes'] = $intVal;
                }
            }
        }

        if (array_key_exists('session_absolute_lifetime_hours', $body)) {
            $val = $body['session_absolute_lifetime_hours'];
            if (!is_int($val) && (!is_string($val) || !ctype_digit($val))) {
                $errors['session_absolute_lifetime_hours'][] = 'session_absolute_lifetime_hours must be a positive integer.';
            } else {
                $intVal = (int) $val;
                if ($intVal <= 0 || $intVal > 720) {
                    $errors['session_absolute_lifetime_hours'][] = 'session_absolute_lifetime_hours must be greater than 0 (max 720 hours).';
                } else {
                    $current['session_absolute_lifetime_hours'] = $intVal;
                }
            }
        }

        if (array_key_exists('login_max_attempts', $body)) {
            $val = $body['login_max_attempts'];
            if (!is_int($val) && (!is_string($val) || !ctype_digit($val))) {
                $errors['login_max_attempts'][] = 'login_max_attempts must be an integer between 1 and 20.';
            } else {
                $intVal = (int) $val;
                if ($intVal < 1 || $intVal > 20) {
                    $errors['login_max_attempts'][] = 'login_max_attempts must be between 1 and 20.';
                } else {
                    $current['login_max_attempts'] = $intVal;
                }
            }
        }

        if (array_key_exists('login_decay_minutes', $body)) {
            $val = $body['login_decay_minutes'];
            if (!is_int($val) && (!is_string($val) || !ctype_digit($val))) {
                $errors['login_decay_minutes'][] = 'login_decay_minutes must be a positive integer.';
            } else {
                $intVal = (int) $val;
                if ($intVal <= 0 || $intVal > 1440) {
                    $errors['login_decay_minutes'][] = 'login_decay_minutes must be greater than 0.';
                } else {
                    $current['login_decay_minutes'] = $intVal;
                }
            }
        }

        if (array_key_exists('allowed_email_domains', $body)) {
            if (!is_array($body['allowed_email_domains'])) {
                $errors['allowed_email_domains'][] = 'allowed_email_domains must be an array of domain names.';
            } else {
                $current['allowed_email_domains'] = array_values(array_filter(array_map('trim', array_map('strtolower', array_map('strval', $body['allowed_email_domains'])))));
            }
        }

        if (!empty($errors)) {
            return $this->problem(422, 'Unprocessable Content', 'Validation failed for security settings.', null, $errors);
        }

        $actor = $this->getActor($request);
        $this->settings->set(self::SETTING_KEY, $current, false, $actor->id);

        return $this->json($current);
    }
}
