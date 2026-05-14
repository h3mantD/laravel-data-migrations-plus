<?php

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Enums\MigrationStatus;
use H3mantd\DataMigrations\Services\TrackingRepository;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->repo = app(TrackingRepository::class);
});

it('records a migration start', function (): void {
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

it('records success', function (): void {
    $id = $this->repo->recordStart('2026_04_01_100000_add_roles', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordSuccess($id, 150);
    $record = $this->repo->getAll(MigrationScope::Central, null)->first();
    expect($record->status)->toBe(MigrationStatus::Completed->value);
    expect($record->duration_ms)->toBe(150);
    expect($record->completed_at)->not->toBeNull();
});

it('records failure', function (): void {
    $id = $this->repo->recordStart('2026_04_01_100000_add_roles', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordFailure($id, 'Something broke', 50);
    $record = $this->repo->getAll(MigrationScope::Central, null)->first();
    expect($record->status)->toBe(MigrationStatus::Failed->value);
    expect($record->error_message)->toBe('Something broke');
    expect($record->duration_ms)->toBe(50);
});

it('resets a failed migration for retry', function (): void {
    $id = $this->repo->recordStart('2026_04_01_100000_add_roles', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordFailure($id, 'broken', 10);
    $this->repo->resetForRetry($id);

    $record = $this->repo->getAll(MigrationScope::Central, null)->first();
    expect($record->status)->toBe(MigrationStatus::Pending->value);
});

it('returns failed migrations', function (): void {
    $id = $this->repo->recordStart('2026_04_01_100000_first', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordFailure($id, 'error', 10);
    $failed = $this->repo->getFailed(MigrationScope::Central, null);
    expect($failed)->toHaveCount(1);
    expect($failed->first()->migration_name)->toBe('2026_04_01_100000_first');
});

it('returns failed and stale running migrations as retryable', function (): void {
    config()->set('data-migrations.lock.ttl', 60);

    $failedId = $this->repo->recordStart('2026_04_01_100000_failed', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordFailure($failedId, 'error', 10);

    $staleId = $this->repo->recordStart('2026_04_01_100000_stale', MigrationScope::Central, null, 'testing', 1, null);
    DB::table('data_migrations')->where('id', $staleId)->update(['started_at' => now()->subSeconds(61)]);

    $freshId = $this->repo->recordStart('2026_04_01_100000_fresh', MigrationScope::Central, null, 'testing', 1, null);
    DB::table('data_migrations')->where('id', $freshId)->update(['started_at' => now()->subSeconds(30)]);

    expect($this->repo->getRetryable(MigrationScope::Central, null)->pluck('migration_name')->all())
        ->toBe(['2026_04_01_100000_failed', '2026_04_01_100000_stale']);
});

it('tracks tenant migrations with target_key', function (): void {
    $this->repo->recordStart('2026_04_01_100000_backfill', MigrationScope::Tenant, 'acme-1', 'tenant', 1, null);
    $this->repo->recordStart('2026_04_01_100000_backfill', MigrationScope::Tenant, 'acme-2', 'tenant', 1, null);

    $acme1 = $this->repo->getAll(MigrationScope::Tenant, 'acme-1');
    $acme2 = $this->repo->getAll(MigrationScope::Tenant, 'acme-2');
    expect($acme1)->toHaveCount(1);
    expect($acme2)->toHaveCount(1);
});

it('calculates next batch number', function (): void {
    expect($this->repo->getNextBatch())->toBe(1);
    $this->repo->recordStart('2026_04_01_100000_first', MigrationScope::Central, null, 'testing', 1, null);
    expect($this->repo->getNextBatch())->toBe(2);
});

it('returns completed migration names', function (): void {
    $id = $this->repo->recordStart('2026_04_01_100000_first', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordSuccess($id, 100);
    $completed = $this->repo->getCompleted(MigrationScope::Central, null);
    expect($completed)->toContain('2026_04_01_100000_first');
});

it('returns migrations by batch', function (): void {
    $id1 = $this->repo->recordStart('2026_04_01_100000_a', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordSuccess($id1, 100);
    $id2 = $this->repo->recordStart('2026_04_02_100000_b', MigrationScope::Central, null, 'testing', 2, null);
    $this->repo->recordSuccess($id2, 100);
    $batch1 = $this->repo->getByBatch(1, MigrationScope::Central, null);
    expect($batch1)->toHaveCount(1);
    expect($batch1->first()->migration_name)->toBe('2026_04_01_100000_a');
});

it('returns last batch number', function (): void {
    $id1 = $this->repo->recordStart('2026_04_01_100000_a', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordSuccess($id1, 100);
    $id2 = $this->repo->recordStart('2026_04_02_100000_b', MigrationScope::Central, null, 'testing', 3, null);
    $this->repo->recordSuccess($id2, 100);
    expect($this->repo->getLastBatch(MigrationScope::Central, null))->toBe(3);
});

it('deletes record on markRolledBack', function (): void {
    $id = $this->repo->recordStart('2026_04_01_100000_a', MigrationScope::Central, null, 'testing', 1, null);
    $this->repo->recordSuccess($id, 100);
    $this->repo->markRolledBack($id);

    expect($this->repo->getAll(MigrationScope::Central, null))->toBeEmpty();
});

it('returns all records by scope regardless of target_key', function (): void {
    $this->repo->recordStart('2026_04_01_100000_a', MigrationScope::Tenant, 'acme-1', 'testing', 1, null);
    $this->repo->recordStart('2026_04_01_100000_a', MigrationScope::Tenant, 'acme-2', 'testing', 1, null);

    $all = $this->repo->getAllByScope(MigrationScope::Tenant);
    expect($all)->toHaveCount(2);
});

it('enforces central migration uniqueness without nullable target keys', function (): void {
    $this->repo->recordStart('2026_04_01_100000_unique', MigrationScope::Central, null, 'testing', 1, null);

    $this->repo->recordStart('2026_04_01_100000_unique', MigrationScope::Central, null, 'testing', 1, null);
})->throws(QueryException::class);

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

    expect($this->repo->getCompleted(MigrationScope::Central, null))->toContain('2026_04_01_100000_legacy');
    expect($this->repo->getAll(MigrationScope::Central, null))->toHaveCount(1);
    expect($this->repo->getLastBatch(MigrationScope::Central, null))->toBe(1);
});
