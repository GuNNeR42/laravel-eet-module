<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

final class DuplicateRegistrationUnit extends EetException
{
    public function __construct(string $message = 'The taxpayer already has a registration unit with this number.')
    {
        parent::__construct($message);
    }
}
