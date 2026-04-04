<?php

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\ChecksumService;
use H3mantd\DataMigrations\Services\TrackingRepository;

beforeEach(function (): void {
    $this->centralDir = sys_get_temp_dir().'/dm-verify-test/central';
    @mkdir($this->centralDir, 0755, true);
    config()->set('data-migrations.central_path', $this->centralDir);
    config()->set('data-migrations.tenant_path', sys_get_temp_dir().'/dm-verify-test/tenant');
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->centralDir.'/*'));
    @rmdir($this->centralDir);
    @rmdir(dirname($this->centralDir));
});

it('passes when no issues found', function (): void {
    $this->artisan('data-migrate:verify')->assertSuccessful();
});

it('detects missing migration files', function (): void {
    $repo = app(TrackingRepository::class);
    $id = $repo->recordStart('2026_04_01_100000_gone', MigrationScope::Central, null, 'testing', 1, 'abc');
    $repo->recordSuccess($id, 100);
    $this->artisan('data-migrate:verify', ['--strict' => true])->assertFailed();
});

it('detects checksum drift', function (): void {
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

it('warns on drift but passes without --strict when fail_on_drift is false', function (): void {
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

it('passes when checksums are disabled', function (): void {
    config()->set('data-migrations.checksum.enabled', false);

    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;

    $filePath = $this->centralDir.'/2026_04_01_100000_test.php';
    file_put_contents($filePath, $stub);

    $repo = app(TrackingRepository::class);
    $checksum = (new ChecksumService)->compute($filePath);
    $id = $repo->recordStart('2026_04_01_100000_test', MigrationScope::Central, null, 'testing', 1, $checksum);
    $repo->recordSuccess($id, 100);

    // Modify file — but checksums are disabled so it should pass
    file_put_contents($filePath, $stub."\n// changed");

    $this->artisan('data-migrate:verify')->assertSuccessful();
});

it('verifies tenant migration records', function (): void {
    $repo = app(TrackingRepository::class);
    // Record a tenant migration as completed but with no file on disk
    $id = $repo->recordStart('2026_04_01_100000_gone_tenant', MigrationScope::Tenant, 'acme-1', 'testing', 1, 'abc');
    $repo->recordSuccess($id, 100);

    $this->artisan('data-migrate:verify', ['--strict' => true])->assertFailed();
});
