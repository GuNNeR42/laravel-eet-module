<div align="center">
    <h1>EET 2.0 Module</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/gunner42/laravel-eet-module"><img src="https://img.shields.io/packagist/v/gunner42/laravel-eet-module.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/gunner42/laravel-eet-module"><img src="https://img.shields.io/packagist/php-v/gunner42/laravel-eet-module.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/gunner42/laravel-eet-module"><img src="https://badge.laravel.cloud/badge/gunner42/laravel-eet-module?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/gunner42/laravel-eet-module/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/gunner42/laravel-eet-module/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/gunner42/laravel-eet-module"><img src="https://img.shields.io/packagist/dt/gunner42/laravel-eet-module.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Laravel package for the Czech electronic registration of sales (EET 2.0, interface v4): taxpayers, registration units, cash registers and cash register certificates in your database, signed data messages sent synchronously to the EET service, verified confirmations (POK), automatic resubmission, reversals and a test double for your own tests.

## Requirements

- PHP 8.3+, `ext-dom`, `ext-openssl`
- Laravel 12 or 13
- a cache store with atomic locks (`redis`, `database`, `memcached`, `dynamodb`, `file`) and a real queue connection (not `sync`, `deferred`, `background` or `null`)

## Installation

```bash
composer require gunner42/laravel-eet-module
php artisan vendor:publish --tag="laravel-eet-module-migrations"
php artisan migrate
```

Optionally publish the configuration and the translations (`cs`, `en`):

```bash
php artisan vendor:publish --tag="laravel-eet-module-config"
php artisan vendor:publish --tag="laravel-eet-module-lang"
```

`--tag="laravel-eet-module"` publishes all three. The package has no routes and no views.

## Configuration

The configuration lives in `config/laravel-eet-module.php`. Every value outside the valid values throws `InvalidConfiguration` with the key before anything is stored or sent.

| Key | Default | Notes |
|---|---|---|
| `default_environment` | `playground` | environment of new taxpayers (`playground`, `production`) |
| `mode` | `live` | `verification` sends `overeni="true"` (no legal effect) |
| `endpoints.playground` / `endpoints.production` | official endpoints | `https`, host from `endpoints.allowed_hosts.*`, port 443, no IP address |
| `endpoints.allowed_hosts.playground` / `.production` | `['pg.trzbyeet.gov.cz']` / `['trzbyeet.gov.cz']` | non-empty list of DNS names; IP addresses are never allowed |
| `response_timeout_ms` | `4000` | at least 2000 (legal minimum) |
| `response_max_bytes` | `65536` | larger responses are a transport error |
| `proxy` | `null` | `http(s)` proxy URL; `HTTP(S)_PROXY` from the environment is ignored |
| `timezone` | `Europe/Prague` | used for `dat_trzby` and `dat_odesl` |
| `minimum_occurred_at.*` | `2026-07-01 00:00` / `2026-11-01 00:00` | earliest sale per environment |
| `response_trust_store` | `null` | `null` = bundled I.CA certificates; or a PEM with your own CA |
| `response_trust_store_max_certificates` | `10` | maximum number of certificates in `response_trust_store` (at least 2) |
| `response_signer.*` | GFŘ identity | expected signer of confirmations; set `production.common_name` once you know it (see `eet:status`) |
| `response_clock_tolerance_seconds` | `600` | allowed difference of `dat_prij` |
| `lock_store` | `null` | cache store for the per-sale lock (default store) |
| `resubmission.connection`, `.queue`, `.backoff`, `.min_backoff`, `.max_ambiguous_attempts` | default connection, `[60, 300, 900, 3600]`, `30`, `3` | resubmission job |
| `verification.sequence_number` | `OVERENI` | `porad_cis` of `Eet::verifyConnection()` |
| `retention.raw_xml_days` | `null` | default age for `eet:prune` |

The `file` lock store does not work across several servers.

The CN of the production signing certificate of the EET service is not published yet. Until `response_signer.production.common_name` is set, every production confirmation must be signed by the GFŘ `organizationIdentifier` with a CN without "playground", and `eet:status` reports this. After the first production confirmation, take the CN from the stored `signer_subject` of that attempt (`eet:status` prints the subject and the fingerprint) and set it in the configuration.

## Usage

### Taxpayers, registration units and cash registers

```php
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Facades\Eet;

$taxpayer = Eet::createTaxpayer('CZ00000019', 'Firma s.r.o.', Environment::Playground);
$unit = Eet::createRegistrationUnit($taxpayer, 181, 'Prodejna Brno');
$cashRegister = Eet::createCashRegister($unit, 'POKLADNA-1', 'Pokladna 1');

Eet::changeEnvironment($taxpayer, Environment::Production); // applies to new sales only
```

### Cash register certificates

```php
$certificate = Eet::importCertificate($taxpayer, file_get_contents($path), $password);
```

Or from the command line (the password is asked as a hidden question, or read from the first line of standard input):

```bash
php artisan eet:certificate:import /path/to/certificate.p12 --taxpayer=CZ00000019
printf '%s\n' "$PASSWORD" | php artisan eet:certificate:import /path/to/certificate.p12 --password-stdin --no-interaction
```

PKCS#12 and PEM (certificate, optionally encrypted key and chain) are accepted. The key is stored encrypted with the Laravel encrypter (`APP_KEY`); the password is never stored. A certificate is selected at every attempt: the pinned certificate of the cash register, otherwise a valid certificate whose CN equals the taxpayer EIČ with the latest `validTo`.

The PKCS#12 files issued by CA EET use a legacy format (RC2-40, 3DES) that OpenSSL 3 reads only with the legacy provider. `Eet::supportsLegacyPkcs12()` tells whether your runtime can read them. Otherwise convert the file to PEM once:

```bash
openssl pkcs12 -legacy -in certificate.p12 -out certificate.pem
```

In code that handles user input, call the import through the action (`app(\Gunner42\LaravelEetModule\Actions\ImportCertificate::class)->handle(...)`) or the manager (`app(\Gunner42\LaravelEetModule\Eet::class)->importCertificate(...)`) rather than the facade, and set `zend.exception_ignore_args=On`: the stack frame of a facade call holds its plain arguments.

### Registering a sale

```php
use Gunner42\LaravelEetModule\Data\SaleData;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\ValueObjects\Amount;

$sale = Eet::register($cashRegister, new SaleData(
    sequenceNumber: '2027/0001',
    occurredAt: now(),
    totalAmount: Amount::fromString('3264.50'),
));

if ($sale->hasValidConfirmationCode()) {
    echo $sale->confirmation_code; // POK for the receipt
}
```

The result is never an exception: the sale is returned as `Confirmed`, `Verified`, `Rejected` or `Unconfirmed`. Unconfirmed sales are resubmitted by the queued `ResubmitSale` job with the configured backoff until the EET service confirms or rejects them. Registering the same data twice returns the existing sale.

`register()`, `reverse()` and `resubmit()` refuse to run inside an open database transaction of your application (`ActiveDatabaseTransaction`): a rollback would delete the record of a sale the EET service already accepted.

Amounts are `Amount` value objects in halere; floats are never accepted. Local warnings (sale in the future, unusual registration unit number, certificate CN differing from the EIČ) are stored in `$sale->preflightWarnings()`; warnings of the EET service in `$sale->warnings()`.

### Reversals

```php
use Gunner42\LaravelEetModule\Data\ReversalData;

$reversal = Eet::reverse($sale, new ReversalData($cashRegister, '2027/0002', now(), Amount::fromString('3264.50')));
```

### Rejected sales

A sale rejected with code 4 or after repeated ambiguous errors can be reopened with `Eet::resubmit($sale)` or `php artisan eet:resubmit --sale=ID`. Codes 2, 3, 6 and 7 need `force: true` / `--force`. Do not automate `--force` in a loop: it bypasses the limit of ambiguous errors.

### Verifying the connection

```php
$result = Eet::verifyConnection($cashRegister); // nothing is stored
```

```bash
php artisan eet:verify CZ00000019 181 POKLADNA-1
```

### Events

`SaleConfirmed`, `SaleVerified`, `SaleRejected`, `SaleUnconfirmed`, `SaleOverdue` (more than 48 h unconfirmed in production), `SaleReopened` and `SalePreflightWarned` in `Gunner42\LaravelEetModule\Events`, dispatched after the database write is committed.

### Artisan commands

| Command | Purpose |
|---|---|
| `eet:certificate:import {path} {--taxpayer=} {--password-stdin}` | import a certificate |
| `eet:verify {eic} {registration-unit} {cash-register}` | verification message |
| `eet:resubmit {--sale=*} {--force}` | resubmit now, reopen given sales |
| `eet:status` | monitoring; exits with 1 when something needs attention |
| `eet:prune {--days=}` | delete the raw XML of old attempts |

The package schedules nothing itself. Schedule the safety nets in your application:

```php
Schedule::command('eet:resubmit')->everyFifteenMinutes();
Schedule::command('eet:status')->hourly();
```

## Testing your application

```php
use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Testing\FakeResponse;

Eet::fake([FakeResponse::timeout(), FakeResponse::confirmed()]);

$sale = Eet::register($cashRegister, $data); // Unconfirmed
Eet::resubmit($sale);                        // Confirmed

Eet::assertSent(fn (SaleMessage $message): bool => $message->totalAmount === '3264.50');
Eet::assertSentCount(2);
```

Under the fake nothing is sent and no certificate is needed; sales are marked `is_fake`. `Eet::fake()` and `Eet::createMessageUuidsUsing()` throw outside of tests. The factories `Sale::factory()->confirmed()`, `->unconfirmed()`, `->rejected()`, `->ambiguousRejected()`, `->playground()` and `->overdue()` create sales with their attempts.

## Security notes

- Error and warning texts from the EET service may contain HTML characters; escape them when displaying them.
- Middleware registered with `Http::globalMiddleware()` sees and can change the requests to the EET service.
- The data is kept permanently; the EIČ of a natural person may contain the birth number (GDPR). The package does not delete personal data.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to EET 2.0 Module! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [GuNNeR42](https://github.com/gunner42)
- [All Contributors](../../contributors)

## License

EET 2.0 Module is open-sourced software licensed under the [MIT license](LICENSE.md).
