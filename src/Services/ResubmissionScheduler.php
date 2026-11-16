<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Jobs\ResubmitSale;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\SubmissionAttempt;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Schedules the ResubmitSale job after a retryable outcome, after the commit
 * and the release of the lock (R40).
 */
final class ResubmissionScheduler
{
    private const array FINAL_OUTCOMES = [AttemptOutcome::Confirmed, AttemptOutcome::Verified, AttemptOutcome::Rejected];

    public function __construct(private readonly Dispatcher $dispatcher) {}

    public function scheduleAfter(Sale $sale, SubmissionAttempt $attempt): void
    {
        $sale->refresh();

        if (in_array($attempt->outcome, self::FINAL_OUTCOMES, true) || $sale->status !== SaleStatus::Unconfirmed) {
            return;
        }

        $connection = config('laravel-eet-module.resubmission.connection');
        $queue = config('laravel-eet-module.resubmission.queue');

        $job = new ResubmitSale($sale->id);
        $job->connection = is_string($connection) ? $connection : null;
        $job->queue = is_string($queue) ? $queue : null;
        $job->delay = $this->delay($sale);

        $this->dispatcher->dispatch($job);
    }

    /**
     * The position in the backoff list is the number of all attempts since
     * creation or after reopened_after_attempt_id; the last value repeats (R40).
     */
    private function delay(Sale $sale): int
    {
        $backoff = config('laravel-eet-module.resubmission.backoff');

        if (! is_array($backoff) || $backoff === [] || ! array_is_list($backoff)) {
            throw new InvalidConfiguration('resubmission.backoff');
        }

        $query = $sale->attempts();

        if ($sale->reopened_after_attempt_id !== null) {
            $query->where('id', '>', $sale->reopened_after_attempt_id);
        }

        $index = max(0, $query->count() - 1);

        $delay = $backoff[min($index, count($backoff) - 1)];

        if (! is_int($delay)) {
            throw new InvalidConfiguration('resubmission.backoff');
        }

        return $delay;
    }
}
