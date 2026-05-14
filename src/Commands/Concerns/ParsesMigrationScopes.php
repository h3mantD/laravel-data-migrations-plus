<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Commands\Concerns;

use H3mantd\DataMigrations\Contracts\TenantAdapter;
use H3mantd\DataMigrations\Enums\MigrationScope;
use H3mantd\DataMigrations\Support\NullTenantAdapter;

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

    /** @param list<MigrationScope> $scopes */
    private function tenantTrackingConnectionMissing(array $scopes, TenantAdapter $tenantAdapter): bool
    {
        return in_array(MigrationScope::Tenant, $scopes, true)
            && ! $tenantAdapter instanceof NullTenantAdapter
            && config('data-migrations.connection') === null;
    }

    private function missingTenantTrackingConnectionMessage(): string
    {
        return 'Set data-migrations.connection to your central database connection before running tenant migrations.';
    }
}
