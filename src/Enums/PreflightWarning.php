<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Enums;

use Gunner42\LaravelEetModule\Enums\Concerns\HasLabel;

enum PreflightWarning: string
{
    use HasLabel;

    case SaleInFuture = 'sale_in_future';

    case UnusualRegistrationUnitNumber = 'unusual_registration_unit_number';

    case CertificateEicMismatch = 'certificate_eic_mismatch';

    protected static function translationGroup(): string
    {
        return 'preflight_warnings';
    }
}
