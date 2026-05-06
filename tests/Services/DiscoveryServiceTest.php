<?php

use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\DiscoveryService;

beforeEach(function (): void {
    $this->centralDir = sys_get_temp_dir().'/data-migration-tests/central';
    $this->tenantDir = sys_get_temp_dir().'/data-migration-tests/tenant';
    @mkdir($this->centralDir, 0755, true);
    @mkdir($this->tenantDir, 0755, true);
    config()->set('data-migrations.central_path', $this->centralDir);
    config()->set('data-migrations.tenant_path', $this->tenantDir);
    config()->set('data-migrations.extra_central_paths', []);
    config()->set('data-migrations.extra_tenant_paths', []);
    config()->set('data-migrations.extra_paths', []);

    $this->service = new DiscoveryService;
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->centralDir.'/*'));
    array_map(unlink(...), glob($this->tenantDir.'/*'));
    @rmdir($this->centralDir);
    @rmdir($this->tenantDir);
    @rmdir(dirname($this->centralDir));
});

it('discovers central migrations from configured path', function (): void {
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

it('discovers tenant migrations from configured path', function (): void {
    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->tenantDir.'/2026_04_01_100000_backfill.php', $stub);
    $migrations = $this->service->discover(MigrationScope::Tenant);
    expect($migrations)->toHaveKey('2026_04_01_100000_backfill');
});

it('returns migrations sorted by filename', function (): void {
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

it('returns empty array when path does not exist', function (): void {
    config()->set('data-migrations.central_path', '/nonexistent/path');
    $service = new DiscoveryService;
    $migrations = $service->discover(MigrationScope::Central);
    expect($migrations)->toBeEmpty();
});

it('ignores non-php files', function (): void {
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

it('returns file paths via getFilePath', function (): void {
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

it('includes scoped extra paths only in their configured scope', function (): void {
    $centralExtraDir = sys_get_temp_dir().'/data-migration-tests/extra-central';
    $tenantExtraDir = sys_get_temp_dir().'/data-migration-tests/extra-tenant';
    @mkdir($centralExtraDir, 0755, true);
    @mkdir($tenantExtraDir, 0755, true);

    config()->set('data-migrations.extra_central_paths', [$centralExtraDir]);
    config()->set('data-migrations.extra_tenant_paths', [$tenantExtraDir]);

    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($centralExtraDir.'/2026_04_01_100000_central_extra.php', $stub);
    file_put_contents($tenantExtraDir.'/2026_04_01_100000_tenant_extra.php', $stub);

    $service = new DiscoveryService;
    $centralMigrations = $service->discover(MigrationScope::Central);
    $tenantMigrations = $service->discover(MigrationScope::Tenant);

    expect($centralMigrations)->toHaveKey('2026_04_01_100000_central_extra');
    expect($centralMigrations)->not->toHaveKey('2026_04_01_100000_tenant_extra');
    expect($tenantMigrations)->toHaveKey('2026_04_01_100000_tenant_extra');
    expect($tenantMigrations)->not->toHaveKey('2026_04_01_100000_central_extra');

    array_map(unlink(...), glob($centralExtraDir.'/*'));
    array_map(unlink(...), glob($tenantExtraDir.'/*'));
    @rmdir($centralExtraDir);
    @rmdir($tenantExtraDir);
});

it('keeps legacy extra_paths as central-only paths', function (): void {
    $extraDir = sys_get_temp_dir().'/data-migration-tests/extra';
    @mkdir($extraDir, 0755, true);

    config()->set('data-migrations.extra_paths', [$extraDir]);

    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($extraDir.'/2026_04_01_100000_extra.php', $stub);

    $service = new DiscoveryService;

    expect($service->discover(MigrationScope::Central))->toHaveKey('2026_04_01_100000_extra');
    expect($service->discover(MigrationScope::Tenant))->not->toHaveKey('2026_04_01_100000_extra');

    array_map(unlink(...), glob($extraDir.'/*'));
    @rmdir($extraDir);
});

it('ignores files that do not return DataMigration instances', function (): void {
    file_put_contents($this->centralDir.'/2026_04_01_100000_bad.php', '<?php return "not a migration";');

    $stub = <<<'PHP'
    <?php
    use H3mantd\DataMigrations\DataMigration;
    use H3mantd\DataMigrations\DataMigrationContext;
    return new class extends DataMigration {
        public function up(DataMigrationContext $context): void {}
    };
    PHP;
    file_put_contents($this->centralDir.'/2026_04_01_200000_good.php', $stub);

    $migrations = $this->service->discover(MigrationScope::Central);
    expect($migrations)->toHaveCount(1);
    expect($migrations)->toHaveKey('2026_04_01_200000_good');
});
