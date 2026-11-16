<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

final class DuplicateSequenceNumber extends EetException
{
    public function __construct(string $message = 'A sale with the same sequence number, time and cash register but different data already exists.')
    {
        parent::__construct($message);
    }
}
