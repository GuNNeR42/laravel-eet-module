<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\SubmissionAttempt;
use Gunner42\LaravelEetModule\Services\ResubmissionScheduler;
use Gunner42\LaravelEetModule\Services\SaleLock;

/**
 * One attempt for a sale that is still Unconfirmed or stuck, decided under
 * the lock; never reopens a rejected sale (R43, R53, R63, R64). Used by the
 * job and by eet:resubmit without --sale. Returns null when nothing was sent.
 *
 * @internal
 */
final class ResubmitUnconfirmedSale
{
    public function __construct(
        private readonly SaleLock $lock,
        private readonly SendSaleAttempt $sendAttempt,
        private readonly ResubmissionScheduler $scheduler,
    ) {}

    public function handle(Sale $sale): ?SubmissionAttempt
    {
        $lock = $this->lock->acquire($sale->id);

        if ($lock === null) {
            return null;
        }

        try {
            $sale->refresh();

            if (! $sale->isAwaitingResubmission()) {
                return null;
            }

            $attempt = $this->sendAttempt->handle($sale);
        } finally {
            $lock->release();
        }

        $this->scheduler->scheduleAfter($sale, $attempt);

        return $attempt;
    }
}
