<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

use LombokClarion\Persistence\Exceptions\QueryException;

/**
 * The DDL sibling of View\Safe::mark() and the QueryBuilder's RawExpression:
 * marks a DDL statement as reviewed, so NoRawSqlValuesRule stops flagging the
 * `PDO::exec()` site.
 *
 * Why this exists: identifiers and column-type fragments can never be bound
 * parameters, so DDL is the one place where SQL genuinely must be assembled by
 * string building. §7's rule ("every value must be a bound parameter") is about
 * VALUES — it was never meant to outlaw `CREATE TABLE "widgets" (...)`.
 *
 * Two deliberate differences from Safe::mark():
 *
 *  1. It returns `string`, not a wrapper object. Safe can return an object
 *     because its value flows into the view engine, which is ours and can type
 *     check it. DDL flows into PDO::exec(string), and under `strict_types=1`
 *     PHP rejects a Stringable there — so a wrapper object is not an option.
 *
 *  2. Because it returns a bare string it could otherwise become a universal
 *     "shut the analyser up" bypass, it is narrowed at RUNTIME: anything that
 *     is not a DDL statement is rejected outright. You cannot launder a
 *     `SELECT ... WHERE id = $id` through it. This is a marker with teeth, not
 *     a filter: it does not sanitize the DDL, it only guarantees the hatch
 *     cannot be pointed at the value-injection surface it was never meant for.
 */
final class TrustedDdl
{
    /**
     * Statements that cannot take bound values in any supported driver.
     */
    private const DDL_VERBS = [
        'CREATE',
        'ALTER',
        'DROP',
        'TRUNCATE',
        'RENAME',
        'COMMENT',
    ];

    private function __construct()
    {
    }

    /**
     * Assert that $sql is trusted DDL and hand it back unchanged.
     *
     * @throws QueryException if $sql is not a DDL statement
     */
    public static function mark(string $sql): string
    {
        $verb = strtoupper((string) strtok(ltrim($sql), " \t\r\n("));

        if (!in_array($verb, self::DDL_VERBS, true)) {
            throw new QueryException(sprintf(
                'TrustedDdl::mark() accepts DDL only (%s), got "%s...". This hatch exists so '
                . 'schema statements — which cannot use bound parameters for identifiers — pass '
                . 'static analysis. It is deliberately not usable for queries carrying values: '
                . 'use a bound parameter, QueryBuilder, or RawExpression for those.',
                implode('/', self::DDL_VERBS),
                substr(ltrim($sql), 0, 40)
            ));
        }

        return $sql;
    }
}
