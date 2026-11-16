<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use OpenSSLCertificate;

/**
 * Trusted CA certificates for the response signature (R35): the bundled
 * I.CA certificates or the configured response_trust_store, never the system
 * store. Anchors are self-signed CA certificates, the other CA certificates
 * are intermediates.
 */
final class TrustStore
{
    /**
     * @return array{anchors: list<OpenSSLCertificate>, intermediates: list<OpenSSLCertificate>}
     */
    public function certificates(): array
    {
        $path = config('laravel-eet-module.response_trust_store');
        $pem = is_string($path) ? (string) file_get_contents($path) : $this->bundledPem();

        return $this->classify($this->readAll($pem));
    }

    /**
     * Validates a configured trust store file (R35, R68).
     */
    public function validateConfigured(string $path, int $maxCertificates): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidConfiguration('response_trust_store');
        }

        $pem = (string) file_get_contents($path);
        $blocks = X509::pemBlocks($pem);
        $certificates = $this->readAll($pem);

        if ($certificates === [] || count($certificates) !== count($blocks) || count($certificates) > $maxCertificates) {
            throw new InvalidConfiguration('response_trust_store');
        }

        foreach ($certificates as $certificate) {
            if (! X509::isCa($certificate)) {
                throw new InvalidConfiguration('response_trust_store');
            }
        }

        ['anchors' => $anchors, 'intermediates' => $intermediates] = $this->classify($certificates);

        if ($anchors === []) {
            throw new InvalidConfiguration('response_trust_store');
        }

        foreach ($intermediates as $intermediate) {
            foreach ($anchors as $anchor) {
                if (X509::isIssuedBy($intermediate, $anchor)) {
                    return;
                }
            }
        }

        throw new InvalidConfiguration('response_trust_store');
    }

    private function bundledPem(): string
    {
        $pem = '';

        foreach (glob(dirname(__DIR__, 2).'/resources/certs/*.pem') ?: [] as $file) {
            $pem .= file_get_contents($file)."\n";
        }

        return $pem;
    }

    /**
     * @return list<OpenSSLCertificate>
     */
    private function readAll(string $pem): array
    {
        $certificates = [];

        foreach (X509::pemBlocks($pem) as $block) {
            $certificate = X509::read($block);

            if ($certificate !== null) {
                $certificates[] = $certificate;
            }
        }

        return $certificates;
    }

    /**
     * @param  list<OpenSSLCertificate>  $certificates
     * @return array{anchors: list<OpenSSLCertificate>, intermediates: list<OpenSSLCertificate>}
     */
    private function classify(array $certificates): array
    {
        $anchors = [];
        $intermediates = [];

        foreach ($certificates as $certificate) {
            if (! X509::isCa($certificate)) {
                continue;
            }

            if (X509::isSelfSigned($certificate)) {
                $anchors[] = $certificate;
            } else {
                $intermediates[] = $certificate;
            }
        }

        return ['anchors' => $anchors, 'intermediates' => $intermediates];
    }
}
