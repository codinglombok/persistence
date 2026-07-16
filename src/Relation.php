<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

/**
 * Declares how a "parent" table relates to a "child" table, used by
 * EagerLoader to batch-load related rows in one query instead of N+1
 * lazy queries inside a view loop (master prompt §7).
 *
 * Relation definitions are registered explicitly in the repository
 * (not discovered via annotations or conventions), consistent with
 * "explicit over magic".
 */
final class Relation
{
    /**
     * @param 'hasMany'|'hasOne'|'belongsTo' $type
     * @param string $relatedTable  the table to query
     * @param string $foreignKey    the FK column on the "many" side
     * @param string $localKey      the PK/ref column on the "one" side
     * @param string $as            the key under which results are attached
     */
    public function __construct(
        public readonly string $type,
        public readonly string $relatedTable,
        public readonly string $foreignKey,
        public readonly string $localKey,
        public readonly string $as,
    ) {
        Identifier::validate($relatedTable);
        Identifier::validate($foreignKey);
        Identifier::validate($localKey);
    }

    public static function hasMany(string $table, string $foreignKey, string $localKey = 'id', ?string $as = null): self
    {
        return new self('hasMany', $table, $foreignKey, $localKey, $as ?? $table);
    }

    public static function hasOne(string $table, string $foreignKey, string $localKey = 'id', ?string $as = null): self
    {
        return new self('hasOne', $table, $foreignKey, $localKey, $as ?? $table);
    }

    public static function belongsTo(string $table, string $foreignKey, string $localKey = 'id', ?string $as = null): self
    {
        return new self('belongsTo', $table, $foreignKey, $localKey, $as ?? $table);
    }
}
