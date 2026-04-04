<?php

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\ChecksumService;
use H3mantd\DataMigrations\Services\TrackingRepository;

beforeEach(function () {
    $this->centralDir = sys_get_temp_dir().'/dm-verify-test/central';
    @mkdir($this->centralDir, 0755, true);
    config()->set('data-migrations.central_path', $this->centralDir);
    config()->set('data-migrations.tenant_path', sys_get_temp_dir().'/dm-verify-test/tenant');
});

afterEach(function () {
    array_map('unlink', glob($this->centralDir.'/*'));
    @rmdir($this->centralDir);
    @rmdir(dirname($this->centralDir));
});

it('passes when no issues found', function () {
    $this->artisan('data-migrate:verify')->assertSuccessful();
});

it('detects missing migration files', function () {
    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_gone', MigrationScope::Central, null, 'testing', 1, 'abc');
    $repo->recordSuccess($id, 100);
    $this->artisan('data-migrate:verify', ['--strict' => true])->assertFailed();
});

it('detects checksum drift', function () {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;

    $filePath = $this->centralDir.'/2026_04_01_100000_drifted.php';
    file_put_contents($filePath, $stub);

    $checksumService = new ChecksumService;
    $checksum = $checksumService->compute($filePath);

    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_drifted', MigrationScope::Central, null, 'testing', 1, $checksum);
    $repo->recordSuccess($id, 100);

    file_put_contents($filePath, $stub."\n// modified");

    $this->artisan('data-migrate:verify')->assertFailed();
});

it('warns on drift but passes without --strict when fail_on_drift is false', function () {
    config()->set('data-migrations.checksum.fail_on_drift', false);

    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;

    $filePath = $this->centralDir.'/2026_04_01_100000_drifted.php';
    file_put_contents($filePath, $stub);

    $checksumService = new ChecksumService;
    $checksum = $checksumService->compute($filePath);

    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_drifted', MigrationScope::Central, null, 'testing', 1, $checksum);
    $repo->recordSuccess($id, 100);

    file_put_contents($filePath, $stub."\n// modified");

    $this->artisan('data-migrate:verify')->assertSuccessful();
});
