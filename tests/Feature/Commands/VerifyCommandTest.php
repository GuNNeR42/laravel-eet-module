<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Data\Warning;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\WarningCode;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Gunner42\LaravelEetModule\Tests\Support\SoapMessage;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
});

it('verifies the connection of a cash register and exits with 0 on Verified [R55, R47]', function (): void {
    Eet::fake();
    cashRegister('CZ00000019', Environment::Playground, 181, 'POKLADNA-1');

    $this->artisan('eet:verify', ['eic' => 'CZ00000019', 'registration-unit' => '181', 'cash-register' => 'POKLADNA-1'])
        ->expectsOutputToContain(AttemptOutcome::Verified->label())
        ->assertExitCode(0);

    expect(Sale::query()->count())->toBe(0);
    Eet::assertSentCount(1);
});

it('prints the code, text, warnings and the global transaction id [R55]', function (): void {
    importFixture(taxpayer());
    cashRegister('CZ00000019', Environment::Playground, 181, 'POKLADNA-1');
    fakeEetService(fn (SoapMessage $message): EetResponse => EetResponse::error($message->uuid(), 0, 'Datovou zpravu evidovane trzby v overovacim modu se podarilo zpracovat')
        ->test('true')
        ->warning(6, 'id_jednotky neodpovida formatem pridelenemu c. evidencni jednotky'), 'gid-verify-42');

    $this->artisan('eet:verify', ['eic' => 'CZ00000019', 'registration-unit' => '181', 'cash-register' => 'POKLADNA-1'])
        ->expectsOutputToContain('Datovou zpravu evidovane trzby v overovacim modu se podarilo zpracovat')
        ->expectsOutputToContain('id_jednotky neodpovida formatem')
        ->expectsOutputToContain('gid-verify-42')
        ->assertExitCode(0);
});

it('exits with 1 when the result is not Verified [R55]', function (FakeResponse $response): void {
    Eet::fake([$response]);
    cashRegister('CZ00000019', Environment::Playground, 181, 'POKLADNA-1');

    $this->artisan('eet:verify', ['eic' => 'CZ00000019', 'registration-unit' => '181', 'cash-register' => 'POKLADNA-1'])
        ->assertExitCode(1);
})->with([
    'rejected' => fn (): FakeResponse => FakeResponse::error(4, null, [new Warning(1, WarningCode::EicMismatchesCertificate, 'EIC')]),
    'timeout' => fn (): FakeResponse => FakeResponse::timeout(),
    'transport error' => fn (): FakeResponse => FakeResponse::transportError(),
]);

it('prints an error and exits with 1 for a cash register that does not exist [R55]', function (array $arguments): void {
    Eet::fake();
    cashRegister('CZ00000019', Environment::Playground, 181, 'POKLADNA-1');

    $this->artisan('eet:verify', $arguments)->assertExitCode(1);

    Eet::assertNothingSent();
})->with([
    'unknown taxpayer' => [['eic' => 'CZ683555118', 'registration-unit' => '181', 'cash-register' => 'POKLADNA-1']],
    'unknown registration unit' => [['eic' => 'CZ00000019', 'registration-unit' => '241', 'cash-register' => 'POKLADNA-1']],
    'unknown cash register' => [['eic' => 'CZ00000019', 'registration-unit' => '181', 'cash-register' => 'POKLADNA-2']],
]);

it('prints the configuration key and exits with 1 on an invalid configuration [R55, R68]', function (): void {
    Eet::fake();
    cashRegister('CZ00000019', Environment::Playground, 181, 'POKLADNA-1');
    config()->set('laravel-eet-module.verification.sequence_number', '');

    $this->artisan('eet:verify', ['eic' => 'CZ00000019', 'registration-unit' => '181', 'cash-register' => 'POKLADNA-1'])
        ->expectsOutputToContain('verification.sequence_number')
        ->assertExitCode(1);

    Eet::assertNothingSent();
});
