<?php

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Enums\MigrationStatus;
use H3mantd\DataMigrations\Services\TrackingRepository;

beforeEach(function () {
    $this->repo = app(TrackingRepository::class);
});

it('records a migration start', function () {
    $id = $this->repo->recordStart(
        name: '2026_04_01_100000_add_roles',
        scope: MigrationScope::Central,
        targetKey: null,
        connectionName: 'testing',
        batch: 1,
        checksum: 'abc123',
    );
    expect($id)->toBeInt();
    $record = $this->repo->getAll(MigrationScope::Central, null)->first();
    expect($record->migration_name)->toBe('2026_04_01_100000_add_roles');
    expect($record->status)->toBe(MigrationStatus::Running->value);
    expect($record->batch)->toBe(1);
    expect($record->checksum)->toBe('abc123');
});

it('records success', function () {
    $id = $this->repo->recordStart('2026_04_01_100000_add_roles', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordSuccess($id, 150);
    $record = $this->repo->getAll(MigrationScope::Central, null)->first();
    expect($record->status)->toBe(MigrationStatus::Completed->value);
    expect($record->duration_ms)->toBe(150);
    expect($record->completed_at)->not->toBeNull();
});

it('records failure', function () {
    $id = $this->repo->recordStart('2026_04_01_100000_add_roles', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordFailure($id, 'Something broke', 50);
    $record = $this->repo->getAll(MigrationScope::Central, null)->first();
    expect($record->status)->toBe(MigrationStatus::Failed->value);
    expect($record->error_message)->toBe('Something broke');
    expect($record->duration_ms)->toBe(50);
});

it('resets a failed migration for retry', function () {
    $id = $this->repo->recordStart('2026_04_01_100000_add_roles', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordFailure($id, 'broken', 10);
    $this->repo->resetForRetry($id);
    $record = $this->repo->getAll(MigrationScope::Central, null)->first();
    expect($record->status)->toBe(MigrationStatus::Pending->value);
});

it('returns failed migrations', function () {
    $id = $this->repo->recordStart('2026_04_01_100000_first', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordFailure($id, 'error', 10);
    $failed = $this->repo->getFailed(MigrationScope::Central, null);
    expect($failed)->toHaveCount(1);
    expect($failed->first()->migration_name)->toBe('2026_04_01_100000_first');
});

it('tracks tenant migrations with target_key', function () {
    $this->repo->recordStart('2026_04_01_100000_backfill', MigrationScope::Tenant, 'acme-1', 'tenant', 1, null);
    $this->repo->recordStart('2026_04_01_100000_backfill', MigrationScope::Tenant, 'acme-2', 'tenant', 1, null);
    $acme1 = $this->repo->getAll(MigrationScope::Tenant, 'acme-1');
    $acme2 = $this->repo->getAll(MigrationScope::Tenant, 'acme-2');
    expect($acme1)->toHaveCount(1);
    expect($acme2)->toHaveCount(1);
});

it('calculates next batch number', function () {
    expect($this->repo->getNextBatch())->toBe(1);
    $this->repo->recordStart('2026_04_01_100000_first', MigrationScope::Central, null, 'testing', 1, null);
    expect($this->repo->getNextBatch())->toBe(2);
});

it('returns completed migration names', function () {
    $id = $this->repo->recordStart('2026_04_01_100000_first', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordSuccess($id, 100);
    $completed = $this->repo->getCompleted(MigrationScope::Central, null);
    expect($completed)->toContain('2026_04_01_100000_first');
});

it('returns migrations by batch', function () {
    $id1 = $this->repo->recordStart('2026_04_01_100000_a', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordSuccess($id1, 100);
    $id2 = $this->repo->recordStart('2026_04_02_100000_b', MigrationScope::Central, null, 'testing', 2, null);
    $this->repo->recordSuccess($id2, 100);
    $batch1 = $this->repo->getByBatch(1, MigrationScope::Central, null);
    expect($batch1)->toHaveCount(1);
    expect($batch1->first()->migration_name)->toBe('2026_04_01_100000_a');
});

it('returns last batch number', function () {
    $id1 = $this->repo->recordStart('2026_04_01_100000_a', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordSuccess($id1, 100);
    $id2 = $this->repo->recordStart('2026_04_02_100000_b', MigrationScope::Central, null, 'testing', 3, null);
    $this->repo->recordSuccess($id2, 100);
    expect($this->repo->getLastBatch(MigrationScope::Central, null))->toBe(3);
});

it('deletes record on markRolledBack', function () {
    $id = $this->repo->recordStart('2026_04_01_100000_a', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordSuccess($id, 100);
    $this->repo->markRolledBack($id);
    expect($this->repo->getAll(MigrationScope::Central, null))->toBeEmpty();
});

it('returns all records by scope regardless of target_key', function () {
    $this->repo->recordStart('2026_04_01_100000_a', MigrationScope::Tenant, 'acme-1', 'testing', 1, null);
    $this->repo->recordStart('2026_04_01_100000_a', MigrationScope::Tenant, 'acme-2', 'testing', 1, null);

    $all = $this->repo->getAllByScope(MigrationScope::Tenant);
    expect($all)->toHaveCount(2);
});
