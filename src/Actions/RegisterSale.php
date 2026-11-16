<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Gunner42\LaravelEetModule\Data\SaleData;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Gunner42\LaravelEetModule\Services\DatabaseTransactionGuard;

/**
 * Registers a sale and sends it synchronously (R19–R47, R61–R68, R72).
 */
final class RegisterSale
{
    public function __construct(
        private readonly ConfigurationValidator $configuration,
        private readonly DatabaseTransactionGuard $transactions,
        private readonly SubmitNewSale $submit,
    ) {}

    public function handle(CashRegister $cashRegister, SaleData $data): Sale
    {
        $this->configuration->forSending();
        $this->transactions->ensureNoTransaction();

        return $this->submit->handle($cashRegister, $data);
    }
}
