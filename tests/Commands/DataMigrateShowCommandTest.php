<?php

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\TrackingRepository;

beforeEach(function (): void {
    $this->centralDir = sys_get_temp_dir().'/dm-show-test/central';
    @mkdir($this->centralDir, 0755, true);
    config()->set('data-migrations.central_path', $this->centralDir);
    config()->set('data-migrations.tenant_path', sys_get_temp_dir().'/dm-show-test/tenant');
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->centralDir.'/*'));
    @rmdir($this->centralDir);
    @rmdir(dirname($this->centralDir));
});

it('shows details of a migration with file and execution history', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_test_show.php', $stub);

    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_test_show', MigrationScope::Central, null, 'testing', 1, 'abc123');
    $repo->recordSuccess($id, 150);

    $this->artisan('data-migrate:show', ['name' => '2026_04_01_100000_test_show'])
        ->assertSuccessful()
        ->expectsOutputToContain('2026_04_01_100000_test_show')
        ->expectsOutputToContain('central');
});

it('shows json output', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_json_test.php', $stub);

    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_json_test', MigrationScope::Central, null, 'testing', 1, null);
    $repo->recordSuccess($id, 100);

    $this->artisan('data-migrate:show', ['name' => '2026_04_01_100000_json_test', '--json' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('2026_04_01_100000_json_test');
});

it('fails for non-existent migration', function (): void {
    $this->artisan('data-migrate:show', ['name' => 'nonexistent_migration'])
        ->assertFailed();
});

it('shows migration that exists on disk but never ran', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_never_ran.php', $stub);

    $this->artisan('data-migrate:show', ['name' => '2026_04_01_100000_never_ran'])
        ->assertSuccessful()
        ->expectsOutputToContain('No execution records found');
});
