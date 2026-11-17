<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Console\Commands;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Actions\RenewCertificate;
use Gunner42\LaravelEetModule\Enums\RenewalStatus;
use Gunner42\LaravelEetModule\Exceptions\CertificateNotRenewable;
use Gunner42\LaravelEetModule\Exceptions\EetException;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\CertificateRenewal;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Gunner42\LaravelEetModule\Services\RenewalPreconditions;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Renews the certificates in the renewal window and continues active
 * renewals (R105). Scheduling is left to the host (Q53).
 */
final class RenewCertificateCommand extends Command
{
    protected $signature = 'eet:certificate:renew
        {--taxpayer=* : Only certificates of these taxpayers (EIČ)}
        {--certificate=* : Only these certificates (ID), also outside the renewal window and after a failed renewal}
        {--dry-run : Only list the certificates that would be processed}';

    protected $description = 'Renew cash register certificates through the CA EET API.';

    public function handle(ConfigurationValidator $configuration, RenewCertificate $renew, RenewalPreconditions $preconditions): int
    {
        try {
            $configuration->forRenewal();
        } catch (InvalidConfiguration $exception) {
            $this->error("Invalid configuration: laravel-eet-module.{$exception->key}");

            return self::FAILURE;
        }

        $taxpayerIds = $this->taxpayerIds();

        if ($taxpayerIds === null) {
            return self::FAILURE;
        }

        $failed = false;
        $given = $this->stringOptions('certificate');

        if ($given === []) {
            $certificates = $this->candidates($taxpayerIds);
        } else {
            $certificates = [];

            foreach ($given as $id) {
                $certificate = ctype_digit($id) ? CashRegisterCertificate::query()->find((int) $id) : null;

                if ($certificate === null) {
                    $this->error("Certificate [{$id}] does not exist.");
                    $failed = true;

                    continue;
                }

                if ($taxpayerIds !== [] && ! in_array($certificate->taxpayer_id, $taxpayerIds, true)) {
                    $this->error("Certificate [{$id}] does not belong to the given taxpayer.");
                    $failed = true;

                    continue;
                }

                $certificates[] = $certificate;
            }
        }

        if ($certificates === []) {
            $this->info('No certificate to renew.');
        }

        foreach ($certificates as $certificate) {
            if ($this->option('dry-run') === true) {
                // R90 applies only to a new renewal; an active one would be continued
                $latest = $certificate->latestRenewal();
                $problem = $latest !== null && $latest->status->isActive() ? null : $preconditions->problem($certificate);

                if ($problem !== null) {
                    $this->error($this->describe($certificate).': cannot be renewed: '.$problem->label());
                    $failed = true;

                    continue;
                }

                $this->line($this->describe($certificate).': would be processed');

                continue;
            }

            try {
                $renewal = $renew->handle($certificate);
            } catch (CertificateNotRenewable $exception) {
                $this->error($this->describe($certificate).': cannot be renewed: '.$exception->problem->label());
                $failed = true;

                continue;
            } catch (InvalidConfiguration $exception) {
                $this->error("Invalid configuration: laravel-eet-module.{$exception->key}");

                return self::FAILURE;
            } catch (Throwable $exception) {
                // never the trace; the message only of package exceptions, others may hold SQL bindings (R103, Q72)
                $message = $exception instanceof EetException ? ': '.$exception->getMessage() : '';
                $this->error($this->describe($certificate).': '.$exception::class.$message);
                $failed = true;

                continue;
            }

            if ($renewal === null) {
                $this->warn($this->describe($certificate).': running elsewhere');

                continue;
            }

            $this->line($this->describe($certificate).': '.$this->result($renewal));
            $failed = $failed || $renewal->status === RenewalStatus::Failed || ($renewal->problem?->isTemporary() ?? false);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * IDs of the taxpayers given by --taxpayer; null after printing an error,
     * an empty list without the option.
     *
     * @return list<int>|null
     */
    private function taxpayerIds(): ?array
    {
        $ids = [];

        foreach ($this->stringOptions('taxpayer') as $eic) {
            if (preg_match('/^CZ[0-9]{8,10}$/D', $eic) !== 1) {
                $this->error("The --taxpayer option [{$eic}] is not a valid EIČ (CZ followed by 8 to 10 digits).");

                return null;
            }

            $taxpayer = Taxpayer::query()->where('eic', $eic)->first();

            if ($taxpayer === null) {
                $this->error("Taxpayer [{$eic}] does not exist.");

                return null;
            }

            $ids[] = $taxpayer->id;
        }

        return $ids;
    }

    /**
     * Certificates with an active renewal and production certificates in the
     * renewal window that can be renewed and whose latest renewal did not fail.
     *
     * @param  list<int>  $taxpayerIds
     * @return list<CashRegisterCertificate>
     */
    private function candidates(array $taxpayerIds): array
    {
        $now = CarbonImmutable::now();
        $candidates = [];

        foreach ($this->query($taxpayerIds)->orderBy('id')->get() as $certificate) {
            $latest = $certificate->latestRenewal();

            if ($latest !== null && $latest->status->isActive()) {
                $candidates[] = $certificate;

                continue;
            }

            if ($latest?->status !== RenewalStatus::Failed
                && $certificate->hasProductionPolicy()
                && $certificate->isValidAt($now)
                && $certificate->isInRenewalWindow()
                && $certificate->isUsable()
                && $certificate->successor() === null) {
                $candidates[] = $certificate;
            }
        }

        return $candidates;
    }

    /**
     * @param  list<int>  $taxpayerIds
     * @return Builder<CashRegisterCertificate>
     */
    private function query(array $taxpayerIds): Builder
    {
        $query = CashRegisterCertificate::query();

        return $taxpayerIds === [] ? $query : $query->whereIn('taxpayer_id', $taxpayerIds);
    }

    private function describe(CashRegisterCertificate $certificate): string
    {
        return "Certificate #{$certificate->id} (CN {$certificate->subject_eic}, serial number {$certificate->decimalSerialNumber()}, valid to {$certificate->valid_to->format('Y-m-d')})";
    }

    private function result(CertificateRenewal $renewal): string
    {
        return $renewal->status->label().($renewal->problem === null ? '' : ' – '.$renewal->problem->label());
    }

    /**
     * @return list<string>
     */
    private function stringOptions(string $name): array
    {
        $values = $this->option($name);

        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
