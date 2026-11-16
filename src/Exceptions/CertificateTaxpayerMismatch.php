<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

final class CertificateTaxpayerMismatch extends EetException
{
    public function __construct(string $message = 'The certificate belongs to another taxpayer.')
    {
        parent::__construct($message);
    }
}
