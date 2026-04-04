<?php

use H3mantd\DataMigrations\Support\NullTenantAdapter;

it('throws on tenants()', function () {
    $adapter = new NullTenantAdapter;
    iterator_to_array($adapter->tenants());
})->throws(RuntimeException::class, 'No tenant adapter configured');

it('throws on enter()', function () {
    $adapter = new NullTenantAdapter;
    $adapter->enter('tenant-1');
})->throws(RuntimeException::class, 'No tenant adapter configured');

it('throws on leave()', function () {
    $adapter = new NullTenantAdapter;
    $adapter->leave();
})->throws(RuntimeException::class, 'No tenant adapter configured');

it('throws on tenantKey()', function () {
    $adapter = new NullTenantAdapter;
    $adapter->tenantKey('tenant-1');
})->throws(RuntimeException::class, 'No tenant adapter configured');

it('throws on connectionName()', function () {
    $adapter = new NullTenantAdapter;
    $adapter->connectionName();
})->throws(RuntimeException::class, 'No tenant adapter configured');
