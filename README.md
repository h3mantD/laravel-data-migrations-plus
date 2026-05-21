# Laravel Data Migrations Plus

[![Latest Version on Packagist](https://img.shields.io/packagist/v/h3mantd/laravel-data-migrations-plus.svg?style=flat-square)](https://packagist.org/packages/h3mantd/laravel-data-migrations-plus)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/h3mantd/laravel-data-migrations-plus/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/h3mantd/laravel-data-migrations-plus/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/h3mantd/laravel-data-migrations-plus.svg?style=flat-square)](https://packagist.org/packages/h3mantd/laravel-data-migrations-plus)

A simple, migration-like system for **versioned application data changes** in Laravel. Use it for backfills, reference data, default records, reconciliation patches, and other data changes that should run once and be tracked.

The default path is intentionally small: create a data migration, write `up()`, run `data-migrate`. Advanced production features such as tenancy, checksums, rollback, and verification are available when you need them.

## Table of Contents

- [Why Data Migrations](#why-data-migrations)
- [Requirements](#requirements)
- [Installation](#installation)
- [Simple Usage](#simple-usage)
  - [Create a Data Migration](#create-a-data-migration)
  - [Write the Data Change](#write-the-data-change)
  - [Central vs Tenant/Domain Migrations](#central-vs-tenantdomain-migrations)
  - [Run Data Migrations](#run-data-migrations)
  - [Check Status](#check-status)
  - [Query Builder and Optional Helpers](#query-builder-and-optional-helpers)
- [Advanced Usage](#advanced-usage)
  - [Generator Options](#generator-options)
  - [Running Options](#running-options)
  - [Migration Scopes](#migration-scopes)
  - [Migration Types](#migration-types)
  - [Transactions](#transactions)
  - [Pre-flight Validation](#pre-flight-validation)
  - [Reversible Migrations](#reversible-migrations)
  - [Inspecting a Migration](#inspecting-a-migration)
  - [Rolling Back](#rolling-back)
  - [Integrity Verification](#integrity-verification)
  - [Multi-Tenancy](#multi-tenancy)
  - [JSON Output and CI](#json-output-and-ci)
- [Reference](#reference)
  - [Directory Structure](#directory-structure)
  - [Configuration](#configuration)
  - [The Context Object](#the-context-object)
  - [Tracking Table](#tracking-table)
  - [Database Compatibility](#database-compatibility)
  - [Safe Patterns and Anti-Patterns](#safe-patterns-and-anti-patterns)
- [Testing](#testing)
- [License](#license)

## Why Data Migrations

Laravel schema migrations are excellent for changing database structure. Long-running applications also need a structured way to evolve application data across releases.

Common examples:

- Adding default roles, permissions, feature flags, or system records
- Backfilling new columns from existing data
- Normalizing lookup values after a schema change
- Fixing historical data after a bug
- Seeding required data in tenant databases
- Cleaning up deprecated records after a feature is removed

Seeders are usually not the right fit for this. They are designed for development and demo data. They are not append-only, not tracked like migrations, and can be accidentally re-run. This package gives data changes a migration-like lifecycle: versioned, tracked, append-only, and safe to run during deployments.

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13
- Any database supported by Laravel: MySQL, PostgreSQL, SQLite, SQL Server, Oracle via [yajra/laravel-oci8](https://github.com/yajra/laravel-oci8), or another Laravel-compatible driver

## Installation

Install the package with Composer:

```bash
composer require h3mantd/laravel-data-migrations-plus
```

Publish and run the tracking table migration:

```bash
php artisan vendor:publish --tag="data-migrations-migrations"
php artisan migrate
```

For single-database applications, that is enough to start using the package.

Publish the config only when you want to customize paths, the tracking table name, checksum behavior, locking, or tenant support:

```bash
php artisan vendor:publish --tag="data-migrations-config"
```

If you need to change the tracking table name or tracking connection, publish and edit the config before running `php artisan migrate`.

## Simple Usage

This is the full flow for most single-database Laravel applications.

### Create a Data Migration

```bash
php artisan make:data-migration AddDefaultRoles
```

This creates a timestamped file in `database/data-migrations/`.

### Write the Data Change

A data migration is a PHP file that returns an anonymous class extending `DataMigration`. The only required method is `up()`.

```php
<?php

declare(strict_types=1);

use H3mantd\DataMigrations\DataMigration;
use H3mantd\DataMigrations\DataMigrationContext;

return new class extends DataMigration
{
    public function up(DataMigrationContext $context): void
    {
        $context->helpers()->ensureRecord('roles', ['name' => 'admin'], [
            'description' => 'Administrator with full access',
        ]);
    }
};
```

Data migrations are transactional by default, are tracked after they run, and will not run again after they complete successfully.

### Central vs Tenant/Domain Migrations

The migration class looks almost the same for central and tenant/domain data. The difference is where the file lives and which connection is active when it runs.

| Use case | File location | Runs against | Runs how often |
|---|---|---|---|
| Central data | `database/data-migrations/` | Main application database | Once |
| Tenant/domain data | `database/data-migrations/tenant/` | Current tenant/domain database | Once per tenant/domain |

Create a central migration for global data:

```bash
php artisan make:data-migration SeedGlobalRoles
```

```php
public function up(DataMigrationContext $context): void
{
    $context->connection
        ->table('roles')
        ->insertOrIgnore([
            ['name' => 'admin'],
            ['name' => 'member'],
        ]);
}
```

Create a tenant/domain migration for data that must exist inside every tenant database:

```bash
php artisan make:data-migration SeedTenantSettings --scope=tenant
```

```php
public function up(DataMigrationContext $context): void
{
    $context->connection
        ->table('settings')
        ->insertOrIgnore([
            ['key' => 'timezone', 'value' => 'UTC'],
        ]);
}
```

Use `$context->connection` in tenant/domain migrations so the query runs on the tenant connection selected by your `TenantAdapter`. Avoid hardcoded `DB::connection(...)` calls in tenant/domain migrations unless every tenant intentionally uses that same connection.

### Run Data Migrations

```bash
php artisan data-migrate
```

In production, Laravel commands that can change data require confirmation. Use `--force` during deployment:

```bash
php artisan data-migrate --force
```

To preview what would run without changing data:

```bash
php artisan data-migrate --pretend
```

### Check Status

```bash
php artisan data-migrate:status
```

This shows discovered migrations, completed migrations, pending migrations, batch numbers, and when each migration ran.

### Query Builder and Optional Helpers

You can write migrations with Laravel's Query Builder directly. The package helpers are optional shortcuts that make common idempotent changes easier.

For central-only applications, using Laravel's `DB` facade is fine when you intentionally want the default connection:

```php
use Illuminate\Support\Facades\DB;

public function up(DataMigrationContext $context): void
{
    DB::table('users')
        ->whereNull('timezone')
        ->update(['timezone' => 'UTC']);
}
```

The package also passes the active connection through `$context->connection`. This is the safest option for package docs, reusable migrations, and tenant-aware migrations because it points at the connection selected for the current migration scope.

```php
public function up(DataMigrationContext $context): void
{
    $context->connection
        ->table('users')
        ->whereNull('timezone')
        ->update(['timezone' => 'UTC']);
}
```

Use `$context->connection` when:

- the migration may run for tenants
- your app changes the default database connection at runtime
- you want the migration to be explicit about which connection it uses
- you are writing reusable migrations for a package or shared module

Watch out for these gotchas:

- `DB::table()` uses Laravel's current default connection. In tenant apps, that may be the central database or the tenant database depending on when tenancy is initialized.
- `DB::connection('mysql')` hardcodes a connection. That is fine for central-only migrations, but wrong for tenant migrations unless every tenant uses that same connection.
- `$context->connection` is a Query Builder connection, not an Eloquent model layer. Prefer table/query operations over models so old migrations keep working after models change.
- The package helpers call `$context->connection` internally. They are optional conveniences, not a requirement.

For larger datasets, process records in chunks. Disable the outer transaction when the migration controls its own batching.

```php
public bool $transactional = false;

public function up(DataMigrationContext $context): void
{
    $context->connection
        ->table('users')
        ->whereNull('normalized_email')
        ->orderBy('id')
        ->chunkById(1000, function ($users) use ($context): void {
            foreach ($users as $user) {
                $context->connection
                    ->table('users')
                    ->where('id', $user->id)
                    ->update([
                        'normalized_email' => strtolower($user->email),
                    ]);
            }
        });
}
```

Helpers are provided by this package for common cases. You can mix them with Query Builder calls in the same migration.

#### `ensureRecord`

Insert a record only if a matching record does not already exist.

```php
$context->helpers()->ensureRecord('roles', ['name' => 'admin'], [
    'description' => 'Administrator with full access',
    'created_at' => now(),
]);
```

If the record already exists, it is not updated. Use Laravel's Query Builder `upsert()` directly if you need upsert behavior.

#### `updateWhereNull`

Backfill a column only where the current value is `NULL`.

```php
$context->helpers()->updateWhereNull('users', 'timezone', 'UTC');

$context->helpers()->updateWhereNull('users', 'role', 'viewer', [
    'is_external' => true,
]);
```

#### `normalizeColumn`

Remap old values to new values.

```php
$context->helpers()->normalizeColumn('orders', 'status', [
    'pending_payment' => 'awaiting_payment',
    'in_progress' => 'processing',
    'done' => 'completed',
]);
```

## Advanced Usage

The features in this section are optional. Use them when you need production controls, recovery tooling, CI checks, custom paths, or multi-tenant execution.

### Generator Options

```bash
php artisan make:data-migration BackfillUserTimezones --type=backfill
php artisan make:data-migration SeedTenantSettings --scope=tenant
php artisan make:data-migration CustomMigration --path=packages/my-module/data-migrations
```

| Option | Default | Description |
|---|---|---|
| `--scope` | `central` | `central` or `tenant` |
| `--type` | `bootstrap` | `bootstrap`, `transform`, `backfill`, `reconcile`, or `cleanup` |
| `--path` | configured path | Custom output directory |

### Running Options

```bash
# Central migrations only
php artisan data-migrate --scope=central

# Tenant migrations only
php artisan data-migrate --scope=tenant

# A specific tenant
php artisan data-migrate --scope=tenant --tenant=acme

# A specific migration by name
php artisan data-migrate --name=2026_04_01_100000_add_default_roles

# Continue after failures instead of stopping at the first failure
php artisan data-migrate --continue-on-failure

# Machine-readable output
php artisan data-migrate --json
```

| Option | Default | Description |
|---|---|---|
| `--scope` | `all` | `central`, `tenant`, or `all` |
| `--tenant` | all tenants | Run for a specific tenant only |
| `--name` | none | Run a specific migration by name |
| `--pretend` | `false` | Dry run; show what would execute |
| `--force` | `false` | Required in production |
| `--continue-on-failure` | `false` | Continue after a migration fails |
| `--json` | `false` | Machine-readable JSON output |

When `--scope=all` is used, central migrations run first, then tenant migrations. If no tenant adapter is configured, the tenant step is skipped. Explicit tenant commands such as `--scope=tenant` or `--tenant=acme` fail with a clear configuration error when no adapter is configured.

Failed migrations are handled like Laravel schema migrations: the error is logged, the exception is rendered in the console, and no successful execution record remains. The runner uses a temporary `running` record while a migration executes so concurrent processes do not apply the same migration twice. If the migration fails, that temporary record is removed. After you fix the migration or underlying data issue, run `php artisan data-migrate` again and the same migration will be picked up automatically. Use `--continue-on-failure` when you want later pending migrations to keep running after an earlier migration fails.

### Migration Scopes

A migration's scope is determined by its directory, not by a class property.

| Directory | Scope | How it runs |
|---|---|---|
| `database/data-migrations/` | Central | Runs once against the main application database |
| `database/data-migrations/tenant/` | Tenant | Runs once per tenant through the configured `TenantAdapter` |

Central migrations are for application-wide data: system configuration, global lookup tables, shared reference data, and similar records.

Tenant migrations are for data that must be applied independently in each tenant database.

### Migration Types

`MigrationType` is an informational label. It does not change execution order, tracking, or behavior. It exists to make migration history easier to read in `data-migrate:show`.

Available types:

| Type | Use for |
|---|---|
| `Bootstrap` | Required records that should exist, such as roles or feature flags |
| `Transform` | Changing existing records to a new shape or naming convention |
| `Backfill` | Filling derived or missing values after a schema change |
| `Reconcile` | Fixing historical inconsistencies or bug-created data problems |
| `Cleanup` | Removing deprecated, orphaned, or invalid records |

Example:

```php
use H3mantd\DataMigrations\Enums\MigrationType;

return new class extends DataMigration
{
    public MigrationType $type = MigrationType::Backfill;

    public function up(DataMigrationContext $context): void
    {
        $context->helpers()->updateWhereNull('users', 'timezone', 'UTC');
    }
};
```

### Transactions

Data migrations are wrapped in a database transaction by default.

```php
return new class extends DataMigration
{
    public bool $transactional = true;

    public function up(DataMigrationContext $context): void
    {
        $context->connection->table('roles')->insert(['name' => 'auditor']);
        $context->connection->table('permissions')->insert([
            'name' => 'view_audit_log',
            'role' => 'auditor',
        ]);
    }
};
```

Set `$transactional = false` only when the migration cannot be safely wrapped in a transaction, such as very large batches or database operations that do not support transactions.

```php
return new class extends DataMigration
{
    public bool $transactional = false;

    public function up(DataMigrationContext $context): void
    {
        $context->connection->table('events')
            ->whereNull('normalized_type')
            ->orderBy('id')
            ->chunk(1000, function ($events) use ($context): void {
                foreach ($events as $event) {
                    $context->connection->table('events')
                        ->where('id', $event->id)
                        ->update(['normalized_type' => strtolower($event->type)]);
                }
            });
    }
};
```

When transactions are disabled, your migration should be idempotent so it can safely run again after a partial failure.

### Pre-flight Validation

Override `validate()` to check required tables, columns, or data before `up()` runs. If validation throws, the error is logged and rendered in the console, no successful execution record is written, and `up()` is not called.

```php
return new class extends DataMigration
{
    public function validate(DataMigrationContext $context): void
    {
        $schema = $context->connection->getSchemaBuilder();

        if (! $schema->hasTable('roles')) {
            throw new RuntimeException('The roles table must exist. Run schema migrations first.');
        }
    }

    public function up(DataMigrationContext $context): void
    {
        $context->helpers()->ensureRecord('roles', ['name' => 'admin']);
    }
};
```

Validation is optional. If you do not override `validate()`, the base implementation does nothing.

### Reversible Migrations

Data migrations are irreversible by default. The base `down()` method throws a `LogicException`.

Override `down()` only when rollback behavior is safe and well understood:

```php
return new class extends DataMigration
{
    public function up(DataMigrationContext $context): void
    {
        $context->helpers()->ensureRecord('roles', ['name' => 'auditor']);
    }

    public function down(DataMigrationContext $context): void
    {
        $context->connection->table('roles')->where('name', 'auditor')->delete();
    }
};
```

For production incidents, prefer a new forward migration that reconciles the data. Forward migrations can be reviewed, tested, deployed, and tracked through the normal release process.

### Inspecting a Migration

Use `data-migrate:show` to inspect one migration.

```bash
php artisan data-migrate:show 2026_04_01_100000_add_default_roles
php artisan data-migrate:show 2026_04_01_100000_add_default_roles --scope=central
php artisan data-migrate:show 2026_04_01_100000_add_default_roles --scope=tenant
php artisan data-migrate:show 2026_04_01_100000_add_default_roles --json
```

It displays the file path, scope, type, transactional flag, current checksum when checksums are enabled, and execution history.

If central and tenant migrations share the same name, pass `--scope=central` or `--scope=tenant`.

### Rolling Back

Rollback works only for migrations that implement `down()`.

```bash
php artisan data-migrate:rollback
php artisan data-migrate:rollback --step=2
php artisan data-migrate:rollback --scope=central
```

`--step` must be a positive integer. In production, rollback requires `--force`.

If a migration does not implement `down()`, rollback fails with a clear error. This is intentional: data migrations are irreversible by default.

### Integrity Verification

Use `data-migrate:verify` to check whether tracked migrations still match files on disk.

```bash
php artisan data-migrate:verify
php artisan data-migrate:verify --strict
```

Verification detects:

- Missing files: a migration was recorded as executed, but the file no longer exists
- Checksum drift: a migration file changed after execution
- Duplicate names: the same migration name appears more than once in a scope

Use `--strict` in CI to fail on warnings:

```bash
php artisan data-migrate:verify --strict
```

Checksum behavior is controlled by `data-migrations.checksum.enabled` and `data-migrations.checksum.fail_on_drift`.

### Multi-Tenancy

Tenant support is optional. Single-database applications do not need this section.

To run tenant migrations, implement `TenantAdapter` and register it in `config/data-migrations.php`.

```php
use H3mantd\DataMigrations\Contracts\TenantAdapter;

interface TenantAdapter
{
    public function tenants(): iterable;

    public function enter(mixed $tenant): void;

    public function leave(): void;

    public function tenantKey(mixed $tenant): string;

    public function connectionName(): string;
}
```

Example for Stancl Tenancy:

```php
<?php

namespace App\DataMigrations;

use App\Models\Tenant;
use H3mantd\DataMigrations\Contracts\TenantAdapter;
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

Example for Spatie Multitenancy:

```php
<?php

namespace App\DataMigrations;

use H3mantd\DataMigrations\Contracts\TenantAdapter;
use Spatie\Multitenancy\Models\Tenant;

class SpatieTenantAdapter implements TenantAdapter
{
    public function tenants(): iterable
    {
        return Tenant::all();
    }

    public function enter(mixed $tenant): void
    {
        $tenant->makeCurrent();
    }

    public function leave(): void
    {
        Tenant::forgetCurrent();
    }

    public function tenantKey(mixed $tenant): string
    {
        return (string) $tenant->id;
    }

    public function connectionName(): string
    {
        return 'tenant';
    }
}
```

Register the adapter:

```php
// config/data-migrations.php
'tenant_adapter' => \App\DataMigrations\StanclTenantAdapter::class,
```

In many tenant applications, the default database connection changes after entering tenant context. Set `data-migrations.connection` to your central database connection so tracking always reads and writes on the central database.

```php
// config/data-migrations.php
'connection' => 'mysql',
```

Tenant migration and rollback commands fail when a tenant adapter is configured and `data-migrations.connection` is still `null`. Central-only commands keep using the default connection.

Run tenant migrations:

```bash
# All tenants
php artisan data-migrate --scope=tenant

# One tenant
php artisan data-migrate --scope=tenant --tenant=acme

# Central first, then all tenants
php artisan data-migrate
```

### JSON Output and CI

Several commands support `--json` for automation:

```bash
php artisan data-migrate --json
php artisan data-migrate:status --json
php artisan data-migrate:show 2026_04_01_100000_add_default_roles --json
```

Recommended CI checks:

```bash
php artisan data-migrate --pretend
php artisan data-migrate:verify --strict
```

## Reference

### Directory Structure

```text
database/
  data-migrations/
    2026_04_01_100000_seed_system_config.php
    2026_04_02_100000_add_feature_flags.php
    tenant/
      2026_04_01_100000_seed_default_roles.php
      2026_04_02_100000_backfill_timezones.php
```

Central data migrations live in `database/data-migrations/`. Tenant data migrations live in `database/data-migrations/tenant/`. Migrations run in filename order, so the timestamp prefix determines order.

### Configuration

Published config:

```php
return [
    'central_path' => database_path('data-migrations'),
    'tenant_path' => database_path('data-migrations/tenant'),
    'extra_central_paths' => [],
    'extra_tenant_paths' => [],
    'extra_paths' => [],

    'table' => 'data_migrations',
    'connection' => null,

    'lock' => [
        'enabled' => true,
        'ttl' => 1800,
    ],

    'checksum' => [
        'enabled' => true,
        'fail_on_drift' => true,
    ],

    'tenant_adapter' => null,
];
```

| Key | Description |
|---|---|
| `central_path` | Directory for central migrations |
| `tenant_path` | Directory for tenant migrations |
| `extra_central_paths` | Additional central migration directories |
| `extra_tenant_paths` | Additional tenant migration directories |
| `extra_paths` | Deprecated central-only alias for older published configs |
| `table` | Tracking table name |
| `connection` | Tracking table database connection; use central DB for tenant apps |
| `lock.enabled` | Prevent concurrent data migration runs |
| `lock.ttl` | Cache lock TTL in seconds |
| `checksum.enabled` | Record file checksums on execution |
| `checksum.fail_on_drift` | Fail verification when executed files change |
| `tenant_adapter` | Tenant adapter class name, or `null` for single-database apps |

### The Context Object

`DataMigrationContext` is passed to `up()`, `down()`, and `validate()`.

```php
public function up(DataMigrationContext $context): void
{
    $context->connection; // Illuminate\Database\Connection
    $context->scope;      // MigrationScope::Central or MigrationScope::Tenant
    $context->targetKey;  // tenant key, or null for central migrations
    $context->helpers();  // DataMigrationHelpers
}
```

Use Query Builder through `$context->connection`. Avoid Eloquent models in data migrations because models change over time: scopes, casts, accessors, mutators, and soft-delete behavior can make old migrations break later.

```php
// Stable
$context->connection->table('users')
    ->where('role', 'admin')
    ->update(['role' => 'administrator']);

// Risky in long-lived migrations
User::where('role', 'admin')->update(['role' => 'administrator']);
```

### Tracking Table

Executions are recorded in `data_migrations` by default.

| Column | Description |
|---|---|
| `id` | Primary key |
| `migration_name` | Filename without extension |
| `scope_type` | `central` or `tenant` |
| `target_key` | Tenant identifier; central migrations use an empty string |
| `connection_name` | Database connection used for execution |
| `batch` | Batch number for grouped runs |
| `status` | `completed` for successfully executed migrations |
| `checksum` | SHA-256 hash of the file at execution time, when enabled |
| `started_at` | When execution began |
| `completed_at` | When execution finished |
| `duration_ms` | Execution duration |
| `error_message` | Reserved for older failed execution records; new failures are logged and rendered in the console instead of being stored |

Central and tenant migrations share the same table. The unique key includes migration name, scope, and target key.

### Database Compatibility

The package uses Laravel's database layer and Query Builder. It works with Laravel-supported databases including MySQL, PostgreSQL, SQLite, SQL Server, and Oracle through `yajra/laravel-oci8`.

Tenant applications may use different database engines for central and tenant connections. The tenant adapter chooses the tenant connection at runtime.

### Safe Patterns and Anti-Patterns

#### Recommended

Write idempotent migrations:

```php
$context->helpers()->ensureRecord('roles', ['name' => 'admin']);
$context->helpers()->updateWhereNull('users', 'timezone', 'UTC');
```

Use `validate()` for preconditions:

```php
public function validate(DataMigrationContext $context): void
{
    if (! $context->connection->getSchemaBuilder()->hasTable('roles')) {
        throw new RuntimeException('Run schema migrations first.');
    }
}
```

Prefer forward reconciliation over rollback:

```php
// Add a new migration that explicitly fixes the data.
public function up(DataMigrationContext $context): void
{
    // Correct the issue here.
}
```

Keep `$transactional = true` unless there is a specific reason not to.

#### Avoid

Do not use Eloquent models in long-lived data migrations.

Do not edit migrations after they have run in shared environments. Treat executed data migrations like schema migrations: once applied, they are history. `data-migrate:verify` can flag changed files.

Do not use data migrations for bulk ETL, CSV imports, or very large background jobs. Use queued jobs or dedicated import pipelines for that.

Do not delete data casually. Cleanup migrations should be conservative and reviewed carefully.

Do not depend on execution order between central and tenant scopes. Central runs before tenant by default, but each scope should remain self-contained.

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
