<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

/**
 * What a {@see Seeder} is handed: a table seam already bound to the connection
 * this run is about, plus the run's {@see Factory}.
 *
 * The connection is resolved ONCE, here, rather than passed to every table()
 * call — a seeder that took a connection name per call could write half its
 * rows to the wrong database and still look correct at the call site. This is
 * the same narrowing SchemaBuilder does for migrations.
 *
 * table() goes through ConnectionManager::table(), so the QueryBuilder arrives
 * with the grammar matching that connection's driver already paired to it.
 */
final class SeedContext
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Factory $factory,
        private readonly ?string $connection = null,
    ) {
    }

    public function table(string $table): QueryBuilder
    {
        return $this->connections->table($table, $this->connection);
    }

    public function factory(): Factory
    {
        return $this->factory;
    }

    /**
     * The resolved name, never null — so a seeder that logs where it wrote
     * logs something a reader can act on.
     */
    public function connectionName(): string
    {
        return $this->connection ?? $this->connections->defaultName();
    }
}
