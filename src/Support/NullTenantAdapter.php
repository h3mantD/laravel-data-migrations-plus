<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Support;

use H3mantd\DataMigrations\Contracts\TenantAdapter;
use RuntimeException;

class NullTenantAdapter implements TenantAdapter
{
    public function tenants(): iterable
    {
        throw new RuntimeException('No tenant adapter configured. Set data-migrations.tenant_adapter in your config.');
    }

    public function enter(mixed $tenant): void
    {
        throw new RuntimeException('No tenant adapter configured. Set data-migrations.tenant_adapter in your config.');
    }

    public function leave(): void
    {
        throw new RuntimeException('No tenant adapter configured. Set data-migrations.tenant_adapter in your config.');
    }

    public function tenantKey(mixed $tenant): string
    {
        throw new RuntimeException('No tenant adapter configured. Set data-migrations.tenant_adapter in your config.');
    }

    public function connectionName(): string
    {
        throw new RuntimeException('No tenant adapter configured. Set data-migrations.tenant_adapter in your config.');
    }
}
