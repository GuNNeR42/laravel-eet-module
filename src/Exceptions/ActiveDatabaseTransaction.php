<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

final class ActiveDatabaseTransaction extends EetException
{
    public function __construct(string $message = 'The operation must not run inside an open database transaction.')
    {
        parent::__construct($message);
    }
}
