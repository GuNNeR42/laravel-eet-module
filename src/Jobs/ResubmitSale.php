<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Jobs;

use Gunner42\LaravelEetModule\Actions\ResubmitUnconfirmedSale;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Resubmits an unconfirmed or stuck sale (R40–R43). Never reopens a
 * rejected sale (R64). Connection, queue and delay are plain properties read
 * by the bus, so dispatch_sync() runs the job in place.
 */
final class ResubmitSale implements ShouldQueue
{
    /** Queue connection (resubmission.connection, R40). */
    public ?string $connection = null;

    /** Queue name (resubmission.queue, R40). */
    public ?string $queue = null;

    /** Delay in seconds (resubmission.backoff, R40). */
    public ?int $delay = null;

    public function __construct(public readonly int $saleId) {}

    public function handle(ConfigurationValidator $configuration, ResubmitUnconfirmedSale $resubmit): void
    {
        $configuration->forSending();

        $sale = Sale::query()->find($this->saleId);

        if ($sale instanceof Sale && $sale->isAwaitingResubmission()) {
            $resubmit->handle($sale);
        }
    }
}
