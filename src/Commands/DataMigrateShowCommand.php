<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Commands;

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\ChecksumService;
use H3mantd\DataMigrations\Services\DiscoveryService;
use H3mantd\DataMigrations\Services\TrackingRepository;
use Illuminate\Console\Command;

class DataMigrateShowCommand extends Command
{
    public $signature = 'data-migrate:show
        {name : The migration name to inspect}
        {--json : Output as JSON}';

    public $description = 'Show details of a specific data migration';

    public function handle(
        DiscoveryService $discovery,
        TrackingRepository $tracking,
        ChecksumService $checksum,
    ): int {
        /** @var string $name */
        $name = $this->argument('name');
        $json = (bool) $this->option('json');

        // Find which scope this migration belongs to
        $scope = null;
        $filePath = $discovery->getFilePath($name, MigrationScope::Central);
        if ($filePath !== null) {
            $scope = MigrationScope::Central;
        } else {
            $filePath = $discovery->getFilePath($name, MigrationScope::Tenant);
            if ($filePath !== null) {
                $scope = MigrationScope::Tenant;
            }
        }

        // Get migration instance if file exists
        $migration = null;
        if ($scope !== null) {
            $discovered = $discovery->discover($scope);
            $migration = $discovered[$name] ?? null;
        }

        // Get all tracking records for this migration name
        $records = [];
        foreach ([MigrationScope::Central, MigrationScope::Tenant] as $s) {
            $all = $tracking->getAllByScope($s);
            foreach ($all as $record) {
                if ($record->migration_name === $name) {
                    $records[] = $record;
                }
            }
        }

        if ($migration === null && $records === []) {
            $this->components->error("Migration not found: {$name}");

            return self::FAILURE;
        }

        // Compute current checksum
        $currentChecksum = null;
        if ($filePath !== null) {
            try {
                $currentChecksum = $checksum->compute($filePath);
            } catch (\Throwable) {
                // file might not be readable
            }
        }

        $info = [
            'name' => $name,
            'file' => $filePath,
            'scope' => $scope !== null ? $scope->value : 'unknown',
            'type' => $migration !== null ? $migration->type->value : 'unknown',
            'transactional' => $migration !== null ? $migration->transactional : null,
            'current_checksum' => $currentChecksum,
            'executions' => array_map(fn (object $r): array => [
                'target_key' => $r->target_key,
                'status' => $r->status,
                'batch' => $r->batch,
                'checksum' => $r->checksum,
                'checksum_match' => $currentChecksum !== null && $r->checksum !== null
                    ? ($currentChecksum === $r->checksum ? 'yes' : 'DRIFTED')
                    : null,
                'duration_ms' => $r->duration_ms,
                'started_at' => $r->started_at,
                'completed_at' => $r->completed_at,
                'error_message' => $r->error_message,
            ], $records),
        ];

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
        $this->components->twoColumnDetail('<fg=white;options=bold>Checksum</>', $info['current_checksum'] ? substr($info['current_checksum'], 0, 16).'...' : '-');

        if ($info['executions'] === []) {
            $this->newLine();
            $this->components->info('No execution records found (migration has not been run).');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info('Execution History:');
        $this->table(
            ['Target', 'Status', 'Batch', 'Duration', 'Checksum', 'Ran At', 'Error'],
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
                is_string($exec['checksum_match']) ? $exec['checksum_match'] : '-',
                is_string($exec['completed_at']) ? $exec['completed_at'] : '-',
                is_string($exec['error_message']) ? substr($exec['error_message'], 0, 40).'...' : '-',
            ], $info['executions']),
        );

        return self::SUCCESS;
    }
}
