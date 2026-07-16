<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

use LombokClarion\Persistence\Exceptions\QueryException;

/**
 * The ONLY raw-SQL escape hatch in the QueryBuilder. It still requires
 * bound placeholders — there is no way to hand it a pre-concatenated
 * string containing a value. The number of `?` placeholders in $sql MUST
 * match count($bindings) or construction fails immediately (master prompt
 * §7: "it cannot accept concatenated values").
 */
final class RawExpression
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings = [],
    ) {
        $placeholderCount = substr_count($sql, '?');
        if ($placeholderCount !== count($bindings)) {
            throw new QueryException(sprintf(
                'rawExpression() placeholder mismatch: "%s" has %d placeholder(s) but %d binding(s) ' .
                'were supplied. Every value must be a bound parameter — concatenating values into ' .
                '$sql directly is not supported.',
                $sql,
                $placeholderCount,
                count($bindings)
            ));
        }
    }
}
