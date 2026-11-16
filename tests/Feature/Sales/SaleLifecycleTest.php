<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Events\SaleConfirmed;
use Gunner42\LaravelEetModule\Events\SaleRejected;
use Gunner42\LaravelEetModule\Events\SaleReopened;
use Gunner42\LaravelEetModule\Events\SaleUnconfirmed;
use Gunner42\LaravelEetModule\Events\SaleVerified;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Jobs\ResubmitSale;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Gunner42\LaravelEetModule\Tests\Support\SoapMessage;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
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

it('sends nothing more for a confirmed or verified sale, neither through resubmit(), the job nor the command [R38]', function (string $mode): void {
    config()->set('laravel-eet-module.mode', $mode);
    Eet::fake();
    $sale = register(cashRegister());

    $returned = Eet::resubmit(freshSale($sale));
    dispatch_sync(new ResubmitSale((int) $sale->getKey()));
    $this->artisan('eet:resubmit')->run();
    $this->artisan('eet:resubmit', ['--sale' => [(string) $sale->getKey()]])->run();

    expect($returned->status)->toBe($mode === 'live' ? SaleStatus::Confirmed : SaleStatus::Verified)
        ->and(freshSale($sale)->status)->toBe($returned->status)
        ->and(attemptsOf($sale))->toHaveCount(1);
    Eet::assertSentCount(1);
})->with(['live', 'verification']);

it('never resubmits a rejected sale automatically through the job or eet:resubmit without --sale [R38]', function (): void {
    Eet::fake([FakeResponse::error(4)]);
    $sale = register(cashRegister());

    dispatch_sync(new ResubmitSale((int) $sale->getKey()));
    $this->artisan('eet:resubmit')->run();

    expect(freshSale($sale)->status)->toBe(SaleStatus::Rejected)
        ->and(attemptsOf($sale))->toHaveCount(1);
    Eet::assertSentCount(1);
});

it('dispatches SaleConfirmed after the database write is committed [R39]', function (): void {
    Eet::fake();
    $baseline = DB::transactionLevel();
    $observed = [];
    Event::listen(SaleConfirmed::class, function (SaleConfirmed $event) use (&$observed): void {
        $observed[] = [DB::transactionLevel(), Sale::query()->findOrFail($event->sale->getKey())->status];
    });

    register(cashRegister());

    expect($observed)->toBe([[$baseline, SaleStatus::Confirmed]]);
});

it('dispatches SaleVerified on the transition to Verified [R39]', function (): void {
    config()->set('laravel-eet-module.mode', 'verification');
    Eet::fake();
    fakeEetEvents();

    $sale = register(cashRegister());

    Event::assertDispatchedTimes(SaleVerified::class, 1);
    Event::assertDispatched(SaleVerified::class, fn (SaleVerified $event): bool => $event->sale->is($sale));
    Event::assertNotDispatched(SaleConfirmed::class);
});

it('dispatches SaleRejected with the error code of the last attempt [R39]', function (): void {
    Eet::fake([FakeResponse::error(6)]);
    fakeEetEvents();

    $sale = register(cashRegister());

    Event::assertDispatched(SaleRejected::class, fn (SaleRejected $event): bool => $event->sale->is($sale) && $event->errorCode === 6);
    Event::assertNotDispatched(SaleUnconfirmed::class);
});

it('dispatches SaleUnconfirmed once on Pending → Unconfirmed, not for further failed attempts [R39]', function (): void {
    Eet::fake([FakeResponse::timeout(), FakeResponse::error(-1), FakeResponse::transportError(), FakeResponse::confirmed()]);
    fakeEetEvents();

    $sale = register(cashRegister());
    Eet::resubmit(freshSale($sale));
    Eet::resubmit(freshSale($sale));

    Event::assertDispatchedTimes(SaleUnconfirmed::class, 1);
    Event::assertDispatched(SaleUnconfirmed::class, fn (SaleUnconfirmed $event): bool => $event->outcome === AttemptOutcome::Timeout);
    Event::assertNotDispatched(SaleConfirmed::class);

    Eet::resubmit(freshSale($sale));

    Event::assertDispatchedTimes(SaleConfirmed::class, 1);
    expect(freshSale($sale)->status)->toBe(SaleStatus::Confirmed);
});

it('dispatches SaleReopened on Rejected → Unconfirmed [R39, R64]', function (): void {
    Eet::fake([FakeResponse::error(4), FakeResponse::timeout()]);
    fakeEetEvents();
    $sale = register(cashRegister());

    Eet::resubmit(freshSale($sale));

    Event::assertDispatchedTimes(SaleReopened::class, 1);
    Event::assertDispatched(SaleReopened::class, fn (SaleReopened $event): bool => $event->sale->is($sale));
    Event::assertNotDispatched(SaleUnconfirmed::class);
});

it('confirms a sale that a concurrent attempt rejected meanwhile, with the POK of the verified confirmation [R67]', function (): void {
    useTestResponseCa();
    importFixture(taxpayer());
    fakeEetEvents();
    fakeEetService(function (SoapMessage $message): EetResponse {
        // a concurrent attempt rejects the sale while this attempt is running
        Sale::query()->update(['status' => SaleStatus::Rejected->value]);

        return confirmationFor($message);
    });

    $sale = register(cashRegister());
    $pok = EetResponse::confirmationCodeFor(sentSoapMessages()[0]->uuid(), 'ff');

    expect(freshSale($sale)->status)->toBe(SaleStatus::Confirmed)
        ->and(freshSale($sale)->confirmation_code)->toBe($pok);
    Event::assertDispatched(SaleConfirmed::class);
});

it('keeps a sale confirmed meanwhile when this attempt times out, and still stores the attempt [R67]', function (): void {
    importFixture(taxpayer());
    Http::preventStrayRequests();
    Http::fake(function (Request $request): PromiseInterface {
        Sale::query()->update(['status' => SaleStatus::Confirmed->value, 'confirmation_code' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee-ff']);

        return Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out after 4001 milliseconds', $request->toPsrRequest(), null, ['errno' => 28]));
    });

    $sale = register(cashRegister());

    expect(freshSale($sale)->status)->toBe(SaleStatus::Confirmed)
        ->and(freshSale($sale)->confirmation_code)->toBe('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee-ff')
        ->and(attemptsOf($sale))->toHaveCount(1);
});

it('never changes the POK of a sale confirmed meanwhile [R67]', function (): void {
    useTestResponseCa();
    importFixture(taxpayer());
    fakeEetEvents();
    fakeEetService(function (SoapMessage $message): EetResponse {
        Sale::query()->update(['status' => SaleStatus::Confirmed->value, 'confirmation_code' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee-ff']);

        return confirmationFor($message);
    });

    $sale = register(cashRegister());

    expect(freshSale($sale)->status)->toBe(SaleStatus::Confirmed)
        ->and(freshSale($sale)->confirmation_code)->toBe('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee-ff')
        ->and(attemptsOf($sale))->toHaveCount(1)
        ->and(attemptsOf($sale)->first()?->outcome)->toBe(AttemptOutcome::Confirmed);
    Event::assertNotDispatched(SaleConfirmed::class);
});

it('does not overwrite a status changed meanwhile with a retryable result [R67]', function (): void {
    importFixture(taxpayer());
    fakeEetEvents();
    fakeEetService(function (SoapMessage $message): EetResponse {
        Sale::query()->update(['status' => SaleStatus::Rejected->value]);

        return EetResponse::error($message->uuid(), -1)->test('true');
    });

    $sale = register(cashRegister());

    expect(freshSale($sale)->status)->toBe(SaleStatus::Rejected)
        ->and(attemptsOf($sale))->toHaveCount(1)
        ->and(attemptsOf($sale)->first()?->outcome)->toBe(AttemptOutcome::TemporaryError);
    Event::assertNotDispatched(SaleUnconfirmed::class);
});
