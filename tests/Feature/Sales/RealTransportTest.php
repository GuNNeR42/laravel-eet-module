<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Tests\Support\LocalEetServer;
use GuzzleHttp\Middleware;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Message\RequestInterface;

/*
 * Integration tests through the REAL cURL handler, without Http::fake()
 * (Q42 → A). A local PHP built-in server on 127.0.0.1 plays the EET service
 * and answers with a confirmation signed by the own test CA for the received
 * message UUID. Endpoints http://127.0.0.1:<port>/… are allowed only while
 * unit tests run (R66).
 */

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
    useTestResponseCa();

    $this->server = LocalEetServer::start();
    config()->set('laravel-eet-module.endpoints.playground', $this->server->endpoint());
    importFixture(taxpayer(), 'playground/CZ00000019.pem');
});

afterEach(function (): void {
    if (isset($this->server) && $this->server instanceof LocalEetServer) {
        $this->server->stop();
    }
});

/**
 * @param  array<string, mixed>  $scenario
 */
function registerAgainstLocalServer(LocalEetServer $server, array $scenario): Sale
{
    $server->writeScenario([
        'receivedAt' => frozenNow()->setTimezone('Europe/Prague')->format('Y-m-d\TH:i:sP'),
        ...$scenario,
    ]);

    return register(cashRegister());
}

it('confirms a sale through the real cURL handler [R32, R33, R35]', function (): void {
    $sale = registerAgainstLocalServer($this->server, ['type' => 'confirmed']);

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::Confirmed)
        ->and(freshSale($sale)->status)->toBe(SaleStatus::Confirmed)
        ->and(freshSale($sale)->confirmation_code)->toEndWith('-ff')
        ->and(lastAttempt($sale)->global_transaction_id)->toBe('local-server-1')
        ->and(lastAttempt($sale)->http_status)->toBe(200)
        ->and($this->server->requests())->toHaveCount(1);
});

it('sends the SOAP headers, no Accept-Encoding and none of the host global HTTP configuration [R32, R66]', function (): void {
    Http::globalOptions(['headers' => ['X-Host' => '1', 'Accept-Encoding' => 'gzip'], 'decode_content' => true]);
    Http::globalMiddleware(Middleware::mapRequest(fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Global-Middleware', '1')));
    Http::globalRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Global-Request', '1')->withHeader('Accept-Encoding', 'gzip'));

    $sale = registerAgainstLocalServer($this->server, ['type' => 'confirmed']);
    $headers = $this->server->headerNames();
    $received = array_change_key_case($this->server->requests()[0]['headers'] ?? [], CASE_LOWER);

    expect(freshSale($sale)->status)->toBe(SaleStatus::Confirmed)
        ->and($headers)->not->toContain('x-host')
        ->and($headers)->not->toContain('x-global-middleware')
        ->and($headers)->not->toContain('x-global-request')
        ->and($headers)->not->toContain('accept-encoding')
        ->and($received['soapaction'] ?? null)->toBe('http://fs.gov.cz/eet/OdeslaniTrzby')
        ->and($received['content-type'] ?? null)->toBe('text/xml; charset=utf-8');
});

it('treats a body larger than response_max_bytes with Content-Length as too_large [R66]', function (): void {
    $sale = registerAgainstLocalServer($this->server, ['type' => 'too_large_length', 'size' => 70000]);

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::TransportError)
        ->and(lastAttempt($sale)->error_message)->toBe('too_large')
        ->and(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed);
});

it('treats a chunked body larger than response_max_bytes without Content-Length as too_large and stops reading [R66]', function (): void {
    $started = microtime(true);

    $sale = registerAgainstLocalServer($this->server, ['type' => 'too_large_chunked']);

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::TransportError)
        ->and(lastAttempt($sale)->error_message)->toBe('too_large')
        ->and(microtime(true) - $started)->toBeLessThan(3.0);
});

it('treats an oversized HTTP 500 response as too_large [R66]', function (): void {
    $sale = registerAgainstLocalServer($this->server, ['type' => 'too_large_500', 'size' => 70000]);

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::TransportError)
        ->and(lastAttempt($sale)->error_message)->toBe('too_large');
});

it('does not follow a redirect [R66]', function (): void {
    $sale = registerAgainstLocalServer($this->server, ['type' => 'redirect']);

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::TransportError)
        ->and(lastAttempt($sale)->error_message)->toBe('redirect')
        ->and(lastAttempt($sale)->http_status)->toBe(302)
        ->and($this->server->requests())->toHaveCount(1);
});

it('ends with Timeout when the server answers after response_timeout_ms [R32, R33]', function (): void {
    config()->set('laravel-eet-module.response_timeout_ms', 2000);
    $started = microtime(true);

    $sale = registerAgainstLocalServer($this->server, ['type' => 'sleep', 'sleepMs' => 3500]);

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::Timeout)
        ->and(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed)
        ->and(microtime(true) - $started)->toBeLessThan(3.4);
});

it('ends with Timeout when the response stalls after the headers and part of the body, regardless of the HTTP status [R32, R33, R71]', function (int $status): void {
    config()->set('laravel-eet-module.response_timeout_ms', 2000);
    $started = microtime(true);

    $sale = registerAgainstLocalServer($this->server, ['type' => 'partial_stall', 'status' => $status, 'sleepMs' => 3500]);

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::Timeout)
        ->and(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed)
        ->and(microtime(true) - $started)->toBeLessThan(3.4);
})->with(['HTTP 500' => 500, 'HTTP 200' => 200]);

it('rejects a gzip-encoded response as content_encoding [R65, R66]', function (): void {
    $sale = registerAgainstLocalServer($this->server, ['type' => 'gzip']);

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::TransportError)
        ->and(lastAttempt($sale)->error_message)->toBe('content_encoding');
});

it('rejects a loopback endpoint outside of tests [R66, R68]', function (): void {
    config()->set('laravel-eet-module.lock_store', 'file');
    config()->set('laravel-eet-module.resubmission.connection', 'database');
    $cashRegister = cashRegister();

    $exception = outsideOfTests(function () use ($cashRegister): ?InvalidConfiguration {
        try {
            register($cashRegister);

            return null;
        } catch (InvalidConfiguration $exception) {
            return $exception;
        }
    });

    expect($exception?->key)->toBe('endpoints.playground')
        ->and(Sale::query()->count())->toBe(0)
        ->and($this->server->requests())->toBe([]);
});

it('allows only http://127.0.0.1 as a plain-http endpoint in tests [R66, R68]', function (string $endpoint): void {
    $cashRegister = cashRegister();
    config()->set('laravel-eet-module.endpoints.playground', $endpoint);

    expect(fn (): Sale => register($cashRegister))->toThrow(InvalidConfiguration::class)
        ->and(Sale::query()->count())->toBe(0);
})->with([
    'localhost' => 'http://localhost:8080/eet/services/EETServiceSOAP/v4',
    'other private address' => 'http://10.0.0.1:8080/eet/services/EETServiceSOAP/v4',
    'IPv6 loopback' => 'http://[::1]:8080/eet/services/EETServiceSOAP/v4',
]);
