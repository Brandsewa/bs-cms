<?php

declare(strict_types=1);

namespace BsCms\Schema\FieldTypes;

final class DatetimeType extends BaseFieldType
{
    public function name(): string
    {
        return 'datetime';
    }

    public function label(): string
    {
        return 'Date & Time (UTC)';
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function jsonSchema(array $config): array
    {
        return [
            'type' => 'string',
            'format' => 'date-time',
        ];
    }

    public function normalize(mixed $value, array $config): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            return null;
        }
        $str = trim((string) $value);
        try {
            $dt = new \DateTimeImmutable($str);
            $dtUtc = $dt->setTimezone(new \DateTimeZone('UTC'));
            return $dtUtc->format('Y-m-d H:i:s.v');
        } catch (\Throwable) {
            return $str;
        }
    }

    public function validate(mixed $value, array $config, ValidationContext $context): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value)) {
            return ['Must be a valid ISO-8601 or datetime string'];
        }
        try {
            new \DateTimeImmutable($value);
            return [];
        } catch (\Throwable) {
            return ['Must be a valid ISO-8601 or datetime string'];
        }
    }

    public function storage(array $config): StorageDescriptor
    {
        return StorageDescriptor::column('DATETIME(3)');
    }

    public function toApi(mixed $value, array $config, PopulateContext $context): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
            return $dt->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return $value;
        }
    }

    public function filterOperators(): array
    {
        return ['eq', 'ne', 'in', 'nin', 'lt', 'lte', 'gt', 'gte', 'between', 'null'];
    }

    public function isSortable(): bool
    {
        return true;
    }
}
