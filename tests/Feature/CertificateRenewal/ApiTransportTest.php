<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Enums\RenewalProblem;
use Gunner42\LaravelEetModule\Enums\RenewalStatus;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\CertificateRenewal;
use Gunner42\LaravelEetModule\Tests\Support\CaEetApi;
use Gunner42\LaravelEetModule\Tests\Support\Renewal;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Http\Message\RequestInterface;

/*
 * R100: the API is called through the Laravel HTTP client, so Http::fake()
 * replaces the transport and its stub receives the Guzzle request options.
 * The transport rules of R66 apply by analogy.
 */

beforeEach(function (): void {
    $this->travelTo(Renewal::now());
    Sleep::fake(syncWithCarbon: true);
});

/**
 * Runs a whole renewal and returns the options of every API call.
 *
 * @return list<array<string, mixed>>
 */
function renewalRequestOptions(): array
{
    $api = CaEetApi::fake();

    Eet::renewCertificate(Renewal::certificate());

    expect($api->count())->toBe(5);

    return array_map(fn (array $entry): array => $entry['options'], $api->log());
}

it('calls renewal.endpoint + path with Accept: application/json [R100]', function (): void {
    $api = CaEetApi::fake();

    Eet::renewCertificate(Renewal::certificate());

    expect(array_map(fn (array $entry): string => $entry['request']->method().' '.$entry['request']->url(), $api->log()))->toBe([
        'GET https://ca.caeet.gov.cz/api/request/not-finished',
        'POST https://ca.caeet.gov.cz/api/request/renew',
        'GET https://ca.caeet.gov.cz/api/request/'.CaEetApi::REQ_ID.'/status',
        'POST https://ca.caeet.gov.cz/api/request/'.CaEetApi::REQ_ID.'/claim-download',
        'POST https://ca.caeet.gov.cz/api/request/'.CaEetApi::REQ_ID.'/ack-download',
    ]);

    foreach ($api->log() as $entry) {
        expect($entry['request']->header('Accept'))->toBe(['application/json']);
    }
});

it('calls another allowed CA EET host configured as renewal.endpoint [R100]', function (string $endpoint): void {
    config()->set('laravel-eet-module.renewal.endpoint', $endpoint);
    $api = CaEetApi::fake(endpoint: $endpoint);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($api->count())->toBe(5);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), $endpoint.'/request/'));
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), CaEetApi::ENDPOINT));
})->with([
    'test' => 'https://ca.test.caeet.gov.cz/api',
    'trial' => 'https://ca.zkus.caeet.gov.cz/api',
]);

it('accepts the allowed host case-insensitively and with a trailing dot [R100, R66]', function (string $endpoint): void {
    config()->set('laravel-eet-module.renewal.endpoint', $endpoint);
    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => match (true) {
        str_ends_with($request->url(), '/request/not-finished') => CaEetApi::notFinished(),
        str_ends_with($request->url(), '/request/renew') => CaEetApi::created(),
        default => CaEetApi::inProcess(1),
    });
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 0);

    expect(Eet::renewCertificate(Renewal::certificate())?->status)->toBe(RenewalStatus::InProcess);
})->with([
    'upper case' => 'https://CA.CAEET.GOV.CZ/api',
    'trailing dot' => 'https://ca.caeet.gov.cz./api',
]);

it('accepts a host added to renewal.allowed_hosts [R100]', function (): void {
    config()->set('laravel-eet-module.renewal.allowed_hosts', ['ca.example.test']);
    config()->set('laravel-eet-module.renewal.endpoint', 'https://ca.example.test/api');
    CaEetApi::fake(endpoint: 'https://ca.example.test/api');

    expect(Eet::renewCertificate(Renewal::certificate())?->status)->toBe(RenewalStatus::Completed);
});

it('rejects the default endpoint once its host is removed from renewal.allowed_hosts [R100]', function (): void {
    config()->set('laravel-eet-module.renewal.allowed_hosts', ['ca.test.caeet.gov.cz']);
    CaEetApi::fake();
    $certificate = Renewal::certificate();

    try {
        Eet::renewCertificate($certificate);

        throw new AssertionFailedError('InvalidConfiguration was not thrown.');
    } catch (InvalidConfiguration $exception) {
        expect($exception->key)->toBe('renewal.endpoint');
    }

    Http::assertNothingSent();
});

it('accepts a loopback http endpoint only while running unit tests [R100]', function (): void {
    $endpoint = 'http://127.0.0.1:8099/api';
    config()->set('laravel-eet-module.renewal.endpoint', $endpoint);
    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => match (true) {
        str_ends_with($request->url(), '/request/not-finished') => CaEetApi::notFinished(),
        str_ends_with($request->url(), '/request/renew') => CaEetApi::created(),
        default => CaEetApi::inProcess(1),
    });
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 0);
    $certificate = Renewal::certificate();

    expect(Eet::renewCertificate($certificate)?->status)->toBe(RenewalStatus::InProcess);
    Http::assertSent(fn (Request $request): bool => $request->url() === $endpoint.'/request/renew');

    $other = Renewal::certificate('renewal/CZ00000019-until-2026-12-05.pem');
    $exception = outsideOfTests(function () use ($other): ?InvalidConfiguration {
        try {
            Eet::renewCertificate($other);
        } catch (InvalidConfiguration $exception) {
            return $exception;
        }

        return null;
    });

    expect($exception?->key)->toBe('renewal.endpoint')
        ->and(CertificateRenewal::query()->where('certificate_id', $other->getKey())->count())->toBe(0);
});

it('always verifies TLS, requires TLS 1.2 and follows no redirects [R100]', function (): void {
    foreach (renewalRequestOptions() as $options) {
        $sslVersion = $options['curl'][CURLOPT_SSLVERSION] ?? null;
        $tlsMinimum = isset($options['crypto_method'])
            || (is_int($sslVersion) && in_array($sslVersion & 0xFFFF, [CURL_SSLVERSION_TLSv1_2, CURL_SSLVERSION_TLSv1_3], true));

        expect($options['verify'] ?? true)->not->toBeFalse()
            ->and($options['allow_redirects'] ?? null)->toBeFalse()
            ->and($tlsMinimum)->toBeTrue();
    }
});

it('limits every call by renewal.timeout_ms [R100]', function (int $timeoutMs, float $seconds): void {
    if ($timeoutMs !== 10000) {
        config()->set('laravel-eet-module.renewal.timeout_ms', $timeoutMs);
    }

    foreach (renewalRequestOptions() as $options) {
        expect((float) ($options['timeout'] ?? 0))->toBe($seconds);
    }
})->with([
    'default' => [10000, 10.0],
    'configured' => [2500, 2.5],
]);

it('uses the proxy from the configuration and ignores the proxy environment variables [R100]', function (): void {
    config()->set('laravel-eet-module.proxy', 'http://proxy.example:3128');
    putenv('HTTPS_PROXY=http://env-proxy.example:3128');
    $_SERVER['HTTPS_PROXY'] = 'http://env-proxy.example:3128';

    try {
        $all = renewalRequestOptions();
    } finally {
        putenv('HTTPS_PROXY');
        unset($_SERVER['HTTPS_PROXY']);
    }

    foreach ($all as $options) {
        expect(json_encode($options['proxy'] ?? null))->toContain('proxy.example:3128')
            ->and(json_encode($options['proxy'] ?? null))->not->toContain('env-proxy.example');
    }
});

it('uses no proxy from the environment without a configured proxy [R100]', function (): void {
    putenv('HTTPS_PROXY=http://env-proxy.example:3128');
    $_SERVER['HTTPS_PROXY'] = 'http://env-proxy.example:3128';

    try {
        $all = renewalRequestOptions();
    } finally {
        putenv('HTTPS_PROXY');
        unset($_SERVER['HTTPS_PROXY']);
    }

    foreach ($all as $options) {
        expect($options)->toHaveKey('proxy')
            ->and(json_encode($options['proxy']))->not->toContain('env-proxy.example');
    }
});

it('is not affected by global HTTP client options, headers and middleware of the host [R100]', function (): void {
    Http::globalOptions([
        'verify' => false,
        'allow_redirects' => true,
        'timeout' => 60,
        'proxy' => 'http://evil:8080',
        'headers' => ['X-Host' => '1', 'Accept' => 'text/html'],
    ]);
    Http::globalRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Global-Middleware', '1'));
    Http::globalMiddleware(Middleware::mapRequest(fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Mapped', '1')));
    $api = CaEetApi::fake();

    Eet::renewCertificate(Renewal::certificate());

    expect($api->count())->toBe(5);

    foreach ($api->log() as $entry) {
        expect($entry['options']['verify'] ?? true)->not->toBeFalse()
            ->and($entry['options']['allow_redirects'] ?? null)->toBeFalse()
            ->and((float) ($entry['options']['timeout'] ?? 0))->toBe(10.0)
            ->and(json_encode($entry['options']['proxy'] ?? null))->not->toContain('evil')
            ->and($entry['request']->hasHeader('X-Host'))->toBeFalse()
            ->and($entry['request']->hasHeader('X-Global-Middleware'))->toBeFalse()
            ->and($entry['request']->hasHeader('X-Mapped'))->toBeFalse()
            ->and($entry['request']->header('Accept'))->toBe(['application/json']);
    }
});

/**
 * Size of the status response body the HTTP client actually received.
 */
function recordedStatusBodyLength(): int
{
    $pair = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/status'))->first();

    if (! is_array($pair)) {
        throw new RuntimeException('No status request was recorded.');
    }

    return strlen($pair[1]->body());
}

it('treats a response larger than response_max_bytes as a temporary problem [R100, R101]', function (): void {
    config()->set('laravel-eet-module.response_max_bytes', 1024);
    $api = CaEetApi::fake(['status' => Http::response('{"reqId":"'.CaEetApi::REQ_ID.'","state":"ISSUED"'.str_repeat(' ', 1100).'}', 200, ['Content-Type' => 'application/json'])]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status)->toBe(RenewalStatus::InProcess)
        ->and($renewal?->problem)->toBe(RenewalProblem::TemporaryError)
        ->and($api->count('claim-download'))->toBe(0)
        ->and(recordedStatusBodyLength())->toBe(strlen('{"reqId":"'.CaEetApi::REQ_ID.'","state":"ISSUED"}') + 1100);
});

it('accepts a response of exactly response_max_bytes [R100]', function (): void {
    config()->set('laravel-eet-module.response_max_bytes', 1024);
    $body = '{"reqId":"'.CaEetApi::REQ_ID.'","state":"ISSUED"}';
    $body = substr($body, 0, -1).str_repeat(' ', 1024 - strlen($body)).'}';
    CaEetApi::fake(['status' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);
    $claim = CaEetApi::pkcs12();

    expect(strlen($body))->toBe(1024);

    // the PKCS#12 response itself is larger than 1024 B, so the download stays temporary
    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect(recordedStatusBodyLength())->toBe(1024)
        ->and($renewal?->status)->toBe(RenewalStatus::Downloading)
        ->and($renewal?->problem)->toBe(RenewalProblem::TemporaryError)
        ->and(strlen((string) $claim->wait()->getBody()))->toBeGreaterThan(1024);
});

/**
 * Records the HTTP client events by URL.
 *
 * @return Closure(): list<string>
 */
function recordHttpClientEvents(): Closure
{
    $events = [];
    Event::listen(
        [RequestSending::class, ResponseReceived::class, ConnectionFailed::class],
        function (object $event) use (&$events): void {
            $request = property_exists($event, 'request') ? $event->request : null;
            $events[] = $event::class.' '.($request instanceof Request ? $request->url() : '');
        },
    );

    return function () use (&$events): array {
        return $events;
    };
}

it('dispatches no HTTP client events for CA EET calls while the fake still answers and records them [R100, R103]', function (): void {
    $events = recordHttpClientEvents();
    $api = CaEetApi::fake();

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->status)->toBe(RenewalStatus::Completed)
        ->and($api->count())->toBe(5)
        ->and(Http::recorded())->toHaveCount(5)
        ->and($events())->toBe([]);
});

it('dispatches no ConnectionFailed event for a failed CA EET call [R100]', function (): void {
    $events = recordHttpClientEvents();
    CaEetApi::fake(['renew' => fn (Request $request): PromiseInterface => Create::rejectionFor(new ConnectException('cURL error 7: Failed to connect', $request->toPsrRequest()))]);

    $renewal = Eet::renewCertificate(Renewal::certificate());

    expect($renewal?->problem)->toBe(RenewalProblem::TemporaryError)
        ->and($events())->toBe([]);
});

it('still dispatches the HTTP client events when submitting a sale [R100, R66]', function (): void {
    $this->travelTo(frozenNow());
    $events = recordHttpClientEvents();
    importFixture(taxpayer());
    confirmingEetService();

    register(cashRegister());

    expect(count(array_filter($events(), fn (string $event): bool => str_starts_with($event, RequestSending::class))))->toBe(1)
        ->and(count(array_filter($events(), fn (string $event): bool => str_starts_with($event, ResponseReceived::class))))->toBe(1);
});
