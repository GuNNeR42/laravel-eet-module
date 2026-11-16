<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Actions\VerifyConnection;
use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Data\VerificationResult;
use Gunner42\LaravelEetModule\Data\Warning;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Enums\SendingMode;
use Gunner42\LaravelEetModule\Enums\WarningCode;
use Gunner42\LaravelEetModule\Events\SaleConfirmed;
use Gunner42\LaravelEetModule\Events\SaleRejected;
use Gunner42\LaravelEetModule\Events\SaleUnconfirmed;
use Gunner42\LaravelEetModule\Events\SaleVerified;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\SubmissionAttempt;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Gunner42\LaravelEetModule\Tests\Support\SoapMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
});

it('sends sales with overeni="true" in verification mode and stores the mode with the sale [R46, R23]', function (): void {
    config()->set('laravel-eet-module.mode', 'verification');
    Eet::fake();

    $sale = register(cashRegister());

    expect($sale->mode)->toBe(SendingMode::Verification)
        ->and($sale->status)->toBe(SaleStatus::Verified);
    Eet::assertSent(fn (SaleMessage $message): bool => $message->verificationMode);
});

it('stores the live mode with sales in live mode [R46, R23]', function (): void {
    Eet::fake();

    expect(register(cashRegister())->mode)->toBe(SendingMode::Live);
    Eet::assertSent(fn (SaleMessage $message): bool => ! $message->verificationMode);
});

it('verifies the connection in verification mode without storing anything or dispatching events [R47]', function (): void {
    Eet::fake();
    fakeEetEvents();
    $cashRegister = cashRegister('CZ00000019', Environment::Playground, 181, 'POKLADNA-1');

    $result = Eet::verifyConnection($cashRegister);

    expect($result)->toBeInstanceOf(VerificationResult::class)
        ->and($result->outcome)->toBe(AttemptOutcome::Verified)
        ->and($result->errorCode)->toBe(0)
        ->and(Sale::query()->count())->toBe(0)
        ->and(SubmissionAttempt::query()->count())->toBe(0);
    Eet::assertSent(fn (SaleMessage $message): bool => $message->verificationMode
        && $message->sequenceNumber === 'OVERENI'
        && $message->totalAmount === '0.00'
        && $message->occurredAt === '2026-11-20T10:00:00+01:00'
        && $message->cashRegisterId === 'POKLADNA-1'
        && $message->registrationUnitId === 181
        && $message->eic === 'CZ00000019');
    Event::assertNotDispatched(SaleVerified::class);
    Event::assertNotDispatched(SaleConfirmed::class);
    Event::assertNotDispatched(SaleRejected::class);
    Event::assertNotDispatched(SaleUnconfirmed::class);
    Queue::assertNothingPushed();
});

it('uses the configured verification sequence number [R47]', function (): void {
    config()->set('laravel-eet-module.verification.sequence_number', 'TEST/1');
    Eet::fake();

    Eet::verifyConnection(cashRegister());

    Eet::assertSent(fn (SaleMessage $message): bool => $message->sequenceNumber === 'TEST/1');
});

it('rejects a verification sequence number outside the mask [R47, R68]', function (): void {
    config()->set('laravel-eet-module.verification.sequence_number', 'OVĚŘENÍ');
    Eet::fake();

    try {
        Eet::verifyConnection(cashRegister());

        throw new AssertionFailedError('InvalidConfiguration was not thrown.');
    } catch (InvalidConfiguration $exception) {
        expect($exception->key)->toBe('verification.sequence_number');
    }

    Eet::assertNothingSent();
});

it('signs the verification message and sends it to the current environment of the taxpayer [R47, R16, R31]', function (): void {
    importFixture(taxpayer('CZ00000019', Environment::Playground));
    $cashRegister = cashRegister();
    Eet::changeEnvironment(taxpayer(), Environment::Production);
    fakeEetService(fn (SoapMessage $message): EetResponse => EetResponse::error($message->uuid(), 0, 'Datovou zpravu evidovane trzby v overovacim modu se podarilo zpracovat')
        ->warning(1, 'EIC poplatnika v datove zprave se neshoduje s EIC v certifikatu'), 'gid-verify-1');

    $result = Eet::verifyConnection($cashRegister->fresh() ?? $cashRegister);

    $message = sentSoapMessages()[0];

    expect(Http::recorded()[0][0]->url())->toBe('https://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4')
        ->and($message->header()['overeni'])->toBe('true')
        ->and($message->verifySignature()['valid'])->toBeTrue()
        ->and($message->tokenFingerprint())->toBe(Fixtures::fingerprint('playground/CZ00000019.cert.pem'))
        ->and($result->outcome)->toBe(AttemptOutcome::Verified)
        ->and($result->errorCode)->toBe(0)
        ->and($result->errorMessage)->toBe('Datovou zpravu evidovane trzby v overovacim modu se podarilo zpracovat')
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0])->toBeInstanceOf(Warning::class)
        ->and($result->warnings[0]->type)->toBe(WarningCode::EicMismatchesCertificate)
        ->and($result->globalTransactionId)->toBe('gid-verify-1')
        ->and(Sale::query()->count())->toBe(0)
        ->and(SubmissionAttempt::query()->count())->toBe(0);
});

it('returns the outcome, code and text of a rejected verification [R47]', function (): void {
    importFixture(taxpayer());
    fakeEetService(fn (SoapMessage $message): EetResponse => EetResponse::error($message->uuid(), 4, 'Neplatny podpis SOAP zpravy')->test('true'));

    $result = Eet::verifyConnection(cashRegister());

    expect($result->outcome)->toBe(AttemptOutcome::Rejected)
        ->and($result->errorCode)->toBe(4)
        ->and($result->errorMessage)->toBe('Neplatny podpis SOAP zpravy')
        ->and(Sale::query()->count())->toBe(0);
});

it('validates the configuration before verifying the connection [R47, R68]', function (): void {
    config()->set('laravel-eet-module.response_timeout_ms', 1000);
    Eet::fake();

    expect(fn (): VerificationResult => Eet::verifyConnection(cashRegister()))->toThrow(InvalidConfiguration::class);
    Eet::assertNothingSent();
});

it('exposes the connection check as an action [R47]', function (): void {
    Eet::fake([FakeResponse::error(0)]);

    expect(app(VerifyConnection::class)->handle(cashRegister())->outcome)->toBe(AttemptOutcome::Verified);
});
