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

    private readonly Grammar $grammar;

    /**
     * $grammar defaults to ANSI — double-quoted identifiers, which is what
     * every call site got before Stage 10 and what pgsql and sqlite want.
     * Passing MySqlGrammar (or letting ConnectionManager::table() pass the
     * one matching the connection's driver) is the only thing that changes
     * the generated SQL. Nothing about VALUES changes: they were bound
     * parameters before this seam existed and they still are.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table,
        ?Grammar $grammar = null,
    ) {
        $this->grammar = $grammar ?? new AnsiGrammar();
        Identifier::validate($table);
    }

    public function grammar(): Grammar
    {
        return $this->grammar;
    }

    /**
     * The SELECT this builder would run, without running it. Exists so that
     * driver-specific SQL generation is testable against a driver we do not
     * have a server for — the grammar is a pure string concern, and proving
     * it should not require booting MySQL.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function toSql(): array
    {
        return $this->buildSelect();
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

    /**
     * WHERE column IS NULL — finding #46, surfaced by the first consumer
     * app (SIGAP-MP): soft-delete filtering needs IS NULL, and no bound
     * parameter can say it (`= NULL` is always false in SQL). A dedicated
     * method instead of admitting 'is' into where()'s operator list,
     * because where() promises "the value is a bound parameter" and an
     * operator whose only valid value is the non-value NULL would be the
     * one row of the table where that promise silently means nothing.
     */
    public function whereNull(string $column): self
    {
        $clone = clone $this;
        $clone->wheres[] = $this->qualifyColumn($column) . ' IS NULL';

        return $clone;
    }

    public function whereNotNull(string $column): self
    {
        $clone = clone $this;
        $clone->wheres[] = $this->qualifyColumn($column) . ' IS NOT NULL';

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
            return $this->grammar->quoteIdentifier($tbl) . '.' . $this->grammar->quoteIdentifier($col);
        }

        return $this->grammar->quoteIdentifier($ref);
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
     * letting a view/loop trigger N+1 lazy queries (design spec §7).
     *
     * @param string ...$relations
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
     * Typed aggregate without opening the raw-string door (U-S1-5):
     * `SELECT <col>, COUNT(*) ... GROUP BY <col>` with the column going
     * through the same identifier validation as everywhere else. Returns
     * value => count. Exists so a consumer counting rows per status does not
     * have to fetch every row and aggregate in PHP — or worse, reach for
     * `$pdo->query("SELECT COUNT(*) ...")` outside the audited path once the
     * data grows.
     *
     * @return array<string, int>
     */
    public function countBy(string $column): array
    {
        [$sql, $bindings] = $this->buildSelect(countOnly: true, countByColumn: $column);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $result = [];
        /** @var array{0: string|int|null, 1: int|string} $row */
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $result[(string) $row[0]] = (int) $row[1];
        }
        return $result;
    }

    /**
     * One page of results plus the metadata a client needs to render paging
     * controls, in one call:
     *
     *   ['data' => list<row>,
     *    'meta' => ['page' => int, 'per_page' => int, 'total' => int,
     *               'last_page' => int, 'from' => int|null, 'to' => int|null]]
     *
     * Two queries — a COUNT and the page SELECT — sharing the same wheres
     * and joins, so they cannot disagree about which rows are being paged.
     * `total` is the count BEFORE limit/offset; any limit()/offset() already
     * on the builder is overridden by the page arithmetic, because a paginated
     * query has exactly one correct limit and it is per_page.
     *
     * `page` is clamped to >= 1 rather than throwing: the typical source is a
     * `?page=` query param, and `?page=0` or `?page=-3` from a hand-edited URL
     * is a request for the first page, not a 500. `per_page` throwing instead
     * of clamping is deliberate too — its source is the CODE, not the user,
     * and per_page <= 0 is a bug to surface, not input to tolerate.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int, from: int|null, to: int|null}}
     */
    public function paginate(int $page = 1, int $perPage = 15): array
    {
        if ($perPage < 1) {
            throw new QueryException("paginate() per_page must be >= 1, got $perPage.");
        }

        $page = max(1, $page);
        $total = $this->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $rows = $this->limit($perPage)->offset(($page - 1) * $perPage)->get();

        $from = $rows === [] ? null : (($page - 1) * $perPage) + 1;
        $to = $rows === [] ? null : (($page - 1) * $perPage) + count($rows);

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $from,
                'to' => $to,
            ],
        ];
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

        $columns = array_map(fn (string $c) => $this->grammar->quoteIdentifier($c), array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->grammar->quoteIdentifier($this->table),
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
            $sets[] = $this->grammar->quoteIdentifier($column) . ' = ?';
            $bindings[] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->grammar->quoteIdentifier($this->table),
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
            $this->grammar->quoteIdentifier($this->table),
            implode(' AND ', $this->wheres)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);

        return $stmt->rowCount();
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildSelect(bool $countOnly = false, ?string $countByColumn = null): array
    {
        if ($countByColumn !== null) {
            // countBy(): the column is caller input, so it takes the same
            // qualifyColumn() path (Identifier::validate + grammar quoting)
            // as every other column reference in this builder.
            $columns = $this->qualifyColumn($countByColumn) . ', COUNT(*)';
        } else {
            $columns = $countOnly ? 'COUNT(*)' : implode(', ', $this->selectColumns);
        }
        $sql = sprintf('SELECT %s FROM %s', $columns, $this->grammar->quoteIdentifier($this->table));

        foreach ($this->joins as $join) {
            $sql .= sprintf(' %s JOIN %s ON %s', $join['type'], $this->grammar->quoteIdentifier($join['table']), $join['on']);
        }

        $allBindings = $this->bindings;

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        if ($countByColumn !== null) {
            $sql .= ' GROUP BY ' . $this->qualifyColumn($countByColumn);
        } elseif (!$countOnly && $this->groupByColumn !== null) {
            $sql .= ' GROUP BY ' . $this->grammar->quoteIdentifier($this->groupByColumn);
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
