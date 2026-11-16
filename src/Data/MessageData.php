<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Data;

use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\ValueObjects\Amount;

/**
 * Values of the Data element of a data message, in the EET form (R22–R25).
 *
 * @internal
 */
final readonly class MessageData
{
    public function __construct(
        public string $eic,
        public ?string $delegatingEic,
        public bool $delegatedByMultipleTaxpayers,
        public int $registrationUnitNumber,
        public string $cashRegisterIdentifier,
        public string $sequenceNumber,
        public string $occurredAt,
        public Amount $totalAmount,
        public ?Amount $amountForLaterDrawing,
        public ?Amount $drawnAmount,
    ) {}

    public static function fromSale(Sale $sale): self
    {
        return new self(
            $sale->eic,
            $sale->delegating_eic,
            $sale->delegated_by_multiple_taxpayers,
            $sale->registration_unit_number,
            $sale->cash_register_identifier,
            $sale->sequence_number,
            $sale->occurred_at,
            $sale->total_amount,
            $sale->amount_for_later_drawing,
            $sale->drawn_amount,
        );
    }

    /**
     * Jednoznačný kód tržby (R25).
     */
    public function uniqueSaleCode(): string
    {
        return implode('|', [
            $this->eic,
            (string) $this->registrationUnitNumber,
            $this->cashRegisterIdentifier,
            $this->sequenceNumber,
            $this->occurredAt,
            $this->totalAmount->toEetString(),
        ]);
    }
}
