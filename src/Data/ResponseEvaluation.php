<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Data;

use Gunner42\LaravelEetModule\Enums\AttemptOutcome;

/**
 * Result of the evaluation of one response (R33–R37, R65, R71).
 *
 * @internal
 */
final readonly class ResponseEvaluation
{
    /**
     * @param  list<Warning>  $warnings
     */
    public function __construct(
        public AttemptOutcome $outcome,
        public ?int $httpStatus = null,
        public ?string $responseXml = null,
        public ?string $globalTransactionId = null,
        public ?int $errorCode = null,
        public ?string $errorMessage = null,
        public ?string $receivedAt = null,
        public ?string $rejectedAt = null,
        public ?string $confirmationCode = null,
        public bool $testFlag = false,
        public array $warnings = [],
        public ?string $signerSubject = null,
        public ?string $signerFingerprint = null,
    ) {}

    public function isRetryable(): bool
    {
        return ! in_array($this->outcome, [AttemptOutcome::Confirmed, AttemptOutcome::Verified, AttemptOutcome::Rejected], true);
    }
}
