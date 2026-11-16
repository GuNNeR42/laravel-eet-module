<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Gunner42\LaravelEetModule\Models\Concerns\ReadsDatesInApplicationTimezone;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenSSLAsymmetricKey;
use SensitiveParameter;

/**
 * Cash register certificate (pokladní certifikát), R9–R16, R69. The private
 * key is stored encrypted and never serialized (R12, R15).
 *
 * @property int $id
 * @property int $taxpayer_id
 * @property string $serial_number
 * @property string $subject_eic
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable $valid_to
 * @property string $fingerprint_sha256
 * @property string $certificate
 * @property string|null $chain
 * @property string $private_key
 * @property-read Taxpayer $taxpayer
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CashRegisterCertificate extends Model
{
    use ReadsDatesInApplicationTimezone;

    protected $table = 'eet_cash_register_certificates';

    protected $guarded = ['id'];

    protected $hidden = ['private_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'taxpayer_id' => 'integer',
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'private_key' => 'encrypted',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Taxpayer, $this>
     */
    public function taxpayer(): BelongsTo
    {
        return $this->belongsTo(Taxpayer::class);
    }

    public function isValidAt(DateTimeInterface $moment): bool
    {
        $timestamp = $moment->getTimestamp();

        return $this->valid_from->getTimestamp() <= $timestamp && $timestamp <= $this->valid_to->getTimestamp();
    }

    /**
     * The stored key can be decrypted and read (R69).
     */
    public function isUsable(): bool
    {
        return $this->privateKey() !== null;
    }

    /**
     * Stores the key encrypted by the model encrypter; the plain PEM never
     * leaves this method (R12, R15).
     *
     * @internal
     */
    public function setEncryptedPrivateKey(#[SensitiveParameter] OpenSSLAsymmetricKey $key): void
    {
        openssl_pkey_export($key, $pem);

        while (openssl_error_string() !== false) {
            // empty the OpenSSL error queue
        }

        $this->attributes['private_key'] = static::currentEncrypter()->encrypt(is_string($pem) ? $pem : '', false);
    }

    /**
     * The decrypted key, held only as OpenSSLAsymmetricKey (R15).
     *
     * @internal
     */
    public function privateKey(): ?OpenSSLAsymmetricKey
    {
        try {
            $key = openssl_pkey_get_private($this->private_key);
        } catch (DecryptException) {
            return null;
        }

        while (openssl_error_string() !== false) {
            // empty the OpenSSL error queue
        }

        return $key === false ? null : $key;
    }
}
