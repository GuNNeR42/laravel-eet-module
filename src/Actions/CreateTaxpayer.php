<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Exceptions\DuplicateTaxpayer;
use Gunner42\LaravelEetModule\Exceptions\InvalidEic;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creates a taxpayer (R1, R2, R59).
 */
final class CreateTaxpayer
{
    public function __construct(private readonly ConfigurationValidator $configuration) {}

    public function handle(string $eic, ?string $name = null, ?Environment $environment = null): Taxpayer
    {
        $this->configuration->forTaxpayer();

        if (preg_match('/^CZ[0-9]{8,10}$/D', $eic) !== 1) {
            throw new InvalidEic($eic);
        }

        if (Taxpayer::query()->where('eic', $eic)->exists()) {
            throw new DuplicateTaxpayer;
        }

        try {
            return Taxpayer::query()->create([
                'eic' => $eic,
                'name' => $name,
                'environment' => $environment ?? Environment::from((string) config('laravel-eet-module.default_environment')),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateTaxpayer;
        }
    }
}
