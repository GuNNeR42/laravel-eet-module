<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Console\Commands;

use Gunner42\LaravelEetModule\Actions\NotifyOverdueSale;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Enums\SendingMode;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\SubmissionAttempt;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Overview for monitoring (R54): counts by status and environment, overdue
 * and rejected production sales and configuration warnings.
 */
final class StatusCommand extends Command
{
    protected $signature = 'eet:status';

    protected $description = 'Show sales that need attention; exits with 1 when there are any.';

    public function handle(ConfigurationValidator $configuration, NotifyOverdueSale $notifyOverdue): int
    {
        try {
            $configuration->forStatus();
        } catch (InvalidConfiguration $exception) {
            $this->error("Invalid configuration: laravel-eet-module.{$exception->key}");

            return self::FAILURE;
        }

        $this->counts();

        $findings = $this->overdue($notifyOverdue);
        $findings = $this->rejected() || $findings;
        $findings = $this->configurationWarnings() || $findings;

        return $findings ? self::FAILURE : self::SUCCESS;
    }

    private function counts(): void
    {
        $this->info('Sales by status and environment:');

        $rows = Sale::query()
            ->selectRaw('status, environment, count(*) as aggregate')
            ->groupBy('status', 'environment')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $status = SaleStatus::tryFrom((string) ($row->status ?? ''));
            $label = $status?->label() ?? (string) ($row->status ?? '');
            $this->line("  {$label} / ".((string) ($row->environment ?? '')).': '.((int) ($row->aggregate ?? 0)));
        }
    }

    private function overdue(NotifyOverdueSale $notifyOverdue): bool
    {
        $sales = $this->productionLive()->awaitingResubmission()->orderBy('id')->get()
            ->filter(static fn (Sale $sale): bool => $sale->isOverdue());

        if ($sales->isEmpty()) {
            return false;
        }

        $this->error('Unconfirmed production sales after the 48 h deadline:');

        foreach ($sales as $sale) {
            $notifyOverdue->handle($sale);
            $lastAttempt = $sale->attempts()->orderByDesc('id')->first();
            $this->describe($sale, $lastAttempt?->error_code);
        }

        return true;
    }

    private function rejected(): bool
    {
        $sales = $this->productionLive()->where('status', SaleStatus::Rejected->value)->orderBy('id')->get();

        if ($sales->isEmpty()) {
            return false;
        }

        $this->error('Rejected production sales:');

        foreach ($sales as $sale) {
            $rejecting = $sale->rejecting_attempt_id === null ? null : SubmissionAttempt::query()->find($sale->rejecting_attempt_id);
            $reopenable = $rejecting !== null
                && ($rejecting->outcome === AttemptOutcome::AmbiguousError || $rejecting->error_code === 4);

            $this->describe($sale, $rejecting?->error_code);
            $this->line($reopenable ? '    can be reopened with eet:resubmit --sale' : '    reopening needs eet:resubmit --sale --force');
        }

        return true;
    }

    private function configurationWarnings(): bool
    {
        $findings = false;
        $productionTaxpayer = Taxpayer::query()->where('environment', Environment::Production->value)->exists();

        if ($productionTaxpayer && config('laravel-eet-module.mode') === SendingMode::Verification->value) {
            $this->warn('The sending mode is verification although a taxpayer is in production: sales do not fulfil the obligation.');
            $findings = true;
        }

        if ($productionTaxpayer && config('laravel-eet-module.response_signer.production.common_name') === null) {
            $this->warn('response_signer.production.common_name is not configured.');
            $signer = SubmissionAttempt::query()
                ->where('outcome', AttemptOutcome::Confirmed->value)
                ->whereNotNull('signer_fingerprint')
                ->whereHas('sale', static fn (Builder $query): Builder => $query->where('environment', Environment::Production->value))
                ->orderByDesc('id')
                ->first();

            if ($signer !== null) {
                $this->line("  Last verified production signer: {$signer->signer_subject}");
                $this->line("  SHA-256 fingerprint: {$signer->signer_fingerprint}");
            }

            $findings = true;
        }

        if ($productionTaxpayer && config('laravel-eet-module.response_trust_store') !== null) {
            $this->warn('A custom response_trust_store is configured although a taxpayer is in production.');
            $findings = true;
        }

        if (Sale::query()->where('environment', Environment::Production->value)->where('is_fake', true)->exists()) {
            $this->warn('Production sales created under Eet::fake() exist.');
            $findings = true;
        }

        return $findings;
    }

    /**
     * @return Builder<Sale>
     */
    private function productionLive(): Builder
    {
        return Sale::query()
            ->where('environment', Environment::Production->value)
            ->where('mode', SendingMode::Live->value);
    }

    private function describe(Sale $sale, ?int $errorCode): void
    {
        $this->line("  Sale #{$sale->id}");
        $this->line("    EIČ: {$sale->eic}");
        $this->line("    Sequence number: {$sale->sequence_number}");
        $this->line("    Occurred at: {$sale->occurred_at}");
        $this->line('    Error code: '.($errorCode ?? '-'));
    }
}
