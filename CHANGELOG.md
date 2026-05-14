# Changelog

All notable changes to `laravel-data-migrations-plus` will be documented in this file.

## v1.0.3 - 2026-05-14

### What's Changed

- Hardened data migration command handling.
- Simplified README usage flow with clearer simple and advanced sections.

### Verification

- Rector, Pint, PHPStan, and full test suite passed before release.

## Unreleased

### Fixed

- Retry stale `running` migration rows when their `started_at` timestamp is older than the configured lock TTL.
- Reject duplicate migration names within the same scope before execution.
- Validate explicit tenant keys before migration, retry, and rollback commands run.
- Stop tenant rollback commands from rolling back central migrations before an invalid tenant is rejected.
- Clean up tenant context even when entering a tenant throws an exception.
- Require an explicit central tracking connection for tenant migration, retry, and rollback commands when a tenant adapter is configured.
- Respect `checksum.enabled=false` during execution and in `data-migrate:show` output.
- Require `data-migrate:rollback --step` to be a positive integer.

### Changed

- `data-migrate:show` accepts `--scope=central|tenant` and requires it when central and tenant migrations share a name.
- Runtime Illuminate package dependencies are declared directly in `composer.json`.

## v1.0.1 - 2026-05-07

### Fixed

- Treat legacy central tracking rows with `target_key = NULL` as completed central migrations to avoid reruns after upgrading to non-null central keys.
- Make `data-migrate:retry` and `data-migrate:rollback` skip implicit tenant work during default `--scope=all` when no tenant adapter is configured, while keeping explicit tenant commands as clear failures.
- Scope extra discovery paths with `extra_central_paths` and `extra_tenant_paths`; legacy `extra_paths` remains as a central-only alias.

## v1.0.0 - 2026-04-04

### Initial Release

A migration-like system for versioned application data changes in Laravel.

#### Commands

- `make:data-migration` — generate timestamped data migration files with `--scope`, `--type`, and `--path` options
- `data-migrate` — run pending data migrations with `--scope`, `--tenant`, `--pretend`, `--force`, `--continue-on-failure`, and `--json` options
- `data-migrate:status` — inspect migration state with per-tenant visibility, `--pending`, `--failed`, and `--json` filters
- `data-migrate:show` — inspect a specific migration's details and per-tenant execution history
- `data-migrate:verify` — check integrity (missing files, checksum drift, duplicates) with `--strict` for CI
- `data-migrate:retry` — retry failed migrations without running unrelated pending ones
- `data-migrate:rollback` — roll back migrations that implement `down()`, with `--step` support

#### Core Features

- Central and tenant scopes — scope determined by file path (`database/data-migrations/` vs `database/data-migrations/tenant/`)
- Tenant-agnostic via `TenantAdapter` contract — works with Stancl Tenancy, Spatie Multitenancy, custom solutions, or no tenancy at all
- Tracking table (`data_migrations`) with batch, status, checksum, duration, and error tracking
- SHA-256 checksum verification with configurable hard-fail on drift
- Cache-based locking to prevent concurrent execution
- Transaction support per migration via `$transactional` property
- Pre-flight validation via `validate()` method
- Pretend (dry-run) mode
- JSON output for all commands
- Production safety (`--force` required for `data-migrate`, `data-migrate:retry`, `data-migrate:rollback`)
- Per-tenant progress feedback during batch execution

#### Built-in Helpers

- `ensureRecord()` — idempotent insert-if-missing
- `updateWhereNull()` — safe backfill for null columns
- `normalizeColumn()` — remap old values to new values

#### Database Support

- MySQL, PostgreSQL, SQLite, SQL Server
- Oracle via yajra/laravel-oci8
- Mixed engines across central and tenant databases

#### Requirements

- PHP 8.3+
- Laravel 11, 12, or 13
