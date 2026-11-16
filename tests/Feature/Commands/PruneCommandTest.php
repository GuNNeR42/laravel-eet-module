<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Actions\PruneRawMessages;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\SubmissionAttempt;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

/**
 * Creates a rejected sale whose attempt stores request and response XML,
 * at the given number of days before NOW.
 */
function saleWithRawXml(int $daysAgo, string $sequenceNumber): Sale
{
    test()->travelTo(frozenNow()->subDays($daysAgo));
    $uuid = testUuid(100 + $daysAgo);
    useMessageUuids($uuid);
    Eet::fake([FakeResponse::raw(EetResponse::error($uuid, 3)->test('true')->toXml(), 200, ['X-Global-Transaction-Id' => 'gid-'.$daysAgo])]);
    $sale = register(cashRegister(), saleData($sequenceNumber));
    test()->travelTo(frozenNow());

    return $sale;
}

it('deletes the raw XML of attempts older than --days and keeps sales, attempts, POK and metadata [R52]', function (): void {
    $old = saleWithRawXml(31, 'OLD');
    $recent = saleWithRawXml(29, 'NEW');

    $this->artisan('eet:prune', ['--days' => '30'])->assertExitCode(0);

    $oldAttempt = lastAttempt($old);
    $recentAttempt = lastAttempt($recent);

    expect($oldAttempt->request_xml)->toBeNull()
        ->and($oldAttempt->response_xml)->toBeNull()
        ->and($oldAttempt->error_code)->toBe(3)
        ->and($oldAttempt->global_transaction_id)->toBe('gid-31')
        ->and($oldAttempt->message_uuid)->toBe(testUuid(131))
        ->and($recentAttempt->request_xml)->not->toBeNull()
        ->and($recentAttempt->response_xml)->not->toBeNull()
        ->and(Sale::query()->count())->toBe(2)
        ->and(SubmissionAttempt::query()->count())->toBe(2);
});

it('keeps the POK of a confirmed sale when pruning [R52]', function (): void {
    $this->travelTo(frozenNow()->subDays(40));
    Eet::fake();
    $sale = register(cashRegister(), saleData('POK'));
    $pok = $sale->confirmation_code;
    $this->travelTo(frozenNow());

    $this->artisan('eet:prune', ['--days' => '30'])->assertExitCode(0);

    expect(freshSale($sale)->confirmation_code)->toBe($pok)
        ->and(lastAttempt($sale)->confirmation_code)->toBe($pok)
        ->and(lastAttempt($sale)->response_xml)->toBeNull();
});

it('deletes nothing without --days when retention.raw_xml_days is null and exits with 0 [R52]', function (): void {
    $old = saleWithRawXml(100, 'OLD');

    $this->artisan('eet:prune')->assertExitCode(0);

    expect(lastAttempt($old)->request_xml)->not->toBeNull()
        ->and(lastAttempt($old)->response_xml)->not->toBeNull();
});

it('uses retention.raw_xml_days when --days is missing [R52]', function (): void {
    config()->set('laravel-eet-module.retention.raw_xml_days', 10);
    $old = saleWithRawXml(11, 'OLD');
    $recent = saleWithRawXml(9, 'NEW');

    $this->artisan('eet:prune')->assertExitCode(0);

    expect(lastAttempt($old)->response_xml)->toBeNull()
        ->and(lastAttempt($recent)->response_xml)->not->toBeNull();
});

it('prints the key and exits with 1 on an invalid retention configuration [R52, R68]', function (): void {
    $old = saleWithRawXml(100, 'OLD');
    config()->set('laravel-eet-module.retention.raw_xml_days', 0);

    $this->artisan('eet:prune')
        ->expectsOutputToContain('retention.raw_xml_days')
        ->assertExitCode(1);

    expect(lastAttempt($old)->response_xml)->not->toBeNull();
});

it('validates only retention.* [R52, R68]', function (): void {
    $old = saleWithRawXml(100, 'OLD');
    config()->set('laravel-eet-module.mode', 'dry-run');
    config()->set('laravel-eet-module.lock_store', 'does-not-exist');

    $this->artisan('eet:prune', ['--days' => '30'])->assertExitCode(0);

    expect(lastAttempt($old)->response_xml)->toBeNull();
});

it('returns the number of pruned attempts from the action [R52]', function (): void {
    saleWithRawXml(50, 'A');
    saleWithRawXml(45, 'B');
    saleWithRawXml(5, 'C');

    expect(app(PruneRawMessages::class)->handle(30))->toBe(2)
        ->and(SubmissionAttempt::query()->whereNull('response_xml')->count())->toBe(2);
});
