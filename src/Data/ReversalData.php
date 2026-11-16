<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Data;

use DateTimeInterface;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\ValueObjects\Amount;

/**
 * Data of a reversal passed to Eet::reverse() (R48, R49).
 */
final readonly class ReversalData
{
    public function __construct(
        public CashRegister $cashRegister,
        public string $sequenceNumber,
        public DateTimeInterface $occurredAt,
        public Amount $amount,
        public ?Amount $drawnAmount = null,
    ) {}
}
