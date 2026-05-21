<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations;

use H3mantd\DataMigrations\Commands\DataMigrateCommand;
use H3mantd\DataMigrations\Commands\DataMigrateRollbackCommand;
use H3mantd\DataMigrations\Commands\DataMigrateShowCommand;
use H3mantd\DataMigrations\Commands\DataMigrateStatusCommand;
use H3mantd\DataMigrations\Commands\DataMigrateVerifyCommand;
use H3mantd\DataMigrations\Commands\MakeDataMigrationCommand;
use H3mantd\DataMigrations\Contracts\TenantAdapter;
use H3mantd\DataMigrations\Services\ChecksumService;
use H3mantd\DataMigrations\Services\DiscoveryService;
use H3mantd\DataMigrations\Services\LockService;
use H3mantd\DataMigrations\Services\MigrationRunner;
use H3mantd\DataMigrations\Services\TrackingRepository;
use H3mantd\DataMigrations\Support\NullTenantAdapter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DataMigrationServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('data-migrations')
            ->hasConfigFile()
            ->hasMigration('create_data_migrations_table')
            ->hasCommand(MakeDataMigrationCommand::class)
            ->hasCommand(DataMigrateCommand::class)
            ->hasCommand(DataMigrateStatusCommand::class)
            ->hasCommand(DataMigrateVerifyCommand::class)
            ->hasCommand(DataMigrateShowCommand::class)
            ->hasCommand(DataMigrateRollbackCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(TenantAdapter::class, function (Application $app) {
            $adapterClass = config('data-migrations.tenant_adapter');
            if (is_string($adapterClass) && class_exists($adapterClass)) {
                /** @var TenantAdapter */
                return $app->make($adapterClass);
            }

            return new NullTenantAdapter;
        });

        $this->app->singleton(TrackingRepository::class);
        $this->app->singleton(DiscoveryService::class);
        $this->app->singleton(ChecksumService::class);
        $this->app->singleton(LockService::class);

        $this->app->singleton(MigrationRunner::class, function (Application $app): MigrationRunner {
            /** @var DiscoveryService $discovery */
            $discovery = $app->make(DiscoveryService::class);
            /** @var TrackingRepository $tracking */
            $tracking = $app->make(TrackingRepository::class);
            /** @var ChecksumService $checksum */
            $checksum = $app->make(ChecksumService::class);
            /** @var LockService $lock */
            $lock = $app->make(LockService::class);
            /** @var TenantAdapter $tenantAdapter */
            $tenantAdapter = $app->make(TenantAdapter::class);
            /** @var DatabaseManager $db */
            $db = $app->make('db');
            /** @var ExceptionHandler $exceptions */
            $exceptions = $app->make(ExceptionHandler::class);

            return new MigrationRunner(
                discovery: $discovery,
                tracking: $tracking,
                checksum: $checksum,
                lock: $lock,
                tenantAdapter: $tenantAdapter,
                db: $db,
                exceptions: $exceptions,
            );
        });
    }
}
