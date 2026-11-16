<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Database\Factories;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Data\MessageData;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Enums\SendingMode;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\RegistrationUnit;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\SubmissionAttempt;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Services\MessageBuilder;
use Gunner42\LaravelEetModule\Services\MessageUuid;
use Gunner42\LaravelEetModule\ValueObjects\Amount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sales for tests of the host. Without a state: Pending without an attempt,
 * production, live. Every state also creates the matching attempt.
 *
 * @extends Factory<Sale>
 */
final class SaleFactory extends Factory
{
    protected $model = Sale::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cash_register_id' => CashRegister::factory(),
            'taxpayer_id' => static fn (array $attributes): int => self::cashRegister($attributes)->registrationUnit->taxpayer_id,
            'eic' => static fn (array $attributes): string => self::cashRegister($attributes)->registrationUnit->taxpayer->eic,
            'delegating_eic' => null,
            'delegated_by_multiple_taxpayers' => false,
            'registration_unit_number' => static fn (array $attributes): int => self::cashRegister($attributes)->registrationUnit->number,
            'cash_register_identifier' => static fn (array $attributes): string => self::cashRegister($attributes)->identifier,
            'sequence_number' => 'F-'.random_int(1, 999_999_999),
            'occurred_at' => MessageBuilder::formatDateTime(CarbonImmutable::now()),
            'total_amount' => Amount::fromHalere(random_int(100, 1_000_000)),
            'amount_for_later_drawing' => null,
            'drawn_amount' => null,
            'unique_sale_code' => static fn (array $attributes): string => self::messageData($attributes)->uniqueSaleCode(),
            'environment' => Environment::Production,
            'mode' => SendingMode::Live,
            'status' => SaleStatus::Pending,
            'confirmation_code' => null,
            'is_test' => false,
            'preflight_warnings' => [],
            'is_fake' => false,
        ];
    }

    public function confirmed(): self
    {
        $code = MessageUuid::random().'-01';

        return $this->state([
            'status' => SaleStatus::Confirmed,
            'environment' => Environment::Production,
            'mode' => SendingMode::Live,
            'confirmation_code' => $code,
            'is_test' => false,
            'confirmed_at' => CarbonImmutable::now(),
        ])->afterCreating(static function (Sale $sale) use ($code): void {
            self::attempt($sale, AttemptOutcome::Confirmed, ['confirmation_code' => $code, 'received_at' => CarbonImmutable::now()]);
        });
    }

    public function unconfirmed(): self
    {
        return $this->state(['status' => SaleStatus::Unconfirmed])
            ->afterCreating(static function (Sale $sale): void {
                self::attempt($sale, AttemptOutcome::Timeout);
            });
    }

    public function rejected(int $errorCode = 3): self
    {
        return $this->state(['status' => SaleStatus::Rejected])
            ->afterCreating(static function (Sale $sale) use ($errorCode): void {
                self::rejectBy($sale, self::attempt($sale, AttemptOutcome::Rejected, ['error_code' => $errorCode, 'rejected_at' => CarbonImmutable::now()]));
            });
    }

    public function ambiguousRejected(int $errorCode = 8): self
    {
        return $this->state(['status' => SaleStatus::Rejected])
            ->afterCreating(static function (Sale $sale) use ($errorCode): void {
                self::rejectBy($sale, self::attempt($sale, AttemptOutcome::AmbiguousError, ['error_code' => $errorCode, 'rejected_at' => CarbonImmutable::now()]));
            });
    }

    public function playground(): self
    {
        $code = MessageUuid::random().'-ff';
        $cashRegister = CashRegister::factory()->for(
            RegistrationUnit::factory()->for(Taxpayer::factory()->playground(), 'taxpayer'),
            'registrationUnit',
        );

        return $this->state([
            'cash_register_id' => $cashRegister,
            'status' => SaleStatus::Confirmed,
            'environment' => Environment::Playground,
            'mode' => SendingMode::Live,
            'is_test' => true,
            'confirmation_code' => $code,
            'confirmed_at' => CarbonImmutable::now(),
        ])->afterCreating(static function (Sale $sale) use ($code): void {
            self::attempt($sale, AttemptOutcome::Confirmed, ['confirmation_code' => $code, 'received_at' => CarbonImmutable::now(), 'is_test' => true]);
        });
    }

    public function overdue(): self
    {
        return $this->unconfirmed()->state([
            'occurred_at' => MessageBuilder::formatDateTime(CarbonImmutable::now()->subHours(49)),
            'environment' => Environment::Production,
            'mode' => SendingMode::Live,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function attempt(Sale $sale, AttemptOutcome $outcome, array $attributes = []): SubmissionAttempt
    {
        return $sale->attempts()->create(array_merge([
            'message_uuid' => MessageUuid::random(),
            'is_first_submission' => true,
            'sent_at' => CarbonImmutable::now(),
            'outcome' => $outcome,
            'warnings' => [],
            'is_test' => $sale->is_test,
            'duration_ms' => 0,
            'is_fake' => false,
        ], $attributes));
    }

    private static function rejectBy(Sale $sale, SubmissionAttempt $attempt): void
    {
        $sale->forceFill(['rejecting_attempt_id' => $attempt->id])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function cashRegister(array $attributes): CashRegister
    {
        $id = $attributes['cash_register_id'] ?? null;

        return CashRegister::query()->whereKey($id instanceof CashRegister ? $id->id : $id)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function messageData(array $attributes): MessageData
    {
        /** @var Amount $total */
        $total = $attributes['total_amount'];

        return new MessageData(
            (string) $attributes['eic'],
            null,
            false,
            (int) $attributes['registration_unit_number'],
            (string) $attributes['cash_register_identifier'],
            (string) $attributes['sequence_number'],
            (string) $attributes['occurred_at'],
            $total,
            null,
            null,
        );
    }
}
