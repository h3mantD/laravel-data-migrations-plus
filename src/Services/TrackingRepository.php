<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Services;

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Enums\MigrationStatus;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use stdClass;

class TrackingRepository
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function recordCompleted(
        string $name,
        MigrationScope $scope,
        ?string $targetKey,
        string $connectionName,
        int $batch,
        ?string $checksum,
        int $durationMs,
    ): int {
        $existing = $this->scopedQuery($scope, $targetKey)
            ->where('migration_name', $name)
            ->first();

        $attributes = [
            'migration_name' => $name,
            'scope_type' => $scope->value,
            'target_key' => $this->storedTargetKey($targetKey),
            'connection_name' => $connectionName,
            'batch' => $batch,
            'status' => MigrationStatus::Completed->value,
            'checksum' => $checksum,
            'started_at' => now()->subMilliseconds($durationMs),
            'completed_at' => now(),
            'duration_ms' => $durationMs,
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($existing !== null) {
            /** @var int $id */
            $id = $existing->id;

            unset($attributes['migration_name'], $attributes['scope_type'], $attributes['target_key'], $attributes['created_at']);

            $this->connection()->table($this->table())->where('id', $id)->update($attributes);

            return $id;
        }

        return $this->connection()->table($this->table())->insertGetId($attributes);
    }

    public function recordStart(
        string $name,
        MigrationScope $scope,
        ?string $targetKey,
        string $connectionName,
        int $batch,
        ?string $checksum,
    ): int {
        $existing = $this->scopedQuery($scope, $targetKey)
            ->where('migration_name', $name)
            ->first();

        if ($existing !== null) {
            /** @var int $id */
            $id = $existing->id;

            $this->connection()->table($this->table())->where('id', $id)->update([
                'connection_name' => $connectionName,
                'batch' => $batch,
                'status' => MigrationStatus::Running->value,
                'checksum' => $checksum,
                'error_message' => null,
                'started_at' => now(),
                'completed_at' => null,
                'duration_ms' => null,
                'updated_at' => now(),
            ]);

            return $id;
        }

        return $this->connection()->table($this->table())->insertGetId([
            'migration_name' => $name,
            'scope_type' => $scope->value,
            'target_key' => $this->storedTargetKey($targetKey),
            'connection_name' => $connectionName,
            'batch' => $batch,
            'status' => MigrationStatus::Running->value,
            'checksum' => $checksum,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function recordSuccess(int $id, int $durationMs): void
    {
        $this->connection()->table($this->table())->where('id', $id)->update([
            'status' => MigrationStatus::Completed->value,
            'duration_ms' => $durationMs,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function recordFailure(int $id, string $errorMessage, int $durationMs): void
    {
        $this->connection()->table($this->table())->where('id', $id)->update([
            'status' => MigrationStatus::Failed->value,
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function resetForRetry(int $id): void
    {
        $this->connection()->table($this->table())->where('id', $id)->update([
            'status' => MigrationStatus::Pending->value,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
            'duration_ms' => null,
            'updated_at' => now(),
        ]);
    }

    /** @return Collection<int, stdClass> */
    public function getFailed(MigrationScope $scope, ?string $targetKey): Collection
    {
        return $this->scopedQuery($scope, $targetKey)
            ->where('status', MigrationStatus::Failed->value)
            ->get();
    }

    /** @return Collection<int, stdClass> */
    public function getRetryable(MigrationScope $scope, ?string $targetKey): Collection
    {
        $ttlConfig = config('data-migrations.lock.ttl', 1800);
        $ttl = is_int($ttlConfig) ? $ttlConfig : 1800;

        return $this->scopedQuery($scope, $targetKey)
            ->where(function (Builder $query) use ($ttl): void {
                $query->where('status', MigrationStatus::Failed->value)
                    ->orWhere(function (Builder $query) use ($ttl): void {
                        $query->where('status', MigrationStatus::Running->value)
                            ->where('started_at', '<=', now()->subSeconds($ttl));
                    });
            })
            ->get();
    }

    /** @return Collection<int|string, mixed> */
    public function getCompleted(MigrationScope $scope, ?string $targetKey): Collection
    {
        return $this->scopedQuery($scope, $targetKey)
            ->where('status', MigrationStatus::Completed->value)
            ->pluck('migration_name');
    }

    public function getNextBatch(): int
    {
        $max = $this->connection()->table($this->table())->max('batch');

        return is_numeric($max) ? ((int) $max) + 1 : 1;
    }

    /** @return Collection<int, stdClass> */
    public function getAll(MigrationScope $scope, ?string $targetKey): Collection
    {
        return $this->scopedQuery($scope, $targetKey)->orderBy('id')->get();
    }

    /** @return Collection<int, stdClass> */
    public function getAllByScope(MigrationScope $scope): Collection
    {
        return $this->connection()->table($this->table())
            ->where('scope_type', $scope->value)
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, stdClass> */
    public function getByBatch(int $batch, MigrationScope $scope, ?string $targetKey): Collection
    {
        return $this->scopedQuery($scope, $targetKey)
            ->where('batch', $batch)
            ->where('status', MigrationStatus::Completed->value)
            ->orderByDesc('id')
            ->get();
    }

    public function getLastBatch(MigrationScope $scope, ?string $targetKey): int
    {
        $max = $this->scopedQuery($scope, $targetKey)
            ->where('status', MigrationStatus::Completed->value)
            ->max('batch');

        return is_numeric($max) ? (int) $max : 0;
    }

    public function markRolledBack(int $id): void
    {
        $this->connection()->table($this->table())->where('id', $id)->delete();
    }

    private function scopedQuery(MigrationScope $scope, ?string $targetKey): Builder
    {
        $query = $this->connection()->table($this->table())
            ->where('scope_type', $scope->value);

        if ($scope === MigrationScope::Central && $targetKey === null) {
            $query->where(function (Builder $query): void {
                $query->where('target_key', '')->orWhereNull('target_key');
            });

            return $query;
        }

        $query->where('target_key', $this->storedTargetKey($targetKey));

        return $query;
    }

    private function storedTargetKey(?string $targetKey): string
    {
        return $targetKey ?? '';
    }

    private function connection(): ConnectionInterface
    {
        /** @var string|null $connectionName */
        $connectionName = config('data-migrations.connection');

        return $this->db->connection($connectionName);
    }

    private function table(): string
    {
        /** @var string $tableName */
        $tableName = config('data-migrations.table', 'data_migrations');

        return $tableName;
    }
}
