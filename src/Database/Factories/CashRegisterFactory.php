<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Database\Factories;

use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\RegistrationUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashRegister>
 */
final class CashRegisterFactory extends Factory
{
    protected $model = CashRegister::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_unit_id' => RegistrationUnit::factory(),
            'identifier' => 'POKLADNA-'.random_int(1, 99_999_999),
            'name' => 'Cash register',
            'certificate_id' => null,
        ];
    }
}
