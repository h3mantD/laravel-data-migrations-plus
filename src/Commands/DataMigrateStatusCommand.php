<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Commands;

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Enums\MigrationStatus;
use H3mantd\DataMigrations\Services\DiscoveryService;
use H3mantd\DataMigrations\Services\TrackingRepository;
use Illuminate\Console\Command;

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

        /** @var list<array{name: string, scope: string, target_key: string|null, status: string, batch: int|null, ran_at: string|null}> $rows */
        $rows = [];

        foreach ($scopes as $migrationScope) {
            $targetKey = $migrationScope === MigrationScope::Tenant ? $tenantKey : null;
            $discovered = $discovery->discover($migrationScope);
            $tracked = $tracking->getAll($migrationScope, $targetKey)->keyBy('migration_name');

            foreach ($discovered as $name => $migration) {
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
                    'scope' => $migrationScope->value,
                    'target_key' => $targetKey,
                    'status' => $status->value,
                    'batch' => $record->batch ?? null,
                    'ran_at' => $record->completed_at ?? null,
                ];
            }

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
                /** @var array{name: string, scope: string, target_key: string|null, status: string, batch: int|null, ran_at: string|null} $row */
                return [
                    $row['name'],
                    $row['scope'],
                    $row['target_key'] ?? '-',
                    match ($row['status']) {
                        'completed' => '<fg=green>'.$row['status'].'</>',
                        'failed' => '<fg=red>'.$row['status'].'</>',
                        'running' => '<fg=yellow>'.$row['status'].'</>',
                        default => $row['status'],
                    },
                    is_scalar($row['batch']) ? (string) $row['batch'] : '-',
                    $row['ran_at'] ?? '-',
                ];
            }, $rows),
        );

        return self::SUCCESS;
    }
}
