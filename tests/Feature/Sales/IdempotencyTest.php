<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Data\ReversalData;
use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Enums\SendingMode;
use Gunner42\LaravelEetModule\Events\SaleConfirmed;
use Gunner42\LaravelEetModule\Events\SaleRejected;
use Gunner42\LaravelEetModule\Exceptions\ActiveDatabaseTransaction;
use Gunner42\LaravelEetModule\Exceptions\DuplicateSequenceNumber;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\SubmissionAttempt;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Gunner42\LaravelEetModule\Tests\Support\SoapMessage;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
});

it('keeps the Data snapshot, environment and mode of the sale for every resubmission [R23, R42]', function (): void {
    Eet::fake([FakeResponse::timeout(), FakeResponse::confirmed()]);
    $cashRegister = cashRegister('CZ00000019', Environment::Playground, 181, 'POKLADNA-1');
    $sale = register($cashRegister, saleData('7', null, '120.00', null, '20.00', 'CZ683555118', true));

    $cashRegister->identifier = 'POKLADNA-X';
    $cashRegister->save();
    config()->set('laravel-eet-module.mode', 'verification');
    Eet::changeEnvironment(taxpayer(), Environment::Production);
    $this->travel(10)->minutes();

    Eet::resubmit(freshSale($sale));

    $messages = [];
    Eet::assertSent(function (SaleMessage $message) use (&$messages): bool {
        $messages[] = $message;

        return true;
    });

    [$first, $second] = $messages;
    $fresh = freshSale($sale);

    expect($second->cashRegisterId)->toBe('POKLADNA-1')
        ->and($second->verificationMode)->toBeFalse()
        ->and($second->isFirstSubmission)->toBeFalse()
        ->and($second->messageUuid)->not->toBe($first->messageUuid)
        ->and($second->sentAt)->not->toBe($first->sentAt)
        ->and([$second->eic, $second->delegatingEic, $second->delegatedByMultipleTaxpayers, $second->registrationUnitId, $second->sequenceNumber, $second->occurredAt, $second->totalAmount, $second->amountForLaterDrawing, $second->drawnAmount])
        ->toBe([$first->eic, $first->delegatingEic, $first->delegatedByMultipleTaxpayers, $first->registrationUnitId, $first->sequenceNumber, $first->occurredAt, $first->totalAmount, $first->amountForLaterDrawing, $first->drawnAmount])
        ->and($fresh->environment)->toBe(Environment::Playground)
        ->and($fresh->mode)->toBe(SendingMode::Live)
        ->and($fresh->cash_register_identifier)->toBe('POKLADNA-1')
        ->and($fresh->status)->toBe(SaleStatus::Confirmed);
});

it('resubmits to the endpoint of the environment stored with the sale [R23, R32]', function (): void {
    failingEetService(-1);
    importFixture(taxpayer('CZ00000019', Environment::Playground));
    $sale = register(cashRegister());

    Eet::changeEnvironment(taxpayer(), Environment::Production);
    Eet::resubmit(freshSale($sale));

    expect(Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all())->toBe([
        'https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4',
        'https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4',
    ]);
});

it('returns the existing sale for a double submit without sending or dispatching again [R26]', function (): void {
    Eet::fake();
    Event::fake([SaleConfirmed::class]);
    $cashRegister = cashRegister();
    $data = saleData('1', null, '100.00', null, '10.00');

    $first = Eet::register($cashRegister, $data);
    $second = Eet::register($cashRegister, saleData('1', null, '100.00', null, '10.00'));

    expect($second->getKey())->toBe($first->getKey())
        ->and(Sale::query()->count())->toBe(1)
        ->and(SubmissionAttempt::query()->count())->toBe(1);
    Eet::assertSentCount(1);
    Event::assertDispatchedTimes(SaleConfirmed::class, 1);
});

it('returns an existing rejected sale unchanged for the same data [R26]', function (): void {
    Eet::fake([FakeResponse::error(3)]);
    Event::fake([SaleRejected::class]);
    $cashRegister = cashRegister();

    $first = register($cashRegister);
    $second = register($cashRegister);

    expect($first->status)->toBe(SaleStatus::Rejected)
        ->and($second->getKey())->toBe($first->getKey())
        ->and($second->status)->toBe(SaleStatus::Rejected);
    Eet::assertSentCount(1);
    Event::assertDispatchedTimes(SaleRejected::class, 1);
});

it('rejects the same unique sale code with different other snapshot data [R26]', function (string $field): void {
    Eet::fake();
    $cashRegister = cashRegister();
    register($cashRegister, saleData('1', null, '100.00', null, '10.00', 'CZ683555118', false));

    $changed = match ($field) {
        'drawnAmount' => saleData('1', null, '100.00', null, '20.00', 'CZ683555118', false),
        'amountForLaterDrawing' => saleData('1', null, '100.00', '5.00', '10.00', 'CZ683555118', false),
        'delegatingEic' => saleData('1', null, '100.00', null, '10.00', 'CZ8551015704', false),
        'delegatedByMultipleTaxpayers' => saleData('1', null, '100.00', null, '10.00', 'CZ683555118', true),
        default => throw new LogicException($field),
    };

    expect(fn (): Sale => register($cashRegister, $changed))->toThrow(DuplicateSequenceNumber::class)
        ->and(Sale::query()->count())->toBe(1);
    Eet::assertSentCount(1);
})->with(['drawnAmount', 'amountForLaterDrawing', 'delegatingEic', 'delegatedByMultipleTaxpayers']);

it('rejects the same sequence number, time and cash register with a different total amount [R27]', function (): void {
    Eet::fake();
    $cashRegister = cashRegister();
    register($cashRegister, saleData('1', null, '100.00'));

    expect(fn (): Sale => register($cashRegister, saleData('1', null, '100.01')))->toThrow(DuplicateSequenceNumber::class)
        ->and(Sale::query()->count())->toBe(1);
    Eet::assertSentCount(1);
});

it('accepts the same sequence number at another time or on another cash register [R27]', function (): void {
    Eet::fake();
    $cashRegister = cashRegister();
    register($cashRegister, saleData('1'));
    register($cashRegister, saleData('1', frozenNow()->subMinute()));
    register(cashRegister('CZ00000019', Environment::Playground, 181, 'POKLADNA-2'), saleData('1'));

    expect(Sale::query()->count())->toBe(3);
});

it('creates the same sale once in playground and once in production [R26]', function (): void {
    Eet::fake();
    $cashRegister = cashRegister('CZ00000019', Environment::Playground);
    $data = saleData('1');

    $playground = Eet::register($cashRegister, $data);
    Eet::changeEnvironment(taxpayer(), Environment::Production);
    $production = Eet::register($cashRegister->fresh() ?? $cashRegister, $data);

    expect($production->getKey())->not->toBe($playground->getKey())
        ->and($playground->environment)->toBe(Environment::Playground)
        ->and($production->environment)->toBe(Environment::Production)
        ->and($production->unique_sale_code)->toBe($playground->unique_sale_code);
    Eet::assertSentCount(2);
});

it('commits the pending sale before the network call [R28]', function (): void {
    $observed = [];
    importFixture(taxpayer());
    $baseline = DB::transactionLevel();
    fakeEetService(function (SoapMessage $message) use (&$observed): EetResponse {
        $sale = Sale::query()->first();
        $observed = [
            'level' => DB::transactionLevel(),
            'status' => $sale?->status,
        ];

        return EetResponse::error($message->uuid(), -1)->test('true');
    });

    register(cashRegister());

    expect($observed['level'])->toBe($baseline)
        ->and($observed['status'])->toBe(SaleStatus::Pending);
});

it('keeps the sale stored when the transport throws [R28]', function (): void {
    importFixture(taxpayer());
    Http::preventStrayRequests();
    Http::fake(function (Request $request): PromiseInterface {
        throw new RuntimeException('Process crashed during sending');
    });

    try {
        register(cashRegister(), saleData('77'));
    } catch (Throwable) {
        // the outcome does not matter here, only that the sale survives
    }

    $sale = Sale::query()->where('sequence_number', '77')->first();

    expect($sale)->not->toBeNull()
        ->and($sale?->status)->not->toBe(SaleStatus::Confirmed);
});

it('rejects register() inside an open database transaction of the host and stores nothing [R72]', function (): void {
    Eet::fake();
    $cashRegister = cashRegister();

    expect(fn (): mixed => DB::transaction(fn (): Sale => register($cashRegister)))->toThrow(ActiveDatabaseTransaction::class)
        ->and(Sale::query()->count())->toBe(0)
        ->and(SubmissionAttempt::query()->count())->toBe(0);
    Eet::assertNothingSent();
});

it('allows register() inside the transaction opened by RefreshDatabase [R72]', function (): void {
    Eet::fake();

    expect(DB::transactionLevel())->toBeGreaterThanOrEqual(1)
        ->and(register(cashRegister())->exists)->toBeTrue();
});

it('rejects reverse() and resubmit() inside an open database transaction [R72]', function (): void {
    Eet::fake([FakeResponse::confirmed(), FakeResponse::timeout()]);
    $cashRegister = cashRegister();
    $original = register($cashRegister, saleData('1'));
    $unconfirmed = register($cashRegister, saleData('2'));

    expect(fn (): mixed => DB::transaction(fn (): Sale => Eet::reverse($original, new ReversalData($cashRegister, 'S1', now(), amount('10.00')))))
        ->toThrow(ActiveDatabaseTransaction::class)
        ->and(fn (): mixed => DB::transaction(fn (): Sale => Eet::resubmit($unconfirmed)))
        ->toThrow(ActiveDatabaseTransaction::class)
        ->and(Sale::query()->count())->toBe(2)
        ->and(attemptsOf($unconfirmed))->toHaveCount(1);
    Eet::assertSentCount(2);
});
