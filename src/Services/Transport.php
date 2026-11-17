<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Gunner42\LaravelEetModule\Data\TransportResult;
use Gunner42\LaravelEetModule\Exceptions\ResponseTooLarge;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use Throwable;

/**
 * Sends a data message (R32, R66) or calls the CA EET API (R100) through
 * the Laravel HTTP client. The
 * request is built without the global options of the host, so only the
 * options below apply; Http::fake() stubs and recording keep working.
 * Exception texts are never stored; only a category is returned. Only
 * transfer errors become an outcome; every other exception propagates.
 */
final class Transport
{
    private const array TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91, 98];

    private const int TIMEOUT_ERROR = 28;

    public function send(string $url, string $xml): TransportResult
    {
        return $this->exchange(
            'POST',
            $url,
            ['SOAPAction' => 'http://fs.gov.cz/eet/OdeslaniTrzby', 'Connection' => 'close'],
            $xml,
            ConfigurationValidator::integer('response_timeout_ms'),
            true,
        );
    }

    /**
     * A request without a body that accepts JSON, e.g. to the CA EET API
     * (R100). The same transport rules apply as to the data messages (R66),
     * but no HTTP client events are dispatched, because they would expose the
     * token and the response to host listeners (R103, Q70).
     */
    public function requestJson(string $method, string $url, #[SensitiveParameter] string $bearerToken, int $timeoutMs): TransportResult
    {
        return $this->exchange(
            $method,
            $url,
            ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$bearerToken],
            null,
            $timeoutMs,
            false,
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function exchange(string $method, string $url, #[SensitiveParameter] array $headers, ?string $body, int $timeoutMs, bool $dispatchEvents): TransportResult
    {
        $maxBytes = $this->maxBytes();
        $proxy = config('laravel-eet-module.proxy');
        $tooLarge = false;
        $timedOut = false;
        $abort = static function () use (&$tooLarge): void {
            $tooLarge = true;

            throw new ResponseTooLarge;
        };

        $options = [
            // seconds as float is the Guzzle API; the float ban of AGENTS.md covers money and quantities
            'timeout' => $timeoutMs / 1000,
            'verify' => true,
            'allow_redirects' => false,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            // an empty proxy disables every proxy, including HTTP(S)_PROXY from the environment
            'proxy' => is_string($proxy) ? $proxy : '',
            'decode_content' => false,
            'http_errors' => false,
            'on_headers' => static function (ResponseInterface $response) use ($maxBytes, $abort): void {
                $length = $response->getHeaderLine('Content-Length');

                if ($length !== '' && ctype_digit($length) && (int) $length > $maxBytes) {
                    $abort();
                }
            },
            // the timeout is taken from the transfer stats, because the HTTP client replaces the
            // timeout exception of a response with status >= 400 by its own (R71 step 1)
            'on_stats' => static function (TransferStats $stats) use (&$timedOut): void {
                $timedOut = $timedOut || self::isTimeout($stats->getHandlerErrorData());
            },
        ];

        // the sink stops accepting data past the limit, which aborts the transfer (Guzzle 7 and 8)
        [$sink, $sinkId] = BoundedSink::open($maxBytes);
        $options['sink'] = $sink;

        try {
            $request = $this->pendingRequest($options, $dispatchEvents)->withHeaders($headers);
            $response = $body === null
                ? $request->send($method, $url)
                : $request->withBody($body, 'text/xml; charset=utf-8')->post($url);
        } catch (TransferException|HttpClientException|ResponseTooLarge $exception) {
            // only real transfer errors become an outcome; anything else propagates (R33)
            if ($tooLarge || BoundedSink::exceeded($sinkId)) {
                return TransportResult::failure('too_large');
            }

            return $timedOut ? TransportResult::timeout() : $this->failure($exception);
        } finally {
            $exceeded = BoundedSink::exceeded($sinkId);
            BoundedSink::forget($sinkId);
        }

        if ($tooLarge || $exceeded) {
            return TransportResult::failure('too_large', $response->status());
        }

        if ($timedOut) {
            return TransportResult::timeout();
        }

        $responseHeaders = [];

        foreach ($response->headers() as $name => $values) {
            $responseHeaders[strtolower((string) $name)] = implode(', ', $values);
        }

        return TransportResult::response($response->status(), $responseHeaders, $this->readBody($response->toPsrResponse(), $maxBytes));
    }

    public function maxBytes(): int
    {
        return ConfigurationValidator::integer('response_max_bytes');
    }

    /**
     * A pending request of the HTTP client factory (stubs, recording, stray
     * request protection) without the global options and middleware of the
     * host (R66).
     *
     * @param  array<string, mixed>  $options
     */
    private function pendingRequest(array $options, bool $dispatchEvents): PendingRequest
    {
        $request = Http::withoutGlobalConfiguration(static fn (): PendingRequest => Http::createPendingRequest());

        return ($dispatchEvents ? $request : SilentPendingRequest::of($request))->withOptions($options);
    }

    /**
     * Reads at most max + 1 bytes, also without Content-Length (R66).
     */
    private function readBody(ResponseInterface $response, int $maxBytes): string
    {
        $stream = $response->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';

        while (! $stream->eof() && strlen($body) <= $maxBytes) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        return $body;
    }

    /**
     * The handler error data of the transfer stats is cURL errno 28 or an
     * exception carrying it.
     */
    private static function isTimeout(mixed $error): bool
    {
        if ($error === self::TIMEOUT_ERROR) {
            return true;
        }

        if ($error instanceof TransferException && method_exists($error, 'getHandlerContext')) {
            $context = $error->getHandlerContext();

            return is_array($context) && ($context['errno'] ?? null) === self::TIMEOUT_ERROR;
        }

        return false;
    }

    private function failure(Throwable $exception): TransportResult
    {
        $errno = null;

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof TransferException && method_exists($current, 'getHandlerContext')) {
                $context = $current->getHandlerContext();
                $errno = is_array($context) && is_int($context['errno'] ?? null) ? $context['errno'] : $errno;
            }

            if ($errno === null && preg_match('/^cURL error (\d+):/', $current->getMessage(), $match) === 1) {
                $errno = (int) $match[1];
            }
        }

        return match (true) {
            $errno === self::TIMEOUT_ERROR => TransportResult::timeout(),
            in_array($errno, self::TLS_ERRORS, true) => TransportResult::failure('tls'),
            default => TransportResult::failure('connection'),
        };
    }
}
