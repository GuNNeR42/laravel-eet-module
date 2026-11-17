<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Enums\RenewalProblem;
use Gunner42\LaravelEetModule\Enums\RenewalStatus;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\CertificateRenewal;
use Gunner42\LaravelEetModule\Tests\Support\CaEetApi;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Gunner42\LaravelEetModule\Tests\Support\PackageFailure;
use Gunner42\LaravelEetModule\Tests\Support\Renewal;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * R105: php artisan eet:certificate:renew {--taxpayer=*} {--certificate=*}
 * {--dry-run}. Time is frozen at 2026-11-19 00:00 UTC, so with the default
 * renewal.days_before_expiry = 21 the certificate valid until 2026-12-10
 * 00:00 UTC is exactly 21 days before expiry (in the window) and the one
 * valid until 2026-12-11 00:00 UTC is 22 days before expiry (outside).
 * Which certificate a request belongs to is read from x5t#S256 of its JWT.
 */

beforeEach(function (): void {
    $this->travelTo(Renewal::now());
    Sleep::fake(syncWithCarbon: true);
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 0);
});

/**
 * @param  array<string, mixed>  $parameters
 * @return array{code: int, output: string}
 */
function runRenewCommand(array $parameters = []): array
{
    $code = Artisan::call('eet:certificate:renew', $parameters);

    return ['code' => $code, 'output' => Artisan::output()];
}

function x5tOfRequest(Request $request): ?string
{
    $token = CaEetApi::bearerToken($request);

    if ($token === null) {
        return null;
    }

    $header = json_decode(CaEetApi::base64UrlDecode(explode('.', $token)[0]), true);

    return is_array($header) && is_string($header['x5t#S256'] ?? null) ? $header['x5t#S256'] : null;
}

/**
 * Fixtures of the renewed certificates the API was called for.
 *
 * @param  list<string>  $fixtures  candidates
 * @return list<string>
 */
function calledFor(CaEetApi $api, array $fixtures): array
{
    $byX5t = [];

    foreach ($fixtures as $fixture) {
        $byX5t[CaEetApi::x5tS256(Fixtures::certificate($fixture))] = $fixture;
    }

    $called = [];

    foreach ($api->log() as $entry) {
        $x5t = x5tOfRequest($entry['request']);

        if ($x5t !== null && isset($byX5t[$x5t])) {
            $called[$byX5t[$x5t]] = true;
        }
    }

    $result = array_keys($called);
    sort($result);

    return $result;
}

/**
 * @return array{inWindow: CashRegisterCertificate, outside: CashRegisterCertificate, failed: CashRegisterCertificate, active: CashRegisterCertificate, playground: CashRegisterCertificate}
 */
function commandCertificates(): array
{
    $inWindow = Renewal::certificate('renewal/CZ00000019-until-2026-12-10.pem');
    $outside = Renewal::certificate('renewal/CZ00000019-until-2026-12-11.pem');
    $failed = Renewal::certificate('renewal/CZ00000019-until-2026-12-05.pem');
    $active = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');
    $playground = importFixture(Renewal::taxpayer(), 'playground/CZ00000019.pem');

    Renewal::insert($failed, RenewalStatus::Failed, '11111111-2222-4333-8444-555555555555', RenewalProblem::Rejected, 'rejected');
    Renewal::insert($active, RenewalStatus::InProcess, CaEetApi::OTHER_REQ_ID);

    return compact('inWindow', 'outside', 'failed', 'active', 'playground');
}

const COMMAND_FIXTURES = [
    'renewal/CZ00000019-until-2026-12-10.pem',
    'renewal/CZ00000019-until-2026-12-11.pem',
    'renewal/CZ00000019-until-2026-12-05.pem',
    'renewal/CZ00000019-until-2027-06-30.pem',
    'renewal/CZ683555118-until-2026-12-10.pem',
    'playground/CZ00000019.pem',
];

// ---------------------------------------------------------------------------
// Selection without --certificate
// ---------------------------------------------------------------------------

it('processes certificates in the window (21 days) and with an active renewal, but not 22 days, playground or after Failed [R105]', function (): void {
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(30)]);
    $certificates = commandCertificates();

    $result = runRenewCommand();

    expect(calledFor($api, COMMAND_FIXTURES))->toBe([
        'renewal/CZ00000019-until-2026-12-10.pem',
        'renewal/CZ00000019-until-2027-06-30.pem',
    ])
        ->and($api->count('renew'))->toBe(1)
        ->and(Renewal::latest($certificates['inWindow'])->status)->toBe(RenewalStatus::InProcess)
        ->and(Renewal::latest($certificates['active'])->request_id)->toBe(CaEetApi::OTHER_REQ_ID)
        ->and(CertificateRenewal::query()->where('certificate_id', $certificates['outside']->getKey())->count())->toBe(0)
        ->and(CertificateRenewal::query()->where('certificate_id', $certificates['playground']->getKey())->count())->toBe(0)
        ->and(CertificateRenewal::query()->where('certificate_id', $certificates['failed']->getKey())->count())->toBe(1)
        ->and($result['code'])->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request->url() === CaEetApi::ENDPOINT.'/request/'.CaEetApi::OTHER_REQ_ID.'/status');
});

it('prints ID, CN, the decimal serial number, validTo as Y-m-d and the resulting state of every processed certificate [R105, R58]', function (): void {
    CaEetApi::fake();
    $certificate = Renewal::certificate();

    $result = runRenewCommand();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain((string) $certificate->getKey())
        ->and($result['output'])->toContain('CZ00000019')
        ->and($result['output'])->toContain('2026-12-10')
        // serial number 7001 (hex 1B59) of the renewed certificate, printed in decimal
        ->and($result['output'])->toContain('7001')
        ->and($result['output'])->toContain(RenewalStatus::Completed->label());
});

it('uses renewal.days_before_expiry for the window [R105]', function (): void {
    config()->set('laravel-eet-module.renewal.days_before_expiry', 22);
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(30)]);
    Renewal::certificate('renewal/CZ00000019-until-2026-12-11.pem');
    Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');

    runRenewCommand();

    expect(calledFor($api, COMMAND_FIXTURES))->toBe(['renewal/CZ00000019-until-2026-12-11.pem']);
});

it('does not process a certificate that left the window one second ago [R105]', function (): void {
    $api = CaEetApi::fake();
    Renewal::certificate();
    $this->travelTo(CarbonImmutable::parse('2026-11-18 23:59:59', 'UTC'));

    $result = runRenewCommand();

    expect($api->count())->toBe(0)
        ->and($result['code'])->toBe(0);
});

it('does not process a playground certificate even inside the window [R105, R90]', function (): void {
    CaEetApi::fake();
    importFixture(Renewal::taxpayer(), 'playground/CZ00000019.pem');
    $this->travelTo(CarbonImmutable::parse('2027-05-01 00:00:00', 'UTC'));

    $result = runRenewCommand();

    expect($result['code'])->toBe(0)
        ->and(CertificateRenewal::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('does not process an expired, an unusable or an already renewed certificate [R105]', function (): void {
    $api = CaEetApi::fake();
    $renewed = Renewal::certificate();
    Eet::renewCertificate($renewed);
    $unusable = Renewal::certificate('renewal/CZ00000019-until-2026-12-05.pem');
    Renewal::makeUnusable($unusable, 'renewal/CZ00000019-until-2026-12-05.pem');
    $api->forget();

    $result = runRenewCommand();

    expect($api->count())->toBe(0)
        ->and($result['code'])->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-12-11 00:00:00', 'UTC'));

    expect(runRenewCommand()['code'])->toBe(0)
        ->and($api->count())->toBe(0);
});

it('processes nothing and exits with 0 without certificates [R105]', function (): void {
    CaEetApi::fake();

    expect(runRenewCommand()['code'])->toBe(0);
    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// --taxpayer
// ---------------------------------------------------------------------------

it('limits the selection to the given taxpayers [R105]', function (): void {
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(30)]);
    Renewal::certificate();
    Renewal::certificate('renewal/CZ683555118-until-2026-12-10.pem', 'CZ683555118');

    $result = runRenewCommand(['--taxpayer' => ['CZ683555118']]);

    expect(calledFor($api, COMMAND_FIXTURES))->toBe(['renewal/CZ683555118-until-2026-12-10.pem'])
        ->and($result['code'])->toBe(0);
});

it('accepts --taxpayer repeatedly [R105]', function (): void {
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(30)]);
    Renewal::certificate();
    Renewal::certificate('renewal/CZ683555118-until-2026-12-10.pem', 'CZ683555118');

    runRenewCommand(['--taxpayer' => ['CZ00000019', 'CZ683555118']]);

    expect(calledFor($api, COMMAND_FIXTURES))->toBe([
        'renewal/CZ00000019-until-2026-12-10.pem',
        'renewal/CZ683555118-until-2026-12-10.pem',
    ]);
});

it('prints an error and exits with 1 without calling the API for an invalid or unknown EIČ [R105]', function (string $eic): void {
    CaEetApi::fake();
    Renewal::certificate();

    $result = runRenewCommand(['--taxpayer' => ['CZ00000019', $eic]]);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain($eic)
        ->and(CertificateRenewal::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'invalid' => 'XY',
    'unknown' => 'CZ12345678',
]);

// ---------------------------------------------------------------------------
// --certificate
// ---------------------------------------------------------------------------

it('processes only the given certificates, outside the window and after Failed [R105, R102]', function (): void {
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(30), 'renew' => [CaEetApi::created(), CaEetApi::created(CaEetApi::UNKNOWN_REQ_ID)]]);
    $certificates = commandCertificates();

    $result = runRenewCommand(['--certificate' => [(string) $certificates['outside']->getKey(), (string) $certificates['failed']->getKey()]]);

    expect(calledFor($api, COMMAND_FIXTURES))->toBe([
        'renewal/CZ00000019-until-2026-12-05.pem',
        'renewal/CZ00000019-until-2026-12-11.pem',
    ])
        ->and(CertificateRenewal::query()->where('certificate_id', $certificates['failed']->getKey())->count())->toBe(2)
        ->and(Renewal::latest($certificates['failed'])->status)->toBe(RenewalStatus::InProcess)
        ->and(Renewal::latest($certificates['outside'])->status)->toBe(RenewalStatus::InProcess)
        ->and(CertificateRenewal::query()->where('certificate_id', $certificates['inWindow']->getKey())->count())->toBe(0)
        ->and($result['code'])->toBe(0);
});

it('prints an error for an unknown ID, processes the others and exits with 1 [R105]', function (): void {
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(30)]);
    $certificate = Renewal::certificate();

    $result = runRenewCommand(['--certificate' => ['999999', (string) $certificate->getKey()]]);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('999999')
        ->and(calledFor($api, COMMAND_FIXTURES))->toBe(['renewal/CZ00000019-until-2026-12-10.pem']);
});

it('prints the translated reason for a certificate that cannot be renewed, processes the others and exits with 1 [R105, R90]', function (): void {
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(30)]);
    $playground = importFixture(Renewal::taxpayer(), 'playground/CZ00000019.pem');
    $certificate = Renewal::certificate();

    $result = runRenewCommand(['--certificate' => [(string) $playground->getKey(), (string) $certificate->getKey()]]);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain(RenewalProblem::NotProductionCertificate->label())
        ->and(calledFor($api, COMMAND_FIXTURES))->toBe(['renewal/CZ00000019-until-2026-12-10.pem'])
        ->and(CertificateRenewal::query()->where('certificate_id', $playground->getKey())->count())->toBe(0);
});

it('prints AlreadyRenewed for a renewed certificate given by ID [R105, R90]', function (): void {
    $api = CaEetApi::fake();
    $certificate = Renewal::certificate();
    Eet::renewCertificate($certificate);
    $api->forget();

    $result = runRenewCommand(['--certificate' => [(string) $certificate->getKey()]]);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain(RenewalProblem::AlreadyRenewed->label())
        ->and($api->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// --dry-run
// ---------------------------------------------------------------------------

it('only lists what it would process with --dry-run: no API call and no write [R105]', function (): void {
    CaEetApi::fake();
    $certificates = commandCertificates();
    $before = Renewal::databaseDump();

    $result = runRenewCommand(['--dry-run' => true]);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('2026-12-10')
        ->and($result['output'])->not->toContain('2026-12-11')
        ->and(Renewal::databaseDump())->toBe($before)
        ->and($certificates['inWindow']->exists)->toBeTrue();
    Http::assertNothingSent();
});

it('does not call the API for given certificates with --dry-run [R105]', function (): void {
    CaEetApi::fake();
    $certificate = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');

    $result = runRenewCommand(['--dry-run' => true, '--certificate' => [(string) $certificate->getKey()]]);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('2027-06-30')
        ->and(CertificateRenewal::query()->count())->toBe(0);
    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Exit codes and failure isolation
// ---------------------------------------------------------------------------

it('exits with 0 after Completed and after InProcess without a problem [R105]', function (Closure $status, string $expected): void {
    CaEetApi::fake(['status' => $status()]);
    $certificate = Renewal::certificate();

    $result = runRenewCommand();

    expect($result['code'])->toBe(0)
        ->and(Renewal::latest($certificate)->status->value)->toBe($expected);
})->with([
    'Completed' => [fn (): PromiseInterface => CaEetApi::issued(), 'completed'],
    'InProcess' => [fn (): PromiseInterface => CaEetApi::inProcess(30), 'in_process'],
]);

it('exits with 1 after Failed and prints the translated problem [R105, R58]', function (): void {
    CaEetApi::fake(['status' => CaEetApi::rejected()]);
    $certificate = Renewal::certificate();

    $result = runRenewCommand();

    expect($result['code'])->toBe(1)
        ->and(Renewal::latest($certificate)->status)->toBe(RenewalStatus::Failed)
        ->and($result['output'])->toContain(RenewalStatus::Failed->label())
        ->and($result['output'])->toContain(RenewalProblem::Rejected->label());
});

it('exits with 1 after a temporary problem [R105, R101]', function (Closure $routes): void {
    CaEetApi::fake($routes());
    $certificate = Renewal::certificate();

    $result = runRenewCommand();

    expect($result['code'])->toBe(1)
        ->and(Renewal::latest($certificate)->problem?->isTemporary())->toBeTrue();
})->with([
    'TemporaryError' => fn (): array => ['renew' => CaEetApi::error(500)],
    'UnknownPendingRequest' => fn (): array => ['not-finished' => CaEetApi::notFinished(CaEetApi::UNKNOWN_REQ_ID)],
]);

it('continues with the other certificates when one fails [R105]', function (): void {
    $first = CaEetApi::x5tS256(Fixtures::certificate('renewal/CZ00000019-until-2026-12-10.pem'));
    $api = CaEetApi::fake([
        'status' => CaEetApi::inProcess(30),
        'renew' => fn (Request $request): PromiseInterface => x5tOfRequest($request) === $first
            ? CaEetApi::error(400, 1, 'Invalid input data.')
            : CaEetApi::created(CaEetApi::OTHER_REQ_ID),
    ]);
    $failing = Renewal::certificate();
    $other = Renewal::certificate('renewal/CZ683555118-until-2026-12-10.pem', 'CZ683555118');

    $result = runRenewCommand();

    expect($result['code'])->toBe(1)
        ->and(Renewal::latest($failing)->status)->toBe(RenewalStatus::Failed)
        ->and(Renewal::latest($other)->status)->toBe(RenewalStatus::InProcess)
        ->and(Renewal::latest($other)->request_id)->toBe(CaEetApi::OTHER_REQ_ID)
        ->and($api->count('renew'))->toBe(2);
});

it('prints only the class of an unexpected non-package exception, continues with the others and exits with 1 [R105, R103]', function (): void {
    $first = CaEetApi::x5tS256(Fixtures::certificate('renewal/CZ00000019-until-2026-12-10.pem'));
    CaEetApi::fake([
        'status' => CaEetApi::inProcess(30),
        'renew' => function (Request $request) use ($first): PromiseInterface {
            if (x5tOfRequest($request) === $first) {
                throw new RuntimeException('Handler exploded');
            }

            return CaEetApi::created(CaEetApi::OTHER_REQ_ID);
        },
    ]);
    Renewal::certificate();
    $other = Renewal::certificate('renewal/CZ683555118-until-2026-12-10.pem', 'CZ683555118');

    $result = runRenewCommand();

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('RuntimeException')
        ->and($result['output'])->not->toContain('Handler exploded')
        ->and($result['output'])->not->toContain('PRIVATE KEY')
        ->and(Renewal::latest($other)->status)->toBe(RenewalStatus::InProcess);
});

it('prints a certificate locked by another run as running elsewhere, does not call the API for it and exits with 0 [R105, R104]', function (): void {
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(30)]);
    $locked = Renewal::certificate();
    $other = Renewal::certificate('renewal/CZ683555118-until-2026-12-10.pem', 'CZ683555118');
    $lock = Cache::store()->lock('laravel-eet-module:certificate:'.$locked->getKey(), 600);
    $lock->get();

    try {
        $result = runRenewCommand();
    } finally {
        $lock->release();
    }

    expect(calledFor($api, COMMAND_FIXTURES))->toBe(['renewal/CZ683555118-until-2026-12-10.pem'])
        ->and(CertificateRenewal::query()->where('certificate_id', $locked->getKey())->count())->toBe(0)
        ->and(Renewal::latest($other)->status)->toBe(RenewalStatus::InProcess)
        ->and($result['output'])->toContain((string) $locked->getKey())
        ->and($result['code'])->toBe(0);
});

it('prints the configuration key and exits with 1 on an invalid configuration [R105, R68]', function (string $key, mixed $value): void {
    CaEetApi::fake();
    Renewal::certificate();
    config()->set('laravel-eet-module.'.$key, $value);

    $result = runRenewCommand();

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain($key)
        ->and(CertificateRenewal::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    ['renewal.endpoint', 'https://ca.example.com/api'],
    ['renewal.days_before_expiry', 0],
]);

// ---------------------------------------------------------------------------
// Continuing renewals through the command (R97, R98)
// ---------------------------------------------------------------------------

it('sends only the acknowledgement for a renewal left in Acknowledging [R105, R97]', function (): void {
    $api = CaEetApi::fake(['ack-download' => [CaEetApi::error(500), CaEetApi::acknowledged()]]);
    $certificate = Renewal::certificate();
    Eet::renewCertificate($certificate);
    $api->forget();

    $result = runRenewCommand();

    expect(Renewal::latest($certificate)->status)->toBe(RenewalStatus::Completed)
        ->and($api->calls())->toBe(['ack-download'])
        ->and($result['code'])->toBe(0);
});

it('completes a renewal in Acknowledging without acknowledging after the renewed certificate expired [R105, R97]', function (): void {
    $api = CaEetApi::fake(['ack-download' => CaEetApi::error(500)]);
    $certificate = Renewal::certificate();
    Eet::renewCertificate($certificate);
    $api->forget();
    $this->travelTo(CarbonImmutable::parse('2026-12-10 00:00:01', 'UTC'));

    runRenewCommand();

    expect(Renewal::latest($certificate)->status)->toBe(RenewalStatus::Completed)
        ->and(Renewal::latest($certificate)->acknowledged_at)->toBeNull()
        ->and($api->count())->toBe(0);
});

it('completes a renewal in Acknowledging with CertificateUnusable without calling the API when the old key cannot be decrypted [R105, R97, R69]', function (): void {
    $api = CaEetApi::fake(['ack-download' => CaEetApi::error(500)]);
    $certificate = Renewal::certificate();
    Eet::renewCertificate($certificate);
    $api->forget();
    Renewal::makeUnusable($certificate);

    runRenewCommand();

    expect(Renewal::latest($certificate)->status)->toBe(RenewalStatus::Completed)
        ->and(Renewal::latest($certificate)->problem)->toBe(RenewalProblem::CertificateUnusable)
        ->and(Renewal::latest($certificate)->acknowledged_at)->toBeNull()
        ->and(Renewal::fresh($certificate)->successor())->not->toBeNull()
        ->and($api->count())->toBe(0);
});

it('fails a renewal in InProcess without calling the API after the renewed certificate expired [R105, R98]', function (): void {
    $api = CaEetApi::fake();
    $certificate = Renewal::certificate();
    Renewal::insert($certificate, RenewalStatus::InProcess);
    $this->travelTo(CarbonImmutable::parse('2026-12-10 00:00:01', 'UTC'));

    $result = runRenewCommand();

    expect(Renewal::latest($certificate)->status)->toBe(RenewalStatus::Failed)
        ->and(Renewal::latest($certificate)->problem)->toBe(RenewalProblem::CertificateNotValid)
        ->and($api->count())->toBe(0)
        ->and($result['code'])->toBe(1);
});

it('prints class and message of an unexpected package exception, continues with the others and exits with 1 [R105]', function (): void {
    $first = CaEetApi::x5tS256(Fixtures::certificate('renewal/CZ00000019-until-2026-12-10.pem'));
    CaEetApi::fake([
        'status' => CaEetApi::inProcess(30),
        'renew' => function (Request $request) use ($first): PromiseInterface {
            if (x5tOfRequest($request) === $first) {
                throw new PackageFailure('Package step failed');
            }

            return CaEetApi::created(CaEetApi::OTHER_REQ_ID);
        },
    ]);
    Renewal::certificate();
    $other = Renewal::certificate('renewal/CZ683555118-until-2026-12-10.pem', 'CZ683555118');

    $result = runRenewCommand();

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('PackageFailure')
        ->and($result['output'])->toContain('Package step failed')
        ->and(Renewal::latest($other)->status)->toBe(RenewalStatus::InProcess);
});

it('prints only the class of a QueryException, without its SQL and bindings [R105, R103]', function (): void {
    $first = CaEetApi::x5tS256(Fixtures::certificate('renewal/CZ00000019-until-2026-12-10.pem'));
    CaEetApi::fake([
        'status' => CaEetApi::inProcess(30),
        'renew' => function (Request $request) use ($first): PromiseInterface {
            if (x5tOfRequest($request) === $first) {
                DB::select('select * from eet_no_such_table where secret = ?', ['binding-secret-42']);
            }

            return CaEetApi::created(CaEetApi::OTHER_REQ_ID);
        },
    ]);
    Renewal::certificate();
    Renewal::certificate('renewal/CZ683555118-until-2026-12-10.pem', 'CZ683555118');

    $result = runRenewCommand();

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('QueryException')
        ->and($result['output'])->not->toContain('binding-secret-42')
        ->and($result['output'])->not->toContain('eet_no_such_table');
});

it('evaluates R90 with --dry-run and prints a certificate that cannot be renewed with exit code 1 [R105, R90]', function (): void {
    CaEetApi::fake();
    $playground = importFixture(Renewal::taxpayer(), 'playground/CZ00000019.pem');
    $before = Renewal::databaseDump();

    $result = runRenewCommand(['--dry-run' => true, '--certificate' => [(string) $playground->getKey()]]);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain((string) $playground->getKey())
        ->and($result['output'])->toContain(RenewalProblem::NotProductionCertificate->label())
        ->and(Renewal::databaseDump())->toBe($before);
    Http::assertNothingSent();
});

it('reports a given certificate of another taxpayer than --taxpayer and exits with 1 [R105]', function (): void {
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(30)]);
    $foreign = Renewal::certificate('renewal/CZ683555118-until-2026-12-10.pem', 'CZ683555118');
    $own = Renewal::certificate();

    $result = runRenewCommand(['--certificate' => [(string) $foreign->getKey(), (string) $own->getKey()], '--taxpayer' => ['CZ00000019']]);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain((string) $foreign->getKey())
        ->and($result['output'])->toContain('does not belong')
        ->and(calledFor($api, COMMAND_FIXTURES))->toBe(['renewal/CZ00000019-until-2026-12-10.pem'])
        ->and(CertificateRenewal::query()->where('certificate_id', $foreign->getKey())->count())->toBe(0);
});
