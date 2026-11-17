<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Gunner42\LaravelEetModule\Data\ParsedCertificate;
use Gunner42\LaravelEetModule\Enums\RenewalStatus;
use Gunner42\LaravelEetModule\Models\Concerns\ReadsDatesInApplicationTimezone;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Gunner42\LaravelEetModule\Services\X509;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OpenSSLAsymmetricKey;
use SensitiveParameter;

/**
 * Cash register certificate (pokladní certifikát), R9–R16, R69, R90–R96.
 * The private key is stored encrypted and never serialized (R12, R15).
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

    /**
     * Renewals of this certificate (R92).
     *
     * @return HasMany<CertificateRenewal, $this>
     */
    public function renewals(): HasMany
    {
        return $this->hasMany(CertificateRenewal::class, 'certificate_id');
    }

    /**
     * The renewal with the highest id (R102).
     */
    public function latestRenewal(): ?CertificateRenewal
    {
        return $this->renewals()->orderByDesc('id')->first();
    }

    /**
     * The certificate stored by a renewal of this one in Acknowledging or
     * Completed; a manually imported certificate is never a successor (R90).
     */
    public function successor(): ?self
    {
        $renewal = $this->renewals()
            ->whereIn('status', [RenewalStatus::Acknowledging->value, RenewalStatus::Completed->value])
            ->whereNotNull('successor_id')
            ->orderByDesc('id')
            ->first();

        return $renewal?->successor()->first();
    }

    /**
     * The certificate whose renewal stored this one (R96).
     */
    public function predecessor(): ?self
    {
        $renewal = CertificateRenewal::query()
            ->where('successor_id', $this->getKey())
            ->whereIn('status', [RenewalStatus::Acknowledging->value, RenewalStatus::Completed->value])
            ->orderByDesc('id')
            ->first();

        return $renewal?->certificate()->first();
    }

    /**
     * The certificate policies contain an OID under 1.2.203.19122063.10.1.
     * (production cash register certificate, R90, Q52).
     */
    public function hasProductionPolicy(): bool
    {
        $certificate = X509::read($this->certificate);

        return $certificate !== null && X509::hasProductionPolicy($certificate);
    }

    /**
     * Valid now and at most renewal.days_before_expiry days before validTo
     * (R105, R106, Q54).
     */
    public function isInRenewalWindow(): bool
    {
        $now = CarbonImmutable::now();
        $days = ConfigurationValidator::integer('renewal.days_before_expiry');

        return $this->isValidAt($now)
            && $this->valid_to->getTimestamp() - $now->getTimestamp() <= $days * 86400;
    }

    /**
     * The serial number in decimal, as the CA EET API shows it.
     */
    public function decimalSerialNumber(): string
    {
        return X509::hexToDecimal($this->serial_number);
    }

    /**
     * An unsaved certificate of the taxpayer built from an imported file; the
     * key is encrypted right away (R12, R15).
     *
     * @internal
     */
    public static function fromParsed(Taxpayer $taxpayer, #[SensitiveParameter] ParsedCertificate $parsed): self
    {
        $certificate = new self;
        $certificate->forceFill([
            'taxpayer_id' => $taxpayer->id,
            'serial_number' => $parsed->serialNumber,
            'subject_eic' => $parsed->commonName,
            'valid_from' => CarbonImmutable::createFromTimestamp($parsed->validFrom)->setTimezone(date_default_timezone_get()),
            'valid_to' => CarbonImmutable::createFromTimestamp($parsed->validTo)->setTimezone(date_default_timezone_get()),
            'fingerprint_sha256' => $parsed->fingerprint,
            'certificate' => $parsed->certificatePem,
            'chain' => $parsed->chainPem,
        ]);
        $certificate->setEncryptedPrivateKey($parsed->privateKey);

        return $certificate;
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
