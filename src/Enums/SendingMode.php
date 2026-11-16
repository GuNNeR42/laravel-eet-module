<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Enums;

use Gunner42\LaravelEetModule\Enums\Concerns\HasLabel;

enum SendingMode: string
{
    use HasLabel;

    case Live = 'live';

    case Verification = 'verification';

    protected static function translationGroup(): string
    {
        return 'sending_modes';
    }
}
