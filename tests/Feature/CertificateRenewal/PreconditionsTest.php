<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Enums\RenewalProblem;
use Gunner42\LaravelEetModule\Enums\RenewalStatus;
use Gunner42\LaravelEetModule\Exceptions\ActiveDatabaseTransaction;
use Gunner42\LaravelEetModule\Exceptions\CertificateNotRenewable;
use Gunner42\LaravelEetModule\Exceptions\EetException;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\CertificateRenewal;
use Gunner42\LaravelEetModule\Tests\Support\CaEetApi;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Gunner42\LaravelEetModule\Tests\Support\Renewal;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\AssertionFailedError;

/*
 * Preconditions of a renewal (R90) and of the call (R91). Time is frozen at
 * 2026-11-19 00:00 UTC: the playground certificates (until 2027-05-14), the
 * own test CA certificates (until 2027-12-31) and the renewed production
 * certificate (until 2026-12-10) are all valid then.
 */

beforeEach(function (): void {
    $this->travelTo(Renewal::now());
    Sleep::fake(syncWithCarbon: true);
});

/**
 * Renews and returns the reason of CertificateNotRenewable.
 */
function notRenewableProblem(CashRegisterCertificate $certificate): RenewalProblem
{
    try {
        Eet::renewCertificate($certificate);
    } catch (CertificateNotRenewable $exception) {
        return $exception->problem;
    }

    throw new AssertionFailedError('CertificateNotRenewable was not thrown.');
}

/**
 * Renews and returns the key of InvalidConfiguration.
 */
function invalidRenewalConfigurationKey(CashRegisterCertificate $certificate): string
{
    try {
        Eet::renewCertificate($certificate);
    } catch (InvalidConfiguration $exception) {
        return $exception->key;
    }

    throw new AssertionFailedError('InvalidConfiguration was not thrown.');
}

// ---------------------------------------------------------------------------
// R90
// ---------------------------------------------------------------------------

it('never renews a playground certificate, not even the shared test ones, and sends nothing [R90]', function (string $fixture, ?string $password): void {
    CaEetApi::fake();
    $certificate = Eet::importCertificate(Renewal::taxpayer(), Fixtures::read($fixture), $password);

    expect($certificate->hasProductionPolicy())->toBeFalse()
        ->and(notRenewableProblem($certificate))->toBe(RenewalProblem::NotProductionCertificate)
        ->and(CertificateRenewal::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'playground PEM' => ['playground/CZ00000019.pem', null],
    'playground PKCS#12' => ['playground/CZ00000019.modern.p12', Fixtures::P12_PASSWORD],
    'own CA without a policy' => ['cash-register/CZ00000019-until-2027-12-31.pem', null],
    'policy 1.2.203.19122063.10.10.…' => ['renewal/CZ00000019-policy-10-10.pem', null],
    'production OID only in a CPS URI and user notice' => ['renewal/CZ00000019-production-oid-in-qualifier.pem', null],
]);

it('renews a production certificate with any policy under 1.2.203.19122063.10.1. [R90]', function (string $fixture): void {
    CaEetApi::fake(['status' => CaEetApi::inProcess(1)]);
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 0);
    $certificate = Renewal::certificate($fixture);

    $renewal = Eet::renewCertificate($certificate);

    expect($certificate->hasProductionPolicy())->toBeTrue()
        ->and($renewal?->status)->toBe(RenewalStatus::InProcess);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/request/renew'));
})->with([
    'policy 1.2.203.19122063.10.1.102.0.1' => CaEetApi::RENEWED,
    'policy 1.2.203.19122063.10.1.5' => 'renewal/CZ00000019-policy-10-1-other.pem',
]);

it('rejects a production certificate that is no longer valid with CertificateNotValid [R90]', function (): void {
    CaEetApi::fake();
    $certificate = Renewal::certificate();
    $this->travelTo(CarbonImmutable::parse('2026-12-10 00:00:01', 'UTC'));

    expect(notRenewableProblem($certificate))->toBe(RenewalProblem::CertificateNotValid)
        ->and(CertificateRenewal::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects a production certificate that is not valid yet with CertificateNotValid [R90]', function (): void {
    CaEetApi::fake();
    $certificate = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');
    $this->travelTo(CarbonImmutable::parse('2026-06-29 23:59:59', 'UTC'));

    expect(notRenewableProblem($certificate))->toBe(RenewalProblem::CertificateNotValid);
    Http::assertNothingSent();
});

it('renews a production certificate at the last second of its validity [R90]', function (): void {
    CaEetApi::fake();
    $certificate = Renewal::certificate();
    $this->travelTo(CarbonImmutable::parse('2026-12-10 00:00:00', 'UTC'));

    expect(Eet::renewCertificate($certificate)?->status)->toBe(RenewalStatus::Completed);
});

it('rejects a production certificate whose key cannot be decrypted with CertificateUnusable [R90, R69]', function (): void {
    CaEetApi::fake();
    $certificate = Renewal::certificate();
    Renewal::makeUnusable($certificate);

    expect(Renewal::fresh($certificate)->isUsable())->toBeFalse()
        ->and(notRenewableProblem(Renewal::fresh($certificate)))->toBe(RenewalProblem::CertificateUnusable)
        ->and(CertificateRenewal::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects a certificate that already has a successor with AlreadyRenewed [R90]', function (): void {
    $api = CaEetApi::fake();
    $certificate = Renewal::certificate();
    Eet::renewCertificate($certificate);
    $api->forget();

    expect(notRenewableProblem(Renewal::fresh($certificate)))->toBe(RenewalProblem::AlreadyRenewed)
        ->and(CertificateRenewal::query()->count())->toBe(1)
        ->and($api->count())->toBe(0);
});

it('does not treat a manually imported later certificate as a successor [R90]', function (): void {
    CaEetApi::fake(['status' => CaEetApi::inProcess(1)]);
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 0);
    $certificate = Renewal::certificate();
    Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');

    expect(Renewal::fresh($certificate)->successor())->toBeNull()
        ->and(Eet::renewCertificate($certificate)?->status)->toBe(RenewalStatus::InProcess);
});

it('reports the first reason in the order NotProductionCertificate, CertificateNotValid, CertificateUnusable, AlreadyRenewed [R90]', function (): void {
    CaEetApi::fake();
    $playground = importFixture(Renewal::taxpayer(), 'playground/CZ00000019.pem');
    $production = Renewal::certificate();
    Renewal::makeUnusable($playground, 'playground/CZ00000019.pem');
    Renewal::makeUnusable($production);
    $this->travelTo(CarbonImmutable::parse('2027-06-01 00:00:00', 'UTC'));

    expect(notRenewableProblem(Renewal::fresh($playground)))->toBe(RenewalProblem::NotProductionCertificate)
        ->and(notRenewableProblem(Renewal::fresh($production)))->toBe(RenewalProblem::CertificateNotValid);
    Http::assertNothingSent();
});

it('reports CertificateUnusable before AlreadyRenewed [R90]', function (): void {
    CaEetApi::fake();
    $certificate = Renewal::certificate();
    Eet::renewCertificate($certificate);
    Renewal::makeUnusable($certificate);

    expect(notRenewableProblem(Renewal::fresh($certificate)))->toBe(RenewalProblem::CertificateUnusable);
});

it('makes CertificateNotRenewable an EetException with the problem [R90]', function (): void {
    CaEetApi::fake();
    $certificate = importFixture(Renewal::taxpayer(), 'playground/CZ00000019.pem');

    try {
        Eet::renewCertificate($certificate);

        throw new AssertionFailedError('CertificateNotRenewable was not thrown.');
    } catch (CertificateNotRenewable $exception) {
        expect($exception)->toBeInstanceOf(EetException::class)
            ->and($exception)->toBeInstanceOf(RuntimeException::class)
            ->and($exception->problem)->toBe(RenewalProblem::NotProductionCertificate);
    }
});

// ---------------------------------------------------------------------------
// R91
// ---------------------------------------------------------------------------

it('renews a certificate outside the renewal window when called manually [R91]', function (): void {
    CaEetApi::fake(['status' => CaEetApi::inProcess(1)]);
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 0);
    $certificate = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');

    expect($certificate->isInRenewalWindow())->toBeFalse()
        ->and(Eet::renewCertificate($certificate)?->status)->toBe(RenewalStatus::InProcess);
});

it('throws ActiveDatabaseTransaction inside an open database transaction of the host and stores and sends nothing [R91]', function (): void {
    CaEetApi::fake();
    $certificate = Renewal::certificate();

    expect(fn (): mixed => DB::transaction(fn (): mixed => Eet::renewCertificate($certificate)))->toThrow(ActiveDatabaseTransaction::class)
        ->and(CertificateRenewal::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('allows the call inside the transaction opened by RefreshDatabase [R91]', function (): void {
    CaEetApi::fake();

    expect(DB::transactionLevel())->toBeGreaterThanOrEqual(1)
        ->and(Eet::renewCertificate(Renewal::certificate())?->status)->toBe(RenewalStatus::Completed);
});

it('does not touch an active renewal when called inside a host transaction [R91]', function (): void {
    $api = CaEetApi::fake();
    $certificate = Renewal::certificate();
    $renewal = Renewal::insert($certificate, RenewalStatus::InProcess);

    expect(fn (): mixed => DB::transaction(fn (): mixed => Eet::renewCertificate($certificate)))->toThrow(ActiveDatabaseTransaction::class)
        ->and(Renewal::latest($certificate)->status)->toBe(RenewalStatus::InProcess)
        ->and(Renewal::latest($certificate)->getKey())->toBe($renewal->getKey())
        ->and($api->count())->toBe(0);
});

it('validates the configuration with the key before any write or API call [R91, R68, R100]', function (string $key, mixed $value, string $expectedKey): void {
    $api = CaEetApi::fake();
    $certificate = Renewal::certificate();
    config()->set('laravel-eet-module.'.$key, $value);

    expect(invalidRenewalConfigurationKey($certificate))->toBe($expectedKey)
        ->and(CertificateRenewal::query()->count())->toBe(0)
        ->and($api->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'endpoint with http' => ['renewal.endpoint', 'http://ca.caeet.gov.cz/api', 'renewal.endpoint'],
    'endpoint with an IP address' => ['renewal.endpoint', 'https://10.1.2.3/api', 'renewal.endpoint'],
    'endpoint with an IPv6 address' => ['renewal.endpoint', 'https://[2001:db8::1]/api', 'renewal.endpoint'],
    'endpoint outside the allow-list' => ['renewal.endpoint', 'https://ca.example.com/api', 'renewal.endpoint'],
    'endpoint with port 8443' => ['renewal.endpoint', 'https://ca.caeet.gov.cz:8443/api', 'renewal.endpoint'],
    'endpoint with userinfo' => ['renewal.endpoint', 'https://user:secret@ca.caeet.gov.cz/api', 'renewal.endpoint'],
    'endpoint that is not a URL' => ['renewal.endpoint', 'ca.caeet.gov.cz', 'renewal.endpoint'],
    'allowed hosts empty' => ['renewal.allowed_hosts', [], 'renewal.allowed_hosts'],
    'allowed hosts with an IP address' => ['renewal.allowed_hosts', ['ca.caeet.gov.cz', '10.1.2.3'], 'renewal.allowed_hosts'],
    'days before expiry 0' => ['renewal.days_before_expiry', 0, 'renewal.days_before_expiry'],
    'days before expiry 366' => ['renewal.days_before_expiry', 366, 'renewal.days_before_expiry'],
    'max wait negative' => ['renewal.max_wait_seconds', -1, 'renewal.max_wait_seconds'],
    'timeout below 1000 ms' => ['renewal.timeout_ms', 999, 'renewal.timeout_ms'],
    'proxy' => ['proxy', 'socks5://proxy.example:1080', 'proxy'],
    'lock store' => ['lock_store', 'does-not-exist', 'lock_store'],
    'response_max_bytes' => ['response_max_bytes', 100, 'response_max_bytes'],
]);

it('validates the configuration before continuing an active renewal [R91, R68]', function (): void {
    $api = CaEetApi::fake();
    $certificate = Renewal::certificate();
    $renewal = Renewal::insert($certificate, RenewalStatus::InProcess);
    config()->set('laravel-eet-module.renewal.timeout_ms', 10);

    expect(invalidRenewalConfigurationKey($certificate))->toBe('renewal.timeout_ms')
        ->and(Renewal::latest($certificate)->status)->toBe(RenewalStatus::InProcess)
        ->and(Renewal::latest($certificate)->problem)->toBeNull()
        ->and(Renewal::latest($certificate)->getKey())->toBe($renewal->getKey())
        ->and($api->count())->toBe(0);
});

it('accepts the boundary values of the renewal configuration [R91, R68]', function (string $key, mixed $value): void {
    CaEetApi::fake();
    config()->set('laravel-eet-module.'.$key, $value);

    expect(Eet::renewCertificate(Renewal::certificate())?->status)->toBe(RenewalStatus::Completed);
})->with([
    'days before expiry 1' => ['renewal.days_before_expiry', 1],
    'days before expiry 365' => ['renewal.days_before_expiry', 365],
    'max wait 0' => ['renewal.max_wait_seconds', 0],
    'timeout 1000 ms' => ['renewal.timeout_ms', 1000],
    'endpoint with port 443' => ['renewal.endpoint', 'https://ca.caeet.gov.cz:443/api'],
]);

it('does not validate unrelated configuration keys [R91, R68]', function (string $key, mixed $value): void {
    CaEetApi::fake();
    config()->set('laravel-eet-module.'.$key, $value);

    expect(Eet::renewCertificate(Renewal::certificate())?->status)->toBe(RenewalStatus::Completed);
})->with([
    'retention' => ['retention.raw_xml_days', 0],
    'response timeout' => ['response_timeout_ms', 1],
]);
