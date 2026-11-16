<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Data;

/**
 * Values of the Hlavicka element of a data message (R29, R30).
 *
 * @internal
 */
final readonly class MessageHeader
{
    public function __construct(
        public string $messageUuid,
        public string $sentAt,
        public bool $isFirstSubmission,
        public bool $verificationMode,
    ) {}
}
