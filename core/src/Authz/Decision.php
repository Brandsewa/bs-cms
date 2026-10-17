<?php

declare(strict_types=1);

namespace BsCms\Authz;

/**
 * Authorization decision with boolean outcome and explanatory reason.
 */
final class Decision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason
    ) {
    }

    public static function allow(string $reason = 'Allowed by policy.'): self
    {
        return new self(true, $reason);
    }

    public static function deny(string $reason = 'Denied by policy.'): self
    {
        return new self(false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }
}
