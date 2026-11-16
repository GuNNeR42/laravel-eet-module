<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Data\SaleData;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\PreflightWarning;
use Gunner42\LaravelEetModule\Enums\SaleDataProblem;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Exceptions\InvalidSaleData;
use Gunner42\LaravelEetModule\Models\RegistrationUnit;
use Gunner42\LaravelEetModule\Models\Taxpayer;

/**
 * Format checks (R20), blocking discrepancies in the order a, c, d, g, h and
 * local warnings b, e (R21, R61).
 */
final class SaleValidator
{
    private const string EIC_MASK = '/^CZ[0-9]{8,10}$/D';

    /**
     * @return list<PreflightWarning>
     */
    public function validate(Taxpayer $taxpayer, Environment $environment, RegistrationUnit $unit, SaleData $data): array
    {
        if (preg_match(ConfigurationValidator::SEQUENCE_NUMBER_MASK, $data->sequenceNumber) !== 1) {
            throw new InvalidSaleData('sequenceNumber', SaleDataProblem::InvalidFormat);
        }

        if ($data->delegatingEic !== null && preg_match(self::EIC_MASK, $data->delegatingEic) !== 1) {
            throw new InvalidSaleData('delegatingEic', SaleDataProblem::InvalidFormat);
        }

        // a
        if ($data->delegatingEic === $taxpayer->eic) {
            throw new InvalidSaleData('delegatingEic', SaleDataProblem::DelegatingEicEqualsTaxpayerEic);
        }

        $now = CarbonImmutable::now();
        $occurredAt = CarbonImmutable::instance($data->occurredAt)->startOfSecond();

        // c
        if ($occurredAt->lessThan($now->subYears(2))) {
            throw new InvalidSaleData('occurredAt', SaleDataProblem::TooOld);
        }

        // d
        if ($occurredAt->lessThan($this->minimumOccurredAt($environment))) {
            throw new InvalidSaleData('occurredAt', SaleDataProblem::BeforeEnvironmentStart);
        }

        // g
        foreach (['amountForLaterDrawing' => $data->amountForLaterDrawing, 'drawnAmount' => $data->drawnAmount] as $field => $part) {
            if ($part !== null && ! $part->isZero() && ! $data->totalAmount->isZero() && $part->isNegative() !== $data->totalAmount->isNegative()) {
                throw new InvalidSaleData($field, SaleDataProblem::SignMismatch);
            }
        }

        // h
        $parts = ($data->amountForLaterDrawing?->halere() ?? 0) + ($data->drawnAmount?->halere() ?? 0);

        if (abs($parts) > abs($data->totalAmount->halere())) {
            throw new InvalidSaleData('totalAmount', SaleDataProblem::PartsExceedTotal);
        }

        $warnings = [];

        // b
        if ($occurredAt->greaterThan($now->addHours(2))) {
            $warnings[] = PreflightWarning::SaleInFuture;
        }

        // e
        if (! $unit->hasDisFormat()) {
            $warnings[] = PreflightWarning::UnusualRegistrationUnitNumber;
        }

        return $warnings;
    }

    private function minimumOccurredAt(Environment $environment): CarbonImmutable
    {
        $key = 'minimum_occurred_at.'.$environment->value;
        $minimum = CarbonImmutable::createFromFormat('!Y-m-d H:i', ConfigurationValidator::string($key), ConfigurationValidator::string('timezone'));

        if (! $minimum instanceof CarbonImmutable) {
            throw new InvalidConfiguration($key);
        }

        return $minimum;
    }
}
