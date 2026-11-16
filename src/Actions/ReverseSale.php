<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Gunner42\LaravelEetModule\Data\ReversalData;
use Gunner42\LaravelEetModule\Data\SaleData;
use Gunner42\LaravelEetModule\Exceptions\InvalidAmount;
use Gunner42\LaravelEetModule\Exceptions\ReversalEnvironmentMismatch;
use Gunner42\LaravelEetModule\Exceptions\ReversalTaxpayerMismatch;
use Gunner42\LaravelEetModule\Models\RegistrationUnit;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Gunner42\LaravelEetModule\Services\DatabaseTransactionGuard;

/**
 * Registers a reversal of a sale as a new sale with negated amounts and a
 * local link to the original (R48–R50).
 */
final class ReverseSale
{
    public function __construct(
        private readonly ConfigurationValidator $configuration,
        private readonly DatabaseTransactionGuard $transactions,
        private readonly SubmitNewSale $submit,
    ) {}

    public function handle(Sale $original, ReversalData $data): Sale
    {
        $this->configuration->forSending();
        $this->transactions->ensureNoTransaction();

        if (! $data->amount->isPositive() || ($data->drawnAmount !== null && ! $data->drawnAmount->isPositive())) {
            throw new InvalidAmount;
        }

        $taxpayer = Taxpayer::query()->findOrFail(RegistrationUnit::query()->findOrFail($data->cashRegister->registration_unit_id)->taxpayer_id);

        if ($taxpayer->id !== $original->taxpayer_id) {
            throw new ReversalTaxpayerMismatch;
        }

        if ($original->environment !== $taxpayer->environment || $original->mode->value !== config('laravel-eet-module.mode')) {
            throw new ReversalEnvironmentMismatch;
        }

        return $this->submit->handle($data->cashRegister, new SaleData(
            $data->sequenceNumber,
            $data->occurredAt,
            $data->amount->negate(),
            null,
            $data->drawnAmount?->negate(),
            $original->delegating_eic,
            $original->delegated_by_multiple_taxpayers,
        ), $original);
    }
}
