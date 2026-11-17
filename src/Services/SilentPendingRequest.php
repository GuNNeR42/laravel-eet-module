<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;

/**
 * A pending request that dispatches no HTTP client events (RequestSending,
 * ResponseReceived, ConnectionFailed), so host listeners never see the
 * token or the PKCS#12 of a CA EET API call (R100, R103). It copies the
 * state of a request created by the factory, so Http::fake() stubs,
 * recording and preventStrayRequests() keep working.
 *
 * @internal
 */
final class SilentPendingRequest extends PendingRequest
{
    public static function of(PendingRequest $template): self
    {
        $request = new self;

        // every property of PendingRequest is protected, so this copies the whole state
        foreach (get_object_vars($template) as $name => $value) {
            $request->{$name} = $value;
        }

        return $request;
    }

    protected function dispatchRequestSendingEvent(): void {}

    protected function dispatchResponseReceivedEvent(Response $response): void {}

    protected function dispatchConnectionFailedEvent(Request $request, ?ConnectionException $exception = null): void {}
}
