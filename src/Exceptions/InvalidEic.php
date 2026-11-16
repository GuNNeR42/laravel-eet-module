<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

final class InvalidEic extends EetException
{
    public function __construct(public readonly string $value)
    {
        parent::__construct('The EIČ does not match the mask ^CZ[0-9]{8,10}$.');
    }
}
