# Laravel Data Migrations Plus

[![Latest Version on Packagist](https://img.shields.io/packagist/v/h3mantd/laravel-data-migrations-plus.svg?style=flat-square)](https://packagist.org/packages/h3mantd/laravel-data-migrations-plus)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/h3mantd/laravel-data-migrations-plus/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/h3mantd/laravel-data-migrations-plus/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/h3mantd/laravel-data-migrations-plus.svg?style=flat-square)](https://packagist.org/packages/h3mantd/laravel-data-migrations-plus)

A migration-like system for **versioned application data changes** in Laravel. Like schema migrations, but for your data — backfills, master data, reference data, reconciliation patches, and more.

Keep your data changes disciplined, tracked, and separate from schema migrations.

## Why?

Laravel's schema migrations handle database structure. But long-running applications also need to evolve their **data** across releases:

- Seeding default roles and permissions
- Backfilling new columns from existing data
- Normalizing lookup tables
- Reconciling historical data states
- Updating tenant-level system defaults

Seeders aren't a great fit — they're not append-only, not tracked, and not release-safe. This package gives you the same discipline for data changes that migrations give you for schema changes.

## Requirements

- PHP 8.4+
- Laravel 11, 12, or 13

## Installation

```bash
composer require h3mantd/laravel-data-migrations-plus
```

Publish and run the migration:

```bash
php artisan vendor:publish --tag="data-migrations-migrations"
php artisan migrate
```

Publish the config file:

```bash
php artisan vendor:publish --tag="data-migrations-config"
```

## Quick Start

### 1. Generate a data migration

```bash
php artisan make:data-migration AddDefaultRoles
```

This creates a timestamped file in `database/data-migrations/`:

```php
<?php

declare(strict_types=1);

use H3mantd\DataMigrations\DataMigration;
use H3mantd\DataMigrations\DataMigrationContext;
use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Enums\MigrationType;

return new class extends DataMigration
{
    public MigrationScope $scope = MigrationScope::Central;
    public MigrationType $type = MigrationType::Bootstrap;
    public bool $transactional = true;

    public function up(DataMigrationContext $context): void
    {
        $context->helpers()->ensureRecord('roles', ['name' => 'admin'], [
            'description' => 'Administrator with full access',
        ]);

        $context->helpers()->ensureRecord('roles', ['name' => 'editor'], [
            'description' => 'Can edit content',
        ]);
    }
};
```

### 2. Run it

```bash
php artisan data-migrate
```

### 3. Check status

```bash
php artisan data-migrate:status
```

That's it. Your data change is tracked, versioned, and won't run again.

## Commands

### `make:data-migration`

Generate a new data migration file.

```bash
php artisan make:data-migration SeedDefaultRoles
php artisan make:data-migration BackfillEmails --type=backfill
php artisan make:data-migration TenantSettings --scope=tenant
php artisan make:data-migration CustomPath --path=custom/path
```

**Options:**

| Option | Default | Description |
|---|---|---|
| `--scope` | `central` | `central` or `tenant` |
| `--type` | `bootstrap` | `bootstrap`, `transform`, `backfill`, `reconcile`, `cleanup` |
| `--path` | (from config) | Custom output path |

### `data-migrate`

Run pending data migrations.

```bash
php artisan data-migrate
php artisan data-migrate --scope=central
php artisan data-migrate --scope=tenant --tenant=acme
php artisan data-migrate --name=2026_04_01_100000_add_default_roles
php artisan data-migrate --pretend
php artisan data-migrate --json
```

**Options:**

| Option | Default | Description |
|---|---|---|
| `--scope` | `all` | `central`, `tenant`, or `all` |
| `--tenant` | (all tenants) | Run for a specific tenant only |
| `--name` | (none) | Run a specific migration by name |
| `--pretend` | `false` | Dry run — show what would execute |
| `--force` | `false` | Required in production |
| `--continue-on-failure` | `false` | Don't halt the batch on error |
| `--json` | `false` | Machine-readable output |

### `data-migrate:status`

Inspect migration state.

```bash
php artisan data-migrate:status
php artisan data-migrate:status --scope=tenant --tenant=acme
php artisan data-migrate:status --pending
php artisan data-migrate:status --failed
php artisan data-migrate:status --json
```

### `data-migrate:verify`

Check integrity — missing files, checksum drift, duplicates.

```bash
php artisan data-migrate:verify
php artisan data-migrate:verify --strict   # Non-zero exit on any warning (CI-friendly)
```

### `data-migrate:retry`

Retry failed migrations.

```bash
php artisan data-migrate:retry
php artisan data-migrate:retry --scope=tenant --tenant=acme
```

### `data-migrate:rollback`

Roll back migrations (only when `down()` is implemented).

```bash
php artisan data-migrate:rollback
php artisan data-migrate:rollback --step=2
```

> **Note:** Rollback is a secondary strategy. The recommended production approach is to fix the issue, add a reconciliation migration, and move forward.

## Writing Data Migrations

### The DataMigration Class

Every data migration extends `DataMigration` and implements `up()`:

```php
return new class extends DataMigration
{
    public MigrationScope $scope = MigrationScope::Central;
    public MigrationType $type = MigrationType::Bootstrap;
    public bool $transactional = true;

    public function up(DataMigrationContext $context): void
    {
        // Your data change logic
    }
};
```

### Properties

| Property | Type | Default | Description |
|---|---|---|---|
| `$scope` | `MigrationScope` | `Central` | `Central` or `Tenant` |
| `$type` | `MigrationType` | `Bootstrap` | Categorizes the migration (see below) |
| `$transactional` | `bool` | `true` | Wrap `up()` in a database transaction |

### Migration Types

| Type | Use For |
|---|---|
| `Bootstrap` | Ensuring required system records exist |
| `Transform` | Changing existing records to a new shape |
| `Backfill` | Filling derived or missing values |
| `Reconcile` | Normalizing historical states across customers |
| `Cleanup` | Removing deprecated or invalid records |

### The Context Object

`DataMigrationContext` is passed to `up()`, `down()`, and `validate()`:

```php
public function up(DataMigrationContext $context): void
{
    $context->connection;   // Illuminate\Database\Connection (Query Builder)
    $context->scope;        // MigrationScope enum
    $context->targetKey;    // Tenant identifier (null for central)
    $context->pretend;      // Whether this is a dry run
    $context->helpers();    // DataMigrationHelpers instance
}
```

### Built-in Helpers

Access via `$context->helpers()`:

#### `ensureRecord(string $table, array $attributes, array $values = [])`

Insert a record only if it doesn't exist. Matches on `$attributes`, inserts `$attributes + $values` if not found.

```php
$context->helpers()->ensureRecord('roles', ['name' => 'admin'], [
    'description' => 'Full access',
]);
```

#### `updateWhereNull(string $table, string $column, mixed $value, array $where = [])`

Backfill a column only where it's currently null.

```php
$context->helpers()->updateWhereNull('users', 'timezone', 'UTC');
$context->helpers()->updateWhereNull('users', 'role', 'viewer', ['is_external' => true]);
```

#### `normalizeColumn(string $table, string $column, array $mapping)`

Remap old values to new values.

```php
$context->helpers()->normalizeColumn('roles', 'name', [
    'admin' => 'administrator',
    'mod' => 'moderator',
]);
```

### Pre-flight Validation

Override `validate()` to check preconditions before execution:

```php
public function validate(DataMigrationContext $context): void
{
    if (! $context->connection->getSchemaBuilder()->hasTable('roles')) {
        throw new RuntimeException('roles table must exist before seeding');
    }
}
```

If `validate()` throws, the migration is marked as failed and `up()` is not called.

### Reversible Migrations

Override `down()` to make a migration reversible:

```php
public function down(DataMigrationContext $context): void
{
    $context->connection->table('roles')
        ->whereIn('name', ['admin', 'editor', 'viewer'])
        ->delete();
}
```

By default, `down()` throws a `LogicException` — making migrations explicitly irreversible unless you opt in.

### Use Query Builder, Not Eloquent

Data migrations should use Query Builder (`$context->connection->table(...)`) instead of Eloquent models. Models change over time — columns get renamed, casts change, scopes get added. A data migration written against the Query Builder will keep working regardless of future model changes.

```php
// Good
$context->connection->table('users')->where('role', 'admin')->update(['role' => 'administrator']);

// Avoid
User::where('role', 'admin')->update(['role' => 'administrator']);
```

## Directory Structure

```
database/
  data-migrations/
    2026_04_01_100000_seed_default_roles.php
    2026_04_02_100000_backfill_timezones.php
    tenant/
      2026_04_01_100000_seed_tenant_config.php
      2026_04_02_100000_normalize_settings.php
```

Central migrations go in `database/data-migrations/`. Tenant migrations go in `database/data-migrations/tenant/`.

## Configuration

```php
// config/data-migrations.php

return [
    // Where central data migrations live
    'central_path' => database_path('data-migrations'),

    // Where tenant data migrations live
    'tenant_path' => database_path('data-migrations/tenant'),

    // Additional paths to scan (merged into both scopes)
    'extra_paths' => [],

    // Tracking table name
    'table' => 'data_migrations',

    // Database connection for the tracking table
    // IMPORTANT: Set this explicitly in multi-tenant apps (see Tenancy section)
    'connection' => null,

    // Locking to prevent concurrent execution
    'lock' => [
        'enabled' => true,
        'ttl' => 1800, // 30 minutes
    ],

    // File integrity checking
    'checksum' => [
        'enabled' => true,
        'fail_on_drift' => true, // Hard-fail when a migration file changes after execution
    ],

    // Tenant adapter (null = NullTenantAdapter, throws on tenant operations)
    'tenant_adapter' => null,
];
```

## Multi-Tenancy

The package supports tenant-aware execution through adapters. It does **not** depend on any specific tenancy package — you implement a simple adapter interface.

### The TenantAdapter Contract

```php
interface TenantAdapter
{
    public function tenants(): iterable;        // Enumerate all tenants
    public function enter(mixed $tenant): void; // Switch into tenant context
    public function leave(): void;              // Exit tenant context
    public function tenantKey(mixed $tenant): string;  // Stable identifier
    public function connectionName(): string;   // Active tenant DB connection
}
```

### Example: Stancl Tenancy Adapter

```php
<?php

namespace App\DataMigrations;

use H3mantd\DataMigrations\Contracts\TenantAdapter;
use App\Models\Tenant;
use Stancl\Tenancy\Tenancy;

class StanclTenantAdapter implements TenantAdapter
{
    public function __construct(
        private readonly Tenancy $tenancy,
    ) {}

    public function tenants(): iterable
    {
        return Tenant::all();
    }

    public function enter(mixed $tenant): void
    {
        $this->tenancy->initialize($tenant);
    }

    public function leave(): void
    {
        $this->tenancy->end();
    }

    public function tenantKey(mixed $tenant): string
    {
        return $tenant->getTenantKey();
    }

    public function connectionName(): string
    {
        return 'tenant';
    }
}
```

Register it in `config/data-migrations.php`:

```php
'tenant_adapter' => \App\DataMigrations\StanclTenantAdapter::class,
```

### Critical: Set the Tracking Connection

In multi-tenant setups where the default DB connection changes when entering a tenant context (e.g., Stancl Tenancy), you **must** set the tracking table's connection explicitly:

```php
// config/data-migrations.php
'connection' => 'mysql',  // or 'sqlite', 'pgsql' — your central DB connection name
```

If left as `null`, the tracking repository uses the default connection, which switches to the tenant DB when tenancy is initialized. This causes the package to look for the `data_migrations` table in the tenant database (where it doesn't exist).

### Creating Tenant Data Migrations

```bash
php artisan make:data-migration SeedTenantDefaults --scope=tenant
```

This creates a file in `database/data-migrations/tenant/` with `MigrationScope::Tenant` set.

### Running Tenant Migrations

```bash
# All tenants
php artisan data-migrate --scope=tenant

# Specific tenant
php artisan data-migrate --scope=tenant --tenant=acme

# All scopes (central first, then all tenants)
php artisan data-migrate
```

## Tracking

All migration execution is recorded in the `data_migrations` table:

| Column | Purpose |
|---|---|
| `migration_name` | Filename without extension |
| `scope_type` | `central` or `tenant` |
| `target_key` | Tenant identifier (null for central) |
| `batch` | Groups migrations run together |
| `status` | `pending`, `running`, `completed`, `failed` |
| `checksum` | SHA-256 of file at execution time |
| `duration_ms` | Execution time in milliseconds |
| `error_message` | Error details on failure |
| `started_at` / `completed_at` | Timestamps |

Central and tenant migrations are all tracked in the same table, distinguished by `scope_type` and `target_key`.

## Integrity Verification

The `data-migrate:verify` command catches problems:

- **Missing files** — a migration that ran but whose file was deleted
- **Checksum drift** — a migration file modified after execution (hard-fails by default)
- **Duplicate names** — same migration name in multiple paths

Use `--strict` in CI to fail on any warning:

```bash
php artisan data-migrate:verify --strict
```

## Safe Patterns

### Do

- Use `ensureRecord()` instead of raw inserts (idempotent)
- Use `updateWhereNull()` for backfills (safe to re-run)
- Use `normalizeColumn()` for value remapping
- Use `validate()` for precondition checks
- Wrap complex operations with `$transactional = true`
- Use Query Builder, not Eloquent models

### Don't

- Don't use Eloquent models (they change, your migrations shouldn't)
- Don't delete data without careful consideration
- Don't assume column types or schema — check with `validate()`
- Don't edit migration files after they've run in shared environments
- Don't use data migrations for large ETL/import jobs
- Don't rely on rollback as a primary recovery strategy

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
