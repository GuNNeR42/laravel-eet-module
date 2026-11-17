<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

use Gunner42\LaravelEetModule\Enums\RenewalProblem;

/**
 * A new renewal cannot start for the certificate (R90).
 */
final class CertificateNotRenewable extends EetException
{
    public function __construct(public readonly RenewalProblem $problem)
    {
        parent::__construct('The certificate cannot be renewed: '.$problem->value.'.');
    }
}
