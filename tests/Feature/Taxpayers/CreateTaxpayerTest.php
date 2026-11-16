<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Actions\ChangeTaxpayerEnvironment;
use Gunner42\LaravelEetModule\Actions\CreateTaxpayer;
use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Exceptions\DuplicateTaxpayer;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Exceptions\InvalidEic;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->travelTo(frozenNow());
});

it('creates a taxpayer with a valid EIČ [R1]', function (string $eic): void {
    $taxpayer = Eet::createTaxpayer($eic, 'Firma s.r.o.');

    expect($taxpayer)->toBeInstanceOf(Taxpayer::class)
        ->and($taxpayer->eic)->toBe($eic)
        ->and($taxpayer->name)->toBe('Firma s.r.o.')
        ->and(Taxpayer::query()->where('eic', $eic)->exists())->toBeTrue();
})->with(['CZ00000019', 'CZ683555118', 'CZ8551015704', 'CZ1234567890']);

it('rejects an invalid EIČ and stores nothing [R1]', function (string $eic): void {
    try {
        Eet::createTaxpayer($eic, 'Firma');
        $this->fail('InvalidEic was not thrown.');
    } catch (InvalidEic $exception) {
        expect($exception->value)->toBe($eic);
    }

    expect(Taxpayer::query()->count())->toBe(0);
})->with([
    'seven digits' => 'CZ1234567',
    'eleven digits' => 'CZ12345678901',
    'lower-case prefix' => 'cz00000019',
    'foreign prefix' => 'SK12345678',
    'DIČ with letters' => 'CZ0000001A',
    'empty' => '',
    'whitespace' => ' CZ00000019',
]);

it('rejects a second taxpayer with the same EIČ [R2]', function (): void {
    Eet::createTaxpayer('CZ00000019', 'První');

    expect(fn (): Taxpayer => Eet::createTaxpayer('CZ00000019', 'Druhý'))->toThrow(DuplicateTaxpayer::class)
        ->and(Taxpayer::query()->count())->toBe(1);
});

it('allows taxpayers with different EIČs [R2]', function (): void {
    Eet::createTaxpayer('CZ00000019');
    Eet::createTaxpayer('CZ683555118');

    expect(Taxpayer::query()->count())->toBe(2);
});

it('uses the configured default environment when none is given [R59]', function (): void {
    expect(Eet::createTaxpayer('CZ00000019')->environment)->toBe(Environment::Playground);

    config()->set('laravel-eet-module.default_environment', 'production');

    expect(Eet::createTaxpayer('CZ683555118')->environment)->toBe(Environment::Production);
});

it('uses an explicitly given environment [R59]', function (): void {
    expect(Eet::createTaxpayer('CZ00000019', null, Environment::Production)->environment)->toBe(Environment::Production)
        ->and(Taxpayer::query()->firstOrFail()->environment)->toBe(Environment::Production);
});

it('changes the environment of a taxpayer [R59]', function (): void {
    $taxpayer = Eet::createTaxpayer('CZ00000019', null, Environment::Playground);

    $changed = Eet::changeEnvironment($taxpayer, Environment::Production);

    expect($changed->environment)->toBe(Environment::Production)
        ->and($taxpayer->fresh()?->environment)->toBe(Environment::Production);
});

it('keeps the environment of existing sales when the taxpayer environment changes [R59, R23]', function (): void {
    Eet::fake([FakeResponse::timeout(), FakeResponse::timeout()]);
    Queue::fake();
    $cashRegister = cashRegister();
    $sale = register($cashRegister);

    Eet::changeEnvironment(taxpayer(), Environment::Production);
    Eet::resubmit(freshSale($sale));

    expect(freshSale($sale)->environment)->toBe(Environment::Playground)
        ->and(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed);
    Eet::assertSent(fn (SaleMessage $message): bool => $message->isFirstSubmission === false);
    expect(register($cashRegister, saleData('2'))->environment)->toBe(Environment::Production);
});

it('rejects an invalid default environment before storing anything [R59, R68]', function (): void {
    config()->set('laravel-eet-module.default_environment', 'staging');

    try {
        Eet::createTaxpayer('CZ00000019');
        $this->fail('InvalidConfiguration was not thrown.');
    } catch (InvalidConfiguration $exception) {
        expect($exception->key)->toBe('default_environment');
    }

    expect(Taxpayer::query()->count())->toBe(0);
});

it('validates default_environment when changing the environment [R68]', function (): void {
    $taxpayer = Eet::createTaxpayer('CZ00000019', null, Environment::Playground);
    config()->set('laravel-eet-module.default_environment', 'test');

    expect(fn (): Taxpayer => Eet::changeEnvironment($taxpayer, Environment::Production))
        ->toThrow(InvalidConfiguration::class)
        ->and($taxpayer->fresh()?->environment)->toBe(Environment::Playground);
});

it('validates only default_environment when creating a taxpayer or changing its environment [R68]', function (string $key, mixed $value): void {
    config()->set('laravel-eet-module.'.$key, $value);

    $taxpayer = Eet::createTaxpayer('CZ00000019', null, Environment::Playground);
    $changed = Eet::changeEnvironment($taxpayer, Environment::Production);

    expect($changed->environment)->toBe(Environment::Production);
})->with([
    'lock store' => ['lock_store', 'does-not-exist'],
    'mode' => ['mode', 'test'],
    'response timeout' => ['response_timeout_ms', 10],
    'retention' => ['retention.raw_xml_days', 0],
]);

it('exposes the taxpayer operations as actions with the facade signature [R1, R59]', function (): void {
    $taxpayer = app(CreateTaxpayer::class)->handle('CZ00000019', 'Firma', Environment::Playground);
    $changed = app(ChangeTaxpayerEnvironment::class)->handle($taxpayer, Environment::Production);

    expect($changed->environment)->toBe(Environment::Production);
});
