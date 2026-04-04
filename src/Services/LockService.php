<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class LockService
{
    private const string LOCK_KEY = 'data-migrations:running';

    private ?Lock $lock = null;

    public function acquire(): bool
    {
        if (! config('data-migrations.lock.enabled', true)) {
            return true;
        }

        $ttlConfig = config('data-migrations.lock.ttl', 1800);
        $ttl = is_int($ttlConfig) ? $ttlConfig : 1800;
        $this->lock = Cache::lock(self::LOCK_KEY, $ttl);

        return (bool) $this->lock->get();
    }

    public function release(): void
    {
        $this->lock?->release();
        $this->lock = null;
    }
}
