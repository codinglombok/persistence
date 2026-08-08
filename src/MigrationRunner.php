<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

use PDO;
use Throwable;

/**
 * Reads an explicit, ordered manifest of migration class names (from
 * bootstrap or a dedicated migrations manifest file) — it never scans a
 * migrations/ directory for files. Applied migrations are tracked in a
 * `migrations` table by class name.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SchemaBuilder $schema,
        private readonly string $driver,
    ) {
        $this->ensureMigrationsTable();
    }

    /**
     * @param list<class-string<Migration>> $manifest run in the exact order given
     * @return list<class-string<Migration>> migrations that were actually applied
     */
    public function migrate(array $manifest): array
    {
        $applied = $this->appliedMigrations();
        $ran = [];

        foreach ($manifest as $migrationClass) {
            if (in_array($migrationClass, $applied, true)) {
                continue;
            }

            /** @var Migration $migration */
            $migration = new $migrationClass();
            $transactional = $migration->runsInTransaction($this->schema);

            if ($transactional) {
                $this->pdo->beginTransaction();
            }

            try {
                $migration->up($this->schema);
                $this->recordApplied($migrationClass);
                if ($transactional) {
                    $this->pdo->commit();
                }
            } catch (Throwable $e) {
                if ($transactional && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }

            $ran[] = $migrationClass;
        }

        return $ran;
    }

    /** @return list<string> */
    /**
     * Undo the last N applied migrations, newest first, by running their
     * down() — the execution path the deploy checklist found missing:
     * every migration AUTHORED a down() and nothing could RUN one
     * (AUDIT-TRAIL #45). Dead rollback code is worse than none, because
     * the runbook assumes it works.
     *
     * Same manifest, same transactional discipline as migrate(), reverse
     * order. A class in the migrations table but absent from the manifest
     * is a hard error — rolling back "whatever the table says" without the
     * class to run is guesswork, and guesswork is not a rollback.
     *
     * This undoes SCHEMA, not data: a down() that drops a table drops its
     * rows. The deployment runbook pairs this with backup-before-migrate
     * for exactly that reason.
     *
     * @param list<class-string<Migration>> $manifest
     * @return list<class-string<Migration>> classes rolled back, newest first
     */
    public function rollback(array $manifest, int $steps = 1): array
    {
        if ($steps < 1) {
            throw new \InvalidArgumentException("rollback steps must be >= 1, got $steps.");
        }

        $applied = $this->appliedMigrations();
        $toRollback = array_slice(array_reverse($applied), 0, $steps);
        $rolledBack = [];

        foreach ($toRollback as $migrationClass) {
            if (!in_array($migrationClass, $manifest, true)) {
                throw new \RuntimeException(
                    "Migration $migrationClass is recorded as applied but is not in the manifest — " .
                    'cannot roll back a migration whose class is unknown. Restore the class or restore a backup.'
                );
            }

            /** @var Migration $migration */
            $migration = new $migrationClass();
            $transactional = $migration->runsInTransaction($this->schema);

            if ($transactional) {
                $this->pdo->beginTransaction();
            }

            try {
                $migration->down($this->schema);
                $stmt = $this->pdo->prepare('DELETE FROM migrations WHERE name = ?');
                $stmt->execute([$migrationClass]);
                if ($transactional) {
                    $this->pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($transactional && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }

            $rolledBack[] = $migrationClass;
        }

        return $rolledBack;
    }

    public function appliedMigrations(): array
    {
        $stmt = $this->pdo->query('SELECT name FROM migrations ORDER BY id ASC');
        return array_map(fn (array $row) => $row['name'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function recordApplied(string $migrationClass): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO migrations (name) VALUES (?)');
        $stmt->execute([$migrationClass]);
    }

    private function ensureMigrationsTable(): void
    {
        $autoIncrement = $this->driver === 'sqlite'
            ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'BIGINT PRIMARY KEY AUTO_INCREMENT';

        if ($this->driver === 'pgsql') {
            $autoIncrement = 'BIGSERIAL PRIMARY KEY';
        }

        // $autoIncrement is one of three driver-specific literals chosen above —
        // never caller input — but it is still interpolated into DDL, which the
        // audit cannot know. Mark the site rather than exempt the file.
        $this->pdo->exec(TrustedDdl::mark(
            "CREATE TABLE IF NOT EXISTS migrations (id $autoIncrement, name VARCHAR(255) NOT NULL UNIQUE)"
        ));
    }
}
