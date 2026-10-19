<?php

declare(strict_types=1);

namespace BsCms\Database;

use InvalidArgumentException;

/**
 * Fluent QueryBuilder for BS CMS.
 *
 * Enforces:
 * - Table name prefixing (`bs_users`).
 * - Identifier validation against `^[a-z_][a-z0-9_]*$` with backtick quotation.
 * - Strict parameter binding.
 * - Expanded keyset pagination predicate: `(published_at < ? OR (published_at = ? AND id < ?))`.
 * - Selective joins, limit, offset, and `forUpdate()`.
 */
class QueryBuilder
{
    private Connection $connection;
    private string $table = '';
    /** @var list<string> */
    private array $columns = ['*'];
    /** @var list<array{type: string, sql: string, params: list<mixed>}> */
    private array $wheres = [];
    /** @var list<array{table: string, first: string, operator: string, second: string, type: string}> */
    private array $joins = [];
    /** @var list<array{column: string, direction: string}> */
    private array $orders = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private bool $forUpdate = false;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function from(string $table): self
    {
        $this->table = $this->connection->prefixTable($table);
        $this->validateIdentifier($this->table);
        return $this;
    }

    /**
     * @param list<string> $columns
     */
    public function select(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        // 2 arguments: where('status', 'active') => where('status', '=', 'active')
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->validateIdentifier($column);
        $validOps = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];
        $op = strtoupper((string) $operator);
        if (!in_array($op, $validOps, true)) {
            throw new InvalidArgumentException("Invalid SQL operator: '{$operator}'");
        }

        $this->wheres[] = [
            'type' => 'AND',
            'sql' => "`{$column}` {$op} ?",
            'params' => [$value],
        ];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->validateIdentifier($column);
        $this->wheres[] = [
            'type' => 'AND',
            'sql' => "`{$column}` IS NULL",
            'params' => [],
        ];
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->validateIdentifier($column);
        $this->wheres[] = [
            'type' => 'AND',
            'sql' => "`{$column}` IS NOT NULL",
            'params' => [],
        ];
        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        $this->validateIdentifier($column);
        if (empty($values)) {
            $this->wheres[] = [
                'type' => 'AND',
                'sql' => '0 = 1',
                'params' => [],
            ];
            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = [
            'type' => 'AND',
            'sql' => "`{$column}` IN ({$placeholders})",
            'params' => $values,
        ];

        return $this;
    }

    /**
     * Expanded keyset pagination predicate for tie-breaking cursor query:
     * `(order_col < ? OR (order_col = ? AND id < ?))`
     */
    public function whereKeyset(string $orderCol, mixed $orderVal, string $idCol, mixed $idVal, string $direction = 'DESC'): self
    {
        $this->validateIdentifier($orderCol);
        $this->validateIdentifier($idCol);

        $dir = strtoupper($direction) === 'ASC' ? '>' : '<';

        $sql = "(`{$orderCol}` {$dir} ? OR (`{$orderCol}` = ? AND `{$idCol}` {$dir} ?))";

        $this->wheres[] = [
            'type' => 'AND',
            'sql' => $sql,
            'params' => [$orderVal, $orderVal, $idVal],
        ];

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $prefixed = $this->connection->prefixTable($table);
        $this->validateIdentifier($prefixed);
        $this->validateIdentifier($first);
        $this->validateIdentifier($second);

        $this->joins[] = [
            'table' => $prefixed,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'type' => strtoupper($type),
        ];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->validateIdentifier($column);
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = ['column' => $column, 'direction' => $dir];
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(1, $limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    public function forUpdate(): self
    {
        $this->forUpdate = true;
        return $this;
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    public function toSql(): array
    {
        $cols = empty($this->columns) || $this->columns === ['*']
            ? '*'
            : implode(', ', array_map(function ($c) {
                if ($c === '*') {
                    return '*';
                }
                if (str_starts_with(strtoupper($c), 'COUNT(')) {
                    return $c;
                }
                $this->validateIdentifier($c);
                return "`{$c}`";
            }, $this->columns));

        $sql = "SELECT {$cols} FROM `{$this->table}`";
        $params = [];

        foreach ($this->joins as $j) {
            $sql .= " {$j['type']} JOIN `{$j['table']}` ON `{$j['first']}` {$j['operator']} `{$j['second']}`";
        }

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ';
            $whereParts = [];
            foreach ($this->wheres as $i => $w) {
                $prefix = $i === 0 ? '' : " {$w['type']} ";
                $whereParts[] = $prefix . $w['sql'];
                foreach ($w['params'] as $p) {
                    $params[] = $p;
                }
            }
            $sql .= implode('', $whereParts);
        }

        if (!empty($this->orders)) {
            $orderParts = [];
            foreach ($this->orders as $o) {
                $orderParts[] = "`{$o['column']}` {$o['direction']}";
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        if ($this->forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Execute SELECT and return all matching rows.
     *
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        $compiled = $this->toSql();
        $stmt = $this->connection->execute($compiled['sql'], $compiled['params']);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Return the first matching row or null.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $this->limit(1);
        $rows = $this->get();
        return $rows[0] ?? null;
    }

    /**
     * Count matching rows.
     */
    public function count(): int
    {
        $origCols = $this->columns;
        $this->columns = ['COUNT(*) as total'];
        $compiled = $this->toSql();
        $this->columns = $origCols;

        // Replace backtick quote on COUNT(*) as total
        $sql = preg_replace('/SELECT `COUNT\(\*\) as total`/', 'SELECT COUNT(*) as total', $compiled['sql']) ?? $compiled['sql'];

        $stmt = $this->connection->execute($sql, $compiled['params']);
        /** @var array{total: int|string}|false $row */
        $row = $stmt->fetch();
        return (int) ($row['total'] ?? 0);
    }

    /**
     * Insert a row.
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $cols = array_keys($data);
        foreach ($cols as $col) {
            $this->validateIdentifier($col);
        }

        $quotedCols = implode(', ', array_map(fn ($c) => "`{$c}`", $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));

        $sql = "INSERT INTO `{$this->table}` ({$quotedCols}) VALUES ({$placeholders})";
        $params = array_values($data);

        $this->connection->execute($sql, $params);
        return true;
    }

    /**
     * Update matching rows.
     *
     * @param array<string, mixed> $data
     */
    public function update(array $data): int
    {
        if (empty($data)) {
            return 0;
        }

        $setParts = [];
        $params = [];

        foreach ($data as $col => $val) {
            $this->validateIdentifier($col);
            $setParts[] = "`{$col}` = ?";
            $params[] = $val;
        }

        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $setParts);

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ';
            $whereParts = [];
            foreach ($this->wheres as $i => $w) {
                $prefix = $i === 0 ? '' : " {$w['type']} ";
                $whereParts[] = $prefix . $w['sql'];
                foreach ($w['params'] as $p) {
                    $params[] = $p;
                }
            }
            $sql .= implode('', $whereParts);
        }

        $stmt = $this->connection->execute($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Delete matching rows.
     */
    public function delete(): int
    {
        $sql = "DELETE FROM `{$this->table}`";
        $params = [];

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ';
            $whereParts = [];
            foreach ($this->wheres as $i => $w) {
                $prefix = $i === 0 ? '' : " {$w['type']} ";
                $whereParts[] = $prefix . $w['sql'];
                foreach ($w['params'] as $p) {
                    $params[] = $p;
                }
            }
            $sql .= implode('', $whereParts);
        }

        $stmt = $this->connection->execute($sql, $params);
        return $stmt->rowCount();
    }

    private function validateIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $identifier)) {
            throw new InvalidArgumentException("Invalid SQL identifier: '{$identifier}'");
        }
    }
}
