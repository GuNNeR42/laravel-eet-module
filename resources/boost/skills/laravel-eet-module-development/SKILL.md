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

### 3. Register sales

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

### 4. React and monitor

- listen to `SaleConfirmed`, `SaleRejected`, `SaleUnconfirmed`, `SaleOverdue`, `SaleReopened`, `SaleVerified`, `SalePreflightWarned` (`Gunner42\LaravelEetModule\Events`)
- schedule `eet:resubmit` and `eet:status` (exit code 1 = attention needed); optionally `eet:prune --days=N`
- check the connection with `Eet::verifyConnection($cashRegister)` or `php artisan eet:verify EIC UNIT CASH-REGISTER`

### 5. Test the application

```php
use Gunner42\LaravelEetModule\Testing\FakeResponse;

Eet::fake([FakeResponse::timeout(), FakeResponse::confirmed()]);
// ... code under test ...
Eet::assertSentCount(1);
```

- `FakeResponse::confirmed()`, `::error(int|ErrorCode)`, `::timeout()`, `::transportError()`, `::raw($xml)`
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
- do not call `register()` inside `DB::transaction()`
- do not treat a POK of a test sale (`is_test`) as valid; use `hasValidConfirmationCode()`
- do not automate `--force` reopening in a loop
- do not print EET error texts without escaping them
- do not use `Eet::fake()` outside of tests (it throws)
