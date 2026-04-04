<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations;

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
            ->runsMigrations();
    }
}
