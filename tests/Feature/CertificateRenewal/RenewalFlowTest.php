<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Enums\CertificateProblem;
use Gunner42\LaravelEetModule\Enums\RenewalProblem;
use Gunner42\LaravelEetModule\Enums\RenewalStatus;
use Gunner42\LaravelEetModule\Events\CertificateRenewalFailed;
use Gunner42\LaravelEetModule\Events\CertificateRenewed;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\CertificateRenewal;
use Gunner42\LaravelEetModule\Tests\Support\CaEetApi;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Gunner42\LaravelEetModule\Tests\Support\Renewal;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * The CA EET API is faked on the HTTP client boundary (R100), waiting is
 * faked with Sleep::fake(syncWithCarbon: true) (R94) and time is frozen at
 * 2026-11-19 00:00 UTC, 21 days before the renewed certificate expires
 * (spec/05 R80: the result must not depend on the run date).
 */

beforeEach(function (): void {
    $this->travelTo(Renewal::now());
    Sleep::fake(syncWithCarbon: true);
});

function renewalPath(string $route, string $reqId = CaEetApi::REQ_ID): string
{
    return match ($route) {
        'not-finished' => 'GET /request/not-finished',
        'renew' => 'POST /request/renew',
        'status' => "GET /request/{$reqId}/status",
        'claim-download' => "POST /request/{$reqId}/claim-download",
        'ack-download' => "POST /request/{$reqId}/ack-download",
        default => throw new RuntimeException("Unknown route [{$route}]."),
    };
}

function cashRegisterCertificateId(CashRegister $cashRegister): mixed
{
    return CashRegister::query()->findOrFail($cashRegister->getKey())->certificate_id;
}

// ---------------------------------------------------------------------------
// Happy path (R92–R97)
// ---------------------------------------------------------------------------

it('renews a certificate in one call: request, wait, download, store, switch and acknowledge [R92, R93, R94, R95, R96, R97]', function (): void {
    Renewal::fakeEvents();
    $api = CaEetApi::fake(['status' => [CaEetApi::inProcess(2), CaEetApi::issued()]]);
    $old = Renewal::certificate();
    $first = Renewal::cashRegister('POKLADNA-1', $old);
    $second = Renewal::cashRegister('POKLADNA-2', $old, 'CZ00000019', 182);
    $unpinned = Renewal::cashRegister('POKLADNA-3');

    $renewal = Eet::renewCertificate($old);

    $successor = Renewal::stored();

    expect($renewal)->toBeInstanceOf(CertificateRenewal::class)
        ->and($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($renewal?->request_id)->toBe(CaEetApi::REQ_ID)
        ->and($renewal?->problem)->toBeNull()
        ->and($renewal?->problem_detail)->toBeNull()
        ->and($renewal?->certificate_id)->toBe($old->getKey())
        ->and($renewal?->successor_id)->toBe($successor?->getKey())
        ->and($renewal?->acknowledged_at?->equalTo(Renewal::now()->addSeconds(2)))->toBeTrue()
        ->and($renewal?->completed_at)->not->toBeNull()
        ->and($renewal?->failed_at)->toBeNull()
        ->and($successor)->toBeInstanceOf(CashRegisterCertificate::class)
        ->and($successor?->taxpayer_id)->toBe($old->taxpayer_id)
        ->and($successor?->subject_eic)->toBe('CZ00000019')
        ->and($successor?->valid_to->equalTo(CarbonImmutable::parse(CaEetApi::SUCCESSOR_VALID_TO)))->toBeTrue()
        ->and($successor?->isUsable())->toBeTrue()
        ->and($successor?->hasProductionPolicy())->toBeTrue()
        ->and(Renewal::fresh($old)->successor()?->getKey())->toBe($successor?->getKey())
        ->and($successor?->predecessor()?->getKey())->toBe($old->getKey())
        ->and(CashRegisterCertificate::query()->whereKey($old->getKey())->exists())->toBeTrue()
        ->and(cashRegisterCertificateId($first))->toBe($successor?->getKey())
        ->and(cashRegisterCertificateId($second))->toBe($successor?->getKey())
        ->and(cashRegisterCertificateId($unpinned))->toBeNull()
        ->and($api->paths())->toBe([
            renewalPath('not-finished'),
            renewalPath('renew'),
            renewalPath('status'),
            renewalPath('status'),
            renewalPath('claim-download'),
            renewalPath('ack-download'),
        ]);

    Event::assertDispatchedTimes(CertificateRenewed::class, 1);
    Event::assertDispatched(CertificateRenewed::class, fn (CertificateRenewed $event): bool => $event->certificate->getKey() === $old->getKey()
        && $event->successor->getKey() === $successor?->getKey()
        && $event->renewal->getKey() === $renewal?->getKey());
    Event::assertNotDispatched(CertificateRenewalFailed::class);
});

it('stores the successor like an import: PEM, chain, encrypted key, serial, CN, validity and fingerprint [R95, R12]', function (): void {
    CaEetApi::fake();
    $old = Renewal::certificate();

    Eet::renewCertificate($old);

    $successor = Renewal::stored();
    $row = (array) DB::table('eet_cash_register_certificates')->where('id', $successor?->getKey())->first();

    expect($successor)->not->toBeNull()
        ->and(strtolower((string) openssl_x509_fingerprint((string) $successor?->certificate, 'sha256')))->toBe(CaEetApi::fingerprintOf(CaEetApi::SUCCESSOR))
        ->and(substr_count((string) $successor?->chain, '-----BEGIN CERTIFICATE-----'))->toBeGreaterThanOrEqual(1)
        ->and($successor?->valid_from->equalTo(CarbonImmutable::parse(CaEetApi::SUCCESSOR_VALID_FROM)))->toBeTrue()
        ->and(in_array(strtoupper((string) $successor?->serial_number), ['015A691A9BF5ACE98C', '15A691A9BF5ACE98C', CaEetApi::SUCCESSOR_SN], true))->toBeTrue()
        ->and((string) $row['private_key'])->not->toContain('PRIVATE KEY');
});

it('sends acknowledge-download only after the successor, the switched pins and Acknowledging are committed [R95, R96, R97]', function (): void {
    $api = CaEetApi::fake();
    $old = Renewal::certificate();
    $cashRegister = Renewal::cashRegister('POKLADNA-1', $old);
    $seen = [];
    $api->observe(function (string $route, Request $request) use (&$seen, $old, $cashRegister): void {
        if ($route !== 'ack-download') {
            return;
        }

        $successor = Renewal::stored();
        $seen = [
            'successor' => $successor?->getKey(),
            'status' => Renewal::latest($old)->status,
            'successor_id' => Renewal::latest($old)->successor_id,
            'pinned' => cashRegisterCertificateId($cashRegister),
        ];
    });

    Eet::renewCertificate($old);

    expect($seen['successor'] ?? null)->not->toBeNull()
        ->and($seen['status'] ?? null)->toBe(RenewalStatus::Acknowledging)
        ->and($seen['successor_id'] ?? null)->toBe($seen['successor'] ?? false)
        ->and($seen['pinned'] ?? null)->toBe($seen['successor'] ?? false)
        ->and($api->calls())->toBe(['not-finished', 'renew', 'status', 'claim-download', 'ack-download']);
});

it('dispatches CertificateRenewed once, after the commit [R96]', function (): void {
    CaEetApi::fake();
    $old = Renewal::certificate();
    $base = DB::transactionLevel();
    $observed = [];
    Event::listen(CertificateRenewed::class, function (CertificateRenewed $event) use (&$observed): void {
        $observed[] = [
            'level' => DB::transactionLevel(),
            'stored' => CashRegisterCertificate::query()->whereKey($event->successor->getKey())->exists(),
        ];
    });

    Eet::renewCertificate($old);

    expect($observed)->toHaveCount(1)
        ->and($observed[0]['level'])->toBe($base)
        ->and($observed[0]['stored'])->toBeTrue();
});

it('stores nothing and sends no acknowledge-download when switching the pins fails [R96]', function (): void {
    $api = CaEetApi::fake();
    $old = Renewal::certificate();
    $cashRegister = Renewal::cashRegister('POKLADNA-1', $old);
    $certificates = CashRegisterCertificate::query()->count();
    DB::statement("CREATE TRIGGER eet_test_fail_pin_switch BEFORE UPDATE ON eet_cash_registers BEGIN SELECT RAISE(ABORT, 'pin switch failed'); END");

    try {
        Eet::renewCertificate($old);
    } catch (Throwable) {
        // how the failure surfaces is not specified, only that nothing is stored
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS eet_test_fail_pin_switch');
    }

    $renewal = CertificateRenewal::query()->where('certificate_id', $old->getKey())->orderByDesc('id')->first();

    expect(CashRegisterCertificate::query()->count())->toBe($certificates)
        ->and(Renewal::stored())->toBeNull()
        ->and(cashRegisterCertificateId($cashRegister))->toBe($old->getKey())
        ->and($renewal?->status)->not->toBe(RenewalStatus::Acknowledging)
        ->and($renewal?->status)->not->toBe(RenewalStatus::Completed)
        ->and($renewal?->successor_id)->toBeNull()
        ->and($api->count('ack-download'))->toBe(0);
});

it('leaves a cash register pinned to another certificate of the taxpayer unchanged [R96]', function (): void {
    CaEetApi::fake();
    $old = Renewal::certificate();
    $other = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');
    $cashRegister = Renewal::cashRegister('POKLADNA-9', $other);

    Eet::renewCertificate($old);

    expect(cashRegisterCertificateId($cashRegister))->toBe($other->getKey());
});

// ---------------------------------------------------------------------------
// One active renewal, continuing (R92)
// ---------------------------------------------------------------------------

it('continues the active renewal on the next call instead of creating another one [R92, R94]', function (): void {
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 0);
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(30)]);
    $old = Renewal::certificate();

    $first = Eet::renewCertificate($old);

    expect($first?->status)->toBe(RenewalStatus::InProcess)
        ->and($first?->problem)->toBeNull()
        ->and($first?->status->isActive())->toBeTrue();

    $api->forget()->on('status', CaEetApi::issued());

    $second = Eet::renewCertificate($old);

    expect($second?->getKey())->toBe($first?->getKey())
        ->and($second?->status)->toBe(RenewalStatus::Completed)
        ->and(Renewal::renewalCount())->toBe(1)
        ->and($api->paths())->toBe([renewalPath('status'), renewalPath('claim-download'), renewalPath('ack-download')]);
});

it('does not treat an API outcome as an exception and returns the renewal in its state [R92]', function (): void {
    CaEetApi::fake(['status' => CaEetApi::rejected()]);
    $old = Renewal::certificate();

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->status)->toBe(RenewalStatus::Failed)
        ->and($renewal?->exists)->toBeTrue();
});

it('exposes the renewal through renewals(), latestRenewal() and the relations of the renewal [R92]', function (): void {
    CaEetApi::fake();
    $old = Renewal::certificate();

    $renewal = Eet::renewCertificate($old);
    $fresh = Renewal::fresh($old);

    expect($fresh->renewals()->count())->toBe(1)
        ->and($fresh->latestRenewal()?->getKey())->toBe($renewal?->getKey())
        ->and($renewal?->certificate?->getKey())->toBe($old->getKey())
        ->and($renewal?->successor?->getKey())->toBe(Renewal::stored()?->getKey());
});

// ---------------------------------------------------------------------------
// Requesting (R93)
// ---------------------------------------------------------------------------

it('asks for not finished requests before submitting a new one [R93]', function (): void {
    $api = CaEetApi::fake();

    Eet::renewCertificate(Renewal::certificate());

    expect(array_slice($api->paths(), 0, 2))->toBe([renewalPath('not-finished'), renewalPath('renew')]);
    Http::assertSent(fn (Request $request): bool => $request->url() === CaEetApi::ENDPOINT.'/request/renew'
        && $request->method() === 'POST'
        && $request->body() === '');
});

it('does not submit a new request while an unknown request is pending and keeps Requesting with UnknownPendingRequest [R93, R101]', function (): void {
    Renewal::fakeEvents();
    $api = CaEetApi::fake(['not-finished' => CaEetApi::notFinished(CaEetApi::UNKNOWN_REQ_ID)]);
    $old = Renewal::certificate();

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->status)->toBe(RenewalStatus::Requesting)
        ->and($renewal?->problem)->toBe(RenewalProblem::UnknownPendingRequest)
        ->and($renewal?->problem?->isTemporary())->toBeTrue()
        ->and((string) $renewal?->problem_detail)->toContain(CaEetApi::UNKNOWN_REQ_ID)
        ->and($renewal?->request_id)->toBeNull()
        ->and($api->calls())->toBe(['not-finished']);
    Event::assertNotDispatched(CertificateRenewalFailed::class);

    // the next run asks again and still does not submit
    $again = Eet::renewCertificate($old);

    expect($again?->getKey())->toBe($renewal?->getKey())
        ->and($again?->status)->toBe(RenewalStatus::Requesting)
        ->and($api->count('renew'))->toBe(0);
});

it('submits the request once the unknown request is no longer pending and clears the problem [R93, R101]', function (): void {
    $api = CaEetApi::fake(['not-finished' => [CaEetApi::notFinished(CaEetApi::UNKNOWN_REQ_ID), CaEetApi::notFinished()]]);
    $old = Renewal::certificate();
    Eet::renewCertificate($old);

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($renewal?->problem)->toBeNull()
        ->and($renewal?->problem_detail)->toBeNull()
        ->and($api->count('renew'))->toBe(1);
});

it('ignores a pending request that belongs to the renewal of another certificate [R93]', function (string $status): void {
    $api = CaEetApi::fake(['not-finished' => CaEetApi::notFinished(CaEetApi::OTHER_REQ_ID)]);
    $old = Renewal::certificate();
    $other = Renewal::certificate('renewal/CZ00000019-until-2026-12-05.pem');
    Renewal::insert($other, RenewalStatus::from($status), CaEetApi::OTHER_REQ_ID, $status === 'failed' ? RenewalProblem::CertificateMismatch : null);

    $renewal = Eet::renewCertificate($old);

    expect($api->count('renew'))->toBe(1)
        ->and($renewal?->request_id)->toBe(CaEetApi::REQ_ID)
        ->and($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and(Renewal::latest($other)->request_id)->toBe(CaEetApi::OTHER_REQ_ID);
})->with(['in_process', 'failed']);

it('still reports an unknown request when a request of another certificate is pending too [R93]', function (): void {
    $api = CaEetApi::fake(['not-finished' => CaEetApi::notFinished(CaEetApi::OTHER_REQ_ID, CaEetApi::UNKNOWN_REQ_ID)]);
    $old = Renewal::certificate();
    Renewal::insert(Renewal::certificate('renewal/CZ00000019-until-2026-12-05.pem'), RenewalStatus::InProcess, CaEetApi::OTHER_REQ_ID);

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->status)->toBe(RenewalStatus::Requesting)
        ->and($renewal?->problem)->toBe(RenewalProblem::UnknownPendingRequest)
        ->and((string) $renewal?->problem_detail)->toContain(CaEetApi::UNKNOWN_REQ_ID)
        ->and($api->count('renew'))->toBe(0);
});

it('takes over the request of an earlier failed renewal of the same certificate after an unreadable PKCS#12 [R93, R95, R102]', function (): void {
    $api = CaEetApi::fake(['claim-download' => CaEetApi::pkcs12(password: 'Wrong123')]);
    $old = Renewal::certificate();

    $failed = Eet::renewCertificate($old);

    expect($failed?->status)->toBe(RenewalStatus::Failed)
        ->and($failed?->problem)->toBe(RenewalProblem::CertificateUnreadable)
        ->and($api->count('ack-download'))->toBe(0);

    $api->forget()
        ->on('not-finished', CaEetApi::notFinished(CaEetApi::REQ_ID))
        ->on('status', CaEetApi::delivering())
        ->on('claim-download', CaEetApi::pkcs12());

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->getKey())->not->toBe($failed?->getKey())
        ->and($renewal?->request_id)->toBe(CaEetApi::REQ_ID)
        // the earlier failed renewal keeps its reqId (the column is not unique)
        ->and(CertificateRenewal::query()->findOrFail($failed?->getKey())->request_id)->toBe(CaEetApi::REQ_ID)
        ->and(CertificateRenewal::query()->findOrFail($failed?->getKey())->status)->toBe(RenewalStatus::Failed)
        ->and(CertificateRenewal::query()->where('request_id', CaEetApi::REQ_ID)->count())->toBe(2)
        ->and($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($renewal?->successor_id)->toBe(Renewal::stored()?->getKey())
        ->and($api->count('renew'))->toBe(0)
        ->and($api->paths())->toBe([
            renewalPath('not-finished'),
            renewalPath('status'),
            renewalPath('claim-download'),
            renewalPath('ack-download'),
        ]);
});

// ---------------------------------------------------------------------------
// InProcess (R94)
// ---------------------------------------------------------------------------

it('polls after pollAfterSeconds until max_wait_seconds would be exceeded and stays InProcess without a problem [R94, R92]', function (): void {
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 12);
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(5)]);
    $old = Renewal::certificate();

    $renewal = Eet::renewCertificate($old);

    expect($api->count('status'))->toBe(3)
        ->and($renewal?->status)->toBe(RenewalStatus::InProcess)
        ->and($renewal?->problem)->toBeNull()
        ->and($renewal?->request_id)->toBe(CaEetApi::REQ_ID)
        ->and(now()->equalTo(Renewal::now()->addSeconds(10)))->toBeTrue();
    Sleep::assertSleptTimes(2);
    Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(5)->seconds()]);

    $api->forget()->on('status', CaEetApi::issued());
    $next = Eet::renewCertificate($old);

    expect($next?->getKey())->toBe($renewal?->getKey())
        ->and($next?->status)->toBe(RenewalStatus::Completed)
        ->and($api->count('renew'))->toBe(0)
        ->and($api->paths()[0])->toBe(renewalPath('status'));
});

it('waits pollAfterSeconds from the body and ignores a different Retry-After header [R94]', function (): void {
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 12);
    $api = CaEetApi::fake(['status' => [CaEetApi::inProcess(5, retryAfter: 1), CaEetApi::inProcess(5, retryAfter: 100), CaEetApi::issued()]]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($api->count('status'))->toBe(3);
    Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(5)->seconds()]);
});

it('waits when the wait ends exactly at max_wait_seconds [R94]', function (): void {
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 12);
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(6)]);

    Eet::renewCertificate(Renewal::certificate());

    expect($api->count('status'))->toBe(3);
    Sleep::assertSleptTimes(2);
});

it('does not wait when pollAfterSeconds exceeds max_wait_seconds [R94]', function (): void {
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(200)]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect(config('laravel-eet-module.renewal.max_wait_seconds'))->toBe(120)
        ->and($api->count('status'))->toBe(1)
        ->and($renewal?->status)->toBe(RenewalStatus::InProcess)
        ->and($renewal?->problem)->toBeNull();
    Sleep::assertNeverSlept();
});

it('asks for the status exactly once with max_wait_seconds = 0 [R94]', function (): void {
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 0);
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(1)]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($api->count('status'))->toBe(1)
        ->and($renewal?->status)->toBe(RenewalStatus::InProcess);
    Sleep::assertNeverSlept();
});

it('continues to the download when the request is issued or delivering [R94, R95]', function (string $state): void {
    $api = CaEetApi::fake(['status' => CaEetApi::state($state)]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($api->calls())->toBe(['not-finished', 'renew', 'status', 'claim-download', 'ack-download']);
})->with(['ISSUED', 'DELIVERING']);

it('fails with Rejected and the rejectedReason when the request is rejected [R94, R101, R102]', function (): void {
    Renewal::fakeEvents();
    $api = CaEetApi::fake(['status' => CaEetApi::rejected('Selhani kontroly parametru zadosti')]);
    $old = Renewal::certificate();

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->status)->toBe(RenewalStatus::Failed)
        ->and($renewal?->problem)->toBe(RenewalProblem::Rejected)
        ->and($renewal?->problem?->isTemporary())->toBeFalse()
        ->and((string) $renewal?->problem_detail)->toContain('Selhani kontroly parametru zadosti')
        ->and($renewal?->failed_at)->not->toBeNull()
        ->and($renewal?->completed_at)->toBeNull()
        ->and($api->count('claim-download'))->toBe(0);
    Event::assertDispatchedTimes(CertificateRenewalFailed::class, 1);
    Event::assertDispatched(CertificateRenewalFailed::class, fn (CertificateRenewalFailed $event): bool => $event->problem === RenewalProblem::Rejected
        && $event->certificate->getKey() === $old->getKey()
        && $event->renewal->getKey() === $renewal?->getKey());
});

it('fails with AlreadyFinished when the request is finished [R94, R101]', function (): void {
    Renewal::fakeEvents();
    $api = CaEetApi::fake(['status' => CaEetApi::finished()]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status)->toBe(RenewalStatus::Failed)
        ->and($renewal?->problem)->toBe(RenewalProblem::AlreadyFinished)
        ->and($api->count('claim-download'))->toBe(0)
        ->and($api->count('ack-download'))->toBe(0);
    Event::assertDispatched(CertificateRenewalFailed::class, fn (CertificateRenewalFailed $event): bool => $event->problem === RenewalProblem::AlreadyFinished);
});

it('downloads again after a crash between claim-download and storing (request DELIVERING) [R94, R95]', function (): void {
    $api = CaEetApi::fake(['status' => CaEetApi::delivering()]);
    $old = Renewal::certificate();
    $left = Renewal::insert($old, RenewalStatus::InProcess);

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->getKey())->toBe($left->getKey())
        ->and($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($api->paths())->toBe([renewalPath('status'), renewalPath('claim-download'), renewalPath('ack-download')]);
});

it('downloads in a renewal left in Downloading [R95]', function (): void {
    $api = CaEetApi::fake(['status' => CaEetApi::delivering()]);
    $old = Renewal::certificate();
    Renewal::insert($old, RenewalStatus::Downloading);

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($api->count('renew'))->toBe(0)
        ->and($api->count('not-finished'))->toBe(0)
        ->and($api->count('claim-download'))->toBe(1)
        ->and($api->calls()[array_key_last($api->calls())])->toBe('ack-download');
});

// ---------------------------------------------------------------------------
// Downloading: checks of the successor (R95)
// ---------------------------------------------------------------------------

it('fails with CertificateMismatch, stores nothing and does not acknowledge when the successor does not match [R95]', function (Closure $claim, string $fixture): void {
    Renewal::fakeEvents();
    $api = CaEetApi::fake(['claim-download' => $claim()]);
    $old = Renewal::certificate();
    $cashRegister = Renewal::cashRegister('POKLADNA-1', $old);
    $certificates = CashRegisterCertificate::query()->count();

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->status)->toBe(RenewalStatus::Failed)
        ->and($renewal?->problem)->toBe(RenewalProblem::CertificateMismatch)
        ->and($renewal?->successor_id)->toBeNull()
        ->and(CashRegisterCertificate::query()->count())->toBe($certificates)
        ->and(Renewal::stored($fixture))->toBeNull()
        ->and(cashRegisterCertificateId($cashRegister))->toBe($old->getKey())
        ->and(Renewal::fresh($old)->successor())->toBeNull()
        ->and($api->count('ack-download'))->toBe(0);
    Event::assertDispatched(CertificateRenewalFailed::class, fn (CertificateRenewalFailed $event): bool => $event->problem === RenewalProblem::CertificateMismatch);
    Event::assertNotDispatched(CertificateRenewed::class);
})->with([
    'different CN' => [fn (): mixed => CaEetApi::pkcs12('renewal/successor-other-cn.p12', certificate: CaEetApi::metadataOf('renewal/successor-other-cn.p12')), 'renewal/successor-other-cn.p12'],
    'validTo not later than the renewed one' => [fn (): mixed => CaEetApi::pkcs12('renewal/successor-same-valid-to.p12', certificate: CaEetApi::metadataOf('renewal/successor-same-valid-to.p12')), 'renewal/successor-same-valid-to.p12'],
    'serial number differs from the metadata' => [fn (): mixed => CaEetApi::pkcs12(certificate: ['sn' => '24961511666821687693']), CaEetApi::SUCCESSOR],
    'validFrom differs from the metadata' => [fn (): mixed => CaEetApi::pkcs12(certificate: ['validFrom' => '2026-11-18 00:00:01+00:00']), CaEetApi::SUCCESSOR],
    'successor without a policy' => [fn (): mixed => CaEetApi::pkcs12('renewal/successor-no-policy.p12', certificate: CaEetApi::metadataOf('renewal/successor-no-policy.p12')), 'renewal/successor-no-policy.p12'],
    'successor with the playground policy' => [fn (): mixed => CaEetApi::pkcs12('renewal/successor-playground-policy.p12', certificate: CaEetApi::metadataOf('renewal/successor-playground-policy.p12')), 'renewal/successor-playground-policy.p12'],
    'another issuer DN' => [fn (): mixed => CaEetApi::pkcs12('renewal/successor-other-issuer.p12', certificate: CaEetApi::metadataOf('renewal/successor-other-issuer.p12')), 'renewal/successor-other-issuer.p12'],
    'self-signed' => [fn (): mixed => CaEetApi::pkcs12('renewal/successor-self-signed.p12', certificate: CaEetApi::metadataOf('renewal/successor-self-signed.p12')), 'renewal/successor-self-signed.p12'],
    'same issuer DN, other authority key identifier' => [fn (): mixed => CaEetApi::pkcs12('renewal/successor-rogue-ca.p12', certificate: CaEetApi::metadataOf('renewal/successor-rogue-ca.p12')), 'renewal/successor-rogue-ca.p12'],
    'same issuer DN and AKI, signature of another key' => [fn (): mixed => CaEetApi::pkcs12('renewal/successor-rogue-same-aki.p12', certificate: CaEetApi::metadataOf('renewal/successor-rogue-same-aki.p12')), 'renewal/successor-rogue-same-aki.p12'],
    'validTo differs from the metadata' => [fn (): mixed => CaEetApi::pkcs12(certificate: ['validTo' => '2027-11-18 00:00:00+00:00']), CaEetApi::SUCCESSOR],
]);

it('accepts metadata with the same moments written with another offset [R95]', function (): void {
    CaEetApi::fake(['claim-download' => CaEetApi::pkcs12(certificate: [
        'validFrom' => '2026-11-18T01:00:00+01:00',
        'validTo' => '2027-11-19T01:00:00+01:00',
    ])]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status)->toBe(RenewalStatus::Completed);
});

it('fails with CertificateMismatch when the taxpayer already has a certificate with the same fingerprint [R95, R13]', function (): void {
    $api = CaEetApi::fake();
    $old = Renewal::certificate();
    $manual = Eet::importCertificate(Renewal::taxpayer(), Fixtures::read(CaEetApi::SUCCESSOR), CaEetApi::PASSWORD);

    expect(Renewal::fresh($old)->successor())->toBeNull();

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->status)->toBe(RenewalStatus::Failed)
        ->and($renewal?->problem)->toBe(RenewalProblem::CertificateMismatch)
        ->and($renewal?->successor_id)->toBeNull()
        ->and(CashRegisterCertificate::query()->where('id', '!=', $manual->getKey())->where('id', '!=', $old->getKey())->count())->toBe(0)
        ->and($api->count('ack-download'))->toBe(0);
});

it('fails with CertificateUnreadable and the CertificateProblem value when the PKCS#12 cannot be imported [R95]', function (Closure $claim, string $detail): void {
    Renewal::fakeEvents();
    $api = CaEetApi::fake(['claim-download' => $claim()]);
    $old = Renewal::certificate();
    $certificates = CashRegisterCertificate::query()->count();

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->status)->toBe(RenewalStatus::Failed)
        ->and($renewal?->problem)->toBe(RenewalProblem::CertificateUnreadable)
        ->and(CashRegisterCertificate::query()->count())->toBe($certificates)
        ->and($api->count('ack-download'))->toBe(0);

    expect($renewal?->problem_detail)->toBe($detail)
        ->and(CertificateProblem::tryFrom($detail))->not->toBeNull();

    Event::assertDispatched(CertificateRenewalFailed::class, fn (CertificateRenewalFailed $event): bool => $event->problem === RenewalProblem::CertificateUnreadable);
})->with([
    'wrong password in the response' => [fn (): mixed => CaEetApi::pkcs12(password: 'Wrong123'), 'unreadable'],
    'damaged PKCS#12' => [fn (): mixed => CaEetApi::pkcs12(pkcs12: base64_encode('this is not a PKCS#12 file')), 'unreadable'],
    'PKCS#12 without a private key' => [fn (): mixed => CaEetApi::pkcs12('renewal/successor-without-key.p12'), 'missing_private_key'],
]);

it('renews from a legacy PKCS#12 exactly when the runtime supports it, otherwise fails without acknowledging [R95, R70]', function (): void {
    $api = CaEetApi::fake(['claim-download' => CaEetApi::pkcs12('renewal/successor.legacy.p12')]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    if (Eet::supportsLegacyPkcs12()) {
        expect($renewal?->status)->toBe(RenewalStatus::Completed)
            ->and($api->count('ack-download'))->toBe(1);
    } else {
        expect($renewal?->status)->toBe(RenewalStatus::Failed)
            ->and($renewal?->problem)->toBe(RenewalProblem::CertificateUnreadable)
            ->and($renewal?->problem_detail)->toBe('unsupported_legacy_format')
            ->and(Renewal::stored())->toBeNull()
            ->and($api->count('ack-download'))->toBe(0);
    }
});

it('stores the successor for the taxpayer of the renewed certificate [R95]', function (): void {
    CaEetApi::fake(['claim-download' => CaEetApi::pkcs12('renewal/successor-CZ683555118.p12', certificate: CaEetApi::metadataOf('renewal/successor-CZ683555118.p12'))]);
    Renewal::taxpayer('CZ00000019');
    $old = Renewal::certificate('renewal/CZ683555118-until-2026-12-10.pem', 'CZ683555118');

    $renewal = Eet::renewCertificate($old);
    $successor = Renewal::stored('renewal/successor-CZ683555118.p12');

    expect($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($successor?->taxpayer_id)->toBe($old->taxpayer_id)
        ->and($successor?->taxpayer->eic)->toBe('CZ683555118');
});

// ---------------------------------------------------------------------------
// Acknowledging (R97)
// ---------------------------------------------------------------------------

it('completes when acknowledge-download answers 404 or 409, because the CA already finished the request [R97]', function (int $status): void {
    CaEetApi::fake(['ack-download' => CaEetApi::error($status, $status === 404 ? 4 : 5, 'Conflict.')]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($renewal?->problem)->toBeNull()
        ->and($renewal?->completed_at)->not->toBeNull()
        // the acknowledgement did not take place
        ->and($renewal?->acknowledged_at)->toBeNull()
        ->and(Renewal::stored())->not->toBeNull();
})->with([404, 409]);

it('completes with CertificateUnusable without acknowledging when the key of the renewed certificate cannot be decrypted in Acknowledging [R97, R69]', function (): void {
    Renewal::fakeEvents();
    $api = CaEetApi::fake(['ack-download' => CaEetApi::error(500)]);
    $old = Renewal::certificate();
    $cashRegister = Renewal::cashRegister('POKLADNA-1', $old);
    $first = Eet::renewCertificate($old);
    $successor = Renewal::stored();
    $api->forget();
    Renewal::makeUnusable($old);

    $renewal = Eet::renewCertificate(Renewal::fresh($old));

    expect($first?->status)->toBe(RenewalStatus::Acknowledging)
        ->and($renewal?->getKey())->toBe($first?->getKey())
        ->and($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($renewal?->acknowledged_at)->toBeNull()
        ->and($renewal?->completed_at)->not->toBeNull()
        ->and($renewal?->failed_at)->toBeNull()
        ->and($renewal?->problem)->toBe(RenewalProblem::CertificateUnusable)
        ->and($renewal?->successor_id)->toBe($successor?->getKey())
        ->and(Renewal::fresh($old)->successor()?->getKey())->toBe($successor?->getKey())
        ->and(cashRegisterCertificateId($cashRegister))->toBe($successor?->getKey())
        ->and($api->count())->toBe(0);
    Event::assertNotDispatched(CertificateRenewalFailed::class);
});

it('stays Acknowledging after a temporary acknowledge failure and the next call sends only the acknowledgement [R97, R101]', function (): void {
    $api = CaEetApi::fake(['ack-download' => [CaEetApi::error(500), CaEetApi::acknowledged()]]);
    $old = Renewal::certificate();
    $cashRegister = Renewal::cashRegister('POKLADNA-1', $old);

    $renewal = Eet::renewCertificate($old);
    $successor = Renewal::stored();

    expect($renewal?->status)->toBe(RenewalStatus::Acknowledging)
        ->and($renewal?->problem)->toBe(RenewalProblem::TemporaryError)
        ->and($renewal?->successor_id)->toBe($successor?->getKey())
        ->and($successor)->not->toBeNull()
        ->and(cashRegisterCertificateId($cashRegister))->toBe($successor?->getKey())
        ->and($renewal?->acknowledged_at)->toBeNull();

    $api->forget();
    $this->travel(1)->hours();
    $next = Eet::renewCertificate($old);

    expect($next?->getKey())->toBe($renewal?->getKey())
        ->and($next?->status)->toBe(RenewalStatus::Completed)
        ->and($next?->problem)->toBeNull()
        ->and($next?->problem_detail)->toBeNull()
        ->and($next?->acknowledged_at?->equalTo(Renewal::now()->addHour()))->toBeTrue()
        ->and(CashRegisterCertificate::query()->count())->toBe(2)
        ->and($api->paths())->toBe([renewalPath('ack-download')]);
});

it('retries the acknowledgement for a certificate in Acknowledging instead of throwing AlreadyRenewed [R90, R97]', function (): void {
    $api = CaEetApi::fake();
    $old = Renewal::certificate();
    $successor = Eet::importCertificate(Renewal::taxpayer(), Fixtures::read(CaEetApi::SUCCESSOR), CaEetApi::PASSWORD);
    $left = Renewal::insert($old, RenewalStatus::Acknowledging, CaEetApi::REQ_ID, null, null, $successor);

    expect(Renewal::fresh($old)->successor()?->getKey())->toBe($successor->getKey());

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->getKey())->toBe($left->getKey())
        ->and($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($renewal?->acknowledged_at)->not->toBeNull()
        ->and($api->paths())->toBe([renewalPath('ack-download')]);
});

it('completes without acknowledging when the renewed certificate expired before the acknowledgement [R97]', function (): void {
    Renewal::fakeEvents();
    $api = CaEetApi::fake(['ack-download' => CaEetApi::error(500)]);
    $old = Renewal::certificate();
    Eet::renewCertificate($old);
    $api->forget();
    $this->travelTo(CarbonImmutable::parse('2026-12-10 00:00:01', 'UTC'));

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($renewal?->acknowledged_at)->toBeNull()
        ->and($renewal?->completed_at)->not->toBeNull()
        // the earlier temporary problem of the acknowledgement does not stay behind
        ->and($renewal?->problem)->toBeNull()
        ->and($renewal?->problem_detail)->toBeNull()
        ->and($api->count())->toBe(0);
    Event::assertNotDispatched(CertificateRenewalFailed::class);
});

it('still acknowledges at the last second of the renewed certificate [R97]', function (): void {
    $api = CaEetApi::fake(['ack-download' => [CaEetApi::error(503), CaEetApi::acknowledged()]]);
    $old = Renewal::certificate();
    Eet::renewCertificate($old);
    $api->forget();
    $this->travelTo(CarbonImmutable::parse('2026-12-10 00:00:00', 'UTC'));

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($renewal?->acknowledged_at)->not->toBeNull()
        ->and($api->calls())->toBe(['ack-download']);
});

// ---------------------------------------------------------------------------
// Renewed certificate no longer valid or usable while continuing (R98)
// ---------------------------------------------------------------------------

it('fails with CertificateNotValid without calling the API when the renewed certificate expired while InProcess [R98]', function (): void {
    Renewal::fakeEvents();
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 0);
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(30)]);
    $old = Renewal::certificate();
    $first = Eet::renewCertificate($old);
    $api->forget();
    $this->travelTo(CarbonImmutable::parse('2026-12-10 00:00:01', 'UTC'));

    $renewal = Eet::renewCertificate($old);

    expect($first?->status)->toBe(RenewalStatus::InProcess)
        ->and($renewal?->getKey())->toBe($first?->getKey())
        ->and($renewal?->status)->toBe(RenewalStatus::Failed)
        ->and($renewal?->problem)->toBe(RenewalProblem::CertificateNotValid)
        ->and($renewal?->failed_at)->not->toBeNull()
        ->and($api->count())->toBe(0);
    Event::assertDispatched(CertificateRenewalFailed::class, fn (CertificateRenewalFailed $event): bool => $event->problem === RenewalProblem::CertificateNotValid);
});

it('continues while the renewed certificate is still valid at its last second [R98]', function (): void {
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 0);
    $api = CaEetApi::fake(['status' => [CaEetApi::inProcess(30), CaEetApi::issued()]]);
    $old = Renewal::certificate();
    Eet::renewCertificate($old);
    $this->travelTo(CarbonImmutable::parse('2026-12-10 00:00:00', 'UTC'));

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($api->count('status'))->toBe(2);
});

it('fails with CertificateNotValid or CertificateUnusable without calling the API in Requesting, InProcess and Downloading [R98]', function (string $status, string $cause): void {
    $api = CaEetApi::fake();
    $old = Renewal::certificate();
    Renewal::insert($old, RenewalStatus::from($status), $status === 'requesting' ? null : CaEetApi::REQ_ID, $status === 'requesting' ? RenewalProblem::TemporaryError : null);

    if ($cause === 'expired') {
        $this->travelTo(CarbonImmutable::parse('2026-12-11 00:00:00', 'UTC'));
    } else {
        Renewal::makeUnusable($old);
    }

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->status)->toBe(RenewalStatus::Failed)
        ->and($renewal?->problem)->toBe($cause === 'expired' ? RenewalProblem::CertificateNotValid : RenewalProblem::CertificateUnusable)
        ->and($api->count())->toBe(0);
})->with(['requesting', 'in_process', 'downloading'])->with(['expired', 'unusable']);

// ---------------------------------------------------------------------------
// Issuer of the successor (R95, Q71)
// ---------------------------------------------------------------------------

it('accepts a successor issued by the issuer of the renewed certificate with the same DN and AKI [R95]', function (): void {
    $old = Renewal::certificate();
    $successor = openssl_x509_parse(CaEetApi::certificateOf(CaEetApi::SUCCESSOR));
    $renewed = openssl_x509_parse(Fixtures::certificate(CaEetApi::RENEWED));
    CaEetApi::fake();

    expect(is_array($successor) && is_array($renewed))->toBeTrue()
        ->and($successor['issuer'] ?? null)->toBe($renewed['issuer'] ?? false)
        ->and($successor['extensions']['authorityKeyIdentifier'] ?? null)->toBe($renewed['extensions']['authorityKeyIdentifier'] ?? false)
        ->and(substr_count((string) $old->chain, '-----BEGIN CERTIFICATE-----'))->toBeGreaterThanOrEqual(1)
        ->and(Eet::renewCertificate($old)?->status)->toBe(RenewalStatus::Completed);
});

it('fails closed with CertificateMismatch when the stored chain lacks a verifying issuer of the renewed certificate [R95]', function (string $renewedFixture, string $successorFixture): void {
    Renewal::fakeEvents();
    $old = Renewal::certificate($renewedFixture);
    $cashRegister = Renewal::cashRegister('POKLADNA-1', $old);
    $certificates = CashRegisterCertificate::query()->count();
    $api = CaEetApi::fake(['claim-download' => CaEetApi::pkcs12($successorFixture, certificate: CaEetApi::metadataOf($successorFixture))]);

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->status)->toBe(RenewalStatus::Failed)
        ->and($renewal?->problem)->toBe(RenewalProblem::CertificateMismatch)
        ->and($renewal?->successor_id)->toBeNull()
        ->and(CashRegisterCertificate::query()->count())->toBe($certificates)
        ->and(Renewal::stored($successorFixture))->toBeNull()
        ->and(cashRegisterCertificateId($cashRegister))->toBe($old->getKey())
        ->and($api->count('ack-download'))->toBe(0);
    Event::assertNotDispatched(CertificateRenewed::class);
})->with([
    'no chain, real successor' => ['renewal/CZ00000019-until-2026-12-10.without-chain.pem', CaEetApi::SUCCESSOR],
    'no chain, rogue successor with the same DN and AKI' => ['renewal/CZ00000019-until-2026-12-10.without-chain.pem', 'renewal/successor-rogue-same-aki.p12'],
    'chain with a same-DN CA that does not verify the renewed certificate, real successor' => ['renewal/CZ00000019-until-2026-12-10.rogue-chain.pem', CaEetApi::SUCCESSOR],
    'chain with a same-DN CA that does not verify the renewed certificate, successor it signed' => ['renewal/CZ00000019-until-2026-12-10.rogue-chain.pem', 'renewal/successor-rogue-same-aki.p12'],
    'issuer without SKI in the chain, rogue successor' => ['renewal/CZ00000019-until-2026-12-10.chain-without-ski.pem', 'renewal/successor-rogue-same-aki.p12'],
]);

it('finds the issuer in the chain by DN and signature even without a subject key identifier [R95]', function (): void {
    $old = Renewal::certificate('renewal/CZ00000019-until-2026-12-10.chain-without-ski.pem');
    CaEetApi::fake();

    expect(Eet::renewCertificate($old)?->status)->toBe(RenewalStatus::Completed)
        ->and(Renewal::stored())->not->toBeNull();
});

// ---------------------------------------------------------------------------
// not-finished priorities (R93, Q73)
// ---------------------------------------------------------------------------

it('ignores a pending reqId that belongs to a renewal of the same certificate that did not fail [R93]', function (): void {
    $api = CaEetApi::fake(['not-finished' => CaEetApi::notFinished(CaEetApi::OTHER_REQ_ID)]);
    $old = Renewal::certificate();
    $completed = Renewal::insert($old, RenewalStatus::Completed, CaEetApi::OTHER_REQ_ID);

    expect(Renewal::fresh($old)->successor())->toBeNull();

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->getKey())->not->toBe($completed->getKey())
        ->and($renewal?->request_id)->toBe(CaEetApi::REQ_ID)
        ->and($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($api->count('renew'))->toBe(1);
});

it('takes over the reqId of a failed renewal of the same certificate even when an unknown reqId is pending too [R93]', function (): void {
    $api = CaEetApi::fake([
        'not-finished' => CaEetApi::notFinished(CaEetApi::UNKNOWN_REQ_ID, CaEetApi::OTHER_REQ_ID),
        'status' => CaEetApi::delivering(),
    ]);
    $old = Renewal::certificate();
    Renewal::insert($old, RenewalStatus::Failed, CaEetApi::OTHER_REQ_ID, RenewalProblem::CertificateUnreadable, 'unsupported_legacy_format');

    $renewal = Eet::renewCertificate($old);

    expect($renewal?->request_id)->toBe(CaEetApi::OTHER_REQ_ID)
        ->and($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($renewal?->problem)->not->toBe(RenewalProblem::UnknownPendingRequest)
        ->and($api->count('renew'))->toBe(0);
});

// ---------------------------------------------------------------------------
// Conditional state transitions (R104, Q72)
// ---------------------------------------------------------------------------

it('discards a step whose start state was changed concurrently [R104]', function (): void {
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 0);
    $api = CaEetApi::fake(['status' => CaEetApi::rejected()]);
    $old = Renewal::certificate();
    $successor = Eet::importCertificate(Renewal::taxpayer(), Fixtures::read(CaEetApi::SUCCESSOR), CaEetApi::PASSWORD);
    $left = Renewal::insert($old, RenewalStatus::InProcess);
    $api->observe(function (string $route, Request $request) use ($left, $successor): void {
        if ($route === 'status') {
            // another process got further in the meantime
            DB::table('eet_certificate_renewals')->where('id', $left->getKey())->update([
                'status' => RenewalStatus::Acknowledging->value,
                'successor_id' => $successor->getKey(),
            ]);
        }
    });

    try {
        Eet::renewCertificate($old);
    } catch (Throwable) {
        // how the discarded step surfaces is not specified
    }

    $stored = CertificateRenewal::query()->findOrFail($left->getKey());

    expect($stored->status)->toBe(RenewalStatus::Acknowledging)
        ->and($stored->failed_at)->toBeNull()
        ->and($stored->successor_id)->toBe($successor->getKey());
});

it('discards the download step when the renewal state changed concurrently and stores nothing [R104, R96]', function (): void {
    Renewal::fakeEvents();
    $api = CaEetApi::fake();
    $old = Renewal::certificate();
    $cashRegister = Renewal::cashRegister('POKLADNA-1', $old);
    $certificates = CashRegisterCertificate::query()->count();
    $api->observe(function (string $route, Request $request) use ($old): void {
        if ($route === 'claim-download') {
            DB::table('eet_certificate_renewals')->where('certificate_id', $old->getKey())->update([
                'status' => RenewalStatus::Failed->value,
                'problem' => RenewalProblem::Rejected->value,
                'failed_at' => now(),
            ]);
        }
    });

    try {
        Eet::renewCertificate($old);
    } catch (Throwable) {
        // how the discarded step surfaces is not specified
    }

    $stored = Renewal::latest($old);

    expect($stored->status)->toBe(RenewalStatus::Failed)
        ->and($stored->problem)->toBe(RenewalProblem::Rejected)
        ->and($stored->successor_id)->toBeNull()
        ->and(Renewal::stored())->toBeNull()
        ->and(CashRegisterCertificate::query()->count())->toBe($certificates)
        ->and(cashRegisterCertificateId($cashRegister))->toBe($old->getKey())
        ->and($api->count('ack-download'))->toBe(0);
    Event::assertNotDispatched(CertificateRenewed::class);
});

it('fails with CertificateMismatch when the same certificate is imported concurrently during the download [R104, R95]', function (): void {
    $api = CaEetApi::fake();
    $old = Renewal::certificate();
    $cashRegister = Renewal::cashRegister('POKLADNA-1', $old);
    $api->observe(function (string $route, Request $request): void {
        if ($route === 'claim-download') {
            Eet::importCertificate(Renewal::taxpayer(), Fixtures::read(CaEetApi::SUCCESSOR), CaEetApi::PASSWORD);
        }
    });

    $renewal = Eet::renewCertificate($old);
    $stored = Renewal::latest($old);

    expect(CashRegisterCertificate::query()->whereRaw('lower(fingerprint_sha256) = ?', [CaEetApi::fingerprintOf(CaEetApi::SUCCESSOR)])->count())->toBe(1)
        ->and($renewal?->getKey())->toBe($stored->getKey())
        ->and($stored->status)->toBe(RenewalStatus::Failed)
        ->and($stored->problem)->toBe(RenewalProblem::CertificateMismatch)
        ->and($stored->successor_id)->toBeNull()
        ->and(cashRegisterCertificateId($cashRegister))->toBe($old->getKey())
        ->and($api->count('ack-download'))->toBe(0);
});

it('asks for the status once and returns for a huge pollAfterSeconds [R94]', function (int $pollAfterSeconds): void {
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess($pollAfterSeconds)]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($api->count('status'))->toBe(1)
        ->and($renewal?->status)->toBe(RenewalStatus::InProcess)
        ->and($renewal?->problem)->toBeNull();
    Sleep::assertNeverSlept();
})->with([
    '10^12' => 1_000_000_000_000,
    'PHP_INT_MAX' => PHP_INT_MAX,
]);

// ---------------------------------------------------------------------------
// Unique fingerprint violation while storing the successor (R104)
// ---------------------------------------------------------------------------

/**
 * Simulates a second process that stores the same successor between the
 * fingerprint check and the insert of the step: the row with the same
 * fingerprint makes the insert of the step fail on the unique index. As the
 * step's transaction is rolled back, the "second process" writes are applied
 * again after the rollback (they belong to another, committed transaction),
 * optionally together with its move of the renewal to Acknowledging.
 *
 * @return Closure(): bool whether the race was reached
 */
function raceSuccessorInsert(CashRegisterCertificate $renewed, bool $moveToAcknowledging): Closure
{
    $state = ['row' => null, 'reached' => false, 'applied' => false];
    $concurrentWrites = function () use (&$state, $renewed, $moveToAcknowledging): void {
        $id = DB::table('eet_cash_register_certificates')->insertGetId($state['row']);

        if ($moveToAcknowledging) {
            DB::table('eet_certificate_renewals')
                ->where('id', Renewal::latest($renewed)->getKey())
                ->update(['status' => RenewalStatus::Acknowledging->value, 'successor_id' => $id]);
        }
    };

    CashRegisterCertificate::creating(function (CashRegisterCertificate $model) use (&$state, $concurrentWrites): void {
        if ($state['reached']) {
            return;
        }

        $now = CarbonImmutable::now();
        $state['reached'] = true;
        $state['row'] = array_merge($model->getAttributes(), ['created_at' => $now, 'updated_at' => $now]);
        $concurrentWrites();
    });
    Event::listen(TransactionRolledBack::class, function () use (&$state, $concurrentWrites): void {
        if ($state['reached'] && ! $state['applied']) {
            $state['applied'] = true;
            $concurrentWrites();
        }
    });

    return function () use (&$state): bool {
        return $state['reached'];
    };
}

it('discards the step when the unique fingerprint is violated and a concurrent run moved the renewal to Acknowledging [R104]', function (): void {
    $failed = [];
    Event::listen(CertificateRenewalFailed::class, function (CertificateRenewalFailed $event) use (&$failed): void {
        $failed[] = $event->problem;
    });
    $api = CaEetApi::fake(['status' => CaEetApi::issued()]);
    $old = Renewal::certificate();
    $reached = raceSuccessorInsert($old, true);
    $thrown = null;

    try {
        Eet::renewCertificate($old);
    } catch (Throwable $exception) {
        $thrown = $exception;
    } finally {
        CashRegisterCertificate::flushEventListeners();
    }

    $stored = Renewal::latest($old);

    expect($reached())->toBeTrue()
        ->and($thrown)->toBeNull()
        ->and($stored->status)->toBe(RenewalStatus::Acknowledging)
        ->and($stored->failed_at)->toBeNull()
        ->and($stored->successor_id)->toBe(Renewal::stored()?->getKey())
        ->and(CashRegisterCertificate::query()->whereRaw('lower(fingerprint_sha256) = ?', [CaEetApi::fingerprintOf(CaEetApi::SUCCESSOR)])->count())->toBe(1)
        ->and($failed)->toBe([])
        ->and($api->count('ack-download'))->toBe(0);
});

it('fails with CertificateMismatch when the unique fingerprint is violated and the renewal is still Downloading [R104, R95]', function (): void {
    Renewal::fakeEvents();
    $api = CaEetApi::fake();
    $old = Renewal::certificate();
    $cashRegister = Renewal::cashRegister('POKLADNA-1', $old);
    $reached = raceSuccessorInsert($old, false);
    $thrown = null;

    try {
        $renewal = Eet::renewCertificate($old);
    } catch (Throwable $exception) {
        $thrown = $exception;
    } finally {
        CashRegisterCertificate::flushEventListeners();
    }

    $stored = Renewal::latest($old);

    expect($reached())->toBeTrue()
        ->and($thrown)->toBeNull()
        ->and($stored->status)->toBe(RenewalStatus::Failed)
        ->and($stored->problem)->toBe(RenewalProblem::CertificateMismatch)
        ->and($stored->successor_id)->toBeNull()
        ->and(cashRegisterCertificateId($cashRegister))->toBe($old->getKey())
        ->and($api->count('ack-download'))->toBe(0);
    Event::assertNotDispatched(CertificateRenewed::class);
    Event::assertDispatched(CertificateRenewalFailed::class, fn (CertificateRenewalFailed $event): bool => $event->problem === RenewalProblem::CertificateMismatch);
});
