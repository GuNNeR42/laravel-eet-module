<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

final class DuplicateTaxpayer extends EetException
{
    public function __construct(string $message = 'A taxpayer with this EIČ already exists.')
    {
        parent::__construct($message);
    }
}
