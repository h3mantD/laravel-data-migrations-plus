<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations;

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Helpers\DataMigrationHelpers;
use Illuminate\Database\Connection;

class DataMigrationContext
{
    private ?DataMigrationHelpers $helpers = null;

    public function __construct(
        public readonly Connection $connection,
        public readonly MigrationScope $scope,
        public readonly ?string $targetKey,
        public readonly bool $pretend,
    ) {}

    public function helpers(): DataMigrationHelpers
    {
        return $this->helpers ??= new DataMigrationHelpers($this->connection);
    }
}
