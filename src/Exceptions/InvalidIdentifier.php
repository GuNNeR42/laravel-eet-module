<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

final class InvalidIdentifier extends EetException
{
    /**
     * @param  'registrationUnitNumber'|'cashRegisterId'  $field
     */
    public function __construct(public readonly string $field)
    {
        parent::__construct("The value of [{$field}] does not match its mask.");
    }
}
