<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Console\Commands;

use Gunner42\LaravelEetModule\Actions\NotifyOverdueSale;
use Gunner42\LaravelEetModule\Actions\ResubmitSale;
use Gunner42\LaravelEetModule\Actions\ResubmitUnconfirmedSale;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Exceptions\SaleNotReopenable;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * One immediate attempt for every unconfirmed or stuck sale, or for the
 * given sales including reopening (R53, R63, R64).
 */
final class ResubmitCommand extends Command
{
    protected $signature = 'eet:resubmit
        {--sale=* : IDs of the sales to resubmit or reopen}
        {--force : Reopen sales rejected with codes 2, 3, 6 or 7 (only with --sale)}';

    protected $description = 'Resubmit unconfirmed sales now, ignoring the backoff.';

    private bool $failed = false;

    public function handle(ConfigurationValidator $configuration, ResubmitSale $resubmit, ResubmitUnconfirmedSale $resubmitUnconfirmed, NotifyOverdueSale $notifyOverdue): int
    {
        try {
            $configuration->forSending();
        } catch (InvalidConfiguration $exception) {
            $this->error("Invalid configuration: laravel-eet-module.{$exception->key}");

            return self::FAILURE;
        }

        $ids = array_values(array_filter((array) $this->input->getOption('sale'), is_string(...)));

        if ($ids === []) {
            // without --sale nothing is ever reopened: the state is checked again under the lock (R53, R64)
            Sale::query()->awaitingResubmission()->orderBy('id')->get()
                ->each(fn (Sale $sale) => $this->processUnconfirmed($sale, $resubmitUnconfirmed, $notifyOverdue));
        } else {
            foreach ($ids as $id) {
                $sale = ctype_digit($id) ? Sale::query()->find((int) $id) : null;

                if (! $sale instanceof Sale) {
                    $this->error("Sale {$id} does not exist.");
                    $this->failed = true;

                    continue;
                }

                $this->process($sale, $this->input->getOption('force') === true, $resubmit, $notifyOverdue);
            }
        }

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function processUnconfirmed(Sale $sale, ResubmitUnconfirmedSale $resubmit, NotifyOverdueSale $notifyOverdue): void
    {
        try {
            $attempt = $resubmit->handle($sale);
        } catch (Throwable $exception) {
            $this->unexpected($sale, $exception);

            return;
        }

        $sale->refresh();
        $notifyOverdue->handle($sale);

        if ($attempt === null) {
            $this->line("Sale {$sale->id} skipped ({$sale->status->label()}).");

            return;
        }

        $this->line("Sale {$sale->id}: {$sale->status->label()}.");

        if ($sale->isAwaitingResubmission()) {
            $this->failed = true;
        }
    }

    private function process(Sale $sale, bool $force, ResubmitSale $resubmit, NotifyOverdueSale $notifyOverdue): void
    {
        $attemptsBefore = $sale->attempts()->count();

        try {
            $sale = $resubmit->handle($sale, $force);
        } catch (SaleNotReopenable $exception) {
            $this->error("Sale {$sale->id} cannot be reopened without --force (error code ".($exception->errorCode ?? 'unknown').').');
            $this->failed = true;

            return;
        } catch (Throwable $exception) {
            $this->unexpected($sale, $exception);

            return;
        }

        $notifyOverdue->handle($sale);

        if ($sale->attempts()->count() === $attemptsBefore) {
            $this->line("Sale {$sale->id} skipped ({$sale->status->label()}).");

            return;
        }

        $this->line("Sale {$sale->id}: {$sale->status->label()}.");

        if ($sale->isAwaitingResubmission()) {
            $this->failed = true;
        }
    }

    /**
     * An unexpected exception of one sale is reported, the other sales
     * continue and the command exits with 1 (R53).
     */
    private function unexpected(Sale $sale, Throwable $exception): void
    {
        app(ExceptionHandler::class)->report($exception);
        $this->error("Sale {$sale->id} failed with an unexpected error; see the application log.");
        $this->failed = true;
    }
}
