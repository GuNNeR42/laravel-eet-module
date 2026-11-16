<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Enums\CertificateProblem;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Tester\CommandTester;

/*
 * The command reads the password from the console input stream (hidden
 * question or, with --password-stdin, its first line). Symfony's
 * CommandTester::setInputs() provides that stream.
 */

beforeEach(function (): void {
    // playground certificates are valid until 2027-05-14
    $this->travelTo(frozenNow());
});

/**
 * @param  array<string, mixed>  $arguments
 * @param  list<string>  $inputs
 */
function runImport(array $arguments, array $inputs = [], bool $interactive = false): CommandTester
{
    $command = Artisan::all()['eet:certificate:import'] ?? null;

    if ($command === null) {
        throw new RuntimeException('Command eet:certificate:import is not registered.');
    }

    $tester = new CommandTester($command);
    $tester->setInputs($inputs);
    $tester->execute($arguments, ['interactive' => $interactive]);

    return $tester;
}

it('imports a plain PEM without --taxpayer, creates the taxpayer from the CN and prints the result [R18]', function (): void {
    config()->set('laravel-eet-module.default_environment', 'production');
    $parsed = openssl_x509_parse(Fixtures::certificate('playground/CZ00000019.cert.pem'));
    assert(is_array($parsed));

    $tester = runImport(['path' => Fixtures::path('playground/CZ00000019.pem')]);

    $taxpayer = Taxpayer::query()->where('eic', 'CZ00000019')->first();
    $display = $tester->getDisplay();

    expect($tester->getStatusCode())->toBe(0)
        ->and($taxpayer)->not->toBeNull()
        ->and($taxpayer?->environment)->toBe(Environment::Production)
        ->and($taxpayer?->certificates()->count())->toBe(1)
        ->and($display)->toContain('CZ00000019')
        ->and($display)->toContain('2027-05-14')
        ->and(str_contains(strtoupper($display), strtoupper($parsed['serialNumberHex'])) || str_contains($display, $parsed['serialNumber']))->toBeTrue();
});

it('reads the password from the first line of standard input with --password-stdin [R18]', function (): void {
    $tester = runImport([
        'path' => Fixtures::path('playground/CZ00000019.modern.p12'),
        '--password-stdin' => true,
    ], [Fixtures::P12_PASSWORD]);

    expect($tester->getStatusCode())->toBe(0)
        ->and(CashRegisterCertificate::query()->count())->toBe(1)
        ->and($tester->getDisplay())->not->toContain(Fixtures::P12_PASSWORD);
});

it('asks for the password with a hidden question when the file needs one [R18]', function (): void {
    $tester = runImport(['path' => Fixtures::path('playground/CZ00000019.encrypted.pem')], [Fixtures::PEM_PASSWORD], interactive: true);

    expect($tester->getStatusCode())->toBe(0)
        ->and(CashRegisterCertificate::query()->count())->toBe(1)
        ->and($tester->getDisplay())->not->toContain(Fixtures::PEM_PASSWORD);
});

it('fails with exit code 1 on a wrong password and never prints the password [R18, R15]', function (): void {
    $tester = runImport([
        'path' => Fixtures::path('playground/CZ00000019.modern.p12'),
        '--password-stdin' => true,
    ], ['SpatneHeslo42']);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->not->toContain('SpatneHeslo42')
        ->and($tester->getDisplay())->toContain(CertificateProblem::Unreadable->label())
        ->and(CashRegisterCertificate::query()->count())->toBe(0);
});

it('prints the translated reason and exits with 1 for an expired certificate [R18, R10]', function (): void {
    $tester = runImport(['path' => Fixtures::path('cash-register/CZ00000019-expired-2025.pem')]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain(CertificateProblem::Expired->label())
        ->and($tester->getDisplay())->not->toContain('PRIVATE KEY')
        ->and(CashRegisterCertificate::query()->count())->toBe(0);
});

it('has no --password option [R18]', function (): void {
    $command = Artisan::all()['eet:certificate:import'];

    expect($command->getDefinition()->hasOption('password'))->toBeFalse()
        ->and($command->getDefinition()->hasOption('password-stdin'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('taxpayer'))->toBeTrue()
        ->and($command->getDefinition()->hasArgument('path'))->toBeTrue();
});

it('fails with exit code 1 for an invalid --taxpayer EIČ [R18]', function (): void {
    $tester = runImport(['path' => Fixtures::path('playground/CZ00000019.pem'), '--taxpayer' => 'XY']);

    expect($tester->getStatusCode())->toBe(1)
        ->and(Taxpayer::query()->count())->toBe(0)
        ->and(CashRegisterCertificate::query()->count())->toBe(0);
});

it('imports to the taxpayer given by --taxpayer [R18]', function (): void {
    $taxpayer = taxpayer('CZ00000019');

    $tester = runImport(['path' => Fixtures::path('playground/CZ00000019.pem'), '--taxpayer' => 'CZ00000019']);

    expect($tester->getStatusCode())->toBe(0)
        ->and(Taxpayer::query()->count())->toBe(1)
        ->and($taxpayer->certificates()->count())->toBe(1);
});

it('creates a missing --taxpayer with the default environment [R18]', function (): void {
    $tester = runImport(['path' => Fixtures::path('playground/CZ00000019.pem'), '--taxpayer' => 'CZ12345678']);

    $taxpayer = Taxpayer::query()->where('eic', 'CZ12345678')->first();

    expect($tester->getStatusCode())->toBe(0)
        ->and($taxpayer?->environment)->toBe(Environment::Playground)
        ->and($taxpayer?->certificates()->count())->toBe(1);
});

it('warns about a CN that differs from --taxpayer and still imports the certificate [R18, R14]', function (): void {
    taxpayer('CZ00000019');

    $tester = runImport(['path' => Fixtures::path('playground/CZ683555118.pem'), '--taxpayer' => 'CZ00000019']);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('CZ683555118')
        ->and(taxpayer('CZ00000019')->certificates()->count())->toBe(1)
        ->and(Taxpayer::query()->where('eic', 'CZ683555118')->exists())->toBeFalse();
});

it('fails with exit code 1 for a PKCS#12 with --no-interaction and without --password-stdin [R18]', function (): void {
    $tester = runImport(['path' => Fixtures::path('playground/CZ00000019.modern.p12')]);

    expect($tester->getStatusCode())->toBe(1)
        ->and(CashRegisterCertificate::query()->count())->toBe(0);
});

it('fails with exit code 1 for an encrypted PEM key with --no-interaction and without --password-stdin [R18]', function (): void {
    $tester = runImport(['path' => Fixtures::path('playground/CZ00000019.encrypted.pem')]);

    expect($tester->getStatusCode())->toBe(1)
        ->and(CashRegisterCertificate::query()->count())->toBe(0);
});

it('fails with exit code 1 when the CN is not a valid EIČ and --taxpayer is missing [R18]', function (): void {
    $tester = runImport(['path' => Fixtures::path('cash-register/TEST-cn.pem')]);

    expect($tester->getStatusCode())->toBe(1)
        ->and(Taxpayer::query()->count())->toBe(0)
        ->and(CashRegisterCertificate::query()->count())->toBe(0);
});

it('imports a CN that is not an EIČ when --taxpayer is given [R18, R14]', function (): void {
    $tester = runImport(['path' => Fixtures::path('cash-register/TEST-cn.pem'), '--taxpayer' => 'CZ00000019']);

    expect($tester->getStatusCode())->toBe(0)
        ->and(taxpayer('CZ00000019')->certificates()->count())->toBe(1);
});

it('prints the configuration key and exits with 1 on an invalid configuration [R18, R68]', function (): void {
    config()->set('laravel-eet-module.default_environment', 'staging');

    $tester = runImport(['path' => Fixtures::path('playground/CZ00000019.pem')]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('default_environment')
        ->and(Taxpayer::query()->count())->toBe(0);
});
