<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Data\ParsedCertificate;
use Gunner42\LaravelEetModule\Enums\CertificateProblem;
use Gunner42\LaravelEetModule\Exceptions\InvalidCertificate;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use SensitiveParameter;

/**
 * Reads a cash register certificate from PKCS#12 or PEM (R9, R10, R12, R70).
 * Contents, password and key never leave this class except as
 * OpenSSLAsymmetricKey (R15).
 */
final class CertificateReader
{
    public function read(#[SensitiveParameter] string $contents, #[SensitiveParameter] ?string $password): ParsedCertificate
    {
        X509::clearErrors();

        [$certificate, $key, $chain] = str_contains($contents, '-----BEGIN')
            ? $this->readPem($contents, $password)
            : $this->readPkcs12($contents, $password);

        if (! openssl_x509_check_private_key($certificate, $key)) {
            X509::clearErrors();

            throw self::problem(CertificateProblem::KeyMismatch);
        }

        if (X509::validTo($certificate) < CarbonImmutable::now()->getTimestamp()) {
            throw self::problem(CertificateProblem::Expired);
        }

        $chainPem = implode('', array_map(static fn (OpenSSLCertificate $item): string => X509::toPem($item), $chain));

        return new ParsedCertificate(
            X509::toPem($certificate),
            $chainPem === '' ? null : $chainPem,
            $key,
            X509::commonName($certificate),
            X509::serialNumberHex($certificate),
            X509::validFrom($certificate),
            X509::validTo($certificate),
            X509::fingerprint($certificate),
        );
    }

    /**
     * The exception is created without arguments in its trace, so no frame
     * can expose the contents, the password or the key (R15).
     */
    private static function problem(CertificateProblem $problem): InvalidCertificate
    {
        $previous = ini_set('zend.exception_ignore_args', '1');

        try {
            return new InvalidCertificate($problem);
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    /**
     * The file needs a password: PKCS#12 or an encrypted PEM key (R18).
     */
    public function needsPassword(#[SensitiveParameter] string $contents): bool
    {
        if (! str_contains($contents, '-----BEGIN')) {
            return true;
        }

        return str_contains($contents, 'ENCRYPTED PRIVATE KEY') || str_contains($contents, 'Proc-Type: 4,ENCRYPTED');
    }

    /**
     * The runtime can decrypt the legacy PKCS#12 of CA EET (RC2-40 certificates,
     * 3DES key), i.e. OpenSSL has the legacy algorithms available (R70).
     */
    public function supportsLegacyPkcs12(): bool
    {
        $supported = in_array('rc2-40-cbc', openssl_get_cipher_methods(), true)
            && openssl_encrypt('probe', 'rc2-40-cbc', 'probe-key', OPENSSL_RAW_DATA, '12345678') !== false
            && openssl_encrypt('probe', 'des-ede3-cbc', str_repeat('k', 24), OPENSSL_RAW_DATA, '12345678') !== false;
        X509::clearErrors();

        return $supported;
    }

    /**
     * @return array{OpenSSLCertificate, OpenSSLAsymmetricKey, list<OpenSSLCertificate>}
     */
    private function readPem(#[SensitiveParameter] string $contents, #[SensitiveParameter] ?string $password): array
    {
        $certificates = [];

        foreach (X509::pemBlocks($contents) as $block) {
            $certificate = X509::read($block);

            if ($certificate === null) {
                throw self::problem(CertificateProblem::Unreadable);
            }

            $certificates[] = $certificate;
        }

        if ($certificates === []) {
            throw self::problem(CertificateProblem::Unreadable);
        }

        if (preg_match('/-----BEGIN ((?:ENCRYPTED |RSA |EC )?PRIVATE KEY)-----.+?-----END \1-----/s', $contents, $match) !== 1) {
            throw self::problem(CertificateProblem::MissingPrivateKey);
        }

        $key = openssl_pkey_get_private($match[0], $password ?? '');
        X509::clearErrors();

        if ($key === false) {
            throw self::problem(CertificateProblem::Unreadable);
        }

        $leaf = array_shift($certificates);

        return [$leaf, $key, $certificates];
    }

    /**
     * @return array{OpenSSLCertificate, OpenSSLAsymmetricKey, list<OpenSSLCertificate>}
     */
    private function readPkcs12(#[SensitiveParameter] string $contents, #[SensitiveParameter] ?string $password): array
    {
        if ($contents === '' || ! openssl_pkcs12_read($contents, $bag, $password ?? '')) {
            $errors = implode("\n", X509::clearErrors());

            // the MAC is verified before decryption, so a wrong password is always Unreadable (R10)
            if (! str_contains($errors, 'mac verify failure') && str_contains($errors, 'unsupported') && ! $this->supportsLegacyPkcs12()) {
                throw self::problem(CertificateProblem::UnsupportedLegacyFormat);
            }

            throw self::problem(CertificateProblem::Unreadable);
        }

        X509::clearErrors();
        $certificate = is_string($bag['cert'] ?? null) ? X509::read($bag['cert']) : null;

        if ($certificate === null) {
            throw self::problem(CertificateProblem::Unreadable);
        }

        $key = is_string($bag['pkey'] ?? null) ? openssl_pkey_get_private($bag['pkey']) : false;
        X509::clearErrors();

        if ($key === false) {
            throw self::problem(CertificateProblem::MissingPrivateKey);
        }

        $chain = [];

        foreach (is_array($bag['extracerts'] ?? null) ? $bag['extracerts'] : [] as $extra) {
            $item = is_string($extra) ? X509::read($extra) : null;

            if ($item !== null) {
                $chain[] = $item;
            }
        }

        return [$certificate, $key, $chain];
    }
}
