<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

use PDO;

/**
 * Given a set of parent rows and a list of Relation definitions, issues
 * ONE query per relation (using a WHERE IN with bound parameters) and
 * attaches the results under the relation's `as` key on each parent row.
 *
 * This is the real implementation behind QueryBuilder::with() — the QB
 * records which relations are requested, and the repository calls
 * EagerLoader::load() after fetching the parent rows.
 *
 * Usage in a repository:
 *
 *   $rows = $this->queryBuilder()->with('comments')->get();
 *   $rows = $this->eagerLoader->load($rows, ['comments'], $this->relations());
 *
 * The N+1 scenario this prevents:
 *   foreach ($posts as $post) {
 *       $post->comments = $commentRepo->findByPostId($post->id); // BAD: 1 query per post
 *   }
 *
 * With eager loading:
 *   // 1 query: SELECT * FROM comments WHERE post_id IN (?, ?, ?)
 *   // attached automatically by EagerLoader
 */
final class EagerLoader
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<array<string, mixed>> $parentRows
     * @param list<string> $requestedRelations relation names (the `as` key)
     * @param array<string, Relation> $availableRelations keyed by name
     * @return list<array<string, mixed>> parent rows with relations attached
     */
    public function load(array $parentRows, array $requestedRelations, array $availableRelations): array
    {
        if ($parentRows === [] || $requestedRelations === []) {
            return $parentRows;
        }

        foreach ($requestedRelations as $name) {
            if (!isset($availableRelations[$name])) {
                throw new Exceptions\QueryException(
                    "Unknown relation \"$name\". Available: " . implode(', ', array_keys($availableRelations))
                );
            }

            $relation = $availableRelations[$name];
            $parentRows = $this->loadRelation($parentRows, $relation);
        }

        return $parentRows;
    }

    /**
     * @param list<array<string, mixed>> $parentRows
     * @return list<array<string, mixed>>
     */
    private function loadRelation(array $parentRows, Relation $relation): array
    {
        if ($relation->type === 'belongsTo') {
            return $this->loadBelongsTo($parentRows, $relation);
        }

        return $this->loadHasOneOrMany($parentRows, $relation);
    }

    /**
     * hasMany / hasOne: parent.localKey referenced by child.foreignKey.
     * Query: SELECT * FROM child WHERE foreignKey IN (parent localKey values)
     */
    private function loadHasOneOrMany(array $parentRows, Relation $relation): array
    {
        $parentKeys = array_unique(array_column($parentRows, $relation->localKey));
        if ($parentKeys === []) {
            return $parentRows;
        }

        $related = (new QueryBuilder($this->pdo, $relation->relatedTable))
            ->where($relation->foreignKey, 'in', $parentKeys)
            ->get();

        // Index by foreign key.
        $grouped = [];
        foreach ($related as $row) {
            $grouped[$row[$relation->foreignKey]][] = $row;
        }

        foreach ($parentRows as &$parent) {
            $key = $parent[$relation->localKey] ?? null;
            if ($relation->type === 'hasMany') {
                $parent[$relation->as] = $grouped[$key] ?? [];
            } else {
                $parent[$relation->as] = ($grouped[$key] ?? [null])[0];
            }
        }
        unset($parent);

        return $parentRows;
    }

    /**
     * belongsTo: child.foreignKey references parent.localKey.
     * Query: SELECT * FROM parent WHERE localKey IN (child foreignKey values)
     */
    private function loadBelongsTo(array $parentRows, Relation $relation): array
    {
        $foreignKeys = array_unique(array_filter(
            array_column($parentRows, $relation->foreignKey)
        ));
        if ($foreignKeys === []) {
            return $parentRows;
        }

        $related = (new QueryBuilder($this->pdo, $relation->relatedTable))
            ->where($relation->localKey, 'in', array_values($foreignKeys))
            ->get();

        $indexed = [];
        foreach ($related as $row) {
            $indexed[$row[$relation->localKey]] = $row;
        }

        foreach ($parentRows as &$parent) {
            $fk = $parent[$relation->foreignKey] ?? null;
            $parent[$relation->as] = $indexed[$fk] ?? null;
        }
        unset($parent);

        return $parentRows;
    }
}
