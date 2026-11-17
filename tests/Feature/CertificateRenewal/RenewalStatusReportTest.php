<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Enums\RenewalProblem;
use Gunner42\LaravelEetModule\Enums\RenewalStatus;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Tests\Support\CaEetApi;
use Gunner42\LaravelEetModule\Tests\Support\Renewal;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/*
 * R106: eet:status reports certificates that need a renewal and failed
 * renewals. The renewal tests use production taxpayers, so the production
 * signer CN is configured to keep R54 quiet.
 */

beforeEach(function (): void {
    $this->travelTo(Renewal::now());
    Sleep::fake(syncWithCarbon: true);
    Queue::fake();
    config()->set('laravel-eet-module.response_signer.production.common_name', 'EET produkcni prostredi');
});

/**
 * @return array{code: int, output: string}
 */
function runStatusCommand(): array
{
    $code = Artisan::call('eet:status');

    return ['code' => $code, 'output' => Artisan::output()];
}

it('exits with 0 when no certificate needs attention [R106]', function (): void {
    $outside = Renewal::certificate('renewal/CZ00000019-until-2026-12-11.pem');
    Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');

    expect($outside->isInRenewalWindow())->toBeFalse()
        ->and(runStatusCommand()['code'])->toBe(0);
});

it('reports a valid production certificate in the renewal window without a successor and exits with 1 [R106]', function (): void {
    $certificate = Renewal::certificate();

    $result = runStatusCommand();

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain((string) $certificate->getKey())
        ->and($result['output'])->toContain('CZ00000019')
        ->and($result['output'])->toContain('2026-12-10');
});

it('does not report a certificate 22 days before expiry with the default window of 21 days [R106]', function (): void {
    Renewal::certificate('renewal/CZ00000019-until-2026-12-11.pem');

    expect(runStatusCommand()['code'])->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-11-19 23:59:59', 'UTC'));

    expect(runStatusCommand()['code'])->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-11-20 00:00:00', 'UTC'));

    expect(runStatusCommand()['code'])->toBe(1);
});

it('uses renewal.days_before_expiry for the window [R106]', function (): void {
    config()->set('laravel-eet-module.renewal.days_before_expiry', 22);
    Renewal::certificate('renewal/CZ00000019-until-2026-12-11.pem');

    expect(runStatusCommand()['code'])->toBe(1);
});

it('exits with 0 after a completed renewal without other findings [R106]', function (): void {
    CaEetApi::fake();
    $certificate = Renewal::certificate();
    Renewal::insert($certificate, RenewalStatus::Failed, '11111111-2222-4333-8444-555555555555', RenewalProblem::Rejected, 'rejected');

    expect(Eet::renewCertificate($certificate)?->status)->toBe(RenewalStatus::Completed)
        ->and(runStatusCommand()['code'])->toBe(0);
});

it('ignores a playground certificate in the renewal window [R106]', function (): void {
    $playground = importFixture(Renewal::taxpayer(), 'playground/CZ00000019.pem');
    $this->travelTo(CarbonImmutable::parse('2027-05-01 00:00:00', 'UTC'));

    expect(Renewal::fresh($playground)->isInRenewalWindow())->toBeTrue()
        ->and(Renewal::fresh($playground)->hasProductionPolicy())->toBeFalse()
        ->and(runStatusCommand()['code'])->toBe(0);
});

it('ignores an expired production certificate without a successor [R106]', function (): void {
    Renewal::certificate();

    expect(runStatusCommand()['code'])->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-12-10 00:00:01', 'UTC'));

    expect(runStatusCommand()['code'])->toBe(0);
});

it('reports a valid certificate whose latest renewal failed, with its state and problem, and exits with 1 [R106, R102]', function (): void {
    $certificate = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');
    Renewal::insert($certificate, RenewalStatus::Failed, CaEetApi::REQ_ID, RenewalProblem::Rejected, 'Selhani kontroly');

    $result = runStatusCommand();

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain((string) $certificate->getKey())
        ->and($result['output'])->toContain('2027-06-30')
        ->and($result['output'])->toContain(RenewalStatus::Failed->label())
        ->and($result['output'])->toContain(RenewalProblem::Rejected->label());
});

it('does not report a failed renewal of a certificate that is no longer valid [R106]', function (): void {
    $certificate = Renewal::certificate();
    Renewal::insert($certificate, RenewalStatus::Failed, CaEetApi::REQ_ID, RenewalProblem::Rejected, 'rejected');
    $this->travelTo(CarbonImmutable::parse('2026-12-10 00:00:01', 'UTC'));

    expect(runStatusCommand()['code'])->toBe(0);
});

it('reports a valid certificate whose latest renewal has UnknownPendingRequest and exits with 1 [R106, R93]', function (): void {
    $certificate = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');
    Renewal::insert($certificate, RenewalStatus::Requesting, null, RenewalProblem::UnknownPendingRequest, CaEetApi::UNKNOWN_REQ_ID);

    $result = runStatusCommand();

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain(RenewalProblem::UnknownPendingRequest->label());
});

it('reports the unknown pending request found by renewCertificate() [R106, R93]', function (): void {
    CaEetApi::fake(['not-finished' => CaEetApi::notFinished(CaEetApi::UNKNOWN_REQ_ID)]);
    $certificate = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');
    Eet::renewCertificate($certificate);

    expect(runStatusCommand()['code'])->toBe(1);
});

it('does not report a renewal outside the window with only a TemporaryError [R106]', function (): void {
    $certificate = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');
    Renewal::insert($certificate, RenewalStatus::Requesting, null, RenewalProblem::TemporaryError, '500');

    expect(runStatusCommand()['code'])->toBe(0);
});

it('does not report a certificate whose older renewal failed but the latest is active [R106]', function (): void {
    $certificate = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');
    Renewal::insert($certificate, RenewalStatus::Failed, '11111111-2222-4333-8444-555555555555', RenewalProblem::Rejected, 'rejected');
    Renewal::insert($certificate, RenewalStatus::InProcess, CaEetApi::REQ_ID);

    expect(runStatusCommand()['code'])->toBe(0);
});

it('validates renewal.days_before_expiry, prints the key and exits with 1 [R106, R68]', function (mixed $value): void {
    config()->set('laravel-eet-module.renewal.days_before_expiry', $value);

    $result = runStatusCommand();

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('renewal.days_before_expiry');
})->with([0, 366]);

it('does not validate the other renewal keys [R106, R68]', function (string $key, mixed $value): void {
    config()->set('laravel-eet-module.'.$key, $value);

    expect(runStatusCommand()['code'])->toBe(0);

    // control: the key that eet:status does use is validated in the same setup
    config()->set('laravel-eet-module.renewal.days_before_expiry', 0);
    $result = runStatusCommand();

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('renewal.days_before_expiry')
        ->and($result['output'])->not->toContain($key);
})->with([
    ['renewal.endpoint', 'http://ca.example.com/api'],
    ['renewal.timeout_ms', 1],
    ['renewal.max_wait_seconds', -1],
]);
