<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;

/**
 * Changes the environment of a taxpayer; existing sales keep theirs (R59).
 */
final class ChangeTaxpayerEnvironment
{
    public function __construct(private readonly ConfigurationValidator $configuration) {}

    public function handle(Taxpayer $taxpayer, Environment $environment): Taxpayer
    {
        $this->configuration->forTaxpayer();

        $taxpayer->environment = $environment;
        $taxpayer->save();

        return $taxpayer;
    }
}
