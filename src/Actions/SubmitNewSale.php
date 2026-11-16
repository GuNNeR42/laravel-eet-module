<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Gunner42\LaravelEetModule\Data\MessageData;
use Gunner42\LaravelEetModule\Data\SaleData;
use Gunner42\LaravelEetModule\Eet;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\PreflightWarning;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Enums\SendingMode;
use Gunner42\LaravelEetModule\Events\SalePreflightWarned;
use Gunner42\LaravelEetModule\Exceptions\DuplicateSequenceNumber;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\RegistrationUnit;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Services\MessageBuilder;
use Gunner42\LaravelEetModule\Services\MessageUuid;
use Gunner42\LaravelEetModule\Services\ResubmissionScheduler;
use Gunner42\LaravelEetModule\Services\SaleLock;
use Gunner42\LaravelEetModule\Services\SaleValidator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Validates, stores (Pending, committed before the network call) and sends a
 * new sale or reversal (R19–R28, R62). The caller validated the
 * configuration and the transaction state.
 *
 * @internal
 */
final class SubmitNewSale
{
    public function __construct(
        private readonly Eet $eet,
        private readonly SaleValidator $validator,
        private readonly SaleLock $lock,
        private readonly SendSaleAttempt $sendAttempt,
        private readonly ResubmissionScheduler $scheduler,
    ) {}

    public function handle(CashRegister $cashRegister, SaleData $data, ?Sale $reversedSale = null): Sale
    {
        $unit = RegistrationUnit::query()->findOrFail($cashRegister->registration_unit_id);
        $taxpayer = Taxpayer::query()->findOrFail($unit->taxpayer_id);
        $environment = $taxpayer->environment;
        $warnings = $this->validator->validate($taxpayer, $environment, $unit, $data);

        $messageData = new MessageData(
            $taxpayer->eic,
            $data->delegatingEic,
            $data->delegatedByMultipleTaxpayers,
            $unit->number,
            $cashRegister->identifier,
            $data->sequenceNumber,
            MessageBuilder::formatDateTime($data->occurredAt),
            $data->totalAmount,
            $data->amountForLaterDrawing,
            $data->drawnAmount,
        );

        // the UUID of the first attempt is validated before anything is stored (R29)
        $messageUuid = MessageUuid::next($this->eet->messageUuidFactory());

        $existing = $this->existing($environment, $messageData);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $sale = DB::transaction(fn (): Sale => Sale::query()->create([
                'taxpayer_id' => $taxpayer->id,
                'cash_register_id' => $cashRegister->id,
                'reversed_sale_id' => $reversedSale?->id,
                'eic' => $messageData->eic,
                'delegating_eic' => $messageData->delegatingEic,
                'delegated_by_multiple_taxpayers' => $messageData->delegatedByMultipleTaxpayers,
                'registration_unit_number' => $messageData->registrationUnitNumber,
                'cash_register_identifier' => $messageData->cashRegisterIdentifier,
                'sequence_number' => $messageData->sequenceNumber,
                'occurred_at' => $messageData->occurredAt,
                'total_amount' => $messageData->totalAmount,
                'amount_for_later_drawing' => $messageData->amountForLaterDrawing,
                'drawn_amount' => $messageData->drawnAmount,
                'unique_sale_code' => $messageData->uniqueSaleCode(),
                'environment' => $environment,
                'mode' => SendingMode::from((string) config('laravel-eet-module.mode')),
                'status' => SaleStatus::Pending,
                'is_test' => $environment === Environment::Playground,
                'preflight_warnings' => array_map(static fn (PreflightWarning $warning): string => $warning->value, $warnings),
                'is_fake' => $this->eet->currentFake() !== null,
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->existing($environment, $messageData) ?? throw new DuplicateSequenceNumber;
        }

        if ($warnings !== []) {
            event(new SalePreflightWarned($sale, $warnings));
        }

        $lock = $this->lock->acquire($sale->id);

        if ($lock === null) {
            return $sale;
        }

        try {
            $attempt = $this->sendAttempt->handle($sale, $messageUuid);
        } finally {
            $lock->release();
        }

        $this->scheduler->scheduleAfter($sale, $attempt);

        return $sale->refresh();
    }

    /**
     * The same sale in the same environment is returned unchanged; different
     * data under the same identity is DuplicateSequenceNumber (R26, R27).
     */
    private function existing(Environment $environment, MessageData $data): ?Sale
    {
        $sameCode = Sale::query()
            ->where('environment', $environment->value)
            ->where('unique_sale_code', $data->uniqueSaleCode())
            ->first();

        if ($sameCode !== null) {
            $identical = $sameCode->delegating_eic === $data->delegatingEic
                && $sameCode->delegated_by_multiple_taxpayers === $data->delegatedByMultipleTaxpayers
                && $sameCode->amount_for_later_drawing?->halere() === $data->amountForLaterDrawing?->halere()
                && $sameCode->drawn_amount?->halere() === $data->drawnAmount?->halere();

            return $identical ? $sameCode : throw new DuplicateSequenceNumber;
        }

        $sameIdentity = Sale::query()
            ->where('environment', $environment->value)
            ->where('eic', $data->eic)
            ->where('registration_unit_number', $data->registrationUnitNumber)
            ->where('cash_register_identifier', $data->cashRegisterIdentifier)
            ->where('sequence_number', $data->sequenceNumber)
            ->where('occurred_at', $data->occurredAt)
            ->exists();

        if ($sameIdentity) {
            throw new DuplicateSequenceNumber;
        }

        return null;
    }
}
