<?php

use Illuminate\Support\Facades\Schema;

it('creates the data_migrations table', function () {
    expect(Schema::hasTable('data_migrations'))->toBeTrue();
});

it('has all expected columns', function () {
    $columns = Schema::getColumnListing('data_migrations');
    expect($columns)->toContain(
        'id', 'migration_name', 'scope_type', 'target_key',
        'connection_name', 'batch', 'status', 'checksum',
        'started_at', 'completed_at', 'duration_ms',
        'error_message', 'meta', 'created_at', 'updated_at',
    );
});
