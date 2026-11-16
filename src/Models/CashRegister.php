<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Models;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Database\Factories\CashRegisterFactory;
use Gunner42\LaravelEetModule\Models\Concerns\ReadsDatesInApplicationTimezone;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cash register (pokladní zařízení), R6–R8.
 *
 * @property int $id
 * @property int $registration_unit_id
 * @property string $identifier
 * @property string|null $name
 * @property int|null $certificate_id
 * @property-read RegistrationUnit $registrationUnit
 * @property-read CashRegisterCertificate|null $certificate
 * @property-read Taxpayer $taxpayer
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CashRegister extends Model
{
    /** @use HasFactory<CashRegisterFactory> */
    use HasFactory;

    use ReadsDatesInApplicationTimezone;

    protected $table = 'eet_cash_registers';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registration_unit_id' => 'integer',
            'certificate_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<RegistrationUnit, $this>
     */
    public function registrationUnit(): BelongsTo
    {
        return $this->belongsTo(RegistrationUnit::class);
    }

    /**
     * @return BelongsTo<CashRegisterCertificate, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(CashRegisterCertificate::class, 'certificate_id');
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * @return Attribute<Taxpayer, never>
     */
    protected function taxpayer(): Attribute
    {
        return Attribute::get(fn (): Taxpayer => $this->registrationUnit->taxpayer);
    }

    protected static function newFactory(): CashRegisterFactory
    {
        return CashRegisterFactory::new();
    }
}
