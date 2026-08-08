<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

use LombokClarion\Persistence\Exceptions\QueryException;
use PDO;

/**
 * DDL statements never take user-supplied values, only identifiers we
 * already validate — there's no value-injection surface here, but column
 * definitions still go through Identifier::validate() for defence in
 * depth (a migration file is trusted code, but a typo shouldn't produce
 * silently-broken SQL either).
 */
final class SchemaBuilder
{
    public function __construct(private readonly PDO $pdo, private readonly string $driver)
    {
    }

    /**
     * @param array<string, string> $columns column name => raw column-type SQL fragment (e.g. 'BIGINT PRIMARY KEY')
     * @param list<string> $constraints table-level constraint fragments, e.g. 'PRIMARY KEY (user_id, role_id)'
     *
     * $constraints exists because some facts are about the table rather than any
     * one column — a composite primary key being the common one. They are raw
     * fragments for the same reason $definition is: they are written in migration
     * code, which is trusted, and there is no vocabulary of every constraint every
     * driver supports that would not immediately become a cage. Column NAMES are
     * still validated; nothing here relaxes that.
     */
    public function createTable(string $table, array $columns, array $constraints = []): void
    {
        Identifier::validate($table);
        if ($columns === []) {
            throw new QueryException('createTable() requires at least one column.');
        }

        $parts = [];
        foreach ($columns as $name => $definition) {
            Identifier::validate($name);
            $parts[] = Identifier::quote($name) . ' ' . $definition;
        }

        foreach ($constraints as $constraint) {
            if (trim($constraint) === '') {
                throw new QueryException('createTable() was given an empty table constraint.');
            }
            $parts[] = $constraint;
        }

        $sql = sprintf('CREATE TABLE %s (%s)', Identifier::quote($table), implode(', ', $parts));
        $this->pdo->exec(TrustedDdl::mark($sql));
    }

    public function dropTable(string $table): void
    {
        Identifier::validate($table);
        $this->pdo->exec('DROP TABLE IF EXISTS ' . Identifier::quote($table));
    }

    public function addColumn(string $table, string $column, string $definition): void
    {
        Identifier::validate($table);
        Identifier::validate($column);
        $sql = sprintf('ALTER TABLE %s ADD COLUMN %s %s', Identifier::quote($table), Identifier::quote($column), $definition);
        $this->pdo->exec(TrustedDdl::mark($sql));
    }

    /**
     * Secondary (non-unique by default) index — the piece the Migration
     * contract was missing (U-S1-4). SQLite does not allow a non-unique index
     * inline in CREATE TABLE, so before this method existed a performance
     * index on a downstream column meant raw PDO outside the audited path.
     * Identifiers go through Identifier::validate(), the SQL through
     * TrustedDdl::mark() — the same two seams as addColumn(), symmetric with
     * it on purpose.
     *
     * The index name is derived, not caller-supplied: `<table>_<cols>_idx`,
     * every part already validated. That keeps the surface one identifier
     * class smaller and makes dropIndex() reversible without remembering a
     * name.
     *
     * @param list<string> $columns
     */
    public function createIndex(string $table, array $columns, bool $unique = false): void
    {
        Identifier::validate($table);
        if ($columns === []) {
            throw new \InvalidArgumentException('createIndex() requires at least one column');
        }
        $quoted = [];
        foreach ($columns as $column) {
            Identifier::validate($column);
            $quoted[] = Identifier::quote($column);
        }
        $name = $this->indexName($table, $columns);
        $sql = sprintf(
            'CREATE %sINDEX %s ON %s (%s)',
            $unique ? 'UNIQUE ' : '',
            Identifier::quote($name),
            Identifier::quote($table),
            implode(', ', $quoted)
        );
        $this->pdo->exec(TrustedDdl::mark($sql));
    }

    /** @param list<string> $columns */
    public function dropIndex(string $table, array $columns): void
    {
        Identifier::validate($table);
        foreach ($columns as $column) {
            Identifier::validate($column);
        }
        $name = $this->indexName($table, $columns);
        // MySQL scopes index names to the table; SQLite scopes them globally.
        $sql = $this->driver === 'mysql'
            ? sprintf('DROP INDEX %s ON %s', Identifier::quote($name), Identifier::quote($table))
            : sprintf('DROP INDEX IF EXISTS %s', Identifier::quote($name));
        $this->pdo->exec(TrustedDdl::mark($sql));
    }

    /** @param list<string> $columns */
    private function indexName(string $table, array $columns): string
    {
        $name = $table . '_' . implode('_', $columns) . '_idx';
        Identifier::validate($name);

        return $name;
    }

    /**
     * MySQL DDL is (mostly) non-transactional, so migrations default to
     * NonTransactional behaviour automatically on that driver (§7). An
     * explicit opt-out is required for the rare DDL that does support
     * transactions on MySQL.
     */
    public function migrationsAreTransactionalByDefault(): bool
    {
        return $this->driver !== 'mysql';
    }
}
