<?php

declare(strict_types=1);

namespace BsCms\Schema;

use BsCms\Schema\FieldTypes\BlocksType;
use BsCms\Schema\FieldTypes\BooleanType;
use BsCms\Schema\FieldTypes\ColorType;
use BsCms\Schema\FieldTypes\ComputedType;
use BsCms\Schema\FieldTypes\DateType;
use BsCms\Schema\FieldTypes\DatetimeType;
use BsCms\Schema\FieldTypes\EmailType;
use BsCms\Schema\FieldTypes\EnumType;
use BsCms\Schema\FieldTypes\FaqType;
use BsCms\Schema\FieldTypes\FieldTypeInterface;
use BsCms\Schema\FieldTypes\GroupType;
use BsCms\Schema\FieldTypes\JsonType;
use BsCms\Schema\FieldTypes\MarkdownType;
use BsCms\Schema\FieldTypes\MediaType;
use BsCms\Schema\FieldTypes\MultiEnumType;
use BsCms\Schema\FieldTypes\NumberType;
use BsCms\Schema\FieldTypes\PhoneType;
use BsCms\Schema\FieldTypes\RelationType;
use BsCms\Schema\FieldTypes\RepeaterType;
use BsCms\Schema\FieldTypes\RichtextType;
use BsCms\Schema\FieldTypes\SeoType;
use BsCms\Schema\FieldTypes\SlugType;
use BsCms\Schema\FieldTypes\TaxonomyType;
use BsCms\Schema\FieldTypes\TextareaType;
use BsCms\Schema\FieldTypes\TextType;
use BsCms\Schema\FieldTypes\TimeType;
use BsCms\Schema\FieldTypes\UrlType;
use BsCms\Schema\FieldTypes\UserType;
use InvalidArgumentException;

final class FieldTypeRegistry
{
    /** @var array<string, FieldTypeInterface> */
    private array $types = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    private function registerDefaults(): void
    {
        $defaults = [
            new TextType(),
            new TextareaType(),
            new MarkdownType(),
            new NumberType(),
            new BooleanType(),
            new DateType(),
            new DatetimeType(),
            new TimeType(),
            new EnumType(),
            new MultiEnumType(),
            new SlugType(),
            new EmailType(),
            new UrlType(),
            new PhoneType(),
            new ColorType(),
            new JsonType(),
            new UserType(),
            new RelationType(),
            new RepeaterType(),
            new GroupType(),
            new FaqType(),
            new SeoType(),
            new ComputedType(),
            // Guarded Phase 4 types (refuse creation with HTTP 422)
            new RichtextType(),
            new MediaType(),
            new TaxonomyType(),
            new BlocksType(),
        ];

        foreach ($defaults as $type) {
            $this->register($type);
        }
    }

    public function register(FieldTypeInterface $type): void
    {
        $this->types[$type->name()] = $type;
    }

    public function get(string $name): FieldTypeInterface
    {
        if (!isset($this->types[$name])) {
            throw new InvalidArgumentException(sprintf("Unknown field type '%s'", $name));
        }
        return $this->types[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    /**
     * @return array<string, FieldTypeInterface>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * Returns list of field type descriptors for API / builder UI.
     *
     * @return list<array{type: string, label: string, is_sortable: bool, filter_operators: list<string>}>
     */
    public function describeAll(): array
    {
        $desc = [];
        foreach ($this->types as $type) {
            $desc[] = [
                'type' => $type->name(),
                'label' => $type->label(),
                'is_sortable' => $type->isSortable(),
                'filter_operators' => $type->filterOperators(),
            ];
        }
        return $desc;
    }
}
