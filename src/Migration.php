<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

interface Migration
{
    public function up(SchemaBuilder $schema): void;

    public function down(SchemaBuilder $schema): void;

    /**
     * Override to return false only for DDL that genuinely supports
     * transactions on the active driver. Defaults to
     * SchemaBuilder::migrationsAreTransactionalByDefault() (false on MySQL,
     * true elsewhere) per master prompt §7.
     */
    public function runsInTransaction(SchemaBuilder $schema): bool;
}
