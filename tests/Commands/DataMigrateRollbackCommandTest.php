<?php

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\TrackingRepository;

beforeEach(function (): void {
    $this->centralDir = sys_get_temp_dir().'/dm-rollback-test/central';
    @mkdir($this->centralDir, 0755, true);
    config()->set('data-migrations.central_path', $this->centralDir);
    config()->set('data-migrations.tenant_path', sys_get_temp_dir().'/dm-rollback-test/tenant');
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->centralDir.'/*'));
    @rmdir($this->centralDir);
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
