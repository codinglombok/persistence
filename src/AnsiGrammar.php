<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

/**
 * Double-quoted identifiers: the SQL standard, and what pgsql and sqlite
 * both do out of the box. This is the default grammar, which is why
 * Identifier::quote() — the pre-Stage-10 behaviour — produces exactly the
 * same string. Nothing that existed before this class changes shape.
 */
final class AnsiGrammar implements Grammar
{
    public function __construct(private readonly string $name = 'ansi')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function quoteIdentifier(string $name): string
    {
        return '"' . Identifier::validate($name) . '"';
    }
}
