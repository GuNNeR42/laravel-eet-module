<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Console\Commands;

use Gunner42\LaravelEetModule\Actions\VerifyConnection;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Illuminate\Console\Command;

/**
 * Verifies the connection of a cash register in verification mode (R55).
 */
final class VerifyCommand extends Command
{
    protected $signature = 'eet:verify
        {eic : EIČ of the taxpayer}
        {registration-unit : Number of the registration unit}
        {cash-register : Identifier of the cash register}';

    protected $description = 'Send a message in verification mode for a cash register.';

    public function handle(VerifyConnection $verify): int
    {
        $cashRegister = $this->cashRegister();

        if ($cashRegister === null) {
            $this->error('The cash register does not exist.');

            return self::FAILURE;
        }

        try {
            $result = $verify->handle($cashRegister);
        } catch (InvalidConfiguration $exception) {
            $this->error("Invalid configuration: laravel-eet-module.{$exception->key}");

            return self::FAILURE;
        }

        $this->line('Outcome: '.$result->outcome->label());
        $this->line('Code: '.($result->errorCode ?? '-'));
        $this->line('Text: '.($result->errorMessage ?? '-'));

        foreach ($result->warnings as $warning) {
            $this->warn("Warning {$warning->code}: {$warning->message}");
        }

        $this->line('X-Global-Transaction-Id: '.($result->globalTransactionId ?? '-'));

        return $result->outcome === AttemptOutcome::Verified ? self::SUCCESS : self::FAILURE;
    }

    private function cashRegister(): ?CashRegister
    {
        $eic = $this->input->getArgument('eic');
        $number = $this->input->getArgument('registration-unit');
        $identifier = $this->input->getArgument('cash-register');

        if (! is_string($eic) || ! is_string($number) || ! is_string($identifier) || ! ctype_digit($number)) {
            return null;
        }

        $taxpayer = Taxpayer::query()->where('eic', $eic)->first();

        if ($taxpayer === null) {
            return null;
        }

        $unit = $taxpayer->registrationUnits()->where('number', (int) $number)->first();

        return $unit?->cashRegisters()->where('identifier', $identifier)->first();
    }
}
