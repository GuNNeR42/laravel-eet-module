<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

final class InvalidAmount extends EetException
{
    public function __construct(string $message = 'The amount is outside the allowed range or format.')
    {
        parent::__construct($message);
    }
}
