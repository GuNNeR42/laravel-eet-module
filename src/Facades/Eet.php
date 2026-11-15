<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Gunner42\LaravelEetModule\Eet
 */
class Eet extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Gunner42\LaravelEetModule\Eet::class;
    }
}
