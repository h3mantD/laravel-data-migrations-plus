<?php

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Enums\MigrationStatus;
use H3mantd\DataMigrations\Services\TrackingRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function trackingRepository(): TrackingRepository
{
    return app(TrackingRepository::class);
}

it('records a migration start', function (): void {
    $id = trackingRepository()->recordStart(
        name: '2026_04_01_100000_add_roles',
        scope: MigrationScope::Central,
        targetKey: null,
        connectionName: 'testing',
        batch: 1,
        checksum: 'abc123',
    );
    expect($id)->toBeInt();
    $record = trackingRepository()->getAll(MigrationScope::Central, null)->first();
    expect($record->migration_name)->toBe('2026_04_01_100000_add_roles');
    expect($record->status)->toBe(MigrationStatus::Running->value);
    expect($record->batch)->toBe(1);
    expect($record->checksum)->toBe('abc123');
});

it('records success', function (): void {
    $id = trackingRepository()->recordStart('2026_04_01_100000_add_roles', MigrationScope::Central, null, 'testing', 1, null);
    trackingRepository()->recordSuccess($id, 150);
    $record = trackingRepository()->getAll(MigrationScope::Central, null)->first();
    expect($record->status)->toBe(MigrationStatus::Completed->value);
    expect($record->duration_ms)->toBe(150);
    expect($record->completed_at)->not->toBeNull();
});

it('records completed migrations without a running row', function (): void {
    $id = trackingRepository()->recordCompleted(
        name: '2026_04_01_100000_success_only',
        scope: MigrationScope::Central,
        targetKey: null,
        connectionName: 'testing',
        batch: 1,
        checksum: 'abc123',
        durationMs: 150,
    );

    expect($id)->toBeInt();

    $record = trackingRepository()->getAll(MigrationScope::Central, null)->first();
    expect($record->migration_name)->toBe('2026_04_01_100000_success_only');
    expect($record->status)->toBe(MigrationStatus::Completed->value);
    expect($record->checksum)->toBe('abc123');
    expect($record->duration_ms)->toBe(150);
    expect($record->error_message)->toBeNull();
});

it('completes a legacy failed row in place', function (): void {
    $id = trackingRepository()->recordStart('2026_04_01_100000_legacy_failed', MigrationScope::Central, null, 'testing', 1, null);
    trackingRepository()->recordFailure($id, 'old failure', 25);

    $completedId = trackingRepository()->recordCompleted(
        name: '2026_04_01_100000_legacy_failed',
        scope: MigrationScope::Central,
        targetKey: null,
        connectionName: 'testing',
        batch: 2,
        checksum: 'fixed',
        durationMs: 50,
    );

    expect($completedId)->toBe($id);

    $records = trackingRepository()->getAll(MigrationScope::Central, null);
    expect($records)->toHaveCount(1);

    $record = $records->first();
    expect($record->status)->toBe(MigrationStatus::Completed->value);
    expect($record->batch)->toBe(2);
    expect($record->checksum)->toBe('fixed');
    expect($record->duration_ms)->toBe(50);
    expect($record->error_message)->toBeNull();
});

it('completes a legacy stale running row in place', function (): void {
    $id = trackingRepository()->recordStart('2026_04_01_100000_legacy_running', MigrationScope::Central, null, 'testing', 1, null);
    DB::table('data_migrations')->where('id', $id)->update(['started_at' => now()->subHour()]);

    $completedId = trackingRepository()->recordCompleted(
        name: '2026_04_01_100000_legacy_running',
        scope: MigrationScope::Central,
        targetKey: null,
        connectionName: 'testing',
        batch: 2,
        checksum: null,
        durationMs: 10,
    );

    expect($completedId)->toBe($id);
    expect(trackingRepository()->getAll(MigrationScope::Central, null))->toHaveCount(1);
    expect(trackingRepository()->getCompleted(MigrationScope::Central, null))->toContain('2026_04_01_100000_legacy_running');
});

it('records failure', function (): void {
    $id = trackingRepository()->recordStart('2026_04_01_100000_add_roles', MigrationScope::Central, null, 'testing', 1, null);
    trackingRepository()->recordFailure($id, 'Something broke', 50);
    $record = trackingRepository()->getAll(MigrationScope::Central, null)->first();
    expect($record->status)->toBe(MigrationStatus::Failed->value);
    expect($record->error_message)->toBe('Something broke');
    expect($record->duration_ms)->toBe(50);
});

it('resets a failed migration for retry', function (): void {
    $id = trackingRepository()->recordStart('2026_04_01_100000_add_roles', MigrationScope::Central, null, 'testing', 1, null);
    trackingRepository()->recordFailure($id, 'broken', 10);
    trackingRepository()->resetForRetry($id);

    $record = trackingRepository()->getAll(MigrationScope::Central, null)->first();
    expect($record->status)->toBe(MigrationStatus::Pending->value);
});

it('returns failed migrations', function (): void {
    $id = trackingRepository()->recordStart('2026_04_01_100000_first', MigrationScope::Central, null, 'testing', 1, null);
    trackingRepository()->recordFailure($id, 'error', 10);
    $failed = trackingRepository()->getFailed(MigrationScope::Central, null);
    expect($failed)->toHaveCount(1);
    expect($failed->first()->migration_name)->toBe('2026_04_01_100000_first');
});

it('returns failed and stale running migrations as retryable', function (): void {
    config()->set('data-migrations.lock.ttl', 60);

    $failedId = trackingRepository()->recordStart('2026_04_01_100000_failed', MigrationScope::Central, null, 'testing', 1, null);
    trackingRepository()->recordFailure($failedId, 'error', 10);

    $staleId = trackingRepository()->recordStart('2026_04_01_100000_stale', MigrationScope::Central, null, 'testing', 1, null);
    DB::table('data_migrations')->where('id', $staleId)->update(['started_at' => now()->subSeconds(61)]);

    $freshId = trackingRepository()->recordStart('2026_04_01_100000_fresh', MigrationScope::Central, null, 'testing', 1, null);
    DB::table('data_migrations')->where('id', $freshId)->update(['started_at' => now()->subSeconds(30)]);

    expect(trackingRepository()->getRetryable(MigrationScope::Central, null)->pluck('migration_name')->all())
        ->toBe(['2026_04_01_100000_failed', '2026_04_01_100000_stale']);
});

it('tracks tenant migrations with target_key', function (): void {
    trackingRepository()->recordStart('2026_04_01_100000_backfill', MigrationScope::Tenant, 'acme-1', 'tenant', 1, null);
    trackingRepository()->recordStart('2026_04_01_100000_backfill', MigrationScope::Tenant, 'acme-2', 'tenant', 1, null);

    $acme1 = trackingRepository()->getAll(MigrationScope::Tenant, 'acme-1');
    $acme2 = trackingRepository()->getAll(MigrationScope::Tenant, 'acme-2');
    expect($acme1)->toHaveCount(1);
    expect($acme2)->toHaveCount(1);
});

it('calculates next batch number', function (): void {
    expect(trackingRepository()->getNextBatch())->toBe(1);
    trackingRepository()->recordStart('2026_04_01_100000_first', MigrationScope::Central, null, 'testing', 1, null);
    expect(trackingRepository()->getNextBatch())->toBe(2);
});

it('returns completed migration names', function (): void {
    $id = trackingRepository()->recordStart('2026_04_01_100000_first', MigrationScope::Central, null, 'testing', 1, null);
    trackingRepository()->recordSuccess($id, 100);
    $completed = trackingRepository()->getCompleted(MigrationScope::Central, null);
    expect($completed)->toContain('2026_04_01_100000_first');
});

it('returns migrations by batch', function (): void {
    $id1 = trackingRepository()->recordStart('2026_04_01_100000_a', MigrationScope::Central, null, 'testing', 1, null);
    trackingRepository()->recordSuccess($id1, 100);
    $id2 = trackingRepository()->recordStart('2026_04_02_100000_b', MigrationScope::Central, null, 'testing', 2, null);
    trackingRepository()->recordSuccess($id2, 100);
    $batch1 = trackingRepository()->getByBatch(1, MigrationScope::Central, null);
    expect($batch1)->toHaveCount(1);
    expect($batch1->first()->migration_name)->toBe('2026_04_01_100000_a');
});

it('returns last batch number', function (): void {
    $id1 = trackingRepository()->recordStart('2026_04_01_100000_a', MigrationScope::Central, null, 'testing', 1, null);
    trackingRepository()->recordSuccess($id1, 100);
    $id2 = trackingRepository()->recordStart('2026_04_02_100000_b', MigrationScope::Central, null, 'testing', 3, null);
    trackingRepository()->recordSuccess($id2, 100);
    expect(trackingRepository()->getLastBatch(MigrationScope::Central, null))->toBe(3);
});

it('deletes record on markRolledBack', function (): void {
    $id = trackingRepository()->recordStart('2026_04_01_100000_a', MigrationScope::Central, null, 'testing', 1, null);
    trackingRepository()->recordSuccess($id, 100);
    trackingRepository()->markRolledBack($id);

    expect(trackingRepository()->getAll(MigrationScope::Central, null))->toBeEmpty();
});

it('returns all records by scope regardless of target_key', function (): void {
    trackingRepository()->recordStart('2026_04_01_100000_a', MigrationScope::Tenant, 'acme-1', 'testing', 1, null);
    trackingRepository()->recordStart('2026_04_01_100000_a', MigrationScope::Tenant, 'acme-2', 'testing', 1, null);

    $all = trackingRepository()->getAllByScope(MigrationScope::Tenant);
    expect($all)->toHaveCount(2);
});

it('reuses an existing central migration row without nullable target keys', function (): void {
    $id = trackingRepository()->recordStart('2026_04_01_100000_unique', MigrationScope::Central, null, 'testing', 1, null);

    $secondId = trackingRepository()->recordStart('2026_04_01_100000_unique', MigrationScope::Central, null, 'testing', 2, 'abc123');

    expect($secondId)->toBe($id);

    $records = trackingRepository()->getAll(MigrationScope::Central, null);
    expect($records)->toHaveCount(1);
    expect($records->first()->batch)->toBe(2);
    expect($records->first()->checksum)->toBe('abc123');
});

it('treats legacy null central target keys as completed', function (): void {
    Schema::drop('data_migrations');
    Schema::create('data_migrations', function (Blueprint $table): void {
        $table->id();
        $table->string('migration_name');
        $table->string('scope_type');
        $table->string('target_key')->nullable();
        $table->string('connection_name');
        $table->unsignedInteger('batch');
        $table->string('status');
        $table->string('checksum')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->unsignedInteger('duration_ms')->nullable();
        $table->text('error_message')->nullable();
        $table->timestamps();
    });

    DB::table('data_migrations')->insert([
        'migration_name' => '2026_04_01_100000_legacy',
        'scope_type' => MigrationScope::Central->value,
        'target_key' => null,
        'connection_name' => 'testing',
        'batch' => 1,
        'status' => MigrationStatus::Completed->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(trackingRepository()->getCompleted(MigrationScope::Central, null))->toContain('2026_04_01_100000_legacy');
    expect(trackingRepository()->getAll(MigrationScope::Central, null))->toHaveCount(1);
    expect(trackingRepository()->getLastBatch(MigrationScope::Central, null))->toBe(1);
});
