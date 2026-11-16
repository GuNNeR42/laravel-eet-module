<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

use Gunner42\LaravelEetModule\Enums\CertificateProblem;

final class InvalidCertificate extends EetException
{
    public function __construct(public readonly CertificateProblem $problem)
    {
        $message = 'The certificate cannot be imported: '.$problem->value.'.';

        if ($problem === CertificateProblem::UnsupportedLegacyFormat) {
            $message .= ' The runtime cannot read the legacy PKCS#12 format of CA EET; convert the file to PEM as described in the README.';
        }

        parent::__construct($message);
    }
}
