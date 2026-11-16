<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Events\SaleConfirmed;
use Gunner42\LaravelEetModule\Events\SaleOverdue;
use Gunner42\LaravelEetModule\Events\SaleReopened;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Jobs\ResubmitSale;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\SubmissionAttempt;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Gunner42\LaravelEetModule\Tests\Support\SoapMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
});

function pendingWithoutAttempt(Sale $sale): Sale
{
    SubmissionAttempt::query()->where('sale_id', $sale->getKey())->delete();
    DB::table('eet_sales')->where('id', $sale->getKey())->update(['status' => SaleStatus::Pending->value]);

    return freshSale($sale);
}

it('performs one attempt for every unconfirmed sale, ignoring the backoff, and exits with 0 when all are confirmed [R53]', function (): void {
    Eet::fake([FakeResponse::timeout(), FakeResponse::timeout()]);
    $cashRegister = cashRegister();
    $first = register($cashRegister, saleData('1'));
    $second = register($cashRegister, saleData('2'));

    $this->artisan('eet:resubmit')->assertExitCode(0);

    expect(freshSale($first)->status)->toBe(SaleStatus::Confirmed)
        ->and(freshSale($second)->status)->toBe(SaleStatus::Confirmed)
        ->and(attemptsOf($first))->toHaveCount(2)
        ->and(attemptsOf($second))->toHaveCount(2);
});

it('exits with 1 when a processed sale stays unconfirmed [R53]', function (): void {
    Eet::fake([FakeResponse::timeout(), FakeResponse::timeout()]);
    $sale = register(cashRegister());

    $this->artisan('eet:resubmit')->assertExitCode(1);

    expect(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed)
        ->and(attemptsOf($sale))->toHaveCount(2);
});

it('leaves confirmed, rejected and fresh pending sales alone without --sale [R53, R38, R64]', function (): void {
    Eet::fake([FakeResponse::confirmed(), FakeResponse::error(4), FakeResponse::timeout()]);
    $cashRegister = cashRegister();
    $confirmed = register($cashRegister, saleData('1'));
    $rejected = register($cashRegister, saleData('2'));
    $pending = pendingWithoutAttempt(register($cashRegister, saleData('3')));

    $this->artisan('eet:resubmit')->assertExitCode(0);

    expect(attemptsOf($confirmed))->toHaveCount(1)
        ->and(attemptsOf($rejected))->toHaveCount(1)
        ->and(freshSale($rejected)->status)->toBe(SaleStatus::Rejected)
        ->and(attemptsOf($pending))->toHaveCount(0);
    Eet::assertSentCount(3);
});

it('sends a sale left in Pending for more than 68 s with prvni_zaslani="true" and skips one exactly 68 s old [R63, R53]', function (): void {
    Eet::fake([FakeResponse::timeout(), FakeResponse::timeout()]);
    $cashRegister = cashRegister();
    $old = pendingWithoutAttempt(register($cashRegister, saleData('1')));
    $this->travel(1)->seconds();
    $young = pendingWithoutAttempt(register($cashRegister, saleData('2')));
    $this->travelTo(frozenNow()->addSeconds(69));

    $this->artisan('eet:resubmit')->assertExitCode(0);

    expect(freshSale($old)->status)->toBe(SaleStatus::Confirmed)
        ->and(attemptsOf($old))->toHaveCount(1)
        ->and(attemptsOf($old)->first()?->is_first_submission)->toBeTrue()
        ->and(freshSale($young)->status)->toBe(SaleStatus::Pending)
        ->and(attemptsOf($young))->toHaveCount(0);
    Eet::assertSent(fn (SaleMessage $message): bool => $message->sequenceNumber === '1' && $message->isFirstSubmission);
});

it('never reopens a sale that becomes rejected while eet:resubmit without --sale runs [R53, R64]', function (): void {
    Eet::fake([FakeResponse::timeout(), FakeResponse::timeout()]);
    $cashRegister = cashRegister();
    $first = register($cashRegister, saleData('1'));
    $second = register($cashRegister, saleData('2'));
    $reopened = [];
    $rejectedMeanwhile = null;
    Event::listen(SaleReopened::class, function (SaleReopened $event) use (&$reopened): void {
        $reopened[] = $event->sale->getKey();
    });
    Event::listen(SaleConfirmed::class, function (SaleConfirmed $event) use (&$rejectedMeanwhile): void {
        if ($rejectedMeanwhile !== null) {
            return;
        }

        // a concurrent process rejects the other selected sale with code 4 (reopenable without --force)
        $other = Sale::query()->whereKeyNot($event->sale->getKey())->where('status', SaleStatus::Unconfirmed->value)->first();

        if ($other instanceof Sale) {
            $attempt = SubmissionAttempt::query()->where('sale_id', $other->getKey())->orderByDesc('id')->firstOrFail();
            $attempt->forceFill(['outcome' => AttemptOutcome::Rejected, 'error_code' => 4])->save();
            DB::table('eet_sales')->where('id', $other->getKey())->update([
                'status' => SaleStatus::Rejected->value,
                'rejecting_attempt_id' => $attempt->getKey(),
            ]);
            $rejectedMeanwhile = $other->getKey();
        }
    });

    $this->artisan('eet:resubmit')->run();

    expect($rejectedMeanwhile)->toBeIn([$first->getKey(), $second->getKey()]);

    $rejected = Sale::query()->findOrFail($rejectedMeanwhile);

    expect($rejected->status)->toBe(SaleStatus::Rejected)
        ->and($rejected->reopened_at)->toBeNull()
        ->and(attemptsOf($rejected))->toHaveCount(1)
        ->and($reopened)->toBe([]);
});

it('keeps processing the other sales when one sale throws an unexpected exception and exits with 1 [R53]', function (): void {
    useTestResponseCa();
    importFixture(taxpayer());
    $phase = 'register';
    fakeEetService(function (SoapMessage $message) use (&$phase): EetResponse {
        if ($phase === 'register') {
            return EetResponse::error($message->uuid(), -1)->test('true');
        }

        if ($message->data()['porad_cis'] === '2') {
            throw new RuntimeException('Unexpected failure for sale 2');
        }

        return confirmationFor($message);
    });
    $cashRegister = cashRegister();
    $first = register($cashRegister, saleData('1'));
    $second = register($cashRegister, saleData('2'));
    $third = register($cashRegister, saleData('3'));
    $phase = 'command';

    $this->artisan('eet:resubmit')
        ->expectsOutputToContain((string) $second->getKey())
        ->assertExitCode(1);

    expect(freshSale($first)->status)->toBe(SaleStatus::Confirmed)
        ->and(freshSale($third)->status)->toBe(SaleStatus::Confirmed)
        ->and(freshSale($second)->status)->toBe(SaleStatus::Unconfirmed)
        ->and(attemptsOf($second))->toHaveCount(1);
});

it('reopens a rejected sale given by --sale when allowed [R53, R64]', function (): void {
    Eet::fake([FakeResponse::error(4), FakeResponse::confirmed()]);
    $sale = register(cashRegister());

    $this->artisan('eet:resubmit', ['--sale' => [(string) $sale->getKey()]])->assertExitCode(0);

    expect(freshSale($sale)->status)->toBe(SaleStatus::Confirmed);
});

it('prints the reason and exits with 1 when a sale given by --sale cannot be reopened [R53, R64]', function (): void {
    Eet::fake([FakeResponse::error(3)]);
    $sale = register(cashRegister());

    $this->artisan('eet:resubmit', ['--sale' => [(string) $sale->getKey()]])
        ->expectsOutputToContain((string) $sale->getKey())
        ->assertExitCode(1);

    expect(freshSale($sale)->status)->toBe(SaleStatus::Rejected)
        ->and(attemptsOf($sale))->toHaveCount(1);
});

it('reopens a sale rejected with code 3 with --sale and --force [R53, R64]', function (): void {
    Eet::fake([FakeResponse::error(3), FakeResponse::confirmed()]);
    $sale = register(cashRegister());

    $this->artisan('eet:resubmit', ['--sale' => [(string) $sale->getKey()], '--force' => true])->assertExitCode(0);

    expect(freshSale($sale)->status)->toBe(SaleStatus::Confirmed);
});

it('ignores --force without --sale [R53]', function (): void {
    Eet::fake([FakeResponse::error(3)]);
    $sale = register(cashRegister());

    $this->artisan('eet:resubmit', ['--force' => true])->assertExitCode(0);

    expect(freshSale($sale)->status)->toBe(SaleStatus::Rejected)
        ->and(attemptsOf($sale))->toHaveCount(1);
});

it('reports a missing sale ID as an error and exits with 1 [R53]', function (): void {
    Eet::fake();

    $this->artisan('eet:resubmit', ['--sale' => ['999999']])
        ->expectsOutputToContain('999999')
        ->assertExitCode(1);
});

it('processes several sales given by --sale [R53]', function (): void {
    Eet::fake([FakeResponse::timeout(), FakeResponse::timeout()]);
    $cashRegister = cashRegister();
    $first = register($cashRegister, saleData('1'));
    $second = register($cashRegister, saleData('2'));

    $this->artisan('eet:resubmit', ['--sale' => [(string) $first->getKey(), (string) $second->getKey()]])->assertExitCode(0);

    expect(freshSale($first)->status)->toBe(SaleStatus::Confirmed)
        ->and(freshSale($second)->status)->toBe(SaleStatus::Confirmed);
});

it('skips a sale whose lock is taken, lists it and does not count it for the exit code [R53, R43]', function (): void {
    Eet::fake([FakeResponse::timeout()]);
    $sale = register(cashRegister());
    Cache::store()->lock('laravel-eet-module:sale:'.$sale->getKey(), 120)->get();

    $this->artisan('eet:resubmit')
        ->expectsOutputToContain((string) $sale->getKey())
        ->assertExitCode(0);

    expect(attemptsOf($sale))->toHaveCount(1);
    Eet::assertSentCount(1);
});

it('checks the 48 h deadline while running [R44, R53]', function (): void {
    Eet::fake([FakeResponse::timeout(), FakeResponse::timeout()]);
    fakeEetEvents();
    $sale = register(cashRegister('CZ00000019', Environment::Production), saleData('1', frozenNow()));
    $this->travelTo(frozenNow()->addHours(48)->addSecond());

    $this->artisan('eet:resubmit')->assertExitCode(1);

    Event::assertDispatchedTimes(SaleOverdue::class, 1);
    expect(freshSale($sale)->overdue_notified_at)->not->toBeNull();
});

it('prints the configuration key and exits with 1 on an invalid configuration [R53, R68]', function (): void {
    Eet::fake([FakeResponse::timeout()]);
    $sale = register(cashRegister());
    config()->set('laravel-eet-module.mode', 'dry-run');

    $this->artisan('eet:resubmit')
        ->expectsOutputToContain('mode')
        ->assertExitCode(1);

    expect(attemptsOf($sale))->toHaveCount(1);
});

it('lets the job fail without recording an attempt on an invalid configuration [R68]', function (): void {
    Eet::fake([FakeResponse::timeout()]);
    $sale = register(cashRegister());
    config()->set('laravel-eet-module.response_timeout_ms', 10);

    try {
        dispatch_sync(new ResubmitSale((int) $sale->getKey()));
    } catch (InvalidConfiguration) {
        // failing by exception is one way for the job to fail
    }

    expect(attemptsOf($sale))->toHaveCount(1)
        ->and(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed);
    Eet::assertSentCount(1);
});
