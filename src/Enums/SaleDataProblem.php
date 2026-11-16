<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Enums;

use Gunner42\LaravelEetModule\Enums\Concerns\HasLabel;

enum SaleDataProblem: string
{
    use HasLabel;

    case InvalidFormat = 'invalid_format';

    case DelegatingEicEqualsTaxpayerEic = 'delegating_eic_equals_taxpayer_eic';

    case TooOld = 'too_old';

    case BeforeEnvironmentStart = 'before_environment_start';

    case SignMismatch = 'sign_mismatch';

    case PartsExceedTotal = 'parts_exceed_total';

    protected static function translationGroup(): string
    {
        return 'sale_data_problems';
    }
}
