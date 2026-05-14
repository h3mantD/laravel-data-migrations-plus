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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->discovery = Mockery::mock(DiscoveryService::class);
    $this->tracking = app(TrackingRepository::class);
    $this->checksum = new ChecksumService;
    $this->lock = Mockery::mock(LockService::class);
    $this->tenantAdapter = Mockery::mock(TenantAdapter::class);

    $this->lock->shouldReceive('acquire')->andReturn(true)->byDefault();
    $this->lock->shouldReceive('release')->byDefault();
    $this->discovery->shouldReceive('duplicateNames')->andReturn([])->byDefault();

    $this->runner = new MigrationRunner(
        discovery: $this->discovery,
        tracking: $this->tracking,
        checksum: $this->checksum,
        lock: $this->lock,
        tenantAdapter: $this->tenantAdapter,
        db: app('db'),
    );
});

it('runs pending central migrations', function (): void {
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

it('skips already completed migrations', function (): void {
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

it('records failure when migration throws', function (): void {
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

it('stops on failure by default', function (): void {
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

it('continues on failure when flag is set', function (): void {
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

it('pretend mode does not execute migrations', function (): void {
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

it('fails when lock cannot be acquired', function (): void {
    $this->lock->shouldReceive('acquire')->andReturn(false);

    $result = $this->runner->run(scope: MigrationScope::Central, targetKey: null, pretend: false, continueOnFailure: false);
    expect($result->lockFailed)->toBeTrue();
});

it('blocks duplicate migration names within a scope before execution', function (): void {
    $this->discovery->shouldReceive('duplicateNames')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_duplicate' => ['/one.php', '/two.php']]);
    $this->discovery->shouldReceive('discover')->never();

    $result = $this->runner->run(scope: MigrationScope::Central, targetKey: null, pretend: false, continueOnFailure: false);

    expect($result->failed)->toBe(['2026_04_01_100000_duplicate']);
    expect($this->tracking->getAll(MigrationScope::Central, null))->toBeEmpty();
});

it('calls validate() before up()', function (): void {
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

it('runs migrations within a transaction when transactional is true', function (): void {
    // Create a test table to verify transactional behavior
    Schema::create('runner_test_table', function ($table): void {
        $table->id();
        $table->string('name');
    });

    $migration = new class extends DataMigration
    {
        public bool $transactional = true;

        public function up(DataMigrationContext $context): void
        {
            $context->connection->table('runner_test_table')->insert(['name' => 'test']);
            throw new RuntimeException('Fail after insert');
        }
    };

    $this->discovery->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_txn_test' => $migration]);
    $this->discovery->shouldReceive('getFilePath')->andReturn('/tmp/fake.php');

    $this->runner->run(
        scope: MigrationScope::Central,
        targetKey: null, pretend: false, continueOnFailure: false,
    );

    // The insert should have been rolled back by the transaction
    expect(DB::table('runner_test_table')->count())->toBe(0);

    Schema::dropIfExists('runner_test_table');
});

it('runs specific migration by name', function (): void {
    $ran1 = false;
    $ran2 = false;

    $migration1 = new class($ran1) extends DataMigration
    {
        public bool $transactional = false;

        public function __construct(private bool &$ran) {}

        public function up(DataMigrationContext $context): void
        {
            $this->ran = true;
        }
    };
    $migration2 = new class($ran2) extends DataMigration
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
            '2026_04_01_100000_first' => $migration1,
            '2026_04_01_200000_second' => $migration2,
        ]);
    $this->discovery->shouldReceive('getFilePath')->andReturn('/tmp/fake.php');

    $result = $this->runner->run(
        scope: MigrationScope::Central,
        targetKey: null, pretend: false, continueOnFailure: false,
        specificName: '2026_04_01_200000_second',
    );

    expect($ran1)->toBeFalse();
    expect($ran2)->toBeTrue();
    expect($result->successful)->toBe(['2026_04_01_200000_second']);
});

it('iterates tenants via adapter when targetKey is null', function (): void {
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

    $this->tenantAdapter->shouldReceive('tenants')->andReturn(['tenant-a', 'tenant-b']);
    $this->tenantAdapter->shouldReceive('enter')->twice();
    $this->tenantAdapter->shouldReceive('leave')->twice();
    $this->tenantAdapter->shouldReceive('tenantKey')
        ->with('tenant-a')->andReturn('key-a')
        ->shouldReceive('tenantKey')
        ->with('tenant-b')->andReturn('key-b');
    $this->tenantAdapter->shouldReceive('connectionName')->andReturn('testing');

    $this->discovery->shouldReceive('discover')
        ->with(MigrationScope::Tenant)
        ->andReturn(['2026_04_01_100000_tenant_mig' => $migration]);
    $this->discovery->shouldReceive('getFilePath')->andReturn('/tmp/fake.php');

    $result = $this->runner->run(
        scope: MigrationScope::Tenant,
        targetKey: null,
        pretend: false,
        continueOnFailure: false,
    );

    expect($result->successful)->toHaveCount(2);
});

it('leaves tenant context when entering a tenant throws', function (): void {
    $this->tenantAdapter->shouldReceive('tenants')->andReturn(['tenant-a']);
    $this->tenantAdapter->shouldReceive('tenantKey')->with('tenant-a')->andReturn('key-a');
    $this->tenantAdapter->shouldReceive('enter')->with('tenant-a')->andThrow(new RuntimeException('enter failed'));
    $this->tenantAdapter->shouldReceive('leave')->once();

    expect(fn () => $this->runner->run(
        scope: MigrationScope::Tenant,
        targetKey: null,
        pretend: false,
        continueOnFailure: false,
    ))->toThrow(RuntimeException::class, 'enter failed');
});
