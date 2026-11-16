<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Database\Factories;

use Gunner42\LaravelEetModule\Models\RegistrationUnit;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationUnit>
 */
final class RegistrationUnitFactory extends Factory
{
    protected $model = RegistrationUnit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'taxpayer_id' => Taxpayer::factory(),
            // the DIS+ form: at least two digits, the last one 1–4
            'number' => random_int(1, 99_999_999) * 10 + 1,
            'name' => 'Registration unit',
        ];
    }
}
