<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * Atomic cache locks in the configured lock_store (R43, R104).
 */
final class LockStore
{
    /**
     * The acquired lock, or null when another process holds it.
     */
    public function acquire(string $key, int $seconds): ?Lock
    {
        $storeName = config('laravel-eet-module.lock_store');
        $store = Cache::store(is_string($storeName) ? $storeName : null)->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The lock store does not provide atomic locks.');
        }

        $lock = $store->lock($key, $seconds);

        return $lock->get() ? $lock : null;
    }
}
