<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Commands;

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\MigrationRunner;
use H3mantd\DataMigrations\Services\TrackingRepository;
use Illuminate\Console\Command;

class DataMigrateRetryCommand extends Command
{
    public $signature = 'data-migrate:retry
        {--scope=all : Scope to retry (central, tenant, or all)}
        {--tenant= : Retry for a specific tenant}
        {--force : Required in production}
        {--json : Output as JSON}';

    public $description = 'Retry failed data migrations';

    public function handle(TrackingRepository $tracking, MigrationRunner $runner): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error('Use --force to run in production.');

            return self::FAILURE;
        }

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

        /** @var list<string> $retried */
        $retried = [];
        /** @var list<string> $failedAgain */
        $failedAgain = [];

        foreach ($scopes as $migrationScope) {
            $targetKey = $migrationScope === MigrationScope::Tenant ? $tenantKey : null;
            $failed = $tracking->getFailed($migrationScope, $targetKey);

            if ($failed->isEmpty()) {
                continue;
            }

            foreach ($failed as $record) {
                /** @var int $recordId */
                $recordId = $record->id;
                /** @var string $migrationName */
                $migrationName = $record->migration_name;

                // Delete the failed record so the runner can insert a fresh one
                $tracking->markRolledBack($recordId);

                $result = $runner->run(
                    scope: $migrationScope,
                    targetKey: $targetKey,
                    pretend: false,
                    continueOnFailure: true,
                    specificName: $migrationName,
                );

                $retried = array_merge($retried, $result->successful);
                $failedAgain = array_merge($failedAgain, $result->failed);
            }
        }

        if ($json) {
            $this->line((string) json_encode(['retried' => $retried, 'failed' => $failedAgain], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (empty($retried) && empty($failedAgain)) {
            $this->components->info('No failed migrations to retry.');

            return self::SUCCESS;
        }

        foreach ($retried as $name) {
            $this->components->twoColumnDetail($name, '<fg=green;options=bold>RETRIED</>');
        }
        foreach ($failedAgain as $name) {
            $this->components->twoColumnDetail($name, '<fg=red;options=bold>FAILED AGAIN</>');
        }

        return count($failedAgain) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
