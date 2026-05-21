<?php

use H3mantd\DataMigrations\Contracts\TenantAdapter;
use H3mantd\DataMigrations\DataMigration;
use H3mantd\DataMigrations\DataMigrationContext;
use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Enums\MigrationStatus;
use H3mantd\DataMigrations\Services\ChecksumService;
use H3mantd\DataMigrations\Services\DiscoveryService;
use H3mantd\DataMigrations\Services\LockService;
use H3mantd\DataMigrations\Services\MigrationRunner;
use H3mantd\DataMigrations\Services\RunResult;
use H3mantd\DataMigrations\Services\TrackingRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;

final class MigrationRunnerRunState
{
    public bool $ran = false;
}

final class MigrationRunnerExceptionHandlerFake implements ExceptionHandler
{
    public int $reported = 0;

    public function report(Throwable $e): void
    {
        $this->reported++;
    }

    public function shouldReport(Throwable $e)
    {
        return true;
    }

    public function render($request, Throwable $e)
    {
        return new Response(status: 500);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        // Not used by these runner tests.
    }
}

function migrationRunnerDiscovery(): DiscoveryService&MockInterface
{
    return $GLOBALS['migrationRunnerDiscovery'];
}

function migrationRunnerTracking(): TrackingRepository
{
    return $GLOBALS['migrationRunnerTracking'];
}

function migrationRunnerChecksum(): ChecksumService
{
    return $GLOBALS['migrationRunnerChecksum'];
}

function migrationRunnerLock(): LockService&MockInterface
{
    return $GLOBALS['migrationRunnerLock'];
}

function migrationRunnerTenantAdapter(): TenantAdapter&MockInterface
{
    return $GLOBALS['migrationRunnerTenantAdapter'];
}

function migrationRunnerExceptions(): MigrationRunnerExceptionHandlerFake
{
    return $GLOBALS['migrationRunnerExceptions'];
}

function migrationRunner(): MigrationRunner
{
    return $GLOBALS['migrationRunner'];
}

beforeEach(function (): void {
    $GLOBALS['migrationRunnerDiscovery'] = Mockery::mock(DiscoveryService::class);
    $GLOBALS['migrationRunnerTracking'] = app(TrackingRepository::class);
    $GLOBALS['migrationRunnerChecksum'] = new ChecksumService;
    $GLOBALS['migrationRunnerLock'] = Mockery::mock(LockService::class);
    $GLOBALS['migrationRunnerTenantAdapter'] = Mockery::mock(TenantAdapter::class);
    $GLOBALS['migrationRunnerExceptions'] = new MigrationRunnerExceptionHandlerFake;

    migrationRunnerLock()->shouldReceive('acquire')->andReturn(true)->byDefault();
    migrationRunnerLock()->shouldReceive('release')->byDefault();
    migrationRunnerDiscovery()->shouldReceive('duplicateNames')->andReturn([])->byDefault();
    $GLOBALS['migrationRunner'] = new MigrationRunner(
        discovery: migrationRunnerDiscovery(),
        tracking: migrationRunnerTracking(),
        checksum: migrationRunnerChecksum(),
        lock: migrationRunnerLock(),
        tenantAdapter: migrationRunnerTenantAdapter(),
        db: app('db'),
        exceptions: migrationRunnerExceptions(),
    );
});

it('runs pending central migrations', function (): void {
    $ran = new MigrationRunnerRunState;
    $migration = new class($ran) extends DataMigration
    {
        public bool $transactional = false;

        public function __construct(private readonly MigrationRunnerRunState $state) {}

        public function up(DataMigrationContext $context): void
        {
            $this->state->ran = true;
        }
    };

    migrationRunnerDiscovery()->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_add_roles' => $migration]);
    migrationRunnerDiscovery()->shouldReceive('getFilePath')->andReturn('/tmp/fake_migration.php');

    $result = migrationRunner()->run(
        scope: MigrationScope::Central, targetKey: null,
        pretend: false, continueOnFailure: false,
    );

    expect($ran->ran)->toBeTrue();
    expect($result->successful)->toHaveCount(1);
    expect($result->failed)->toBeEmpty();
});

it('skips already completed migrations', function (): void {
    $ran = new MigrationRunnerRunState;
    $migration = new class($ran) extends DataMigration
    {
        public function __construct(private readonly MigrationRunnerRunState $state) {}

        public function up(DataMigrationContext $context): void
        {
            $this->state->ran = true;
        }
    };

    $id = migrationRunnerTracking()->recordStart('2026_04_01_100000_add_roles', MigrationScope::Central, null, 'testing', 1, null);
    migrationRunnerTracking()->recordSuccess($id, 100);

    migrationRunnerDiscovery()->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_add_roles' => $migration]);

    $result = migrationRunner()->run(
        scope: MigrationScope::Central, targetKey: null,
        pretend: false, continueOnFailure: false,
    );

    expect($ran->ran)->toBeFalse();
    expect($result->successful)->toBeEmpty();
});

it('logs and rethrows failure without recording it when migration throws', function (): void {
    $migration = new class extends DataMigration
    {
        public bool $transactional = false;

        public function up(DataMigrationContext $context): void
        {
            throw new RuntimeException('Migration exploded');
        }
    };

    migrationRunnerDiscovery()->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_broken' => $migration]);
    migrationRunnerDiscovery()->shouldReceive('getFilePath')->andReturn('/tmp/fake_migration.php');

    expect(fn (): RunResult => migrationRunner()->run(
        scope: MigrationScope::Central, targetKey: null,
        pretend: false, continueOnFailure: false,
    ))->toThrow(RuntimeException::class, 'Migration exploded');

    expect(migrationRunnerTracking()->getAll(MigrationScope::Central, null))->toBeEmpty();
});

it('skips migrations already marked as running', function (): void {
    $ran = new MigrationRunnerRunState;
    $migration = new class($ran) extends DataMigration
    {
        public bool $transactional = false;

        public function __construct(private readonly MigrationRunnerRunState $state) {}

        public function up(DataMigrationContext $context): void
        {
            $this->state->ran = true;
        }
    };

    migrationRunnerTracking()->recordStart('2026_04_01_100000_already_running', MigrationScope::Central, null, 'testing', 1, null);

    migrationRunnerDiscovery()->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_already_running' => $migration]);

    $result = migrationRunner()->run(
        scope: MigrationScope::Central,
        targetKey: null,
        pretend: false,
        continueOnFailure: false,
    );

    expect($ran->ran)->toBeFalse();
    expect($result->successful)->toBeEmpty();
    expect(migrationRunnerTracking()->getAll(MigrationScope::Central, null))->toHaveCount(1);
});

it('reruns stale running migrations and completes the existing row', function (): void {
    config()->set('data-migrations.lock.ttl', 60);

    $ran = new MigrationRunnerRunState;
    $migration = new class($ran) extends DataMigration
    {
        public bool $transactional = false;

        public function __construct(private readonly MigrationRunnerRunState $state) {}

        public function up(DataMigrationContext $context): void
        {
            $this->state->ran = true;
        }
    };

    $id = migrationRunnerTracking()->recordStart('2026_04_01_100000_stale_running', MigrationScope::Central, null, 'testing', 1, null);
    DB::table('data_migrations')->where('id', $id)->update(['started_at' => now()->subSeconds(61)]);

    migrationRunnerDiscovery()->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_stale_running' => $migration]);
    migrationRunnerDiscovery()->shouldReceive('getFilePath')->andReturn('/tmp/fake_migration.php');

    $result = migrationRunner()->run(
        scope: MigrationScope::Central,
        targetKey: null,
        pretend: false,
        continueOnFailure: false,
    );

    expect($ran->ran)->toBeTrue();
    expect($result->successful)->toBe(['2026_04_01_100000_stale_running']);

    $records = migrationRunnerTracking()->getAll(MigrationScope::Central, null);
    expect($records)->toHaveCount(1);
    expect($records->first()->id)->toBe($id);
    expect($records->first()->status)->toBe(MigrationStatus::Completed->value);
});

it('reruns legacy failed migrations and completes the existing row', function (): void {
    $ran = new MigrationRunnerRunState;
    $migration = new class($ran) extends DataMigration
    {
        public bool $transactional = false;

        public function __construct(private readonly MigrationRunnerRunState $state) {}

        public function up(DataMigrationContext $context): void
        {
            $this->state->ran = true;
        }
    };

    $id = migrationRunnerTracking()->recordStart('2026_04_01_100000_legacy_failed', MigrationScope::Central, null, 'testing', 1, null);
    migrationRunnerTracking()->recordFailure($id, 'old failure', 10);

    migrationRunnerDiscovery()->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_legacy_failed' => $migration]);
    migrationRunnerDiscovery()->shouldReceive('getFilePath')->andReturn('/tmp/fake_migration.php');

    $result = migrationRunner()->run(
        scope: MigrationScope::Central,
        targetKey: null,
        pretend: false,
        continueOnFailure: false,
    );

    expect($ran->ran)->toBeTrue();
    expect($result->successful)->toBe(['2026_04_01_100000_legacy_failed']);

    $records = migrationRunnerTracking()->getAll(MigrationScope::Central, null);
    expect($records)->toHaveCount(1);
    expect($records->first()->id)->toBe($id);
    expect($records->first()->status)->toBe(MigrationStatus::Completed->value);
    expect($records->first()->error_message)->toBeNull();
});

it('stops on failure by default', function (): void {
    $secondRan = new MigrationRunnerRunState;

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

        public function __construct(private readonly MigrationRunnerRunState $state) {}

        public function up(DataMigrationContext $context): void
        {
            $this->state->ran = true;
        }
    };

    migrationRunnerDiscovery()->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn([
            '2026_04_01_100000_broken' => $broken,
            '2026_04_01_200000_good' => $good,
        ]);
    migrationRunnerDiscovery()->shouldReceive('getFilePath')->andReturn('/tmp/fake_migration.php');

    expect(fn (): RunResult => migrationRunner()->run(
        scope: MigrationScope::Central,
        targetKey: null,
        pretend: false,
        continueOnFailure: false,
    ))->toThrow(RuntimeException::class, 'broken');

    expect($secondRan->ran)->toBeFalse();
});

it('continues on failure when flag is set', function (): void {
    $secondRan = new MigrationRunnerRunState;

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

        public function __construct(private readonly MigrationRunnerRunState $state) {}

        public function up(DataMigrationContext $context): void
        {
            $this->state->ran = true;
        }
    };

    migrationRunnerDiscovery()->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn([
            '2026_04_01_100000_broken' => $broken,
            '2026_04_01_200000_good' => $good,
        ]);
    migrationRunnerDiscovery()->shouldReceive('getFilePath')->andReturn('/tmp/fake_migration.php');

    $result = migrationRunner()->run(scope: MigrationScope::Central, targetKey: null, pretend: false, continueOnFailure: true);
    expect($secondRan->ran)->toBeTrue();
    expect($result->failed)->toHaveCount(1);
    expect($result->successful)->toHaveCount(1);
    expect(migrationRunnerExceptions()->reported)->toBe(1);
});

it('pretend mode does not execute migrations', function (): void {
    $ran = new MigrationRunnerRunState;
    $migration = new class($ran) extends DataMigration
    {
        public function __construct(private readonly MigrationRunnerRunState $state) {}

        public function up(DataMigrationContext $context): void
        {
            $this->state->ran = true;
        }
    };

    migrationRunnerDiscovery()->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_add_roles' => $migration]);
    migrationRunnerDiscovery()->shouldReceive('getFilePath')->andReturn('/tmp/fake_migration.php');

    $result = migrationRunner()->run(scope: MigrationScope::Central, targetKey: null, pretend: true, continueOnFailure: false);
    expect($ran->ran)->toBeFalse();
    expect($result->pretended)->toHaveCount(1);
});

it('fails when lock cannot be acquired', function (): void {
    migrationRunnerLock()->shouldReceive('acquire')->andReturn(false);

    $result = migrationRunner()->run(scope: MigrationScope::Central, targetKey: null, pretend: false, continueOnFailure: false);
    expect($result->lockFailed)->toBeTrue();
});

it('blocks duplicate migration names within a scope before execution', function (): void {
    migrationRunnerDiscovery()->shouldReceive('duplicateNames')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_duplicate' => ['/one.php', '/two.php']]);
    migrationRunnerDiscovery()->shouldReceive('discover')->never();

    $result = migrationRunner()->run(scope: MigrationScope::Central, targetKey: null, pretend: false, continueOnFailure: false);

    expect($result->failed)->toBe(['2026_04_01_100000_duplicate']);
    expect(migrationRunnerTracking()->getAll(MigrationScope::Central, null))->toBeEmpty();
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

    migrationRunnerDiscovery()->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_invalid' => $migration]);
    migrationRunnerDiscovery()->shouldReceive('getFilePath')->andReturn('/tmp/fake_migration.php');

    expect(fn (): RunResult => migrationRunner()->run(
        scope: MigrationScope::Central,
        targetKey: null,
        pretend: false,
        continueOnFailure: false,
    ))->toThrow(RuntimeException::class, 'Precondition failed');

    expect(migrationRunnerTracking()->getAll(MigrationScope::Central, null))->toBeEmpty();
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

    migrationRunnerDiscovery()->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn(['2026_04_01_100000_txn_test' => $migration]);
    migrationRunnerDiscovery()->shouldReceive('getFilePath')->andReturn('/tmp/fake.php');

    expect(fn (): RunResult => migrationRunner()->run(
        scope: MigrationScope::Central,
        targetKey: null, pretend: false, continueOnFailure: false,
    ))->toThrow(RuntimeException::class, 'Fail after insert');

    // The insert should have been rolled back by the transaction
    expect(DB::table('runner_test_table')->count())->toBe(0);

    Schema::dropIfExists('runner_test_table');
});

it('runs specific migration by name', function (): void {
    $ran1 = new MigrationRunnerRunState;
    $ran2 = new MigrationRunnerRunState;

    $migration1 = new class($ran1) extends DataMigration
    {
        public bool $transactional = false;

        public function __construct(private readonly MigrationRunnerRunState $state) {}

        public function up(DataMigrationContext $context): void
        {
            $this->state->ran = true;
        }
    };
    $migration2 = new class($ran2) extends DataMigration
    {
        public bool $transactional = false;

        public function __construct(private readonly MigrationRunnerRunState $state) {}

        public function up(DataMigrationContext $context): void
        {
            $this->state->ran = true;
        }
    };

    migrationRunnerDiscovery()->shouldReceive('discover')
        ->with(MigrationScope::Central)
        ->andReturn([
            '2026_04_01_100000_first' => $migration1,
            '2026_04_01_200000_second' => $migration2,
        ]);
    migrationRunnerDiscovery()->shouldReceive('getFilePath')->andReturn('/tmp/fake.php');

    $result = migrationRunner()->run(
        scope: MigrationScope::Central,
        targetKey: null, pretend: false, continueOnFailure: false,
        specificName: '2026_04_01_200000_second',
    );

    expect($ran1->ran)->toBeFalse();
    expect($ran2->ran)->toBeTrue();
    expect($result->successful)->toBe(['2026_04_01_200000_second']);
});

it('iterates tenants via adapter when targetKey is null', function (): void {
    $ran = new MigrationRunnerRunState;
    $migration = new class($ran) extends DataMigration
    {
        public bool $transactional = false;

        public function __construct(private readonly MigrationRunnerRunState $state) {}

        public function up(DataMigrationContext $context): void
        {
            $this->state->ran = true;
        }
    };

    migrationRunnerTenantAdapter()->shouldReceive('tenants')->andReturn(['tenant-a', 'tenant-b']);
    migrationRunnerTenantAdapter()->shouldReceive('enter')->twice();
    migrationRunnerTenantAdapter()->shouldReceive('leave')->twice();
    migrationRunnerTenantAdapter()->shouldReceive('tenantKey')->with('tenant-a')->andReturn('key-a');
    migrationRunnerTenantAdapter()->shouldReceive('tenantKey')->with('tenant-b')->andReturn('key-b');
    migrationRunnerTenantAdapter()->shouldReceive('connectionName')->andReturn('testing');

    migrationRunnerDiscovery()->shouldReceive('discover')
        ->with(MigrationScope::Tenant)
        ->andReturn(['2026_04_01_100000_tenant_mig' => $migration]);
    migrationRunnerDiscovery()->shouldReceive('getFilePath')->andReturn('/tmp/fake.php');

    $result = migrationRunner()->run(
        scope: MigrationScope::Tenant,
        targetKey: null,
        pretend: false,
        continueOnFailure: false,
    );

    expect($result->successful)->toHaveCount(2);
});

it('leaves tenant context when entering a tenant throws', function (): void {
    migrationRunnerTenantAdapter()->shouldReceive('tenants')->andReturn(['tenant-a']);
    migrationRunnerTenantAdapter()->shouldReceive('tenantKey')->with('tenant-a')->andReturn('key-a');
    migrationRunnerTenantAdapter()->shouldReceive('enter')->with('tenant-a')->andThrow(new RuntimeException('enter failed'));
    migrationRunnerTenantAdapter()->shouldReceive('leave')->once();

    expect(fn (): RunResult => migrationRunner()->run(
        scope: MigrationScope::Tenant,
        targetKey: null,
        pretend: false,
        continueOnFailure: false,
    ))->toThrow(RuntimeException::class, 'enter failed');
});
