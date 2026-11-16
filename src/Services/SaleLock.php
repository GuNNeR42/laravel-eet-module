<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * Atomic cache lock of one sale (R43): key laravel-eet-module:sale:{id},
 * TTL response_timeout_ms + 30 s, store lock_store.
 */
final class SaleLock
{
    public function acquire(int $saleId): ?Lock
    {
        $storeName = config('laravel-eet-module.lock_store');
        $store = Cache::store(is_string($storeName) ? $storeName : null)->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The lock store does not provide atomic locks.');
        }

        $seconds = intdiv(ConfigurationValidator::integer('response_timeout_ms') + 999, 1000) + 30;
        $lock = $store->lock('laravel-eet-module:sale:'.$saleId, $seconds);

        return $lock->get() ? $lock : null;
    }
}
