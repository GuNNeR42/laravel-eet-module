<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Actions\RegisterSale;
use Gunner42\LaravelEetModule\Data\SaleData;
use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\PreflightWarning;
use Gunner42\LaravelEetModule\Enums\SaleDataProblem;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Events\SalePreflightWarned;
use Gunner42\LaravelEetModule\Exceptions\InvalidSaleData;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
});

/**
 * Asserts that register() throws InvalidSaleData with the field and problem
 * and that nothing was stored or sent.
 */
function expectBlocked(CashRegister $cashRegister, SaleData $data, string $field, SaleDataProblem $problem): void
{
    $salesBefore = Sale::query()->count();
    $sentBefore = fakeSentCount();

    try {
        Eet::register($cashRegister, $data);

        throw new AssertionFailedError('InvalidSaleData was not thrown.');
    } catch (InvalidSaleData $exception) {
        expect($exception->field)->toBe($field)
            ->and($exception->problem)->toBe($problem);
    }

    expect(Sale::query()->count())->toBe($salesBefore);
    Eet::assertSentCount($sentBefore);
}

/**
 * Number of messages sent through Eet::fake() so far.
 */
function fakeSentCount(): int
{
    $count = 0;

    try {
        Eet::assertSent(function () use (&$count): bool {
            $count++;

            return true;
        });
    } catch (AssertionFailedError) {
        // nothing was sent yet
    }

    return $count;
}

it('registers a sale on a cash register and derives taxpayer and registration unit [R19]', function (): void {
    Eet::fake([FakeResponse::confirmed()]);
    $cashRegister = cashRegister('CZ00000019', Environment::Production, 181, 'POKLADNA-1');

    $sale = Eet::register($cashRegister, saleData('1'));

    expect($sale)->toBeInstanceOf(Sale::class)
        ->and($sale->taxpayer->is(taxpayer()))->toBeTrue()
        ->and($sale->cashRegister->is($cashRegister))->toBeTrue()
        ->and($sale->eic)->toBe('CZ00000019')
        ->and($sale->registration_unit_number)->toBe(181)
        ->and($sale->cash_register_identifier)->toBe('POKLADNA-1')
        ->and($sale->status)->toBe(SaleStatus::Confirmed)
        ->and($sale->confirmation_code)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}-[0-9a-f]{2}$/i')
        ->and(attemptsOf($sale))->toHaveCount(1)
        ->and(attemptsOf($sale)->first()?->is_first_submission)->toBeTrue();
});

it('exposes sale registration as an action [R19]', function (): void {
    Eet::fake();

    expect(app(RegisterSale::class)->handle(cashRegister(), saleData()))->toBeInstanceOf(Sale::class);
});

it('accepts a sequence number matching the schema mask [R20]', function (string $sequenceNumber): void {
    Eet::fake();

    $sale = register(cashRegister(), saleData($sequenceNumber));

    expect($sale->sequence_number)->toBe($sequenceNumber);
    Eet::assertSent(fn (SaleMessage $message): bool => $message->sequenceNumber === $sequenceNumber);
})->with(['#25/c-12/1A_2/2027', '1', '1234567890123456789012345', '#135433c/11/2027']);

it('rejects a sequence number outside the schema mask and stores and sends nothing [R20]', function (string $sequenceNumber): void {
    Eet::fake();

    expectBlocked(cashRegister(), saleData($sequenceNumber), 'sequenceNumber', SaleDataProblem::InvalidFormat);
})->with([
    'twenty-six characters' => '12345678901234567890123456',
    'diacritics' => 'č123',
    'empty' => '',
    'plus sign' => '1+1',
]);

it('rejects a delegating EIČ outside the mask [R20]', function (string $delegatingEic): void {
    Eet::fake();

    expectBlocked(cashRegister(), saleData(delegatingEic: $delegatingEic), 'delegatingEic', SaleDataProblem::InvalidFormat);
})->with(['CZ1234567', 'cz12345678', 'SK12345678', '']);

it('blocks a delegating EIČ equal to the taxpayer EIČ [R21]', function (): void {
    Eet::fake();

    expectBlocked(cashRegister('CZ00000019'), saleData(delegatingEic: 'CZ00000019'), 'delegatingEic', SaleDataProblem::DelegatingEicEqualsTaxpayerEic);
});

it('accepts a delegating EIČ different from the taxpayer EIČ [R21]', function (): void {
    Eet::fake();

    $sale = register(cashRegister('CZ00000019'), saleData(delegatingEic: 'CZ683555118'));

    expect($sale->delegating_eic)->toBe('CZ683555118')
        ->and($sale->preflightWarnings())->toBe([]);
});

it('accepts a sale exactly two hours in the future without a warning [R21]', function (): void {
    Eet::fake();

    $sale = register(cashRegister(), saleData(occurredAt: frozenNow()->addHours(2)));

    expect($sale->preflightWarnings())->not->toContain(PreflightWarning::SaleInFuture);
});

it('sends a sale more than two hours in the future with the SaleInFuture warning [R21, R62]', function (): void {
    Eet::fake();

    $sale = register(cashRegister(), saleData(occurredAt: frozenNow()->addHours(2)->addSecond()));

    expect($sale->preflightWarnings())->toBe([PreflightWarning::SaleInFuture])
        ->and($sale->status)->toBe(SaleStatus::Confirmed);
    Eet::assertSentCount(1);
});

it('accepts a sale exactly two calendar years old [R21]', function (): void {
    Eet::fake();
    config()->set('laravel-eet-module.minimum_occurred_at.playground', '2020-01-01 00:00');

    $sale = register(cashRegister(), saleData(occurredAt: frozenNow()->subYears(2)));

    expect($sale->exists)->toBeTrue();
});

it('blocks a sale older than two calendar years [R21]', function (): void {
    Eet::fake();
    config()->set('laravel-eet-module.minimum_occurred_at.playground', '2020-01-01 00:00');

    expectBlocked(cashRegister(), saleData(occurredAt: frozenNow()->subYears(2)->subSecond()), 'occurredAt', SaleDataProblem::TooOld);
});

it('blocks a production sale before the start of the production environment and accepts the first moment [R21, R61]', function (): void {
    Eet::fake();
    $cashRegister = cashRegister('CZ00000019', Environment::Production);

    expectBlocked($cashRegister, saleData('1', CarbonImmutable::parse('2026-10-31 23:59:59', 'Europe/Prague')), 'occurredAt', SaleDataProblem::BeforeEnvironmentStart);

    $sale = register($cashRegister, saleData('2', CarbonImmutable::parse('2026-11-01 00:00:00', 'Europe/Prague')));

    expect($sale->occurred_at)->toBe('2026-11-01T00:00:00+01:00');
});

it('blocks a playground sale before 2026-07-01 00:00 and accepts the first moment [R21, R61]', function (): void {
    Eet::fake();
    $cashRegister = cashRegister('CZ00000019', Environment::Playground);

    expectBlocked($cashRegister, saleData('1', CarbonImmutable::parse('2026-06-30 23:59:59', 'Europe/Prague')), 'occurredAt', SaleDataProblem::BeforeEnvironmentStart);

    expect(register($cashRegister, saleData('2', CarbonImmutable::parse('2026-07-01 00:00:00', 'Europe/Prague')))->exists)->toBeTrue();
});

it('reads the minimum occurredAt from the configuration in the configured timezone [R61]', function (): void {
    Eet::fake();
    config()->set('laravel-eet-module.minimum_occurred_at.production', '2026-11-10 12:00');
    config()->set('laravel-eet-module.timezone', 'UTC');
    $cashRegister = cashRegister('CZ00000019', Environment::Production);

    // 12:59:59 in Prague is 11:59:59 UTC, i.e. before 12:00 UTC
    expectBlocked($cashRegister, saleData('1', CarbonImmutable::parse('2026-11-10 12:59:59', 'Europe/Prague')), 'occurredAt', SaleDataProblem::BeforeEnvironmentStart);

    expect(register($cashRegister, saleData('2', CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC')))->exists)->toBeTrue();
});

it('uses the minimum date of the environment of the sale, not of the other environment [R61]', function (): void {
    Eet::fake();

    // 2026-08-01 is after the playground start but before the production start
    $playground = register(cashRegister('CZ00000019', Environment::Playground), saleData('1', CarbonImmutable::parse('2026-08-01 10:00:00', 'Europe/Prague')));

    expect($playground->exists)->toBeTrue();
    expectBlocked(cashRegister('CZ683555118', Environment::Production), saleData('1', CarbonImmutable::parse('2026-08-01 10:00:00', 'Europe/Prague')), 'occurredAt', SaleDataProblem::BeforeEnvironmentStart);
});

it('sends a sale of a registration unit without the DIS+ format with a local warning [R21, R5]', function (): void {
    Eet::fake();

    $sale = register(cashRegister('CZ00000019', Environment::Playground, 5));

    expect($sale->preflightWarnings())->toBe([PreflightWarning::UnusualRegistrationUnitNumber])
        ->and($sale->status)->toBe(SaleStatus::Confirmed);
    Eet::assertSentCount(1);
});

it('does not warn about a registration unit with the DIS+ format [R21]', function (): void {
    Eet::fake();

    expect(register(cashRegister('CZ00000019', Environment::Playground, 181))->preflightWarnings())->toBe([]);
});

it('blocks a part with the opposite sign of the total amount [R21]', function (string $total, ?string $forLaterDrawing, ?string $drawn, string $field): void {
    Eet::fake();

    expectBlocked(cashRegister(), saleData('1', null, $total, $forLaterDrawing, $drawn), $field, SaleDataProblem::SignMismatch);
})->with([
    'negative amount for later drawing' => ['100.00', '-50.00', null, 'amountForLaterDrawing'],
    'negative drawn amount' => ['100.00', null, '-50.00', 'drawnAmount'],
    'positive drawn amount of a negative total amount' => ['-100.00', null, '50.00', 'drawnAmount'],
    'both wrong, amount for later drawing first' => ['100.00', '-10.00', '-10.00', 'amountForLaterDrawing'],
    'sign before parts exceeding the total amount' => ['10.00', '-50.00', null, 'amountForLaterDrawing'],
]);

it('accepts zero parts and parts with the sign of the total amount [R21]', function (string $total, ?string $forLaterDrawing, ?string $drawn): void {
    Eet::fake();

    expect(register(cashRegister(), saleData('1', null, $total, $forLaterDrawing, $drawn))->exists)->toBeTrue();
})->with([
    'zero drawn amount' => ['100.00', null, '0.00'],
    'zero part of a negative total amount' => ['-100.00', '0.00', null],
    'parts equal to the total amount' => ['100.00', '60.00', '40.00'],
    'negative parts of a negative total amount' => ['-100.00', '-60.00', '-40.00'],
    'MF example: amounts for later drawing and drawn cancel out at zero total amount' => ['0.00', '100.00', '-100.00'],
]);

it('blocks parts exceeding the total amount [R21]', function (string $total, ?string $forLaterDrawing, ?string $drawn): void {
    Eet::fake();

    expectBlocked(cashRegister(), saleData('1', null, $total, $forLaterDrawing, $drawn), 'totalAmount', SaleDataProblem::PartsExceedTotal);
})->with([
    'MF counter-example' => ['100.00', '60.00', '50.00'],
    'non-zero part with zero total amount' => ['0.00', '10.00', null],
    'negative' => ['-100.00', null, '-100.01'],
]);

it('accepts a total amount of zero without a warning [R21]', function (): void {
    Eet::fake();

    $sale = register(cashRegister(), saleData('1', null, '0.00'));

    expect($sale->preflightWarnings())->toBe([])
        ->and($sale->status)->toBe(SaleStatus::Confirmed);
});

it('checks blocking rules in the order a, c, d, g, h [R21]', function (): void {
    Eet::fake();
    $cashRegister = cashRegister('CZ00000019', Environment::Production);

    // a + c
    expectBlocked($cashRegister, saleData('1', frozenNow()->subYears(3), '100.00', null, null, 'CZ00000019'), 'delegatingEic', SaleDataProblem::DelegatingEicEqualsTaxpayerEic);
    // c + d
    expectBlocked($cashRegister, saleData('1', frozenNow()->subYears(3)), 'occurredAt', SaleDataProblem::TooOld);
    // d + g
    expectBlocked($cashRegister, saleData('1', CarbonImmutable::parse('2026-10-01 10:00', 'Europe/Prague'), '100.00', '-1.00'), 'occurredAt', SaleDataProblem::BeforeEnvironmentStart);
});

it('stores local warnings without duplicates and dispatches SalePreflightWarned only for new ones [R62, R21]', function (): void {
    Event::fake([SalePreflightWarned::class]);
    failingEetService(-1);
    $taxpayer = taxpayer('CZ00000019');
    importFixture($taxpayer, 'playground/CZ683555118.pem');
    $cashRegister = cashRegister('CZ00000019', Environment::Playground, 5);

    $sale = register($cashRegister);

    expect(freshSale($sale)->preflightWarnings())->toEqualCanonicalizing([
        PreflightWarning::UnusualRegistrationUnitNumber,
        PreflightWarning::CertificateEicMismatch,
    ]);

    Eet::resubmit(freshSale($sale));
    Eet::resubmit(freshSale($sale));

    $warnings = freshSale($sale)->preflightWarnings();
    expect($warnings)->toHaveCount(2)
        ->and(array_unique(array_map(fn (PreflightWarning $warning): string => $warning->value, $warnings)))->toHaveCount(2)
        ->and(attemptsOf($sale))->toHaveCount(3);

    $dispatched = Event::dispatched(SalePreflightWarned::class);
    $reported = $dispatched->flatMap(fn (array $arguments): array => $arguments[0]->warnings)->all();

    expect($dispatched->every(fn (array $arguments): bool => $arguments[0]->sale->is($sale)))->toBeTrue()
        ->and($reported)->toHaveCount(2)
        ->and($reported)->toEqualCanonicalizing([
            PreflightWarning::UnusualRegistrationUnitNumber,
            PreflightWarning::CertificateEicMismatch,
        ]);
});

it('does not dispatch SalePreflightWarned for a sale without local warnings [R62]', function (): void {
    Event::fake([SalePreflightWarned::class]);
    Eet::fake();

    register(cashRegister());

    Event::assertNotDispatched(SalePreflightWarned::class);
});

it('dispatches SalePreflightWarned with the new warnings of a new sale [R62]', function (): void {
    Event::fake([SalePreflightWarned::class]);
    Eet::fake();

    $sale = register(cashRegister('CZ00000019', Environment::Playground, 5), saleData(occurredAt: frozenNow()->addHours(3)));

    Event::assertDispatched(SalePreflightWarned::class, fn (SalePreflightWarned $event): bool => $event->sale->is($sale)
        && count($event->warnings) === 2
        && in_array(PreflightWarning::SaleInFuture, $event->warnings, true)
        && in_array(PreflightWarning::UnusualRegistrationUnitNumber, $event->warnings, true));
});
