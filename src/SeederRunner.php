<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

use PDO;
use Throwable;

/**
 * Runs an explicit, ordered manifest of seeder class names — it never scans a
 * seeders/ directory, for the same reason MigrationRunner never scans a
 * migrations/ directory (design spec §2.5). Applied seeders are recorded in
 * a `seeders` table by class name.
 *
 * WHY SEEDERS ARE TRACKED AT ALL. The obvious alternative — re-run everything
 * on every `seed`, as the mainstream frameworks do — means the second run of a
 * reference-data seeder silently doubles its rows unless every seeder author
 * remembered to write an existence check by hand. That is precisely the
 * "authored but enforced by nobody" shape of AUDIT-TRAIL #45 (every migration
 * had a down(); nothing could run one). Tracking makes run-once structural, so
 * `seed` is safe in a deploy pipeline, and re-running is something an operator
 * asks for by name (see seedOnly()).
 *
 * ONE TRANSACTION PER SEEDER, covering both the seeder's DML and its `seeders`
 * row. Seeders — unlike migrations — issue no DDL, so every supported driver
 * can roll them back, and there is no reason to leave the door open for a
 * half-applied seed recorded as complete.
 */
final class SeederRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $driver,
    ) {
        $this->ensureSeedersTable();
    }

    /**
     * Run every manifest entry that has not run yet, in manifest order.
     *
     * @param list<class-string<Seeder>> $manifest
     * @return list<class-string<Seeder>> seeders that actually ran
     */
    public function seed(array $manifest, SeedContext $context): array
    {
        $applied = $this->appliedSeeders();
        $ran = [];

        foreach ($manifest as $seederClass) {
            if (in_array($seederClass, $applied, true)) {
                continue;
            }

            $this->runOne($seederClass, $context, alreadyApplied: false);
            $ran[] = $seederClass;
        }

        return $ran;
    }

    /**
     * Run named seeders whether or not they have run before — the deliberate
     * re-run. The caller is responsible for having decided that a second pass
     * is what it wants; this method's job is to make that decision auditable
     * by requiring the names.
     *
     * @param list<class-string<Seeder>> $manifest
     * @param list<class-string<Seeder>> $only
     * @return list<class-string<Seeder>>
     */
    public function seedOnly(array $manifest, array $only, SeedContext $context, bool $force): array
    {
        $applied = $this->appliedSeeders();
        $ran = [];

        foreach ($only as $seederClass) {
            // A name outside the manifest is a hard error rather than a
            // class to load anyway: the manifest is the list of seeders this
            // application admits to having, and running something absent from
            // it reintroduces the untracked-provenance problem.
            if (!in_array($seederClass, $manifest, true)) {
                throw new \RuntimeException(
                    "Seeder $seederClass is not in the manifest — add it there before running it."
                );
            }

            $isApplied = in_array($seederClass, $applied, true);

            if ($isApplied && !$force) {
                continue;
            }

            $this->runOne($seederClass, $context, alreadyApplied: $isApplied);
            $ran[] = $seederClass;
        }

        return $ran;
    }

    /** @return list<string> */
    public function appliedSeeders(): array
    {
        $stmt = $this->pdo->query('SELECT name FROM seeders ORDER BY id ASC');

        return array_map(fn (array $row) => $row['name'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Forget that a seeder ran, without undoing its rows. Exists so that a
     * deliberate re-seed has a path that does not involve hand-editing the
     * tracking table, and is named for what it does NOT do: there is no
     * down() for data, so this cannot be called "rollback".
     */
    public function forget(string $seederClass): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM seeders WHERE name = ?');
        $stmt->execute([$seederClass]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param class-string<Seeder> $seederClass
     */
    private function runOne(string $seederClass, SeedContext $context, bool $alreadyApplied): void
    {
        if (!class_exists($seederClass)) {
            throw new \RuntimeException("Seeder class $seederClass does not exist.");
        }

        $seeder = new $seederClass();

        if (!$seeder instanceof Seeder) {
            throw new \RuntimeException(
                "$seederClass is in the seeder manifest but does not implement " . Seeder::class . '.'
            );
        }

        $this->pdo->beginTransaction();

        try {
            $seeder->seed($context);

            // On a forced re-run the row is already there and UNIQUE would
            // reject a second one. Re-recording is not the point of the
            // re-run, so leave the original row: the first-applied time is
            // the useful fact, not the latest.
            if (!$alreadyApplied) {
                $this->recordApplied($seederClass);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    private function recordApplied(string $seederClass): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO seeders (name) VALUES (?)');
        $stmt->execute([$seederClass]);
    }

    private function ensureSeedersTable(): void
    {
        $autoIncrement = $this->driver === 'sqlite'
            ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'BIGINT PRIMARY KEY AUTO_INCREMENT';

        if ($this->driver === 'pgsql') {
            $autoIncrement = 'BIGSERIAL PRIMARY KEY';
        }

        // Same reasoning as MigrationRunner::ensureMigrationsTable(): the
        // interpolated fragment is one of three driver-specific literals
        // chosen right here and never caller input, but the audit cannot know
        // that — so mark the site rather than exempt the file.
        $this->pdo->exec(TrustedDdl::mark(
            "CREATE TABLE IF NOT EXISTS seeders (id $autoIncrement, name VARCHAR(255) NOT NULL UNIQUE)"
        ));
    }
}
