<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Commands;

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\ChecksumService;
use H3mantd\DataMigrations\Services\DiscoveryService;
use H3mantd\DataMigrations\Services\TrackingRepository;
use Illuminate\Console\Command;
use stdClass;

class DataMigrateShowCommand extends Command
{
    public $signature = 'data-migrate:show
        {name : The migration name to inspect}
        {--scope= : Scope to inspect (central or tenant)}
        {--json : Output as JSON}';

    public $description = 'Show details of a specific data migration';

    public function handle(
        DiscoveryService $discovery,
        TrackingRepository $tracking,
        ChecksumService $checksum,
    ): int {
        /** @var string $name */
        $name = $this->argument('name');
        /** @var string|null $scopeOption */
        $scopeOption = $this->option('scope');
        $json = (bool) $this->option('json');
        $checksumEnabled = (bool) config('data-migrations.checksum.enabled', true);

        $scope = $this->resolveRequestedScope($scopeOption);
        if ($scopeOption !== null && ! $scope instanceof MigrationScope) {
            return $this->failWithMessage(sprintf('Invalid scope: %s. Valid scopes: central, tenant', $scopeOption), $json);
        }

        $centralFilePath = $discovery->getFilePath($name, MigrationScope::Central);
        $tenantFilePath = $discovery->getFilePath($name, MigrationScope::Tenant);

        if (! $scope instanceof MigrationScope && $centralFilePath !== null && $tenantFilePath !== null) {
            return $this->failWithMessage('Migration name is ambiguous across scopes. Use --scope=central or --scope=tenant.', $json);
        }

        if (! $scope instanceof MigrationScope) {
            $scope = $centralFilePath !== null ? MigrationScope::Central : null;
            $scope ??= $tenantFilePath !== null ? MigrationScope::Tenant : null;
        }

        $filePath = match ($scope) {
            MigrationScope::Central => $centralFilePath,
            MigrationScope::Tenant => $tenantFilePath,
            null => null,
        };

        // Get migration instance if file exists
        $migration = null;
        if ($scope !== null) {
            $discovered = $discovery->discover($scope);
            $migration = $discovered[$name] ?? null;
        }

        // Get all tracking records for this migration name
        /** @var list<stdClass> $records */
        $records = [];
        $recordScopes = $scope !== null ? [$scope] : [MigrationScope::Central, MigrationScope::Tenant];
        foreach ($recordScopes as $recordScope) {
            $all = $tracking->getAllByScope($recordScope);
            foreach ($all as $record) {
                if ($record->migration_name === $name) {
                    $records[] = $record;
                }
            }
        }

        if ($migration === null && $records === []) {
            return $this->failWithMessage('Migration not found: '.$name, $json);
        }

        $currentChecksum = null;
        if ($checksumEnabled && $filePath !== null) {
            $currentChecksum = $this->currentChecksum($checksum, $filePath);
        }

        $info = [
            'name' => $name,
            'file' => $filePath,
            'scope' => $scope !== null ? $scope->value : 'unknown',
            'type' => $migration !== null ? $migration->type->value : 'unknown',
            'transactional' => $migration !== null ? $migration->transactional : null,
            'executions' => array_map(
                fn (object $record): array => $this->executionInfo($record, $checksumEnabled, $currentChecksum),
                $records,
            ),
        ];

        if ($checksumEnabled) {
            $info['current_checksum'] = $currentChecksum;
        }

        if ($json) {
            $this->line((string) json_encode($info, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=white;options=bold>Name</>', $info['name']);
        $this->components->twoColumnDetail('<fg=white;options=bold>File</>', $info['file'] ?? '<fg=red>NOT FOUND</>');
        $this->components->twoColumnDetail('<fg=white;options=bold>Scope</>', $info['scope']);
        $this->components->twoColumnDetail('<fg=white;options=bold>Type</>', $info['type']);
        $this->components->twoColumnDetail('<fg=white;options=bold>Transactional</>', $info['transactional'] === null ? '-' : ($info['transactional'] ? 'yes' : 'no'));
        if ($checksumEnabled) {
            $this->components->twoColumnDetail('<fg=white;options=bold>Checksum</>', $currentChecksum ? substr($currentChecksum, 0, 16).'...' : '-');
        }

        if ($info['executions'] === []) {
            $this->newLine();
            $this->components->info('No execution records found (migration has not been run).');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info('Execution History:');
        $headers = $checksumEnabled
            ? ['Target', 'Status', 'Batch', 'Duration', 'Checksum', 'Ran At', 'Error']
            : ['Target', 'Status', 'Batch', 'Duration', 'Ran At', 'Error'];

        $this->table(
            $headers,
            array_map(fn (array $exec): array => [
                $exec['target_key'] ?? '-',
                match ($exec['status']) {
                    'completed' => '<fg=green>'.$exec['status'].'</>',
                    'failed' => '<fg=red>'.$exec['status'].'</>',
                    'running' => '<fg=yellow>'.$exec['status'].'</>',
                    default => $exec['status'],
                },
                is_scalar($exec['batch']) ? (string) $exec['batch'] : '-',
                is_numeric($exec['duration_ms']) ? $exec['duration_ms'].'ms' : '-',
                ...($checksumEnabled ? [is_string($exec['checksum_match']) ? $exec['checksum_match'] : '-'] : []),
                is_string($exec['completed_at']) ? $exec['completed_at'] : '-',
                is_string($exec['error_message']) ? substr($exec['error_message'], 0, 40).'...' : '-',
            ], $info['executions']),
        );

        return self::SUCCESS;
    }

    private function resolveRequestedScope(?string $scope): ?MigrationScope
    {
        return match ($scope) {
            'central' => MigrationScope::Central,
            'tenant' => MigrationScope::Tenant,
            null => null,
            default => null,
        };
    }

    private function currentChecksum(ChecksumService $checksum, string $filePath): ?string
    {
        try {
            return $checksum->compute($filePath);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function executionInfo(stdClass $record, bool $checksumEnabled, ?string $currentChecksum): array
    {
        $info = [
            'target_key' => $record->target_key,
            'status' => $record->status,
            'batch' => $record->batch,
            'duration_ms' => $record->duration_ms,
            'started_at' => $record->started_at,
            'completed_at' => $record->completed_at,
            'error_message' => $record->error_message,
        ];

        if (! $checksumEnabled) {
            return $info;
        }

        $info['checksum'] = $record->checksum;
        $info['checksum_match'] = $currentChecksum !== null && $record->checksum !== null
            ? ($currentChecksum === $record->checksum ? 'yes' : 'DRIFTED')
            : null;

        return $info;
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
}
