<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Models;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Data\Warning;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Models\Concerns\ReadsDatesInApplicationTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to send a data message and its outcome (R29, R34, R51).
 *
 * @property int $id
 * @property int $sale_id
 * @property string $message_uuid
 * @property bool $is_first_submission
 * @property int|null $certificate_id
 * @property string|null $certificate_fingerprint
 * @property CarbonImmutable $sent_at
 * @property AttemptOutcome $outcome
 * @property int|null $error_code
 * @property string|null $error_message
 * @property list<array{code: int, message: string}> $warnings
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $confirmation_code
 * @property bool $is_test
 * @property string|null $global_transaction_id
 * @property int|null $http_status
 * @property int $duration_ms
 * @property string|null $request_xml
 * @property string|null $response_xml
 * @property string|null $signer_subject
 * @property string|null $signer_fingerprint
 * @property bool $is_fake
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Sale $sale
 * @property-read CashRegisterCertificate|null $certificate
 */
class SubmissionAttempt extends Model
{
    use ReadsDatesInApplicationTimezone;

    protected $table = 'eet_submission_attempts';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_id' => 'integer',
            'is_first_submission' => 'boolean',
            'certificate_id' => 'integer',
            'sent_at' => 'immutable_datetime',
            'outcome' => AttemptOutcome::class,
            'error_code' => 'integer',
            'warnings' => 'array',
            'received_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'is_test' => 'boolean',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'is_fake' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<Warning>
     */
    public function warnings(): array
    {
        $warnings = [];

        foreach ($this->warnings ?? [] as $warning) {
            $warnings[] = Warning::fromCode((int) $warning['code'], (string) $warning['message']);
        }

        return $warnings;
    }

    /**
     * Only CertificateUnavailable sent nothing (R29).
     */
    public function wasSent(): bool
    {
        return $this->outcome !== AttemptOutcome::CertificateUnavailable;
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<CashRegisterCertificate, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(CashRegisterCertificate::class, 'certificate_id');
    }
}
