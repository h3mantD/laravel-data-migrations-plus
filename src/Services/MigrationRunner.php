<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Services;

use H3mantd\DataMigrations\Contracts\TenantAdapter;
use H3mantd\DataMigrations\DataMigrationContext;
use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Enums\MigrationStatus;
use H3mantd\DataMigrations\Support\NullTenantAdapter;
use Illuminate\Database\DatabaseManager;
use Throwable;

class MigrationRunner
{
    public function __construct(
        private readonly DiscoveryService $discovery,
        private readonly TrackingRepository $tracking,
        private readonly ChecksumService $checksum,
        private readonly LockService $lock,
        private readonly TenantAdapter $tenantAdapter,
        private readonly DatabaseManager $db,
    ) {}

    /** @var (\Closure(string): void)|null */
    private ?\Closure $onTenantStart = null;

    /**
     * @param  (\Closure(string): void)|null  $onTenantStart
     */
    public function onTenantStart(?\Closure $onTenantStart): self
    {
        $this->onTenantStart = $onTenantStart;

        return $this;
    }

    public function run(
        MigrationScope $scope,
        ?string $targetKey,
        bool $pretend,
        bool $continueOnFailure,
        ?string $specificName = null,
    ): RunResult {
        if (! $this->lock->acquire()) {
            return new RunResult(lockFailed: true);
        }

        try {
            if ($scope === MigrationScope::Tenant && $this->tenantAdapter instanceof NullTenantAdapter) {
                return new RunResult;
            }

            if ($scope === MigrationScope::Tenant && $targetKey === null) {
                return $this->runAllTenants($pretend, $continueOnFailure, $specificName);
            }

            return $this->runScope($scope, $targetKey, $pretend, $continueOnFailure, $specificName);
        } finally {
            $this->lock->release();
        }
    }

    private function runAllTenants(bool $pretend, bool $continueOnFailure, ?string $specificName): RunResult
    {
        /** @var list<string> $allSuccessful */
        $allSuccessful = [];
        /** @var list<string> $allFailed */
        $allFailed = [];
        /** @var list<string> $allPretended */
        $allPretended = [];

        foreach ($this->tenantAdapter->tenants() as $tenant) {
            $this->tenantAdapter->enter($tenant);
            $key = $this->tenantAdapter->tenantKey($tenant);

            if ($this->onTenantStart !== null) {
                ($this->onTenantStart)($key);
            }

            try {
                $result = $this->runScope(MigrationScope::Tenant, $key, $pretend, $continueOnFailure, $specificName);
                $allSuccessful = array_merge($allSuccessful, $result->successful);
                $allFailed = array_merge($allFailed, $result->failed);
                $allPretended = array_merge($allPretended, $result->pretended);

                if (! $continueOnFailure && count($result->failed) > 0) {
                    break;
                }
            } finally {
                $this->tenantAdapter->leave();
            }
        }

        return new RunResult(successful: $allSuccessful, failed: $allFailed, pretended: $allPretended);
    }

    private function runScope(
        MigrationScope $scope,
        ?string $targetKey,
        bool $pretend,
        bool $continueOnFailure,
        ?string $specificName,
    ): RunResult {
        $discovered = $this->discovery->discover($scope);
        $completed = $this->tracking->getCompleted($scope, $targetKey);
        $running = $this->tracking->getAll($scope, $targetKey)
            ->where('status', MigrationStatus::Running->value)
            ->pluck('migration_name');

        /** @var list<string> $successful */
        $successful = [];
        /** @var list<string> $failed */
        $failed = [];
        /** @var list<string> $pretended */
        $pretended = [];
        $batch = $this->tracking->getNextBatch();

        foreach ($discovered as $name => $migration) {
            if ($specificName !== null && $name !== $specificName) {
                continue;
            }

            if ($completed->contains($name) || $running->contains($name)) {
                continue;
            }

            if ($pretend) {
                $pretended[] = $name;

                continue;
            }

            $connectionName = $scope === MigrationScope::Tenant
                ? $this->tenantAdapter->connectionName()
                : $this->defaultConnectionName();

            $connection = $this->db->connection(
                $scope === MigrationScope::Tenant ? $connectionName : null
            );

            $filePath = $this->discovery->getFilePath($name, $scope);
            $checksumValue = $this->computeChecksumSafely($filePath);

            $recordId = $this->tracking->recordStart(
                name: $name,
                scope: $scope,
                targetKey: $targetKey,
                connectionName: $connectionName,
                batch: $batch,
                checksum: $checksumValue,
            );

            $startTime = hrtime(true);

            try {
                $context = new DataMigrationContext(
                    connection: $connection,
                    scope: $scope,
                    targetKey: $targetKey,
                );

                $migration->validate($context);

                if ($migration->transactional) {
                    $connection->transaction(fn () => $migration->up($context));
                } else {
                    $migration->up($context);
                }

                $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
                $this->tracking->recordSuccess($recordId, $durationMs);
                $successful[] = $name;
            } catch (Throwable $e) {
                $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
                $this->tracking->recordFailure($recordId, $e->getMessage(), $durationMs);
                $failed[] = $name;

                if (! $continueOnFailure) {
                    break;
                }
            }
        }

        return new RunResult(successful: $successful, failed: $failed, pretended: $pretended);
    }

    private function defaultConnectionName(): string
    {
        /** @var string $name */
        $name = config('database.default');

        return $name;
    }

    private function computeChecksumSafely(?string $filePath): ?string
    {
        if ($filePath === null) {
            return null;
        }

        try {
            return $this->checksum->compute($filePath);
        } catch (Throwable) {
            return null;
        }
    }
}
