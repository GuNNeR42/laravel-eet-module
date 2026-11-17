<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Events;

use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\CertificateRenewal;
use Illuminate\Queue\SerializesModels;

/**
 * The successor of a certificate was stored and the cash registers pinned to
 * the renewed certificate now use it; dispatched after the commit (R96).
 */
final class CertificateRenewed
{
    use SerializesModels;

    public function __construct(
        public readonly CashRegisterCertificate $certificate,
        public readonly CashRegisterCertificate $successor,
        public readonly CertificateRenewal $renewal,
    ) {}
}
