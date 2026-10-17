<?php

declare(strict_types=1);

namespace BsCms\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Clock provides a unified source of UTC time with millisecond precision across BS CMS.
 */
final class Clock
{
    private static ?DateTimeZone $utc = null;

    public static function utcZone(): DateTimeZone
    {
        return self::$utc ??= new DateTimeZone('UTC');
    }

    /**
     * Get the current time in UTC with millisecond precision.
     */
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::utcZone());
    }

    /**
     * Normalize any DateTimeInterface or valid date string to a UTC DateTimeImmutable.
     */
    public static function from(DateTimeInterface|string $datetime): DateTimeImmutable
    {
        if ($datetime instanceof DateTimeImmutable) {
            return $datetime->setTimezone(self::utcZone());
        }

        if ($datetime instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($datetime)->setTimezone(self::utcZone());
        }

        try {
            return new DateTimeImmutable($datetime, self::utcZone());
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Invalid date string: {$datetime}", 0, $e);
        }
    }

    /**
     * Format a timestamp matching docs/01-data-model.md conventions: DATETIME(3) 'Y-m-d H:i:s.v'.
     */
    public static function sql(DateTimeInterface $dt): string
    {
        return self::from($dt)->format('Y-m-d H:i:s.v');
    }

    /**
     * Format a timestamp in standard ISO 8601 (RFC 3339) with millisecond precision in UTC.
     */
    public static function iso8601(DateTimeInterface $dt): string
    {
        return self::from($dt)->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Get the current Unix timestamp in seconds.
     */
    public static function timestamp(): int
    {
        return self::now()->getTimestamp();
    }
}
