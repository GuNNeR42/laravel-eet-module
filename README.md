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

### Installing from the private GitHub repository

The package is not published on Packagist; Composer installs it straight from the private GitHub repository. Every machine that runs `composer install` (developers, CI, deployment) needs read access to the repository.

1. Add the repository to the host application's `composer.json`:

    ```json
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/GuNNeR42/laravel-eet-module"
        }
    ]
    ```

    Or run `composer config repositories.laravel-eet-module vcs https://github.com/GuNNeR42/laravel-eet-module`.

2. Give Composer a GitHub token with read access to the repository: a fine-grained personal access token limited to this repository with the *Contents: Read-only* permission, or a classic token with the `repo` scope.

    ```bash
    # once per machine, stored in ~/.composer/auth.json (outside the project)
    composer config --global github-oauth.github.com <token>
    ```

    In CI and deployment pipelines pass the token through the `COMPOSER_AUTH` environment variable instead, for example `COMPOSER_AUTH='{"github-oauth":{"github.com":"<token>"}}'`, stored as a secret. Never commit an `auth.json` with the token.

3. Require the package:

    ```bash
    composer require gunner42/laravel-eet-module:^1.0
    ```

    Composer resolves versions from the Git tags of the repository. To follow unreleased changes, require `dev-main` instead.

Then publish and run the migrations as shown above.

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
| `lock_store` | `null` | cache store for the per-sale and per-certificate locks (default store) |
| `resubmission.connection`, `.queue`, `.backoff`, `.min_backoff`, `.max_ambiguous_attempts` | default connection, `[60, 300, 900, 3600]`, `30`, `3` | resubmission job |
| `verification.sequence_number` | `OVERENI` | `porad_cis` of `Eet::verifyConnection()` |
| `retention.raw_xml_days` | `null` | default age for `eet:prune` |
| `renewal.endpoint` | `https://ca.caeet.gov.cz/api` | CA EET API; `https`, host from `renewal.allowed_hosts`, port 443, no IP address |
| `renewal.allowed_hosts` | `['ca.caeet.gov.cz', 'ca.zkus.caeet.gov.cz', 'ca.test.caeet.gov.cz']` | non-empty list of DNS names |
| `renewal.days_before_expiry` | `21` | renewal window in days (1–365) |
| `renewal.max_wait_seconds` | `120` | how long one run waits for the issue of a certificate (0 = ask once) |
| `renewal.timeout_ms` | `10000` | timeout of one CA EET API call (at least 1000) |

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

### Certificate renewal

A cash register certificate is valid for 366 days. The package renews **production** certificates (certificate policy `1.2.203.19122063.10.1.…`) through the CA EET API before they expire; playground certificates are never renewed.

```php
$renewal = Eet::renewCertificate($certificate); // CertificateRenewal, or null while another process renews it
```

One call takes as many steps as it can: it asks for unfinished requests, submits the request for a successor, waits for the issue (at most `renewal.max_wait_seconds`), downloads the PKCS#12, checks it (same CN, later `validTo`, serial number and validity as announced by CA EET, production policy, same issuer DN and authority key identifier, and a signature verified by the issuer certificate from the stored chain of the renewed certificate; otherwise `CertificateMismatch`), stores the successor, switches every cash register pinned to the old certificate to the successor in the same transaction and acknowledges the download. Each API call is authenticated with a short-lived RS256 token signed by the key of the renewed certificate. CA EET API calls dispatch no Laravel HTTP client events (`RequestSending`, `ResponseReceived`, `ConnectionFailed`), so loggers such as Telescope never see the token or the PKCS#12; `Http::fake()` still answers and records them. The result of the API is the state of the renewal (`RenewalStatus`: `Requesting`, `InProcess`, `Downloading`, `Acknowledging`, `Completed`, `Failed`) and its `problem` (`RenewalProblem`), never an exception. An unfinished renewal continues on the next call.

- Import the certificate **with its certificate chain** (the PKCS#12 from DIS+ contains it; for PEM append the issuing CA certificate). Without the issuer of the renewed certificate in the stored chain the successor cannot be verified and the renewal fails closed with `CertificateMismatch` (`eet:status` reports it).
- `CertificateNotRenewable` (`problem`: `NotProductionCertificate`, `CertificateNotValid`, `CertificateUnusable`, `AlreadyRenewed`) when a new renewal cannot start; `ActiveDatabaseTransaction` inside a database transaction.
- Temporary problems (`TemporaryError`, `UnknownPendingRequest`) keep the state; the next run tries again while the certificate is valid. `UnknownPendingRequest` means CA EET has an unfinished request the package does not know (e.g. from DIS+); finish it there or wait until it ends.
- Final problems end in `Failed` and dispatch `CertificateRenewalFailed`; automatic renewal of that certificate stops. Renew it again manually with `Eet::renewCertificate()` or `eet:certificate:renew --certificate=ID`; a request already issued by CA EET is taken over (the PKCS#12 can be downloaded for 30 days).
- `$certificate->successor()`, `->predecessor()`, `->latestRenewal()`, `->renewals()`, `->hasProductionPolicy()` and `->isInRenewalWindow()` describe the state. The old certificate is kept.

Schedule the command daily (`--dry-run` lists what it would process and reports certificates that cannot be renewed; `--certificate=ID` together with `--taxpayer` rejects a certificate of another taxpayer); it processes certificates in the renewal window (`renewal.days_before_expiry`, default 21 days) and continues active renewals:

```php
Schedule::command('eet:certificate:renew')->daily();
```

The downloaded PKCS#12 and its password are never stored, logged or cached. If CA EET delivers the legacy PKCS#12 format and your runtime cannot read it (`Eet::supportsLegacyPkcs12()` is `false`), the renewal fails with `CertificateUnreadable` (`problem_detail` = `unsupported_legacy_format`) without acknowledging the download; enable the OpenSSL legacy provider and renew manually within 30 days.

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

`SaleConfirmed`, `SaleVerified`, `SaleRejected`, `SaleUnconfirmed`, `SaleOverdue` (more than 48 h unconfirmed in production), `SaleReopened` and `SalePreflightWarned` in `Gunner42\LaravelEetModule\Events`, dispatched after the database write is committed. Certificate renewal dispatches `CertificateRenewed` (`certificate`, `successor`, `renewal`) and `CertificateRenewalFailed` (`certificate`, `renewal`, `problem`), also after the commit; both use `SerializesModels`, so they can be queued.

### Artisan commands

| Command | Purpose |
|---|---|
| `eet:certificate:import {path} {--taxpayer=} {--password-stdin}` | import a certificate |
| `eet:certificate:renew {--taxpayer=*} {--certificate=*} {--dry-run}` | renew certificates in the renewal window; exits with 1 after a failed renewal or a temporary problem |
| `eet:verify {eic} {registration-unit} {cash-register}` | verification message |
| `eet:resubmit {--sale=*} {--force}` | resubmit now, reopen given sales |
| `eet:status` | monitoring (sales, configuration, certificates in the renewal window without a successor, failed renewals); exits with 1 when something needs attention |
| `eet:prune {--days=}` | delete the raw XML of old attempts |

The package schedules nothing itself. Schedule the safety nets in your application:

```php
Schedule::command('eet:resubmit')->everyFifteenMinutes();
Schedule::command('eet:status')->hourly();
Schedule::command('eet:certificate:renew')->daily();
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
- Middleware registered with `Http::globalMiddleware()` does not apply to the requests to the EET service and the CA EET API; `Http::fake()` does.
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
