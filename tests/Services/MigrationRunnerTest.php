<?php

use H3mantd\DataMigrations\Contracts\TenantAdapter;
use H3mantd\DataMigrations\DataMigration;
use H3mantd\DataMigrations\DataMigrationContext;
use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Services\ChecksumService;
use H3mantd\DataMigrations\Services\DiscoveryService;
use H3mantd\DataMigrations\Services\LockService;
use H3mantd\DataMigrations\Services\MigrationRunner;
use H3mantd\DataMigrations\Services\TrackingRepository;

beforeEach(function () {
    $this->discovery = Mockery::mock(DiscoveryService::class);
    $this->tracking = app(TrackingRepository::class);
    $this->checksum = new ChecksumService;
    $this->lock = Mockery::mock(LockService::class);
    $this->tenantAdapter = Mockery::mock(TenantAdapter::class);

    $this->lock->shouldReceive('acquire')->andReturn(true)->byDefault();
    $this->lock->shouldReceive('release')->byDefault();

    $this->runner = new MigrationRunner(
        discovery: $this->discovery,
        tracking: $this->tracking,
        checksum: $this->checksum,
        lock: $this->lock,
        tenantAdapter: $this->tenantAdapter,
        db: app('db'),
    );
});

it('runs pending central migrations', function () {
    $ran = false;
    $migration = new class($ran) extends DataMigration
    {
        public bool $transactional = false;

        public function __construct(private bool &$ran) {}

        public function up(DataMigrationContext $context): void
        {
            $this->ran = true;
        }
    };

    $this->discovery->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_add_roles' => $migration]);
    $this->discovery->shouldReceive('getFilePath')->andReturn('/tmp/fake_migration.php');

    $result = $this->runner->run(
        scope: MigrationScope::Central, targetKey: null,
        pretend: false, continueOnFailure: false,
    );

    expect($ran)->toBeTrue();
    expect($result->successful)->toHaveCount(1);
    expect($result->failed)->toBeEmpty();
});

it('skips already completed migrations', function () {
    $ran = false;
    $migration = new class($ran) extends DataMigration
    {
        public function __construct(private bool &$ran) {}

        public function up(DataMigrationContext $context): void
        {
            $this->ran = true;
        }
    };

    $id = $this->tracking->recordStart('2026_04_01_100000_add_roles', MigrationScope::Central, null, 'testing', 1, null);
    $this->tracking->recordSuccess($id, 100);

    $this->discovery->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_add_roles' => $migration]);

    $result = $this->runner->run(
        scope: MigrationScope::Central, targetKey: null,
        pretend: false, continueOnFailure: false,
    );

    expect($ran)->toBeFalse();
    expect($result->successful)->toBeEmpty();
});

it('records failure when migration throws', function () {
    $migration = new class extends DataMigration
    {
        public bool $transactional = false;

        public function up(DataMigrationContext $context): void
        {
            throw new RuntimeException('Migration exploded');
        }
    };

    $this->discovery->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_broken' => $migration]);
    $this->discovery->shouldReceive('getFilePath')->andReturn('/tmp/fake_migration.php');

    $result = $this->runner->run(
        scope: MigrationScope::Central, targetKey: null,
        pretend: false, continueOnFailure: false,
    );

    expect($result->failed)->toHaveCount(1);
    $failed = $this->tracking->getFailed(MigrationScope::Central, null);
    expect($failed)->toHaveCount(1);
    expect($failed->first()->error_message)->toContain('Migration exploded');
});

it('stops on failure by default', function () {
    $secondRan = false;

    $broken = new class extends DataMigration
    {
        public bool $transactional = false;

        public function up(DataMigrationContext $context): void
        {
            throw new RuntimeException('broken');
        }
    };
    $good = new class($secondRan) extends DataMigration
    {
        public bool $transactional = false;

        public function __construct(private bool &$ran) {}

        public function up(DataMigrationContext $context): void
        {
            $this->ran = true;
        }
    };

    $this->discovery->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn([
            '2026_04_01_100000_broken' => $broken,
            '2026_04_01_200000_good' => $good,
        ]);
    $this->discovery->shouldReceive('getFilePath')->andReturn('/tmp/fake_migration.php');

    $this->runner->run(scope: MigrationScope::Central, targetKey: null, pretend: false, continueOnFailure: false);
    expect($secondRan)->toBeFalse();
});

it('continues on failure when flag is set', function () {
    $secondRan = false;

    $broken = new class extends DataMigration
    {
        public bool $transactional = false;

        public function up(DataMigrationContext $context): void
        {
            throw new RuntimeException('broken');
        }
    };
    $good = new class($secondRan) extends DataMigration
    {
        public bool $transactional = false;

        public function __construct(private bool &$ran) {}

        public function up(DataMigrationContext $context): void
        {
            $this->ran = true;
        }
    };

    $this->discovery->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn([
            '2026_04_01_100000_broken' => $broken,
            '2026_04_01_200000_good' => $good,
        ]);
    $this->discovery->shouldReceive('getFilePath')->andReturn('/tmp/fake_migration.php');

    $result = $this->runner->run(scope: MigrationScope::Central, targetKey: null, pretend: false, continueOnFailure: true);
    expect($secondRan)->toBeTrue();
    expect($result->failed)->toHaveCount(1);
    expect($result->successful)->toHaveCount(1);
});

it('pretend mode does not execute migrations', function () {
    $ran = false;
    $migration = new class($ran) extends DataMigration
    {
        public function __construct(private bool &$ran) {}

        public function up(DataMigrationContext $context): void
        {
            $this->ran = true;
        }
    };

    $this->discovery->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_add_roles' => $migration]);
    $this->discovery->shouldReceive('getFilePath')->andReturn('/tmp/fake_migration.php');

    $result = $this->runner->run(scope: MigrationScope::Central, targetKey: null, pretend: true, continueOnFailure: false);
    expect($ran)->toBeFalse();
    expect($result->pretended)->toHaveCount(1);
});

it('fails when lock cannot be acquired', function () {
    $this->lock->shouldReceive('acquire')->andReturn(false);

    $result = $this->runner->run(scope: MigrationScope::Central, targetKey: null, pretend: false, continueOnFailure: false);
    expect($result->lockFailed)->toBeTrue();
});

it('calls validate() before up()', function () {
    $migration = new class extends DataMigration
    {
        public bool $transactional = false;

        public function validate(DataMigrationContext $context): void
        {
            throw new RuntimeException('Precondition failed');
        }

        public function up(DataMigrationContext $context): void {}
    };

    $this->discovery->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_invalid' => $migration]);
    $this->discovery->shouldReceive('getFilePath')->andReturn('/tmp/fake_migration.php');

    $result = $this->runner->run(scope: MigrationScope::Central, targetKey: null, pretend: false, continueOnFailure: false);
    expect($result->failed)->toHaveCount(1);
});
