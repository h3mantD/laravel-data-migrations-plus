<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Commands;

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\ChecksumService;
use H3mantd\DataMigrations\Services\DiscoveryService;
use H3mantd\DataMigrations\Services\TrackingRepository;
use Illuminate\Console\Command;

class DataMigrateVerifyCommand extends Command
{
    public $signature = 'data-migrate:verify
        {--strict : Exit with non-zero code on any warning}';

    public $description = 'Verify integrity of data migrations';

    public function handle(DiscoveryService $discovery, TrackingRepository $tracking, ChecksumService $checksum): int
    {
        $strict = (bool) $this->option('strict');
        $failOnDrift = (bool) config('data-migrations.checksum.fail_on_drift', true);

        /** @var list<string> $errors */
        $errors = [];
        /** @var list<string> $warnings */
        $warnings = [];

        foreach ([MigrationScope::Central, MigrationScope::Tenant] as $scope) {
            $discovered = $discovery->discover($scope);
            // For verify, we check ALL records regardless of target_key
            $tracked = $tracking->getAllByScope($scope);

            foreach ($discovery->duplicateNames($scope) as $migrationName => $paths) {
                $errors[] = sprintf(
                    'Duplicate migration name: %s (%s) exists in: %s.',
                    $migrationName,
                    $scope->value,
                    implode(', ', $paths),
                );
            }

            foreach ($tracked as $record) {
                /** @var string $migrationName */
                $migrationName = $record->migration_name;

                if (! isset($discovered[$migrationName])) {
                    $filePath = $discovery->getFilePath($migrationName, $scope);
                    if ($filePath === null) {
                        $errors[] = sprintf('Missing file: %s (%s) was run but file no longer exists.', $migrationName, $scope->value);
                    }
                }
            }

            if ((bool) config('data-migrations.checksum.enabled', true)) {
                foreach ($tracked as $record) {
                    /** @var string $migrationName */
                    $migrationName = $record->migration_name;
                    /** @var string|null $storedChecksum */
                    $storedChecksum = $record->checksum;

                    if ($storedChecksum === null) {
                        continue;
                    }

                    $filePath = $discovery->getFilePath($migrationName, $scope);
                    if ($filePath === null) {
                        continue;
                    }

                    if ($checksum->hasDrifted($filePath, $storedChecksum)) {
                        $message = sprintf('Checksum drift: %s (%s) has been modified since execution.', $migrationName, $scope->value);
                        if ($failOnDrift) {
                            $errors[] = $message;
                        } else {
                            $warnings[] = $message;
                        }
                    }
                }
            }
        }

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        foreach ($errors as $error) {
            $this->components->error($error);
        }

        if (count($errors) > 0) {
            return self::FAILURE;
        }

        if ($strict && count($warnings) > 0) {
            return self::FAILURE;
        }

        $this->components->info('Verification passed.');

        return self::SUCCESS;
    }
}
