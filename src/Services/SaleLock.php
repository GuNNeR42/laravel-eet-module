<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Illuminate\Contracts\Cache\Lock;

/**
 * Atomic cache lock of one sale (R43): key laravel-eet-module:sale:{id},
 * TTL response_timeout_ms + 30 s, store lock_store.
 */
final class SaleLock
{
    public function __construct(private readonly LockStore $locks) {}

    public function acquire(int $saleId): ?Lock
    {
        $seconds = intdiv(ConfigurationValidator::integer('response_timeout_ms') + 999, 1000) + 30;

        return $this->locks->acquire('laravel-eet-module:sale:'.$saleId, $seconds);
    }
}
