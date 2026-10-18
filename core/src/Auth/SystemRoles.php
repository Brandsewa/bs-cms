<?php

declare(strict_types=1);

namespace BsCms\Auth;

/**
 * System Role ID constants.
 * Single source of truth across application code and migrations.
 */
final class SystemRoles
{
    public const ROLE_OWNER = '01JB0000000000000000000001';
    public const ROLE_ADMIN = '01JB0000000000000000000002';
    public const ROLE_DEVELOPER = '01JB0000000000000000000003';
    public const ROLE_EDITOR = '01JB0000000000000000000004';
    public const ROLE_AUTHOR = '01JB0000000000000000000005';
    public const ROLE_CONTRIBUTOR = '01JB0000000000000000000006';
    public const ROLE_VIEWER = '01JB0000000000000000000007';
    public const ROLE_AI_DRAFT = '01JB0000000000000000000008';
    public const ROLE_AI_PUBLISHER = '01JB0000000000000000000009';
}
