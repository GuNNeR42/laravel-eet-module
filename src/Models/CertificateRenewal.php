<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Models;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Enums\RenewalProblem;
use Gunner42\LaravelEetModule\Enums\RenewalStatus;
use Gunner42\LaravelEetModule\Models\Concerns\ReadsDatesInApplicationTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renewal of a cash register certificate through the CA EET API (R92–R102).
 * It never holds the downloaded PKCS#12 or its password (R103).
 *
 * @property int $id
 * @property int $certificate_id
 * @property int|null $successor_id
 * @property string|null $request_id
 * @property RenewalStatus $status
 * @property RenewalProblem|null $problem
 * @property string|null $problem_detail
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property-read CashRegisterCertificate $certificate
 * @property-read CashRegisterCertificate|null $successor
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CertificateRenewal extends Model
{
    use ReadsDatesInApplicationTimezone;

    protected $table = 'eet_certificate_renewals';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'certificate_id' => 'integer',
            'successor_id' => 'integer',
            'status' => RenewalStatus::class,
            'problem' => RenewalProblem::class,
            'acknowledged_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The renewed certificate.
     *
     * @return BelongsTo<CashRegisterCertificate, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(CashRegisterCertificate::class, 'certificate_id');
    }

    /**
     * The certificate stored by this renewal (R96).
     *
     * @return BelongsTo<CashRegisterCertificate, $this>
     */
    public function successor(): BelongsTo
    {
        return $this->belongsTo(CashRegisterCertificate::class, 'successor_id');
    }
}
