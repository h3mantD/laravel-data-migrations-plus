<?php

use H3mantd\DataMigrations\DataMigrationContext;
use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Helpers\DataMigrationHelpers;
use Illuminate\Database\Connection;

it('exposes all properties', function () {
    $connection = $this->app->make('db')->connection();
    $context = new DataMigrationContext(
        connection: $connection,
        scope: MigrationScope::Central,
        targetKey: null,
        pretend: false,
    );
    expect($context->connection)->toBeInstanceOf(Connection::class);
    expect($context->scope)->toBe(MigrationScope::Central);
    expect($context->targetKey)->toBeNull();
    expect($context->pretend)->toBeFalse();
});

it('exposes tenant properties', function () {
    $connection = $this->app->make('db')->connection();
    $context = new DataMigrationContext(
        connection: $connection,
        scope: MigrationScope::Tenant,
        targetKey: 'acme-1',
        pretend: true,
    );
    expect($context->scope)->toBe(MigrationScope::Tenant);
    expect($context->targetKey)->toBe('acme-1');
    expect($context->pretend)->toBeTrue();
});

it('returns helpers instance', function () {
    $connection = $this->app->make('db')->connection();
    $context = new DataMigrationContext(
        connection: $connection,
        scope: MigrationScope::Central,
        targetKey: null,
        pretend: false,
    );
    expect($context->helpers())->toBeInstanceOf(DataMigrationHelpers::class);
});
