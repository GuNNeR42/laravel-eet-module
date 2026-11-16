<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Jobs\ResubmitSale;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Gunner42\LaravelEetModule\Tests\Support\SoapMessage;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Http\Message\RequestInterface;

/*
 * The package sends through the Laravel HTTP client (R32), so the transport
 * is replaced with Http::fake(). The stub receives the Guzzle request options.
 */

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
});

/**
 * Registers a sale of a taxpayer with a certificate in the environment and
 * returns the request options seen by the HTTP client.
 *
 * @return array<string, mixed>
 */
function capturedOptions(Environment $environment = Environment::Playground): array
{
    $captured = [];
    importFixture(taxpayer('CZ00000019', $environment));
    fakeEetService(function (SoapMessage $message, Request $request, array $options) use (&$captured, $environment): EetResponse {
        $captured = $options;

        return EetResponse::error($message->uuid(), -1)->test($environment === Environment::Playground ? 'true' : null);
    });

    register(cashRegister('CZ00000019', $environment));

    return $captured;
}

/**
 * Registers a sale whose single attempt gets the given HTTP result.
 */
function saleWithHttpResult(Closure $result, Environment $environment = Environment::Playground): Sale
{
    importFixture(taxpayer('CZ00000019', $environment));
    Http::preventStrayRequests();
    Http::fake(fn (Request $request): PromiseInterface => $result(new SoapMessage($request->body()), $request));

    return register(cashRegister('CZ00000019', $environment));
}

/**
 * Expects InvalidConfiguration with the key before anything is stored or sent.
 */
/**
 * @param  array<string, mixed>  $config  laravel-eet-module keys set after the cash register exists
 */
function expectInvalidConfigurationOnRegister(string $key, array $config, Environment $environment = Environment::Production): void
{
    Http::preventStrayRequests();
    Http::fake();
    $cashRegister = cashRegister('CZ00000019', $environment);

    foreach ($config as $configKey => $value) {
        config()->set('laravel-eet-module.'.$configKey, $value);
    }

    try {
        register($cashRegister);

        throw new AssertionFailedError('InvalidConfiguration was not thrown.');
    } catch (InvalidConfiguration $exception) {
        expect($exception->key)->toBe($key);
    }

    expect(Sale::query()->count())->toBe(0);
    Http::assertNothingSent();
}

it('posts to the endpoint of the sale environment with the SOAP headers [R32]', function (string $environmentName, string $url): void {
    $environment = Environment::from($environmentName);
    importFixture(taxpayer('CZ00000019', $environment));
    failingEetService(-1, $environment);

    register(cashRegister('CZ00000019', $environment));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === $url
        && $request->header('Content-Type') === ['text/xml; charset=utf-8']
        && $request->header('SOAPAction') === ['http://fs.gov.cz/eet/OdeslaniTrzby']);
})->with([
    'playground' => ['playground', 'https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4'],
    'production' => ['production', 'https://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4'],
]);

it('limits the whole attempt by response_timeout_ms [R32]', function (): void {
    expect((float) (capturedOptions()['timeout'] ?? 0))->toBe(4.0);
});

it('uses a configured response timeout of at least 2000 ms [R32]', function (): void {
    config()->set('laravel-eet-module.response_timeout_ms', 2000);

    expect((float) (capturedOptions()['timeout'] ?? 0))->toBe(2.0);
});

it('rejects a response timeout below the legal minimum of 2000 ms [R32, R68]', function (): void {
    expectInvalidConfigurationOnRegister('response_timeout_ms', ['response_timeout_ms' => 1999], Environment::Playground);
});

it('always verifies the server TLS certificate, requires TLS 1.2 and follows no redirects [R32, R66]', function (): void {
    $options = capturedOptions();

    $sslVersion = $options['curl'][CURLOPT_SSLVERSION] ?? null;
    $tlsMinimum = isset($options['crypto_method'])
        || (is_int($sslVersion) && in_array($sslVersion & 0xFFFF, [CURL_SSLVERSION_TLSv1_2, CURL_SSLVERSION_TLSv1_3], true));

    expect($options['verify'] ?? true)->not->toBeFalse()
        ->and($options['allow_redirects'] ?? null)->toBeFalse()
        ->and($tlsMinimum)->toBeTrue();
});

it('sets transport options on the request so global client options of the host cannot override them [R66]', function (): void {
    Http::globalOptions(['verify' => false, 'allow_redirects' => true, 'timeout' => 60, 'proxy' => 'http://host-proxy.example:8080', 'decode_content' => true]);

    $options = capturedOptions();

    expect($options['verify'] ?? true)->not->toBeFalse()
        ->and($options['allow_redirects'] ?? null)->toBeFalse()
        ->and((float) ($options['timeout'] ?? 0))->toBe(4.0)
        ->and(json_encode($options['proxy'] ?? null))->not->toContain('host-proxy.example')
        ->and($options['decode_content'] ?? null)->toBeFalse();
});

it('is not affected by global HTTP client options, headers and curl options of the host [R66]', function (): void {
    Http::globalOptions([
        'verify' => false,
        'allow_redirects' => true,
        'proxy' => 'http://evil:8080',
        'headers' => ['Accept-Encoding' => 'gzip', 'X-Host' => '1'],
        'curl' => [CURLOPT_SSL_VERIFYPEER => false],
    ]);

    $options = capturedOptions();
    $curl = is_array($options['curl'] ?? null) ? $options['curl'] : [];

    expect($options['verify'] ?? true)->not->toBeFalse()
        ->and($options['allow_redirects'] ?? null)->toBeFalse()
        ->and(json_encode($options['proxy'] ?? null))->not->toContain('evil')
        ->and($curl[CURLOPT_SSL_VERIFYPEER] ?? true)->not->toBeFalse();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Accept-Encoding')
        && ! $request->hasHeader('X-Host'));
});

it('opens a new connection for every attempt and disables compression [R66]', function (): void {
    $options = capturedOptions();

    Http::assertSent(fn (Request $request): bool => $request->header('Connection') === ['close']
        && ! $request->hasHeader('Accept-Encoding'));
    expect($options['decode_content'] ?? null)->toBeFalse();
});

it('ignores the HTTPS_PROXY environment variable [R66]', function (): void {
    putenv('HTTPS_PROXY=http://env-proxy.example:3128');
    putenv('HTTP_PROXY=http://env-proxy.example:3128');
    $_SERVER['HTTPS_PROXY'] = 'http://env-proxy.example:3128';

    try {
        $options = capturedOptions();
    } finally {
        putenv('HTTPS_PROXY');
        putenv('HTTP_PROXY');
        unset($_SERVER['HTTPS_PROXY']);
    }

    expect($options)->toHaveKey('proxy')
        ->and(json_encode($options['proxy']))->not->toContain('env-proxy.example');
});

it('uses the proxy from the configuration [R66]', function (): void {
    config()->set('laravel-eet-module.proxy', 'http://proxy.example:3128');

    expect(json_encode(capturedOptions()['proxy'] ?? null))->toContain('proxy.example:3128');
});

it('rejects a proxy that is not an http or https URL [R66, R68]', function (): void {
    expectInvalidConfigurationOnRegister('proxy', ['proxy' => 'socks5://proxy.example:1080']);
});

it('treats a response that did not arrive within the response timeout as Timeout [R32, R33, R71]', function (): void {
    $sale = saleWithHttpResult(fn (SoapMessage $message, Request $request): PromiseInterface => Create::rejectionFor(new ConnectException(
        'cURL error 28: Operation timed out after 4001 milliseconds with 0 bytes received',
        $request->toPsrRequest(),
        null,
        ['errno' => 28],
    )));

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::Timeout)
        ->and($sale->status)->toBe(SaleStatus::Unconfirmed);
});

it('treats a connection error as TransportError and stores only the category [R66, R33]', function (): void {
    $sale = saleWithHttpResult(fn (SoapMessage $message, Request $request): PromiseInterface => Create::rejectionFor(new ConnectException(
        'cURL error 7: Failed to connect to proxy http://user:proxy-secret@proxy.example:3128',
        $request->toPsrRequest(),
    )));

    $attempt = lastAttempt($sale);

    expect($attempt->outcome)->toBe(AttemptOutcome::TransportError)
        ->and($attempt->error_message)->toBe('connection')
        ->and(json_encode($attempt->toArray()))->not->toContain('proxy-secret')
        ->and($sale->status)->toBe(SaleStatus::Unconfirmed);
});

it('never lets a transport exception escape register() and stores it as TransportError [R33, R66]', function (Closure $failure): void {
    $sale = saleWithHttpResult($failure);

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::TransportError)
        ->and(lastAttempt($sale)->error_message)->toBe('connection')
        ->and(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed);
    Queue::assertPushed(ResubmitSale::class);
})->with([
    'RequestException' => function (SoapMessage $message, Request $request): PromiseInterface {
        return Create::rejectionFor(new RequestException('Unknown transfer failure with secret details', $request->toPsrRequest()));
    },
]);

it('lets an exception that is not a transfer error propagate, stores no attempt and leaves the sale Pending [R33, R28]', function (string $exceptionClass, Closure $failure): void {
    importFixture(taxpayer());
    Http::preventStrayRequests();
    Http::fake($failure);
    $cashRegister = cashRegister();

    expect(fn (): Sale => register($cashRegister, saleData('77')))->toThrow($exceptionClass);

    $sale = Sale::query()->where('sequence_number', '77')->firstOrFail();

    expect($sale->status)->toBe(SaleStatus::Pending)
        ->and(attemptsOf($sale))->toHaveCount(0);
    Queue::assertNothingPushed();
})->with([
    'RuntimeException' => [RuntimeException::class, function (Request $request): PromiseInterface {
        throw new RuntimeException('Handler exploded');
    }],
    'InvalidArgumentException' => [InvalidArgumentException::class, function (Request $request): PromiseInterface {
        throw new InvalidArgumentException('Invalid cURL option');
    }],
]);

it('builds the request without global middleware of the host [R66]', function (): void {
    $captured = [];
    Http::globalMiddleware(Middleware::mapRequest(fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Global-Middleware', '1')->withHeader('Accept-Encoding', 'gzip')));
    Http::globalMiddleware(function (callable $handler): callable {
        return fn (RequestInterface $request, array $options): mixed => $handler($request, ['verify' => false, 'allow_redirects' => true] + $options);
    });
    Http::globalRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Host', '1'));
    importFixture(taxpayer());
    fakeEetService(function (SoapMessage $message, Request $request, array $options) use (&$captured): EetResponse {
        $captured = ['request' => $request, 'options' => $options];

        return EetResponse::error($message->uuid(), -1)->test('true');
    });

    register(cashRegister());

    /** @var Request $request */
    $request = $captured['request'];

    expect($request->hasHeader('X-Global-Middleware'))->toBeFalse()
        ->and($request->hasHeader('X-Host'))->toBeFalse()
        ->and($request->hasHeader('Accept-Encoding'))->toBeFalse()
        ->and($captured['options']['verify'] ?? true)->not->toBeFalse()
        ->and($captured['options']['allow_redirects'] ?? null)->toBeFalse();
});

it('treats an HTTP 500 response without a readable Odpoved as TransportError [R33, R66]', function (): void {
    $sale = saleWithHttpResult(fn (SoapMessage $message, Request $request): PromiseInterface => Http::response('<html><body>Internal Server Error</body></html>', 500));

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::TransportError)
        ->and(lastAttempt($sale)->http_status)->toBe(500)
        ->and(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed);
    Queue::assertPushed(ResubmitSale::class);
});

it('does not follow redirects and treats 3xx as TransportError [R66]', function (int $status): void {
    $sale = saleWithHttpResult(fn (SoapMessage $message): PromiseInterface => Http::response(
        EetResponse::error($message->uuid(), -1)->test('true')->toXml(),
        $status,
        ['Location' => 'https://evil.example/eet'],
    ));

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::TransportError)
        ->and(lastAttempt($sale)->error_message)->toBe('redirect')
        ->and(lastAttempt($sale)->http_status)->toBe($status);
    Http::assertSentCount(1);
})->with([301, 302, 307]);

it('treats a response body larger than 64 KiB as TransportError, also without Content-Length [R66]', function (): void {
    $sale = saleWithHttpResult(function (SoapMessage $message): PromiseInterface {
        $xml = EetResponse::error($message->uuid(), -1)->test('true')->toXml();
        $padded = str_replace('</soapenv:Envelope>', str_repeat(' ', 65537 - strlen($xml)).'</soapenv:Envelope>', $xml);

        return Http::response($padded, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    });

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::TransportError)
        ->and(lastAttempt($sale)->error_message)->toBe('too_large');
});

it('accepts a response body of exactly response_max_bytes [R66]', function (): void {
    $sale = saleWithHttpResult(function (SoapMessage $message): PromiseInterface {
        $xml = EetResponse::error($message->uuid(), -1)->test('true')->toXml();
        $padded = str_replace('</soapenv:Envelope>', str_repeat(' ', 65536 - strlen($xml)).'</soapenv:Envelope>', $xml);

        return Http::response($padded, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    });

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::TemporaryError);
});

it('uses a configured response_max_bytes [R66]', function (): void {
    config()->set('laravel-eet-module.response_max_bytes', 1024);

    $sale = saleWithHttpResult(function (SoapMessage $message): PromiseInterface {
        $xml = EetResponse::error($message->uuid(), -1)->test('true')->toXml();

        return Http::response(str_replace('</soapenv:Envelope>', str_repeat(' ', 1025 - strlen($xml)).'</soapenv:Envelope>', $xml), 200);
    });

    expect(lastAttempt($sale)->error_message)->toBe('too_large');
});

it('rejects response_max_bytes below 1024 [R66, R68]', function (): void {
    expectInvalidConfigurationOnRegister('response_max_bytes', ['response_max_bytes' => 1023]);
});

it('treats a response with Content-Encoding other than identity as TransportError [R65, R66]', function (): void {
    $sale = saleWithHttpResult(fn (SoapMessage $message): PromiseInterface => Http::response(
        (string) gzencode(EetResponse::error($message->uuid(), -1)->test('true')->toXml()),
        200,
        ['Content-Type' => 'text/xml; charset=UTF-8', 'Content-Encoding' => 'gzip'],
    ));

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::TransportError)
        ->and(lastAttempt($sale)->error_message)->toBe('content_encoding');
});

it('accepts Content-Encoding identity [R65]', function (): void {
    $sale = saleWithHttpResult(fn (SoapMessage $message): PromiseInterface => Http::response(
        EetResponse::error($message->uuid(), -1)->test('true')->toXml(),
        200,
        ['Content-Type' => 'text/xml; charset=UTF-8', 'Content-Encoding' => 'identity'],
    ));

    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::TemporaryError);
});

it('rejects an endpoint that is not https, uses an IP address, userinfo, another port or a host outside the allow-list [R66, R68]', function (string $endpoint): void {
    expectInvalidConfigurationOnRegister('endpoints.production', ['endpoints.production' => $endpoint]);
})->with([
    'plain http' => 'http://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4',
    'IPv4 address' => 'https://10.0.0.1/eet/services/EETServiceSOAP/v4',
    'decimal IPv4 address' => 'https://167772161/eet/services/EETServiceSOAP/v4',
    'hexadecimal IPv4 address' => 'https://0x0a000001/eet/services/EETServiceSOAP/v4',
    'IPv6 address' => 'https://[::1]/eet/services/EETServiceSOAP/v4',
    'foreign host' => 'https://evil.example/eet/services/EETServiceSOAP/v4',
    'suffix of an allowed host' => 'https://eviltrzbyeet.gov.cz/eet/services/EETServiceSOAP/v4',
    'subdomain of an allowed host' => 'https://x.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4',
    'userinfo' => 'https://user@trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4',
    'other port' => 'https://trzbyeet.gov.cz:8443/eet/services/EETServiceSOAP/v4',
    'playground host for production' => 'https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4',
]);

it('rejects an invalid playground endpoint with its key [R66, R68]', function (): void {
    expectInvalidConfigurationOnRegister('endpoints.playground', ['endpoints.playground' => 'https://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4'], Environment::Playground);
});

it('accepts an allowed host in any letter case, with a trailing dot and port 443 [R66]', function (string $endpoint): void {
    config()->set('laravel-eet-module.endpoints.production', $endpoint);

    capturedOptions(Environment::Production);

    Http::assertSentCount(1);
})->with([
    'upper case' => 'https://TRZBYEET.GOV.CZ/eet/services/EETServiceSOAP/v4',
    'explicit port 443' => 'https://trzbyeet.gov.cz:443/eet/services/EETServiceSOAP/v4',
    'trailing dot' => 'https://trzbyeet.gov.cz./eet/services/EETServiceSOAP/v4',
]);

it('uses a configured allow-list of hosts [R66]', function (): void {
    config()->set('laravel-eet-module.endpoints.allowed_hosts.production', ['eet.example.cz']);
    config()->set('laravel-eet-module.endpoints.production', 'https://eet.example.cz/eet/services/EETServiceSOAP/v4');

    capturedOptions(Environment::Production);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://eet.example.cz/eet/services/EETServiceSOAP/v4');
});

it('rejects an IP address or an empty list in the allow-list [R66, R68]', function (mixed $hosts): void {
    expectInvalidConfigurationOnRegister('endpoints.allowed_hosts.production', ['endpoints.allowed_hosts.production' => $hosts]);
})->with([
    'IPv4' => [['10.0.0.1']],
    'IPv4 next to a name' => [['trzbyeet.gov.cz', '10.0.0.1']],
    'IPv6' => [['::1']],
    'empty' => [[]],
]);
