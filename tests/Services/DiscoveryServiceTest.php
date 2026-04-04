<?php

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\DiscoveryService;

beforeEach(function () {
    $this->centralDir = sys_get_temp_dir().'/data-migration-tests/central';
    $this->tenantDir = sys_get_temp_dir().'/data-migration-tests/tenant';
    @mkdir($this->centralDir, 0755, true);
    @mkdir($this->tenantDir, 0755, true);
    config()->set('data-migrations.central_path', $this->centralDir);
    config()->set('data-migrations.tenant_path', $this->tenantDir);
    config()->set('data-migrations.extra_paths', []);
    $this->service = new DiscoveryService;
});

afterEach(function () {
    array_map('unlink', glob($this->centralDir.'/*'));
    array_map('unlink', glob($this->tenantDir.'/*'));
    @rmdir($this->centralDir);
    @rmdir($this->tenantDir);
    @rmdir(dirname($this->centralDir));
});

it('discovers central migrations from configured path', function () {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_add_roles.php', $stub);
    $migrations = $this->service->discover(MigrationScope::Central);
    expect($migrations)->toHaveKey('2026_04_01_100000_add_roles');
});

it('discovers tenant migrations from configured path', function () {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    use H3mantd\DataMigrations\Enums\MigrationScope;
    return new class extends DataMigration {
        public \H3mantd\DataMigrations\Enums\MigrationScope $scope = MigrationScope::Tenant;
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->tenantDir.'/2026_04_01_100000_backfill.php', $stub);
    $migrations = $this->service->discover(MigrationScope::Tenant);
    expect($migrations)->toHaveKey('2026_04_01_100000_backfill');
});

it('returns migrations sorted by filename', function () {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_02_100000_second.php', $stub);
    file_put_contents($this->centralDir.'/2026_04_01_100000_first.php', $stub);
    $migrations = $this->service->discover(MigrationScope::Central);
    expect(array_keys($migrations))->toBe(['2026_04_01_100000_first', '2026_04_02_100000_second']);
});

it('returns empty array when path does not exist', function () {
    config()->set('data-migrations.central_path', '/nonexistent/path');
    $service = new DiscoveryService;
    $migrations = $service->discover(MigrationScope::Central);
    expect($migrations)->toBeEmpty();
});

it('ignores non-php files', function () {
    file_put_contents($this->centralDir.'/readme.md', '# notes');
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_add_roles.php', $stub);
    $migrations = $this->service->discover(MigrationScope::Central);
    expect($migrations)->toHaveCount(1);
});

it('returns file paths via getFilePath', function () {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_100000_add_roles.php', $stub);
    $path = $this->service->getFilePath('2026_04_01_100000_add_roles', MigrationScope::Central);
    expect($path)->toBe($this->centralDir.'/2026_04_01_100000_add_roles.php');
});
