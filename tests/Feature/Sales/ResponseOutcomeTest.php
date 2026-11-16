<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Data\Warning;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\ErrorCode;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Enums\WarningCode;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Jobs\ResubmitSale;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Illuminate\Support\Facades\Queue;

/*
 * Signed responses come from the own test CA (response_trust_store) and go
 * through the full processing via FakeResponse::raw() (R57). The message UUID
 * is fixed with createMessageUuidsUsing() so the response can echo it.
 */

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
    useTestResponseCa();
});

/**
 * @param  array<string, string>  $headers
 */
function rawSale(string $xml, Environment $environment = Environment::Playground, array $headers = [], int $status = 200): Sale
{
    useMessageUuids(testUuid(1));
    Eet::fake([FakeResponse::raw($xml, $status, $headers)]);

    return register(cashRegister('CZ00000019', $environment));
}

it('confirms a playground sale with a valid signed Potvrzeni [R33, R36]', function (): void {
    $pok = EetResponse::confirmationCodeFor(testUuid(1), 'ff');

    $sale = rawSale(EetResponse::confirmed(testUuid(1), $pok)->test('true')->toXml());

    expect($sale->status)->toBe(SaleStatus::Confirmed)
        ->and(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::Confirmed)
        ->and($sale->confirmation_code)->toBe($pok)
        ->and($sale->is_test)->toBeTrue()
        ->and($sale->hasValidConfirmationCode())->toBeFalse();
    Queue::assertNothingPushed();
});

it('confirms a production sale with a valid signed Potvrzeni and a valid POK [R33, R36]', function (): void {
    $pok = EetResponse::confirmationCodeFor(testUuid(1), '01');

    $sale = rawSale(EetResponse::confirmed(testUuid(1), $pok)->signedBy('signer-production')->toXml(), Environment::Production);

    expect($sale->status)->toBe(SaleStatus::Confirmed)
        ->and($sale->confirmation_code)->toBe($pok)
        ->and($sale->is_test)->toBeFalse()
        ->and($sale->hasValidConfirmationCode())->toBeTrue()
        ->and(CarbonImmutable::parse($sale->confirmed_at)->equalTo(frozenNow()))->toBeTrue();
});

it('verifies a sale in verification mode with Chyba kod="0" [R33, R46]', function (): void {
    config()->set('laravel-eet-module.mode', 'verification');

    $sale = rawSale(EetResponse::error(testUuid(1), 0, 'Datovou zpravu evidovane trzby v overovacim modu se podarilo zpracovat')->test('true')->toXml());

    expect($sale->status)->toBe(SaleStatus::Verified)
        ->and(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::Verified)
        ->and($sale->confirmation_code)->toBeNull()
        ->and($sale->hasValidConfirmationCode())->toBeFalse();
    Queue::assertNothingPushed();
});

it('rejects a sale for error codes 2, 3, 4, 6 and 7 without resubmission [R33]', function (int $code): void {
    $sale = rawSale(EetResponse::error(testUuid(1), $code)->test('true')->toXml());

    expect($sale->status)->toBe(SaleStatus::Rejected)
        ->and(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::Rejected)
        ->and(lastAttempt($sale)->error_code)->toBe($code);
    Queue::assertNothingPushed();
})->with([2, 3, 4, 6, 7]);

it('treats Chyba kod="-1" as TemporaryError and schedules a resubmission after 60 s [R33, R40]', function (): void {
    $sale = rawSale(EetResponse::error(testUuid(1), -1, 'Docasna technicka chyba zpracovani')->test('true')->toXml());

    expect($sale->status)->toBe(SaleStatus::Unconfirmed)
        ->and(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::TemporaryError);
    Queue::assertPushed(ResubmitSale::class, fn (ResubmitSale $job): bool => jobDelay($job) === 60);
});

it('treats code 8, unknown codes and code 0 in live mode as AmbiguousError [R33]', function (int $code): void {
    $sale = rawSale(EetResponse::error(testUuid(1), $code)->test('true')->toXml());

    expect($sale->status)->toBe(SaleStatus::Unconfirmed)
        ->and(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::AmbiguousError)
        ->and(lastAttempt($sale)->error_code)->toBe($code);
    Queue::assertPushed(ResubmitSale::class);
})->with([
    'code 8' => 8,
    'reserved code 1' => 1,
    'code 5 used before v4' => 5,
    'unknown code 999' => 999,
    'unknown negative code' => -2,
    'code 0 in live mode' => 0,
]);

it('treats a missing response as Timeout [R33]', function (): void {
    Eet::fake([FakeResponse::timeout()]);

    $sale = register(cashRegister());

    expect($sale->status)->toBe(SaleStatus::Unconfirmed)
        ->and(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::Timeout);
    Queue::assertPushed(ResubmitSale::class);
});

it('treats transport failures and unreadable responses as TransportError [R33]', function (FakeResponse $response): void {
    Eet::fake([$response]);

    $sale = register(cashRegister());

    expect($sale->status)->toBe(SaleStatus::Unconfirmed)
        ->and(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::TransportError);
    Queue::assertPushed(ResubmitSale::class);
})->with([
    'transport error' => fn (): FakeResponse => FakeResponse::transportError(),
    'not XML' => fn (): FakeResponse => FakeResponse::raw('<html><body>Service Unavailable</body></html>', 503),
    'malformed XML' => fn (): FakeResponse => FakeResponse::raw('<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'),
    'SOAP fault' => fn (): FakeResponse => FakeResponse::raw('<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><soapenv:Fault><faultcode>soapenv:Server</faultcode><faultstring>Internal Error</faultstring></soapenv:Fault></soapenv:Body></soapenv:Envelope>', 500),
    'empty body' => fn (): FakeResponse => FakeResponse::raw(''),
]);

it('treats a Potvrzeni with an invalid signature as InvalidResponseSignature [R33, R35]', function (): void {
    $xml = EetResponse::confirmed(testUuid(1), EetResponse::confirmationCodeFor(testUuid(1), 'ff'))->test('true')
        ->mutateXml(fn (string $xml): string => str_replace('-ff"', '-fe"', $xml))
        ->toXml();

    $sale = rawSale($xml);

    expect($sale->status)->toBe(SaleStatus::Unconfirmed)
        ->and($sale->confirmation_code)->toBeNull()
        ->and(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::InvalidResponseSignature);
    Queue::assertPushed(ResubmitSale::class);
});

it('treats mismatching responses as ResponseMismatch [R33, R36]', function (EetResponse $response, string $environment, string $mode): void {
    config()->set('laravel-eet-module.mode', $mode);

    $sale = rawSale($response->toXml(), Environment::from($environment));

    expect($sale->status)->toBe(SaleStatus::Unconfirmed)
        ->and($sale->confirmation_code)->toBeNull()
        ->and(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::ResponseMismatch);
    Queue::assertPushed(ResubmitSale::class);
})->with([
    'Potvrzeni without uuid_zpravy' => [fn (): EetResponse => EetResponse::confirmed(null, EetResponse::confirmationCodeFor(testUuid(1), 'ff'))->test('true'), 'playground', 'live'],
    'Potvrzeni with another uuid_zpravy' => [fn (): EetResponse => EetResponse::confirmed(testUuid(2), EetResponse::confirmationCodeFor(testUuid(2), 'ff'))->test('true'), 'playground', 'live'],
    'Chyba with another uuid_zpravy' => [fn (): EetResponse => EetResponse::error(testUuid(2), -1)->test('true'), 'playground', 'live'],
    'Potvrzeni in verification mode' => [fn (): EetResponse => EetResponse::confirmed(testUuid(1), EetResponse::confirmationCodeFor(testUuid(1), 'ff'))->test('true'), 'playground', 'verification'],
    'production with test="true"' => [fn (): EetResponse => EetResponse::confirmed(testUuid(1))->signedBy('signer-production')->test('true'), 'production', 'live'],
    'production with test="1"' => [fn (): EetResponse => EetResponse::confirmed(testUuid(1))->signedBy('signer-production')->test('1'), 'production', 'live'],
    'production with POK ending -ff' => [fn (): EetResponse => EetResponse::confirmed(testUuid(1), EetResponse::confirmationCodeFor(testUuid(1), 'ff'))->signedBy('signer-production'), 'production', 'live'],
    'production with POK ending -FF' => [fn (): EetResponse => EetResponse::confirmed(testUuid(1), strtoupper(EetResponse::confirmationCodeFor(testUuid(1), 'ff')))->signedBy('signer-production'), 'production', 'live'],
    'playground without test' => [fn (): EetResponse => EetResponse::confirmed(testUuid(1), EetResponse::confirmationCodeFor(testUuid(1), 'ff')), 'playground', 'live'],
    'playground with test="false"' => [fn (): EetResponse => EetResponse::confirmed(testUuid(1), EetResponse::confirmationCodeFor(testUuid(1), 'ff'))->test('false'), 'playground', 'live'],
]);

it('accepts test="1" as a test flag of a playground confirmation [R33, R36]', function (): void {
    $sale = rawSale(EetResponse::confirmed(testUuid(1), EetResponse::confirmationCodeFor(testUuid(1), 'ff'))->test('1')->toXml());

    expect($sale->status)->toBe(SaleStatus::Confirmed)
        ->and($sale->is_test)->toBeTrue();
});

it('evaluates the signature before the uuid match: a changed uuid inside the signed Body is InvalidResponseSignature [R71]', function (): void {
    $xml = EetResponse::confirmed(testUuid(1), EetResponse::confirmationCodeFor(testUuid(1), 'ff'))->test('true')
        ->mutateXml(fn (string $xml): string => str_replace('uuid_zpravy="'.testUuid(1).'"', 'uuid_zpravy="'.testUuid(2).'"', $xml))
        ->toXml();

    expect(lastAttempt(rawSale($xml))->outcome)->toBe(AttemptOutcome::InvalidResponseSignature);
});

it('evaluates the uuid match before the classification: Chyba kod="4" with a foreign uuid is ResponseMismatch [R71]', function (): void {
    $sale = rawSale(EetResponse::error(testUuid(2), 4, 'Neplatny podpis SOAP zpravy')->test('true')->toXml());

    expect($sale->status)->toBe(SaleStatus::Unconfirmed)
        ->and(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::ResponseMismatch);
});

it('evaluates value masks before the signature: a 38-character POK in a validly signed response is TransportError [R71, R65]', function (): void {
    $pok = substr(EetResponse::confirmationCodeFor(testUuid(1), 'ff'), 0, 38);

    expect(lastAttempt(rawSale(EetResponse::confirmed(testUuid(1), $pok)->test('true')->toXml()))->outcome)
        ->toBe(AttemptOutcome::TransportError);
});

it('evaluates the signature before the environment match for a production sale [R71, R35]', function (): void {
    // validly signed by the playground signer, answered to a production sale
    $sale = rawSale(EetResponse::confirmed(testUuid(1), EetResponse::confirmationCodeFor(testUuid(1), 'ff'))->test('true')->toXml(), Environment::Production);

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::InvalidResponseSignature);
});

it('stores the attempt data from a confirmation [R34]', function (): void {
    $pok = EetResponse::confirmationCodeFor(testUuid(1), 'ff');

    $sale = rawSale(
        EetResponse::confirmed(testUuid(1), $pok)->test('true')->warning(4, 'Datum a cas uskutecneni trzby je novejsi')->toXml(),
        Environment::Playground,
        ['X-Global-Transaction-Id' => 'bc626a986af9dc99035fe7d0'],
    );
    $attempt = lastAttempt($sale);

    expect($attempt->message_uuid)->toBe(testUuid(1))
        ->and($attempt->is_first_submission)->toBeTrue()
        ->and(CarbonImmutable::parse($attempt->sent_at)->equalTo(frozenNow()))->toBeTrue()
        ->and($attempt->outcome)->toBe(AttemptOutcome::Confirmed)
        ->and($attempt->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($attempt->http_status)->toBe(200)
        ->and(CarbonImmutable::parse($attempt->received_at)->equalTo(frozenNow()))->toBeTrue()
        ->and($attempt->rejected_at)->toBeNull()
        ->and($attempt->confirmation_code)->toBe($pok)
        ->and($attempt->is_test)->toBeTrue()
        ->and($attempt->global_transaction_id)->toBe('bc626a986af9dc99035fe7d0')
        ->and($attempt->warnings())->toHaveCount(1)
        ->and($attempt->warnings()[0]->code)->toBe(4)
        ->and($attempt->warnings()[0]->type)->toBe(WarningCode::SaleInFuture)
        ->and($attempt->warnings()[0]->message)->toBe('Datum a cas uskutecneni trzby je novejsi');
});

it('stores the error code, text and dat_odmit from an error response [R34]', function (): void {
    $sale = rawSale(
        EetResponse::error(testUuid(1), 3, 'XML zprava nevyhovela kontrole XML schematu')->test('true')->rejectedAt('2026-11-20T09:59:58+01:00')->toXml(),
        Environment::Playground,
        ['X-Global-Transaction-Id' => 'abc-123'],
    );
    $attempt = lastAttempt($sale);

    expect($attempt->error_code)->toBe(3)
        ->and($attempt->error_message)->toBe('XML zprava nevyhovela kontrole XML schematu')
        ->and(CarbonImmutable::parse($attempt->rejected_at)->equalTo(CarbonImmutable::parse('2026-11-20T09:59:58+01:00')))->toBeTrue()
        ->and($attempt->received_at)->toBeNull()
        ->and($attempt->confirmation_code)->toBeNull()
        ->and($attempt->global_transaction_id)->toBe('abc-123');
});

it('stores warnings and exposes them through Sale::warnings() from the last attempt with a response [R37]', function (): void {
    useMessageUuids(testUuid(1), testUuid(2));
    Eet::fake([
        FakeResponse::raw(EetResponse::error(testUuid(1), -1)->test('true')
            ->warning(1, 'EIC poplatnika v datove zprave se neshoduje s EIC v certifikatu')
            ->warning(6, 'id_jednotky neodpovida formatem')->toXml()),
        FakeResponse::timeout(),
    ]);

    $sale = register(cashRegister());
    Eet::resubmit(freshSale($sale));

    $warnings = freshSale($sale)->warnings();

    expect($warnings)->toHaveCount(2)
        ->and($warnings->first())->toBeInstanceOf(Warning::class)
        ->and($warnings->map(fn (Warning $warning): int => $warning->code)->all())->toBe([1, 6])
        ->and($warnings->map(fn (Warning $warning): ?WarningCode => $warning->type)->all())->toBe([WarningCode::EicMismatchesCertificate, WarningCode::InvalidRegistrationUnitFormat]);
});

it('keeps an unknown warning code with type null [R37]', function (): void {
    $sale = rawSale(EetResponse::confirmed(testUuid(1), EetResponse::confirmationCodeFor(testUuid(1), 'ff'))->test('true')->warning(99, 'Nove varovani')->toXml());

    $warning = freshSale($sale)->warnings()->first();

    expect($sale->status)->toBe(SaleStatus::Confirmed)
        ->and($warning?->code)->toBe(99)
        ->and($warning?->type)->toBeNull()
        ->and($warning?->message)->toBe('Nove varovani');
});

it('accepts scripted fake warnings as Warning objects [R37, R57]', function (): void {
    Eet::fake([FakeResponse::confirmed(null, [new Warning(5, WarningCode::SaleTooOld, 'Datum a cas uskutecneni trzby je vyrazne v minulosti')])]);

    $sale = register(cashRegister());

    expect(freshSale($sale)->warnings()->first()?->type)->toBe(WarningCode::SaleTooOld);
});

it('stores the response but not the request of a confirmed attempt [R51]', function (): void {
    $xml = EetResponse::confirmed(testUuid(1), EetResponse::confirmationCodeFor(testUuid(1), 'ff'))->test('true')->toXml();

    $attempt = lastAttempt(rawSale($xml, Environment::Playground, ['X-Global-Transaction-Id' => 'gid-1']));

    expect($attempt->response_xml)->toBe($xml)
        ->and($attempt->request_xml)->toBeNull()
        ->and($attempt->global_transaction_id)->toBe('gid-1');
});

it('stores the request but no response of a timed-out attempt [R51]', function (): void {
    Eet::fake([FakeResponse::timeout()]);

    $attempt = lastAttempt(register(cashRegister()));

    expect($attempt->request_xml)->toContain('Trzba')
        ->and($attempt->response_xml)->toBeNull()
        ->and($attempt->global_transaction_id)->toBeNull();
});

it('stores both request and response of an unsuccessful attempt with a response [R51]', function (): void {
    $xml = EetResponse::error(testUuid(1), 3)->test('true')->toXml();

    $attempt = lastAttempt(rawSale($xml, Environment::Playground, ['X-Global-Transaction-Id' => 'gid-2']));

    expect($attempt->response_xml)->toBe($xml)
        ->and($attempt->request_xml)->toContain('Trzba')
        ->and($attempt->global_transaction_id)->toBe('gid-2');
});

it('does not store the request of a verified attempt [R51]', function (): void {
    config()->set('laravel-eet-module.mode', 'verification');
    Eet::fake();

    $attempt = lastAttempt(register(cashRegister()));

    expect($attempt->outcome)->toBe(AttemptOutcome::Verified)
        ->and($attempt->request_xml)->toBeNull();
});

it('maps error codes to the ErrorCode enum [R33]', function (): void {
    expect(ErrorCode::from(-1))->toBe(ErrorCode::TemporaryTechnicalError)
        ->and(ErrorCode::from(0))->toBe(ErrorCode::VerificationSucceeded)
        ->and(ErrorCode::from(2))->toBe(ErrorCode::InvalidXmlEncoding)
        ->and(ErrorCode::from(3))->toBe(ErrorCode::SchemaValidationFailed)
        ->and(ErrorCode::from(4))->toBe(ErrorCode::InvalidSignature)
        ->and(ErrorCode::from(6))->toBe(ErrorCode::InvalidEicStructure)
        ->and(ErrorCode::from(7))->toBe(ErrorCode::MessageTooLarge)
        ->and(ErrorCode::from(8))->toBe(ErrorCode::ProcessingFailed)
        ->and(ErrorCode::tryFrom(5))->toBeNull()
        ->and(WarningCode::from(1))->toBe(WarningCode::EicMismatchesCertificate)
        ->and(WarningCode::from(2))->toBe(WarningCode::InvalidDelegatingEicFormat)
        ->and(WarningCode::from(4))->toBe(WarningCode::SaleInFuture)
        ->and(WarningCode::from(5))->toBe(WarningCode::SaleTooOld)
        ->and(WarningCode::from(6))->toBe(WarningCode::InvalidRegistrationUnitFormat)
        ->and(WarningCode::from(7))->toBe(WarningCode::DelegatingEicEqualsTaxpayerEic)
        ->and(WarningCode::tryFrom(3))->toBeNull();
});
