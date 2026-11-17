<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Enums\RenewalProblem;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;

/**
 * Why a new renewal of the certificate cannot start, in the order of R90;
 * null when it can. Reads only.
 */
final class RenewalPreconditions
{
    public function problem(CashRegisterCertificate $certificate): ?RenewalProblem
    {
        return match (true) {
            ! $certificate->hasProductionPolicy() => RenewalProblem::NotProductionCertificate,
            ! $certificate->isValidAt(CarbonImmutable::now()) => RenewalProblem::CertificateNotValid,
            ! $certificate->isUsable() => RenewalProblem::CertificateUnusable,
            $certificate->successor() !== null => RenewalProblem::AlreadyRenewed,
            default => null,
        };
    }
}
