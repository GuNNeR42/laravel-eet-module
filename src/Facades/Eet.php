<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Gunner42\LaravelEetModule\Models\Taxpayer createTaxpayer(string $eic, ?string $name = null, ?\Gunner42\LaravelEetModule\Enums\Environment $environment = null)
 * @method static \Gunner42\LaravelEetModule\Models\Taxpayer changeEnvironment(\Gunner42\LaravelEetModule\Models\Taxpayer $taxpayer, \Gunner42\LaravelEetModule\Enums\Environment $environment)
 * @method static \Gunner42\LaravelEetModule\Models\RegistrationUnit createRegistrationUnit(\Gunner42\LaravelEetModule\Models\Taxpayer $taxpayer, int $number, ?string $name = null)
 * @method static \Gunner42\LaravelEetModule\Models\CashRegister createCashRegister(\Gunner42\LaravelEetModule\Models\RegistrationUnit $unit, string $identifier, ?string $name = null, ?\Gunner42\LaravelEetModule\Models\CashRegisterCertificate $certificate = null)
 * @method static \Gunner42\LaravelEetModule\Models\CashRegisterCertificate importCertificate(\Gunner42\LaravelEetModule\Models\Taxpayer $taxpayer, string $contents, ?string $password = null)
 * @method static bool supportsLegacyPkcs12()
 * @method static \Gunner42\LaravelEetModule\Models\CertificateRenewal|null renewCertificate(\Gunner42\LaravelEetModule\Models\CashRegisterCertificate $certificate)
 * @method static \Gunner42\LaravelEetModule\Models\Sale register(\Gunner42\LaravelEetModule\Models\CashRegister $cashRegister, \Gunner42\LaravelEetModule\Data\SaleData $data)
 * @method static \Gunner42\LaravelEetModule\Models\Sale reverse(\Gunner42\LaravelEetModule\Models\Sale $original, \Gunner42\LaravelEetModule\Data\ReversalData $data)
 * @method static \Gunner42\LaravelEetModule\Models\Sale resubmit(\Gunner42\LaravelEetModule\Models\Sale $sale, bool $force = false)
 * @method static \Gunner42\LaravelEetModule\Data\VerificationResult verifyConnection(\Gunner42\LaravelEetModule\Models\CashRegister $cashRegister)
 * @method static \Gunner42\LaravelEetModule\Testing\EetFake fake(array<int, \Gunner42\LaravelEetModule\Testing\FakeResponse> $responses = [])
 * @method static void createMessageUuidsUsing(?callable $factory)
 * @method static void assertSent(?callable $callback = null)
 * @method static void assertSentCount(int $count)
 * @method static void assertNothingSent()
 *
 * @see \Gunner42\LaravelEetModule\Eet
 */
class Eet extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Gunner42\LaravelEetModule\Eet::class;
    }
}
