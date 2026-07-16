<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

use LombokClarion\Persistence\Exceptions\QueryException;
use PDO;

/**
 * There is no method on this class that accepts raw interpolated SQL for a
 * VALUE. Every value — in where(), insert(), update() — becomes a bound
 * PDO parameter. Table/column names go through Identifier::validate()
 * since they cannot be bound. The only raw-SQL surface is rawExpression(),
 * which still requires bound placeholders (see RawExpression).
 *
 * update()/delete() require at least one where() clause: an accidental
 * full-table mutation is a bug class this builder makes structurally
 * harder to hit, not just something the docs warn against.
 */
final class QueryBuilder
{
    private array $wheres = [];
    private array $bindings = [];
    private array $selectColumns = ['*'];
    /** @var list<array{type: string, table: string, on: string, onBindings: list<mixed>}> */
    private array $joins = [];
    private ?string $orderColumn = null;
    private string $orderDirection = 'ASC';
    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private ?string $groupByColumn = null;

    /** @var list<string> relations requested for eager loading (see Repository::with()) */
    private array $eagerLoad = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table,
    ) {
        Identifier::validate($table);
    }

    public function select(string ...$columns): self
    {
        $clone = clone $this;
        $clone->selectColumns = array_map(
            fn (string $c) => $c === '*' ? '*' : $this->qualifyColumn($c),
            $columns ?: ['*']
        );
        return $clone;
    }

    /**
     * @param '='|'!='|'<'|'>'|'<='|'>='|'like'|'in'|'not in' $operator
     */
    public function where(string $column, string $operator, mixed $value): self
    {
        $operator = strtolower($operator);
        $allowed = ['=', '!=', '<', '>', '<=', '>=', 'like', 'in', 'not in'];
        if (!in_array($operator, $allowed, true)) {
            throw new QueryException("Unsupported operator \"$operator\". Allowed: " . implode(', ', $allowed));
        }

        $clone = clone $this;

        if (in_array($operator, ['in', 'not in'], true)) {
            if (!is_array($value) || $value === []) {
                throw new QueryException("Operator \"$operator\" requires a non-empty array value.");
            }
            $placeholders = implode(', ', array_fill(0, count($value), '?'));
            $sqlOp = strtoupper($operator);
            $clone->wheres[] = $this->qualifyColumn($column) . " $sqlOp ($placeholders)";
            $clone->bindings = [...$this->bindings, ...array_values($value)];
            return $clone;
        }

        $clone->wheres[] = $this->qualifyColumn($column) . ' ' . ($operator === '!=' ? '!=' : strtoupper($operator)) . ' ?';
        $clone->bindings = [...$this->bindings, $value];
        return $clone;
    }

    public function whereRaw(RawExpression $expr): self
    {
        $clone = clone $this;
        $clone->wheres[] = $expr->sql;
        $clone->bindings = [...$this->bindings, ...$expr->bindings];
        return $clone;
    }

    /**
     * @param string $onLeft  "table.column" or just "column"
     * @param string $onRight "table.column" or just "column"
     */
    public function join(string $table, string $onLeft, string $operator, string $onRight): self
    {
        return $this->addJoin('INNER', $table, $onLeft, $operator, $onRight);
    }

    public function leftJoin(string $table, string $onLeft, string $operator, string $onRight): self
    {
        return $this->addJoin('LEFT', $table, $onLeft, $operator, $onRight);
    }

    private function addJoin(string $type, string $table, string $onLeft, string $operator, string $onRight): self
    {
        Identifier::validate($table);
        $allowed = ['=', '!=', '<', '>', '<=', '>='];
        if (!in_array($operator, $allowed, true)) {
            throw new QueryException("Unsupported join operator \"$operator\".");
        }

        $clone = clone $this;
        $clone->joins[] = [
            'type' => $type,
            'table' => $table,
            'on' => $this->qualifyColumn($onLeft) . " $operator " . $this->qualifyColumn($onRight),
            'onBindings' => [],
        ];
        return $clone;
    }

    /**
     * Validates and quotes a possibly-qualified "table.column" or bare "column".
     */
    private function qualifyColumn(string $ref): string
    {
        if (str_contains($ref, '.')) {
            [$tbl, $col] = explode('.', $ref, 2);
            return Identifier::quote(Identifier::validate($tbl)) . '.' . Identifier::quote(Identifier::validate($col));
        }

        return Identifier::quote(Identifier::validate($ref));
    }

    public function groupBy(string $column): self
    {
        Identifier::validate($column);
        $clone = clone $this;
        $clone->groupByColumn = $column;
        return $clone;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new QueryException("Invalid order direction \"$direction\".");
        }
        $clone = clone $this;
        $clone->orderColumn = $column;
        $clone->orderDirection = $direction;
        return $clone;
    }

    public function limit(int $n): self
    {
        $clone = clone $this;
        $clone->limitValue = $n;
        return $clone;
    }

    public function offset(int $n): self
    {
        $clone = clone $this;
        $clone->offsetValue = $n;
        return $clone;
    }

    /**
     * Explicit eager-loading declaration. Repository implementations read
     * this list and issue one extra bound query per relation instead of
     * letting a view/loop trigger N+1 lazy queries (master prompt §7).
     *
     * @param list<string> $relations
     */
    public function with(string ...$relations): self
    {
        $clone = clone $this;
        $clone->eagerLoad = [...$this->eagerLoad, ...$relations];
        return $clone;
    }

    /** @return list<string> */
    public function requestedRelations(): array
    {
        return $this->eagerLoad;
    }

    /** @return list<array<string, mixed>> */
    public function get(): array
    {
        [$sql, $bindings] = $this->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        $rows = $this->limit(1)->get();
        return $rows[0] ?? null;
    }

    public function count(): int
    {
        [$sql, $bindings] = $this->buildSelect(countOnly: true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     * @return string last insert id
     */
    public function insert(array $data): string
    {
        if ($data === []) {
            throw new QueryException('insert() requires at least one column.');
        }

        $columns = array_map(fn (string $c) => Identifier::quote(Identifier::validate($c)), array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            Identifier::quote($this->table),
            implode(', ', $columns),
            $placeholders
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));

        return $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(array $data): int
    {
        if ($data === []) {
            throw new QueryException('update() requires at least one column.');
        }
        if ($this->wheres === []) {
            throw new QueryException(
                'update() requires at least one where() clause. Use whereRaw(new RawExpression("1 = ?", [1])) ' .
                'if a full-table update is genuinely intended — this is an explicit, greppable escape hatch.'
            );
        }

        $sets = [];
        $bindings = [];
        foreach ($data as $column => $value) {
            $sets[] = Identifier::quote(Identifier::validate($column)) . ' = ?';
            $bindings[] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            Identifier::quote($this->table),
            implode(', ', $sets),
            implode(' AND ', $this->wheres)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...$bindings, ...$this->bindings]);

        return $stmt->rowCount();
    }

    public function delete(): int
    {
        if ($this->wheres === []) {
            throw new QueryException(
                'delete() requires at least one where() clause. Use whereRaw(new RawExpression("1 = ?", [1])) ' .
                'if a full-table delete is genuinely intended.'
            );
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            Identifier::quote($this->table),
            implode(' AND ', $this->wheres)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);

        return $stmt->rowCount();
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildSelect(bool $countOnly = false): array
    {
        $columns = $countOnly ? 'COUNT(*)' : implode(', ', $this->selectColumns);
        $sql = sprintf('SELECT %s FROM %s', $columns, Identifier::quote($this->table));

        foreach ($this->joins as $join) {
            $sql .= sprintf(' %s JOIN %s ON %s', $join['type'], Identifier::quote($join['table']), $join['on']);
        }

        $allBindings = $this->bindings;

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        if (!$countOnly && $this->groupByColumn !== null) {
            $sql .= ' GROUP BY ' . Identifier::quote($this->groupByColumn);
        }

        if (!$countOnly && $this->orderColumn !== null) {
            $sql .= ' ORDER BY ' . $this->qualifyColumn($this->orderColumn) . ' ' . $this->orderDirection;
        }

        if (!$countOnly && $this->limitValue !== null) {
            $sql .= ' LIMIT ' . $this->limitValue;
        }

        if (!$countOnly && $this->offsetValue !== null) {
            $sql .= ' OFFSET ' . $this->offsetValue;
        }

        return [$sql, $allBindings];
    }
}
