<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Enums;

use Gunner42\LaravelEetModule\Enums\Concerns\HasLabel;

enum Environment: string
{
    use HasLabel;

    case Playground = 'playground';

    case Production = 'production';

    protected static function translationGroup(): string
    {
        return 'environments';
    }
}
