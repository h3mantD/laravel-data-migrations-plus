<?php

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\TrackingRepository;
use H3mantd\DataMigrations\Tests\Fixtures\InMemoryTenantAdapter;

beforeEach(function (): void {
    $this->centralDir = sys_get_temp_dir().'/dm-rollback-test/central';
    $this->tenantDir = sys_get_temp_dir().'/dm-rollback-test/tenant';
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

it('rolls back the last batch', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public bool $transactional = false;
        public function up(DataMigrationContext $context): void {}
        public function down(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_reversible.php', $stub);

    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_reversible', MigrationScope::Central, null, 'testing', 1, null);
    $repo->recordSuccess($id, 100);

    $this->artisan('data-migrate:rollback', ['--scope' => 'central'])->assertSuccessful();

    $completed = $repo->getCompleted(MigrationScope::Central, null);
    expect($completed)->not->toContain('2026_04_01_100000_reversible');
});

it('fails when migration is irreversible', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public bool $transactional = false;
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_irreversible.php', $stub);

    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_irreversible', MigrationScope::Central, null, 'testing', 1, null);
    $repo->recordSuccess($id, 100);

    $this->artisan('data-migrate:rollback', ['--scope' => 'central'])->assertFailed();
});

it('reports when nothing to rollback', function (): void {
    $this->artisan('data-migrate:rollback', ['--scope' => 'central'])
        ->assertSuccessful()
        ->expectsOutputToContain('Nothing to rollback');
});

it('supports --step option', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public bool $transactional = false;
        public function up(DataMigrationContext $context): void {}
        public function down(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_batch1.php', $stub);
    file_put_contents($this->centralDir.'/2026_04_02_100000_batch2.php', $stub);

    $repo = app(TrackingRepository::class);
    $id1 = $repo->recordStart('2026_04_01_100000_batch1', MigrationScope::Central, null, 'testing', 1, null);
    $repo->recordSuccess($id1, 100);
    $id2 = $repo->recordStart('2026_04_02_100000_batch2', MigrationScope::Central, null, 'testing', 2, null);
    $repo->recordSuccess($id2, 100);

    $this->artisan('data-migrate:rollback', ['--scope' => 'central', '--step' => 1])->assertSuccessful();

    $completed = $repo->getCompleted(MigrationScope::Central, null);
    expect($completed)->toContain('2026_04_01_100000_batch1');
    expect($completed)->not->toContain('2026_04_02_100000_batch2');
});

it('requires --force in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    $this->artisan('data-migrate:rollback')->assertFailed();
});

it('runs in production with --force', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    $this->artisan('data-migrate:rollback', ['--scope' => 'central', '--force' => true])
        ->assertSuccessful();
});

it('outputs json format', function (): void {
    $this->artisan('data-migrate:rollback', ['--scope' => 'central', '--json' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('"rolled_back"');
});

it('rejects invalid scope', function (): void {
    $this->artisan('data-migrate:rollback', ['--scope' => 'bogus'])
        ->assertFailed();
});

it('fails explicit tenant scope when no adapter is configured', function (): void {
    $this->artisan('data-migrate:rollback', ['--scope' => 'tenant'])
        ->assertFailed();
});

it('fails tenant option when no adapter is configured', function (): void {
    $this->artisan('data-migrate:rollback', ['--tenant' => 'acme-1'])
        ->assertFailed();
});

it('skips tenant rollback during default all scope when no adapter is configured', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public bool $transactional = false;
        public function up(DataMigrationContext $context): void {}
        public function down(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_reversible.php', $stub);

    $repo = app(TrackingRepository::class);
    $centralId = $repo->recordStart('2026_04_01_100000_reversible', MigrationScope::Central, null, 'testing', 1, null);
    $repo->recordSuccess($centralId, 100);
    $tenantId = $repo->recordStart('2026_04_01_100000_tenant_reversible', MigrationScope::Tenant, 'acme-1', 'testing', 1, null);
    $repo->recordSuccess($tenantId, 100);

    $this->artisan('data-migrate:rollback')->assertSuccessful();

    expect($repo->getCompleted(MigrationScope::Central, null))->not->toContain('2026_04_01_100000_reversible');
    expect($repo->getCompleted(MigrationScope::Tenant, 'acme-1'))->toContain('2026_04_01_100000_tenant_reversible');
});

it('rolls back tenant batches for all tenants', function (): void {
    config()->set('data-migrations.tenant_adapter', InMemoryTenantAdapter::class);
    InMemoryTenantAdapter::$tenants = ['acme-1'];
    InMemoryTenantAdapter::$entered = [];
    InMemoryTenantAdapter::$leaveCount = 0;

    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public bool $transactional = false;
        public function up(DataMigrationContext $context): void {}
        public function down(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->tenantDir.'/2026_04_01_100000_reversible_tenant.php', $stub);

    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_reversible_tenant', MigrationScope::Tenant, 'acme-1', 'testing', 1, null);
    $repo->recordSuccess($id, 100);

    $this->artisan('data-migrate:rollback', ['--scope' => 'tenant'])->assertSuccessful();

    expect($repo->getCompleted(MigrationScope::Tenant, 'acme-1'))->not->toContain('2026_04_01_100000_reversible_tenant');
    expect(InMemoryTenantAdapter::$entered)->toBe(['acme-1']);
    expect(InMemoryTenantAdapter::$leaveCount)->toBe(1);
});

it('enters tenant context when rolling back a specific tenant', function (): void {
    config()->set('data-migrations.tenant_adapter', InMemoryTenantAdapter::class);
    InMemoryTenantAdapter::$tenants = ['acme-1', 'acme-2'];
    InMemoryTenantAdapter::$entered = [];
    InMemoryTenantAdapter::$leaveCount = 0;

    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public bool $transactional = false;
        public function up(DataMigrationContext $context): void {}
        public function down(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->tenantDir.'/2026_04_01_100000_specific_tenant.php', $stub);

    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_specific_tenant', MigrationScope::Tenant, 'acme-2', 'testing', 1, null);
    $repo->recordSuccess($id, 100);

    $this->artisan('data-migrate:rollback', ['--scope' => 'tenant', '--tenant' => 'acme-2'])->assertSuccessful();

    expect($repo->getCompleted(MigrationScope::Tenant, 'acme-2'))->not->toContain('2026_04_01_100000_specific_tenant');
    expect(InMemoryTenantAdapter::$entered)->toBe(['acme-2']);
    expect(InMemoryTenantAdapter::$leaveCount)->toBe(1);
});
