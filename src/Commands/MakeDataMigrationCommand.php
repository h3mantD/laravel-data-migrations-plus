<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Commands;

use H3mantd\DataMigrations\Enums\MigrationType;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeDataMigrationCommand extends Command
{
    /** @var string */
    public $signature = 'make:data-migration
        {name : The name of the data migration}
        {--scope=central : The scope (central or tenant)}
        {--type=bootstrap : The migration type (bootstrap, transform, backfill, reconcile, cleanup)}
        {--path= : Custom output path}';

    /** @var string */
    public $description = 'Create a new data migration file';

    public function handle(): int
    {
        /** @var string $rawName */
        $rawName = $this->argument('name');
        $name = Str::snake($rawName);

        /** @var string $scope */
        $scope = $this->option('scope');

        /** @var string $type */
        $type = $this->option('type');

        if (! in_array($scope, ['central', 'tenant'], true)) {
            $this->error("Invalid scope: {$scope}. Valid scopes: central, tenant");

            return self::FAILURE;
        }

        if (! MigrationType::tryFrom($type)) {
            $this->error("Invalid type: {$type}. Valid types: bootstrap, transform, backfill, reconcile, cleanup");

            return self::FAILURE;
        }

        if (preg_match('/[^a-zA-Z0-9_]/', $name)) {
            $this->error("Invalid name: {$name}. Name must contain only alphanumeric characters and underscores.");

            return self::FAILURE;
        }

        $timestamp = now()->format('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";

        $customPath = $this->option('path');

        /** @var string $tenantPath */
        $tenantPath = config('data-migrations.tenant_path');

        /** @var string $centralPath */
        $centralPath = config('data-migrations.central_path');

        $path = is_string($customPath) && $customPath !== '' ? $customPath : match ($scope) {
            'tenant' => $tenantPath,
            default => $centralPath,
        };

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $stubFile = $scope === 'tenant' ? 'data-migration.tenant.stub' : 'data-migration.stub';
        $stub = file_get_contents(__DIR__.'/../../stubs/'.$stubFile);

        if ($stub === false) {
            $this->error("Could not read stub file: {$stubFile}");

            return self::FAILURE;
        }

        $stub = str_replace('{{ type }}', Str::studly($type), $stub);

        $filePath = $path.'/'.$filename;
        file_put_contents($filePath, $stub);

        $relativePath = str_replace(base_path().'/', '', $filePath);
        $this->components->info("Data migration created: {$relativePath}");
        $this->components->twoColumnDetail('Scope', $scope);
        $this->components->twoColumnDetail('Type', $type);

        return self::SUCCESS;
    }
}
