<?php

namespace H3mantd\DataMigrations\Tests;

use H3mantd\DataMigrations\DataMigrationServiceProvider;
use H3mantd\DataMigrations\Services\DiscoveryService;
use H3mantd\DataMigrations\Services\TrackingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * @method \Illuminate\Testing\PendingCommand artisan(string $command, array<string, mixed> $parameters = [])
 *
 * @property string $centralDir
 * @property string $tenantDir
 * @property DiscoveryService $service
 * @property TrackingRepository $repo
 */
class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app)
    {
        return [
            DataMigrationServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config()->set('cache.default', 'array');

        $migration = include __DIR__.'/../database/migrations/create_data_migrations_table.php';
        $migration->up();
    }
}
