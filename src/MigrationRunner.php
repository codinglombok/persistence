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

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS migrations (id $autoIncrement, name VARCHAR(255) NOT NULL UNIQUE)"
        );
    }
}
