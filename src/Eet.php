<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule;

use Closure;
use Gunner42\LaravelEetModule\Actions\ChangeTaxpayerEnvironment;
use Gunner42\LaravelEetModule\Actions\CreateCashRegister;
use Gunner42\LaravelEetModule\Actions\CreateRegistrationUnit;
use Gunner42\LaravelEetModule\Actions\CreateTaxpayer;
use Gunner42\LaravelEetModule\Actions\ImportCertificate;
use Gunner42\LaravelEetModule\Actions\RegisterSale;
use Gunner42\LaravelEetModule\Actions\ResubmitSale;
use Gunner42\LaravelEetModule\Actions\ReverseSale;
use Gunner42\LaravelEetModule\Actions\VerifyConnection;
use Gunner42\LaravelEetModule\Data\ReversalData;
use Gunner42\LaravelEetModule\Data\SaleData;
use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Data\VerificationResult;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\RegistrationUnit;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use Gunner42\LaravelEetModule\Services\CertificateReader;
use Gunner42\LaravelEetModule\Testing\EetFake;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use SensitiveParameter;

/**
 * The package manager behind the Eet facade. Delegates to the Actions and
 * holds the test doubles (fake, message UUID factory).
 */
class Eet
{
    private ?EetFake $fake = null;

    /** @var (Closure(): string)|null */
    private ?Closure $messageUuidFactory = null;

    public function createTaxpayer(string $eic, ?string $name = null, ?Environment $environment = null): Taxpayer
    {
        return app(CreateTaxpayer::class)->handle($eic, $name, $environment);
    }

    public function changeEnvironment(Taxpayer $taxpayer, Environment $environment): Taxpayer
    {
        return app(ChangeTaxpayerEnvironment::class)->handle($taxpayer, $environment);
    }

    public function createRegistrationUnit(Taxpayer $taxpayer, int $number, ?string $name = null): RegistrationUnit
    {
        return app(CreateRegistrationUnit::class)->handle($taxpayer, $number, $name);
    }

    public function createCashRegister(RegistrationUnit $unit, string $identifier, ?string $name = null, ?CashRegisterCertificate $certificate = null): CashRegister
    {
        return app(CreateCashRegister::class)->handle($unit, $identifier, $name, $certificate);
    }

    public function importCertificate(Taxpayer $taxpayer, #[SensitiveParameter] string $contents, #[SensitiveParameter] ?string $password = null): CashRegisterCertificate
    {
        return app(ImportCertificate::class)->handle($taxpayer, $contents, $password);
    }

    public function supportsLegacyPkcs12(): bool
    {
        return app(CertificateReader::class)->supportsLegacyPkcs12();
    }

    public function register(CashRegister $cashRegister, SaleData $data): Sale
    {
        return app(RegisterSale::class)->handle($cashRegister, $data);
    }

    public function reverse(Sale $original, ReversalData $data): Sale
    {
        return app(ReverseSale::class)->handle($original, $data);
    }

    public function resubmit(Sale $sale, bool $force = false): Sale
    {
        return app(ResubmitSale::class)->handle($sale, $force);
    }

    public function verifyConnection(CashRegister $cashRegister): VerificationResult
    {
        return app(VerifyConnection::class)->handle($cashRegister);
    }

    /**
     * Replaces sending in tests (R57).
     *
     * @param  array<int, FakeResponse>  $responses
     */
    public function fake(array $responses = []): EetFake
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('Eet::fake() may only be used while running tests.');
        }

        return $this->fake = new EetFake($responses);
    }

    /**
     * Lets tests choose the message UUIDs; null restores the default (R29).
     *
     * @param  (callable(): string)|null  $factory
     */
    public function createMessageUuidsUsing(?callable $factory): void
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('Eet::createMessageUuidsUsing() may only be used while running tests.');
        }

        $this->messageUuidFactory = $factory === null ? null : Closure::fromCallable($factory);
    }

    /**
     * @param  (callable(SaleMessage): bool)|null  $callback
     */
    public function assertSent(?callable $callback = null): void
    {
        $this->requireFake()->assertSent($callback);
    }

    public function assertSentCount(int $count): void
    {
        $this->requireFake()->assertSentCount($count);
    }

    public function assertNothingSent(): void
    {
        $this->requireFake()->assertNothingSent();
    }

    /**
     * @internal
     */
    public function currentFake(): ?EetFake
    {
        return $this->fake;
    }

    /**
     * @internal
     *
     * @return (Closure(): string)|null
     */
    public function messageUuidFactory(): ?Closure
    {
        return $this->messageUuidFactory;
    }

    private function requireFake(): EetFake
    {
        if ($this->fake === null) {
            throw new AssertionFailedError('Call Eet::fake() before asserting sent data messages.');
        }

        return $this->fake;
    }
}
