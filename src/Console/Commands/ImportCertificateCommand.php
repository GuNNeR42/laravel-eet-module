<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Console\Commands;

use Gunner42\LaravelEetModule\Actions\CreateTaxpayer;
use Gunner42\LaravelEetModule\Actions\ImportCertificate;
use Gunner42\LaravelEetModule\Exceptions\InvalidCertificate;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Services\CertificateReader;
use Gunner42\LaravelEetModule\Services\ConfigurationValidator;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Imports a cash register certificate from a file (R18).
 */
final class ImportCertificateCommand extends Command
{
    protected $signature = 'eet:certificate:import
        {path : PKCS#12 or PEM file with the certificate and the private key}
        {--taxpayer= : EIČ of the taxpayer (default: the CN of the certificate)}
        {--password-stdin : Read the password from the first line of standard input}';

    protected $description = 'Import a cash register certificate (PKCS#12 or PEM).';

    public function handle(ConfigurationValidator $configuration, CertificateReader $reader, CreateTaxpayer $createTaxpayer, ImportCertificate $import): int
    {
        try {
            $configuration->forTaxpayer();
        } catch (InvalidConfiguration $exception) {
            $this->error("Invalid configuration: laravel-eet-module.{$exception->key}");

            return self::FAILURE;
        }

        $path = $this->input->getArgument('path');
        $path = is_string($path) ? $path : '';
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            $this->error("The file [{$path}] cannot be read.");

            return self::FAILURE;
        }

        $taxpayerEic = $this->input->getOption('taxpayer');

        if ($taxpayerEic !== null && (! is_string($taxpayerEic) || ! self::isEic($taxpayerEic))) {
            $this->error('The --taxpayer option must be a valid EIČ (CZ followed by 8 to 10 digits).');

            return self::FAILURE;
        }

        $password = null;

        if ($reader->needsPassword($contents)) {
            $password = $this->password();

            if ($password === null) {
                $this->error('The file needs a password: use --password-stdin or run the command interactively.');

                return self::FAILURE;
            }
        }

        // exceptions raised while the file is read carry no arguments in their trace (R15)
        $previous = ini_set('zend.exception_ignore_args', '1');

        try {
            $parsed = $reader->read($contents, $password);
            $eic = is_string($taxpayerEic) ? $taxpayerEic : $parsed->commonName;

            if (! self::isEic($eic)) {
                $this->error('The CN of the certificate is not a valid EIČ; give the taxpayer with --taxpayer.');

                return self::FAILURE;
            }

            if ($parsed->commonName !== $eic) {
                $this->warn("The CN of the certificate [{$parsed->commonName}] differs from the EIČ of the taxpayer [{$eic}].");
            }

            $taxpayer = Taxpayer::query()->where('eic', $eic)->first() ?? $createTaxpayer->handle($eic);
            $certificate = $import->handle($taxpayer, $contents, $password);
        } catch (InvalidCertificate $exception) {
            $this->error('The certificate cannot be imported: '.$exception->problem->label());

            return self::FAILURE;
        } catch (InvalidConfiguration $exception) {
            $this->error("Invalid configuration: laravel-eet-module.{$exception->key}");

            return self::FAILURE;
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }

        $this->info("Imported the certificate for taxpayer {$taxpayer->eic}.");
        $this->line("Serial number: {$certificate->serial_number}");
        $this->line('Valid to: '.$certificate->valid_to->format('Y-m-d H:i:s T'));

        return self::SUCCESS;
    }

    private function password(): ?string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;

        if ($this->input->getOption('password-stdin') === true) {
            return $this->firstLine(is_resource($stream) ? $stream : STDIN);
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        // an input stream that is not a terminal (a pipe, a test) shows nothing that could be hidden;
        // reading it directly avoids the platform-specific terminal handling of hidden questions
        if (is_resource($stream) && ! stream_isatty($stream)) {
            $this->output->write('Password: ');

            return $this->firstLine($stream);
        }

        $password = $this->secret('Password');

        return is_string($password) ? $password : '';
    }

    private function firstLine(mixed $stream): string
    {
        $line = is_resource($stream) ? fgets($stream) : false;

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    private static function isEic(string $value): bool
    {
        return preg_match('/^CZ[0-9]{8,10}$/D', $value) === 1;
    }
}
