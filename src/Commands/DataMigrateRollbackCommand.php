<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Commands;

use H3mantd\DataMigrations\Commands\Concerns\ParsesMigrationScopes;
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
    use ParsesMigrationScopes;

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

        $json = (bool) $this->option('json');
        $step = $this->option('step');
        if (! $this->isPositiveInteger($step)) {
            return $this->failWithMessage('Invalid step: must be a positive integer.', $json);
        }

        /** @var string $scope */
        $scope = $this->option('scope');
        /** @var string|null $tenantKey */
        $tenantKey = $this->option('tenant');

        $scopes = $this->parseMigrationScopes($scope);
        if ($scopes === null) {
            return $this->failWithMessage($this->invalidScopeMessage($scope), $json);
        }

        if (($scope === 'tenant' || $tenantKey !== null) && $tenantAdapter instanceof NullTenantAdapter) {
            return $this->failWithMessage('No tenant adapter configured. Set data-migrations.tenant_adapter in your config.', $json);
        }

        if ($tenantKey !== null && ! $this->tenantExists($tenantAdapter, $tenantKey)) {
            return $this->failWithMessage('Tenant not found: '.$tenantKey, $json);
        }

        if ($this->tenantTrackingConnectionMissing($scopes, $tenantAdapter)) {
            return $this->failWithMessage($this->missingTenantTrackingConnectionMessage(), $json);
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

        $scopes = $this->parseMigrationScopes($scope);
        if ($scopes === null) {
            return $this->failWithMessage($this->invalidScopeMessage($scope), $json);
        }

        if (($scope === 'tenant' || $tenantKey !== null) && $tenantAdapter instanceof NullTenantAdapter) {
            return $this->failWithMessage('No tenant adapter configured. Set data-migrations.tenant_adapter in your config.', $json);
        }

        /** @var list<string> $rolledBack */
        $rolledBack = [];
        /** @var list<string> $errors */
        $errors = [];

        foreach ($scopes as $migrationScope) {
            $targetKey = $migrationScope === MigrationScope::Tenant ? $tenantKey : null;
            if ($migrationScope === MigrationScope::Tenant && $tenantAdapter instanceof NullTenantAdapter) {
                continue;
            }

            if ($migrationScope === MigrationScope::Tenant && $targetKey === null) {
                foreach ($tenantAdapter->tenants() as $tenant) {
                    $tenantKeyForRollback = $tenantAdapter->tenantKey($tenant);

                    try {
                        $tenantAdapter->enter($tenant);
                        $this->rollbackTarget($tracking, $discovery, $db, $tenantAdapter, $migrationScope, $tenantKeyForRollback, $steps, $rolledBack, $errors);
                    } finally {
                        $tenantAdapter->leave();
                    }
                }

                continue;
            }

            if ($migrationScope === MigrationScope::Tenant) {
                $this->rollbackSpecificTenant($tracking, $discovery, $db, $tenantAdapter, $targetKey, $steps, $rolledBack, $errors);

                continue;
            }

            $this->rollbackTarget($tracking, $discovery, $db, $tenantAdapter, $migrationScope, $targetKey, $steps, $rolledBack, $errors);
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

    /**
     * @param  list<string>  $rolledBack
     * @param  list<string>  $errors
     */
    private function rollbackTarget(
        TrackingRepository $tracking,
        DiscoveryService $discovery,
        DatabaseManager $db,
        TenantAdapter $tenantAdapter,
        MigrationScope $migrationScope,
        ?string $targetKey,
        int $steps,
        array &$rolledBack,
        array &$errors,
    ): void {
        $lastBatch = $tracking->getLastBatch($migrationScope, $targetKey);
        if ($lastBatch === 0) {
            return;
        }

        $startBatch = max(1, $lastBatch - $steps + 1);
        $discovered = $discovery->discover($migrationScope);

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
    }

    /**
     * @param  list<string>  $rolledBack
     * @param  list<string>  $errors
     */
    private function rollbackSpecificTenant(
        TrackingRepository $tracking,
        DiscoveryService $discovery,
        DatabaseManager $db,
        TenantAdapter $tenantAdapter,
        string $targetKey,
        int $steps,
        array &$rolledBack,
        array &$errors,
    ): void {
        foreach ($tenantAdapter->tenants() as $tenant) {
            if ($tenantAdapter->tenantKey($tenant) !== $targetKey) {
                continue;
            }

            try {
                $tenantAdapter->enter($tenant);
                $this->rollbackTarget($tracking, $discovery, $db, $tenantAdapter, MigrationScope::Tenant, $targetKey, $steps, $rolledBack, $errors);
            } finally {
                $tenantAdapter->leave();
            }

            return;
        }

        $errors[] = 'Tenant not found: '.$targetKey;
    }

    private function failWithMessage(string $message, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode(['error' => $message], JSON_PRETTY_PRINT));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }

    private function isPositiveInteger(mixed $value): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        $stringValue = (string) $value;

        return ctype_digit($stringValue) && (int) $stringValue > 0;
    }

    private function tenantExists(TenantAdapter $tenantAdapter, string $targetKey): bool
    {
        foreach ($tenantAdapter->tenants() as $tenant) {
            if ($tenantAdapter->tenantKey($tenant) === $targetKey) {
                return true;
            }
        }

        return false;
    }
}
