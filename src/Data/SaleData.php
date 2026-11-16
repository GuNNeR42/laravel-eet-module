<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Data;

use DateTimeInterface;
use Gunner42\LaravelEetModule\ValueObjects\Amount;

/**
 * Data of a sale passed to Eet::register() (R19–R22).
 */
final readonly class SaleData
{
    public function __construct(
        public string $sequenceNumber,
        public DateTimeInterface $occurredAt,
        public Amount $totalAmount,
        public ?Amount $amountForLaterDrawing = null,
        public ?Amount $drawnAmount = null,
        public ?string $delegatingEic = null,
        public bool $delegatedByMultipleTaxpayers = false,
    ) {}
}
