<?php

declare(strict_types=1);

namespace LombokClarion\Persistence;

use LombokClarion\Persistence\Exceptions\QueryException;
use PDO;

/**
 * Named connections, resolved lazily, with no static `DB::connection()`
 * anywhere in sight. The manager is an ordinary object you inject; code
 * that needs the reporting database asks for it by name at the one place
 * it is wired, and that wiring is greppable.
 *
 *   $manager = new ConnectionManager([
 *       'default'   => ConnectionConfig::dsn('pgsql', 'pgsql:host=primary;dbname=app',
 *                          readDsn: 'pgsql:host=replica;dbname=app'),
 *       'reporting' => ConnectionConfig::dsn('pgsql', 'pgsql:host=warehouse;dbname=rollups'),
 *       'tenants'   => ConnectionConfig::template('sqlite', 'sqlite:/data/{database}.sqlite'),
 *   ], default: 'default');
 *
 *   $manager->write();               // primary
 *   $manager->read();                // replica
 *   $manager->write('reporting');    // warehouse (no split configured → read() === write())
 *   $manager->forDatabase('tenants', 'acme');
 *
 * LAZY: constructing the manager opens nothing. A DSN pointing at a server
 * that is down is not an error until someone actually asks for it — which
 * is what makes it safe to declare six connections and use one, and what
 * makes `optimize` able to compile the graph without a database present.
 *
 * READ/WRITE SPLIT: this class routes; it does not know about replication
 * lag, and it will not guess for you. read() is a promise about which
 * server, not about which data. A read-your-own-write immediately after a
 * write() must ask for write() explicitly. There is deliberately no
 * "sticky connection" heuristic that silently redirects reads after a
 * write — that is the kind of magic that works in tests and produces
 * unreproducible staleness bugs in production.
 */
final class ConnectionManager
{
    /** @var array<string, ConnectionConfig> */
    private array $configs;

    /** @var array<string, PDO> resolved PDOs, keyed by an internal cache key */
    private array $resolved = [];

    /**
     * @param array<string, ConnectionConfig> $configs
     */
    public function __construct(array $configs, private readonly string $default = 'default')
    {
        foreach ($configs as $name => $config) {
            if (!is_string($name) || $name === '') {
                throw new QueryException('Connection names must be non-empty strings.');
            }
            if (!$config instanceof ConnectionConfig) {
                throw new QueryException(sprintf(
                    'Connection "%s" must be a ConnectionConfig, got %s. The manager takes typed ' .
                    'config objects, not arrays — the shape is checked here, once, not at every use site.',
                    (string) $name,
                    get_debug_type($config)
                ));
            }
        }

        if (!array_key_exists($default, $configs)) {
            throw new QueryException(sprintf(
                'Default connection "%s" is not defined. Defined: %s.',
                $default,
                $configs === [] ? '(none)' : implode(', ', array_keys($configs))
            ));
        }

        $this->configs = $configs;
    }

    public function defaultName(): string
    {
        return $this->default;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->configs);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->configs);
    }

    public function config(?string $name = null): ConnectionConfig
    {
        $name ??= $this->default;

        if (!array_key_exists($name, $this->configs)) {
            throw new QueryException(sprintf(
                'Unknown connection "%s". Defined: %s.',
                $name,
                implode(', ', array_keys($this->configs))
            ));
        }

        return $this->configs[$name];
    }

    /** Alias for write(). The unqualified connection is the writable one. */
    public function connection(?string $name = null): PDO
    {
        return $this->write($name);
    }

    public function write(?string $name = null): PDO
    {
        $name ??= $this->default;
        $config = $this->config($name);
        $this->rejectTemplate($name, $config);

        return $this->resolved["$name#write"] ??= $config->writePdo
            ?? $this->makePdo(
                (string) $config->dsn,
                $config->username,
                $config->password,
                $config->options
            );
    }

    /**
     * The read side. With no split configured this returns the identical
     * PDO instance write() returns — same object, not a second connection.
     */
    public function read(?string $name = null): PDO
    {
        $name ??= $this->default;
        $config = $this->config($name);
        $this->rejectTemplate($name, $config);

        if (!$config->hasReadSplit()) {
            return $this->write($name);
        }

        return $this->resolved["$name#read"] ??= $config->readPdo
            ?? $this->makePdo(
                (string) $config->readDsn,
                $config->username,
                $config->password,
                $config->options
            );
    }

    public function driver(?string $name = null): string
    {
        return $this->config($name)->driver;
    }

    public function grammar(?string $name = null): Grammar
    {
        return GrammarFactory::forDriver($this->config($name)->driver);
    }

    /**
     * A QueryBuilder already wired to the right PDO and the right grammar
     * for that connection — so the caller cannot pair a mysql PDO with an
     * ANSI grammar by forgetting an argument.
     */
    public function table(string $table, ?string $connection = null): QueryBuilder
    {
        return new QueryBuilder(
            $this->write($connection),
            $table,
            $this->grammar($connection)
        );
    }

    /**
     * Resolve a {database} template into a real connection — the seam
     * database-per-tenant isolation stands on. Resolved PDOs are cached per
     * database, so two requests for the same tenant in one process share one
     * connection while different tenants can never share one.
     */
    public function forDatabase(string $name, string $database): PDO
    {
        $config = $this->config($name);

        if (!$config->isTemplate) {
            throw new QueryException(sprintf(
                'Connection "%s" is not a template, so forDatabase() has nothing to substitute. ' .
                'Use write()/read() for fixed connections.',
                $name
            ));
        }

        // The substituted value lands in a DSN, which is not SQL but is also
        // not a bound parameter — so it gets the same treatment identifiers
        // get: validated against a strict pattern, never escaped.
        Identifier::validate($this->databaseKey($database));

        return $this->resolved["$name@$database"] ??= $this->makePdo(
            str_replace('{database}', $database, (string) $config->dsn),
            $config->username,
            $config->password,
            $config->options
        );
    }

    /**
     * A database name may legitimately be a filesystem path (sqlite) or a
     * plain name (pgsql/mysql). Validate the last path segment, minus any
     * extension — enough to reject traversal and DSN-option injection
     * (`;` and `=` are how you smuggle extra DSN attributes) without
     * banning `/data/tenants/acme.sqlite`.
     */
    private function databaseKey(string $database): string
    {
        if ($database === '') {
            throw new QueryException('Database name for a template connection cannot be empty.');
        }

        if (str_contains($database, ';') || str_contains($database, '=') || str_contains($database, '..')) {
            throw new QueryException(sprintf(
                'Refusing database name "%s": ";", "=" and ".." can rewrite the DSN or escape the ' .
                'intended directory.',
                $database
            ));
        }

        $base = basename(str_replace('\\', '/', $database));
        $dot = strpos($base, '.');

        return $dot === false ? $base : substr($base, 0, $dot);
    }

    private function rejectTemplate(string $name, ConnectionConfig $config): void
    {
        if ($config->isTemplate) {
            throw new QueryException(sprintf(
                'Connection "%s" is a {database} template and cannot be opened directly — there is ' .
                'no correct database to pick. Resolve it with forDatabase("%s", $database).',
                $name,
                $name
            ));
        }
    }

    /**
     * @param array<int, mixed> $options
     */
    private function makePdo(string $dsn, ?string $username, ?string $password, array $options): PDO
    {
        $pdo = new PDO($dsn, $username, $password, $options === [] ? null : $options);

        // Silent-failure mode is PDO's default and is indefensible: a failed
        // statement returning false, then a fatal on ->fetch(), points at the
        // wrong line. Set unless the caller deliberately said otherwise.
        if (!array_key_exists(PDO::ATTR_ERRMODE, $options)) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return $pdo;
    }
}
