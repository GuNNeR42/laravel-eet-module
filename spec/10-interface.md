# 10 – Veřejné rozhraní balíčku

> Stav: návrh · Verze: 0.1 (první iterace, Q1 → A). Implementace musí tomuto
> kontraktu odpovídat **přesně**. Testy se píšou proti němu dřív, než existuje
> kód. Změna kontraktu je pro hostitelské aplikace breaking change
> (**BREAKING**).
>
> Pravidla: `R1–R18`, `R59–R60`, `R69–R70` v
> `spec/11-poplatnik-a-certifikat.md`; `R19–R58`, `R61–R68`, `R71–R72` v
> `spec/20-evidence-trzby.md`; `R90–R106` ve
> `spec/12-obnova-certifikatu.md` (issue #2, nic z toho není BREAKING).

Namespace: `Gunner42\LaravelEetModule\`. Níže se uvádí relativně.

## 0. Odstranění scaffoldu (BREAKING, před prvním vydáním)

Odstraní se: config klíč `placeholder`, příkaz
`laravel-eet-module:placeholder`, pohled `placeholder.blade.php`, route
`laravel-eet-module.placeholder`, migrace `create_laravel_eet_module_placeholder_table`,
překladový klíč `messages.placeholder` a publish tagy
`laravel-eet-module-views` a `laravel-eet-module-assets`. Balíček nemá HTTP
routy ani pohledy.

## 1. Facade `Facades\Eet` → `Eet`

| Metoda | Návrat | Výjimky | Pravidla |
|---|---|---|---|
| `createTaxpayer(string $eic, ?string $name = null, ?Enums\Environment $environment = null)` | `Models\Taxpayer` | `InvalidEic`, `DuplicateTaxpayer`, `InvalidConfiguration` | R1–R2, R59, R68 |
| `changeEnvironment(Models\Taxpayer $taxpayer, Enums\Environment $environment)` | `Models\Taxpayer` | `InvalidConfiguration` | R59, R68 |
| `createRegistrationUnit(Models\Taxpayer $taxpayer, int $number, ?string $name = null)` | `Models\RegistrationUnit` | `InvalidIdentifier`, `DuplicateRegistrationUnit` | R3–R5 |
| `createCashRegister(Models\RegistrationUnit $unit, string $identifier, ?string $name = null, ?Models\CashRegisterCertificate $certificate = null)` | `Models\CashRegister` | `InvalidIdentifier`, `DuplicateCashRegister`, `CertificateTaxpayerMismatch` | R6–R8 |
| `importCertificate(Models\Taxpayer $taxpayer, #[\SensitiveParameter] string $contents, #[\SensitiveParameter] ?string $password = null)` | `Models\CashRegisterCertificate` | `InvalidCertificate` | R9–R15, R70 |
| `supportsLegacyPkcs12()` | `bool` | – | R70 |
| `renewCertificate(Models\CashRegisterCertificate $certificate)` | `?Models\CertificateRenewal` (`null` = zámek obsazen, R104) | `CertificateNotRenewable`, `InvalidConfiguration`, `ActiveDatabaseTransaction` | R90–R104 |
| `register(Models\CashRegister $cashRegister, Data\SaleData $data)` | `Models\Sale` | `InvalidSaleData`, `DuplicateSequenceNumber`, `InvalidConfiguration`, `ActiveDatabaseTransaction` | R19–R47, R61–R68 |
| `reverse(Models\Sale $original, Data\ReversalData $data)` | `Models\Sale` | jako `register` + `InvalidAmount`, `ReversalTaxpayerMismatch`, `ReversalEnvironmentMismatch` | R48–R50 |
| `resubmit(Models\Sale $sale, bool $force = false)` | `Models\Sale` | `InvalidConfiguration`, `SaleNotReopenable`, `ActiveDatabaseTransaction` | R45, R63, R64, R72 |
| `verifyConnection(Models\CashRegister $cashRegister)` | `Data\VerificationResult` | `InvalidConfiguration` | R47 |
| `fake(array<int, Testing\FakeResponse> $responses = [])` | `Testing\EetFake` | `\LogicException` mimo testy | R57 |
| `createMessageUuidsUsing(?callable(): string $factory)` | `void` | `\LogicException` mimo testy | R29 |
| `assertSent(?callable(Data\SaleMessage): bool $callback = null)`, `assertSentCount(int $count)`, `assertNothingSent()` | `void` | `PHPUnit\Framework\AssertionFailedError` (jen po `fake()`) | R57 |

Výsledek odeslání **není výjimka**. `register()` vrací tržbu v jejím stavu
(`Confirmed`, `Verified`, `Rejected`, `Unconfirmed`).

## 2. Actions (`Actions\*`, jedna veřejná metoda `handle()`)

Facade deleguje na tyto třídy. Hostitel je smí volat přímo; signatura
`handle()` je stejná jako u facade metody.

`CreateTaxpayer`, `ChangeTaxpayerEnvironment`, `CreateRegistrationUnit`, `CreateCashRegister`,
`ImportCertificate`, `RenewCertificate`, `RegisterSale`, `ReverseSale`, `ResubmitSale` (jeden
pokus; jinou třídou je job), `VerifyConnection`, `PruneRawMessages`
(`handle(int $days): int` = počet upravených pokusů).

## 3. Data a hodnotové objekty

### `ValueObjects\Amount` (final, immutable) – R56

| Člen | Popis |
|---|---|
| `static fromHalere(int $halere): self` | `InvalidAmount` při \|x\| ≥ 10 000 000 000 |
| `static fromString(string $value): self` | maska `^-?(0\|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$`, jinak `InvalidAmount` |
| `halere(): int` | |
| `toEetString(): string` | např. `-187.20`, `0.00` |
| `negate(): self`, `isPositive(): bool`, `isNegative(): bool`, `isZero(): bool`, `equals(self $other): bool` | |

### `Data\SaleData` (readonly) – R19–R22

`__construct(string $sequenceNumber, \DateTimeInterface $occurredAt,
Amount $totalAmount, ?Amount $amountForLaterDrawing = null, ?Amount
$drawnAmount = null, ?string $delegatingEic = null, bool
$delegatedByMultipleTaxpayers = false)`

### `Data\ReversalData` (readonly) – R48–R49

`__construct(Models\CashRegister $cashRegister, string $sequenceNumber,
\DateTimeInterface $occurredAt, Amount $amount, ?Amount $drawnAmount = null)`

### `Data\Warning` (readonly) – R37

`int $code`, `?Enums\WarningCode $type`, `string $message`

### `Data\VerificationResult` (readonly) – R47

`Enums\AttemptOutcome $outcome`, `?int $errorCode`, `?string $errorMessage`,
`list<Warning> $warnings`, `?string $globalTransactionId`

### `Data\SaleMessage` (readonly) – R57

Rozparsovaná odeslaná zpráva: `string $messageUuid`, `string $sentAt`,
`bool $isFirstSubmission`, `bool $verificationMode`, `string $eic`,
`?string $delegatingEic`, `bool $delegatedByMultipleTaxpayers`,
`int $registrationUnitId`, `string $cashRegisterId`, `string $sequenceNumber`,
`string $occurredAt`, `string $totalAmount`, `?string $amountForLaterDrawing`,
`?string $drawnAmount`, `string $xml`. Částky a časy jsou v EET tvaru.

### `Testing\FakeResponse` – R57

`static confirmed(?string $confirmationCode = null, array<int, Warning>
$warnings = [], ?bool $test = null)` (`null` = podle prostředí tržby, R57), `static error(int|ErrorCode $code,
?string $message = null, array<int, Warning> $warnings = [], ?bool $test = null)`,
`static timeout()`, `static transportError()`, `static raw(string $xml, int $status = 200, array<string, string> $headers = [])` (prochází plným zpracováním včetně R35)

## 4. Enumy (`Enums\*`, string- nebo int-backed, TitleCase klíče)

Každý enum má `label(): string` (překlad, R58).

| Enum | Backing | Hodnoty |
|---|---|---|
| `SaleStatus` | string | `Pending='pending'`, `Confirmed='confirmed'`, `Verified='verified'`, `Unconfirmed='unconfirmed'`, `Rejected='rejected'` |
| `AttemptOutcome` | string | `Confirmed`, `Verified`, `Rejected`, `TemporaryError`, `AmbiguousError`, `Timeout`, `TransportError`, `InvalidResponseSignature`, `ResponseMismatch`, `CertificateUnavailable` (hodnota = snake_case klíče) |
| `SendingMode` | string | `Live='live'`, `Verification='verification'` |
| `Environment` | string | `Playground='playground'`, `Production='production'` |
| `ErrorCode` | int | `TemporaryTechnicalError=-1`, `VerificationSucceeded=0`, `InvalidXmlEncoding=2`, `SchemaValidationFailed=3`, `InvalidSignature=4`, `InvalidEicStructure=6`, `MessageTooLarge=7`, `ProcessingFailed=8` |
| `WarningCode` | int | `EicMismatchesCertificate=1`, `InvalidDelegatingEicFormat=2`, `SaleInFuture=4`, `SaleTooOld=5`, `InvalidRegistrationUnitFormat=6`, `DelegatingEicEqualsTaxpayerEic=7` |
| `CertificateProblem` | string | `Unreadable`, `MissingPrivateKey`, `KeyMismatch`, `Expired`, `UnsupportedLegacyFormat` (hodnota = snake_case) |
| `SaleDataProblem` | string | `InvalidFormat`, `DelegatingEicEqualsTaxpayerEic`, `TooOld`, `BeforeEnvironmentStart`, `SignMismatch`, `PartsExceedTotal` (hodnota = snake_case) |
| `PreflightWarning` | string | `SaleInFuture`, `UnusualRegistrationUnitNumber`, `CertificateEicMismatch` (hodnota = snake_case) |
| `RenewalStatus` | string | `Requesting`, `InProcess`, `Downloading`, `Acknowledging`, `Completed`, `Failed` (hodnota = snake_case); metody `isActive(): bool` (první čtyři) |
| `RenewalProblem` | string | předpoklady: `NotProductionCertificate`, `CertificateNotValid`, `CertificateUnusable`, `AlreadyRenewed`; dočasné: `TemporaryError`, `UnknownPendingRequest`; konečné: `Rejected`, `AlreadyFinished`, `RequestFailed`, `CertificateUnreadable`, `CertificateMismatch` (+ `CertificateNotValid`, `CertificateUnusable`) (hodnota = snake_case); metoda `isTemporary(): bool` |

## 5. Výjimky (`Exceptions\*`)

Všechny dědí z `Exceptions\EetException` (`\RuntimeException`). Zprávy
nikdy neobsahují privátní klíč ani heslo (R15).

| Výjimka | Veřejné vlastnosti | Pravidla |
|---|---|---|
| `InvalidEic` | `string $value` | R1 |
| `InvalidIdentifier` | `string $field` (`registrationUnitNumber` \| `cashRegisterId`) | R3, R6 |
| `InvalidSaleData` | `string $field`, `Enums\SaleDataProblem $problem` | R20, R21 |
| `InvalidAmount` | – | R49, R56 |
| `DuplicateTaxpayer`, `DuplicateRegistrationUnit`, `DuplicateCashRegister` | – | R2, R4, R7 |
| `DuplicateSequenceNumber` | – | R26, R27 |
| `ActiveDatabaseTransaction` | – | R72 |
| `InvalidCertificate` | `Enums\CertificateProblem $problem` | R9, R10 |
| `CertificateTaxpayerMismatch` | – | R8 |
| `CertificateNotRenewable` | `Enums\RenewalProblem $problem` | R90 |
| `ReversalTaxpayerMismatch` | – | R49 |
| `ReversalEnvironmentMismatch` | – | R49 |
| `SaleNotReopenable` | `?int $errorCode` | R64 |
| `InvalidConfiguration` | `string $key` | R32, R35, R43, R66, R68 |

## 6. Modely (`Models\*`) a tabulky

Migrace jsou publikovatelné (tag `laravel-eet-module-migrations`). Všechny
tabulky mají `id` (bigint) a `created_at`/`updated_at`.

### `Taxpayer` → `eet_taxpayers`

| Sloupec | Typ | Poznámka |
|---|---|---|
| `eic` | string(12), unique | |
| `name` | string null | |
| `environment` | string | cast `Environment`, R59 |

Relace: `registrationUnits()`, `certificates()`, `sales()`.

### `RegistrationUnit` → `eet_registration_units`

`taxpayer_id` FK, `number` unsigned int, `name` string null;
unique `(taxpayer_id, number)`. Relace: `taxpayer()`, `cashRegisters()`. Metoda
`hasDisFormat(): bool` (R5).

### `CashRegister` → `eet_cash_registers`

`registration_unit_id` FK, `identifier` string(20), `name` string null,
`certificate_id` FK null → `eet_cash_register_certificates`;
unique `(registration_unit_id, identifier)`.
Relace: `registrationUnit()`, `certificate()`, `sales()`. Accessor `taxpayer`.

### `CashRegisterCertificate` → `eet_cash_register_certificates`

| Sloupec | Typ | Poznámka |
|---|---|---|
| `taxpayer_id` | FK | |
| `serial_number` | string | |
| `subject_eic` | string(64) | CN; nemusí být EIČ (R14), 64 = max. délka CN podle X.509 |
| `valid_from`, `valid_to` | datetime | cast `immutable_datetime` |
| `fingerprint_sha256` | string(64) | unique `(taxpayer_id, fingerprint_sha256)` |
| `certificate` | text | PEM |
| `chain` | text null | PEM, certifikační cesta |
| `private_key` | text | cast `encrypted`, `$hidden` |

Metody `isValidAt(\DateTimeInterface $moment): bool` a `isUsable(): bool` (klíč jde dešifrovat, R69).
Navíc (R90–R96): `hasProductionPolicy(): bool` (OID `1.2.203.19122063.10.1.…`), `isInRenewalWindow(): bool` (podle `now()` a `renewal.days_before_expiry`), relace `renewals()` (HasMany `CertificateRenewal`), `successor(): ?CashRegisterCertificate` (nástupce z obnovy `Acknowledging`/`Completed`), `predecessor(): ?CashRegisterCertificate`, `latestRenewal(): ?CertificateRenewal` (nejvyšší `id`). Tabulka se nemění.

### `CertificateRenewal` → `eet_certificate_renewals` (R92–R102)

| Sloupec | Typ | Poznámka |
|---|---|---|
| `certificate_id` | FK → `eet_cash_register_certificates` | obnovovaný certifikát |
| `successor_id` | FK null → `eet_cash_register_certificates` | nástupce, R96 |
| `request_id` | uuid null, index (ne unique, R93) | `reqId` CA; null v `Requesting` |
| `status` | string | cast `RenewalStatus` |
| `problem` | string null | cast `RenewalProblem`, poslední problém |
| `problem_detail` | string(255) null | R101 |
| `acknowledged_at`, `completed_at`, `failed_at` | datetime null | cast `immutable_datetime` |

Relace `certificate()`, `successor()`. Nejvýš jedna aktivní obnova na certifikát (R92).

### `Sale` → `eet_sales`

| Sloupec | Typ | Poznámka |
|---|---|---|
| `taxpayer_id`, `cash_register_id` | FK | |
| `reversed_sale_id` | FK null → `eet_sales` | R50 |
| `eic` | string(12) | snímek |
| `delegating_eic` | string(12) null | |
| `delegated_by_multiple_taxpayers` | bool | |
| `registration_unit_number` | unsigned int | snímek |
| `cash_register_identifier` | string(20) | snímek |
| `sequence_number` | string(25) | |
| `occurred_at` | string(25) | EET formát; accessor `occurredAt(): CarbonImmutable` |
| `total_amount`, `amount_for_later_drawing`, `drawn_amount` | bigint (haléře), poslední dva null | cast `Amount` |
| `unique_sale_code` | string | R25; unique `(environment, unique_sale_code)` (R26) |
| `environment` | string | cast `Environment` |
| `mode` | string | cast `SendingMode` |
| `status` | string | cast `SaleStatus` |
| `confirmation_code` | string(39) null | POK |
| `is_test` | bool | R36 |
| `preflight_warnings` | json | seznam `PreflightWarning`, R62 |
| `confirmed_at` | datetime null | `dat_prij` |
| `overdue_notified_at` | datetime null | R44 |
| `rejecting_attempt_id` | FK null → `eet_submission_attempts` | R64 |
| `reopened_at` | datetime null | R41, R64 |
| `reopened_after_attempt_id` | FK null → `eet_submission_attempts` | R40, R41, R64 |
| `is_fake` | bool | R57, Q39 |

Unique `(environment, eic, registration_unit_number, cash_register_identifier,
sequence_number, occurred_at)` (R27).
Metody: `hasValidConfirmationCode(): bool`, `preflightWarnings(): list<PreflightWarning>`, `warnings(): Collection<int,
Warning>`, `isOverdue(): bool` (`true`, právě když je tržba v ostrém módu, v produkci, `Unconfirmed` nebo uvízlá podle R63 a `occurredAt` + 48 h < `now()`). Relace: `taxpayer()`, `cashRegister()`,
`attempts()`, `reversedSale()`, `reversals()`.

### `SubmissionAttempt` → `eet_submission_attempts`

`sale_id` FK, `message_uuid` uuid unique, `is_first_submission` bool,
`certificate_id` FK null, `certificate_fingerprint` string(64) null, `sent_at`
datetime, `outcome` string (cast `AttemptOutcome`), `error_code` int null,
`error_message` string null, `warnings` json, `received_at` datetime null,
`rejected_at` datetime null, `confirmation_code` string(39) null, `is_test`
bool, `global_transaction_id` string(64) null, `http_status` smallint null,
`duration_ms` unsigned int, `request_xml` longtext null, `response_xml`
longtext null, `signer_subject` string null, `signer_fingerprint` string(64) null
(R35, Q38), `is_fake` bool (R57, Q39). Relace: `sale()`, `certificate()`. Metody: `warnings(): list<Warning>`,
`wasSent(): bool` (`false` jen u `CertificateUnavailable`, R29).

### Factories (pro testy hostitele)

`Taxpayer::factory()`, `RegistrationUnit::factory()`, `CashRegister::factory()`
a `Sale::factory()` bez stavu vytvoří tržbu `Pending` bez pokusu (prostředí
`production`, mód `live`). Stavy (každý vytvoří i odpovídající pokus; výchozí
prostředí je `production` a mód `live`, pokud stav neříká jinak):
- `confirmed()`: `Confirmed`, produkce, ostrý mód, POK bez `-ff`, pokus `Confirmed`;
- `unconfirmed()`: `Unconfirmed`, jeden pokus `Timeout`;
- `rejected(int $errorCode = 3)`: `Rejected`, odmítající pokus s výsledkem `Rejected` a tímto kódem (2, 3, 4, 6 nebo 7);
- `ambiguousRejected(int $errorCode = 8)`: `Rejected` po limitu R41, odmítající pokus s výsledkem `AmbiguousError` a tímto kódem (0, 8 nebo neznámý);
- `playground()`: `Confirmed` v prostředí `playground` u poplatníka s prostředím `playground`, `isTest = true`, POK `…-ff`, pokus `Confirmed` s `test = true`;
- `overdue()`: `unconfirmed()` s `occurredAt` = `now()` − 49 h, produkce, ostrý mód.

Factory certifikátu se nedodává; testy používají certifikáty z playgroundu
nebo vlastní testovací CA.

## 7. Eventy (`Events\*`)

Eventy tržeb mají `public readonly Models\Sale $sale` a vyvolávají se po commitu
(R39).

| Event | Navíc |
|---|---|
| `SaleConfirmed` | – |
| `SaleVerified` | – |
| `SaleRejected` | `?int $errorCode` |
| `SaleUnconfirmed` | `Enums\AttemptOutcome $outcome` |
| `SaleOverdue` | – |
| `SaleReopened` | – |
| `SalePreflightWarned` | `list<Enums\PreflightWarning> $warnings` (jen nová) |

Eventy obnovy certifikátu (po commitu, bez `$sale`, `SerializesModels`):

| Event | Vlastnosti | Pravidla |
|---|---|---|
| `CertificateRenewed` | `Models\CashRegisterCertificate $certificate`, `Models\CashRegisterCertificate $successor`, `Models\CertificateRenewal $renewal` | R96 |
| `CertificateRenewalFailed` | `Models\CashRegisterCertificate $certificate`, `Models\CertificateRenewal $renewal`, `Enums\RenewalProblem $problem` | R102 |

## 8. Job

Cache zámek pokusu: klíč `laravel-eet-module:sale:{id}`, TTL
`response_timeout_ms` + 30 s (R43). Zámek obnovy certifikátu:
`laravel-eet-module:certificate:{id}`, TTL `renewal.max_wait_seconds` + 5 × ⌈`renewal.timeout_ms` / 1000⌉ + 60 s (R104).

`Jobs\ResubmitSale` (`ShouldQueue`), konstruktor `(int $saleId)`. Spojení,
fronta a zpoždění podle configu (R40–R43).

## 9. Artisan příkazy

| Signatura | Exit kódy | Pravidla |
|---|---|---|
| `eet:certificate:import {path} {--taxpayer=} {--password-stdin}` | 0 OK, 1 chyba | R18 |
| `eet:certificate:renew {--taxpayer=*} {--certificate=*} {--dry-run}` | 0 žádná obnova `Failed` ani s dočasným problémem, žádný předpoklad / ID neselhal a žádná neočekávaná výjimka (obsazený zámek a `--dry-run` = 0); 1 jinak | R105 |
| `eet:verify {eic} {registration-unit} {cash-register}` | 0 `Verified`, 1 jinak | R55 |
| `eet:resubmit {--sale=*} {--force}` | 0 nic nezůstalo `Unconfirmed`, žádné znovuotevření neselhalo a žádné ID nechybělo; 1 jinak | R53, R63, R64 |
| `eet:status` | 0 nic z výčtu R54 a R106 nenastalo (po lhůtě, produkční `Rejected`, varování k módu, podpisovému certifikátu, trust store a fake tržbám, certifikát v okně obnovy bez nástupce, obnova `Failed` nebo `UnknownPendingRequest`); 1 jinak | R54, R106 |
| `eet:prune {--days=}` | 0 OK, 1 `InvalidConfiguration` (R68) | R52 |

Balíček sám nic neplánuje. README doporučí hostiteli naplánovat
`eet:resubmit` a `eet:status` jako pojistku a `eet:certificate:renew` denně.

## 10. Konfigurace `config/laravel-eet-module.php` (klíč `laravel-eet-module`)

Hodnota mimo sloupec „Platné hodnoty“ vyhodí `InvalidConfiguration`
s názvem klíče (R68).

| Klíč | Výchozí | Platné hodnoty | Pravidla |
|---|---|---|---|
| `default_environment` | `'playground'` | `'playground'`, `'production'` | R59 |
| `mode` | `'live'` | `'live'`, `'verification'` | R23, R46 |
| `endpoints.playground` | `'https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4'` | `https`, hostitel z `endpoints.allowed_hosts.playground`, port prázdný nebo 443, bez userinfo; v testech i `http://127.0.0.1:<port>` (R66) | R32, R66 |
| `endpoints.production` | `'https://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4'` | `https`, hostitel z `endpoints.allowed_hosts.production`, port prázdný nebo 443, bez userinfo; v testech i `http://127.0.0.1:<port>` (R66) | R32, R66 |
| `endpoints.allowed_hosts.playground` | `['pg.trzbyeet.gov.cz']` | neprázdný seznam DNS jmen (ne IP) | R66, Q36 |
| `endpoints.allowed_hosts.production` | `['trzbyeet.gov.cz']` | neprázdný seznam DNS jmen (ne IP) | R66, Q36 |
| `response_max_bytes` | `65536` | int ≥ 1024 | R66, Q36 |
| `proxy` | `null` | `null` nebo URL proxy (`http`/`https`) | R66 |
| `response_timeout_ms` | `4000` | int ≥ 2000 (zákonné minimum) | R32 |
| `timezone` | `'Europe/Prague'` | platný identifikátor IANA | R24 |
| `minimum_occurred_at.playground` | `'2026-07-01 00:00'` | řetězec `Y-m-d H:i` | R61 |
| `minimum_occurred_at.production` | `'2026-11-01 00:00'` | řetězec `Y-m-d H:i` | R61 |
| `response_trust_store` | `null` = přibalené CA I.CA z `resources/certs/` | `null` nebo cesta k čitelnému PEM podle R35 (1–10 certifikátů s `CA:TRUE`, aspoň jedna self-signed kotva) | R35 |
| `response_trust_store_max_certificates` | `10` | int ≥ 2 | R35, Q36 |
| `response_signer.organization_identifier` | `'NTRCZ-72080043'` | `^NTRCZ-\d{8}$` | R35 |
| `response_signer.playground.common_name` | `'NEOSTRE - playground prostredi EET'` | neprázdný řetězec | R35 |
| `response_signer.production.common_name` | `null` | `null` nebo neprázdný řetězec | R35 |
| `response_clock_tolerance_seconds` | `600` | int ≥ 1 | R35, Q36 |
| `lock_store` | `null` (výchozí cache store) | `null` nebo název store z `cache.stores`; driver `redis`, `database`, `memcached`, `dynamodb`, `file` (u `memo`/`failover` podkladový); v testech i `array` | R43, Q35 |
| `resubmission.connection` | `null` (výchozí spojení) | `null` nebo název z `queue.connections`; driver není `sync`, `deferred`, `background`, `null` (v testech `sync` povolen) | R40 |
| `resubmission.queue` | `null` (výchozí fronta) | `null` nebo neprázdný řetězec | R40 |
| `resubmission.backoff` | `[60, 300, 900, 3600]` | neprázdný seznam intů ≥ `resubmission.min_backoff` | R40 |
| `resubmission.min_backoff` | `30` | int ≥ 1 | R40, Q35, Q36 |
| `verification.sequence_number` | `'OVERENI'` | maska `porad_cis` (R20) | R47, Q36 |
| `resubmission.max_ambiguous_attempts` | `3` | int ≥ 1 | R41 |
| `retention.raw_xml_days` | `null` | `null` nebo int ≥ 1 | R52 |
| `renewal.endpoint` | `'https://ca.caeet.gov.cz/api'` | `https`, hostitel z `renewal.allowed_hosts`, port prázdný nebo 443, bez userinfo; v testech i `http://127.0.0.1:<port>` | R100 |
| `renewal.allowed_hosts` | `['ca.caeet.gov.cz', 'ca.zkus.caeet.gov.cz', 'ca.test.caeet.gov.cz']` | neprázdný seznam DNS jmen (ne IP) | R100 |
| `renewal.days_before_expiry` | `21` | int 1–365 | R105, R106, Q54 |
| `renewal.max_wait_seconds` | `120` | int ≥ 0 | R94, Q57 |
| `renewal.timeout_ms` | `10000` | int ≥ 1000 | R100, Q66 |

## 11. Publish tagy

| Tag | Publikuje |
|---|---|
| `laravel-eet-module` | vše níže |
| `laravel-eet-module-config` | `config/laravel-eet-module.php` |
| `laravel-eet-module-migrations` | migrace `eet_*` tabulek |
| `laravel-eet-module-lang` | `lang/vendor/laravel-eet-module/{cs,en}` |

Přibalené certifikáty CA I.CA (`resources/certs/*.pem`) se nepublikují; přepisují se configem `response_trust_store`.

## 12. Překlady (namespace `laravel-eet-module`, soubor `messages`, locale `cs` a `en`)

| Klíč | Pro |
|---|---|
| `laravel-eet-module::messages.error_codes.<KlíčEnumu>` | `ErrorCode` |
| `laravel-eet-module::messages.warning_codes.<KlíčEnumu>` | `WarningCode` |
| `laravel-eet-module::messages.sale_statuses.<KlíčEnumu>` | `SaleStatus` |
| `laravel-eet-module::messages.attempt_outcomes.<KlíčEnumu>` | `AttemptOutcome` |
| `laravel-eet-module::messages.certificate_problems.<KlíčEnumu>` | `CertificateProblem` |
| `laravel-eet-module::messages.sale_data_problems.<KlíčEnumu>` | `SaleDataProblem` |
| `laravel-eet-module::messages.preflight_warnings.<KlíčEnumu>` | `PreflightWarning` |
| `laravel-eet-module::messages.renewal_statuses.<KlíčEnumu>` | `RenewalStatus` |
| `laravel-eet-module::messages.renewal_problems.<KlíčEnumu>` | `RenewalProblem` |

Příklad: `laravel-eet-module::messages.error_codes.TemporaryTechnicalError`.

## 13. Závislosti

Runtime (`composer.json`, schválil člověk, N1): `php ^8.3`, `ext-dom`,
`ext-openssl`, `guzzlehttp/guzzle ^7.15.2||^8.0.1` a `illuminate/{cache,console,
database,encryption,events,http,queue,support,translation}` `^12.71||^13.40`
(nejnižší verze bez bezpečnostního advisory, viz `spec/05` R74).
UUID se generuje bez další závislosti (R29). Konfigurační klíče
`certificate.*` neexistují (R17).
