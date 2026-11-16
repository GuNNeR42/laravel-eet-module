<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Tests\Support;

use RuntimeException;

/**
 * Paths to the committed test fixtures (see tests/fixtures/README.md).
 */
final class Fixtures
{
    /** Password of the CA EET playground PKCS#12 files. */
    public const PLAYGROUND_PASSWORD = 'aaaa1111';

    /** Password of the encrypted PEM keys in tests/fixtures/playground. */
    public const PEM_PASSWORD = 'Heslo123';

    /** Password of the modern (non-legacy) PKCS#12 files. */
    public const P12_PASSWORD = 'Abcd1234';

    /** UUID of the request answered by the real playground response sample. */
    public const SAMPLE_UUID = '60b96f2c-e521-47e1-92aa-cb60f3034f6e';

    /** dat_prij of the real playground response sample. */
    public const SAMPLE_RECEIVED_AT = '2026-11-15T17:23:53+01:00';

    /** POK of the real playground response sample. */
    public const SAMPLE_CONFIRMATION_CODE = '5cf9d5a2-c64b-425f-88c7-986a62bc569b-ff';

    public static function path(string $relative): string
    {
        $path = __DIR__.'/../fixtures/'.$relative;

        if (! is_file($path)) {
            throw new RuntimeException("Missing fixture [{$relative}].");
        }

        return (string) realpath($path);
    }

    public static function read(string $relative): string
    {
        return (string) file_get_contents(self::path($relative));
    }

    /**
     * The certificate (PEM, first block) of a fixture file.
     */
    public static function certificate(string $relative): string
    {
        $contents = self::read($relative);

        if (preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $contents, $match) !== 1) {
            throw new RuntimeException("Fixture [{$relative}] contains no certificate.");
        }

        return $match[0]."\n";
    }

    /**
     * SHA-256 fingerprint (lower-case hex of the DER) of a fixture certificate.
     */
    public static function fingerprint(string $relative): string
    {
        return strtolower((string) openssl_x509_fingerprint(self::certificate($relative), 'sha256'));
    }

    /**
     * Base64 DER of a fixture certificate, as it appears in a BinarySecurityToken.
     */
    public static function der(string $relative): string
    {
        $pem = self::certificate($relative);
        $base64 = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);

        return (string) $base64;
    }
}
