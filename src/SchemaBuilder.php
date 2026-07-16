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
     */
    public function createTable(string $table, array $columns): void
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

        $sql = sprintf('CREATE TABLE %s (%s)', Identifier::quote($table), implode(', ', $parts));
        $this->pdo->exec($sql);
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
        $this->pdo->exec($sql);
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
