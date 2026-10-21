<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers\SprintA;

use BsCms\Auth\Actor;
use BsCms\Auth\SystemRoles;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Http\Controllers\BaseController;
use BsCms\Settings\SettingsRepository;
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
        private readonly Authorizer $authorizer
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

        if (isset($body['password_min_length'])) {
            $current['password_min_length'] = max(8, min(64, (int) $body['password_min_length']));
        }
        if (isset($body['password_require_uppercase'])) {
            $current['password_require_uppercase'] = (bool) $body['password_require_uppercase'];
        }
        if (isset($body['password_require_lowercase'])) {
            $current['password_require_lowercase'] = (bool) $body['password_require_lowercase'];
        }
        if (isset($body['password_require_numbers'])) {
            $current['password_require_numbers'] = (bool) $body['password_require_numbers'];
        }
        if (isset($body['password_require_symbols'])) {
            $current['password_require_symbols'] = (bool) $body['password_require_symbols'];
        }
        if (isset($body['two_factor_policy']) && in_array($body['two_factor_policy'], ['optional', 'mandatory_all', 'mandatory_roles'], true)) {
            $current['two_factor_policy'] = $body['two_factor_policy'];
        }
        if (isset($body['two_factor_enforced_roles']) && is_array($body['two_factor_enforced_roles'])) {
            $current['two_factor_enforced_roles'] = array_values(array_filter(array_map('strval', $body['two_factor_enforced_roles'])));
        }
        if (isset($body['session_idle_timeout_minutes'])) {
            $current['session_idle_timeout_minutes'] = max(5, min(1440, (int) $body['session_idle_timeout_minutes']));
        }
        if (isset($body['session_absolute_lifetime_hours'])) {
            $current['session_absolute_lifetime_hours'] = max(1, min(720, (int) $body['session_absolute_lifetime_hours']));
        }
        if (isset($body['login_max_attempts'])) {
            $current['login_max_attempts'] = max(3, min(20, (int) $body['login_max_attempts']));
        }
        if (isset($body['login_decay_minutes'])) {
            $current['login_decay_minutes'] = max(1, min(120, (int) $body['login_decay_minutes']));
        }
        if (isset($body['allowed_email_domains']) && is_array($body['allowed_email_domains'])) {
            $current['allowed_email_domains'] = array_values(array_filter(array_map('trim', array_map('strtolower', array_map('strval', $body['allowed_email_domains'])))));
        }

        $actor = $this->getActor($request);
        $this->settings->set(self::SETTING_KEY, $current, false, $actor->id);

        return $this->json($current);
    }
}
