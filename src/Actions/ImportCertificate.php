<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Data\ParsedCertificate;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Services\CertificateReader;
use Gunner42\LaravelEetModule\Services\X509;
use Illuminate\Database\UniqueConstraintViolationException;
use SensitiveParameter;

/**
 * Imports a cash register certificate of a taxpayer (R9–R15, R70). The key
 * is encrypted by the Laravel encrypter before it reaches Eloquent, the
 * password is never stored, and exceptions raised during the import carry no
 * arguments in their trace (R15).
 */
final class ImportCertificate
{
    public function __construct(private readonly CertificateReader $reader) {}

    public function handle(Taxpayer $taxpayer, #[SensitiveParameter] string $contents, #[SensitiveParameter] ?string $password = null): CashRegisterCertificate
    {
        $previous = ini_set('zend.exception_ignore_args', '1');

        try {
            return $this->import($taxpayer, $this->reader->read($contents, $password));
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }

            X509::clearErrors();
        }
    }

    private function import(Taxpayer $taxpayer, ParsedCertificate $parsed): CashRegisterCertificate
    {
        $existing = $this->existing($taxpayer, $parsed->fingerprint);

        if ($existing !== null) {
            return $existing;
        }

        $certificate = new CashRegisterCertificate;
        $certificate->forceFill([
            'taxpayer_id' => $taxpayer->id,
            'serial_number' => $parsed->serialNumber,
            'subject_eic' => $parsed->commonName,
            'valid_from' => CarbonImmutable::createFromTimestamp($parsed->validFrom)->setTimezone(date_default_timezone_get()),
            'valid_to' => CarbonImmutable::createFromTimestamp($parsed->validTo)->setTimezone(date_default_timezone_get()),
            'fingerprint_sha256' => $parsed->fingerprint,
            'certificate' => $parsed->certificatePem,
            'chain' => $parsed->chainPem,
        ]);
        $certificate->setEncryptedPrivateKey($parsed->privateKey);

        try {
            $certificate->save();
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($taxpayer, $parsed->fingerprint) ?? throw $exception;
        }

        return $certificate;
    }

    private function existing(Taxpayer $taxpayer, string $fingerprint): ?CashRegisterCertificate
    {
        return $taxpayer->certificates()->where('fingerprint_sha256', $fingerprint)->first();
    }
}
