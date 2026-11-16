<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Data\MessageData;
use Gunner42\LaravelEetModule\Data\MessageHeader;
use Gunner42\LaravelEetModule\Data\ResponseEvaluation;
use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Data\VerificationResult;
use Gunner42\LaravelEetModule\Eet;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\SendingMode;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\RegistrationUnit;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Services\CertificateSelector;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Gunner42\LaravelEetModule\Services\MessageBuilder;
use Gunner42\LaravelEetModule\Services\MessageSigner;
use Gunner42\LaravelEetModule\Services\MessageUuid;
use Gunner42\LaravelEetModule\Services\ResponseEvaluator;
use Gunner42\LaravelEetModule\Services\Transport;
use Gunner42\LaravelEetModule\ValueObjects\Amount;
use OpenSSLAsymmetricKey;
use SensitiveParameter;

/**
 * Sends a message in verification mode to the current environment of the
 * taxpayer; stores nothing and dispatches no event (R47).
 */
final class VerifyConnection
{
    public function __construct(
        private readonly ConfigurationValidator $configuration,
        private readonly Eet $eet,
        private readonly CertificateSelector $certificates,
        private readonly MessageBuilder $builder,
        private readonly MessageSigner $signer,
        private readonly Transport $transport,
        private readonly ResponseEvaluator $evaluator,
    ) {}

    public function handle(CashRegister $cashRegister): VerificationResult
    {
        $this->configuration->forSending();

        $unit = RegistrationUnit::query()->findOrFail($cashRegister->registration_unit_id);
        $taxpayer = Taxpayer::query()->findOrFail($unit->taxpayer_id);
        $environment = $taxpayer->environment;
        $uuid = MessageUuid::next($this->eet->messageUuidFactory());
        $header = new MessageHeader($uuid, MessageBuilder::formatDateTime(CarbonImmutable::now()), true, true);
        $data = new MessageData(
            $taxpayer->eic,
            null,
            false,
            $unit->number,
            $cashRegister->identifier,
            ConfigurationValidator::string('verification.sequence_number'),
            MessageBuilder::formatDateTime(CarbonImmutable::now()),
            Amount::fromHalere(0),
            null,
            null,
        );
        $fake = $this->eet->currentFake();

        if ($fake !== null) {
            [$result, $verifySignature] = $fake->respond(SaleMessage::fromXml($this->builder->unsigned($header, $data)), $environment);
            $evaluation = $this->evaluator->evaluate($result, $uuid, $environment, SendingMode::Verification, $verifySignature, $this->transport->maxBytes());
        } else {
            $xml = $this->certificates->withCertificate(
                $taxpayer,
                $cashRegister,
                fn (CashRegisterCertificate $selected, #[SensitiveParameter] OpenSSLAsymmetricKey $key): string => $this->signer->sign($this->builder->envelope($header, $data), $selected->certificate, $key),
            );

            if ($xml === null) {
                $evaluation = new ResponseEvaluation(AttemptOutcome::CertificateUnavailable);
            } else {
                $result = $this->transport->send(ConfigurationValidator::string('endpoints.'.$environment->value), $xml);
                $evaluation = $this->evaluator->evaluate($result, $uuid, $environment, SendingMode::Verification, true, $this->transport->maxBytes());
            }
        }

        return new VerificationResult(
            $evaluation->outcome,
            $evaluation->errorCode,
            $evaluation->errorMessage,
            $evaluation->warnings,
            $evaluation->globalTransactionId,
        );
    }
}
