<?php

declare(strict_types=1);

return [

    'error_codes' => [
        'TemporaryTechnicalError' => 'Temporary technical error, the message will be sent again',
        'VerificationSucceeded' => 'The message in verification mode was processed successfully',
        'InvalidXmlEncoding' => 'Invalid XML encoding',
        'SchemaValidationFailed' => 'The message does not match the XML schema',
        'InvalidSignature' => 'Invalid signature of the SOAP message',
        'InvalidEicStructure' => 'Invalid structure of the EIČ',
        'MessageTooLarge' => 'The message is too large',
        'ProcessingFailed' => 'The message could not be processed',
    ],

    'warning_codes' => [
        'EicMismatchesCertificate' => 'The taxpayer EIČ differs from the EIČ in the certificate',
        'InvalidDelegatingEicFormat' => 'Invalid format of the EIČ of the delegating taxpayer',
        'SaleInFuture' => 'The date and time of the sale are in the future',
        'SaleTooOld' => 'The date and time of the sale are far in the past',
        'InvalidRegistrationUnitFormat' => 'The registration unit number does not have the assigned format',
        'DelegatingEicEqualsTaxpayerEic' => 'The EIČ of the delegating taxpayer equals the taxpayer EIČ',
    ],

    'sale_statuses' => [
        'Pending' => 'Pending',
        'Confirmed' => 'Confirmed',
        'Verified' => 'Verified',
        'Unconfirmed' => 'Unconfirmed',
        'Rejected' => 'Rejected',
    ],

    'attempt_outcomes' => [
        'Confirmed' => 'Confirmed',
        'Verified' => 'Verified',
        'Rejected' => 'Rejected',
        'TemporaryError' => 'Temporary error',
        'AmbiguousError' => 'Ambiguous error',
        'Timeout' => 'Timeout',
        'TransportError' => 'Transport error',
        'InvalidResponseSignature' => 'Invalid response signature',
        'ResponseMismatch' => 'Response mismatch',
        'CertificateUnavailable' => 'No valid certificate',
    ],

    'certificate_problems' => [
        'Unreadable' => 'The file is damaged or the password is wrong',
        'MissingPrivateKey' => 'The file contains no private key',
        'KeyMismatch' => 'The private key does not belong to the certificate',
        'Expired' => 'The certificate has expired',
        'UnsupportedLegacyFormat' => 'The runtime cannot read the legacy PKCS#12 format; convert the file to PEM',
    ],

    'sale_data_problems' => [
        'InvalidFormat' => 'The value does not match the required format',
        'DelegatingEicEqualsTaxpayerEic' => 'The EIČ of the delegating taxpayer equals the taxpayer EIČ',
        'TooOld' => 'The sale is more than two years old',
        'BeforeEnvironmentStart' => 'The sale is older than the start of the environment',
        'SignMismatch' => 'The amount has the opposite sign than the total amount',
        'PartsExceedTotal' => 'The partial amounts exceed the total amount',
    ],

    'preflight_warnings' => [
        'SaleInFuture' => 'The sale is more than two hours in the future',
        'UnusualRegistrationUnitNumber' => 'The registration unit number does not have the DIS+ format',
        'CertificateEicMismatch' => 'The CN of the signing certificate differs from the taxpayer EIČ',
    ],

    'sending_modes' => [
        'Live' => 'Live mode',
        'Verification' => 'Verification mode',
    ],

    'environments' => [
        'Playground' => 'Playground',
        'Production' => 'Production',
    ],

    'renewal_statuses' => [
        'Requesting' => 'Requesting a certificate',
        'InProcess' => 'Waiting for the certificate',
        'Downloading' => 'Downloading the certificate',
        'Acknowledging' => 'Acknowledging the download',
        'Completed' => 'Completed',
        'Failed' => 'Failed',
    ],

    'renewal_problems' => [
        'NotProductionCertificate' => 'Only production certificates are renewed',
        'CertificateNotValid' => 'The certificate is not valid',
        'CertificateUnusable' => 'The private key of the certificate cannot be decrypted',
        'AlreadyRenewed' => 'The certificate has already been renewed',
        'TemporaryError' => 'Temporary error of the CA EET API; the next run tries again',
        'UnknownPendingRequest' => 'An unknown certificate request is pending at CA EET; finish it in DIS+ or wait until it ends',
        'Rejected' => 'CA EET rejected the request',
        'AlreadyFinished' => 'CA EET already finished the request',
        'RequestFailed' => 'CA EET refused the call',
        'CertificateUnreadable' => 'The downloaded certificate cannot be read',
        'CertificateMismatch' => 'The downloaded certificate does not match the renewed certificate',
    ],

];
