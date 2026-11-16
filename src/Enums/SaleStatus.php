<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Enums;

use Gunner42\LaravelEetModule\Enums\Concerns\HasLabel;

enum SaleStatus: string
{
    use HasLabel;

    case Pending = 'pending';

    case Confirmed = 'confirmed';

    case Verified = 'verified';

    case Unconfirmed = 'unconfirmed';

    case Rejected = 'rejected';

    protected static function translationGroup(): string
    {
        return 'sale_statuses';
    }
}
