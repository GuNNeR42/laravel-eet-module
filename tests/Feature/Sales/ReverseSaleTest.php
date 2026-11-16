<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Actions\ReverseSale;
use Gunner42\LaravelEetModule\Data\ReversalData;
use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\SaleDataProblem;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Exceptions\InvalidAmount;
use Gunner42\LaravelEetModule\Exceptions\InvalidSaleData;
use Gunner42\LaravelEetModule\Exceptions\ReversalEnvironmentMismatch;
use Gunner42\LaravelEetModule\Exceptions\ReversalTaxpayerMismatch;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Gunner42\LaravelEetModule\Tests\Support\SoapMessage;
use Gunner42\LaravelEetModule\ValueObjects\Amount;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
});

function originalSale(string $total = '5450.00', ?string $drawn = null, ?string $delegatingEic = null, bool $multiple = false): Sale
{
    return register(cashRegister('CZ00000019', Environment::Production, 181, 'POKLADNA-1'), saleData('P-1', frozenNow()->subHour(), $total, null, $drawn, $delegatingEic, $multiple));
}

it('registers a reversal as a new sale with the negated amount and a local link to the original [R48, R50]', function (): void {
    Eet::fake();
    $original = originalSale('5450.00', null, 'CZ683555118', true);
    $cashRegister = cashRegister('CZ00000019', Environment::Production, 241, 'POKLADNA-9');

    $reversal = Eet::reverse($original, new ReversalData($cashRegister, 'S-1', frozenNow(), Amount::fromString('5450.00')));

    expect($reversal->getKey())->not->toBe($original->getKey())
        ->and($reversal->total_amount->toEetString())->toBe('-5450.00')
        ->and($reversal->sequence_number)->toBe('S-1')
        ->and($reversal->registration_unit_number)->toBe(241)
        ->and($reversal->cash_register_identifier)->toBe('POKLADNA-9')
        ->and($reversal->delegating_eic)->toBe('CZ683555118')
        ->and($reversal->delegated_by_multiple_taxpayers)->toBeTrue()
        ->and($reversal->reversed_sale_id)->toBe($original->getKey())
        ->and($reversal->reversedSale?->is($original))->toBeTrue()
        ->and(freshSale($original)->reversals->pluck('id')->all())->toBe([$reversal->getKey()])
        ->and($reversal->status)->toBe(SaleStatus::Confirmed);

    Eet::assertSent(fn (SaleMessage $message): bool => $message->sequenceNumber === 'S-1'
        && $message->totalAmount === '-5450.00'
        && $message->delegatingEic === 'CZ683555118'
        && $message->delegatedByMultipleTaxpayers
        && $message->cashRegisterId === 'POKLADNA-9'
        && $message->occurredAt === '2026-11-20T10:00:00+01:00');
});

it('never puts the link to the original into the data message [R50]', function (): void {
    Eet::fake();
    $original = originalSale();

    Eet::reverse($original, new ReversalData(cashRegister('CZ00000019', Environment::Production, 181, 'POKLADNA-1'), 'S-1', frozenNow(), Amount::fromString('5450.00')));

    $messages = [];
    Eet::assertSent(function (SaleMessage $message) use (&$messages): bool {
        $messages[] = $message;

        return true;
    });
    $reversalXml = $messages[1]->xml;
    $data = (new SoapMessage($reversalXml))->data();

    expect(array_keys($data))->toEqualCanonicalizing(['eic_popl', 'id_jednotky', 'id_pokl', 'porad_cis', 'dat_trzby', 'celk_trzba'])
        ->and($reversalXml)->not->toContain('P-1');
});

it('negates a given drawn amount [R48]', function (): void {
    Eet::fake();
    $original = originalSale('5450.00', '450.00');

    $reversal = Eet::reverse($original, new ReversalData(cashRegister('CZ00000019', Environment::Production, 181, 'POKLADNA-1'), 'S-1', frozenNow(), Amount::fromString('5450.00'), Amount::fromString('450.00')));

    expect($reversal->drawn_amount?->toEetString())->toBe('-450.00');
    Eet::assertSent(fn (SaleMessage $message): bool => $message->sequenceNumber === 'S-1' && $message->drawnAmount === '-450.00');
});

it('rejects a reversal amount or drawn amount that is not positive [R49]', function (string $amount, ?string $drawn): void {
    Eet::fake();
    $original = originalSale();

    expect(fn (): Sale => Eet::reverse($original, new ReversalData(
        cashRegister('CZ00000019', Environment::Production, 181, 'POKLADNA-1'),
        'S-1',
        frozenNow(),
        Amount::fromString($amount),
        $drawn === null ? null : Amount::fromString($drawn),
    )))->toThrow(InvalidAmount::class)
        ->and(Sale::query()->count())->toBe(1);
})->with([
    'negative amount' => ['-5450.00', null],
    'zero amount' => ['0.00', null],
    'negative drawn amount' => ['100.00', '-10.00'],
]);

it('rejects a cash register of another taxpayer [R49]', function (): void {
    Eet::fake();
    $original = originalSale();

    expect(fn (): Sale => Eet::reverse($original, new ReversalData(cashRegister('CZ683555118', Environment::Production), 'S-1', frozenNow(), Amount::fromString('10.00'))))
        ->toThrow(ReversalTaxpayerMismatch::class)
        ->and(Sale::query()->count())->toBe(1);
});

it('rejects a reversal of an original from another environment [R49]', function (): void {
    Eet::fake();
    $cashRegister = cashRegister('CZ00000019', Environment::Playground);
    $original = register($cashRegister, saleData('P-1'));
    Eet::changeEnvironment(taxpayer(), Environment::Production);

    expect(fn (): Sale => Eet::reverse($original, new ReversalData($cashRegister, 'S-1', frozenNow(), Amount::fromString('10.00'))))
        ->toThrow(ReversalEnvironmentMismatch::class)
        ->and(Sale::query()->count())->toBe(1);
    Eet::assertSentCount(1);
});

it('rejects a reversal of an original from another mode [R49]', function (): void {
    Eet::fake();
    config()->set('laravel-eet-module.mode', 'verification');
    $original = originalSale();
    config()->set('laravel-eet-module.mode', 'live');

    expect(fn (): Sale => Eet::reverse($original, new ReversalData(cashRegister('CZ00000019', Environment::Production, 181, 'POKLADNA-1'), 'S-1', frozenNow(), Amount::fromString('10.00'))))
        ->toThrow(ReversalEnvironmentMismatch::class)
        ->and(Sale::query()->count())->toBe(1);
});

it('checks neither the status of the original nor the amounts of earlier reversals [R49, R21]', function (): void {
    Eet::fake([FakeResponse::error(3)]);
    $original = originalSale('100.00');
    $cashRegister = cashRegister('CZ00000019', Environment::Production, 181, 'POKLADNA-1');

    $first = Eet::reverse($original, new ReversalData($cashRegister, 'S-1', frozenNow(), Amount::fromString('150.00')));
    $second = Eet::reverse($original, new ReversalData($cashRegister, 'S-2', frozenNow(), Amount::fromString('80.00')));

    expect($original->status)->toBe(SaleStatus::Rejected)
        ->and($first->total_amount->toEetString())->toBe('-150.00')
        ->and($second->total_amount->toEetString())->toBe('-80.00')
        ->and(freshSale($original)->reversals)->toHaveCount(2);
});

it('applies the sale validation to a reversal [R48, R20]', function (): void {
    Eet::fake();
    $original = originalSale();

    try {
        Eet::reverse($original, new ReversalData(cashRegister('CZ00000019', Environment::Production, 181, 'POKLADNA-1'), 'Storno č. 1', frozenNow(), Amount::fromString('10.00')));

        throw new AssertionFailedError('InvalidSaleData was not thrown.');
    } catch (InvalidSaleData $exception) {
        expect($exception->field)->toBe('sequenceNumber')
            ->and($exception->problem)->toBe(SaleDataProblem::InvalidFormat);
    }

    expect(Sale::query()->count())->toBe(1);
});

it('exposes the reversal as an action [R48]', function (): void {
    Eet::fake();
    $original = originalSale();

    $reversal = app(ReverseSale::class)->handle($original, new ReversalData(cashRegister('CZ00000019', Environment::Production, 181, 'POKLADNA-1'), 'S-1', frozenNow(), Amount::fromString('1.00')));

    expect($reversal->total_amount->toEetString())->toBe('-1.00');
});
