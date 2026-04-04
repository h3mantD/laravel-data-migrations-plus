<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations;

use H3mantd\DataMigrations\Enums\MigrationType;
use LogicException;

abstract class DataMigration
{
    public MigrationType $type = MigrationType::Bootstrap;

    public bool $transactional = true;

    public function validate(DataMigrationContext $context): void {}

    abstract public function up(DataMigrationContext $context): void;

    public function down(DataMigrationContext $context): void
    {
        throw new LogicException('This data migration is irreversible.');
    }
}
