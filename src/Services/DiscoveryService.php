<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Services;

use H3mantd\DataMigrations\DataMigration;
use H3mantd\DataMigrations\Enums\MigrationScope;

class DiscoveryService
{
    /**
     * @return array<string, DataMigration>
     */
    public function discover(MigrationScope $scope): array
    {
        $paths = $this->pathsFor($scope);
        $files = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $found = glob($path.'/*.php');

            if ($found === false) {
                continue;
            }

            foreach ($found as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $files[$name] = $file;
            }
        }

        ksort($files);

        $resolved = [];

        foreach ($files as $name => $file) {
            $instance = require $file;

            if ($instance instanceof DataMigration) {
                $resolved[$name] = $instance;
            }
        }

        return $resolved;
    }

    public function getFilePath(string $name, MigrationScope $scope): ?string
    {
        foreach ($this->pathsFor($scope) as $path) {
            $file = $path.'/'.$name.'.php';

            if (file_exists($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function duplicateNames(MigrationScope $scope): array
    {
        /** @var array<string, list<string>> $filesByName */
        $filesByName = [];

        foreach ($this->pathsFor($scope) as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $found = glob($path.'/*.php');
            if ($found === false) {
                continue;
            }

            foreach ($found as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $filesByName[$name][] = $file;
            }
        }

        return array_filter($filesByName, fn (array $files): bool => count($files) > 1);
    }

    /**
     * @return list<string>
     */
    private function pathsFor(MigrationScope $scope): array
    {
        /** @var string|null $primary */
        $primary = match ($scope) {
            MigrationScope::Central => config('data-migrations.central_path'),
            MigrationScope::Tenant => config('data-migrations.tenant_path'),
        };

        $extra = $this->extraPathsFor($scope);

        /** @var list<string> $merged */
        $merged = array_values(array_filter(array_merge(
            $primary !== null ? [$primary] : [],
            $extra,
        )));

        return $merged;
    }

    /**
     * @return list<string>
     */
    private function extraPathsFor(MigrationScope $scope): array
    {
        $paths = match ($scope) {
            MigrationScope::Central => $this->configuredPaths('data-migrations.extra_central_paths'),
            MigrationScope::Tenant => $this->configuredPaths('data-migrations.extra_tenant_paths'),
        };

        if ($scope === MigrationScope::Central) {
            return array_merge($paths, $this->configuredPaths('data-migrations.extra_paths'));
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function configuredPaths(string $key): array
    {
        $paths = config($key, []);

        if (! is_array($paths)) {
            return [];
        }

        return array_values(array_filter($paths, is_string(...)));
    }
}
