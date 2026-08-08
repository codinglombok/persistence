<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

use LombokClarion\Persistence\Exceptions\QueryException;
use PDO;

/**
 * One named connection, as a typed value object rather than an array the
 * manager has to guess the shape of.
 *
 * Three named constructors, because there are exactly three ways a
 * connection comes to exist and they have genuinely different rules:
 *
 *   dsn()      — the manager opens the PDO lazily, on first use.
 *   provided() — the PDO already exists and was handed to us. This is the
 *                `$externallyProvided` case from `optimize`: a compiled
 *                container cannot serialise a live PDO, so the connection
 *                is bound at boot and the manager must not try to build it.
 *   template() — the DSN carries a {database} placeholder and is NOT
 *                directly usable. Asking the manager for it by name is an
 *                error; it is resolved per-database via forDatabase().
 *
 * $readDsn is the read/write split. When it is null, read() and write()
 * return the SAME PDO instance — not two connections to the same server.
 * That identity is the point: an unconfigured split must cost nothing.
 */
final class ConnectionConfig
{
    /**
     * @param array<int, mixed> $options PDO driver options
     */
    private function __construct(
        public readonly string $driver,
        public readonly ?string $dsn,
        public readonly ?string $readDsn,
        public readonly ?string $username,
        public readonly ?string $password,
        public readonly array $options,
        public readonly ?PDO $writePdo,
        public readonly ?PDO $readPdo,
        public readonly bool $isTemplate,
    ) {
    }

    /**
     * @param array<int, mixed> $options
     */
    public static function dsn(
        string $driver,
        string $dsn,
        ?string $readDsn = null,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
    ): self {
        if (str_contains($dsn, '{database}')) {
            throw new QueryException(
                'This DSN contains a {database} placeholder, so it is a template, not a ' .
                'usable connection. Use ConnectionConfig::template() and resolve it with ' .
                'ConnectionManager::forDatabase().'
            );
        }

        return new self($driver, $dsn, $readDsn, $username, $password, $options, null, null, false);
    }

    /**
     * A connection whose PDO was built outside the manager — bound at boot,
     * the way `optimize`'s $externallyProvided list requires.
     */
    public static function provided(string $driver, PDO $write, ?PDO $read = null): self
    {
        return new self($driver, null, null, null, null, [], $write, $read, false);
    }

    /**
     * @param array<int, mixed> $options
     */
    public static function template(string $driver, string $dsn, array $options = [], ?string $username = null, ?string $password = null): self
    {
        if (!str_contains($dsn, '{database}')) {
            throw new QueryException(sprintf(
                'A template DSN must contain the {database} placeholder; "%s" does not. ' .
                'If it is a fixed connection, use ConnectionConfig::dsn().',
                $dsn
            ));
        }

        return new self($driver, $dsn, null, $username, $password, $options, null, null, true);
    }

    public function hasReadSplit(): bool
    {
        return $this->readDsn !== null || $this->readPdo !== null;
    }
}
