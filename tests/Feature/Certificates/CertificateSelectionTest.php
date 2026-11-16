<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\PreflightWarning;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Events\SalePreflightWarned;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Jobs\ResubmitSale;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Gunner42\LaravelEetModule\Tests\Support\SoapMessage;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Certificate selection is tested without Eet::fake(), through Http::fake()
 * and certificates converted to PEM (R57). Playground certificates are valid
 * until 2027-05-14, so time is always frozen.
 */

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
});

function signingFingerprint(int $index = -1): string
{
    $messages = sentSoapMessages();
    $message = $index < 0 ? end($messages) : $messages[$index];

    if (! $message instanceof SoapMessage) {
        throw new RuntimeException('Nothing was sent.');
    }

    return $message->tokenFingerprint();
}

/**
 * Re-encrypts the stored key with another APP_KEY, as after a key rotation
 * without APP_PREVIOUS_KEYS.
 */
function encryptKeyWithForeignAppKey(CashRegisterCertificate $certificate): void
{
    $foreign = new Encrypter(base64_decode('b3RoZXItYXBwLWtleS1mb3ItZWV0LW1vZHVsZS0zMmI='), 'aes-256-cbc');

    DB::table('eet_cash_register_certificates')
        ->where('id', $certificate->getKey())
        ->update(['private_key' => $foreign->encryptString(Fixtures::read('playground/CZ00000019.key.pem'))]);
}

function pin(CashRegister $cashRegister, CashRegisterCertificate $certificate): CashRegister
{
    $cashRegister->certificate()->associate($certificate);
    $cashRegister->save();

    return $cashRegister;
}

it('signs with the pinned certificate when it is valid [R16]', function (): void {
    confirmingEetService();
    $taxpayer = taxpayer();
    $pinned = importFixture($taxpayer, 'playground/CZ00000019.pem');
    importFixture($taxpayer, 'cash-register/CZ00000019-until-2027-12-31.pem');
    $unit = Eet::createRegistrationUnit($taxpayer, 181);
    $cashRegister = Eet::createCashRegister($unit, 'POKLADNA-1', null, $pinned);

    $sale = register($cashRegister);

    expect(signingFingerprint())->toBe(Fixtures::fingerprint('playground/CZ00000019.cert.pem'))
        ->and($sale->status)->toBe(SaleStatus::Confirmed)
        ->and(lastAttempt($sale)->certificate_id)->toBe($pinned->getKey())
        ->and(strtolower((string) lastAttempt($sale)->certificate_fingerprint))->toBe(Fixtures::fingerprint('playground/CZ00000019.cert.pem'));
});

it('signs with the valid certificate with the latest validTo when none is pinned [R16]', function (): void {
    confirmingEetService();
    $taxpayer = taxpayer();
    importFixture($taxpayer, 'cash-register/CZ00000019-until-2027-12-31.pem');
    importFixture($taxpayer, 'playground/CZ00000019.pem');

    register(cashRegister());

    expect(signingFingerprint())->toBe(Fixtures::fingerprint('cash-register/CZ00000019-until-2027-12-31.pem'));
});

it('prefers the later imported certificate when validTo is equal [R16]', function (): void {
    confirmingEetService();
    $taxpayer = taxpayer();
    importFixture($taxpayer, 'cash-register/CZ00000019-until-2027-10-01-a.pem');
    importFixture($taxpayer, 'cash-register/CZ00000019-until-2027-10-01-b.pem');

    register(cashRegister());

    expect(signingFingerprint())->toBe(Fixtures::fingerprint('cash-register/CZ00000019-until-2027-10-01-b.pem'));
});

it('falls back to a valid taxpayer certificate when the pinned one has expired [R16]', function (): void {
    confirmingEetService();
    $taxpayer = taxpayer();
    $pinned = importFixture($taxpayer, 'cash-register/CZ00000019-until-2027-03-01-noon.pem');
    importFixture($taxpayer, 'playground/CZ00000019.pem');
    $cashRegister = pin(cashRegister(), $pinned);

    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));
    register($cashRegister, saleData('1'));
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:01', 'UTC'));
    register($cashRegister, saleData('2'));

    expect(signingFingerprint(0))->toBe(Fixtures::fingerprint('cash-register/CZ00000019-until-2027-03-01-noon.pem'))
        ->and(signingFingerprint(1))->toBe(Fixtures::fingerprint('playground/CZ00000019.cert.pem'));
});

it('prefers certificates with CN equal to the taxpayer EIČ and uses another CN only when none is left [R16, R14, R21]', function (): void {
    confirmingEetService();
    Event::fake([SalePreflightWarned::class]);
    $taxpayer = taxpayer('CZ00000019');
    importFixture($taxpayer, 'playground/CZ00000019.pem');
    importFixture($taxpayer, 'cash-register/CZ683555118-until-2027-12-31.pem');
    $cashRegister = cashRegister();

    $first = register($cashRegister, saleData('1'));

    expect(signingFingerprint())->toBe(Fixtures::fingerprint('playground/CZ00000019.cert.pem'))
        ->and($first->preflightWarnings())->not->toContain(PreflightWarning::CertificateEicMismatch);

    $this->travelTo(CarbonImmutable::parse('2027-05-15T10:00:00+02:00'));
    $second = register($cashRegister, saleData('2'));

    expect(signingFingerprint())->toBe(Fixtures::fingerprint('cash-register/CZ683555118-until-2027-12-31.pem'))
        ->and(freshSale($second)->preflightWarnings())->toContain(PreflightWarning::CertificateEicMismatch)
        ->and($second->status)->toBe(SaleStatus::Confirmed);
});

it('does not select a certificate before its validFrom [R11, R16]', function (): void {
    confirmingEetService();
    importFixture(taxpayer(), 'cash-register/CZ00000019-from-2027-01-01.pem');
    $cashRegister = cashRegister();

    $this->travelTo(CarbonImmutable::parse('2026-12-31T23:59:59+01:00'));
    $before = register($cashRegister, saleData('1'));

    expect(lastAttempt($before)->outcome)->toBe(AttemptOutcome::CertificateUnavailable)
        ->and(Http::recorded())->toHaveCount(0);

    $this->travelTo(CarbonImmutable::parse('2027-01-01T01:00:00+01:00'));
    register($cashRegister, saleData('2'));

    expect(signingFingerprint())->toBe(Fixtures::fingerprint('cash-register/CZ00000019-from-2027-01-01.pem'));
});

it('never uses a certificate of another taxpayer and ends with CertificateUnavailable [R16]', function (): void {
    confirmingEetService();
    importFixture(taxpayer('CZ683555118'), 'playground/CZ683555118.pem');
    $cashRegister = cashRegister('CZ00000019');

    $sale = register($cashRegister);

    $attempt = lastAttempt($sale);
    expect(Http::recorded())->toHaveCount(0)
        ->and($attempt->outcome)->toBe(AttemptOutcome::CertificateUnavailable)
        ->and($attempt->wasSent())->toBeFalse()
        ->and($attempt->certificate_id)->toBeNull()
        ->and($sale->status)->toBe(SaleStatus::Unconfirmed);
    Queue::assertPushed(ResubmitSale::class);
});

it('uses a playground certificate for a taxpayer in production; EET rejects it with code 4 [R16, R60, R33]', function (): void {
    failingEetService(4, Environment::Production);
    importFixture(taxpayer('CZ00000019', Environment::Production), 'playground/CZ00000019.pem');

    $sale = register(cashRegister('CZ00000019', Environment::Production));

    expect(signingFingerprint())->toBe(Fixtures::fingerprint('playground/CZ00000019.cert.pem'))
        ->and(Http::recorded()[0][0]->url())->toBe('https://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4')
        ->and($sale->status)->toBe(SaleStatus::Rejected)
        ->and(lastAttempt($sale)->error_code)->toBe(4);
});

it('reports a certificate whose key cannot be decrypted as unusable and skips it [R69, R16]', function (): void {
    confirmingEetService();
    $taxpayer = taxpayer();
    $broken = importFixture($taxpayer, 'cash-register/CZ00000019-until-2027-12-31.pem');
    importFixture($taxpayer, 'playground/CZ00000019.pem');
    encryptKeyWithForeignAppKey($broken);
    $cashRegister = pin(cashRegister(), $broken);

    $sale = register($cashRegister);

    expect(CashRegisterCertificate::query()->findOrFail($broken->getKey())->isUsable())->toBeFalse()
        ->and(signingFingerprint())->toBe(Fixtures::fingerprint('playground/CZ00000019.cert.pem'))
        ->and($sale->status)->toBe(SaleStatus::Confirmed);
});

it('ends with CertificateUnavailable without an exception when the only key cannot be decrypted [R69]', function (): void {
    confirmingEetService();
    encryptKeyWithForeignAppKey(importFixture(taxpayer(), 'playground/CZ00000019.pem'));

    $sale = register(cashRegister());

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::CertificateUnavailable)
        ->and($sale->status)->toBe(SaleStatus::Unconfirmed)
        ->and(Http::recorded())->toHaveCount(0);
});

it('ignores certificate.* configuration keys [R17]', function (): void {
    confirmingEetService();
    config()->set('laravel-eet-module.certificate', [
        'path' => Fixtures::path('playground/CZ00000019.pem'),
        'password' => null,
        'contents' => Fixtures::read('playground/CZ00000019.pem'),
    ]);

    $sale = register(cashRegister());

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::CertificateUnavailable)
        ->and(Http::recorded())->toHaveCount(0);
});

it('selects the certificate again at every attempt [R16, R42]', function (): void {
    failingEetService(-1);
    $taxpayer = taxpayer();
    importFixture($taxpayer, 'playground/CZ00000019.pem');
    $sale = register(cashRegister());

    importFixture($taxpayer, 'cash-register/CZ00000019-until-2027-12-31.pem');
    Eet::resubmit(freshSale($sale));

    expect(signingFingerprint(0))->toBe(Fixtures::fingerprint('playground/CZ00000019.cert.pem'))
        ->and(signingFingerprint(1))->toBe(Fixtures::fingerprint('cash-register/CZ00000019-until-2027-12-31.pem'));
});

it('stores is_first_submission = false for CertificateUnavailable after a message was sent [R29]', function (): void {
    failingEetService(-1);
    $certificate = importFixture(taxpayer(), 'playground/CZ00000019.pem');
    $sale = register(cashRegister());

    encryptKeyWithForeignAppKey($certificate);
    Eet::resubmit(freshSale($sale));

    expect(attemptsOf($sale)->pluck('is_first_submission')->all())->toBe([true, false])
        ->and(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::CertificateUnavailable)
        ->and(Http::recorded())->toHaveCount(1);
});

it('sends prvni_zaslani="true" with the first message actually sent after CertificateUnavailable [R29]', function (): void {
    confirmingEetService();
    $sale = register(cashRegister());

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::CertificateUnavailable)
        ->and(lastAttempt($sale)->is_first_submission)->toBeTrue();

    importFixture(taxpayer(), 'playground/CZ00000019.pem');
    Eet::resubmit(freshSale($sale));

    expect(sentSoapMessages())->toHaveCount(1)
        ->and(sentSoapMessages()[0]->header()['prvni_zaslani'])->toBe('true')
        ->and(lastAttempt($sale)->is_first_submission)->toBeTrue()
        ->and(freshSale($sale)->status)->toBe(SaleStatus::Confirmed);
});
