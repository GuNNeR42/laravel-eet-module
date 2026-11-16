<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Data;

use Gunner42\LaravelEetModule\Enums\AttemptOutcome;

/**
 * Result of Eet::verifyConnection() (R47).
 */
final readonly class VerificationResult
{
    /**
     * @param  list<Warning>  $warnings
     */
    public function __construct(
        public AttemptOutcome $outcome,
        public ?int $errorCode,
        public ?string $errorMessage,
        public array $warnings,
        public ?string $globalTransactionId,
    ) {}
}
