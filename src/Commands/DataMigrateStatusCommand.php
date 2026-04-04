<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Commands;

use H3mantd\DataMigrations\DataMigration;
use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Enums\MigrationStatus;
use H3mantd\DataMigrations\Services\DiscoveryService;
use H3mantd\DataMigrations\Services\TrackingRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class DataMigrateStatusCommand extends Command
{
    public $signature = 'data-migrate:status
        {--scope=all : Scope to show (central, tenant, or all)}
        {--tenant= : Filter by tenant}
        {--pending : Show only pending migrations}
        {--failed : Show only failed migrations}
        {--json : Output as JSON}';

    public $description = 'Show the status of data migrations';

    public function handle(DiscoveryService $discovery, TrackingRepository $tracking): int
    {
        /** @var string $scope */
        $scope = $this->option('scope');
        /** @var string|null $tenantKey */
        $tenantKey = $this->option('tenant');
        $showPending = (bool) $this->option('pending');
        $showFailed = (bool) $this->option('failed');
        $json = (bool) $this->option('json');

        $scopes = match ($scope) {
            'central' => [MigrationScope::Central],
            'tenant' => [MigrationScope::Tenant],
            default => [MigrationScope::Central, MigrationScope::Tenant],
        };

        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        foreach ($scopes as $migrationScope) {
            if ($migrationScope === MigrationScope::Tenant && $tenantKey === null) {
                $this->collectTenantStatusAll($discovery, $tracking, $showPending, $showFailed, $rows);
            } else {
                $targetKey = $migrationScope === MigrationScope::Tenant ? $tenantKey : null;
                $this->collectScopedStatus($discovery, $tracking, $migrationScope, $targetKey, $showPending, $showFailed, $rows);
            }
        }

        if ($json) {
            $this->line((string) json_encode(['migrations' => $rows], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->info('No data migrations found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Scope', 'Target', 'Status', 'Batch', 'Ran At'],
            array_map(function (array $row): array {
                $name = is_string($row['name'] ?? null) ? $row['name'] : '-';
                $scope = is_string($row['scope'] ?? null) ? $row['scope'] : '-';
                $target = is_string($row['target_key'] ?? null) ? $row['target_key'] : '-';
                $status = is_string($row['status'] ?? null) ? $row['status'] : '-';
                $batch = is_scalar($row['batch'] ?? null) ? (string) $row['batch'] : '-';
                $ranAt = is_string($row['ran_at'] ?? null) ? $row['ran_at'] : '-';

                return [
                    $name,
                    $scope,
                    $target,
                    match ($status) {
                        'completed' => '<fg=green>'.$status.'</>',
                        'failed' => '<fg=red>'.$status.'</>',
                        'running' => '<fg=yellow>'.$status.'</>',
                        default => $status,
                    },
                    $batch,
                    $ranAt,
                ];
            }, $rows),
        );

        return self::SUCCESS;
    }

    /**
     * Collect status for all tenants when no specific --tenant is given.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function collectTenantStatusAll(
        DiscoveryService $discovery,
        TrackingRepository $tracking,
        bool $showPending,
        bool $showFailed,
        array &$rows,
    ): void {
        $discovered = $discovery->discover(MigrationScope::Tenant);
        $allTracked = $tracking->getAllByScope(MigrationScope::Tenant);

        // Group tracked records by target_key
        $byTenant = $allTracked->groupBy(fn (object $r): string => is_string($r->target_key) ? $r->target_key : '');

        // Show per-tenant status for each tracked tenant
        foreach ($byTenant as $key => $records) {
            $tenantKey = (string) $key;
            $trackedByName = $records->keyBy('migration_name');
            $this->buildRows($discovered, $trackedByName, MigrationScope::Tenant, $tenantKey, $showPending, $showFailed, $rows);

            // Show tracked-but-missing-on-disk (orphaned) per tenant
            if (! $showPending) {
                foreach ($trackedByName as $migName => $record) {
                    if (isset($discovered[$migName])) {
                        continue;
                    }

                    /** @var string $orphanStatus */
                    $orphanStatus = $record->status;
                    $status = MigrationStatus::from($orphanStatus);
                    if ($showFailed && $status !== MigrationStatus::Failed) {
                        continue;
                    }

                    $rows[] = [
                        'name' => (string) $migName,
                        'scope' => MigrationScope::Tenant->value,
                        'target_key' => $tenantKey,
                        'status' => $status->value,
                        'batch' => $record->batch,
                        'ran_at' => $record->completed_at,
                    ];
                }
            }
        }

        // If no tracked records exist, still show discovered migrations as pending
        if ($byTenant->isEmpty() && ! $showFailed) {
            foreach (array_keys($discovered) as $name) {
                $rows[] = [
                    'name' => $name,
                    'scope' => MigrationScope::Tenant->value,
                    'target_key' => null,
                    'status' => MigrationStatus::Pending->value,
                    'batch' => null,
                    'ran_at' => null,
                ];
            }
        }
    }

    /**
     * Collect status for a specific scope and target_key.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function collectScopedStatus(
        DiscoveryService $discovery,
        TrackingRepository $tracking,
        MigrationScope $migrationScope,
        ?string $targetKey,
        bool $showPending,
        bool $showFailed,
        array &$rows,
    ): void {
        $discovered = $discovery->discover($migrationScope);
        $tracked = $tracking->getAll($migrationScope, $targetKey)->keyBy('migration_name');
        $this->buildRows($discovered, $tracked, $migrationScope, $targetKey, $showPending, $showFailed, $rows);

        // Show tracked-but-missing-on-disk (orphaned)
        if (! $showPending) {
            foreach ($tracked as $name => $record) {
                if (isset($discovered[$name])) {
                    continue;
                }

                /** @var string $orphanStatus */
                $orphanStatus = $record->status;
                $status = MigrationStatus::from($orphanStatus);
                if ($showFailed && $status !== MigrationStatus::Failed) {
                    continue;
                }

                $rows[] = [
                    'name' => (string) $name,
                    'scope' => $migrationScope->value,
                    'target_key' => $targetKey,
                    'status' => $status->value,
                    'batch' => $record->batch,
                    'ran_at' => $record->completed_at,
                ];
            }
        }
    }

    /**
     * Build rows from discovered migrations + tracked records.
     *
     * @param  array<string, DataMigration>  $discovered
     * @param  Collection<int|string, \stdClass>  $tracked
     * @param  list<array<string, mixed>>  $rows
     */
    private function buildRows(
        array $discovered,
        Collection $tracked,
        MigrationScope $scope,
        ?string $targetKey,
        bool $showPending,
        bool $showFailed,
        array &$rows,
    ): void {
        foreach (array_keys($discovered) as $name) {
            $record = $tracked->get($name);
            /** @var string|null $recordStatus */
            $recordStatus = $record->status ?? null;
            $status = $recordStatus !== null ? MigrationStatus::from($recordStatus) : MigrationStatus::Pending;

            if ($showPending && $status !== MigrationStatus::Pending) {
                continue;
            }

            if ($showFailed && $status !== MigrationStatus::Failed) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'scope' => $scope->value,
                'target_key' => $targetKey,
                'status' => $status->value,
                'batch' => $record->batch ?? null,
                'ran_at' => $record->completed_at ?? null,
            ];
        }
    }
}
