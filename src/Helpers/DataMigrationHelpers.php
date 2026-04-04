<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Helpers;

use Illuminate\Database\Connection;

class DataMigrationHelpers
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function ensureRecord(string $table, array $attributes, array $values = []): void
    {
        $exists = $this->connection->table($table)->where($attributes)->exists();
        if (! $exists) {
            $this->connection->table($table)->insert(array_merge($attributes, $values));
        }
    }

    public function updateWhereNull(string $table, string $column, mixed $value, array $where = []): void
    {
        $query = $this->connection->table($table)->whereNull($column);
        foreach ($where as $key => $val) {
            $query->where($key, $val);
        }
        $query->update([$column => $value]);
    }

    public function normalizeColumn(string $table, string $column, array $mapping): void
    {
        foreach ($mapping as $oldValue => $newValue) {
            $this->connection->table($table)
                ->where($column, $oldValue)
                ->update([$column => $newValue]);
        }
    }
}
