<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Data\Warning;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\ErrorCode;
use Gunner42\LaravelEetModule\Enums\PreflightWarning;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Enums\WarningCode;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Testing\EetFake;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
});

it('replaces sending without any network call and without a certificate [R57]', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    $fake = Eet::fake();
    $sale = register(cashRegister('CZ00000019', Environment::Production));

    expect($fake)->toBeInstanceOf(EetFake::class)
        ->and(taxpayer()->certificates()->count())->toBe(0)
        ->and($sale->status)->toBe(SaleStatus::Confirmed);
    Http::assertNothingSent();
});

it('confirms a production sale by default with a valid POK [R57]', function (): void {
    Eet::fake();

    $sale = register(cashRegister('CZ00000019', Environment::Production));

    expect($sale->status)->toBe(SaleStatus::Confirmed)
        ->and($sale->confirmation_code)->toEndWith('-01')
        ->and($sale->confirmation_code)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}-01$/i')
        ->and($sale->is_test)->toBeFalse()
        ->and($sale->hasValidConfirmationCode())->toBeTrue();
});

it('confirms a playground sale by default with a test POK [R57, R36]', function (): void {
    Eet::fake();

    $sale = register(cashRegister('CZ00000019', Environment::Playground));

    expect($sale->status)->toBe(SaleStatus::Confirmed)
        ->and($sale->confirmation_code)->toEndWith('-ff')
        ->and($sale->is_test)->toBeTrue()
        ->and($sale->hasValidConfirmationCode())->toBeFalse();
});

it('verifies sales in verification mode and the connection check by default [R57]', function (string $environment): void {
    config()->set('laravel-eet-module.mode', 'verification');
    Eet::fake();
    $cashRegister = cashRegister('CZ00000019', Environment::from($environment));

    expect(register($cashRegister)->status)->toBe(SaleStatus::Verified)
        ->and(Eet::verifyConnection($cashRegister)->outcome)->toBe(AttemptOutcome::Verified);
})->with(['playground', 'production']);

it('marks sales and attempts created under the fake [R57]', function (): void {
    Eet::fake();

    $sale = register(cashRegister('CZ00000019', Environment::Production));

    expect(freshSale($sale)->is_fake)->toBeTrue()
        ->and(lastAttempt($sale)->is_fake)->toBeTrue()
        ->and(freshSale($sale)->hasValidConfirmationCode())->toBeTrue();
});

it('consumes the script in order and then falls back to the default [R57]', function (): void {
    Eet::fake([FakeResponse::timeout(), FakeResponse::confirmed()]);
    $cashRegister = cashRegister('CZ00000019', Environment::Production);

    $sale = register($cashRegister, saleData('1'));

    expect($sale->status)->toBe(SaleStatus::Unconfirmed);

    expect(Eet::resubmit(freshSale($sale))->status)->toBe(SaleStatus::Confirmed)
        ->and(register($cashRegister, saleData('2'))->status)->toBe(SaleStatus::Confirmed);
    Eet::assertSentCount(3);
});

it('uses a scripted confirmation code, warnings and test flag [R57]', function (): void {
    $pok = 'b3a09b52-7c87-4014-a496-4c7a53cf9120-01';
    Eet::fake([FakeResponse::confirmed($pok, [new Warning(6, WarningCode::InvalidRegistrationUnitFormat, 'id_jednotky neodpovida formatem')])]);

    $sale = register(cashRegister('CZ00000019', Environment::Production));

    expect($sale->confirmation_code)->toBe($pok)
        ->and(freshSale($sale)->warnings()->first()?->code)->toBe(6);
});

it('fills in uuid_zpravy and the test flag so scripted errors are classified, not mismatched [R57, R71]', function (int $code, string $status): void {
    Eet::fake([FakeResponse::error($code)]);

    $sale = register(cashRegister('CZ00000019', Environment::Playground));

    expect($sale->status)->toBe(SaleStatus::from($status))
        ->and(lastAttempt($sale)->outcome)->not->toBe(AttemptOutcome::ResponseMismatch);
})->with([
    'code 4' => [4, 'rejected'],
    'temporary error' => [-1, 'unconfirmed'],
]);

it('accepts an ErrorCode enum in a scripted error [R57]', function (): void {
    Eet::fake([FakeResponse::error(ErrorCode::InvalidSignature)]);

    $sale = register(cashRegister('CZ00000019', Environment::Playground));

    expect($sale->status)->toBe(SaleStatus::Rejected)
        ->and(lastAttempt($sale)->error_code)->toBe(4);
});

it('treats a scripted test flag that contradicts the environment as ResponseMismatch [R57, R33]', function (): void {
    Eet::fake([FakeResponse::confirmed(null, [], false), FakeResponse::confirmed(null, [], true)]);

    $playground = register(cashRegister('CZ00000019', Environment::Playground));
    $production = register(cashRegister('CZ683555118', Environment::Production));

    expect(lastAttempt($playground)->outcome)->toBe(AttemptOutcome::ResponseMismatch)
        ->and(lastAttempt($production)->outcome)->toBe(AttemptOutcome::ResponseMismatch);
});

it('scripts transport errors [R57]', function (): void {
    Eet::fake([FakeResponse::transportError()]);

    expect(lastAttempt(register(cashRegister()))->outcome)->toBe(AttemptOutcome::TransportError);
});

it('skips certificate selection, so neither CertificateEicMismatch nor CertificateUnavailable occurs under the fake [R57]', function (): void {
    Eet::fake();
    importFixture(taxpayer('CZ00000019'), 'playground/CZ683555118.pem');

    $withForeignCn = register(cashRegister('CZ00000019'));
    $withoutCertificate = register(cashRegister('CZ8551015704'));

    expect($withForeignCn->preflightWarnings())->not->toContain(PreflightWarning::CertificateEicMismatch)
        ->and(lastAttempt($withoutCertificate)->outcome)->not->toBe(AttemptOutcome::CertificateUnavailable);
});

it('passes the parsed message to assertSent [R57]', function (): void {
    Eet::fake();

    register(cashRegister('CZ00000019', Environment::Playground, 181, 'POKLADNA-1'), saleData('99', null, '10.50'));

    Eet::assertSent(fn (SaleMessage $message): bool => $message->sequenceNumber === '99' && $message->totalAmount === '10.50');
    Eet::assertSentCount(1);
    expect(function (): void {
        Eet::assertSent(fn (SaleMessage $message): bool => $message->sequenceNumber === 'other');
    })->toThrow(AssertionFailedError::class)
        ->and(function (): void {
            Eet::assertSentCount(2);
        })->toThrow(AssertionFailedError::class);
});

it('fails assertNothingSent when something was sent and passes otherwise [R57]', function (): void {
    Eet::fake();

    Eet::assertNothingSent();
    register(cashRegister());

    expect(function (): void {
        Eet::assertNothingSent();
    })->toThrow(AssertionFailedError::class);
});

it('throws a LogicException and replaces nothing when fake() is called outside of tests [R57]', function (): void {
    importFixture(taxpayer());
    failingEetService(-1);

    expect(fn (): mixed => outsideOfTests(fn (): EetFake => Eet::fake()))->toThrow(LogicException::class);

    register(cashRegister());

    Http::assertSentCount(1);
});

it('throws a LogicException when createMessageUuidsUsing() is called outside of tests [R29, R57]', function (): void {
    expect(fn (): mixed => outsideOfTests(function (): bool {
        Eet::createMessageUuidsUsing(fn (): string => testUuid(1));

        return true;
    }))->toThrow(LogicException::class);
});
