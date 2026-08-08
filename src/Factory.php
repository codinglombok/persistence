<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Generates filler data for seeders — deterministically, from a seed the
 * caller had to state.
 *
 * THE ONE RULE THIS CLASS EXISTS TO ENFORCE: there is no constructor that
 * does not take a seed, and nothing here reaches for an unseeded global
 * source. Data generated from an implicit source cannot be reproduced, so a
 * seeded fixture that breaks something breaks it once and is never seen
 * again — the same "where did this come from?" property the migration
 * manifest exists to prevent, in data rather than in schema. `seed --seed=N`
 * prints its N, and passing that N back reproduces the run exactly.
 *
 * This is filler, not fake identity data: values are obviously synthetic
 * (`example.invalid` addresses, lorem-shaped words) so a row that escapes into
 * a real environment reads as test data rather than as a plausible person.
 *
 * Not a facade and not a singleton: a Factory is constructed with its seed and
 * handed to seeders through SeedContext.
 */
final class Factory
{
    private readonly Randomizer $randomizer;

    private int $sequence = 0;

    /** @var list<string> */
    private const WORDS = [
        'alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta',
        'iota', 'kappa', 'lambda', 'mu', 'nu', 'xi', 'omicron', 'pi',
        'rho', 'sigma', 'tau', 'upsilon', 'phi', 'chi', 'psi', 'omega',
    ];

    public function __construct(private readonly int $seed)
    {
        // Mt19937 with an explicit seed: same seed, same stream, every run and
        // every machine. The engine is named rather than defaulted so that a
        // future PHP changing the default engine cannot silently change what
        // an old seed produces.
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /**
     * The seed this factory was built from — so a command can print it and a
     * failing run can be reproduced.
     */
    public function seed(): int
    {
        return $this->seed;
    }

    public function int(int $min, int $max): int
    {
        if ($min > $max) {
            throw new \InvalidArgumentException("Factory::int() needs min <= max, got $min > $max.");
        }

        return $this->randomizer->getInt($min, $max);
    }

    public function bool(): bool
    {
        return $this->randomizer->getInt(0, 1) === 1;
    }

    /**
     * @template T
     * @param non-empty-list<T> $choices
     * @return T
     */
    public function pick(array $choices): mixed
    {
        if ($choices === []) {
            throw new \InvalidArgumentException('Factory::pick() needs at least one choice.');
        }

        return $choices[$this->randomizer->getInt(0, count($choices) - 1)];
    }

    /**
     * A monotonic counter, per factory instance. For the columns that must be
     * unique but whose value nobody reads — a random value would collide
     * eventually and only sometimes, which is worse than never.
     */
    public function sequence(): int
    {
        return ++$this->sequence;
    }

    public function words(int $count = 3): string
    {
        if ($count < 1) {
            throw new \InvalidArgumentException("Factory::words() needs count >= 1, got $count.");
        }

        $picked = [];

        for ($i = 0; $i < $count; $i++) {
            $picked[] = $this->pick(self::WORDS);
        }

        return implode(' ', $picked);
    }

    /**
     * Deliberately `.invalid` (RFC 2606): a reserved TLD that can never
     * resolve, so a seeded address cannot reach a real inbox if some job
     * later mails everyone in the table.
     */
    public function email(string $localPrefix = 'user'): string
    {
        return sprintf('%s%d@example.invalid', $localPrefix, $this->sequence());
    }

    /**
     * A 32-hex identifier in the shape this framework's tables use, drawn from
     * the seeded stream rather than random_bytes() — an id that changed every
     * run would make a seeded fixture unassertable.
     */
    public function id(): string
    {
        return bin2hex($this->randomizer->getBytes(16));
    }
}
