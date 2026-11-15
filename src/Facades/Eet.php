<?php

declare(strict_types=1);

namespace gunner42\Eet\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \gunner42\Eet\Eet
 */
class Eet extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \gunner42\Eet\Eet::class;
    }
}
