<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Exception;
use Gunner42\LaravelEetModule\Data\CaEetResult;
use Gunner42\LaravelEetModule\Data\ParsedCertificate;
use Gunner42\LaravelEetModule\Enums\RenewalProblem;
use Gunner42\LaravelEetModule\Enums\RenewalStatus;
use Gunner42\LaravelEetModule\Events\CertificateRenewalFailed;
use Gunner42\LaravelEetModule\Events\CertificateRenewed;
use Gunner42\LaravelEetModule\Exceptions\CertificateNotRenewable;
use Gunner42\LaravelEetModule\Exceptions\InvalidCertificate;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\CertificateRenewal;
use Gunner42\LaravelEetModule\Services\CaEetClient;
use Gunner42\LaravelEetModule\Services\CertificateReader;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Gunner42\LaravelEetModule\Services\DatabaseTransactionGuard;
use Gunner42\LaravelEetModule\Services\LockStore;
use Gunner42\LaravelEetModule\Services\QueryErrors;
use Gunner42\LaravelEetModule\Services\RenewalPreconditions;
use Gunner42\LaravelEetModule\Services\X509;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Sleep;
use OpenSSLAsymmetricKey;
use SensitiveParameter;
use Throwable;

/**
 * Renews a cash register certificate through the CA EET API (R90–R104):
 * continues the active renewal of the certificate or starts a new one and
 * takes as many steps as possible in one call. API outcomes are stored on the
 * renewal, never thrown. The downloaded PKCS#12 and its password exist only
 * as local values of the download step (R103).
 */
final class RenewCertificate
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    private const string DATE_TIME = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/iD';

    public function __construct(
        private readonly ConfigurationValidator $configuration,
        private readonly DatabaseTransactionGuard $transactions,
        private readonly LockStore $locks,
        private readonly CaEetClient $api,
        private readonly CertificateReader $reader,
        private readonly RenewalPreconditions $preconditions,
    ) {}

    /**
     * The renewal in its current state, or null when another process holds
     * the lock of the certificate (R104).
     */
    public function handle(CashRegisterCertificate $certificate): ?CertificateRenewal
    {
        $this->configuration->forRenewal();
        $this->transactions->ensureNoTransaction();

        $maxWait = ConfigurationValidator::integer('renewal.max_wait_seconds');
        // max wait + five API calls + 60 s (R104, Q72)
        $ttl = $maxWait + 5 * intdiv(ConfigurationValidator::integer('renewal.timeout_ms') + 999, 1000) + 60;
        $lock = $this->locks->acquire('laravel-eet-module:certificate:'.$certificate->getKey(), $ttl);

        if ($lock === null) {
            return null;
        }

        // exceptions raised while the successor is handled carry no arguments in their trace (R103)
        $previous = ini_set('zend.exception_ignore_args', '1');

        try {
            $certificate = CashRegisterCertificate::query()->whereKey($certificate->getKey())->firstOrFail();
            $renewal = $certificate->renewals()
                ->whereIn('status', RenewalStatus::activeValues())
                ->orderByDesc('id')
                ->first() ?? $this->start($certificate);

            return $this->run($certificate, $renewal, CarbonImmutable::now()->addSeconds($maxWait));
        } finally {
            $lock->release();

            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }

            X509::clearErrors();
        }
    }

    /**
     * Preconditions of a new renewal in the order of R90.
     */
    private function start(CashRegisterCertificate $certificate): CertificateRenewal
    {
        $problem = $this->preconditions->problem($certificate);

        if ($problem !== null) {
            throw new CertificateNotRenewable($problem);
        }

        return $certificate->renewals()->create(['status' => RenewalStatus::Requesting]);
    }

    private function run(CashRegisterCertificate $certificate, CertificateRenewal $renewal, CarbonImmutable $deadline): CertificateRenewal
    {
        do {
            $status = $renewal->status;

            if ($status === RenewalStatus::Acknowledging) {
                $this->acknowledge($certificate, $renewal);

                return $renewal;
            }

            if (! $status->isActive()) {
                return $renewal;
            }

            // R98: the renewed certificate must stay valid and usable until the successor is stored
            if (! $certificate->isValidAt(CarbonImmutable::now())) {
                $this->fail($certificate, $renewal, RenewalProblem::CertificateNotValid);

                return $renewal;
            }

            $key = $certificate->privateKey();

            if ($key === null) {
                $this->fail($certificate, $renewal, RenewalProblem::CertificateUnusable);

                return $renewal;
            }

            $continue = match ($status) {
                RenewalStatus::Requesting => $this->request($certificate, $key, $renewal),
                RenewalStatus::InProcess => $this->poll($certificate, $key, $renewal, $deadline),
                default => $this->download($certificate, $key, $renewal),
            };
        } while ($continue);

        return $renewal;
    }

    /**
     * R93: not-finished, then a takeover of a failed request of the same
     * certificate, a stop on an unknown request, or a new request.
     */
    private function request(CashRegisterCertificate $certificate, #[SensitiveParameter] OpenSSLAsymmetricKey $key, CertificateRenewal $renewal): bool
    {
        $result = $this->api->call($certificate, $key, 'GET', '/request/not-finished', 200);

        if ($result->problem !== null) {
            return $this->problem($certificate, $renewal, $result);
        }

        $requestIds = $this->requestIds($result->data);

        if ($requestIds === null) {
            return $this->temporary($renewal, 'HTTP 200: invalid response');
        }

        $takeOver = null;
        $unknown = [];

        foreach ($requestIds as $requestId) {
            $known = CertificateRenewal::query()->where('request_id', $requestId)->get();

            if ($known->isEmpty()) {
                $unknown[] = $requestId;
            } elseif ($takeOver === null && $known->contains(static fn (CertificateRenewal $other): bool => $other->certificate_id === $certificate->id && $other->status === RenewalStatus::Failed)) {
                $takeOver = $requestId;
            }
        }

        if ($takeOver !== null) {
            return $this->advance($renewal, RenewalStatus::InProcess, ['request_id' => $takeOver]);
        }

        if ($unknown !== []) {
            $this->store($renewal, ['problem' => RenewalProblem::UnknownPendingRequest, 'problem_detail' => implode(', ', $unknown)]);

            return false;
        }

        $result = $this->api->call($certificate, $key, 'POST', '/request/renew', 201);

        if ($result->problem !== null) {
            return $this->problem($certificate, $renewal, $result);
        }

        $requestId = $result->data['reqId'] ?? null;

        if (! is_string($requestId) || preg_match(self::UUID, $requestId) !== 1) {
            return $this->temporary($renewal, 'HTTP 201: invalid response');
        }

        return $this->advance($renewal, RenewalStatus::InProcess, ['request_id' => strtolower($requestId)]);
    }

    /**
     * R94: the status of the request; waits pollAfterSeconds while the wait
     * ends within renewal.max_wait_seconds from the start of the call.
     */
    private function poll(CashRegisterCertificate $certificate, #[SensitiveParameter] OpenSSLAsymmetricKey $key, CertificateRenewal $renewal, CarbonImmutable $deadline): bool
    {
        $result = $this->api->call($certificate, $key, 'GET', '/request/'.$renewal->request_id.'/status', 200);

        if ($result->problem !== null) {
            return $this->problem($certificate, $renewal, $result);
        }

        $data = $result->data;
        // a status of another request does not match the API contract (R101, Q72)
        $state = is_string($data['reqId'] ?? null) && strtolower($data['reqId']) === strtolower((string) $renewal->request_id)
            ? ($data['state'] ?? null)
            : null;

        switch ($state) {
            case 'INPROCESS':
                $seconds = $data['pollAfterSeconds'] ?? null;

                if (! is_int($seconds) || $seconds < 1) {
                    return $this->temporary($renewal, 'HTTP 200: invalid response');
                }

                if (! $this->store($renewal, ['problem' => null, 'problem_detail' => null])) {
                    return false;
                }

                // integers only: a huge pollAfterSeconds must not overflow a date
                if ($seconds > $deadline->getTimestamp() - CarbonImmutable::now()->getTimestamp()) {
                    return false;
                }

                Sleep::for($seconds)->seconds();

                return true;

            case 'ISSUED':
            case 'DELIVERING':
                return $this->advance($renewal, RenewalStatus::Downloading);

            case 'REJECTED':
                $reason = $data['rejectedReason'] ?? null;

                if (! is_string($reason)) {
                    return $this->temporary($renewal, 'HTTP 200: invalid response');
                }

                return $this->fail($certificate, $renewal, RenewalProblem::Rejected, $reason);

            case 'FINISHED':
                return $this->fail($certificate, $renewal, RenewalProblem::AlreadyFinished);

            default:
                return $this->temporary($renewal, 'HTTP 200: invalid response');
        }
    }

    /**
     * R95, R96: downloads the PKCS#12, checks the successor and stores it
     * together with the switched pins and Acknowledging in one transaction.
     */
    private function download(CashRegisterCertificate $certificate, #[SensitiveParameter] OpenSSLAsymmetricKey $key, CertificateRenewal $renewal): bool
    {
        $result = $this->api->call($certificate, $key, 'POST', '/request/'.$renewal->request_id.'/claim-download', 200);

        if ($result->problem !== null) {
            return $this->problem($certificate, $renewal, $result);
        }

        $download = $this->downloaded($result);

        if ($download === null) {
            return $this->temporary($renewal, 'HTTP 200: invalid response');
        }

        [$contents, $password, $serialNumber, $validFrom, $validTo] = $download;

        try {
            $parsed = $this->reader->read($contents, $password);
        } catch (InvalidCertificate $exception) {
            return $this->fail($certificate, $renewal, RenewalProblem::CertificateUnreadable, $exception->problem->value);
        }

        if (! $this->matches($certificate, $parsed, $serialNumber, $validFrom, $validTo)) {
            return $this->fail($certificate, $renewal, RenewalProblem::CertificateMismatch);
        }

        $successor = CashRegisterCertificate::fromParsed($certificate->taxpayer, $parsed);

        $connection = $renewal->getConnection();
        $connection->beginTransaction();

        try {
            $successor->save();

            CashRegister::query()
                ->where('certificate_id', $certificate->id)
                ->update(['certificate_id' => $successor->id, 'updated_at' => CarbonImmutable::now()]);

            $stored = $this->store($renewal, [
                'successor_id' => $successor->id,
                'status' => RenewalStatus::Acknowledging,
                'problem' => null,
                'problem_detail' => null,
            ]);

            if ($stored) {
                $connection->commit();
            }
        } catch (UniqueConstraintViolationException) {
            // the same certificate was stored concurrently (R13, R95); its message holds the bindings
            $connection->rollBack();
            $renewal->refresh();

            // a concurrent run moved the renewal on: the step is discarded (R104)
            if ($renewal->status !== RenewalStatus::Downloading) {
                return false;
            }

            return $this->fail($certificate, $renewal, RenewalProblem::CertificateMismatch);
        } catch (QueryException $exception) {
            $connection->rollBack();
            $renewal->refresh();

            throw QueryErrors::withoutBindings($exception);
        } catch (Throwable $exception) {
            // also a failed commit: back to the stored state, nothing claims an unsaved step
            $connection->rollBack();
            $renewal->refresh();

            throw $exception;
        }

        if (! $stored) {
            // another process changed the renewal: the step is discarded (R104)
            $connection->rollBack();
            $renewal->refresh();

            return false;
        }

        event(new CertificateRenewed($certificate, $successor, $renewal));

        return true;
    }

    /**
     * The fields of Pkcs12DTO, or null when the response does not match the
     * API schema (R101).
     *
     * @return array{string, string, string, int, int}|null
     */
    private function downloaded(#[SensitiveParameter] CaEetResult $result): ?array
    {
        $data = $result->data;
        $pkcs12 = is_string($data['pkcs12'] ?? null) ? base64_decode($data['pkcs12'], true) : false;
        $password = $data['password'] ?? null;
        $metadata = $data['certificate'] ?? null;

        if ($pkcs12 === false || $pkcs12 === '' || ! is_string($password) || preg_match('/^[A-Za-z0-9]{8}$/D', $password) !== 1 || ! is_array($metadata)) {
            return null;
        }

        $serialNumber = $metadata['sn'] ?? null;
        $validFrom = $this->timestamp($metadata['validFrom'] ?? null);
        $validTo = $this->timestamp($metadata['validTo'] ?? null);

        if (! is_string($serialNumber) || preg_match('/^\d+$/D', $serialNumber) !== 1 || $validFrom === null || $validTo === null) {
            return null;
        }

        return [$pkcs12, $password, $serialNumber, $validFrom, $validTo];
    }

    private function timestamp(mixed $value): ?int
    {
        if (! is_string($value) || preg_match(self::DATE_TIME, $value) !== 1) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Q59: same CN, a later validTo, serial number and validity as in the
     * metadata, and not yet stored for the taxpayer (R13).
     */
    private function matches(CashRegisterCertificate $certificate, #[SensitiveParameter] ParsedCertificate $parsed, string $serialNumber, int $validFrom, int $validTo): bool
    {
        $metadataSerialNumber = ltrim($serialNumber, '0');

        return $parsed->commonName === $certificate->subject_eic
            && $parsed->validTo > $certificate->valid_to->getTimestamp()
            && X509::hexToDecimal($parsed->serialNumber) === ($metadataSerialNumber === '' ? '0' : $metadataSerialNumber)
            && $parsed->validFrom === $validFrom
            && $parsed->validTo === $validTo
            && $this->sameIssuer($certificate, $parsed)
            && ! $certificate->taxpayer->certificates()->where('fingerprint_sha256', $parsed->fingerprint)->exists();
    }

    /**
     * Q71, Q74: a production certificate of the same issuer (DN and AKI),
     * signed by a certificate of the stored chain whose subject is the issuer
     * DN of the renewed certificate and whose key verifies the renewed
     * certificate. Without such an issuer in the chain it fails closed.
     */
    private function sameIssuer(CashRegisterCertificate $certificate, #[SensitiveParameter] ParsedCertificate $parsed): bool
    {
        $renewed = X509::read($certificate->certificate);
        $successor = X509::read($parsed->certificatePem);

        if ($renewed === null || $successor === null || ! X509::hasProductionPolicy($successor)) {
            return false;
        }

        $authorityKeyIdentifier = X509::authorityKeyIdentifier($successor);

        if (X509::issuerName($successor) !== X509::issuerName($renewed)
            || $authorityKeyIdentifier === null
            || $authorityKeyIdentifier !== X509::authorityKeyIdentifier($renewed)) {
            return false;
        }

        foreach (X509::pemBlocks((string) $certificate->chain) as $block) {
            $issuer = X509::read($block);

            if ($issuer !== null
                && X509::subjectName($issuer) === X509::issuerName($renewed)
                && X509::isSignedBy($renewed, $issuer)
                && X509::isSignedBy($successor, $issuer)) {
                return true;
            }
        }

        return false;
    }

    /**
     * R97: acknowledges the download with the key of the renewed certificate.
     */
    private function acknowledge(CashRegisterCertificate $certificate, CertificateRenewal $renewal): void
    {
        if (! $certificate->isValidAt(CarbonImmutable::now())) {
            $this->store($renewal, ['status' => RenewalStatus::Completed, 'completed_at' => CarbonImmutable::now(), 'problem' => null, 'problem_detail' => null]);

            return;
        }

        $key = $certificate->privateKey();

        if ($key === null) {
            // the ack cannot be signed; the successor stays (R97, Q69)
            $this->store($renewal, [
                'status' => RenewalStatus::Completed,
                'completed_at' => CarbonImmutable::now(),
                'problem' => RenewalProblem::CertificateUnusable,
                'problem_detail' => null,
            ]);

            return;
        }

        $result = $this->api->call($certificate, $key, 'POST', '/request/'.$renewal->request_id.'/ack-download', 204);

        if ($result->problem === null) {
            $this->store($renewal, [
                'status' => RenewalStatus::Completed,
                'acknowledged_at' => CarbonImmutable::now(),
                'completed_at' => CarbonImmutable::now(),
                'problem' => null,
                'problem_detail' => null,
            ]);

            return;
        }

        // 404/409: the CA already finished the request
        if (in_array($result->status, [404, 409], true)) {
            $this->store($renewal, ['status' => RenewalStatus::Completed, 'completed_at' => CarbonImmutable::now(), 'problem' => null, 'problem_detail' => null]);

            return;
        }

        if ($result->problem->isTemporary()) {
            $this->store($renewal, ['problem' => $result->problem, 'problem_detail' => $result->detail]);

            return;
        }

        // a final problem keeps the successor (Q67)
        $this->store($renewal, [
            'status' => RenewalStatus::Completed,
            'completed_at' => CarbonImmutable::now(),
            'problem' => $result->problem,
            'problem_detail' => $result->detail,
        ]);
    }

    /**
     * @return list<string>|null
     */
    private function requestIds(mixed $data): ?array
    {
        if (! is_array($data) || ! is_int($data['count'] ?? null)) {
            return null;
        }

        $list = $data['data'] ?? [];

        if (! is_array($list) || ! array_is_list($list)) {
            return null;
        }

        $requestIds = [];

        foreach ($list as $requestId) {
            if (! is_string($requestId) || preg_match(self::UUID, $requestId) !== 1) {
                return null;
            }

            $requestIds[] = strtolower($requestId);
        }

        return $requestIds;
    }

    /**
     * A successful step: the next state, without a problem (R101).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function advance(CertificateRenewal $renewal, RenewalStatus $status, array $attributes = []): bool
    {
        return $this->store($renewal, ['status' => $status, 'problem' => null, 'problem_detail' => null] + $attributes);
    }

    private function temporary(CertificateRenewal $renewal, string $detail): bool
    {
        $this->store($renewal, ['problem' => RenewalProblem::TemporaryError, 'problem_detail' => $detail]);

        return false;
    }

    private function problem(CashRegisterCertificate $certificate, CertificateRenewal $renewal, #[SensitiveParameter] CaEetResult $result): bool
    {
        $problem = $result->problem ?? RenewalProblem::TemporaryError;

        if ($problem->isTemporary()) {
            $this->store($renewal, ['problem' => $problem, 'problem_detail' => $result->detail]);

            return false;
        }

        return $this->fail($certificate, $renewal, $problem, $result->detail);
    }

    /**
     * A final problem: Failed and CertificateRenewalFailed after the commit
     * (R101, R102).
     */
    private function fail(CashRegisterCertificate $certificate, CertificateRenewal $renewal, RenewalProblem $problem, ?string $detail = null): bool
    {
        $stored = $this->store($renewal, [
            'status' => RenewalStatus::Failed,
            'failed_at' => CarbonImmutable::now(),
            'problem' => $problem,
            'problem_detail' => $detail,
        ]);

        if ($stored) {
            event(new CertificateRenewalFailed($certificate, $renewal, $problem));
        }

        return false;
    }

    /**
     * Writes the attributes only while the renewal is still in the state the
     * step started from; otherwise reloads it and returns false, so the step
     * is discarded (R104). problem_detail is stripped of control characters
     * and cut to 255 characters (R101).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function store(CertificateRenewal $renewal, array $attributes): bool
    {
        $from = $renewal->status;

        if (is_string($attributes['problem_detail'] ?? null)) {
            $attributes['problem_detail'] = self::detail($attributes['problem_detail']);
        }

        $renewal->forceFill($attributes);

        if (! $renewal->isDirty()) {
            return true;
        }

        $renewal->updated_at = CarbonImmutable::now();
        $updated = CertificateRenewal::query()
            ->whereKey($renewal->id)
            ->where('status', $from->value)
            ->toBase()
            ->update($renewal->getDirty());

        if ($updated !== 1) {
            $renewal->refresh();

            return false;
        }

        $renewal->syncOriginal();

        return true;
    }

    private static function detail(string $detail): string
    {
        $clean = preg_replace('/[\x{0}-\x{1F}\x{7F}-\x{9F}]/u', '', mb_scrub($detail, 'UTF-8'));

        return mb_substr(is_string($clean) ? $clean : '', 0, 255);
    }
}
