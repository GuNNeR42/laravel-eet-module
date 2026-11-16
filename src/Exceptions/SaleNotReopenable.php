<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

final class SaleNotReopenable extends EetException
{
    public function __construct(public readonly ?int $errorCode)
    {
        parent::__construct('The rejected sale can be reopened only with force (error code '.($errorCode ?? 'unknown').').');
    }
}
