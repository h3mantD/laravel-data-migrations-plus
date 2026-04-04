# Laravel Data Migrations Plus

[![Latest Version on Packagist](https://img.shields.io/packagist/v/h3mantd/laravel-data-migrations-plus.svg?style=flat-square)](https://packagist.org/packages/h3mantd/laravel-data-migrations-plus)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/h3mantd/laravel-data-migrations-plus/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/h3mantd/laravel-data-migrations-plus/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/h3mantd/laravel-data-migrations-plus.svg?style=flat-square)](https://packagist.org/packages/h3mantd/laravel-data-migrations-plus)

A migration-like system for **versioned application data changes** in Laravel. Like schema migrations, but for your data — backfills, master data, reference data, reconciliation patches, and more.

Keep your data changes disciplined, tracked, and separate from schema migrations.

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Writing Data Migrations](#writing-data-migrations)
  - [Generating Data Migrations](#generating-data-migrations)
  - [Data Migration Structure](#data-migration-structure)
  - [Migration Scope](#migration-scope)
  - [Migration Types](#migration-types)
  - [Transactions](#transactions)
  - [The Context Object](#the-context-object)
  - [Pre-flight Validation](#pre-flight-validation)
  - [Reversible Migrations](#reversible-migrations)
  - [Why Query Builder Over Eloquent](#why-query-builder-over-eloquent)
- [Built-in Helpers](#built-in-helpers)
  - [ensureRecord](#ensurerecord)
  - [updateWhereNull](#updatewherenull)
  - [normalizeColumn](#normalizecolumn)
- [Running Data Migrations](#running-data-migrations)
  - [Running Migrations](#running-migrations)
  - [Pretend Mode](#pretend-mode)
  - [Checking Status](#checking-status)
  - [Inspecting a Migration](#inspecting-a-migration)
  - [Retrying Failed Migrations](#retrying-failed-migrations)
  - [Rolling Back](#rolling-back)
  - [Integrity Verification](#integrity-verification)
- [Multi-Tenancy](#multi-tenancy)
  - [The TenantAdapter Contract](#the-tenantadapter-contract)
  - [Stancl Tenancy Example](#stancl-tenancy-example)
  - [Spatie Multitenancy Example](#spatie-multitenancy-example)
  - [Setting the Tracking Connection](#setting-the-tracking-connection)
  - [Running Tenant Migrations](#running-tenant-migrations)
- [Directory Structure](#directory-structure)
- [Configuration](#configuration)
- [Tracking](#tracking)
- [Database Compatibility](#database-compatibility)
- [Safe Patterns & Anti-Patterns](#safe-patterns--anti-patterns)

## Introduction

Laravel has excellent support for schema migrations, but long-running applications also need a structured way to evolve their **data** across releases. Common scenarios include:

- Adding default roles, permissions, or system records
- Backfilling new columns from existing data
- Normalizing lookup tables after a schema change
- Reconciling historical data so all customers converge to a consistent state
- Seeding required configuration in tenant databases
- Cleaning up deprecated records after a feature removal

Laravel's seeders are not a great fit for these scenarios. Seeders are designed for development and demo data — they're not append-only, not tracked, not release-safe, and can be accidentally re-run. This package gives you the same discipline for data changes that migrations give you for schema changes: versioned, tracked, append-only, and safe for production.

## Requirements

- PHP 8.4+
- Laravel 11, 12, or 13
- Any database supported by Laravel (MySQL, PostgreSQL, SQLite, SQL Server)

## Installation

Install the package via Composer:

```bash
composer require h3mantd/laravel-data-migrations-plus
```

Publish and run the tracking table migration:

```bash
php artisan vendor:publish --tag="data-migrations-migrations"
php artisan migrate
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="data-migrations-config"
```

## Quick Start

**1. Generate a data migration:**

```bash
php artisan make:data-migration AddDefaultRoles
```

**2. Write your data change logic:**

```php
public function up(DataMigrationContext $context): void
{
    $context->helpers()->ensureRecord('roles', ['name' => 'admin'], [
        'description' => 'Administrator with full access',
    ]);
}
```

**3. Run it:**

```bash
php artisan data-migrate
```

**4. Check status:**

```bash
php artisan data-migrate:status
```

That's it. Your data change is tracked, versioned, and won't run again.

## Writing Data Migrations

### Generating Data Migrations

Use the `make:data-migration` Artisan command to create a new data migration file. The command generates a timestamped file in the appropriate directory:

```bash
php artisan make:data-migration AddDefaultRoles
```

You may use the `--scope` and `--type` options to specify the migration scope and type:

```bash
php artisan make:data-migration BackfillUserTimezones --type=backfill
php artisan make:data-migration SeedTenantSettings --scope=tenant --type=bootstrap
php artisan make:data-migration NormalizeRoleNames --type=transform
php artisan make:data-migration CleanupExpiredTokens --type=cleanup
```

To place the migration in a custom directory, use the `--path` option:

```bash
php artisan make:data-migration CustomMigration --path=packages/my-module/data-migrations
```

| Option | Default | Description |
|---|---|---|
| `--scope` | `central` | `central` or `tenant` |
| `--type` | `bootstrap` | `bootstrap`, `transform`, `backfill`, `reconcile`, or `cleanup` |
| `--path` | *(from config)* | Custom output directory |

### Data Migration Structure

Every data migration is a PHP file that returns an anonymous class extending `DataMigration`. The only required method is `up()`, which receives a `DataMigrationContext`:

```php
<?php

declare(strict_types=1);

use H3mantd\DataMigrations\DataMigration;
use H3mantd\DataMigrations\DataMigrationContext;
use H3mantd\DataMigrations\Enums\MigrationType;

return new class extends DataMigration
{
    public MigrationType $type = MigrationType::Bootstrap;

    public bool $transactional = true;

    public function up(DataMigrationContext $context): void
    {
        // Your data change logic here
    }
};
```

Data migrations have two public properties and one required method:

| Property | Type | Default | Affects Execution | Description |
|---|---|---|---|---|
| `$type` | `MigrationType` | `Bootstrap` | No | Informational label visible in `data-migrate:show` |
| `$transactional` | `bool` | `true` | **Yes** | Whether `up()` is wrapped in a database transaction |

### Migration Scope

A migration's scope is determined by **which directory it lives in** — not by a property on the class. This keeps it simple: the file path is the single source of truth.

| Directory | Scope | How it runs |
|---|---|---|
| `database/data-migrations/` | Central | Runs once against the main application database |
| `database/data-migrations/tenant/` | Tenant | Runs once **per tenant** via the configured TenantAdapter |

Use the `--scope` option when generating to place the file in the correct directory:

```bash
# Creates in database/data-migrations/
php artisan make:data-migration SeedFeatureFlags

# Creates in database/data-migrations/tenant/
php artisan make:data-migration SeedTenantSettings --scope=tenant
```

#### Central Migrations

Central migrations run once against the main application database. Use them for application-wide data: system configuration, global lookup tables, shared reference data.

```php
// database/data-migrations/2026_04_01_100000_seed_feature_flags.php

return new class extends DataMigration
{
    public function up(DataMigrationContext $context): void
    {
        $helpers = $context->helpers();
        $helpers->ensureRecord('feature_flags', ['key' => 'dark_mode'], ['enabled' => false]);
        $helpers->ensureRecord('feature_flags', ['key' => 'api_v2'], ['enabled' => true]);
    }
};
```

#### Tenant Migrations

Tenant migrations run once **per tenant**. When you execute `php artisan data-migrate --scope=tenant`, the package iterates through all tenants (via your configured [TenantAdapter](#the-tenantadapter-contract)), enters each tenant's context, and runs any pending tenant migrations against that tenant's database.

```php
// database/data-migrations/tenant/2026_04_01_100000_seed_tenant_settings.php

return new class extends DataMigration
{
    public function up(DataMigrationContext $context): void
    {
        // $context->targetKey is the tenant identifier (e.g., 'acme', 'globex')
        // $context->connection points to the tenant's database

        $context->helpers()->ensureRecord('settings', ['key' => 'timezone'], [
            'value' => 'UTC',
        ]);
    }
};
```

Each tenant's execution is tracked independently. If a migration succeeds for tenant `acme` but fails for tenant `globex`, you can see this in `data-migrate:status` and retry only the failed tenant with `data-migrate:retry --tenant=globex`.

> **Note:** Tenant migrations require a configured `tenant_adapter` in your config. Without one, tenant scope is silently skipped when using `--scope=all`, and explicitly fails when using `--scope=tenant`.

### Migration Types

The `$type` property categorizes the **intent** of a migration. It is purely informational — it does not affect how the migration is executed, ordered, or tracked. Think of it as structured documentation: it helps your team understand what a migration does at a glance, and shows up in `data-migrate:show` output.

If you don't set `$type`, it defaults to `MigrationType::Bootstrap`. Setting the wrong type has no functional impact — but getting it right makes your migration history more readable.

There are five migration types:

#### `MigrationType::Bootstrap`

Ensures required system records exist. Bootstrap migrations are idempotent by nature — they insert records only if missing. Use these for initial seed data that every installation needs.

**When to use:** First-time setup of default data, adding new required records in a release.

```php
return new class extends DataMigration
{
    public MigrationType $type = MigrationType::Bootstrap;

    public function up(DataMigrationContext $context): void
    {
        $helpers = $context->helpers();

        // Ensure default roles exist
        $helpers->ensureRecord('roles', ['name' => 'admin'], [
            'description' => 'Full access to all features',
            'is_system' => true,
        ]);
        $helpers->ensureRecord('roles', ['name' => 'member'], [
            'description' => 'Standard member access',
            'is_system' => true,
        ]);
        $helpers->ensureRecord('roles', ['name' => 'guest'], [
            'description' => 'Limited read-only access',
            'is_system' => false,
        ]);

        // Ensure default notification channels exist
        $helpers->ensureRecord('notification_channels', ['slug' => 'email'], [
            'name' => 'Email',
            'enabled' => true,
        ]);
        $helpers->ensureRecord('notification_channels', ['slug' => 'sms'], [
            'name' => 'SMS',
            'enabled' => false,
        ]);
    }
};
```

#### `MigrationType::Transform`

Changes existing records to a new shape or format. Transform migrations modify data in-place — renaming values, restructuring JSON columns, splitting or merging fields.

**When to use:** A schema change requires existing data to be updated to match, or a business rule change requires existing values to be remapped.

```php
return new class extends DataMigration
{
    public MigrationType $type = MigrationType::Transform;

    public function up(DataMigrationContext $context): void
    {
        // Rename role slugs to match new naming convention
        $context->helpers()->normalizeColumn('roles', 'name', [
            'super-admin' => 'administrator',
            'mod'         => 'moderator',
            'basic'       => 'member',
        ]);

        // Convert legacy status codes to new format
        $context->connection->table('orders')
            ->where('status', 'pending_payment')
            ->update(['status' => 'awaiting_payment']);

        $context->connection->table('orders')
            ->where('status', 'in_progress')
            ->update(['status' => 'processing']);
    }
};
```

#### `MigrationType::Backfill`

Fills in derived or missing values, typically after a new column is added. Backfill migrations are safe to re-run because they only touch records where the target value is null or missing.

**When to use:** A new column was added to the schema and existing records need a default value computed from other data.

```php
return new class extends DataMigration
{
    public MigrationType $type = MigrationType::Backfill;

    public function up(DataMigrationContext $context): void
    {
        // Backfill timezone for users who don't have one set
        $context->helpers()->updateWhereNull('users', 'timezone', 'UTC');

        // Backfill display_name from first_name + last_name where it's missing
        $users = $context->connection->table('users')
            ->whereNull('display_name')
            ->whereNotNull('first_name')
            ->get();

        foreach ($users as $user) {
            $context->connection->table('users')
                ->where('id', $user->id)
                ->update(['display_name' => trim($user->first_name.' '.$user->last_name)]);
        }

        // Backfill a computed column from related data
        $usersWithoutCompany = $context->connection->table('users')
            ->whereNull('company_name')
            ->get();

        foreach ($usersWithoutCompany as $user) {
            $company = $context->connection->table('companies')
                ->where('id', $user->company_id)
                ->first();

            if ($company) {
                $context->connection->table('users')
                    ->where('id', $user->id)
                    ->update(['company_name' => $company->name]);
            }
        }
    }
};
```

#### `MigrationType::Reconcile`

Normalizes historical data so all installations converge to the same final state. Reconcile migrations are powerful when you need to fix inconsistencies caused by bugs, manual edits, or differences between customer environments.

**When to use:** Different customers have diverged data states, and you need everyone to end up at the same known-good state. Also useful for fixing data corrupted by a previously deployed bug.

```php
return new class extends DataMigration
{
    public MigrationType $type = MigrationType::Reconcile;

    public function up(DataMigrationContext $context): void
    {
        $db = $context->connection;

        // Fix: Bug in v2.3 created duplicate permission records for some tenants.
        // Remove duplicates, keeping the oldest record.
        $duplicates = $db->table('permissions')
            ->select('name', $db->raw('MIN(id) as keep_id'), $db->raw('COUNT(*) as cnt'))
            ->groupBy('name')
            ->having($db->raw('COUNT(*)'), '>', 1)
            ->get();

        foreach ($duplicates as $dup) {
            $db->table('permissions')
                ->where('name', $dup->name)
                ->where('id', '!=', $dup->keep_id)
                ->delete();
        }

        // Ensure all tenants have the 'manage_billing' permission that was
        // accidentally skipped in the v2.4 bootstrap migration for some.
        $context->helpers()->ensureRecord('permissions', ['name' => 'manage_billing'], [
            'description' => 'Access to billing management',
            'group' => 'billing',
        ]);
    }
};
```

#### `MigrationType::Cleanup`

Removes deprecated, orphaned, or invalid records. Cleanup migrations should be conservative — only delete data that is provably safe to remove.

**When to use:** A feature was removed and its data is no longer needed. Orphaned records from a removed foreign key. Expired temporary data that was never cleaned up.

```php
return new class extends DataMigration
{
    public MigrationType $type = MigrationType::Cleanup;

    public function up(DataMigrationContext $context): void
    {
        $db = $context->connection;

        // Remove deprecated 'legacy_reports' feature flag (feature fully removed in v3.0)
        $db->table('feature_flags')->where('key', 'legacy_reports')->delete();

        // Remove orphaned role_user records where the role no longer exists
        $db->table('role_user')
            ->whereNotExists(function ($query) {
                $query->select($query->raw(1))
                    ->from('roles')
                    ->whereColumn('roles.id', 'role_user.role_id');
            })
            ->delete();

        // Remove expired password reset tokens older than 30 days
        $db->table('password_reset_tokens')
            ->where('created_at', '<', now()->subDays(30))
            ->delete();
    }
};
```

### Transactions

The `$transactional` property controls whether `up()` is wrapped in a database transaction. When `true` (the default), if any part of your migration fails, all changes are automatically rolled back — leaving the database in its pre-migration state.

```php
return new class extends DataMigration
{
    // Default: the entire up() is wrapped in a transaction
    public bool $transactional = true;

    public function up(DataMigrationContext $context): void
    {
        // If this insert succeeds but the update below fails,
        // BOTH operations are rolled back automatically.
        $context->connection->table('roles')->insert(['name' => 'auditor']);
        $context->connection->table('permissions')->insert(['name' => 'view_audit_log', 'role' => 'auditor']);
    }
};
```

Set `$transactional = false` when your migration performs operations that can't be wrapped in a transaction (e.g., DDL statements on some databases), or when you're operating on very large datasets and want explicit control over batching:

```php
return new class extends DataMigration
{
    public bool $transactional = false;

    public function up(DataMigrationContext $context): void
    {
        // Process in chunks to avoid memory issues on large tables
        $context->connection->table('events')
            ->whereNull('normalized_type')
            ->orderBy('id')
            ->chunk(1000, function ($events) use ($context) {
                foreach ($events as $event) {
                    $context->connection->table('events')
                        ->where('id', $event->id)
                        ->update(['normalized_type' => strtolower($event->type)]);
                }
            });
    }
};
```

> **Warning:** When `$transactional` is `false`, a failure mid-execution leaves the database in a partially-modified state. Your migration logic should be idempotent so it can safely be re-run after a failure.

### The Context Object

Every migration method (`up()`, `down()`, `validate()`) receives a `DataMigrationContext` instance. This is your gateway to the database and migration metadata:

```php
public function up(DataMigrationContext $context): void
{
    // The active database connection (Query Builder)
    // For central migrations: the default connection
    // For tenant migrations: the tenant's connection
    $context->connection;

    // The migration scope — automatically set based on the file's directory
    // MigrationScope::Central for database/data-migrations/
    // MigrationScope::Tenant for database/data-migrations/tenant/
    $context->scope;

    // The tenant identifier (null for central migrations)
    // e.g., 'acme', 'globex', 'tenant-123'
    $context->targetKey;

    // Access to built-in helper methods
    $context->helpers();
}
```

The context **deliberately does not expose Eloquent**. You interact with the database through `$context->connection`, which gives you a `Illuminate\Database\Connection` instance — the same object that powers `DB::table()`. This is a design choice: [see why below](#why-query-builder-over-eloquent).

Using the connection directly:

```php
public function up(DataMigrationContext $context): void
{
    $db = $context->connection;

    // Select
    $admins = $db->table('users')->where('role', 'admin')->get();

    // Insert
    $db->table('audit_log')->insert([
        'action' => 'data_migration',
        'details' => 'Seeded default roles',
        'created_at' => now(),
    ]);

    // Update
    $db->table('users')->where('status', 'inactive')->update(['archived' => true]);

    // Delete
    $db->table('temp_tokens')->where('expires_at', '<', now())->delete();

    // Transactions (manual, when $transactional = false)
    $db->transaction(function () use ($db) {
        $accountId = $db->table('accounts')->insertGetId(['name' => 'System']);
        $db->table('wallets')->insert(['account_id' => $accountId]);
    });

    // Schema checks
    $hasColumn = $db->getSchemaBuilder()->hasColumn('users', 'timezone');
}
```

### Pre-flight Validation

Override the `validate()` method to check preconditions before `up()` runs. If `validate()` throws an exception, the migration is marked as **failed** and `up()` is never called.

```php
return new class extends DataMigration
{
    public function validate(DataMigrationContext $context): void
    {
        $schema = $context->connection->getSchemaBuilder();

        // Ensure required tables exist
        if (! $schema->hasTable('roles')) {
            throw new RuntimeException('The roles table must exist. Run schema migrations first.');
        }

        // Ensure required columns exist
        if (! $schema->hasColumn('users', 'timezone')) {
            throw new RuntimeException('The users.timezone column is missing. Run 2026_03_15 migration first.');
        }

        // Check for required data
        $adminExists = $context->connection->table('roles')->where('name', 'admin')->exists();
        if (! $adminExists) {
            throw new RuntimeException('The admin role must exist before running this migration.');
        }
    }

    public function up(DataMigrationContext $context): void
    {
        // This only runs if validate() passed
    }
};
```

Validation is optional. If you don't override `validate()`, the base class implementation does nothing and `up()` runs immediately.

### Reversible Migrations

By default, data migrations are **irreversible**. The base `down()` method throws a `LogicException`, which means `data-migrate:rollback` will fail with a clear message if you try to roll back an irreversible migration.

To make a migration reversible, override `down()`:

```php
return new class extends DataMigration
{
    public function up(DataMigrationContext $context): void
    {
        $context->helpers()->ensureRecord('roles', ['name' => 'auditor'], [
            'description' => 'Can view audit logs',
        ]);
    }

    public function down(DataMigrationContext $context): void
    {
        $context->connection->table('roles')->where('name', 'auditor')->delete();
    }
};
```

> **Production guidance:** Rollback is a secondary recovery strategy. For production systems, the recommended approach is:
> 1. Fix the issue
> 2. Add a new reconciliation migration that corrects the data
> 3. Move forward
>
> This is safer because forward migrations can be tested, reviewed, and deployed through your normal release process. Rollbacks are untested code paths by definition.

### Why Query Builder Over Eloquent

Data migrations should use the Query Builder (`$context->connection->table(...)`) rather than Eloquent models. This is a deliberate design choice, not a limitation.

**Eloquent models are a moving target.** Over time, models accumulate changes: columns get renamed, casts change, accessors/mutators are added, scopes alter queries, soft deletes are toggled. A data migration written today against `User::where(...)` may break in six months when the `User` model changes.

**Query Builder is stable.** A migration written against `$context->connection->table('users')->where(...)` talks directly to the database schema as it exists at migration time. It doesn't care what the model looks like.

```php
// GOOD: This will work forever, regardless of model changes
$context->connection->table('users')
    ->where('role', 'admin')
    ->update(['role' => 'administrator']);

// RISKY: This breaks if User model adds a global scope, cast, or accessor
User::where('role', 'admin')->update(['role' => 'administrator']);
```

## Built-in Helpers

The package ships with helper methods for common idempotent data operations. Access them via `$context->helpers()`. All helpers operate on the active database connection — they automatically use the correct database for both central and tenant scopes.

### `ensureRecord`

```php
ensureRecord(string $table, array $attributes, array $values = []): void
```

Inserts a record only if a matching record doesn't already exist. Checks for existence by querying with `$attributes`, and if no match is found, inserts `$attributes + $values`.

This is the data migration equivalent of "insert if not exists" — safe to run multiple times.

```php
$helpers = $context->helpers();

// Simple: ensure a role exists
$helpers->ensureRecord('roles', ['name' => 'admin']);

// With additional values: match on 'name', insert with 'name' + 'description'
$helpers->ensureRecord('roles', ['name' => 'admin'], [
    'description' => 'Administrator with full access',
    'created_at' => now(),
]);

// Compound key: match on multiple columns
$helpers->ensureRecord('permissions', [
    'role_id' => 1,
    'action' => 'users.manage',
], [
    'granted_at' => now(),
]);
```

> **Important:** If the record already exists, `ensureRecord` does **not** update it. It only inserts if missing. If you need upsert behavior, use Query Builder's `upsert()` directly on `$context->connection`.

### `updateWhereNull`

```php
updateWhereNull(string $table, string $column, mixed $value, array $where = []): void
```

Updates a column only on rows where it's currently `NULL`. Ideal for backfilling new columns without overwriting values that were already set.

```php
$helpers = $context->helpers();

// Backfill timezone for all users who don't have one
$helpers->updateWhereNull('users', 'timezone', 'UTC');

// Backfill with a condition: only external users
$helpers->updateWhereNull('users', 'role', 'viewer', ['is_external' => true]);

// Backfill a default status
$helpers->updateWhereNull('orders', 'fulfillment_status', 'pending');
```

### `normalizeColumn`

```php
normalizeColumn(string $table, string $column, array $mapping): void
```

Remaps old values to new values in a column using a `['old' => 'new']` mapping. Only rows matching the old values are updated — all other rows are untouched.

```php
$helpers = $context->helpers();

// Rename role identifiers
$helpers->normalizeColumn('roles', 'name', [
    'admin'       => 'administrator',
    'mod'         => 'moderator',
    'super-admin' => 'administrator',
]);

// Normalize status values after a schema change
$helpers->normalizeColumn('orders', 'status', [
    'pending_payment' => 'awaiting_payment',
    'in_progress'     => 'processing',
    'done'            => 'completed',
]);

// Normalize country codes
$helpers->normalizeColumn('addresses', 'country', [
    'US'  => 'USA',
    'UK'  => 'GBR',
    'CA'  => 'CAN',
]);
```

## Running Data Migrations

### Running Migrations

The `data-migrate` command runs all pending data migrations:

```bash
# Run all pending (central first, then tenant)
php artisan data-migrate

# Central only
php artisan data-migrate --scope=central

# Tenant only (all tenants)
php artisan data-migrate --scope=tenant

# Specific tenant
php artisan data-migrate --scope=tenant --tenant=acme

# Specific migration by name
php artisan data-migrate --name=2026_04_01_100000_add_default_roles

# Required in production
php artisan data-migrate --force

# Continue past failures instead of halting
php artisan data-migrate --continue-on-failure

# Machine-readable output
php artisan data-migrate --json
```

| Option | Default | Description |
|---|---|---|
| `--scope` | `all` | `central`, `tenant`, or `all` |
| `--tenant` | *(all tenants)* | Run for a specific tenant only |
| `--name` | *(none)* | Run a specific migration by name |
| `--pretend` | `false` | Dry run — show what would execute |
| `--force` | `false` | Required in production environment |
| `--continue-on-failure` | `false` | Don't halt the batch on error |
| `--json` | `false` | Machine-readable JSON output |

When running in `--scope=all` (the default), central migrations execute first, then tenant migrations. This ensures that any global data your tenant migrations depend on is already in place.

### Pretend Mode

Use `--pretend` to see what would run without actually executing anything:

```bash
php artisan data-migrate --pretend
```

No data is changed, no records are tracked. This is useful for previewing what a deployment will do.

### Checking Status

The `data-migrate:status` command shows the state of all migrations:

```bash
php artisan data-migrate:status
php artisan data-migrate:status --scope=tenant
php artisan data-migrate:status --scope=tenant --tenant=acme
php artisan data-migrate:status --pending
php artisan data-migrate:status --failed
php artisan data-migrate:status --json
```

The output shows each migration's name, scope, tenant (if applicable), status, batch number, and when it ran. Pending migrations (discovered on disk but not yet executed) are also shown.

### Inspecting a Migration

Use `data-migrate:show` to get detailed information about a specific migration:

```bash
php artisan data-migrate:show 2026_04_01_100000_add_default_roles
php artisan data-migrate:show 2026_04_01_100000_add_default_roles --json
```

This displays:
- File path, scope, type, and transactional flag
- Current file checksum
- Full execution history: per-tenant status, batch, duration, checksum match, and any error messages

### Retrying Failed Migrations

If a migration failed (for a specific tenant or centrally), retry it:

```bash
php artisan data-migrate:retry
php artisan data-migrate:retry --scope=tenant --tenant=acme
php artisan data-migrate:retry --json
```

The retry command only re-runs migrations that previously failed. It does **not** run new pending migrations — keeping the blast radius small.

### Rolling Back

Roll back the last batch of migrations (only works if `down()` is implemented):

```bash
php artisan data-migrate:rollback
php artisan data-migrate:rollback --step=2    # Roll back last 2 batches
php artisan data-migrate:rollback --scope=central
```

Attempting to rollback a migration that doesn't implement `down()` will fail with a clear error. This is intentional — the package makes irreversibility the safe default.

### Integrity Verification

The `data-migrate:verify` command checks for consistency problems:

```bash
php artisan data-migrate:verify
php artisan data-migrate:verify --strict
```

It detects:
- **Missing files** — a migration was recorded as executed, but the file no longer exists on disk
- **Checksum drift** — a migration file was modified after it was executed (hard-fails by default)
- **Duplicate names** — the same migration name appears in multiple paths

Use `--strict` in CI pipelines to fail on any warning:

```bash
# In your CI pipeline
php artisan data-migrate:verify --strict
```

## Multi-Tenancy

The package supports tenant-aware execution through adapters. It does **not** depend on any specific tenancy package — you implement a simple interface and register it in your config.

### The TenantAdapter Contract

```php
use H3mantd\DataMigrations\Contracts\TenantAdapter;

interface TenantAdapter
{
    /**
     * Return all tenants to iterate over.
     */
    public function tenants(): iterable;

    /**
     * Enter the given tenant's context (switch database, etc.).
     */
    public function enter(mixed $tenant): void;

    /**
     * Leave the current tenant context.
     */
    public function leave(): void;

    /**
     * Return a stable, unique string identifier for this tenant.
     * Used as target_key in the tracking table.
     */
    public function tenantKey(mixed $tenant): string;

    /**
     * Return the database connection name to use for this tenant.
     */
    public function connectionName(): string;
}
```

### Stancl Tenancy Example

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

### Spatie Multitenancy Example

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

Register your adapter in `config/data-migrations.php`:

```php
'tenant_adapter' => \App\DataMigrations\StanclTenantAdapter::class,
// or
'tenant_adapter' => \App\DataMigrations\SpatieTenantAdapter::class,
```

### Setting the Tracking Connection

> **This is critical for multi-tenant setups.**

In multi-tenant applications where the default database connection changes when entering a tenant context (which is how both Stancl and Spatie tenancy work), you **must** explicitly set the connection for the tracking table:

```php
// config/data-migrations.php
'connection' => 'mysql',  // or 'pgsql', 'sqlite' — your central DB connection name
```

If left as `null`, the tracking repository uses the default connection. When tenancy is initialized, the default switches to the tenant's database — and the package will try to read/write the `data_migrations` table in the tenant database (where it doesn't exist). Setting an explicit connection prevents this.

### Running Tenant Migrations

```bash
# All tenants — iterates through every tenant
php artisan data-migrate --scope=tenant

# Specific tenant only
php artisan data-migrate --scope=tenant --tenant=acme

# All scopes (central first, then all tenants)
php artisan data-migrate
```

During tenant execution, the command shows progress per tenant:

```
Tenant: acme ...................................................... RUNNING
2026_04_01_100000_seed_default_roles ............................... DONE
2026_04_01_200000_seed_default_settings ............................ DONE
Tenant: globex .................................................... RUNNING
2026_04_01_100000_seed_default_roles ............................... DONE
2026_04_01_200000_seed_default_settings ............................ DONE
```

## Directory Structure

```
database/
  data-migrations/
    2026_04_01_100000_seed_system_config.php
    2026_04_02_100000_add_feature_flags.php
    tenant/
      2026_04_01_100000_seed_default_roles.php
      2026_04_02_100000_backfill_timezones.php
      2026_04_03_100000_normalize_settings.php
```

Central data migrations live in `database/data-migrations/`. Tenant data migrations live in `database/data-migrations/tenant/`. These paths are configurable.

Migrations are executed in **filename order** (the timestamp prefix ensures deterministic ordering), just like Laravel schema migrations.

## Configuration

The full configuration file:

```php
// config/data-migrations.php

return [
    /*
    |--------------------------------------------------------------------------
    | Migration Paths
    |--------------------------------------------------------------------------
    |
    | The directories where data migration files are stored. Central migrations
    | go in central_path, tenant migrations go in tenant_path. You may add
    | additional paths via extra_paths (merged into both scopes).
    |
    */
    'central_path' => database_path('data-migrations'),
    'tenant_path' => database_path('data-migrations/tenant'),
    'extra_paths' => [],

    /*
    |--------------------------------------------------------------------------
    | Tracking Table
    |--------------------------------------------------------------------------
    |
    | The name and connection for the data_migrations tracking table. This table
    | records which migrations have run, their status, checksums, and timing.
    |
    | IMPORTANT: In multi-tenant apps, set 'connection' to your central database
    | connection name. If null, the default connection is used — which may switch
    | to the tenant database when tenancy is initialized.
    |
    */
    'table' => 'data_migrations',
    'connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Locking
    |--------------------------------------------------------------------------
    |
    | Prevents concurrent execution of data migrations. Uses Laravel's cache
    | lock mechanism. The TTL is a safety net — if a migration crashes without
    | releasing the lock, it will auto-expire after this many seconds.
    |
    */
    'lock' => [
        'enabled' => true,
        'ttl' => 1800, // 30 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Checksum Verification
    |--------------------------------------------------------------------------
    |
    | When enabled, a SHA-256 checksum of each migration file is recorded at
    | execution time. The verify command compares current checksums against
    | stored ones to detect unauthorized modifications.
    |
    | fail_on_drift: When true, the verify command fails (non-zero exit) when
    | a migration file has been modified after execution. When false, it warns
    | but still exits successfully (unless --strict is used).
    |
    */
    'checksum' => [
        'enabled' => true,
        'fail_on_drift' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Adapter
    |--------------------------------------------------------------------------
    |
    | The fully qualified class name of your TenantAdapter implementation.
    | Set to null if you don't use multi-tenancy. When null, running
    | data-migrate --scope=all silently skips the tenant scope.
    |
    */
    'tenant_adapter' => null,
];
```

## Tracking

All migration execution is recorded in the `data_migrations` table on your central database:

| Column | Type | Description |
|---|---|---|
| `id` | auto-increment | Primary key |
| `migration_name` | string | Filename without extension (e.g., `2026_04_01_100000_add_roles`) |
| `scope_type` | string | `central` or `tenant` |
| `target_key` | string, nullable | Tenant identifier (`null` for central) |
| `connection_name` | string | Database connection used for execution |
| `batch` | integer | Groups migrations that ran together |
| `status` | string | `pending`, `running`, `completed`, or `failed` |
| `checksum` | string, nullable | SHA-256 hash of the file at execution time |
| `started_at` | timestamp | When execution began |
| `completed_at` | timestamp | When execution finished |
| `duration_ms` | integer | Execution time in milliseconds |
| `error_message` | text, nullable | Error details on failure |

Central and tenant migrations are tracked in the same table. A unique constraint on `(migration_name, scope_type, target_key)` ensures each migration is tracked independently per scope and tenant.

## Database Compatibility

The package works with **all databases supported by Laravel**: MySQL, PostgreSQL, SQLite, and SQL Server. All queries use Laravel's Query Builder — no raw SQL, no driver-specific syntax.

In multi-tenant setups, the central database and tenant databases can be on **different database engines**. For example, your central database might be MySQL while tenant databases are PostgreSQL. The package resolves the correct connection at runtime through the `TenantAdapter`.

## Safe Patterns & Anti-Patterns

### Recommended Patterns

**Use idempotent helpers.** `ensureRecord()`, `updateWhereNull()`, and `normalizeColumn()` are safe to run multiple times:

```php
// Safe: won't insert duplicates
$context->helpers()->ensureRecord('roles', ['name' => 'admin']);

// Safe: won't overwrite existing values
$context->helpers()->updateWhereNull('users', 'timezone', 'UTC');
```

**Use validate() for preconditions.** Don't let migrations fail mid-execution when you can check upfront:

```php
public function validate(DataMigrationContext $context): void
{
    if (! $context->connection->getSchemaBuilder()->hasTable('roles')) {
        throw new RuntimeException('Run schema migrations first.');
    }
}
```

**Prefer forward reconciliation over rollback.** When something goes wrong in production, add a new migration to fix it instead of rolling back:

```php
// Instead of rolling back, add a new reconciliation migration
// 2026_04_05_100000_fix_duplicate_roles.php
public function up(DataMigrationContext $context): void
{
    // Fix the issue explicitly
}
```

**Use transactions for atomicity.** Keep `$transactional = true` (the default) unless you have a specific reason not to.

### Anti-Patterns to Avoid

**Don't use Eloquent models.** They change over time and will break your migrations:

```php
// Bad: Model might add scopes, casts, or soft deletes later
User::where('role', 'admin')->update(['role' => 'administrator']);

// Good: Direct Query Builder, stable forever
$context->connection->table('users')->where('role', 'admin')->update(['role' => 'administrator']);
```

**Don't edit migrations after they've run in shared environments.** Treat shipped data migrations like schema migrations — once applied, they're history. The `data-migrate:verify` command will flag modified files.

**Don't use data migrations for bulk ETL or imports.** Data migrations are for structured, versioned data changes — not for importing CSV files or processing millions of records. Use queued jobs for that.

**Don't delete data without careful consideration.** Cleanup migrations should be the exception, not the rule. Always verify that deleted data is truly unused.

**Don't depend on execution order between central and tenant scopes.** While the package runs central before tenant by default, don't encode cross-scope dependencies in your migrations. Each scope should be self-contained.

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
