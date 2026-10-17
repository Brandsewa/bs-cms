<?php

declare(strict_types=1);

namespace BsCms\Database;

/**
 * Raw SQL expression wrapper.
 */
final class Expression
{
    private string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
