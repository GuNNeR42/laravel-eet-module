<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Actions\CreateCashRegister;
use Gunner42\LaravelEetModule\Exceptions\CertificateTaxpayerMismatch;
use Gunner42\LaravelEetModule\Exceptions\DuplicateCashRegister;
use Gunner42\LaravelEetModule\Exceptions\InvalidIdentifier;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\CashRegister;

beforeEach(function (): void {
    // playground certificates are valid until 2027-05-14
    $this->travelTo(frozenNow());
});

it('creates a cash register with an identifier matching the mask [R6]', function (string $identifier): void {
    $unit = Eet::createRegistrationUnit(taxpayer(), 181);

    $cashRegister = Eet::createCashRegister($unit, $identifier, 'Pokladna');

    expect($cashRegister)->toBeInstanceOf(CashRegister::class)
        ->and($cashRegister->identifier)->toBe($identifier)
        ->and($cashRegister->name)->toBe('Pokladna')
        ->and($cashRegister->registrationUnit->is($unit))->toBeTrue()
        ->and($cashRegister->taxpayer->is(taxpayer()))->toBeTrue()
        ->and($cashRegister->certificate)->toBeNull();
})->with([
    'MF example' => '5a/A-q/5:22d_2',
    'twenty characters' => '12345678901234567890',
    'single character' => 'A',
    'all allowed punctuation' => '.,:;/#-_ ',
]);

it('rejects a cash register identifier outside the mask and stores nothing [R6]', function (string $identifier): void {
    $unit = Eet::createRegistrationUnit(taxpayer(), 181);

    try {
        Eet::createCashRegister($unit, $identifier);
        $this->fail('InvalidIdentifier was not thrown.');
    } catch (InvalidIdentifier $exception) {
        expect($exception->field)->toBe('cashRegisterId');
    }

    expect(CashRegister::query()->count())->toBe(0);
})->with([
    'empty' => '',
    'twenty-one characters' => '123456789012345678901',
    'en dash (non-ASCII)' => 'POKLADNA–1',
    'diacritics' => 'pokladnaž',
    'plus sign' => 'A+B',
    'newline' => "A\nB",
]);

it('rejects a duplicate cash register identifier within one registration unit [R7]', function (): void {
    $unit = Eet::createRegistrationUnit(taxpayer(), 181);
    Eet::createCashRegister($unit, 'POKLADNA-1');

    expect(fn (): CashRegister => Eet::createCashRegister($unit, 'POKLADNA-1'))->toThrow(DuplicateCashRegister::class)
        ->and(CashRegister::query()->count())->toBe(1);
});

it('allows the same cash register identifier in two registration units [R7]', function (): void {
    $taxpayer = taxpayer();
    Eet::createCashRegister(Eet::createRegistrationUnit($taxpayer, 181), 'POKLADNA-1');
    Eet::createCashRegister(Eet::createRegistrationUnit($taxpayer, 241), 'POKLADNA-1');

    expect(CashRegister::query()->where('identifier', 'POKLADNA-1')->count())->toBe(2);
});

it('pins a certificate of the same taxpayer to the cash register [R8]', function (): void {
    $taxpayer = taxpayer();
    $certificate = importFixture($taxpayer);

    $cashRegister = Eet::createCashRegister(Eet::createRegistrationUnit($taxpayer, 181), 'POKLADNA-1', null, $certificate);

    expect($cashRegister->certificate?->is($certificate))->toBeTrue();
});

it('rejects pinning a certificate of another taxpayer [R8]', function (): void {
    $foreignCertificate = importFixture(taxpayer('CZ683555118'), 'playground/CZ683555118.pem');
    $unit = Eet::createRegistrationUnit(taxpayer('CZ00000019'), 181);

    expect(fn (): CashRegister => Eet::createCashRegister($unit, 'POKLADNA-1', null, $foreignCertificate))
        ->toThrow(CertificateTaxpayerMismatch::class)
        ->and(CashRegister::query()->count())->toBe(0);
});

it('exposes cash register creation as an action [R6]', function (): void {
    $unit = Eet::createRegistrationUnit(taxpayer(), 181);

    expect(app(CreateCashRegister::class)->handle($unit, 'POKLADNA-9', null, null)->identifier)->toBe('POKLADNA-9');
});
