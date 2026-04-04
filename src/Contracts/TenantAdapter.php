<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Contracts;

interface TenantAdapter
{
    public function tenants(): iterable;
    public function enter(mixed $tenant): void;
    public function leave(): void;
    public function tenantKey(mixed $tenant): string;
    public function connectionName(): string;
}
