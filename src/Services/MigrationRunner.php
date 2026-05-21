<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Services;

use H3mantd\DataMigrations\Contracts\TenantAdapter;
use H3mantd\DataMigrations\DataMigrationContext;
use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Enums\MigrationStatus;
use H3mantd\DataMigrations\Support\NullTenantAdapter;
use Illuminate\Contracts\Debug\ExceptionHandler;
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
        private readonly ExceptionHandler $exceptions,
    ) {}

    /** @var (\Closure(string): void)|null */
    private ?\Closure $onTenantStart = null;

    /** @var (\Closure(string, Throwable): void)|null */
    private ?\Closure $onMigrationFailure = null;

    /**
     * @param  (\Closure(string): void)|null  $onTenantStart
     */
    public function onTenantStart(?\Closure $onTenantStart): self
    {
        $this->onTenantStart = $onTenantStart;

        return $this;
    }

    /**
     * @param  (\Closure(string, Throwable): void)|null  $onMigrationFailure
     */
    public function onMigrationFailure(?\Closure $onMigrationFailure): self
    {
        $this->onMigrationFailure = $onMigrationFailure;

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

            if ($scope === MigrationScope::Tenant) {
                return $this->runSpecificTenant($targetKey, $pretend, $continueOnFailure, $specificName);
            }

            return $this->runScope($scope, $targetKey, $pretend, $continueOnFailure, $specificName);
        } finally {
            $this->lock->release();
        }
    }

    private function runSpecificTenant(string $targetKey, bool $pretend, bool $continueOnFailure, ?string $specificName): RunResult
    {
        foreach ($this->tenantAdapter->tenants() as $tenant) {
            if ($this->tenantAdapter->tenantKey($tenant) !== $targetKey) {
                continue;
            }

            try {
                $this->tenantAdapter->enter($tenant);

                if ($this->onTenantStart instanceof \Closure) {
                    ($this->onTenantStart)($targetKey);
                }

                return $this->runScope(MigrationScope::Tenant, $targetKey, $pretend, $continueOnFailure, $specificName);
            } finally {
                $this->tenantAdapter->leave();
            }
        }

        return new RunResult;
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
            $key = $this->tenantAdapter->tenantKey($tenant);

            try {
                $this->tenantAdapter->enter($tenant);

                if ($this->onTenantStart instanceof \Closure) {
                    ($this->onTenantStart)($key);
                }

                $result = $this->runScope(MigrationScope::Tenant, $key, $pretend, $continueOnFailure, $specificName);
                $allSuccessful = array_merge($allSuccessful, $result->successful);
                $allFailed = array_merge($allFailed, $result->failed);
                $allPretended = array_merge($allPretended, $result->pretended);

                if (! $continueOnFailure && $result->failed !== []) {
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
        $duplicates = $this->discovery->duplicateNames($scope);
        if ($duplicates !== []) {
            return new RunResult(failed: array_keys($duplicates));
        }

        $discovered = $this->discovery->discover($scope);
        $completed = $this->tracking->getCompleted($scope, $targetKey);
        $retryable = $this->tracking->getRetryable($scope, $targetKey)
            ->pluck('migration_name');
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

        if ($specificName !== null && ! array_key_exists($specificName, $discovered)) {
            return new RunResult(failed: [$specificName]);
        }

        foreach ($discovered as $name => $migration) {
            if ($specificName !== null && $name !== $specificName) {
                continue;
            }

            if ($completed->contains($name)) {
                continue;
            }

            if ($running->contains($name) && ! $retryable->contains($name)) {
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
                $this->tracking->markRolledBack($recordId);
                $failed[] = $name;

                if (! $continueOnFailure) {
                    throw $e;
                }

                $this->exceptions->report($e);

                if ($this->onMigrationFailure instanceof \Closure) {
                    ($this->onMigrationFailure)($name, $e);
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
        if (! (bool) config('data-migrations.checksum.enabled', true)) {
            return null;
        }

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
