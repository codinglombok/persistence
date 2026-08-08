<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

use LombokClarion\Persistence\Exceptions\QueryException;

/**
 * Maps a PDO driver name to its grammar. An unknown driver is an error,
 * not a silent fall back to ANSI: generating double-quoted SQL for a
 * driver we have never seen would fail at the server with a syntax error
 * far from its cause. Failing here names the actual problem.
 */
final class GrammarFactory
{
    public static function forDriver(string $driver): Grammar
    {
        return match (strtolower($driver)) {
            'sqlite', 'pgsql', 'postgres', 'postgresql' => new AnsiGrammar(strtolower($driver)),
            'mysql', 'mariadb' => new MySqlGrammar(),
            default => throw new QueryException(sprintf(
                'No grammar registered for driver "%s". Known drivers: sqlite, pgsql, mysql, mariadb. ' .
                'Pass an explicit Grammar to QueryBuilder if you are adding a driver.',
                $driver
            )),
        };
    }
}
