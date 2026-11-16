<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Events\SaleReopened;
use Gunner42\LaravelEetModule\Exceptions\SaleNotReopenable;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\SubmissionAttempt;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Gunner42\LaravelEetModule\Services\DatabaseTransactionGuard;
use Gunner42\LaravelEetModule\Services\ResubmissionScheduler;
use Gunner42\LaravelEetModule\Services\SaleLock;

/**
 * One immediate attempt for an unconfirmed or stuck sale; reopens a rejected
 * sale (R45, R63, R64, R72). Other sales are returned unchanged.
 */
final class ResubmitSale
{
    public function __construct(
        private readonly ConfigurationValidator $configuration,
        private readonly DatabaseTransactionGuard $transactions,
        private readonly SaleLock $lock,
        private readonly SendSaleAttempt $sendAttempt,
        private readonly ResubmissionScheduler $scheduler,
    ) {}

    public function handle(Sale $sale, bool $force = false): Sale
    {
        $this->configuration->forSending();
        $this->transactions->ensureNoTransaction();

        $lock = $this->lock->acquire($sale->id);

        if ($lock === null) {
            return $sale;
        }

        try {
            $sale->refresh();

            if ($sale->status === SaleStatus::Rejected) {
                $this->reopen($sale, $force);
            } elseif (! $sale->isAwaitingResubmission()) {
                return $sale;
            }

            $attempt = $this->sendAttempt->handle($sale);
        } finally {
            $lock->release();
        }

        $this->scheduler->scheduleAfter($sale, $attempt);

        return $sale->refresh();
    }

    /**
     * Rejected → Unconfirmed; without force only after code 4 or the
     * AmbiguousError limit, decided by the rejecting attempt (R64).
     */
    private function reopen(Sale $sale, bool $force): void
    {
        $rejecting = $sale->rejecting_attempt_id === null ? null : SubmissionAttempt::query()->find($sale->rejecting_attempt_id);
        $reopenable = $rejecting !== null
            && ($rejecting->outcome === AttemptOutcome::AmbiguousError
                || ($rejecting->outcome === AttemptOutcome::Rejected && $rejecting->error_code === 4));

        if (! $reopenable && ! $force) {
            throw new SaleNotReopenable($rejecting?->error_code);
        }

        $lastAttemptId = $sale->attempts()->max('id');

        $updated = Sale::query()
            ->whereKey($sale->id)
            ->where('status', SaleStatus::Rejected->value)
            ->update([
                'status' => SaleStatus::Unconfirmed->value,
                'rejecting_attempt_id' => null,
                'reopened_at' => now(),
                'reopened_after_attempt_id' => is_numeric($lastAttemptId) ? (int) $lastAttemptId : null,
                'updated_at' => now(),
            ]);

        $sale->refresh();

        if ($updated === 1) {
            event(new SaleReopened($sale));
        }
    }
}
