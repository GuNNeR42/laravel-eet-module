<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Exceptions\EetException;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Jobs\ResubmitSale;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
});

it('merges the package configuration with the documented defaults [R68]', function (): void {
    expect(config('laravel-eet-module.default_environment'))->toBe('playground')
        ->and(config('laravel-eet-module.mode'))->toBe('live')
        ->and(config('laravel-eet-module.endpoints.playground'))->toBe('https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4')
        ->and(config('laravel-eet-module.endpoints.production'))->toBe('https://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4')
        ->and(config('laravel-eet-module.endpoints.allowed_hosts.playground'))->toBe(['pg.trzbyeet.gov.cz'])
        ->and(config('laravel-eet-module.endpoints.allowed_hosts.production'))->toBe(['trzbyeet.gov.cz'])
        ->and(config('laravel-eet-module.response_max_bytes'))->toBe(65536)
        ->and(config('laravel-eet-module.proxy'))->toBeNull()
        ->and(config('laravel-eet-module.response_timeout_ms'))->toBe(4000)
        ->and(config('laravel-eet-module.timezone'))->toBe('Europe/Prague')
        ->and(config('laravel-eet-module.minimum_occurred_at.playground'))->toBe('2026-07-01 00:00')
        ->and(config('laravel-eet-module.minimum_occurred_at.production'))->toBe('2026-11-01 00:00')
        ->and(config('laravel-eet-module.response_trust_store'))->toBeNull()
        ->and(config('laravel-eet-module.response_trust_store_max_certificates'))->toBe(10)
        ->and(config('laravel-eet-module.response_signer.organization_identifier'))->toBe('NTRCZ-72080043')
        ->and(config('laravel-eet-module.response_signer.playground.common_name'))->toBe('NEOSTRE - playground prostredi EET')
        ->and(config('laravel-eet-module.response_signer.production.common_name'))->toBeNull()
        ->and(config('laravel-eet-module.response_clock_tolerance_seconds'))->toBe(600)
        ->and(config('laravel-eet-module.lock_store'))->toBeNull()
        ->and(config('laravel-eet-module.resubmission.connection'))->toBeNull()
        ->and(config('laravel-eet-module.resubmission.queue'))->toBeNull()
        ->and(config('laravel-eet-module.resubmission.backoff'))->toBe([60, 300, 900, 3600])
        ->and(config('laravel-eet-module.resubmission.min_backoff'))->toBe(30)
        ->and(config('laravel-eet-module.resubmission.max_ambiguous_attempts'))->toBe(3)
        ->and(config('laravel-eet-module.verification.sequence_number'))->toBe('OVERENI')
        ->and(config('laravel-eet-module.retention.raw_xml_days'))->toBeNull();
});

it('has no certificate configuration keys and no scaffold placeholder [R17]', function (): void {
    expect(config('laravel-eet-module'))->not->toHaveKey('certificate')
        ->and(config('laravel-eet-module'))->not->toHaveKey('placeholder');
});

it('rejects configuration values outside the valid values with the key before storing or sending anything [R68]', function (string $key, mixed $value): void {
    Eet::fake();
    $cashRegister = cashRegister();
    $taxpayers = Taxpayer::query()->count();
    config()->set('laravel-eet-module.'.$key, $value);

    try {
        register($cashRegister);

        throw new AssertionFailedError("InvalidConfiguration was not thrown for {$key}.");
    } catch (InvalidConfiguration $exception) {
        expect($exception->key)->toBe($key)
            ->and($exception)->toBeInstanceOf(EetException::class)
            ->and($exception)->toBeInstanceOf(RuntimeException::class);
    }

    expect(Sale::query()->count())->toBe(0)
        ->and(Taxpayer::query()->count())->toBe($taxpayers);
    Eet::assertNothingSent();
})->with([
    'mode' => ['mode', 'production'],
    'default environment' => ['default_environment', 'live'],
    'timezone' => ['timezone', 'Mars/Olympus'],
    'response timeout as string' => ['response_timeout_ms', '4000ms'],
    'minimum occurredAt playground' => ['minimum_occurred_at.playground', '1. 7. 2026'],
    'minimum occurredAt production' => ['minimum_occurred_at.production', '2026-11-01T00:00:00+01:00'],
    'proxy' => ['proxy', 'not a url'],
    'response_max_bytes' => ['response_max_bytes', 100],
    'response_trust_store_max_certificates' => ['response_trust_store_max_certificates', 1],
    'response_clock_tolerance_seconds' => ['response_clock_tolerance_seconds', -1],
    'verification.sequence_number' => ['verification.sequence_number', 'příliš dlouhé pořadové číslo tržby'],
]);

it('does not validate retention.* when sending [R68]', function (): void {
    Eet::fake();
    $cashRegister = cashRegister();
    config()->set('laravel-eet-module.retention.raw_xml_days', 0);

    expect(register($cashRegister)->exists)->toBeTrue();
    Eet::assertSentCount(1);
});

it('accepts a valid timezone and minimum dates from the configuration [R68, R24, R61]', function (): void {
    config()->set('laravel-eet-module.timezone', 'Europe/Bratislava');
    config()->set('laravel-eet-module.minimum_occurred_at.playground', '2026-01-01 00:00');
    Eet::fake();

    expect(register(cashRegister())->occurred_at)->toBe('2026-11-20T10:00:00+01:00');
});

it('validates the configuration in the job before any attempt [R68]', function (): void {
    Eet::fake([FakeResponse::timeout()]);
    $sale = register(cashRegister());
    config()->set('laravel-eet-module.mode', 'invalid');

    try {
        dispatch_sync(new ResubmitSale((int) $sale->getKey()));
    } catch (InvalidConfiguration $exception) {
        expect($exception->key)->toBe('mode');
    }

    expect(attemptsOf($sale))->toHaveCount(1);
    Eet::assertSentCount(1);
});
