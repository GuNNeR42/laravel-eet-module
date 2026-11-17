<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Actions\RenewCertificate;
use Gunner42\LaravelEetModule\Enums\RenewalProblem;
use Gunner42\LaravelEetModule\Enums\RenewalStatus;
use Gunner42\LaravelEetModule\Events\CertificateRenewalFailed;
use Gunner42\LaravelEetModule\Events\CertificateRenewed;
use Gunner42\LaravelEetModule\Exceptions\CertificateNotRenewable;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\CertificateRenewal;
use Gunner42\LaravelEetModule\Tests\Support\CaEetApi;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Gunner42\LaravelEetModule\Tests\Support\Renewal;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * Classification of problems (R101), failure handling (R102) and secrecy of
 * the downloaded PKCS#12 and its password (R103).
 */

beforeEach(function (): void {
    $this->travelTo(Renewal::now());
    Sleep::fake(syncWithCarbon: true);
});

function timeoutFailure(): Closure
{
    return fn (Request $request): PromiseInterface => Create::rejectionFor(new ConnectException(
        'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received',
        $request->toPsrRequest(),
        null,
        ['errno' => 28],
    ));
}

function connectionFailure(): Closure
{
    return fn (Request $request): PromiseInterface => Create::rejectionFor(new ConnectException(
        'cURL error 7: Failed to connect to proxy http://user:proxy-secret@proxy.example:3128',
        $request->toPsrRequest(),
    ));
}

/**
 * @return array<string, Closure(): (PromiseInterface|Closure)>
 */
function temporaryFailures(): array
{
    return [
        'HTTP 429' => fn (): PromiseInterface => CaEetApi::error(429, 6, 'Too many requests.'),
        'HTTP 500' => fn (): PromiseInterface => CaEetApi::error(500),
        'HTTP 503 without a body' => fn (): PromiseInterface => Http::response('', 503),
        'HTTP 302' => fn (): PromiseInterface => Http::response('', 302, ['Location' => 'https://evil.example/api']),
        'timeout' => fn (): Closure => timeoutFailure(),
        'connection error' => fn (): Closure => connectionFailure(),
        'invalid JSON' => fn (): PromiseInterface => Http::response('{"reqId": ', 201, ['Content-Type' => 'application/json']),
        'HTML instead of JSON' => fn (): PromiseInterface => Http::response('<html><body>Maintenance</body></html>', 201, ['Content-Type' => 'text/html']),
        'reqId missing' => fn (): PromiseInterface => Http::response(['id' => CaEetApi::REQ_ID], 201),
        'reqId not a UUID' => fn (): PromiseInterface => Http::response(['reqId' => '../../admin'], 201),
    ];
}

// ---------------------------------------------------------------------------
// R101 temporary problems
// ---------------------------------------------------------------------------

it('keeps Requesting with TemporaryError on a temporary problem of POST /request/renew [R101]', function (Closure $failure): void {
    Renewal::fakeEvents();
    $api = CaEetApi::fake(['renew' => $failure()]);
    $certificate = Renewal::certificate();

    $renewal = Eet::renewCertificate($certificate);

    expect($renewal?->status)->toBe(RenewalStatus::Requesting)
        ->and($renewal?->problem)->toBe(RenewalProblem::TemporaryError)
        ->and($renewal?->problem?->isTemporary())->toBeTrue()
        ->and($renewal?->request_id)->toBeNull()
        ->and($renewal?->failed_at)->toBeNull()
        ->and((string) $renewal?->problem_detail)->not->toContain('proxy-secret')
        ->and(json_encode($renewal?->toArray()))->not->toContain('proxy-secret')
        ->and($api->count('status'))->toBe(0);
    Event::assertNotDispatched(CertificateRenewalFailed::class);
})->with(temporaryFailures());

it('keeps the state with TemporaryError on a temporary problem in every step [R101]', function (string $route, string $expected): void {
    $api = CaEetApi::fake([$route => CaEetApi::error(500)]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status?->value)->toBe($expected)
        ->and($renewal?->problem)->toBe(RenewalProblem::TemporaryError)
        ->and($api->count($route))->toBe(1);
})->with([
    'not-finished' => ['not-finished', 'requesting'],
    'status' => ['status', 'in_process'],
    'claim-download' => ['claim-download', 'downloading'],
    'ack-download' => ['ack-download', 'acknowledging'],
]);

it('treats a status response that does not match the API schema as a temporary problem [R101]', function (Closure $response): void {
    $api = CaEetApi::fake(['status' => $response()]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status)->toBe(RenewalStatus::InProcess)
        ->and($renewal?->problem)->toBe(RenewalProblem::TemporaryError)
        ->and($api->count('claim-download'))->toBe(0);
})->with([
    'unknown state' => fn (): PromiseInterface => CaEetApi::state('REVOKED'),
    'INPROCESS without pollAfterSeconds' => fn (): PromiseInterface => CaEetApi::state('INPROCESS'),
    'not JSON' => fn (): PromiseInterface => Http::response('ISSUED', 200),
]);

it('treats a claim-download response that does not match the API schema as a temporary problem and does not acknowledge [R101]', function (Closure $response): void {
    $api = CaEetApi::fake(['claim-download' => $response()]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status)->toBe(RenewalStatus::Downloading)
        ->and($renewal?->problem)->toBe(RenewalProblem::TemporaryError)
        ->and(Renewal::stored())->toBeNull()
        ->and($api->count('ack-download'))->toBe(0);
})->with([
    'pkcs12 missing' => fn (): PromiseInterface => Http::response(['password' => CaEetApi::PASSWORD, 'certificate' => CaEetApi::metadataOf(CaEetApi::SUCCESSOR)], 200),
    'certificate metadata missing' => fn (): PromiseInterface => Http::response(['pkcs12' => base64_encode(Fixtures::read(CaEetApi::SUCCESSOR)), 'password' => CaEetApi::PASSWORD], 200),
    'serial number not decimal' => fn (): PromiseInterface => CaEetApi::pkcs12(certificate: ['sn' => '15A691A9BF5ACE98C']),
]);

it('clears problem and problem_detail after a successful step [R101]', function (): void {
    $api = CaEetApi::fake(['renew' => [CaEetApi::error(429, 6, 'Too many requests.'), CaEetApi::created()]]);
    $certificate = Renewal::certificate();
    Eet::renewCertificate($certificate);

    $renewal = Eet::renewCertificate($certificate);

    expect($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($renewal?->problem)->toBeNull()
        ->and($renewal?->problem_detail)->toBeNull()
        ->and(Renewal::renewalCount())->toBe(1)
        ->and($api->count('renew'))->toBe(2);
});

it('retries a temporary problem only while the certificate is valid [R101, R98]', function (): void {
    $api = CaEetApi::fake(['renew' => CaEetApi::error(500)]);
    $certificate = Renewal::certificate();
    Eet::renewCertificate($certificate);
    $api->forget();
    $this->travelTo(CarbonImmutable::parse('2026-12-10 00:00:01', 'UTC'));

    $renewal = Eet::renewCertificate($certificate);

    expect($renewal?->status)->toBe(RenewalStatus::Failed)
        ->and($renewal?->problem)->toBe(RenewalProblem::CertificateNotValid)
        ->and($api->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// R101 final problems
// ---------------------------------------------------------------------------

it('fails with RequestFailed on HTTP 400 and 401 of POST /request/renew [R101, R102]', function (int $status, int $code, string $message): void {
    Renewal::fakeEvents();
    $api = CaEetApi::fake(['renew' => CaEetApi::error($status, $code, $message, 'Secret additional info')]);
    $certificate = Renewal::certificate();

    $renewal = Eet::renewCertificate($certificate);

    expect($renewal?->status)->toBe(RenewalStatus::Failed)
        ->and($renewal?->problem)->toBe(RenewalProblem::RequestFailed)
        ->and($renewal?->problem?->isTemporary())->toBeFalse()
        ->and($renewal?->failed_at)->not->toBeNull()
        ->and((string) $renewal?->problem_detail)->toContain((string) $status)
        ->and((string) $renewal?->problem_detail)->toContain($message)
        ->and((string) $renewal?->problem_detail)->not->toContain('Secret additional info')
        ->and($api->count('status'))->toBe(0);
    Event::assertDispatchedTimes(CertificateRenewalFailed::class, 1);
    Event::assertDispatched(CertificateRenewalFailed::class, fn (CertificateRenewalFailed $event): bool => $event->problem === RenewalProblem::RequestFailed
        && $event->renewal->getKey() === $renewal?->getKey());
})->with([
    'bad request' => [400, 1, 'Invalid input data.'],
    'unauthorized' => [401, 2, 'Unauthorized access.'],
]);

it('fails with RequestFailed on HTTP 404 of the status and 409 of claim-download [R101]', function (string $route, int $status): void {
    $api = CaEetApi::fake([$route => CaEetApi::error($status, $status === 404 ? 4 : 5, 'Unknown object.')]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status)->toBe(RenewalStatus::Failed)
        ->and($renewal?->problem)->toBe(RenewalProblem::RequestFailed)
        ->and(Renewal::stored())->toBeNull()
        ->and($api->count('ack-download'))->toBe(0);
})->with([
    'status 404' => ['status', 404],
    'status 401' => ['status', 401],
    'claim-download 409' => ['claim-download', 409],
    'claim-download 404' => ['claim-download', 404],
]);

it('fails with RequestFailed on HTTP 401 of not-finished [R101]', function (): void {
    $api = CaEetApi::fake(['not-finished' => CaEetApi::error(401, 2, 'Unauthorized access.')]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status)->toBe(RenewalStatus::Failed)
        ->and($renewal?->problem)->toBe(RenewalProblem::RequestFailed)
        ->and($api->count('renew'))->toBe(0);
});

it('completes with RequestFailed and without acknowledged_at on a final problem of ack-download, keeping the successor [R97, R101]', function (int $status, int $code, string $message): void {
    Renewal::fakeEvents();
    $api = CaEetApi::fake(['ack-download' => CaEetApi::error($status, $code, $message)]);
    $certificate = Renewal::certificate();
    $cashRegister = Renewal::cashRegister('POKLADNA-1', $certificate);

    $renewal = Eet::renewCertificate($certificate);
    $successor = Renewal::stored();

    expect($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($renewal?->acknowledged_at)->toBeNull()
        ->and($renewal?->completed_at)->not->toBeNull()
        ->and($renewal?->failed_at)->toBeNull()
        ->and($renewal?->problem)->toBe(RenewalProblem::RequestFailed)
        ->and((string) $renewal?->problem_detail)->toContain((string) $status)
        ->and((string) $renewal?->problem_detail)->toContain($message)
        ->and($successor)->not->toBeNull()
        ->and(Renewal::fresh($certificate)->successor()?->getKey())->toBe($successor?->getKey())
        ->and(CashRegister::query()->findOrFail($cashRegister->getKey())->certificate_id)->toBe($successor?->getKey());
    Event::assertNotDispatched(CertificateRenewalFailed::class);
    Event::assertDispatchedTimes(CertificateRenewed::class, 1);

    // the certificate counts as renewed: no new renewal, no further call
    $api->forget();

    expect(fn (): mixed => Eet::renewCertificate(Renewal::fresh($certificate)))->toThrow(CertificateNotRenewable::class)
        ->and($api->count())->toBe(0);
})->with([
    'bad request' => [400, 1, 'Invalid input data.'],
    'unauthorized' => [401, 2, 'Unauthorized access.'],
]);

it('limits problem_detail to 255 characters [R101]', function (): void {
    CaEetApi::fake(['renew' => CaEetApi::error(400, 1, str_repeat('Invalid input data. ', 50))]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->problem)->toBe(RenewalProblem::RequestFailed)
        ->and(mb_strlen((string) $renewal?->problem_detail))->toBeLessThanOrEqual(255)
        ->and((string) $renewal?->problem_detail)->toContain('400');
});

// ---------------------------------------------------------------------------
// R102
// ---------------------------------------------------------------------------

it('dispatches CertificateRenewalFailed after the commit with the certificate, renewal and problem [R102]', function (): void {
    CaEetApi::fake(['status' => CaEetApi::rejected()]);
    $certificate = Renewal::certificate();
    $base = DB::transactionLevel();
    $observed = [];
    Event::listen(CertificateRenewalFailed::class, function (CertificateRenewalFailed $event) use (&$observed): void {
        $observed[] = [
            'level' => DB::transactionLevel(),
            'status' => CertificateRenewal::query()->findOrFail($event->renewal->getKey())->status,
            'certificate' => $event->certificate->getKey(),
            'problem' => $event->problem,
        ];
    });

    Eet::renewCertificate($certificate);

    expect($observed)->toHaveCount(1)
        ->and($observed[0]['level'])->toBe($base)
        ->and($observed[0]['status'])->toBe(RenewalStatus::Failed)
        ->and($observed[0]['certificate'])->toBe($certificate->getKey())
        ->and($observed[0]['problem'])->toBe(RenewalProblem::Rejected);
});

it('does not dispatch CertificateRenewalFailed for a temporary problem [R102]', function (): void {
    Renewal::fakeEvents();
    CaEetApi::fake(['status' => CaEetApi::error(500)]);

    Eet::renewCertificate(Renewal::certificate());

    Event::assertNotDispatched(CertificateRenewalFailed::class);
});

it('starts a new renewal after Failed when renewCertificate() is called again [R102, R93]', function (): void {
    $api = CaEetApi::fake(['status' => [CaEetApi::rejected(), CaEetApi::issued()], 'renew' => [CaEetApi::created(), CaEetApi::created(CaEetApi::OTHER_REQ_ID)]]);
    $certificate = Renewal::certificate();
    $failed = Eet::renewCertificate($certificate);
    $api->forget();

    $renewal = Eet::renewCertificate($certificate);

    expect($failed?->status)->toBe(RenewalStatus::Failed)
        ->and($renewal?->getKey())->not->toBe($failed?->getKey())
        ->and($renewal?->request_id)->toBe(CaEetApi::OTHER_REQ_ID)
        ->and($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and(CertificateRenewal::query()->findOrFail($failed?->getKey())->status)->toBe(RenewalStatus::Failed)
        ->and(Renewal::fresh($certificate)->latestRenewal()?->getKey())->toBe($renewal?->getKey())
        ->and($api->calls())->toBe(['not-finished', 'renew', 'status', 'claim-download', 'ack-download']);
});

// ---------------------------------------------------------------------------
// R103
// ---------------------------------------------------------------------------

/**
 * @return list<string>
 */
function renewalSecrets(): array
{
    $pkcs12 = base64_encode(Fixtures::read(CaEetApi::SUCCESSOR));

    return [CaEetApi::PASSWORD, $pkcs12, substr($pkcs12, 0, 64), substr($pkcs12, 200, 64)];
}

it('keeps the PKCS#12, its password and the successor key out of logs, events, the renewal and model serialization [R103]', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    $dispatched = [];
    Event::listen([CertificateRenewed::class, CertificateRenewalFailed::class], function (object $event) use (&$dispatched): void {
        $dispatched[] = serialize($event).print_r($event, true);
    });
    CaEetApi::fake();
    $certificate = Renewal::certificate();

    $renewal = Eet::renewCertificate($certificate);
    $successor = Renewal::stored();

    $everything = implode("\n", [
        implode("\n", $logged),
        implode("\n", $dispatched),
        json_encode($renewal?->toArray()),
        (string) $renewal?->toJson(),
        json_encode($successor?->toArray()),
        json_encode(Renewal::fresh($certificate)->load('renewals')->toArray()),
        Renewal::databaseDump(),
    ]);

    expect($dispatched)->toHaveCount(1)
        ->and($successor?->toArray())->not->toHaveKey('private_key')
        ->and($everything)->not->toContain('PRIVATE KEY');

    foreach (renewalSecrets() as $secret) {
        expect($everything)->not->toContain($secret);
    }
});

it('keeps the PKCS#12 and its password out of the problem, the failure event and the logs when the download fails [R103]', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    $dispatched = [];
    Event::listen(CertificateRenewalFailed::class, function (CertificateRenewalFailed $event) use (&$dispatched): void {
        $dispatched[] = serialize($event).print_r($event, true);
    });
    CaEetApi::fake(['claim-download' => CaEetApi::pkcs12('renewal/successor-other-cn.p12', certificate: CaEetApi::metadataOf('renewal/successor-other-cn.p12'))]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    $pkcs12 = base64_encode(Fixtures::read('renewal/successor-other-cn.p12'));
    $everything = implode("\n", [implode("\n", $logged), implode("\n", $dispatched), json_encode($renewal?->toArray()), Renewal::databaseDump()]);

    expect($renewal?->problem)->toBe(RenewalProblem::CertificateMismatch)
        ->and($dispatched)->toHaveCount(1)
        ->and($everything)->not->toContain(CaEetApi::PASSWORD)
        ->and($everything)->not->toContain(substr($pkcs12, 0, 64))
        ->and($everything)->not->toContain(substr($pkcs12, 300, 64));
});

it('keeps the PKCS#12 and its password out of the cache [R103]', function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eet-renewal-cache-'.bin2hex(random_bytes(8));
    config()->set('cache.stores.renewal-test', ['driver' => 'file', 'path' => $directory, 'lock_path' => $directory]);
    config()->set('cache.default', 'renewal-test');
    config()->set('laravel-eet-module.lock_store', 'renewal-test');
    CaEetApi::fake();

    try {
        expect(Eet::renewCertificate(Renewal::certificate())?->status)->toBe(RenewalStatus::Completed);

        $cached = '';

        foreach (File::exists($directory) ? File::allFiles($directory) : [] as $file) {
            $cached .= (string) file_get_contents($file->getPathname());
        }

        foreach (renewalSecrets() as $secret) {
            expect($cached)->not->toContain($secret);
        }
    } finally {
        File::deleteDirectory($directory);
    }
});

/**
 * The strings among the arguments of a stack trace (up to a small depth),
 * without rendering whole objects.
 *
 * @param  list<array<string, mixed>>  $trace
 * @return list<string>
 */
function renewalTraceStrings(array $trace): array
{
    $strings = [];
    $collect = function (mixed $value, int $depth) use (&$collect, &$strings): void {
        if (is_string($value)) {
            $strings[] = $value;
        } elseif (is_array($value) && $depth < 3) {
            foreach ($value as $item) {
                $collect($item, $depth + 1);
            }
        }
    };

    foreach ($trace as $frame) {
        $collect($frame['args'] ?? [], 0);
    }

    return $strings;
}

it('keeps the PKCS#12 and its password out of exceptions and their traces when storing the successor fails [R103]', function (): void {
    $api = CaEetApi::fake();
    $certificate = Renewal::certificate();
    CashRegisterCertificate::creating(function (): void {
        throw new RuntimeException('Persisting failed');
    });
    $thrown = null;

    try {
        app(RenewCertificate::class)->handle($certificate);
    } catch (Throwable $exception) {
        $thrown = $exception;
    } finally {
        CashRegisterCertificate::flushEventListeners();
    }

    for ($exception = $thrown; $exception !== null; $exception = $exception->getPrevious()) {
        $rendered = $exception->getMessage()."\n".$exception->getTraceAsString()."\n".implode("\n", renewalTraceStrings($exception->getTrace()));

        expect($rendered)->not->toContain('PRIVATE KEY');

        foreach (renewalSecrets() as $secret) {
            expect($rendered)->not->toContain($secret);
        }
    }

    $renewal = Renewal::latest($certificate);

    expect(Renewal::stored())->toBeNull()
        ->and($renewal->status)->not->toBe(RenewalStatus::Completed)
        ->and(json_encode($renewal->toArray()))->not->toContain(CaEetApi::PASSWORD)
        ->and($api->count('ack-download'))->toBe(0);
});

it('treats any undocumented HTTP status as a temporary problem and keeps the state [R101]', function (string $route, Closure $response, string $expected): void {
    $api = CaEetApi::fake([$route => $response()]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status?->value)->toBe($expected)
        ->and($renewal?->problem)->toBe(RenewalProblem::TemporaryError)
        ->and($api->count($route))->toBe(1);
})->with([
    'renew 200 instead of 201' => ['renew', fn (): PromiseInterface => Http::response(['reqId' => CaEetApi::REQ_ID], 200), 'requesting'],
    'status 403' => ['status', fn (): PromiseInterface => CaEetApi::error(403, 3, 'Forbidden.'), 'in_process'],
    'claim-download 422' => ['claim-download', fn (): PromiseInterface => CaEetApi::error(422, 7, 'Unprocessable.'), 'downloading'],
    'not-finished 418' => ['not-finished', fn (): PromiseInterface => CaEetApi::error(418, 8, 'Teapot.'), 'requesting'],
    'ack-download 200 instead of 204' => ['ack-download', fn (): PromiseInterface => Http::response('', 200), 'acknowledging'],
]);

it('treats a status for another reqId as a temporary problem and keeps the state [R101]', function (): void {
    $api = CaEetApi::fake(['status' => CaEetApi::issued(CaEetApi::OTHER_REQ_ID)])->verbatimStatus();

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status)->toBe(RenewalStatus::InProcess)
        ->and($renewal?->request_id)->toBe(CaEetApi::REQ_ID)
        ->and($renewal?->problem)->toBe(RenewalProblem::TemporaryError)
        ->and($api->count('claim-download'))->toBe(0);
});

it('removes control characters (C0, DEL, C1) from problem_detail [R101]', function (string $route, Closure $response): void {
    CaEetApi::fake([$route => $response()]);

    $renewal = Eet::renewCertificate(Renewal::certificate());
    $detail = (string) $renewal?->problem_detail;

    expect($renewal?->problem)->not->toBeNull()
        ->and($detail)->toContain('Bad')
        ->and($detail)->toContain('end')
        ->and(preg_match('/[\x{0}-\x{1F}\x{7F}-\x{9F}]/u', $detail))->toBe(0);
})->with([
    'ErrorDTO message' => ['renew', fn (): PromiseInterface => CaEetApi::error(400, 1, "Bad\u{1b}[31m input\u{7}\r\n\u{0}\u{7f}\u{9b}\u{85} end")],
    'rejectedReason' => ['status', fn (): PromiseInterface => CaEetApi::rejected("Bad\u{1b}]0;title\u{7} reason\t\u{7f}\u{9b}\u{9f} end")],
]);

// ---------------------------------------------------------------------------
// Q75: a failed write of a certificate leaks neither PEM nor encrypted key
// ---------------------------------------------------------------------------

function failCertificateInserts(): void
{
    DB::statement("CREATE TRIGGER eet_test_fail_certificate_insert BEFORE INSERT ON eet_cash_register_certificates BEGIN SELECT RAISE(ABORT, 'boom'); END");
}

function expectQueryExceptionWithoutSecrets(?Throwable $thrown, string $keyFixturePem): void
{
    expect($thrown)->toBeInstanceOf(QueryException::class);

    if (! $thrown instanceof QueryException) {
        return;
    }

    $keyBody = (string) preg_replace('/\s+/', '', (string) preg_replace('/^.*?-----BEGIN PRIVATE KEY-----(.+?)-----END PRIVATE KEY-----.*$/s', '$1', $keyFixturePem));

    expect($thrown->getMessage())->toContain('boom')
        ->and($thrown->getMessage())->not->toContain('BEGIN')
        ->and($thrown->getMessage())->not->toContain('eyJpdiI6')
        ->and($thrown->getMessage())->not->toContain(substr($keyBody, 0, 64))
        ->and($thrown->getBindings())->toBe([])
        ->and($thrown->getPrevious())->not->toBeNull();
}

it('rethrows a failed certificate import without bindings, so neither PEM nor encrypted key appears [R15, R103]', function (): void {
    $taxpayer = Renewal::taxpayer();
    failCertificateInserts();
    $thrown = null;

    try {
        Eet::importCertificate($taxpayer, Fixtures::read(CaEetApi::RENEWED));
    } catch (Throwable $exception) {
        $thrown = $exception;
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS eet_test_fail_certificate_insert');
    }

    expectQueryExceptionWithoutSecrets($thrown, Fixtures::read(CaEetApi::RENEWED));
    expect(CashRegisterCertificate::query()->count())->toBe(0);
});

it('rethrows a failed write of the successor without bindings and stores nothing partial [R103, R15]', function (): void {
    Renewal::fakeEvents();
    $api = CaEetApi::fake();
    $old = Renewal::certificate();
    $cashRegister = Renewal::cashRegister('POKLADNA-1', $old);
    failCertificateInserts();
    $thrown = null;

    try {
        Eet::renewCertificate($old);
    } catch (Throwable $exception) {
        $thrown = $exception;
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS eet_test_fail_certificate_insert');
    }

    $successorKey = '';
    $contents = [];

    if (openssl_pkcs12_read(Fixtures::read(CaEetApi::SUCCESSOR), $contents, CaEetApi::PASSWORD) && is_string($contents['pkey'] ?? null)) {
        $successorKey = $contents['pkey'];
    }

    expectQueryExceptionWithoutSecrets($thrown, $successorKey);
    expect(Renewal::stored())->toBeNull()
        ->and(Renewal::latest($old)->status)->not->toBe(RenewalStatus::Acknowledging)
        ->and(Renewal::latest($old)->successor_id)->toBeNull()
        ->and(CashRegister::query()->findOrFail($cashRegister->getKey())->certificate_id)->toBe($old->getKey())
        ->and($api->count('ack-download'))->toBe(0);
    Event::assertNotDispatched(CertificateRenewed::class);
});
