<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Commands;

use H3mantd\DataMigrations\Contracts\TenantAdapter;
use H3mantd\DataMigrations\DataMigrationContext;
use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\DiscoveryService;
use H3mantd\DataMigrations\Services\LockService;
use H3mantd\DataMigrations\Services\TrackingRepository;
use H3mantd\DataMigrations\Support\NullTenantAdapter;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

class DataMigrateRollbackCommand extends Command
{
    public $signature = 'data-migrate:rollback
        {--step=1 : Number of batches to rollback}
        {--scope=all : Scope to rollback (central, tenant, or all)}
        {--tenant= : Rollback for a specific tenant}
        {--force : Required in production}
        {--json : Output as JSON}';

    public $description = 'Rollback data migrations (when reversible)';

    public function handle(
        TrackingRepository $tracking,
        DiscoveryService $discovery,
        LockService $lock,
        DatabaseManager $db,
        TenantAdapter $tenantAdapter,
    ): int {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error('Use --force to run in production.');

            return self::FAILURE;
        }

        if (! $lock->acquire()) {
            $this->components->error('Could not acquire lock. Another migration may be running.');

            return self::FAILURE;
        }

        try {
            return $this->rollback($tracking, $discovery, $db, $tenantAdapter);
        } finally {
            $lock->release();
        }
    }

    private function rollback(
        TrackingRepository $tracking,
        DiscoveryService $discovery,
        DatabaseManager $db,
        TenantAdapter $tenantAdapter,
    ): int {
        $steps = (int) $this->option('step');
        /** @var string $scope */
        $scope = $this->option('scope');
        /** @var string|null $tenantKey */
        $tenantKey = $this->option('tenant');
        $json = (bool) $this->option('json');

        $scopes = match ($scope) {
            'central' => [MigrationScope::Central],
            'tenant' => [MigrationScope::Tenant],
            default => [MigrationScope::Central, MigrationScope::Tenant],
        };

        /** @var list<string> $rolledBack */
        $rolledBack = [];
        /** @var list<string> $errors */
        $errors = [];

        foreach ($scopes as $migrationScope) {
            if ($migrationScope === MigrationScope::Tenant && $tenantAdapter instanceof NullTenantAdapter) {
                continue;
            }

            $targetKey = $migrationScope === MigrationScope::Tenant ? $tenantKey : null;
            $lastBatch = $tracking->getLastBatch($migrationScope, $targetKey);

            if ($lastBatch === 0) {
                continue;
            }

            $startBatch = max(1, $lastBatch - $steps + 1);
            $discovered = $discovery->discover($migrationScope);

            // For tenant scope, find and enter the tenant context
            $enteredTenant = false;
            if ($migrationScope === MigrationScope::Tenant && $targetKey !== null) {
                foreach ($tenantAdapter->tenants() as $tenant) {
                    if ($tenantAdapter->tenantKey($tenant) === $targetKey) {
                        $tenantAdapter->enter($tenant);
                        $enteredTenant = true;

                        break;
                    }
                }

                if (! $enteredTenant) {
                    $errors[] = 'Tenant not found: '.$targetKey;

                    continue;
                }
            }

            try {
                for ($batch = $lastBatch; $batch >= $startBatch; $batch--) {
                    $records = $tracking->getByBatch($batch, $migrationScope, $targetKey);

                    foreach ($records as $record) {
                        /** @var string $migrationName */
                        $migrationName = $record->migration_name;

                        $migration = $discovered[$migrationName] ?? null;

                        if ($migration === null) {
                            $errors[] = 'File not found for: '.$migrationName;

                            continue;
                        }

                        $connection = $db->connection(
                            $migrationScope === MigrationScope::Tenant ? $tenantAdapter->connectionName() : null
                        );

                        $context = new DataMigrationContext(
                            connection: $connection,
                            scope: $migrationScope,
                            targetKey: $targetKey,
                        );

                        try {
                            if ($migration->transactional) {
                                $connection->transaction(fn () => $migration->down($context));
                            } else {
                                $migration->down($context);
                            }

                            /** @var int $recordId */
                            $recordId = $record->id;
                            $tracking->markRolledBack($recordId);
                            $rolledBack[] = $migrationName;
                        } catch (Throwable $e) {
                            $errors[] = sprintf('%s: %s', $migrationName, $e->getMessage());
                        }
                    }
                }
            } finally {
                if ($enteredTenant) {
                    $tenantAdapter->leave();
                }
            }
        }

        if ($json) {
            $this->line((string) json_encode(['rolled_back' => $rolledBack, 'errors' => $errors], JSON_PRETTY_PRINT));

            return count($errors) > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($rolledBack === [] && $errors === []) {
            $this->components->info('Nothing to rollback.');

            return self::SUCCESS;
        }

        foreach ($rolledBack as $name) {
            $this->components->twoColumnDetail($name, '<fg=yellow;options=bold>ROLLED BACK</>');
        }

        foreach ($errors as $error) {
            $this->components->error($error);
        }

        return count($errors) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
