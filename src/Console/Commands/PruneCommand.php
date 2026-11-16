<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Console\Commands;

use Gunner42\LaravelEetModule\Actions\PruneRawMessages;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Illuminate\Console\Command;

/**
 * Deletes the raw XML of old attempts (R52).
 */
final class PruneCommand extends Command
{
    protected $signature = 'eet:prune {--days= : Age in days (default: retention.raw_xml_days)}';

    protected $description = 'Delete the raw request and response XML of old submission attempts.';

    public function handle(ConfigurationValidator $configuration, PruneRawMessages $prune): int
    {
        try {
            $configuration->forPrune();
        } catch (InvalidConfiguration $exception) {
            $this->error("Invalid configuration: laravel-eet-module.{$exception->key}");

            return self::FAILURE;
        }

        $option = $this->input->getOption('days');

        if ($option !== null) {
            if (! is_string($option) || ! ctype_digit($option) || (int) $option < 1) {
                $this->error('The --days option must be a positive integer.');

                return self::FAILURE;
            }

            $days = (int) $option;
        } else {
            $configured = config('laravel-eet-module.retention.raw_xml_days');

            if (! is_int($configured)) {
                $this->line('Nothing pruned: retention.raw_xml_days is not set and --days is missing.');

                return self::SUCCESS;
            }

            $days = $configured;
        }

        $this->info("Pruned the raw XML of {$prune->handle($days)} attempts older than {$days} days.");

        return self::SUCCESS;
    }
}
