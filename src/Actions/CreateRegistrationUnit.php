<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Gunner42\LaravelEetModule\Exceptions\DuplicateRegistrationUnit;
use Gunner42\LaravelEetModule\Exceptions\InvalidIdentifier;
use Gunner42\LaravelEetModule\Models\RegistrationUnit;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creates a registration unit of a taxpayer (R3–R5).
 */
final class CreateRegistrationUnit
{
    public function handle(Taxpayer $taxpayer, int $number, ?string $name = null): RegistrationUnit
    {
        if ($number < 1 || $number > 999_999_999) {
            throw new InvalidIdentifier('registrationUnitNumber');
        }

        if ($taxpayer->registrationUnits()->where('number', $number)->exists()) {
            throw new DuplicateRegistrationUnit;
        }

        try {
            return $taxpayer->registrationUnits()->create(['number' => $number, 'name' => $name]);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateRegistrationUnit;
        }
    }
}
