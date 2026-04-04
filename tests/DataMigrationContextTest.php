<?php

use H3mantd\DataMigrations\DataMigrationContext;
use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Helpers\DataMigrationHelpers;
use Illuminate\Database\Connection;

it('exposes all properties', function (): void {
    $connection = $this->app->make('db')->connection();
    $context = new DataMigrationContext(
        connection: $connection,
        scope: MigrationScope::Central,
        targetKey: null,
    );
    expect($context->connection)->toBeInstanceOf(Connection::class);
    expect($context->scope)->toBe(MigrationScope::Central);
    expect($context->targetKey)->toBeNull();
});

it('exposes tenant properties', function (): void {
    $connection = $this->app->make('db')->connection();
    $context = new DataMigrationContext(
        connection: $connection,
        scope: MigrationScope::Tenant,
        targetKey: 'acme-1',
    );
    expect($context->scope)->toBe(MigrationScope::Tenant);
    expect($context->targetKey)->toBe('acme-1');
});

it('returns helpers instance', function (): void {
    $connection = $this->app->make('db')->connection();
    $context = new DataMigrationContext(
        connection: $connection,
        scope: MigrationScope::Central,
        targetKey: null,
    );
    expect($context->helpers())->toBeInstanceOf(DataMigrationHelpers::class);
});
