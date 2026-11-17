<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Events;

use Gunner42\LaravelEetModule\Enums\RenewalProblem;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\CertificateRenewal;
use Illuminate\Queue\SerializesModels;

/**
 * A renewal ended in Failed; dispatched after the commit (R102).
 */
final class CertificateRenewalFailed
{
    use SerializesModels;

    public function __construct(
        public readonly CashRegisterCertificate $certificate,
        public readonly CertificateRenewal $renewal,
        public readonly RenewalProblem $problem,
    ) {}
}
