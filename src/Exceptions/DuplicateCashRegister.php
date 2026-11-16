<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

final class DuplicateCashRegister extends EetException
{
    public function __construct(string $message = 'The registration unit already has a cash register with this identifier.')
    {
        parent::__construct($message);
    }
}
