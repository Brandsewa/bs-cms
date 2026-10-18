<?php

declare(strict_types=1);

namespace BsCms\Schema;

final class SchemaPlan
{
    /**
     * @param list<array<string, mixed>> $changes
     * @param list<string> $sqlStatements
     * @param array{data_loss: bool, long_running: bool, locks_table: bool} $riskFlags
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $collectionHandle,
        public readonly array $changes,
        public readonly array $sqlStatements,
        public readonly array $riskFlags,
        public readonly int $estimatedRows = 0
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'collection_handle' => $this->collectionHandle,
            'changes' => $this->changes,
            'sql_statements' => $this->sqlStatements,
            'risk_flags' => $this->riskFlags,
            'estimated_rows' => $this->estimatedRows,
        ];
    }
}
