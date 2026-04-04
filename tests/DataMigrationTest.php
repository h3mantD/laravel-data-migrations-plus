<?php

use H3mantd\DataMigrations\DataMigration;
use H3mantd\DataMigrations\DataMigrationContext;
use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Enums\MigrationType;

it('has default type of bootstrap', function () {
    $migration = new class extends DataMigration
    {
        public function up(DataMigrationContext $context): void {}
    };
    expect($migration->type)->toBe(MigrationType::Bootstrap);
});

it('is transactional by default', function () {
    $migration = new class extends DataMigration
    {
        public function up(DataMigrationContext $context): void {}
    };
    expect($migration->transactional)->toBeTrue();
});

it('throws LogicException on down() by default', function () {
    $migration = new class extends DataMigration
    {
        public function up(DataMigrationContext $context): void {}
    };
    $connection = $this->app->make('db')->connection();
    $context = new DataMigrationContext(
        connection: $connection,
        scope: MigrationScope::Central,
        targetKey: null,
        pretend: false,
    );
    $migration->down($context);
})->throws(LogicException::class, 'This data migration is irreversible.');

it('validate() does nothing by default', function () {
    $migration = new class extends DataMigration
    {
        public function up(DataMigrationContext $context): void {}
    };
    $connection = $this->app->make('db')->connection();
    $context = new DataMigrationContext(
        connection: $connection,
        scope: MigrationScope::Central,
        targetKey: null,
        pretend: false,
    );
    $migration->validate($context);
    expect(true)->toBeTrue();
});
