<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Tests\Fixtures;

use H3mantd\DataMigrations\Contracts\TenantAdapter;

class InMemoryTenantAdapter implements TenantAdapter
{
    /** @var list<string> */
    public static array $tenants = ['acme-1'];

    /** @var list<string> */
    public static array $entered = [];

    public static int $leaveCount = 0;

    public function tenants(): iterable
    {
        return self::$tenants;
    }

    public function enter(mixed $tenant): void
    {
        self::$entered[] = (string) $tenant;
    }

    public function leave(): void
    {
        self::$leaveCount++;
    }

    public function tenantKey(mixed $tenant): string
    {
        return (string) $tenant;
    }

    public function connectionName(): string
    {
        return 'testing';
    }
}
