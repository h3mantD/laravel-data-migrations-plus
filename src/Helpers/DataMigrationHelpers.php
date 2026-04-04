<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Helpers;

use Illuminate\Database\Connection;

class DataMigrationHelpers
{
    public function __construct(
        private readonly Connection $connection,
    ) {}
}
