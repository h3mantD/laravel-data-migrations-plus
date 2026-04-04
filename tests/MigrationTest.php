<?php

use Illuminate\Support\Facades\Schema;

it('creates the data_migrations table', function (): void {
    expect(Schema::hasTable('data_migrations'))->toBeTrue();
});

it('has all expected columns', function (): void {
    $columns = Schema::getColumnListing('data_migrations');
    expect($columns)->toContain(
        'id', 'migration_name', 'scope_type', 'target_key',
        'connection_name', 'batch', 'status', 'checksum',
        'started_at', 'completed_at', 'duration_ms',
        'error_message', 'created_at', 'updated_at',
    );
});
