<?php

use H3mantd\DataMigrations\Services\LockService;

beforeEach(function () {
    config()->set('data-migrations.lock.enabled', true);
    config()->set('data-migrations.lock.ttl', 1800);
    $this->service = new LockService;
});

it('acquires and releases lock', function () {
    $acquired = $this->service->acquire();
    expect($acquired)->toBeTrue();
    $this->service->release();
    $acquired = $this->service->acquire();
    expect($acquired)->toBeTrue();
    $this->service->release();
});

it('fails to acquire lock when already held', function () {
    $this->service->acquire();
    $secondService = new LockService;
    $acquired = $secondService->acquire();
    expect($acquired)->toBeFalse();
    $this->service->release();
});

it('skips locking when disabled', function () {
    config()->set('data-migrations.lock.enabled', false);
    $service = new LockService;
    $acquired = $service->acquire();
    expect($acquired)->toBeTrue();
    $another = new LockService;
    expect($another->acquire())->toBeTrue();
});
