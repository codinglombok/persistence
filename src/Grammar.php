<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

/**
 * The one thing SQL dialects disagree about that the QueryBuilder cannot
 * paper over: how an identifier is quoted. ANSI (and therefore pgsql and
 * sqlite) uses double quotes; MySQL uses backticks unless ANSI_QUOTES is
 * set, which nobody sets.
 *
 * This is deliberately the SMALLEST possible seam. It is not a general
 * "dialect" abstraction with a method per clause — that is the road to a
 * second, worse query language. If a future driver difference genuinely
 * cannot be expressed in the shared subset, that difference belongs in a
 * RawExpression written by the application, not in a growing interface
 * here.
 *
 * Implementations MUST still route the name through Identifier::validate().
 * Quoting is presentation; validation is the security property, and one
 * does not substitute for the other. A grammar that quoted without
 * validating would make `->where($column, ...)` injectable again the
 * moment someone shipped a driver where the quote char can be escaped.
 */
interface Grammar
{
    /** Driver name this grammar speaks, e.g. 'mysql' — for diagnostics. */
    public function name(): string;

    /** Validate then quote a single identifier (table or column, unqualified). */
    public function quoteIdentifier(string $name): string;
}
