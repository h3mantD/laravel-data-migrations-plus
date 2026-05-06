<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Commands\Concerns;

use H3mantd\DataMigrations\Enums\MigrationScope;

trait ParsesMigrationScopes
{
    /** @return list<MigrationScope>|null */
    private function parseMigrationScopes(string $scope): ?array
    {
        return match ($scope) {
            'central' => [MigrationScope::Central],
            'tenant' => [MigrationScope::Tenant],
            'all' => [MigrationScope::Central, MigrationScope::Tenant],
            default => null,
        };
    }

    private function invalidScopeMessage(string $scope): string
    {
        return sprintf('Invalid scope: %s. Valid scopes: central, tenant, all', $scope);
    }
}
