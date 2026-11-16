<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

final class ReversalTaxpayerMismatch extends EetException
{
    public function __construct(string $message = 'The cash register of the reversal belongs to another taxpayer than the original sale.')
    {
        parent::__construct($message);
    }
}
