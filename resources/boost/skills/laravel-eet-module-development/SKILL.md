---
name: laravel-eet-module-development
description: >
  Register sales with the Czech EET 2.0 service from Laravel applications using
  gunner42/laravel-eet-module: taxpayers, cash registers, certificates, sales,
  reversals, resubmission, monitoring and the test fake.
license: MIT
metadata:
  author: GuNNeR42
---

# EET 2.0 Module

Use this skill when a Laravel application registers sales with the Czech EET 2.0 service (`gunner42/laravel-eet-module`).

## Primary Goal

- register every sale through the `Eet` facade (or the Actions) and show the POK only when `hasValidConfirmationCode()` is true

## Workflow

### 1. Install and configure

- `composer require gunner42/laravel-eet-module`
- `php artisan vendor:publish --tag="laravel-eet-module-migrations"` and `php artisan migrate`
- optionally `--tag="laravel-eet-module-config"` / `--tag="laravel-eet-module-lang"`
- make sure the cache store (`lock_store`, default store) supports atomic locks (redis, database, memcached, dynamodb, file) and the queue connection (`resubmission.connection`) is a real queue (not sync, deferred, background, null); otherwise operations throw `InvalidConfiguration` with the key
- keep `default_environment = playground` until the taxpayer has a production certificate

### 2. Set up taxpayers and certificates

```php
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Facades\Eet;

$taxpayer = Eet::createTaxpayer('CZ00000019', 'Firma s.r.o.', Environment::Playground);
$unit = Eet::createRegistrationUnit($taxpayer, 181);
$cashRegister = Eet::createCashRegister($unit, 'POKLADNA-1');
Eet::importCertificate($taxpayer, $contents, $password); // PKCS#12 or PEM
```

- CLI: `php artisan eet:certificate:import path.p12 --taxpayer=CZ00000019 [--password-stdin]`
- legacy CA EET `.p12` needs OpenSSL legacy support (`Eet::supportsLegacyPkcs12()`); otherwise convert to PEM (`openssl pkcs12 -legacy -in cert.p12 -out cert.pem`)
- for user input, import through `app(\Gunner42\LaravelEetModule\Actions\ImportCertificate::class)->handle(...)` instead of the facade (the facade frame holds the password)
- `Eet::changeEnvironment($taxpayer, Environment::Production)` affects only new sales
- after the first production confirmation set `response_signer.production.common_name` from the stored `signer_subject` (`eet:status` shows it)

### 3. Renew certificates (production only)

```php
$renewal = Eet::renewCertificate($certificate); // CertificateRenewal|null (null = renewed elsewhere right now)
```

- schedule `Schedule::command('eet:certificate:renew')->daily();` — renews production certificates within `renewal.days_before_expiry` (default 21) days of expiry and continues unfinished renewals; `--certificate=ID` renews manually (also after `Failed`), `--dry-run` only lists (and reports certificates that cannot be renewed)
- the result is the renewal state (`RenewalStatus`) and `problem` (`RenewalProblem`), not an exception; `CertificateNotRenewable` only when a new renewal cannot start (playground, invalid, unusable key, already renewed)
- on success the successor (same CN, issuer DN and AKI, production policy, signature verified by the issuer from the stored chain) is stored for the same taxpayer and pinned cash registers switch to it; listen to `CertificateRenewed` / `CertificateRenewalFailed` (queueable, `SerializesModels`)
- CA EET API calls dispatch no HTTP client events; test them with `Http::fake()`
- config: `renewal.endpoint`, `renewal.allowed_hosts`, `renewal.days_before_expiry`, `renewal.max_wait_seconds`, `renewal.timeout_ms`
- import production certificates with their chain (DIS+ PKCS#12 has it; PEM: append the issuing CA); without the issuer in the stored chain the renewal fails with `CertificateMismatch`
- a legacy PKCS#12 from CA EET needs `Eet::supportsLegacyPkcs12()`; otherwise the renewal fails with `CertificateUnreadable` / `unsupported_legacy_format`

### 4. Register sales

```php
use Gunner42\LaravelEetModule\Data\SaleData;
use Gunner42\LaravelEetModule\ValueObjects\Amount;

$sale = Eet::register($cashRegister, new SaleData('2027/0001', now(), Amount::fromString('100.00')));
$pok = $sale->hasValidConfirmationCode() ? $sale->confirmation_code : null;
```

- the result is the sale in its status (`Confirmed`, `Verified`, `Rejected`, `Unconfirmed`), not an exception; unconfirmed sales are resubmitted by the queued `ResubmitSale` job
- call outside of database transactions (`ActiveDatabaseTransaction` otherwise)
- validation errors: `InvalidSaleData` (`field`, `problem`), `DuplicateSequenceNumber`
- reversal: `Eet::reverse($sale, new ReversalData($cashRegister, 'S-1', now(), Amount::fromString('100.00')))`
- reopen a rejected sale: `Eet::resubmit($sale)` (codes 2, 3, 6, 7 need `force: true`)

### 5. React and monitor

- listen to `SaleConfirmed`, `SaleRejected`, `SaleUnconfirmed`, `SaleOverdue`, `SaleReopened`, `SaleVerified`, `SalePreflightWarned` (`Gunner42\LaravelEetModule\Events`)
- schedule `eet:resubmit`, `eet:status` (exit code 1 = attention needed, incl. certificates in the renewal window without a successor and failed renewals) and `eet:certificate:renew` daily; optionally `eet:prune --days=N`
- check the connection with `Eet::verifyConnection($cashRegister)` or `php artisan eet:verify EIC UNIT CASH-REGISTER`

### 6. Test the application

```php
use Gunner42\LaravelEetModule\Testing\FakeResponse;

Eet::fake([FakeResponse::timeout(), FakeResponse::confirmed()]);
// ... code under test ...
Eet::assertSentCount(1);
```

- `FakeResponse::confirmed()`, `::error(int|ErrorCode)`, `::timeout()`, `::transportError()`, `::raw($xml)`
- certificate renewal calls the CA EET API through the Laravel HTTP client: fake it with `Http::fake()` and `Sleep::fake()`
- factories: `Sale::factory()->confirmed()`, `->unconfirmed()`, `->rejected()`, `->ambiguousRejected()`, `->playground()`, `->overdue()`

## Rules, References, and Templates

Read before executing:

- `README.md` of the package
- `config/laravel-eet-module.php`

## Examples

- a POS controller registers the sale, prints the POK when `hasValidConfirmationCode()` is true, otherwise prints the receipt with the unique sale code and lets the queue resubmit
- an admin page lists `Rejected` production sales and offers "send again" through `Eet::resubmit($sale)`

## Anti-patterns

- never pass floats for amounts; use `Amount::fromString()` or `Amount::fromHalere()`
- do not call `register()` or `renewCertificate()` inside `DB::transaction()`
- do not try to renew playground certificates or store the downloaded PKCS#12 yourself
- do not treat a POK of a test sale (`is_test`) as valid; use `hasValidConfirmationCode()`
- do not automate `--force` reopening in a loop
- do not print EET error texts without escaping them
- do not use `Eet::fake()` outside of tests (it throws)
