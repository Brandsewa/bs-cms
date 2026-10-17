<?php

declare(strict_types=1);

namespace BsCms\Authz;

/**
 * Value object representing a single role permission rule.
 */
final class Permission
{
    /**
     * @param array<string, mixed>|null $fields
     * @param array<string, mixed>|null $conditions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $roleId,
        public readonly string $subjectType,
        public readonly string $subjectKey,
        public readonly string $action,
        public readonly string $effect = 'allow',
        public readonly ?array $fields = null,
        public readonly ?array $conditions = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'role_id' => $this->roleId,
            'subject_type' => $this->subjectType,
            'subject_key' => $this->subjectKey,
            'action' => $this->action,
            'effect' => $this->effect,
            'fields' => $this->fields,
            'conditions' => $this->conditions,
        ];
    }
}
