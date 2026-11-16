<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Data\MessageData;
use Gunner42\LaravelEetModule\Data\MessageHeader;
use Gunner42\LaravelEetModule\Data\ResponseEvaluation;
use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Data\TransportResult;
use Gunner42\LaravelEetModule\Data\Warning;
use Gunner42\LaravelEetModule\Eet;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\PreflightWarning;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Enums\SendingMode;
use Gunner42\LaravelEetModule\Events\SaleConfirmed;
use Gunner42\LaravelEetModule\Events\SalePreflightWarned;
use Gunner42\LaravelEetModule\Events\SaleRejected;
use Gunner42\LaravelEetModule\Events\SaleUnconfirmed;
use Gunner42\LaravelEetModule\Events\SaleVerified;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\SubmissionAttempt;
use Gunner42\LaravelEetModule\Services\CertificateSelector;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Gunner42\LaravelEetModule\Services\MessageBuilder;
use Gunner42\LaravelEetModule\Services\MessageSigner;
use Gunner42\LaravelEetModule\Services\MessageUuid;
use Gunner42\LaravelEetModule\Services\ResponseEvaluator;
use Gunner42\LaravelEetModule\Services\Transport;
use Illuminate\Support\Facades\DB;
use OpenSSLAsymmetricKey;
use SensitiveParameter;

/**
 * Performs one attempt for a sale (R16, R29–R37, R41, R51, R67): selects the
 * certificate, builds, signs and sends the message, evaluates the response,
 * stores the attempt and writes the result to the sale conditionally. The
 * caller holds the lock (R43) and schedules the resubmission (R40).
 *
 * @internal
 */
final class SendSaleAttempt
{
    public function __construct(
        private readonly Eet $eet,
        private readonly CertificateSelector $certificates,
        private readonly MessageBuilder $builder,
        private readonly MessageSigner $signer,
        private readonly Transport $transport,
        private readonly ResponseEvaluator $evaluator,
        private readonly NotifyOverdueSale $notifyOverdue,
    ) {}

    public function handle(Sale $sale, ?string $messageUuid = null): SubmissionAttempt
    {
        $startStatus = $sale->status;
        $uuid = $messageUuid ?? MessageUuid::next($this->eet->messageUuidFactory());
        $sentAt = CarbonImmutable::now();
        $header = new MessageHeader(
            $uuid,
            MessageBuilder::formatDateTime($sentAt),
            ! $sale->attempts()->where('outcome', '!=', AttemptOutcome::CertificateUnavailable->value)->exists(),
            $sale->mode === SendingMode::Verification,
        );
        $data = MessageData::fromSale($sale);
        $fake = $this->eet->currentFake();
        $certificate = null;
        $xml = null;
        $started = hrtime(true);

        if ($fake !== null) {
            $xml = $this->builder->unsigned($header, $data);
            [$result, $verifySignature] = $fake->respond(SaleMessage::fromXml($xml), $sale->environment);
            $evaluation = $this->evaluate($result, $uuid, $sale, $verifySignature);
        } else {
            $signed = $this->certificates->withCertificate(
                $sale->taxpayer,
                $sale->cashRegister,
                fn (CashRegisterCertificate $selected, #[SensitiveParameter] OpenSSLAsymmetricKey $key): array => [
                    $selected,
                    $this->signer->sign($this->builder->envelope($header, $data), $selected->certificate, $key),
                ],
            );

            if ($signed === null) {
                $evaluation = new ResponseEvaluation(AttemptOutcome::CertificateUnavailable);
            } else {
                [$certificate, $xml] = $signed;
                $result = $this->transport->send(ConfigurationValidator::string('endpoints.'.$sale->environment->value), $xml);
                $evaluation = $this->evaluate($result, $uuid, $sale, true);
            }
        }

        $duration = intdiv(hrtime(true) - $started, 1_000_000);
        $newWarnings = $certificate !== null && $certificate->subject_eic !== $sale->eic
            && ! in_array(PreflightWarning::CertificateEicMismatch, $sale->preflightWarnings(), true)
            ? [PreflightWarning::CertificateEicMismatch]
            : [];

        $events = [];
        $attempt = DB::transaction(function () use ($sale, $startStatus, $header, $sentAt, $evaluation, $certificate, $xml, $duration, $fake, $newWarnings, &$events): SubmissionAttempt {
            $attempt = $sale->attempts()->create([
                'message_uuid' => $header->messageUuid,
                'is_first_submission' => $header->isFirstSubmission,
                'certificate_id' => $certificate?->id,
                'certificate_fingerprint' => $certificate?->fingerprint_sha256,
                'sent_at' => $sentAt,
                'outcome' => $evaluation->outcome,
                'error_code' => $evaluation->errorCode,
                'error_message' => $evaluation->errorMessage,
                'warnings' => array_map(static fn (Warning $warning): array => $warning->toArray(), $evaluation->warnings),
                'received_at' => self::dateTime($evaluation->receivedAt),
                'rejected_at' => self::dateTime($evaluation->rejectedAt),
                'confirmation_code' => $evaluation->confirmationCode,
                'is_test' => $evaluation->testFlag,
                'global_transaction_id' => $evaluation->globalTransactionId,
                'http_status' => $evaluation->httpStatus,
                'duration_ms' => max(0, $duration),
                'request_xml' => in_array($evaluation->outcome, [AttemptOutcome::Confirmed, AttemptOutcome::Verified], true) ? null : $xml,
                'response_xml' => $evaluation->responseXml,
                'signer_subject' => $evaluation->signerSubject,
                'signer_fingerprint' => $evaluation->signerFingerprint,
                'is_fake' => $fake !== null,
            ]);

            $events = $this->writeResult($sale, $startStatus, $attempt, $evaluation);

            if ($newWarnings !== []) {
                $sale->refresh();
                $sale->preflight_warnings = array_values(array_unique(array_merge(
                    $sale->preflight_warnings ?? [],
                    array_map(static fn (PreflightWarning $warning): string => $warning->value, $newWarnings),
                )));
                $sale->save();
                $events[] = static fn (Sale $sale): SalePreflightWarned => new SalePreflightWarned($sale, $newWarnings);
            }

            return $attempt;
        });

        $sale->refresh();

        foreach ($events as $event) {
            event($event($sale));
        }

        $this->notifyOverdue->handle($sale);

        return $attempt;
    }

    private function evaluate(TransportResult $result, string $uuid, Sale $sale, bool $verifySignature): ResponseEvaluation
    {
        return $this->evaluator->evaluate($result, $uuid, $sale->environment, $sale->mode, $verifySignature, $this->transport->maxBytes());
    }

    /**
     * Conditional write of the result (R67) and the limit of R41. Returns the
     * events to dispatch after the commit (R39).
     *
     * @return list<callable(Sale): object>
     */
    private function writeResult(Sale $sale, SaleStatus $startStatus, SubmissionAttempt $attempt, ResponseEvaluation $evaluation): array
    {
        $now = CarbonImmutable::now();
        $query = static fn () => Sale::query()->whereKey($sale->id);

        if ($evaluation->outcome === AttemptOutcome::Confirmed) {
            $isTest = $sale->environment === Environment::Playground
                || $evaluation->testFlag
                || str_ends_with(strtolower((string) $evaluation->confirmationCode), '-ff');

            $updated = $query()->where('status', '!=', SaleStatus::Confirmed->value)->update([
                'status' => SaleStatus::Confirmed->value,
                'confirmation_code' => $evaluation->confirmationCode,
                'confirmed_at' => self::dateTime($evaluation->receivedAt) ?? $now,
                'is_test' => $isTest,
                'rejecting_attempt_id' => null,
                'updated_at' => $now,
            ]);

            return $updated === 1 ? [static fn (Sale $sale): SaleConfirmed => new SaleConfirmed($sale)] : [];
        }

        if ($evaluation->outcome === AttemptOutcome::Verified) {
            $updated = $query()->where('status', $startStatus->value)->update(['status' => SaleStatus::Verified->value, 'updated_at' => $now]);

            return $updated === 1 ? [static fn (Sale $sale): SaleVerified => new SaleVerified($sale)] : [];
        }

        if ($evaluation->outcome === AttemptOutcome::Rejected || $this->ambiguousLimitReached($sale, $evaluation)) {
            $updated = $query()->where('status', $startStatus->value)->update([
                'status' => SaleStatus::Rejected->value,
                'rejecting_attempt_id' => $attempt->id,
                'updated_at' => $now,
            ]);
            $errorCode = $evaluation->errorCode;

            return $updated === 1 ? [static fn (Sale $sale): SaleRejected => new SaleRejected($sale, $errorCode)] : [];
        }

        if ($startStatus !== SaleStatus::Pending) {
            return [];
        }

        $updated = $query()->where('status', SaleStatus::Pending->value)->update(['status' => SaleStatus::Unconfirmed->value, 'updated_at' => $now]);
        $outcome = $evaluation->outcome;

        return $updated === 1 ? [static fn (Sale $sale): SaleUnconfirmed => new SaleUnconfirmed($sale, $outcome)] : [];
    }

    /**
     * AmbiguousError attempts since creation or after reopened_after_attempt_id reach the limit (R41).
     */
    private function ambiguousLimitReached(Sale $sale, ResponseEvaluation $evaluation): bool
    {
        if ($evaluation->outcome !== AttemptOutcome::AmbiguousError) {
            return false;
        }

        $limit = ConfigurationValidator::integer('resubmission.max_ambiguous_attempts');
        $query = $sale->attempts()->where('outcome', AttemptOutcome::AmbiguousError->value);

        if ($sale->reopened_after_attempt_id !== null) {
            $query->where('id', '>', $sale->reopened_after_attempt_id);
        }

        return $query->count() >= $limit;
    }

    private static function dateTime(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->setTimezone(date_default_timezone_get());
    }
}
