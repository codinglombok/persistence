<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

/**
 * A named, ordered unit of data insertion — the DML counterpart to
 * {@see Migration}, run from an explicit manifest and recorded once applied.
 *
 * WHY THERE IS NO runsInTransaction() HERE, unlike Migration. A migration
 * changes SCHEMA, and DDL is not transactional on every driver (MySQL commits
 * implicitly), so a migration has to be asked. A seeder only issues DML, which
 * every supported driver rolls back, so SeederRunner always wraps a seeder and
 * its `seeders` row in ONE transaction. That is not a convenience: it makes
 * "recorded as seeded" and "actually seeded" impossible to disagree, which is
 * the failure mode a half-applied reference-data seed would otherwise leave
 * behind — and unlike a migration, nothing about that state is visible in the
 * schema afterwards.
 *
 * Seeders receive a {@see SeedContext}, not a PDO, for the same reason
 * migrations receive a SchemaBuilder: the context has already resolved which
 * connection this run is about, so a seeder cannot write to a different one by
 * forgetting an argument.
 */
interface Seeder
{
    public function seed(SeedContext $context): void;
}
