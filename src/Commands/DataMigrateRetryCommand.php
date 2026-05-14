<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Commands;

use DateTimeInterface;
use H3mantd\DataMigrations\Commands\Concerns\ParsesMigrationScopes;
use H3mantd\DataMigrations\Contracts\TenantAdapter;
use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Enums\MigrationStatus;
use H3mantd\DataMigrations\Services\DiscoveryService;
use H3mantd\DataMigrations\Services\MigrationRunner;
use H3mantd\DataMigrations\Services\TrackingRepository;
use H3mantd\DataMigrations\Support\NullTenantAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use stdClass;

class DataMigrateRetryCommand extends Command
{
    use ParsesMigrationScopes;

    public $signature = 'data-migrate:retry
        {--scope=all : Scope to retry (central, tenant, or all)}
        {--tenant= : Retry for a specific tenant}
        {--force : Required in production}
        {--json : Output as JSON}';

    public $description = 'Retry failed data migrations';

    public function handle(
        TrackingRepository $tracking,
        MigrationRunner $runner,
        DiscoveryService $discovery,
        TenantAdapter $tenantAdapter,
    ): int {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error('Use --force to run in production.');

            return self::FAILURE;
        }

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

        if ($tenantKey !== null && ! $this->tenantExists($tenantAdapter, $tenantKey)) {
            return $this->failWithMessage('Tenant not found: '.$tenantKey, $json);
        }

        if ($this->tenantTrackingConnectionMissing($scopes, $tenantAdapter)) {
            return $this->failWithMessage($this->missingTenantTrackingConnectionMessage(), $json);
        }

        /** @var list<string> $retried */
        $retried = [];
        /** @var list<string> $failedAgain */
        $failedAgain = [];

        foreach ($scopes as $migrationScope) {
            $targetKey = $migrationScope === MigrationScope::Tenant ? $tenantKey : null;
            if ($migrationScope === MigrationScope::Tenant && $tenantAdapter instanceof NullTenantAdapter) {
                continue;
            }

            $failed = $this->failedRecords($tracking, $migrationScope, $targetKey);

            if ($failed->isEmpty()) {
                continue;
            }

            foreach ($failed as $record) {
                /** @var int $recordId */
                $recordId = $record->id;
                /** @var string $migrationName */
                $migrationName = $record->migration_name;

                if ($discovery->getFilePath($migrationName, $migrationScope) === null) {
                    $failedAgain[] = $migrationName;

                    continue;
                }

                /** @var string|null $recordTargetKey */
                $recordTargetKey = $migrationScope === MigrationScope::Tenant ? $record->target_key : null;
                if ($migrationScope === MigrationScope::Tenant && $recordTargetKey !== null && ! $this->tenantExists($tenantAdapter, $recordTargetKey)) {
                    $failedAgain[] = $migrationName;

                    continue;
                }

                $tracking->resetForRetry($recordId);

                $result = $runner->run(
                    scope: $migrationScope,
                    targetKey: $recordTargetKey ?? $targetKey,
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

            return count($failedAgain) > 0 ? self::FAILURE : self::SUCCESS;
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

    /** @return Collection<int, stdClass> */
    private function failedRecords(TrackingRepository $tracking, MigrationScope $scope, ?string $targetKey): Collection
    {
        if ($scope === MigrationScope::Tenant && $targetKey === null) {
            return $tracking->getAllByScope($scope)
                ->filter(fn (stdClass $record): bool => $this->isRetryableRecord($record))
                ->values();
        }

        return $tracking->getRetryable($scope, $targetKey);
    }

    private function isRetryableRecord(stdClass $record): bool
    {
        if ($record->status === MigrationStatus::Failed->value) {
            return true;
        }

        if ($record->status !== MigrationStatus::Running->value || $record->started_at === null) {
            return false;
        }

        if (! is_string($record->started_at) && ! $record->started_at instanceof DateTimeInterface) {
            return false;
        }

        $ttlConfig = config('data-migrations.lock.ttl', 1800);
        $ttl = is_int($ttlConfig) ? $ttlConfig : 1800;

        return Carbon::parse($record->started_at)->lte(now()->subSeconds($ttl));
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
