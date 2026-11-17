<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Tests\Support;

use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A scripted fake of the CA EET API (spec/12, caeetapi_jwt.yml) on the
 * boundary of the Laravel HTTP client (R100: the package calls the API
 * through Illuminate\Support\Facades\Http, so Http::fake() replaces it).
 *
 * Every route answers with a sequence of responses; the last one repeats.
 * A request to an unknown route is a stray request and fails the test.
 *
 * This is test support code, not package code.
 */
final class CaEetApi
{
    public const ENDPOINT = 'https://ca.caeet.gov.cz/api';

    /** Password of the PKCS#12 files in tests/fixtures/renewal (^[A-Za-z0-9]{8}$). */
    public const PASSWORD = 'Renew123';

    public const REQ_ID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';

    public const OTHER_REQ_ID = '7d1e0c2b-9a4f-4e6d-8c3b-5a2f1e0d9c8b';

    public const UNKNOWN_REQ_ID = 'c0ffee00-1234-4abc-9def-0123456789ab';

    /** Moment the renewal tests freeze time to: the renewed certificate expires in exactly 21 days. */
    public const NOW = '2026-11-19T00:00:00Z';

    /** The renewed certificate: production policy, valid 2025-12-10 – 2026-12-10 00:00 UTC. */
    public const RENEWED = 'renewal/CZ00000019-until-2026-12-10.pem';

    /** Its successor as returned by claim-download (modern PKCS#12). */
    public const SUCCESSOR = 'renewal/successor.p12';

    /** Decimal serial number and validity of the successor. */
    public const SUCCESSOR_SN = '24961511666821687692';

    public const SUCCESSOR_VALID_FROM = '2026-11-18 00:00:00+00:00';

    public const SUCCESSOR_VALID_TO = '2027-11-19 00:00:00+00:00';

    public const ROUTES = ['not-finished', 'renew', 'status', 'claim-download', 'ack-download'];

    /** @var array<string, list<PromiseInterface|Closure(Request, array<string, mixed>): PromiseInterface>> */
    private array $routes = [];

    /** @var list<array{route: string, request: Request, options: array<string, mixed>, now: CarbonImmutable}> */
    private array $log = [];

    /** @var list<Closure(string, Request): void> */
    private array $observers = [];

    private bool $echoStatusReqId = true;

    private function __construct(private readonly string $endpoint) {}

    /**
     * Registers the fake. Without routes, the API answers the happy path:
     * nothing pending, request created, issued, PKCS#12 of the successor, ack.
     *
     * @param  array<string, PromiseInterface|Closure(Request, array<string, mixed>): PromiseInterface|list<PromiseInterface|Closure(Request, array<string, mixed>): PromiseInterface>>  $routes
     */
    public static function fake(array $routes = [], string $endpoint = self::ENDPOINT): self
    {
        $api = new self((string) preg_replace('#^(https://[^/:]+):443(?=/|$)#i', '$1', rtrim($endpoint, '/')));

        foreach (array_merge(self::happyPath(), $routes) as $route => $responses) {
            $api->on($route, ...(is_array($responses) ? $responses : [$responses]));
        }

        Http::preventStrayRequests();
        Http::fake(fn (Request $request, array $options): ?PromiseInterface => $api->handle($request, $options));

        return $api;
    }

    /**
     * @return array<string, PromiseInterface>
     */
    public static function happyPath(): array
    {
        return [
            'not-finished' => self::notFinished(),
            'renew' => self::created(),
            'status' => self::issued(),
            'claim-download' => self::pkcs12(),
            'ack-download' => self::acknowledged(),
        ];
    }

    /**
     * Replaces the responses of a route (the last one repeats).
     *
     * @param  PromiseInterface|Closure(Request, array<string, mixed>): PromiseInterface  ...$responses
     */
    public function on(string $route, PromiseInterface|Closure ...$responses): self
    {
        if (! in_array($route, self::ROUTES, true)) {
            throw new RuntimeException("Unknown CA EET API route [{$route}].");
        }

        $this->routes[$route] = array_values($responses);

        return $this;
    }

    /**
     * Runs the callback before a request of the route is answered.
     *
     * @param  Closure(string, Request): void  $observer
     */
    public function observe(Closure $observer): self
    {
        $this->observers[] = $observer;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function handle(Request $request, array $options): ?PromiseInterface
    {
        $route = $this->routeOf($request);

        if ($route === null) {
            return null;
        }

        $this->log[] = ['route' => $route, 'request' => $request, 'options' => $options, 'now' => CarbonImmutable::now()];

        foreach ($this->observers as $observer) {
            $observer($route, $request);
        }

        $responses = $this->routes[$route] ?? [];

        if ($responses === []) {
            throw new RuntimeException("No response scripted for the CA EET API route [{$route}].");
        }

        $response = count($responses) > 1 ? array_shift($this->routes[$route]) : $responses[0];

        $promise = $response instanceof Closure ? $response($request, $options) : $response;

        if ($promise->getState() !== PromiseInterface::FULFILLED) {
            return $promise;
        }

        $result = $promise->wait();

        // the same scripted response may answer several requests
        if ($result instanceof ResponseInterface && $result->getBody()->isSeekable()) {
            $result->getBody()->rewind();
        }

        // a status answers for the request in the path, as the CA does
        if ($route === 'status' && $this->echoStatusReqId && $result instanceof ResponseInterface) {
            $result = $this->withReqIdOf($request, $result);
        }

        return Create::promiseFor($result);
    }

    private function withReqIdOf(Request $request, ResponseInterface $response): ResponseInterface
    {
        $body = (string) $response->getBody();

        if ($response->getBody()->isSeekable()) {
            $response->getBody()->rewind();
        }

        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['reqId']) || preg_match('#/request/([^/]+)/status$#', $request->url(), $match) !== 1) {
            return $response;
        }

        // keep the scripted body byte for byte when it already answers for this request
        if ($data['reqId'] === $match[1]) {
            return $response;
        }

        $data['reqId'] = $match[1];

        return $response->withBody(Utils::streamFor(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    }

    public function routeOf(Request $request): ?string
    {
        // an explicit default port (https://host:443/…) addresses the same endpoint
        $url = (string) preg_replace('#^(https://[^/:]+):443(?=/|$)#i', '$1', $request->url());

        if (! str_starts_with($url, $this->endpoint.'/')) {
            return null;
        }

        $path = substr($url, strlen($this->endpoint));
        $uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

        return match (true) {
            $request->method() === 'GET' && $path === '/request/not-finished' => 'not-finished',
            $request->method() === 'POST' && $path === '/request/renew' => 'renew',
            $request->method() === 'GET' && preg_match("#^/request/{$uuid}/status$#", $path) === 1 => 'status',
            $request->method() === 'POST' && preg_match("#^/request/{$uuid}/claim-download$#", $path) === 1 => 'claim-download',
            $request->method() === 'POST' && preg_match("#^/request/{$uuid}/ack-download$#", $path) === 1 => 'ack-download',
            default => null,
        };
    }

    /**
     * Routes of the answered requests, in order.
     *
     * @return list<string>
     */
    public function calls(): array
    {
        return array_map(fn (array $entry): string => $entry['route'], $this->log);
    }

    /**
     * "METHOD /path" of the answered requests, in order.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(
            fn (array $entry): string => $entry['request']->method().' '.substr($entry['request']->url(), strlen($this->endpoint)),
            $this->log,
        );
    }

    public function count(?string $route = null): int
    {
        return $route === null ? count($this->log) : count(array_filter($this->calls(), fn (string $call): bool => $call === $route));
    }

    /**
     * @return list<array{route: string, request: Request, options: array<string, mixed>, now: CarbonImmutable}>
     */
    public function log(): array
    {
        return $this->log;
    }

    /** Status responses keep their reqId even when it differs from the path. */
    public function verbatimStatus(): self
    {
        $this->echoStatusReqId = false;

        return $this;
    }

    /** Forgets the answered requests (the scripted responses stay). */
    public function forget(): self
    {
        $this->log = [];

        return $this;
    }

    // -----------------------------------------------------------------------
    // Responses (caeetapi_jwt.yml)
    // -----------------------------------------------------------------------

    /**
     * ReqIdListDTO of GET /request/not-finished.
     */
    public static function notFinished(string ...$reqIds): PromiseInterface
    {
        return Http::response(['count' => count($reqIds), 'data' => array_values($reqIds)], 200, ['Content-Type' => 'application/json']);
    }

    /**
     * RequestResDTO of POST /request/renew (201).
     */
    public static function created(string $reqId = self::REQ_ID): PromiseInterface
    {
        return Http::response(['reqId' => $reqId], 201, ['Content-Type' => 'application/json']);
    }

    public static function inProcess(int $pollAfterSeconds = 2, string $reqId = self::REQ_ID, ?int $retryAfter = null): PromiseInterface
    {
        return Http::response(
            ['reqId' => $reqId, 'state' => 'INPROCESS', 'pollAfterSeconds' => $pollAfterSeconds],
            200,
            ['Content-Type' => 'application/json', 'Retry-After' => (string) ($retryAfter ?? $pollAfterSeconds)],
        );
    }

    public static function issued(string $reqId = self::REQ_ID): PromiseInterface
    {
        return self::state('ISSUED', $reqId);
    }

    public static function delivering(string $reqId = self::REQ_ID): PromiseInterface
    {
        return self::state('DELIVERING', $reqId);
    }

    public static function finished(string $reqId = self::REQ_ID): PromiseInterface
    {
        return self::state('FINISHED', $reqId);
    }

    public static function rejected(string $reason = 'Selhání kontroly parametrů žádosti', string $reqId = self::REQ_ID): PromiseInterface
    {
        return Http::response(['reqId' => $reqId, 'state' => 'REJECTED', 'rejectedReason' => $reason], 200, ['Content-Type' => 'application/json']);
    }

    public static function state(string $state, string $reqId = self::REQ_ID): PromiseInterface
    {
        return Http::response(['reqId' => $reqId, 'state' => $state], 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Pkcs12DTO of POST /request/{reqId}/claim-download.
     *
     * @param  array<string, string>  $certificate  overrides of the CertificateDTO
     */
    public static function pkcs12(
        string $fixture = self::SUCCESSOR,
        string $password = self::PASSWORD,
        array $certificate = [],
        ?string $pkcs12 = null,
    ): PromiseInterface {
        return Http::response([
            'pkcs12' => $pkcs12 ?? base64_encode(Fixtures::read($fixture)),
            'password' => $password,
            'certificate' => $certificate + [
                'sn' => self::SUCCESSOR_SN,
                'validFrom' => self::SUCCESSOR_VALID_FROM,
                'validTo' => self::SUCCESSOR_VALID_TO,
                'state' => 'VALID',
            ],
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Metadata (CertificateDTO) of a certificate inside a fixture PKCS#12.
     *
     * @return array{sn: string, validFrom: string, validTo: string, state: string}
     */
    public static function metadataOf(string $fixture): array
    {
        $parsed = openssl_x509_parse(self::certificateOf($fixture));

        if (! is_array($parsed)) {
            throw new RuntimeException("Fixture [{$fixture}] contains no readable certificate.");
        }

        return [
            'sn' => (string) $parsed['serialNumber'],
            'validFrom' => CarbonImmutable::createFromTimestampUTC((int) $parsed['validFrom_time_t'])->format('Y-m-d H:i:sP'),
            'validTo' => CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t'])->format('Y-m-d H:i:sP'),
            'state' => 'VALID',
        ];
    }

    /**
     * The certificate (PEM) inside a modern fixture PKCS#12.
     */
    public static function certificateOf(string $fixture): string
    {
        $contents = [];

        if (! openssl_pkcs12_read(Fixtures::read($fixture), $contents, self::PASSWORD)) {
            throw new RuntimeException("Fixture [{$fixture}] cannot be read.");
        }

        $certificate = $contents['cert'] ?? ($contents['extracerts'][0] ?? null);

        if (! is_string($certificate)) {
            throw new RuntimeException("Fixture [{$fixture}] contains no certificate.");
        }

        return $certificate;
    }

    public static function fingerprintOf(string $fixture): string
    {
        return strtolower((string) openssl_x509_fingerprint(self::certificateOf($fixture), 'sha256'));
    }

    public static function acknowledged(): PromiseInterface
    {
        return Http::response('', 204);
    }

    /**
     * ErrorDTO with the HTTP status.
     */
    public static function error(int $status, int $code = 99, string $message = 'Internal error.', string $additionalInfo = 'Some more info.'): PromiseInterface
    {
        return Http::response(['code' => $code, 'message' => $message, 'additionalInfo' => $additionalInfo], $status, ['Content-Type' => 'application/json']);
    }

    // -----------------------------------------------------------------------
    // JWT (R99)
    // -----------------------------------------------------------------------

    /**
     * The bearer token of a request, or null without one.
     */
    public static function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (count($header) !== 1 || ! str_starts_with($header[0], 'Bearer ')) {
            return null;
        }

        return substr($header[0], strlen('Bearer '));
    }

    public static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('Not base64url.');
        }

        return $decoded;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * base64url(SHA-256(DER)) of a PEM certificate (x5t#S256).
     */
    public static function x5tS256(string $certificatePem): string
    {
        $base64 = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $certificatePem);

        return self::base64UrlEncode(hash('sha256', (string) base64_decode($base64, true), true));
    }
}
