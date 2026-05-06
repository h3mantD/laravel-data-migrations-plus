<?php

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\TrackingRepository;

beforeEach(function (): void {
    $this->centralDir = sys_get_temp_dir().'/dm-status-test/central';
    @mkdir($this->centralDir, 0755, true);
    config()->set('data-migrations.central_path', $this->centralDir);
    config()->set('data-migrations.tenant_path', sys_get_temp_dir().'/dm-status-test/tenant');
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->centralDir.'/*'));
    @rmdir($this->centralDir);
    @rmdir(dirname($this->centralDir));
});

it('shows pending migrations', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_add_roles.php', $stub);

    $result = $this->artisan('data-migrate:status', ['--scope' => 'central', '--json' => true]);
    $result->assertSuccessful();
    $result->expectsOutputToContain('2026_04_01_100000_add_roles');
});

it('shows completed migrations', function (): void {
    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_add_roles', MigrationScope::Central, null, 'testing', 1, null);
    $repo->recordSuccess($id, 100);

    $result = $this->artisan('data-migrate:status', ['--scope' => 'central', '--json' => true]);
    $result->assertSuccessful();
    $result->expectsOutputToContain('2026_04_01_100000_add_roles');
});

it('shows failed migrations with --failed filter', function (): void {
    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_broken', MigrationScope::Central, null, 'testing', 1, null);
    $repo->recordFailure($id, 'Something went wrong', 50);

    $result = $this->artisan('data-migrate:status', ['--scope' => 'central', '--failed' => true, '--json' => true]);
    $result->assertSuccessful();
    $result->expectsOutputToContain('2026_04_01_100000_broken');
});

it('outputs json format', function (): void {
    $this->artisan('data-migrate:status', ['--scope' => 'central', '--json' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('"migrations"');
});

it('shows tenant records when --scope=tenant without --tenant', function (): void {
    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_tenant_migration', MigrationScope::Tenant, 'acme-1', 'testing', 1, null);
    $repo->recordSuccess($id, 100);

    $this->artisan('data-migrate:status', ['--scope' => 'tenant', '--json' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('2026_04_01_100000_tenant_migration');
});

it('shows pending migrations with --pending filter', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_pending_one.php', $stub);

    // Also add a completed one that should NOT show
    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_pending_one', MigrationScope::Central, null, 'testing', 1, null);
    $repo->recordSuccess($id, 100);

    file_put_contents($this->centralDir.'/2026_04_02_100000_actual_pending.php', $stub);

    $this->artisan('data-migrate:status', ['--scope' => 'central', '--pending' => true, '--json' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('2026_04_02_100000_actual_pending');
});

it('rejects invalid scope', function (): void {
    $this->artisan('data-migrate:status', ['--scope' => 'bogus'])
        ->assertFailed();
});
