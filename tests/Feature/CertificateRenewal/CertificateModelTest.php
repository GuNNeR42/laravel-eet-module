<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Enums\CertificateProblem;
use Gunner42\LaravelEetModule\Enums\RenewalProblem;
use Gunner42\LaravelEetModule\Enums\RenewalStatus;
use Gunner42\LaravelEetModule\Exceptions\InvalidCertificate;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Tests\Support\CaEetApi;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Gunner42\LaravelEetModule\Tests\Support\Renewal;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The renewal methods of CashRegisterCertificate (spec/10 chap. 6):
 * hasProductionPolicy(), isInRenewalWindow(), successor(), predecessor(),
 * latestRenewal(). Time is frozen at 2026-11-19 00:00 UTC.
 */

beforeEach(function (): void {
    $this->travelTo(Renewal::now());
});

it('recognizes the production policy by an OID starting with 1.2.203.19122063.10.1. [R90]', function (string $fixture, bool $production): void {
    $certificate = Eet::importCertificate(Renewal::taxpayer(), Fixtures::read($fixture));

    expect($certificate->hasProductionPolicy())->toBe($production);
})->with([
    'production 10.1.102.0.1' => [CaEetApi::RENEWED, true],
    'production 10.1.5' => ['renewal/CZ00000019-policy-10-1-other.pem', true],
    'near miss 10.10.1' => ['renewal/CZ00000019-policy-10-10.pem', false],
    'production OID only in policy qualifiers' => ['renewal/CZ00000019-production-oid-in-qualifier.pem', false],
    'playground 10.2.102.0.1' => ['playground/CZ00000019.pem', false],
    'no policy' => ['cash-register/CZ00000019-until-2027-12-31.pem', false],
]);

it('is in the renewal window exactly 21 days before validTo and not one second earlier [R105, R106]', function (): void {
    $certificate = Renewal::certificate();

    expect($certificate->isInRenewalWindow())->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-11-18 23:59:59', 'UTC'));

    expect(Renewal::fresh($certificate)->isInRenewalWindow())->toBeFalse();
});

it('is in the renewal window until the last second of validity, but not after it [R105]', function (): void {
    $certificate = Renewal::certificate();
    $this->travelTo(CarbonImmutable::parse('2026-12-10 00:00:00', 'UTC'));

    expect(Renewal::fresh($certificate)->isInRenewalWindow())->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-12-10 00:00:01', 'UTC'));

    expect(Renewal::fresh($certificate)->isInRenewalWindow())->toBeFalse();
});

it('uses renewal.days_before_expiry for the window [R105]', function (): void {
    $certificate = Renewal::certificate('renewal/CZ00000019-until-2026-12-11.pem');

    expect($certificate->isInRenewalWindow())->toBeFalse();

    config()->set('laravel-eet-module.renewal.days_before_expiry', 22);

    expect(Renewal::fresh($certificate)->isInRenewalWindow())->toBeTrue();
});

it('is not in the renewal window before validFrom [R105]', function (): void {
    config()->set('laravel-eet-module.renewal.days_before_expiry', 365);
    $certificate = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');

    expect($certificate->isInRenewalWindow())->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-06-29 23:59:59', 'UTC'));

    expect(Renewal::fresh($certificate)->isInRenewalWindow())->toBeFalse();
});

it('has no successor, predecessor or renewal after import [R90, R95]', function (): void {
    $certificate = Renewal::certificate();

    expect($certificate->successor())->toBeNull()
        ->and($certificate->predecessor())->toBeNull()
        ->and($certificate->latestRenewal())->toBeNull()
        ->and($certificate->renewals()->count())->toBe(0);
});

it('takes the successor only from a renewal in Acknowledging or Completed [R90, R96]', function (string $status, bool $hasSuccessor): void {
    $certificate = Renewal::certificate();
    $other = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');
    Renewal::insert(
        $certificate,
        RenewalStatus::from($status),
        CaEetApi::REQ_ID,
        $status === 'failed' ? RenewalProblem::RequestFailed : null,
        null,
        $other,
    );

    expect(Renewal::fresh($certificate)->successor()?->getKey())->toBe($hasSuccessor ? $other->getKey() : null)
        ->and(Renewal::fresh($other)->predecessor()?->getKey())->toBe($hasSuccessor ? $certificate->getKey() : null);
})->with([
    'acknowledging' => ['acknowledging', true],
    'completed' => ['completed', true],
    'failed' => ['failed', false],
]);

it('returns the renewal with the highest id as the latest renewal [R102]', function (): void {
    $certificate = Renewal::certificate();
    Renewal::insert($certificate, RenewalStatus::Failed, '11111111-2222-4333-8444-555555555555', RenewalProblem::Rejected);
    $latest = Renewal::insert($certificate, RenewalStatus::Requesting, null);

    expect(Renewal::fresh($certificate)->latestRenewal()?->getKey())->toBe($latest->getKey())
        ->and(Renewal::fresh($certificate)->renewals()->count())->toBe(2);
});

it('rejects the import of a PKCS#12 without a private key with MissingPrivateKey [R10]', function (): void {
    try {
        Eet::importCertificate(Renewal::taxpayer(), Fixtures::read('renewal/successor-without-key.p12'), CaEetApi::PASSWORD);

        throw new AssertionFailedError('InvalidCertificate was not thrown.');
    } catch (InvalidCertificate $exception) {
        expect($exception->problem)->toBe(CertificateProblem::MissingPrivateKey);
    }
});
