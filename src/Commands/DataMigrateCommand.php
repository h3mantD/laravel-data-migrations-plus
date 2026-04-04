<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Commands;

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\MigrationRunner;
use H3mantd\DataMigrations\Services\RunResult;
use Illuminate\Console\Command;

class DataMigrateCommand extends Command
{
    public $signature = 'data-migrate
        {--scope=all : Scope to run (central, tenant, or all)}
        {--tenant= : Run for a specific tenant only}
        {--name= : Run a specific migration by name}
        {--pretend : Dry run — show what would execute}
        {--force : Required in production}
        {--continue-on-failure : Continue executing after a migration fails}
        {--json : Output results as JSON}';

    public $description = 'Run pending data migrations';

    public function handle(MigrationRunner $runner): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error('Use --force to run in production.');

            return self::FAILURE;
        }

        $scope = $this->option('scope');
        $pretend = (bool) $this->option('pretend');
        $continueOnFailure = (bool) $this->option('continue-on-failure');
        $specificName = $this->option('name');
        $tenantKey = $this->option('tenant');
        $json = (bool) $this->option('json');

        $results = [];

        $scopes = match ($scope) {
            'central' => [MigrationScope::Central],
            'tenant' => [MigrationScope::Tenant],
            default => [MigrationScope::Central, MigrationScope::Tenant],
        };

        if (! $json) {
            $runner->onTenantStart(function (string $key): void {
                $this->components->twoColumnDetail('Tenant: '.$key, '<fg=cyan>RUNNING</>');
            });
        }

        foreach ($scopes as $migrationScope) {
            $targetKey = $migrationScope === MigrationScope::Tenant ? $tenantKey : null;

            /** @var string|null $specificNameStr */
            $specificNameStr = $specificName;

            /** @var string|null $targetKeyStr */
            $targetKeyStr = $targetKey;

            $result = $runner->run(
                scope: $migrationScope,
                targetKey: $targetKeyStr,
                pretend: $pretend,
                continueOnFailure: $continueOnFailure,
                specificName: $specificNameStr,
            );

            if ($result->lockFailed) {
                if ($json) {
                    $this->line((string) json_encode(['error' => 'Could not acquire lock.'], JSON_PRETTY_PRINT));
                } else {
                    $this->components->error('Could not acquire lock. Another migration may be running.');
                }

                return self::FAILURE;
            }

            $results[] = $result;
        }

        if ($json) {
            $this->outputJson($results);
        } else {
            $this->outputConsole($results, $pretend);
        }

        foreach ($results as $result) {
            if ($result->failed !== []) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /** @param  list<RunResult>  $results */
    private function outputJson(array $results): void
    {
        $merged = ['successful' => [], 'failed' => [], 'pretended' => []];
        foreach ($results as $result) {
            $merged['successful'] = array_merge($merged['successful'], $result->successful);
            $merged['failed'] = array_merge($merged['failed'], $result->failed);
            $merged['pretended'] = array_merge($merged['pretended'], $result->pretended);
        }

        $this->line((string) json_encode($merged, JSON_PRETTY_PRINT));
    }

    /** @param  list<RunResult>  $results */
    private function outputConsole(array $results, bool $pretend): void
    {
        $hasOutput = false;
        foreach ($results as $result) {
            if ($pretend && count($result->pretended) > 0) {
                $this->components->info('Would run:');
                foreach ($result->pretended as $name) {
                    $this->components->twoColumnDetail($name, '<fg=yellow;options=bold>PRETEND</>');
                }

                $hasOutput = true;
            }

            foreach ($result->successful as $name) {
                $this->components->twoColumnDetail($name, '<fg=green;options=bold>DONE</>');
                $hasOutput = true;
            }

            foreach ($result->failed as $name) {
                $this->components->twoColumnDetail($name, '<fg=red;options=bold>FAILED</>');
                $hasOutput = true;
            }
        }

        if (! $hasOutput) {
            $this->components->info('Nothing to migrate.');
        }
    }
}
