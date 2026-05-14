<?php

use H3mantd\DataMigrations\Contracts\TenantAdapter;
use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\TrackingRepository;
use H3mantd\DataMigrations\Tests\Fixtures\InMemoryTenantAdapter;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->centralDir = sys_get_temp_dir().'/dm-retry-test/central';
    @mkdir($this->centralDir, 0755, true);
    config()->set('data-migrations.central_path', $this->centralDir);
    config()->set('data-migrations.tenant_path', sys_get_temp_dir().'/dm-retry-test/tenant');
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->centralDir.'/*'));
    @rmdir($this->centralDir);
    @rmdir(dirname($this->centralDir));
});

it('retries failed migrations', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public bool $transactional = false;
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_was_broken.php', $stub);

    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_was_broken', MigrationScope::Central, null, 'testing', 1, null);
    $repo->recordFailure($id, 'original error', 10);

    $this->artisan('data-migrate:retry', ['--scope' => 'central'])->assertSuccessful();

    $completed = $repo->getCompleted(MigrationScope::Central, null);
    expect($completed)->toContain('2026_04_01_100000_was_broken');
});

it('reports when no failed migrations exist', function (): void {
    $this->artisan('data-migrate:retry', ['--scope' => 'central'])
        ->assertSuccessful()
        ->expectsOutputToContain('No failed');
});

it('outputs json when --json is passed', function (): void {
    $this->artisan('data-migrate:retry', ['--scope' => 'central', '--json' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('"retried"');
});

it('requires --force in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    $this->artisan('data-migrate:retry')->assertFailed();
});

it('does not run unrelated pending migrations during retry', function (): void {
    $stub_good = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public bool $transactional = false;
        public function up(DataMigrationContext $context): void {}
    };
    PHP;

    file_put_contents($this->centralDir.'/2026_04_01_100000_was_broken.php', $stub_good);
    file_put_contents($this->centralDir.'/2026_04_02_100000_unrelated_new.php', $stub_good);

    // Only mark the first as failed
    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_was_broken', MigrationScope::Central, null, 'testing', 1, null);
    $repo->recordFailure($id, 'error', 10);

    $this->artisan('data-migrate:retry', ['--scope' => 'central'])->assertSuccessful();

    // The unrelated new migration should NOT have been run
    $completed = $repo->getCompleted(MigrationScope::Central, null);
    expect($completed)->toContain('2026_04_01_100000_was_broken');
    expect($completed)->not->toContain('2026_04_02_100000_unrelated_new');
});

it('rejects invalid scope', function (): void {
    $this->artisan('data-migrate:retry', ['--scope' => 'bogus'])
        ->assertFailed();
});

it('fails explicit tenant scope when no adapter is configured', function (): void {
    $this->artisan('data-migrate:retry', ['--scope' => 'tenant'])
        ->assertFailed();
});

it('fails tenant option when no adapter is configured', function (): void {
    $this->artisan('data-migrate:retry', ['--tenant' => 'acme-1'])
        ->assertFailed();
});

it('fails when an explicit tenant key does not exist', function (): void {
    config()->set('data-migrations.tenant_adapter', InMemoryTenantAdapter::class);
    app()->instance(TenantAdapter::class, new InMemoryTenantAdapter);
    InMemoryTenantAdapter::$tenants = ['acme-1'];

    $this->artisan('data-migrate:retry', ['--scope' => 'tenant', '--tenant' => 'missing'])
        ->assertFailed()
        ->expectsOutputToContain('Tenant not found: missing');
});

it('retries stale running migrations after the lock ttl', function (): void {
    config()->set('data-migrations.lock.ttl', 60);

    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public bool $transactional = false;
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_stale_running.php', $stub);

    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_stale_running', MigrationScope::Central, null, 'testing', 1, null);
    DB::table('data_migrations')->where('id', $id)->update(['started_at' => now()->subSeconds(61)]);

    $this->artisan('data-migrate:retry', ['--scope' => 'central'])->assertSuccessful();

    expect($repo->getCompleted(MigrationScope::Central, null))->toContain('2026_04_01_100000_stale_running');
});

it('does not retry fresh running migrations before the lock ttl', function (): void {
    config()->set('data-migrations.lock.ttl', 60);

    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public bool $transactional = false;
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_fresh_running.php', $stub);

    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_fresh_running', MigrationScope::Central, null, 'testing', 1, null);
    DB::table('data_migrations')->where('id', $id)->update(['started_at' => now()->subSeconds(30)]);

    $this->artisan('data-migrate:retry', ['--scope' => 'central'])->assertSuccessful();

    expect($repo->getAll(MigrationScope::Central, null)->first()->status)->toBe('running');
});

it('skips tenant retry during default all scope when no adapter is configured', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public bool $transactional = false;
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_was_broken.php', $stub);

    $repo = app(TrackingRepository::class);
    $centralId = $repo->recordStart('2026_04_01_100000_was_broken', MigrationScope::Central, null, 'testing', 1, null);
    $repo->recordFailure($centralId, 'central error', 10);
    $tenantId = $repo->recordStart('2026_04_01_100000_tenant_was_broken', MigrationScope::Tenant, 'acme-1', 'testing', 1, null);
    $repo->recordFailure($tenantId, 'tenant error', 10);

    $this->artisan('data-migrate:retry')->assertSuccessful();

    expect($repo->getCompleted(MigrationScope::Central, null))->toContain('2026_04_01_100000_was_broken');
    expect($repo->getFailed(MigrationScope::Tenant, 'acme-1'))->toHaveCount(1);
});

it('preserves failed record when retry file is missing', function (): void {
    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_missing_file', MigrationScope::Central, null, 'testing', 1, null);
    $repo->recordFailure($id, 'original error', 10);

    $this->artisan('data-migrate:retry', ['--scope' => 'central'])->assertFailed();

    $failed = $repo->getFailed(MigrationScope::Central, null);
    expect($failed)->toHaveCount(1);
    expect($failed->first()->id)->toBe($id);
});

it('retries failed migration in place', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public bool $transactional = false;
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_retry_in_place.php', $stub);

    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_retry_in_place', MigrationScope::Central, null, 'testing', 1, null);
    $repo->recordFailure($id, 'original error', 10);

    $this->artisan('data-migrate:retry', ['--scope' => 'central'])->assertSuccessful();

    $records = $repo->getAll(MigrationScope::Central, null);
    expect($records)->toHaveCount(1);
    expect($records->first()->id)->toBe($id);
    expect($repo->getCompleted(MigrationScope::Central, null))->toContain('2026_04_01_100000_retry_in_place');
});
