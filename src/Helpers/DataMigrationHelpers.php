<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Helpers;

use Illuminate\Database\Connection;

class DataMigrationHelpers
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public function ensureRecord(string $table, array $attributes, array $values = []): void
    {
        $exists = $this->connection->table($table)->where($attributes)->exists();
        if (! $exists) {
            $this->connection->table($table)->insert(array_merge($attributes, $values));
        }
    }

    /** @param array<string, mixed> $where */
    public function updateWhereNull(string $table, string $column, mixed $value, array $where = []): void
    {
        $query = $this->connection->table($table)->whereNull($column);
        foreach ($where as $key => $val) {
            $query->where($key, $val);
        }

        $query->update([$column => $value]);
    }

    /** @param array<string, string> $mapping */
    public function normalizeColumn(string $table, string $column, array $mapping): void
    {
        foreach ($mapping as $oldValue => $newValue) {
            $this->connection->table($table)
                ->where($column, $oldValue)
                ->update([$column => $newValue]);
        }
    }
}
