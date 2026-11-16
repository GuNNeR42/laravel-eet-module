<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Data;

use LogicException;
use OpenSSLAsymmetricKey;

/**
 * A certificate read from an imported file. The key is held only as
 * OpenSSLAsymmetricKey and the object cannot be serialized (R15).
 *
 * @internal
 */
final readonly class ParsedCertificate
{
    public function __construct(
        public string $certificatePem,
        public ?string $chainPem,
        public OpenSSLAsymmetricKey $privateKey,
        public string $commonName,
        public string $serialNumber,
        public int $validFrom,
        public int $validTo,
        public string $fingerprint,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A parsed certificate cannot be serialized.');
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['commonName' => $this->commonName, 'serialNumber' => $this->serialNumber, 'fingerprint' => $this->fingerprint];
    }
}
