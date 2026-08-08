<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

/**
 * Backtick-quoted identifiers. MySQL/MariaDB accept double quotes only
 * under ANSI_QUOTES, which is off by default and which we will not assume
 * on someone else's server.
 *
 * Identifier::validate() runs first and rejects anything containing a
 * backtick long before we get here — the pattern is [a-zA-Z_][a-zA-Z0-9_]*
 * — so there is no "escape the quote char by doubling it" logic in this
 * class, and there should never be one. If a name needs escaping, it is
 * not a name we accept.
 */
final class MySqlGrammar implements Grammar
{
    public function name(): string
    {
        return 'mysql';
    }

    public function quoteIdentifier(string $name): string
    {
        return '`' . Identifier::validate($name) . '`';
    }
}
