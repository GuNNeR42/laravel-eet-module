<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Gunner42\LaravelEetModule\Exceptions\CertificateTaxpayerMismatch;
use Gunner42\LaravelEetModule\Exceptions\DuplicateCashRegister;
use Gunner42\LaravelEetModule\Exceptions\InvalidIdentifier;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\RegistrationUnit;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creates a cash register, optionally with a pinned certificate (R6–R8).
 */
final class CreateCashRegister
{
    public function handle(RegistrationUnit $unit, string $identifier, ?string $name = null, ?CashRegisterCertificate $certificate = null): CashRegister
    {
        if (preg_match('/^[0-9a-zA-Z.,:;\/#\-_ ]{1,20}$/D', $identifier) !== 1) {
            throw new InvalidIdentifier('cashRegisterId');
        }

        if ($certificate !== null && $certificate->taxpayer_id !== $unit->taxpayer_id) {
            throw new CertificateTaxpayerMismatch;
        }

        if ($unit->cashRegisters()->where('identifier', $identifier)->exists()) {
            throw new DuplicateCashRegister;
        }

        try {
            return $unit->cashRegisters()->create([
                'identifier' => $identifier,
                'name' => $name,
                'certificate_id' => $certificate?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateCashRegister;
        }
    }
}
