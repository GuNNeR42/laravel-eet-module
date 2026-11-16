<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

final class InvalidConfiguration extends EetException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct("Invalid configuration value of [laravel-eet-module.{$key}].");
    }
}
