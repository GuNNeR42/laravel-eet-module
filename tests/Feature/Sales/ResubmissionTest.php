<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Events\SaleOverdue;
use Gunner42\LaravelEetModule\Events\SaleRejected;
use Gunner42\LaravelEetModule\Events\SaleReopened;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Exceptions\SaleNotReopenable;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Jobs\ResubmitSale;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\SubmissionAttempt;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Gunner42\LaravelEetModule\Tests\Support\SoapMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    $this->travelTo(frozenNow());
});

/**
 * Runs the last pushed ResubmitSale job synchronously.
 */
function runLastResubmitJob(): void
{
    $job = Queue::pushed(ResubmitSale::class)->last();

    if (! $job instanceof ResubmitSale) {
        throw new RuntimeException('No ResubmitSale job was pushed.');
    }

    dispatch_sync($job);
}

/**
 * @return list<SaleMessage>
 */
function messagesSentByFake(): array
{
    $messages = [];
    Eet::assertSent(function (SaleMessage $message) use (&$messages): bool {
        $messages[] = $message;

        return true;
    });

    return $messages;
}

/**
 * Creates a sale left in Pending (R63): the process died after
 * the sale was stored and before an attempt was recorded.
 */
function pendingSaleAfterCrash(CarbonImmutable $storedAt): Sale
{
    test()->travelTo($storedAt);
    Eet::fake([FakeResponse::timeout()]);
    $sale = register(cashRegister('CZ8551015704', Environment::Production));
    SubmissionAttempt::query()->where('sale_id', $sale->getKey())->delete();
    DB::table('eet_sales')->where('id', $sale->getKey())->update(['status' => SaleStatus::Pending->value]);

    return freshSale($sale);
}

function lockSale(Sale $sale): void
{
    $acquired = Cache::store()->lock('laravel-eet-module:sale:'.$sale->getKey(), 120)->get();

    expect($acquired)->toBeTrue();
}

describe('scheduling of resubmissions', function (): void {
    beforeEach(function (): void {
        Queue::fake();
    });

    it('schedules ResubmitSale with the backoff 60, 300, 900, 3600 and then 3600 s again [R40]', function (): void {
        Eet::fake(array_fill(0, 5, FakeResponse::timeout()));

        register(cashRegister());
        for ($i = 0; $i < 4; $i++) {
            runLastResubmitJob();
        }

        expect(Queue::pushed(ResubmitSale::class)->map(fn (ResubmitSale $job): ?int => jobDelay($job))->values()->all())
            ->toBe([60, 300, 900, 3600, 3600]);
    });

    it('uses the configured connection, queue and backoff [R40]', function (): void {
        config()->set('queue.connections.eet-redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default']);
        config()->set('laravel-eet-module.resubmission.connection', 'eet-redis');
        config()->set('laravel-eet-module.resubmission.queue', 'eet');
        config()->set('laravel-eet-module.resubmission.backoff', [45, 90]);
        Eet::fake(array_fill(0, 3, FakeResponse::timeout()));

        register(cashRegister());
        runLastResubmitJob();
        runLastResubmitJob();

        $jobs = Queue::pushed(ResubmitSale::class)->values();

        expect($jobs->map(fn (ResubmitSale $job): ?int => jobDelay($job))->all())->toBe([45, 90, 90])
            ->and($jobs->first()?->connection)->toBe('eet-redis')
            ->and($jobs->first()?->queue)->toBe('eet');
    });

    it('accepts backoff values down to a configured min_backoff [R40]', function (): void {
        config()->set('laravel-eet-module.resubmission.min_backoff', 5);
        config()->set('laravel-eet-module.resubmission.backoff', [10]);
        Eet::fake([FakeResponse::timeout()]);

        register(cashRegister());

        Queue::assertPushed(ResubmitSale::class, fn (ResubmitSale $job): bool => jobDelay($job) === 10);
    });

    it('does not limit the number of attempts for temporary errors [R40, R41]', function (): void {
        Eet::fake(array_fill(0, 20, FakeResponse::error(-1)));

        $sale = register(cashRegister());
        for ($i = 0; $i < 19; $i++) {
            runLastResubmitJob();
        }

        expect(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed)
            ->and(attemptsOf($sale))->toHaveCount(20);
        Queue::assertPushed(ResubmitSale::class, 20);
    });

    it('rejects the sale after three AmbiguousError attempts, not necessarily consecutive [R41]', function (): void {
        Eet::fake([FakeResponse::error(8), FakeResponse::timeout(), FakeResponse::error(999), FakeResponse::error(-1), FakeResponse::error(8)]);
        fakeEetEvents();

        $sale = register(cashRegister());
        for ($i = 0; $i < 4; $i++) {
            expect(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed);
            runLastResubmitJob();
        }

        $limitAttempt = lastAttempt($sale);

        expect(freshSale($sale)->status)->toBe(SaleStatus::Rejected)
            ->and($limitAttempt->outcome)->toBe(AttemptOutcome::AmbiguousError)
            ->and(freshSale($sale)->rejecting_attempt_id)->toBe($limitAttempt->getKey());
        Event::assertDispatched(SaleRejected::class, fn (SaleRejected $event): bool => $event->errorCode === 8);
        Queue::assertPushed(ResubmitSale::class, 4);
    });

    it('uses a configured max_ambiguous_attempts [R41]', function (): void {
        config()->set('laravel-eet-module.resubmission.max_ambiguous_attempts', 1);
        Eet::fake([FakeResponse::error(8)]);

        expect(register(cashRegister())->status)->toBe(SaleStatus::Rejected);
        Queue::assertNothingPushed();
    });

    it('resubmits the same Data with a new UUID, the current dat_odesl and prvni_zaslani="false" [R42]', function (): void {
        Eet::fake([FakeResponse::timeout(), FakeResponse::confirmed()]);

        $sale = register(cashRegister());
        $this->travel(61)->seconds();
        runLastResubmitJob();

        [$first, $second] = messagesSentByFake();
        $firstData = (new SoapMessage($first->xml))->data();
        $secondData = (new SoapMessage($second->xml))->data();

        expect($secondData)->toBe($firstData)
            ->and($second->messageUuid)->not->toBe($first->messageUuid)
            ->and($second->isFirstSubmission)->toBeFalse()
            ->and($second->sentAt)->toBe('2026-11-20T10:01:01+01:00')
            ->and(freshSale($sale)->status)->toBe(SaleStatus::Confirmed);
    });

    it('does nothing in the job for a sale that is neither Unconfirmed nor Pending beyond the R63 boundary [R43]', function (): void {
        Eet::fake();
        $confirmed = register(cashRegister());
        $pending = pendingSaleAfterCrash(frozenNow());
        $this->travelTo(frozenNow()->addSeconds(10));

        dispatch_sync(new ResubmitSale((int) $confirmed->getKey()));
        dispatch_sync(new ResubmitSale((int) $pending->getKey()));

        expect(attemptsOf($confirmed))->toHaveCount(1)
            ->and(attemptsOf($pending))->toHaveCount(0)
            ->and(freshSale($pending)->status)->toBe(SaleStatus::Pending);
    });

    it('lets the job and resubmit() skip a sale whose lock is taken, without sending or recording an attempt [R43, R45]', function (): void {
        Eet::fake([FakeResponse::timeout()]);
        fakeEetEvents();
        $sale = register(cashRegister());
        Queue::fake();
        lockSale($sale);

        dispatch_sync(new ResubmitSale((int) $sale->getKey()));
        $returned = Eet::resubmit(freshSale($sale));

        expect($returned->status)->toBe(SaleStatus::Unconfirmed)
            ->and(attemptsOf($sale))->toHaveCount(1);
        Eet::assertSentCount(1);
        Queue::assertNothingPushed();
    });

    it('lets resubmit() on a rejected sale with a taken lock change nothing [R45]', function (): void {
        Eet::fake([FakeResponse::error(4)]);
        $sale = register(cashRegister());
        fakeEetEvents();
        Queue::fake();
        lockSale($sale);

        $returned = Eet::resubmit(freshSale($sale));

        expect($returned->status)->toBe(SaleStatus::Rejected)
            ->and(freshSale($sale)->status)->toBe(SaleStatus::Rejected)
            ->and(freshSale($sale)->reopened_at)->toBeNull()
            ->and(attemptsOf($sale))->toHaveCount(1);
        Event::assertNotDispatched(SaleReopened::class);
        Queue::assertNothingPushed();
    });

    it('uses the configured lock store [R43]', function (): void {
        // a private cache directory keeps parallel workers from sharing lock files
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eet-locks-'.bin2hex(random_bytes(8));
        config()->set('cache.stores.file.path', $directory);
        config()->set('cache.stores.file.lock_path', $directory);
        config()->set('laravel-eet-module.lock_store', 'file');
        Eet::fake([FakeResponse::timeout()]);
        $sale = register(cashRegister());
        Cache::store('file')->lock('laravel-eet-module:sale:'.$sale->getKey(), 120)->get();

        Eet::resubmit(freshSale($sale));

        expect(attemptsOf($sale))->toHaveCount(1);
        Cache::store('file')->lock('laravel-eet-module:sale:'.$sale->getKey())->forceRelease();
        File::deleteDirectory($directory);
    });

    it('dispatches SaleOverdue once for a production live sale unconfirmed for more than 48 h and keeps resubmitting [R44]', function (): void {
        Eet::fake(array_fill(0, 4, FakeResponse::timeout()));
        fakeEetEvents();
        $occurredAt = frozenNow();
        $sale = register(cashRegister('CZ00000019', Environment::Production), saleData('1', $occurredAt));

        $this->travelTo($occurredAt->addHours(48));
        runLastResubmitJob();
        Event::assertNotDispatched(SaleOverdue::class);
        expect(freshSale($sale)->isOverdue())->toBeFalse();

        $this->travelTo($occurredAt->addHours(48)->addSecond());
        runLastResubmitJob();
        runLastResubmitJob();

        Event::assertDispatchedTimes(SaleOverdue::class, 1);
        Event::assertDispatched(SaleOverdue::class, fn (SaleOverdue $event): bool => $event->sale->is($sale));
        expect(freshSale($sale)->overdue_notified_at)->not->toBeNull()
            ->and(freshSale($sale)->isOverdue())->toBeTrue()
            ->and(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed);
        Queue::assertPushed(ResubmitSale::class, 4);
    });

    it('does not dispatch SaleOverdue for verification mode or playground sales [R44, R46]', function (string $environment, string $mode): void {
        config()->set('laravel-eet-module.mode', $mode);
        Eet::fake(array_fill(0, 2, $mode === 'verification' ? FakeResponse::error(-1) : FakeResponse::timeout()));
        fakeEetEvents();
        $sale = register(cashRegister('CZ00000019', Environment::from($environment)), saleData('1', frozenNow()));

        $this->travelTo(frozenNow()->addHours(49));
        runLastResubmitJob();

        Event::assertNotDispatched(SaleOverdue::class);
        expect(freshSale($sale)->isOverdue())->toBeFalse()
            ->and(freshSale($sale)->overdue_notified_at)->toBeNull();
    })->with([
        'playground live' => ['playground', 'live'],
        'production verification' => ['production', 'verification'],
    ]);

    it('lets a sale left in Pending beyond the R63 boundary become overdue [R44, R63]', function (): void {
        fakeEetEvents();
        $sale = pendingSaleAfterCrash(frozenNow());
        $this->travelTo(frozenNow()->addHours(49));

        expect(freshSale($sale)->isOverdue())->toBeTrue();

        $this->artisan('eet:status')->run();

        Event::assertDispatchedTimes(SaleOverdue::class, 1);
    });
});

describe('resubmit()', function (): void {
    beforeEach(function (): void {
        Queue::fake();
    });

    it('performs one immediate attempt for an unconfirmed sale [R45]', function (): void {
        Eet::fake([FakeResponse::timeout(), FakeResponse::confirmed()]);
        $sale = register(cashRegister());

        $returned = Eet::resubmit(freshSale($sale));

        expect($returned->status)->toBe(SaleStatus::Confirmed)
            ->and(attemptsOf($sale))->toHaveCount(2);
    });

    it('returns confirmed, verified and fresh pending sales unchanged [R45]', function (): void {
        Eet::fake();
        $confirmed = register(cashRegister());
        $pending = pendingSaleAfterCrash(frozenNow());
        $this->travelTo(frozenNow()->addSeconds(68));

        expect(Eet::resubmit($confirmed)->status)->toBe(SaleStatus::Confirmed)
            ->and(Eet::resubmit($pending)->status)->toBe(SaleStatus::Pending)
            ->and(attemptsOf($confirmed))->toHaveCount(1)
            ->and(attemptsOf($pending))->toHaveCount(0);
    });

    it('sends a sale left in Pending for more than 68 s with prvni_zaslani="true" [R63, R45]', function (): void {
        $sale = pendingSaleAfterCrash(frozenNow());
        $this->travelTo(frozenNow()->addSeconds(69));
        Eet::fake([FakeResponse::confirmed()]);

        $returned = Eet::resubmit($sale);

        expect($returned->status)->toBe(SaleStatus::Confirmed)
            ->and(attemptsOf($sale))->toHaveCount(1)
            ->and(attemptsOf($sale)->first()?->is_first_submission)->toBeTrue();
        Eet::assertSent(fn (SaleMessage $message): bool => $message->isFirstSubmission);
    });

    it('derives the R63 boundary from response_timeout_ms [R63]', function (): void {
        config()->set('laravel-eet-module.response_timeout_ms', 5000);
        $sale = pendingSaleAfterCrash(frozenNow());
        Eet::fake([FakeResponse::confirmed()]);

        $this->travelTo(frozenNow()->addSeconds(70));
        expect(Eet::resubmit($sale)->status)->toBe(SaleStatus::Pending);

        $this->travelTo(frozenNow()->addSeconds(71));
        expect(Eet::resubmit($sale)->status)->toBe(SaleStatus::Confirmed);
    });

    it('reopens a sale rejected with code 4 and sends it with prvni_zaslani="false" and a newly selected certificate [R64]', function (): void {
        useTestResponseCa();
        importFixture(taxpayer());
        fakeEetEvents();
        $responses = [
            fn (SoapMessage $message): EetResponse => EetResponse::error($message->uuid(), 4, 'Neplatny podpis SOAP zpravy')->test('true'),
            fn (SoapMessage $message): EetResponse => confirmationFor($message),
        ];
        fakeEetService(function (SoapMessage $message) use (&$responses): EetResponse {
            $next = array_shift($responses);

            return $next($message);
        });
        $sale = register(cashRegister());

        expect($sale->status)->toBe(SaleStatus::Rejected);

        importFixture(taxpayer(), 'cash-register/CZ00000019-until-2027-12-31.pem');
        $returned = Eet::resubmit(freshSale($sale));

        $messages = sentSoapMessages();

        expect($returned->status)->toBe(SaleStatus::Confirmed)
            ->and($messages)->toHaveCount(2)
            ->and($messages[1]->header()['prvni_zaslani'])->toBe('false')
            ->and($messages[1]->data())->toBe($messages[0]->data())
            ->and($messages[1]->uuid())->not->toBe($messages[0]->uuid())
            ->and($messages[1]->tokenFingerprint())->toBe(Fixtures::fingerprint('cash-register/CZ00000019-until-2027-12-31.pem'));
        Event::assertDispatchedTimes(SaleReopened::class, 1);
    });

    it('reopens without force a sale rejected after the AmbiguousError limit [R64, R41]', function (int $code): void {
        config()->set('laravel-eet-module.resubmission.max_ambiguous_attempts', 1);
        Eet::fake([FakeResponse::error($code), FakeResponse::timeout()]);
        fakeEetEvents();
        $sale = register(cashRegister());

        expect($sale->status)->toBe(SaleStatus::Rejected);

        $returned = Eet::resubmit(freshSale($sale));

        expect($returned->status)->toBe(SaleStatus::Unconfirmed)
            ->and(attemptsOf($sale))->toHaveCount(2)
            ->and(attemptsOf($sale)->last()?->is_first_submission)->toBeFalse();
        Event::assertDispatched(SaleReopened::class);
    })->with(['code 8' => 8, 'unknown code 999' => 999, 'code 0 in live mode' => 0]);

    it('refuses to reopen a sale rejected with code 2, 3, 6 or 7 without force [R64]', function (int $code): void {
        Eet::fake([FakeResponse::error($code)]);
        fakeEetEvents();
        $sale = register(cashRegister());

        try {
            Eet::resubmit(freshSale($sale));

            throw new AssertionFailedError('SaleNotReopenable was not thrown.');
        } catch (SaleNotReopenable $exception) {
            expect($exception->errorCode)->toBe($code);
        }

        expect(freshSale($sale)->status)->toBe(SaleStatus::Rejected)
            ->and(attemptsOf($sale))->toHaveCount(1);
        Eet::assertSentCount(1);
        Event::assertNotDispatched(SaleReopened::class);
    })->with([2, 3, 6, 7]);

    it('reopens a sale rejected with code 3 with force [R64]', function (): void {
        Eet::fake([FakeResponse::error(3), FakeResponse::confirmed()]);
        fakeEetEvents();
        $sale = register(cashRegister());

        $returned = Eet::resubmit(freshSale($sale), force: true);

        expect($returned->status)->toBe(SaleStatus::Confirmed)
            ->and(attemptsOf($sale))->toHaveCount(2);
        Event::assertDispatchedTimes(SaleReopened::class, 1);
    });

    it('stores the rejecting attempt, clears it on reopening and sets reopened_at [R64]', function (): void {
        Eet::fake([FakeResponse::error(4), FakeResponse::timeout()]);
        $sale = register(cashRegister());
        $rejecting = lastAttempt($sale);

        expect(freshSale($sale)->rejecting_attempt_id)->toBe($rejecting->getKey())
            ->and(freshSale($sale)->reopened_at)->toBeNull();

        $this->travel(5)->minutes();
        Eet::resubmit(freshSale($sale));

        expect(freshSale($sale)->rejecting_attempt_id)->toBeNull()
            ->and(CarbonImmutable::parse(freshSale($sale)->reopened_at)->equalTo(frozenNow()->addMinutes(5)))->toBeTrue();
    });

    it('decides by the rejecting attempt, not by later attempt records [R64, R67]', function (): void {
        importFixture(taxpayer());
        $responses = [-1, -1];
        fakeEetService(function (SoapMessage $message) use (&$responses): EetResponse {
            $code = array_shift($responses);

            if (count($responses) === 0) {
                // while the second attempt runs, a concurrent attempt rejects the sale with code 3
                $first = SubmissionAttempt::query()->orderBy('id')->firstOrFail();
                $first->forceFill(['outcome' => AttemptOutcome::Rejected, 'error_code' => 3])->save();
                DB::table('eet_sales')->update(['status' => SaleStatus::Rejected->value, 'rejecting_attempt_id' => $first->getKey()]);
            }

            return EetResponse::error($message->uuid(), (int) $code)->test('true');
        });
        $sale = register(cashRegister());
        Eet::resubmit(freshSale($sale));

        expect(freshSale($sale)->status)->toBe(SaleStatus::Rejected)
            ->and(freshSale($sale)->rejecting_attempt_id)->toBe(attemptsOf($sale)->first()?->getKey())
            ->and(attemptsOf($sale))->toHaveCount(2)
            ->and(fn (): Sale => Eet::resubmit(freshSale($sale)))->toThrow(SaleNotReopenable::class);
    });

    it('counts AmbiguousError attempts only after reopened_at [R64, R41]', function (): void {
        Eet::fake([FakeResponse::error(8), FakeResponse::error(8), FakeResponse::error(8), FakeResponse::error(8), FakeResponse::error(8), FakeResponse::error(8)]);
        $sale = register(cashRegister());
        Eet::resubmit(freshSale($sale));
        Eet::resubmit(freshSale($sale));

        expect(freshSale($sale)->status)->toBe(SaleStatus::Rejected);

        $this->travel(1)->minutes();
        Eet::resubmit(freshSale($sale));
        expect(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed);

        Eet::resubmit(freshSale($sale));
        expect(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed);

        Eet::resubmit(freshSale($sale));
        expect(freshSale($sale)->status)->toBe(SaleStatus::Rejected)
            ->and(attemptsOf($sale))->toHaveCount(6);
    });
});

describe('reopening at a frozen moment', function (): void {
    beforeEach(function (): void {
        Queue::fake();
    });

    it('stores the last attempt on reopening and restarts the AmbiguousError counter by attempt ID, not by time [R64, R41]', function (): void {
        // time stays frozen for the whole test: everything happens in the same second
        Eet::fake(array_fill(0, 6, FakeResponse::error(8)));
        $sale = register(cashRegister());
        Eet::resubmit(freshSale($sale));
        Eet::resubmit(freshSale($sale));
        $lastBeforeReopening = lastAttempt($sale);

        expect(freshSale($sale)->status)->toBe(SaleStatus::Rejected)
            ->and(freshSale($sale)->reopened_after_attempt_id)->toBeNull();

        Eet::resubmit(freshSale($sale));

        expect(freshSale($sale)->reopened_after_attempt_id)->toBe($lastBeforeReopening->getKey())
            ->and(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed);

        Eet::resubmit(freshSale($sale));
        expect(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed);

        Eet::resubmit(freshSale($sale));
        expect(freshSale($sale)->status)->toBe(SaleStatus::Rejected)
            ->and(attemptsOf($sale))->toHaveCount(6);
    });

    it('restarts the backoff after reopening at the same moment [R64, R40]', function (): void {
        Eet::fake([FakeResponse::error(4), FakeResponse::timeout()]);
        $sale = register(cashRegister());

        Eet::resubmit(freshSale($sale));

        Queue::assertPushed(ResubmitSale::class, 1);
        Queue::assertPushed(ResubmitSale::class, fn (ResubmitSale $job): bool => jobDelay($job) === 60);
    });

    it('counts the backoff after reopening by attempt ID even with earlier unconfirmed attempts [R64, R40]', function (): void {
        Eet::fake([FakeResponse::timeout(), FakeResponse::timeout(), FakeResponse::error(4), FakeResponse::timeout()]);
        $sale = register(cashRegister());
        Eet::resubmit(freshSale($sale));
        Eet::resubmit(freshSale($sale));

        expect(freshSale($sale)->status)->toBe(SaleStatus::Rejected);

        Eet::resubmit(freshSale($sale));

        expect(Queue::pushed(ResubmitSale::class)->map(fn (ResubmitSale $job): ?int => jobDelay($job))->values()->all())
            ->toBe([60, 300, 60]);
    });
});

describe('real synchronous queue', function (): void {
    it('schedules the job only after the result is committed and the lock released [R40, R43]', function (): void {
        // the sync connection is allowed in tests (Q35) and runs the job at push time:
        // if the lock were still held, the job would do nothing (R43)
        Eet::fake([FakeResponse::timeout()]);

        $sale = register(cashRegister());

        expect(freshSale($sale)->status)->toBe(SaleStatus::Confirmed)
            ->and(attemptsOf($sale))->toHaveCount(2)
            ->and(attemptsOf($sale)->pluck('outcome')->all())->toBe([AttemptOutcome::Timeout, AttemptOutcome::Confirmed]);
    });
});

describe('resubmission configuration', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        config()->set('cache.stores.eet-null', ['driver' => 'null']);
    });

    it('rejects a queue connection whose driver ignores delays or drops jobs [R40, R68]', function (string $driver): void {
        config()->set('queue.connections.eet-test', ['driver' => $driver]);
        config()->set('laravel-eet-module.resubmission.connection', 'eet-test');
        Eet::fake();

        try {
            register(cashRegister());

            throw new AssertionFailedError('InvalidConfiguration was not thrown.');
        } catch (InvalidConfiguration $exception) {
            expect($exception->key)->toBe('resubmission.connection');
        }

        expect(Sale::query()->count())->toBe(0);
    })->with(['deferred', 'background', 'null']);

    it('rejects an unknown queue connection [R40, R68]', function (): void {
        config()->set('laravel-eet-module.resubmission.connection', 'does-not-exist');
        Eet::fake();

        expect(fn (): Sale => register(cashRegister()))->toThrow(InvalidConfiguration::class);
    });

    it('rejects the sync queue driver outside of tests [R40, R68]', function (): void {
        config()->set('queue.default', 'sync');
        config()->set('laravel-eet-module.lock_store', 'file');
        Http::preventStrayRequests();
        Http::fake();
        $cashRegister = cashRegister();

        $exception = outsideOfTests(function () use ($cashRegister): ?InvalidConfiguration {
            try {
                register($cashRegister);

                return null;
            } catch (InvalidConfiguration $exception) {
                return $exception;
            }
        });

        expect($exception?->key)->toBe('resubmission.connection')
            ->and(Sale::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('rejects backoff values below min_backoff and invalid resubmission settings [R40, R41, R68]', function (string $key, mixed $value): void {
        config()->set('laravel-eet-module.'.$key, $value);
        Eet::fake();

        try {
            register(cashRegister());

            throw new AssertionFailedError('InvalidConfiguration was not thrown.');
        } catch (InvalidConfiguration $exception) {
            expect($exception->key)->toBe($key);
        }
    })->with([
        'backoff below 30 s' => ['resubmission.backoff', [10]],
        'empty backoff' => ['resubmission.backoff', []],
        'non-integer backoff' => ['resubmission.backoff', ['60']],
        'min_backoff 0' => ['resubmission.min_backoff', 0],
        'empty queue name' => ['resubmission.queue', ''],
        'max_ambiguous_attempts 0' => ['resubmission.max_ambiguous_attempts', 0],
    ]);

    it('rejects a lock store without atomic locks [R43, R68]', function (array $store): void {
        config()->set('cache.stores.eet-lock', $store);
        config()->set('laravel-eet-module.lock_store', 'eet-lock');
        Eet::fake();

        try {
            register(cashRegister());

            throw new AssertionFailedError('InvalidConfiguration was not thrown.');
        } catch (InvalidConfiguration $exception) {
            expect($exception->key)->toBe('lock_store');
        }

        expect(Sale::query()->count())->toBe(0);
    })->with([
        'null driver' => [['driver' => 'null']],
        'failover over null' => [['driver' => 'failover', 'stores' => ['eet-null']]],
    ]);

    it('rejects an unknown lock store [R43, R68]', function (): void {
        config()->set('laravel-eet-module.lock_store', 'does-not-exist');
        Eet::fake();

        expect(fn (): Sale => register(cashRegister()))->toThrow(InvalidConfiguration::class);
    });

    it('allows the array lock store in tests but not outside of them [R43, R68]', function (): void {
        config()->set('cache.stores.eet-failover', ['driver' => 'failover', 'stores' => ['array']]);
        Eet::fake();

        config()->set('laravel-eet-module.lock_store', 'array');
        expect(register(cashRegister(), saleData('1'))->exists)->toBeTrue();

        Http::preventStrayRequests();
        Http::fake();
        $cashRegister = cashRegister('CZ683555118');

        foreach (['array', 'eet-failover'] as $store) {
            config()->set('laravel-eet-module.lock_store', $store);
            config()->set('laravel-eet-module.resubmission.connection', 'database');

            $exception = outsideOfTests(function () use ($cashRegister): ?InvalidConfiguration {
                try {
                    register($cashRegister, saleData('2'));

                    return null;
                } catch (InvalidConfiguration $exception) {
                    return $exception;
                }
            });

            expect($exception?->key)->toBe('lock_store');
        }

        expect(Sale::query()->count())->toBe(1);
    });
});
