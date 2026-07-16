<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

use LombokClarion\Persistence\Exceptions\QueryException;

/**
 * Table/column names can never be bound parameters in SQL, so instead of
 * accepting arbitrary strings we validate them against a strict identifier
 * pattern before splicing them into generated SQL. This is what makes
 * `->where($column, ...)` safe even though $column itself isn't a bound
 * value: anything that isn't a plain identifier is rejected outright.
 */
final class Identifier
{
    private const PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    public static function validate(string $name): string
    {
        if (!preg_match(self::PATTERN, $name)) {
            throw new QueryException(sprintf(
                'Invalid identifier "%s". Table and column names must match %s — this restriction ' .
                'exists because identifiers cannot be passed as bound parameters.',
                $name,
                self::PATTERN
            ));
        }

        return $name;
    }

    public static function quote(string $name): string
    {
        return '"' . self::validate($name) . '"';
    }
}
