<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use SensitiveParameter;

/**
 * Stateless helpers over ext-openssl for X.509 certificates.
 */
final class X509
{
    public const string OID_ORGANIZATION_IDENTIFIER = '2.5.4.97';

    /**
     * Splits PEM text into its certificate blocks.
     *
     * @return list<string>
     */
    public static function pemBlocks(#[SensitiveParameter] string $pem): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $matches);

        return array_map(static fn (string $block): string => $block."\n", $matches[0]);
    }

    public static function read(#[SensitiveParameter] string $pem): ?OpenSSLCertificate
    {
        // the warning of an unreadable certificate is an expected outcome here
        $certificate = @openssl_x509_read($pem);
        self::clearErrors();

        return $certificate === false ? null : $certificate;
    }

    public static function toPem(OpenSSLCertificate $certificate): string
    {
        openssl_x509_export($certificate, $pem);

        return is_string($pem) ? $pem : '';
    }

    public static function toDer(OpenSSLCertificate $certificate): string
    {
        $base64 = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', self::toPem($certificate));

        return (string) base64_decode($base64, true);
    }

    public static function derToPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
    }

    public static function fingerprint(OpenSSLCertificate $certificate): string
    {
        return strtolower((string) openssl_x509_fingerprint($certificate, 'sha256'));
    }

    /**
     * Values of one attribute of the subject (short name or OID).
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function subjectValues(OpenSSLCertificate $certificate, array $names): array
    {
        $short = openssl_x509_parse($certificate);
        $subject = is_array($short) && is_array($short['subject'] ?? null) ? $short['subject'] : [];
        $values = [];

        foreach ($names as $name) {
            $value = $subject[$name] ?? null;

            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_string($item)) {
                    $values[] = $item;
                }
            }
        }

        return $values;
    }

    public static function commonName(OpenSSLCertificate $certificate): string
    {
        return self::subjectValues($certificate, ['CN'])[0] ?? '';
    }

    public static function serialNumberHex(OpenSSLCertificate $certificate): string
    {
        $parsed = openssl_x509_parse($certificate);

        return is_array($parsed) && is_string($parsed['serialNumberHex'] ?? null) ? strtoupper($parsed['serialNumberHex']) : '';
    }

    public static function validFrom(OpenSSLCertificate $certificate): int
    {
        $parsed = openssl_x509_parse($certificate);

        return is_array($parsed) && is_int($parsed['validFrom_time_t'] ?? null) ? $parsed['validFrom_time_t'] : PHP_INT_MAX;
    }

    public static function validTo(OpenSSLCertificate $certificate): int
    {
        $parsed = openssl_x509_parse($certificate);

        return is_array($parsed) && is_int($parsed['validTo_time_t'] ?? null) ? $parsed['validTo_time_t'] : PHP_INT_MIN;
    }

    public static function isValidAt(OpenSSLCertificate $certificate, int $timestamp): bool
    {
        return self::validFrom($certificate) <= $timestamp && $timestamp <= self::validTo($certificate);
    }

    private static function extension(OpenSSLCertificate $certificate, string $name): ?string
    {
        $parsed = openssl_x509_parse($certificate);
        $value = is_array($parsed) && is_array($parsed['extensions'] ?? null) ? ($parsed['extensions'][$name] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    public static function isCa(OpenSSLCertificate $certificate): bool
    {
        return preg_match('/\bCA:TRUE\b/', self::extension($certificate, 'basicConstraints') ?? '') === 1;
    }

    public static function isNotCa(OpenSSLCertificate $certificate): bool
    {
        return preg_match('/\bCA:FALSE\b/', self::extension($certificate, 'basicConstraints') ?? '') === 1;
    }

    public static function hasKeyUsage(OpenSSLCertificate $certificate, string $usage): bool
    {
        $usages = array_map('trim', explode(',', self::extension($certificate, 'keyUsage') ?? ''));

        return in_array($usage, $usages, true);
    }

    public static function subjectKeyIdentifier(OpenSSLCertificate $certificate): ?string
    {
        return self::keyIdentifier(self::extension($certificate, 'subjectKeyIdentifier'));
    }

    public static function authorityKeyIdentifier(OpenSSLCertificate $certificate): ?string
    {
        // OpenSSL 1.1 prints "keyid:AB:CD…", OpenSSL 3 only "AB:CD…"
        return self::keyIdentifier(self::extension($certificate, 'authorityKeyIdentifier'));
    }

    private static function keyIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            $line = trim((string) preg_replace('/^keyid:/i', '', trim($line)));

            if (preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2})*$/iD', $line) === 1) {
                return strtoupper($line);
            }
        }

        return null;
    }

    /**
     * Subject and issuer distinguished names in a comparable form.
     */
    public static function subjectName(OpenSSLCertificate $certificate): string
    {
        $parsed = openssl_x509_parse($certificate);

        return is_array($parsed) ? self::nameKey($parsed['subject'] ?? []) : '';
    }

    public static function issuerName(OpenSSLCertificate $certificate): string
    {
        $parsed = openssl_x509_parse($certificate);

        return is_array($parsed) ? self::nameKey($parsed['issuer'] ?? []) : '';
    }

    private static function nameKey(mixed $name): string
    {
        return is_array($name) ? (string) json_encode($name) : '';
    }

    /**
     * The certificate is signed by the given issuer certificate.
     */
    public static function isSignedBy(OpenSSLCertificate $certificate, OpenSSLCertificate $issuer): bool
    {
        $key = openssl_pkey_get_public($issuer);

        if (! $key instanceof OpenSSLAsymmetricKey) {
            self::clearErrors();

            return false;
        }

        $result = openssl_x509_verify($certificate, $key);
        self::clearErrors();

        return $result === 1;
    }

    public static function isSelfSigned(OpenSSLCertificate $certificate): bool
    {
        return self::subjectName($certificate) === self::issuerName($certificate) && self::isSignedBy($certificate, $certificate);
    }

    /**
     * Issuer relationship: names and AKI/SKI match and the signature verifies.
     */
    public static function isIssuedBy(OpenSSLCertificate $certificate, OpenSSLCertificate $issuer): bool
    {
        $authorityKeyIdentifier = self::authorityKeyIdentifier($certificate);

        return self::issuerName($certificate) === self::subjectName($issuer)
            && $authorityKeyIdentifier !== null
            && $authorityKeyIdentifier === self::subjectKeyIdentifier($issuer)
            && self::isSignedBy($certificate, $issuer);
    }

    /**
     * Subject in the RFC 2253 form (reverse order, escaped values).
     */
    public static function rfc2253Subject(OpenSSLCertificate $certificate): string
    {
        $parsed = openssl_x509_parse($certificate);
        $subject = is_array($parsed) && is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        $parts = [];

        foreach ($subject as $name => $values) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                $parts[] = $name.'='.self::escapeRfc2253((string) $value);
            }
        }

        return implode(',', array_reverse($parts));
    }

    private static function escapeRfc2253(string $value): string
    {
        $escaped = (string) preg_replace('/([,+"\\\\<>;=])/', '\\\\$1', $value);

        if (str_starts_with($escaped, '#') || str_starts_with($escaped, ' ')) {
            $escaped = '\\'.$escaped;
        }

        if (str_ends_with($escaped, ' ')) {
            $escaped = substr($escaped, 0, -1).'\\ ';
        }

        return $escaped;
    }

    /**
     * Empties the OpenSSL error queue and returns its messages.
     *
     * @return list<string>
     */
    public static function clearErrors(): array
    {
        $errors = [];

        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors;
    }
}
