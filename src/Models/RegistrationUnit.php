<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Models;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Database\Factories\RegistrationUnitFactory;
use Gunner42\LaravelEetModule\Models\Concerns\ReadsDatesInApplicationTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Registration unit (evidenční jednotka), R3–R5.
 *
 * @property int $id
 * @property int $taxpayer_id
 * @property int $number
 * @property string|null $name
 * @property-read Taxpayer $taxpayer
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class RegistrationUnit extends Model
{
    /** @use HasFactory<RegistrationUnitFactory> */
    use HasFactory;

    use ReadsDatesInApplicationTimezone;

    protected $table = 'eet_registration_units';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'taxpayer_id' => 'integer',
            'number' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The number has the form assigned in DIS+: at least two digits, the last
     * one 1–4 (R5).
     */
    public function hasDisFormat(): bool
    {
        return preg_match('/^[1-9][0-9]*[1-4]$/D', (string) $this->number) === 1;
    }

    /**
     * @return BelongsTo<Taxpayer, $this>
     */
    public function taxpayer(): BelongsTo
    {
        return $this->belongsTo(Taxpayer::class);
    }

    /**
     * @return HasMany<CashRegister, $this>
     */
    public function cashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class);
    }

    protected static function newFactory(): RegistrationUnitFactory
    {
        return RegistrationUnitFactory::new();
    }
}
