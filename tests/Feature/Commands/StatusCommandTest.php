<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Enums\SendingMode;
use Gunner42\LaravelEetModule\Events\SaleOverdue;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * Sales for eet:status come from the factories of the public contract, so
 * they are not fake sales (is_fake = false) unless a test creates them under
 * Eet::fake() on purpose.
 */

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
    // a production taxpayer without a configured production CN is itself a finding (R54, Q38)
    config()->set('laravel-eet-module.response_signer.production.common_name', 'EET produkcni prostredi');
});

it('exits with 0 and prints counts by status and environment when nothing needs attention [R54]', function (): void {
    Sale::factory()->confirmed()->create();
    Sale::factory()->playground()->create();

    $this->artisan('eet:status')
        ->expectsOutputToContain(SaleStatus::Confirmed->label())
        ->assertExitCode(0);
});

it('exits with 0 without any sale [R54]', function (): void {
    $this->artisan('eet:status')->assertExitCode(0);
});

it('lists a production live sale unconfirmed after 48 h and exits with 1 [R54, R44]', function (): void {
    fakeEetEvents();
    $sale = Sale::factory()->overdue()->create();

    $this->artisan('eet:status')
        ->expectsOutputToContain($sale->eic)
        ->expectsOutputToContain($sale->sequence_number)
        ->assertExitCode(1);
    $this->artisan('eet:status')->assertExitCode(1);

    Event::assertDispatchedTimes(SaleOverdue::class, 1);
    expect(freshSale($sale)->overdue_notified_at)->not->toBeNull();
});

it('does not report a production unconfirmed sale within 48 h [R54]', function (): void {
    Sale::factory()->unconfirmed()->state(['occurred_at' => frozenNow()->subHours(47)->setTimezone('Europe/Prague')->format('Y-m-d\TH:i:sP')])->create();

    $this->artisan('eet:status')->assertExitCode(0);
});

it('reports a production sale left in Pending after 48 h [R54, R63]', function (): void {
    $sale = Sale::factory()->overdue()->create();
    DB::table('eet_submission_attempts')->where('sale_id', $sale->getKey())->delete();
    DB::table('eet_sales')->where('id', $sale->getKey())->update([
        'status' => SaleStatus::Pending->value,
        'created_at' => frozenNow()->subHours(49),
    ]);

    $this->artisan('eet:status')
        ->expectsOutputToContain($sale->sequence_number)
        ->assertExitCode(1);
});

it('lists every production live rejected sale (factory default) with its code and exits with 1 [R54, R64]', function (): void {
    $sale = Sale::factory()->rejected(3)->create();

    $this->artisan('eet:status')
        ->expectsOutputToContain($sale->eic)
        ->expectsOutputToContain($sale->sequence_number)
        ->expectsOutputToContain('3')
        ->assertExitCode(1);
});

it('reports a production rejected sale that can be reopened without --force as well [R54]', function (): void {
    Sale::factory()->rejected(4)->create();

    $this->artisan('eet:status')->assertExitCode(1);
});

it('ignores playground sales for the exit code [R54]', function (): void {
    Sale::factory()->rejected(3)->state(['environment' => Environment::Playground, 'is_test' => true])->create();
    Sale::factory()->unconfirmed()->state(['environment' => Environment::Playground, 'is_test' => true, 'occurred_at' => frozenNow()->subHours(72)->setTimezone('Europe/Prague')->format('Y-m-d\TH:i:sP')])->create();

    $this->artisan('eet:status')->assertExitCode(0);
});

it('warns about verification mode when a production taxpayer exists and exits with 1 [R54, R46]', function (): void {
    config()->set('laravel-eet-module.mode', 'verification');
    Eet::createTaxpayer('CZ00000019', null, Environment::Production);

    $this->artisan('eet:status')->assertExitCode(1);
});

it('does not warn about verification mode without a production taxpayer [R54]', function (): void {
    config()->set('laravel-eet-module.mode', 'verification');
    Eet::createTaxpayer('CZ00000019', null, Environment::Playground);

    $this->artisan('eet:status')->assertExitCode(0);
});

it('warns while the production signer CN is not configured and a production taxpayer exists [R54, R35]', function (): void {
    config()->set('laravel-eet-module.response_signer.production.common_name', null);
    Eet::createTaxpayer('CZ00000019', null, Environment::Production);

    $this->artisan('eet:status')->assertExitCode(1);

    config()->set('laravel-eet-module.response_signer.production.common_name', 'EET produkcni prostredi');

    $this->artisan('eet:status')->assertExitCode(0);
});

it('prints the subject and fingerprint of the last verified production confirmation while the CN is not configured [R54, R35]', function (): void {
    config()->set('laravel-eet-module.response_signer.production.common_name', null);
    useTestResponseCa();
    useMessageUuids(testUuid(1));
    Eet::fake([FakeResponse::raw(EetResponse::confirmed(testUuid(1))->signedBy('signer-production')->toXml())]);
    $sale = register(cashRegister('CZ00000019', Environment::Production));
    $attempt = lastAttempt($sale);
    config()->set('laravel-eet-module.response_trust_store', null);
    DB::table('eet_sales')->update(['is_fake' => false]);
    DB::table('eet_submission_attempts')->update(['is_fake' => false]);

    $this->artisan('eet:status')
        ->expectsOutputToContain((string) $attempt->signer_fingerprint)
        ->assertExitCode(1);

    expect(strtolower((string) $attempt->signer_fingerprint))->toBe(Fixtures::fingerprint('response-ca/signer-production.pem'));
});

it('warns about a custom response trust store when a production taxpayer exists [R54, R35]', function (): void {
    useTestResponseCa();
    Eet::createTaxpayer('CZ00000019', null, Environment::Production);

    $this->artisan('eet:status')->assertExitCode(1);
});

it('does not warn about a custom trust store without a production taxpayer [R54]', function (): void {
    useTestResponseCa();
    Eet::createTaxpayer('CZ00000019', null, Environment::Playground);

    $this->artisan('eet:status')->assertExitCode(0);
});

it('reports a production sale created under the fake and exits with 1 [R54, R57]', function (): void {
    Eet::fake();
    register(cashRegister('CZ00000019', Environment::Production));

    $this->artisan('eet:status')->assertExitCode(1);
});

it('does not report a playground sale created under the fake [R54, R57]', function (): void {
    Eet::fake();
    register(cashRegister('CZ00000019', Environment::Playground));

    $this->artisan('eet:status')->assertExitCode(0);
});

it('prints the configuration key and exits with 1 on an invalid configuration [R54, R68]', function (): void {
    config()->set('laravel-eet-module.mode', 'staging');

    $this->artisan('eet:status')
        ->expectsOutputToContain('mode')
        ->assertExitCode(1);
});

it('validates only mode, response_timeout_ms, response_signer.* and response_trust_store [R54, R68]', function (string $key, mixed $value): void {
    config()->set('laravel-eet-module.'.$key, $value);

    $this->artisan('eet:status')->assertExitCode(0);
})->with([
    'lock store' => ['lock_store', 'does-not-exist'],
    'retention' => ['retention.raw_xml_days', 0],
    'default environment' => ['default_environment', 'staging'],
]);

it('rejects an invalid response signer setting or trust store [R54, R68]', function (string $key, mixed $value): void {
    config()->set('laravel-eet-module.'.$key, $value);

    $this->artisan('eet:status')
        ->expectsOutputToContain($key)
        ->assertExitCode(1);
})->with([
    ['response_signer.organization_identifier', 'GFR'],
    ['response_trust_store', '/nonexistent/eet-trust-store.pem'],
]);

it('validates response_timeout_ms, which defines the R63 boundary [R54, R63, R68]', function (mixed $value): void {
    config()->set('laravel-eet-module.response_timeout_ms', $value);

    $this->artisan('eet:status')
        ->expectsOutputToContain('response_timeout_ms')
        ->assertExitCode(1);
})->with([
    'string' => '4000ms',
    'below the legal minimum' => 1999,
]);

it('provides the factory states of the public contract [R54]', function (): void {
    $confirmed = Sale::factory()->confirmed()->create();
    $unconfirmed = Sale::factory()->unconfirmed()->create();
    $rejected = Sale::factory()->rejected(7)->create();
    $ambiguous = Sale::factory()->ambiguousRejected()->create();
    $playground = Sale::factory()->playground()->create();
    $overdue = Sale::factory()->overdue()->create();

    expect($confirmed->status)->toBe(SaleStatus::Confirmed)
        ->and($confirmed->environment)->toBe(Environment::Production)
        ->and($confirmed->mode)->toBe(SendingMode::Live)
        ->and($confirmed->hasValidConfirmationCode())->toBeTrue()
        ->and(attemptsOf($confirmed)->first()?->outcome?->value)->toBe('confirmed')
        ->and($unconfirmed->status)->toBe(SaleStatus::Unconfirmed)
        ->and(attemptsOf($unconfirmed)->pluck('outcome.value')->all())->toBe(['timeout'])
        ->and($rejected->status)->toBe(SaleStatus::Rejected)
        ->and(attemptsOf($rejected)->last()?->error_code)->toBe(7)
        ->and($rejected->rejecting_attempt_id)->toBe(attemptsOf($rejected)->last()?->getKey())
        ->and($ambiguous->status)->toBe(SaleStatus::Rejected)
        ->and(attemptsOf($ambiguous)->last()?->outcome?->value)->toBe('ambiguous_error')
        ->and(attemptsOf($ambiguous)->last()?->error_code)->toBe(8)
        ->and($playground->status)->toBe(SaleStatus::Confirmed)
        ->and($playground->environment)->toBe(Environment::Playground)
        ->and($playground->taxpayer->environment)->toBe(Environment::Playground)
        ->and($playground->hasValidConfirmationCode())->toBeFalse()
        ->and(attemptsOf($playground)->last()?->outcome?->value)->toBe('confirmed')
        ->and(attemptsOf($playground)->last()?->is_test)->toBeTrue()
        ->and($playground->is_test)->toBeTrue()
        ->and($playground->confirmation_code)->toEndWith('-ff')
        ->and($overdue->isOverdue())->toBeTrue()
        ->and($overdue->occurredAt()->equalTo(frozenNow()->subHours(49)))->toBeTrue()
        ->and(Taxpayer::query()->count())->toBeGreaterThan(0);
});
