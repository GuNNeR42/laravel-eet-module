<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Enums;

use Gunner42\LaravelEetModule\Enums\Concerns\HasLabel;

enum WarningCode: int
{
    use HasLabel;

    case EicMismatchesCertificate = 1;

    case InvalidDelegatingEicFormat = 2;

    case SaleInFuture = 4;

    case SaleTooOld = 5;

    case InvalidRegistrationUnitFormat = 6;

    case DelegatingEicEqualsTaxpayerEic = 7;

    protected static function translationGroup(): string
    {
        return 'warning_codes';
    }
}
