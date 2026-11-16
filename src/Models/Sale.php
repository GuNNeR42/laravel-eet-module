<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Models;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Data\Warning;
use Gunner42\LaravelEetModule\Database\Factories\SaleFactory;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\PreflightWarning;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Enums\SendingMode;
use Gunner42\LaravelEetModule\Models\Concerns\ReadsDatesInApplicationTimezone;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Gunner42\LaravelEetModule\ValueObjects\Amount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Registered sale (evidovaná tržba) with the snapshot of its Data (R23).
 *
 * @property int $id
 * @property int $taxpayer_id
 * @property int $cash_register_id
 * @property int|null $reversed_sale_id
 * @property string $eic
 * @property string|null $delegating_eic
 * @property bool $delegated_by_multiple_taxpayers
 * @property int $registration_unit_number
 * @property string $cash_register_identifier
 * @property string $sequence_number
 * @property string $occurred_at
 * @property Amount $total_amount
 * @property Amount|null $amount_for_later_drawing
 * @property Amount|null $drawn_amount
 * @property string $unique_sale_code
 * @property Environment $environment
 * @property SendingMode $mode
 * @property SaleStatus $status
 * @property string|null $confirmation_code
 * @property bool $is_test
 * @property list<string> $preflight_warnings
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $overdue_notified_at
 * @property int|null $rejecting_attempt_id
 * @property CarbonImmutable|null $reopened_at
 * @property int|null $reopened_after_attempt_id
 * @property bool $is_fake
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Taxpayer $taxpayer
 * @property-read CashRegister $cashRegister
 * @property-read Sale|null $reversedSale
 * @property-read Collection<int, Sale> $reversals
 */
class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    use ReadsDatesInApplicationTimezone;

    protected $table = 'eet_sales';

    protected $guarded = ['id'];

    /**
     * Outcomes of attempts that received an accepted response (R37).
     */
    public const array RESPONSE_OUTCOMES = [
        AttemptOutcome::Confirmed,
        AttemptOutcome::Verified,
        AttemptOutcome::Rejected,
        AttemptOutcome::TemporaryError,
        AttemptOutcome::AmbiguousError,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'taxpayer_id' => 'integer',
            'cash_register_id' => 'integer',
            'reversed_sale_id' => 'integer',
            'delegated_by_multiple_taxpayers' => 'boolean',
            'registration_unit_number' => 'integer',
            'total_amount' => Amount::class,
            'amount_for_later_drawing' => Amount::class,
            'drawn_amount' => Amount::class,
            'environment' => Environment::class,
            'mode' => SendingMode::class,
            'status' => SaleStatus::class,
            'is_test' => 'boolean',
            'preflight_warnings' => 'array',
            'confirmed_at' => 'immutable_datetime',
            'overdue_notified_at' => 'immutable_datetime',
            'rejecting_attempt_id' => 'integer',
            'reopened_at' => 'immutable_datetime',
            'reopened_after_attempt_id' => 'integer',
            'is_fake' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function occurredAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d\TH:i:sP', $this->occurred_at) ?: CarbonImmutable::parse($this->occurred_at);
    }

    /**
     * Valid POK: confirmed and not from the non-production environment (R36).
     */
    public function hasValidConfirmationCode(): bool
    {
        return $this->status === SaleStatus::Confirmed && ! $this->is_test;
    }

    /**
     * @return list<PreflightWarning>
     */
    public function preflightWarnings(): array
    {
        $warnings = [];

        foreach ($this->preflight_warnings ?? [] as $value) {
            $warning = PreflightWarning::tryFrom($value);

            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }

    /**
     * Warnings of the last attempt that received a response (R37).
     *
     * @return Collection<int, Warning>
     */
    public function warnings(): Collection
    {
        $attempt = $this->attempts()
            ->whereIn('outcome', array_map(static fn (AttemptOutcome $outcome): string => $outcome->value, self::RESPONSE_OUTCOMES))
            ->orderByDesc('id')
            ->first();

        return collect($attempt?->warnings() ?? [])->values();
    }

    /**
     * A Pending sale older than 2 × response timeout + 60 s (R63).
     */
    public function isStuck(): bool
    {
        return $this->status === SaleStatus::Pending
            && $this->created_at !== null
            && $this->created_at->lessThan(self::stuckBoundary());
    }

    public static function stuckBoundary(): CarbonImmutable
    {
        // stored timestamps are in the application timezone
        return CarbonImmutable::now()
            ->subMilliseconds(2 * ConfigurationValidator::integer('response_timeout_ms') + 60_000)
            ->setTimezone(date_default_timezone_get());
    }

    /**
     * @param  Builder<Sale>  $query
     * @return Builder<Sale>
     */
    public function scopeAwaitingResubmission(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('status', SaleStatus::Unconfirmed->value)
                ->orWhere(function (Builder $query): void {
                    $query->where('status', SaleStatus::Pending->value)
                        ->where('created_at', '<', self::stuckBoundary());
                });
        });
    }

    public function isAwaitingResubmission(): bool
    {
        return $this->status === SaleStatus::Unconfirmed || $this->isStuck();
    }

    /**
     * Live, production, unconfirmed or stuck and more than 48 h after
     * occurredAt (R44).
     */
    public function isOverdue(): bool
    {
        return $this->mode === SendingMode::Live
            && $this->environment === Environment::Production
            && $this->isAwaitingResubmission()
            && $this->occurredAt()->addHours(48)->lessThan(CarbonImmutable::now());
    }

    /**
     * @return BelongsTo<Taxpayer, $this>
     */
    public function taxpayer(): BelongsTo
    {
        return $this->belongsTo(Taxpayer::class);
    }

    /**
     * @return BelongsTo<CashRegister, $this>
     */
    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    /**
     * @return HasMany<SubmissionAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(SubmissionAttempt::class);
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function reversedSale(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_sale_id');
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversed_sale_id');
    }

    protected static function newFactory(): SaleFactory
    {
        return SaleFactory::new();
    }
}
