<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Database\Factories;

use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Taxpayer>
 */
final class TaxpayerFactory extends Factory
{
    protected $model = Taxpayer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'eic' => 'CZ'.random_int(1_000_000_000, 9_999_999_999),
            'name' => 'Taxpayer',
            'environment' => Environment::Production,
        ];
    }

    public function playground(): self
    {
        return $this->state(['environment' => Environment::Playground]);
    }
}
