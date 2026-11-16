<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Models;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Database\Factories\TaxpayerFactory;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Models\Concerns\ReadsDatesInApplicationTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Taxpayer (poplatník), R1, R2, R59.
 *
 * @property int $id
 * @property string $eic
 * @property string|null $name
 * @property Environment $environment
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Taxpayer extends Model
{
    /** @use HasFactory<TaxpayerFactory> */
    use HasFactory;

    use ReadsDatesInApplicationTimezone;

    protected $table = 'eet_taxpayers';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<RegistrationUnit, $this>
     */
    public function registrationUnits(): HasMany
    {
        return $this->hasMany(RegistrationUnit::class);
    }

    /**
     * @return HasMany<CashRegisterCertificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(CashRegisterCertificate::class);
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    protected static function newFactory(): TaxpayerFactory
    {
        return TaxpayerFactory::new();
    }
}
