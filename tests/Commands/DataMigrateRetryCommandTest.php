<?php

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\TrackingRepository;

beforeEach(function () {
    $this->centralDir = sys_get_temp_dir().'/dm-retry-test/central';
    @mkdir($this->centralDir, 0755, true);
    config()->set('data-migrations.central_path', $this->centralDir);
    config()->set('data-migrations.tenant_path', sys_get_temp_dir().'/dm-retry-test/tenant');
});

afterEach(function () {
    array_map('unlink', glob($this->centralDir.'/*'));
    @rmdir($this->centralDir);
    @rmdir(dirname($this->centralDir));
});

it('retries failed migrations', function () {
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

it('reports when no failed migrations exist', function () {
    $this->artisan('data-migrate:retry', ['--scope' => 'central'])
        ->assertSuccessful()
        ->expectsOutputToContain('No failed');
});

it('outputs json when --json is passed', function () {
    $this->artisan('data-migrate:retry', ['--scope' => 'central', '--json' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('"retried"');
});

it('requires --force in production', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('data-migrate:retry')->assertFailed();
});

it('does not run unrelated pending migrations during retry', function () {
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
