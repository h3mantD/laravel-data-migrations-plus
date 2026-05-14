<?php

use H3mantd\DataMigrations\Contracts\TenantAdapter;
use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\TrackingRepository;
use H3mantd\DataMigrations\Tests\Fixtures\InMemoryTenantAdapter;

beforeEach(function (): void {
    $this->centralDir = sys_get_temp_dir().'/dm-cmd-test/central';
    $this->tenantDir = sys_get_temp_dir().'/dm-cmd-test/tenant';
    @mkdir($this->centralDir, 0755, true);
    @mkdir($this->tenantDir, 0755, true);
    config()->set('data-migrations.central_path', $this->centralDir);
    config()->set('data-migrations.tenant_path', $this->tenantDir);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->centralDir.'/*'));
    array_map(unlink(...), glob($this->tenantDir.'/*'));
    @rmdir($this->centralDir);
    @rmdir($this->tenantDir);
    @rmdir(dirname($this->centralDir));
});

it('runs pending central migrations', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public bool $transactional = false;
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_test_migration.php', $stub);

    $this->artisan('data-migrate', ['--scope' => 'central'])->assertSuccessful();

    $repo = app(TrackingRepository::class);
    $completed = $repo->getCompleted(MigrationScope::Central, null);
    expect($completed)->toContain('2026_04_01_100000_test_migration');
});

it('requires --force in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    $this->artisan('data-migrate')->assertFailed();
});

it('runs in production with --force', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    $this->artisan('data-migrate', ['--force' => true, '--scope' => 'central'])->assertSuccessful();
});

it('supports pretend mode', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_pretend_test.php', $stub);

    $this->artisan('data-migrate', ['--pretend' => true, '--scope' => 'central'])->assertSuccessful();

    $repo = app(TrackingRepository::class);
    $completed = $repo->getCompleted(MigrationScope::Central, null);
    expect($completed)->toBeEmpty();
});

it('outputs json when --json is passed', function (): void {
    $this->artisan('data-migrate', ['--json' => true, '--scope' => 'central'])
        ->assertSuccessful()
        ->expectsOutputToContain('"successful"');
});

it('gracefully skips tenant scope when no adapter is configured', function (): void {
    // Default config has tenant_adapter = null (NullTenantAdapter)
    // Running --scope=all should NOT throw — it should skip tenants silently
    $this->artisan('data-migrate', ['--scope' => 'all'])
        ->assertSuccessful();
});

it('rejects invalid scope', function (): void {
    $this->artisan('data-migrate', ['--scope' => 'bogus'])
        ->assertFailed();
});

it('fails explicit tenant scope when no adapter is configured', function (): void {
    $this->artisan('data-migrate', ['--scope' => 'tenant'])
        ->assertFailed();
});

it('fails tenant option when no adapter is configured', function (): void {
    $this->artisan('data-migrate', ['--tenant' => 'acme-1'])
        ->assertFailed();
});

it('fails when an explicit tenant key does not exist', function (): void {
    config()->set('data-migrations.tenant_adapter', InMemoryTenantAdapter::class);
    app()->instance(TenantAdapter::class, new InMemoryTenantAdapter);
    InMemoryTenantAdapter::$tenants = ['acme-1'];

    $this->artisan('data-migrate', ['--scope' => 'tenant', '--tenant' => 'missing'])
        ->assertFailed()
        ->expectsOutputToContain('Tenant not found: missing');
});

it('fails tenant migrations when tracking connection is not explicitly configured', function (): void {
    config()->set('data-migrations.tenant_adapter', InMemoryTenantAdapter::class);
    config()->set('data-migrations.connection');

    app()->instance(TenantAdapter::class, new InMemoryTenantAdapter);
    InMemoryTenantAdapter::$tenants = ['acme-1'];

    $this->artisan('data-migrate', ['--scope' => 'tenant'])
        ->assertFailed()
        ->expectsOutputToContain('Set data-migrations.connection');
});

it('does not record checksums when checksums are disabled', function (): void {
    config()->set('data-migrations.checksum.enabled', false);

    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public bool $transactional = false;
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_no_checksum.php', $stub);

    $this->artisan('data-migrate', ['--scope' => 'central'])->assertSuccessful();

    $record = app(TrackingRepository::class)->getAll(MigrationScope::Central, null)->first();
    expect($record->checksum)->toBeNull();
});

it('fails when a specific migration name is not discovered', function (): void {
    $this->artisan('data-migrate', ['--scope' => 'central', '--name' => '2026_04_01_100000_missing'])
        ->assertFailed();
});

it('does not rerun failed migrations during a normal run', function (): void {
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

    $this->artisan('data-migrate', ['--scope' => 'central'])->assertSuccessful();

    expect($repo->getFailed(MigrationScope::Central, null))->toHaveCount(1);
    expect($repo->getAll(MigrationScope::Central, null))->toHaveCount(1);
});
