<?php

use H3mantd\DataMigrations\Support\NullTenantAdapter;

it('throws on tenants()', function (): void {
    $adapter = new NullTenantAdapter;
    iterator_to_array($adapter->tenants());
})->throws(RuntimeException::class, 'No tenant adapter configured');

it('throws on enter()', function (): void {
    $adapter = new NullTenantAdapter;
    $adapter->enter('tenant-1');
})->throws(RuntimeException::class, 'No tenant adapter configured');

it('throws on leave()', function (): void {
    $adapter = new NullTenantAdapter;
    $adapter->leave();
})->throws(RuntimeException::class, 'No tenant adapter configured');

it('throws on tenantKey()', function (): void {
    $adapter = new NullTenantAdapter;
    $adapter->tenantKey('tenant-1');
})->throws(RuntimeException::class, 'No tenant adapter configured');

it('throws on connectionName()', function (): void {
    $adapter = new NullTenantAdapter;
    $adapter->connectionName();
})->throws(RuntimeException::class, 'No tenant adapter configured');
