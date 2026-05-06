<?php

declare(strict_types=1);

it('publishes config with expected keys', function (): void {
    $config = config('data-migrations');
    expect($config)->toHaveKeys([
        'central_path', 'tenant_path', 'extra_central_paths', 'extra_tenant_paths', 'extra_paths',
        'table', 'connection',
        'lock', 'lock.enabled', 'lock.ttl',
        'checksum', 'checksum.enabled', 'checksum.fail_on_drift',
        'tenant_adapter',
    ]);
});

it('has sensible defaults', function (): void {
    expect(config('data-migrations.table'))->toBe('data_migrations');
    expect(config('data-migrations.connection'))->toBeNull();
    expect(config('data-migrations.lock.enabled'))->toBeTrue();
    expect(config('data-migrations.lock.ttl'))->toBe(1800);
    expect(config('data-migrations.checksum.enabled'))->toBeTrue();
    expect(config('data-migrations.checksum.fail_on_drift'))->toBeTrue();
    expect(config('data-migrations.tenant_adapter'))->toBeNull();
    expect(config('data-migrations.extra_central_paths'))->toBe([]);
    expect(config('data-migrations.extra_tenant_paths'))->toBe([]);
    expect(config('data-migrations.extra_paths'))->toBe([]);
});
