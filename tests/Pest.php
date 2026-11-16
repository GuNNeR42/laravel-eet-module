<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Data\SaleData;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Events\SaleConfirmed;
use Gunner42\LaravelEetModule\Events\SaleOverdue;
use Gunner42\LaravelEetModule\Events\SalePreflightWarned;
use Gunner42\LaravelEetModule\Events\SaleRejected;
use Gunner42\LaravelEetModule\Events\SaleReopened;
use Gunner42\LaravelEetModule\Events\SaleUnconfirmed;
use Gunner42\LaravelEetModule\Events\SaleVerified;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\SubmissionAttempt;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Gunner42\LaravelEetModule\Tests\Support\SoapMessage;
use Gunner42\LaravelEetModule\Tests\TestCase;
use Gunner42\LaravelEetModule\ValueObjects\Amount;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(TestCase::class)->in(__DIR__);

/*
|--------------------------------------------------------------------------
| Shared helpers
|--------------------------------------------------------------------------
|
| Every test that uses an absolute date, the playground certificates or the
| playground response sample freezes time (spec 20, acceptance criteria).
| The default frozen moment is NOW (winter time, +01:00): after the start of
| both environments (R61) and inside the validity of every certificate fixture.
|
*/

const NOW = '2026-11-20T10:00:00+01:00';

const TEST_CA_TRUST_STORE = 'response-ca/trust-store.pem';

function frozenNow(): CarbonImmutable
{
    return CarbonImmutable::parse(NOW);
}

function amount(string $value): Amount
{
    return Amount::fromString($value);
}

function saleData(
    string $sequenceNumber = '1',
    ?DateTimeInterface $occurredAt = null,
    string $totalAmount = '100.00',
    ?string $amountForLaterDrawing = null,
    ?string $drawnAmount = null,
    ?string $delegatingEic = null,
    bool $delegatedByMultipleTaxpayers = false,
): SaleData {
    return new SaleData(
        $sequenceNumber,
        $occurredAt ?? CarbonImmutable::now(),
        Amount::fromString($totalAmount),
        $amountForLaterDrawing === null ? null : Amount::fromString($amountForLaterDrawing),
        $drawnAmount === null ? null : Amount::fromString($drawnAmount),
        $delegatingEic,
        $delegatedByMultipleTaxpayers,
    );
}

function taxpayer(string $eic = 'CZ00000019', Environment $environment = Environment::Playground): Taxpayer
{
    $existing = Taxpayer::query()->where('eic', $eic)->first();

    return $existing instanceof Taxpayer ? $existing : Eet::createTaxpayer($eic, 'Test '.$eic, $environment);
}

function cashRegister(
    string $eic = 'CZ00000019',
    Environment $environment = Environment::Playground,
    int $registrationUnit = 181,
    string $identifier = 'POKLADNA-1',
): CashRegister {
    $taxpayer = taxpayer($eic, $environment);
    $unit = $taxpayer->registrationUnits()->where('number', $registrationUnit)->first()
        ?? Eet::createRegistrationUnit($taxpayer, $registrationUnit, 'Jednotka '.$registrationUnit);
    $existing = $unit->cashRegisters()->where('identifier', $identifier)->first();

    return $existing instanceof CashRegister ? $existing : Eet::createCashRegister($unit, $identifier, 'Pokladna '.$identifier);
}

function importFixture(Taxpayer $taxpayer, string $fixture = 'playground/CZ00000019.pem', ?string $password = null): CashRegisterCertificate
{
    return Eet::importCertificate($taxpayer, Fixtures::read($fixture), $password);
}

function register(CashRegister $cashRegister, ?SaleData $data = null): Sale
{
    return Eet::register($cashRegister, $data ?? saleData());
}

function useTestResponseCa(string $trustStore = TEST_CA_TRUST_STORE): void
{
    config()->set('laravel-eet-module.response_trust_store', Fixtures::path($trustStore));
}

/**
 * Fakes only the package events, so Eloquent events keep working.
 */
function fakeEetEvents(): void
{
    Event::fake([
        SaleConfirmed::class,
        SaleVerified::class,
        SaleRejected::class,
        SaleUnconfirmed::class,
        SaleOverdue::class,
        SaleReopened::class,
        SalePreflightWarned::class,
    ]);
}

/**
 * Replaces the EET service on the HTTP boundary (R32: the package sends
 * through the Laravel HTTP client). The responder gets the parsed request.
 *
 * @param  Closure(SoapMessage, Request, array<string, mixed>): (EetResponse|PromiseInterface)  $responder
 */
function fakeEetService(Closure $responder, string $globalTransactionId = 'bc626a986af9dc99035fe7d0'): void
{
    Http::preventStrayRequests();
    Http::fake(function (Request $request, array $options) use ($responder, $globalTransactionId): PromiseInterface {
        $result = $responder(new SoapMessage($request->body()), $request, $options);

        if ($result instanceof EetResponse) {
            return Http::response($result->toXml(), 200, [
                'Content-Type' => 'text/xml; charset=UTF-8',
                'X-Global-Transaction-Id' => $globalTransactionId,
            ]);
        }

        return $result;
    });
}

/**
 * A confirmation as the EET service of the given environment would send it,
 * signed by the own test CA (call useTestResponseCa()).
 */
function confirmationFor(SoapMessage $message, Environment $environment = Environment::Playground): EetResponse
{
    return $environment === Environment::Playground
        ? EetResponse::confirmed($message->uuid(), EetResponse::confirmationCodeFor($message->uuid(), 'ff'))->test('true')
        : EetResponse::confirmed($message->uuid())->signedBy('signer-production');
}

/**
 * The EET service confirms every message (signed by the own test CA).
 */
function confirmingEetService(Environment $environment = Environment::Playground): void
{
    useTestResponseCa();
    fakeEetService(fn (SoapMessage $message): EetResponse => confirmationFor($message, $environment));
}

/**
 * The EET service answers every message with an unsigned error.
 */
function failingEetService(int $code = -1, Environment $environment = Environment::Playground): void
{
    fakeEetService(fn (SoapMessage $message): EetResponse => EetResponse::error($message->uuid(), $code)
        ->test($environment === Environment::Playground ? 'true' : null));
}

/**
 * Every SOAP message the package sent through the HTTP client, in order.
 *
 * @return list<SoapMessage>
 */
function sentSoapMessages(): array
{
    return Http::recorded()
        ->map(static fn (array $pair): SoapMessage => new SoapMessage($pair[0]->body()))
        ->values()
        ->all();
}

/**
 * @return Collection<int, SubmissionAttempt>
 */
function attemptsOf(Sale $sale): Collection
{
    return SubmissionAttempt::query()->where('sale_id', $sale->getKey())->orderBy('id')->get();
}

function lastAttempt(Sale $sale): SubmissionAttempt
{
    $attempt = attemptsOf($sale)->last();

    if (! $attempt instanceof SubmissionAttempt) {
        throw new RuntimeException('The sale has no attempt.');
    }

    return $attempt;
}

function freshSale(Sale $sale): Sale
{
    $fresh = Sale::query()->findOrFail($sale->getKey());

    return $fresh;
}

/**
 * Delay of a queued job in seconds (Queueable::$delay).
 */
function jobDelay(object $job): ?int
{
    $delay = property_exists($job, 'delay') ? $job->delay : null;

    if ($delay instanceof DateInterval) {
        return (int) CarbonImmutable::now()->add($delay)->diffInSeconds(CarbonImmutable::now(), true);
    }

    if ($delay instanceof DateTimeInterface) {
        return (int) CarbonImmutable::now()->diffInSeconds($delay, true);
    }

    return is_int($delay) ? $delay : null;
}

/**
 * Runs the callback as if the application did not run unit tests
 * (app()->runningUnitTests() === false) and restores the environment.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function outsideOfTests(Closure $callback): mixed
{
    $previous = app()['env'];
    app()['env'] = 'production';

    try {
        return $callback();
    } finally {
        app()['env'] = $previous;
    }
}

/**
 * A deterministic version 4 UUID for test messages.
 */
function testUuid(int $number = 1): string
{
    return sprintf('3f1c2b4a-5d6e-4f70-8a9b-%012x', $number);
}

/**
 * The next attempts get these message UUIDs (R29), in order.
 */
function useMessageUuids(string ...$uuids): void
{
    Eet::createMessageUuidsUsing(function () use (&$uuids): string {
        $uuid = array_shift($uuids);

        if ($uuid === null) {
            throw new RuntimeException('No more test message UUIDs.');
        }

        return $uuid;
    });
}

/**
 * The real signed playground response with an optional string mutation.
 */
function sampleResponse(?Closure $mutation = null): string
{
    $xml = Fixtures::read('pg-vzorek/response-potvrzeni.xml');

    return $mutation === null ? $xml : $mutation($xml);
}
