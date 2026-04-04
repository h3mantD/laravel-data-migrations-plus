<?php

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\TrackingRepository;

beforeEach(function () {
    $this->centralDir = sys_get_temp_dir().'/dm-cmd-test/central';
    $this->tenantDir = sys_get_temp_dir().'/dm-cmd-test/tenant';
    @mkdir($this->centralDir, 0755, true);
    @mkdir($this->tenantDir, 0755, true);
    config()->set('data-migrations.central_path', $this->centralDir);
    config()->set('data-migrations.tenant_path', $this->tenantDir);
});

afterEach(function () {
    array_map('unlink', glob($this->centralDir.'/*'));
    array_map('unlink', glob($this->tenantDir.'/*'));
    @rmdir($this->centralDir);
    @rmdir($this->tenantDir);
    @rmdir(dirname($this->centralDir));
});

it('runs pending central migrations', function () {
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

it('requires --force in production', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('data-migrate')->assertFailed();
});

it('runs in production with --force', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('data-migrate', ['--force' => true, '--scope' => 'central'])->assertSuccessful();
});

it('supports pretend mode', function () {
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

it('outputs json when --json is passed', function () {
    $this->artisan('data-migrate', ['--json' => true, '--scope' => 'central'])
        ->assertSuccessful()
        ->expectsOutputToContain('"successful"');
});
