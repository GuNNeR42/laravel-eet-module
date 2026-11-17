<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Enums\RenewalStatus;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\CertificateRenewal;
use Gunner42\LaravelEetModule\Tests\Support\CaEetApi;
use Gunner42\LaravelEetModule\Tests\Support\Renewal;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * R104: at most one renewal runs per certificate, guarded by the cache lock
 * laravel-eet-module:certificate:{id} in lock_store. Tests may occupy it.
 */

beforeEach(function (): void {
    $this->travelTo(Renewal::now());
    Sleep::fake(syncWithCarbon: true);
});

it('returns null and does nothing while the certificate lock is taken [R104]', function (): void {
    CaEetApi::fake();
    $certificate = Renewal::certificate();
    $lock = Cache::store()->lock('laravel-eet-module:certificate:'.$certificate->getKey(), 600);

    expect($lock->get())->toBeTrue();

    try {
        expect(Eet::renewCertificate($certificate))->toBeNull()
            ->and(CertificateRenewal::query()->count())->toBe(0);
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

it('leaves an active renewal untouched while the lock is taken [R104]', function (): void {
    $api = CaEetApi::fake();
    $certificate = Renewal::certificate();
    $renewal = Renewal::insert($certificate, RenewalStatus::InProcess);
    $lock = Cache::store()->lock('laravel-eet-module:certificate:'.$certificate->getKey(), 600);
    $lock->get();

    try {
        expect(Eet::renewCertificate($certificate))->toBeNull()
            ->and(Renewal::latest($certificate)->status)->toBe(RenewalStatus::InProcess)
            ->and(Renewal::latest($certificate)->updated_at?->equalTo($renewal->updated_at))->toBeTrue()
            ->and($api->count())->toBe(0);
    } finally {
        $lock->release();
    }
});

it('locks only the certificate being renewed [R104]', function (): void {
    CaEetApi::fake();
    $certificate = Renewal::certificate();
    $other = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');
    $lock = Cache::store()->lock('laravel-eet-module:certificate:'.$other->getKey(), 600);
    $lock->get();

    try {
        expect(Eet::renewCertificate($certificate)?->status)->toBe(RenewalStatus::Completed);
    } finally {
        $lock->release();
    }
});

it('holds the lock during the API calls and releases it afterwards [R104]', function (): void {
    $api = CaEetApi::fake();
    $certificate = Renewal::certificate();
    $key = 'laravel-eet-module:certificate:'.$certificate->getKey();
    $free = [];
    $api->observe(function (string $route, Request $request) use (&$free, $key): void {
        $probe = Cache::store()->lock($key, 10);
        $acquired = $probe->get();
        $free[] = $acquired;

        if ($acquired) {
            $probe->release();
        }
    });

    Eet::renewCertificate($certificate);

    $after = Cache::store()->lock($key, 10);

    expect($free)->toHaveCount(5)
        ->and(array_unique($free))->toBe([false])
        ->and($after->get())->toBeTrue();

    $after->release();
});

it('releases the lock when the renewal ends with a problem [R104]', function (): void {
    CaEetApi::fake(['renew' => CaEetApi::error(500)]);
    $certificate = Renewal::certificate();

    Eet::renewCertificate($certificate);

    $lock = Cache::store()->lock('laravel-eet-module:certificate:'.$certificate->getKey(), 10);

    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('takes the lock in the configured lock_store [R104, R43]', function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eet-renewal-lock-'.bin2hex(random_bytes(8));
    config()->set('cache.stores.renewal-locks', ['driver' => 'file', 'path' => $directory, 'lock_path' => $directory]);
    config()->set('laravel-eet-module.lock_store', 'renewal-locks');
    CaEetApi::fake();
    $certificate = Renewal::certificate();
    $key = 'laravel-eet-module:certificate:'.$certificate->getKey();
    $default = Cache::store()->lock($key, 600);
    $default->get();

    try {
        // the lock in the default store does not matter
        expect(Eet::renewCertificate($certificate)?->status)->toBe(RenewalStatus::Completed);

        $other = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');
        $configured = Cache::store('renewal-locks')->lock('laravel-eet-module:certificate:'.$other->getKey(), 600);
        $configured->get();

        expect(Eet::renewCertificate($other))->toBeNull();
        $configured->release();
    } finally {
        $default->release();
        File::deleteDirectory($directory);
    }
});

it('holds the lock for max_wait_seconds + 5 × timeout + 60 s [R104]', function (int $maxWait, int $timeoutMs, int $ttl): void {
    config()->set('laravel-eet-module.renewal.max_wait_seconds', $maxWait);
    config()->set('laravel-eet-module.renewal.timeout_ms', $timeoutMs);
    $api = CaEetApi::fake();
    $certificate = Renewal::certificate();
    $key = 'laravel-eet-module:certificate:'.$certificate->getKey();
    $probes = [];
    $api->observe(function (string $route, Request $request) use (&$probes, $key, $ttl): void {
        if ($route !== 'not-finished') {
            return;
        }

        foreach ([$ttl - 1, $ttl + 1] as $offset) {
            $this->travelTo(Renewal::now()->addSeconds($offset));
            $probe = Cache::store()->lock($key, 10);
            $acquired = $probe->get();
            $probes[$offset] = $acquired;

            if ($acquired) {
                $probe->release();
            }
        }

        $this->travelTo(Renewal::now());
    });

    Eet::renewCertificate($certificate);

    expect($probes)->toBe([$ttl - 1 => false, $ttl + 1 => true]);
})->with([
    'defaults' => [120, 10000, 230],
    'configured' => [30, 2000, 100],
]);
