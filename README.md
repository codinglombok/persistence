# lombokclarion/persistence

**QueryBuilder (bound-params-only), SchemaBuilder, migrations, seeding, multi-DB ConnectionManager.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/persistence
```

## Namespace

```php
LombokClarion\Persistence
```

## What's Inside

| Class | Role |
|-------|------|
| `QueryBuilder` | SQL builder — bound params only, no raw value injection |
| `SchemaBuilder` | DDL builder: `create()`, `alter()`, `drop()` |
| `Grammar` / `AnsiGrammar` / `MySqlGrammar` | SQL dialect abstraction |
| `GrammarFactory` | Resolves Grammar from driver name |
| `RawExpression` | Escape hatch (placeholder-count == binding-count enforced) |
| `Identifier` | Validates table/column names (no injection via identifiers) |
| `ConnectionConfig` | DSN / provided / template configuration value object |
| `ConnectionManager` | Multi-connection manager (default, reporting, tenants) |
| `Migration` | Migration interface (`up()` / `down()`) |
| `MigrationRunner` | Runs migrations with `lc_migrations` tracking table |
| `Seeder` | Seeder interface |
| `SeedContext` | Seed context (connection + deterministic seed value) |
| `SeederRunner` | Runs seeders with `lc_seeds` idempotency tracking |
| `Factory` | Test data factory for generating fake records |
| `Relation` | Relation definition: `hasMany()`, `hasOne()`, `belongsTo()` |
| `EagerLoader` | N+1-safe eager loading (single `WHERE IN` per relation) |
| `TrustedDdl` | Allows DDL in trusted contexts (migration runner) |

## Usage

```php
use LombokClarion\Persistence\QueryBuilder;

$qb = new QueryBuilder($pdo, $grammar);

// Select
$rows = $qb->table('widgets')
    ->where('status', '=', 'active')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

// Insert
$qb->table('widgets')->insert([
    'name' => 'Gadget',
    'status' => 'active',
]);

// Join
$rows = $qb->table('widgets')
    ->join('categories', 'widgets.category_id', '=', 'categories.id')
    ->select('widgets.*', 'categories.name AS category_name')
    ->get();

// Schema
$schema = new SchemaBuilder($pdo, $grammar);
$schema->create('widgets', function ($table) {
    $table->id();
    $table->string('name');
    $table->string('status')->default('draft');
    $table->timestamps();
});
```

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
