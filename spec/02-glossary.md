# 02 – Glosář

> Stav: **závazný** pro první iteraci (Q1–Q33 zodpovězeny). Změny jen přes
> architekta. Jeden pojem = jedno slovo ve specifikaci, v kódu
> i v testech.

| Pojem | V kódu | XML | Nepoužívat |
|---|---|---|---|
| Evidovaná tržba | `Sale` | `Trzba/Data` | receipt, transaction, payment, účtenka (jako synonymum tržby) |
| Datová zpráva evidované tržby | `SaleMessage` | `Trzba` | request, payload |
| Potvrzovací datová zpráva | `Confirmation` | `Odpoved/Potvrzeni` | acknowledgement, receipt |
| Chybová datová zpráva | `ErrorResponse` | `Odpoved/Chyba` | fault (SOAP fault je jiná věc) |
| Potvrzovací kód (POK) | `confirmationCode` | `pok` | FIK, BKP, PKP (EET 1) |
| Pokus o odeslání | `SubmissionAttempt` | – | try, retry (jako podstatné jméno) |
| První zaslání | `isFirstSubmission` | `prvni_zaslani` | – |
| Opakované zaslání | `Resubmission` | `prvni_zaslani="false"` | retry, resend |
| UUID zprávy | `messageUuid` | `uuid_zpravy` | id, guid |
| Datum a čas odeslání zprávy | `sentAt` | `dat_odesl` | – |
| Datum a čas přijetí zprávy | `receivedAt` | `dat_prij` | – |
| Datum a čas odmítnutí zprávy | `rejectedAt` | `dat_odmit` | – |
| Ostrý mód | `SendingMode::Live` | `overeni` chybí / `false` | production mode |
| Ověřovací mód | `SendingMode::Verification` | `overeni="true"` | test mode, dry run |
| Produkční prostředí | `Environment::Production` | – | live |
| Neprodukční prostředí (playground) | `Environment::Playground` | `test="true"` | sandbox, test |
| Poplatník | `Taxpayer` | – | merchant, customer, tenant |
| EIČ (evidenční identifikační číslo) | `eic` | `eic_popl` | DIČ, VAT ID |
| EIČ pověřujícího poplatníka | `delegatingEic` | `eic_poverujiciho` | principal |
| Pověření více poplatníky | `delegatedByMultipleTaxpayers` | `povereni_vice_popl` | – |
| Evidenční jednotka | `RegistrationUnit` | – | provozovna, store, branch |
| Označení evidenční jednotky | `registrationUnitId` | `id_jednotky` | – |
| Pokladní zařízení | `CashRegister` | – | POS, terminal, device |
| Označení pokladního zařízení | `cashRegisterId` | `id_pokl` | – |
| Pořadové číslo tržby | `sequenceNumber` | `porad_cis` | receipt number, invoice number |
| Datum a čas uskutečnění tržby | `occurredAt` | `dat_trzby` | createdAt, date |
| Celková částka tržby | `totalAmount` | `celk_trzba` | total, price |
| Částka určená k následnému čerpání nebo zúčtování | `amountForLaterDrawing` | `urceno_cerp_zuct` | deposit, top-up |
| Částka, která je následným čerpáním nebo zúčtováním | `drawnAmount` | `cerp_zuct` | – |
| Částka (hodnotový objekt) | `Amount` | – | float, Money (jiné knihovny) |
| Jednoznačný kód tržby | `uniqueSaleCode` | – | hash, fingerprint |
| Storno | `Reversal` | záporná `celk_trzba` | refund, cancel, void |
| Oprava | – (storno + nová tržba, vlastní API nemá) | – | edit, update, Correction |
| Stav tržby | `SaleStatus` (enum) | – | state |
| Výsledek pokusu o odeslání | `AttemptOutcome` (enum) | – | result |
| Mód odesílání | `SendingMode` (enum) | `overeni` | – |
| Prostředí | `Environment` (enum) | – | env, stage |
| Platný POK | `hasValidConfirmationCode()` | – | – |
| Lhůta 48 h překročena | `SaleOverdue` (event), `overdueNotifiedAt` | – | late, expired |
| Lokální varování (zjištěné balíčkem před odesláním) | `PreflightWarning` (enum), `preflightWarnings()` | – | warning (to je varování od EET) |
| Nesrovnalost, která evidenci zablokuje | `SaleDataProblem` (enum) | – | – |
| Uvízlá tržba | – (`Pending` po hranici R63) | – | stuck, orphan |
| Znovuotevření odmítnuté tržby | `SaleReopened` (event), `SaleNotReopenable` | – | retry, unreject |
| Důvod neúspěšného importu certifikátu | `CertificateProblem` (enum) | – | – |
| Volání uvnitř otevřené DB transakce | `ActiveDatabaseTransaction` (výjimka) | – | – |
| Pořadí vyhodnocení odpovědi | – (R71) | – | – |
| Kritická chyba | `ErrorCode` (enum) | `Chyba/@kod` | – |
| Varování (propustná chyba) | `Warning`, `WarningCode` (enum) | `Varovani/@kod_varov` | notice |
| Mezní doba odezvy | `responseTimeout` | – | timeout (bez upřesnění) |
| Pokladní certifikát | `CashRegisterCertificate` | `BinarySecurityToken` | key, cert (zkratky) |
| Obnova pokladního certifikátu | `CertificateRenewal` | – | refresh |
| Žádost o následný certifikát (CA EET) | `requestId` | `reqId` | ticket, order |
| Nástupce certifikátu (z obnovy) | `successor` | – | replacement, next |
| Předchůdce certifikátu | `predecessor` | – | parent |
| Okno obnovy | `isInRenewalWindow()`, `renewal.days_before_expiry` | – | grace period |
| Produkční certifikát (politika `…10.1.…`) | `hasProductionPolicy()` | – | live certificate |
| Stav obnovy | `RenewalStatus` (enum) | `state` (CA) | – |
| Důvod neúspěšné obnovy | `RenewalProblem` (enum) | – | – |
| Potvrzení převzetí PKCS#12 | – (`ack-download`, `acknowledged_at`) | – | confirm |
| Společné technické zařízení správce daně | `EetService` | – | server, API, gateway |
| ID globální transakce | `globalTransactionId` | HTTP `X-Global-Transaction-Id` | trace id |

## Kódy (TitleCase klíče enumů)

`ErrorCode`: `TemporaryTechnicalError` (−1), `VerificationSucceeded` (0),
`InvalidXmlEncoding` (2), `SchemaValidationFailed` (3), `InvalidSignature` (4),
`InvalidEicStructure` (6), `MessageTooLarge` (7), `ProcessingFailed` (8).

`WarningCode`: `EicMismatchesCertificate` (1), `InvalidDelegatingEicFormat` (2),
`SaleInFuture` (4), `SaleTooOld` (5), `InvalidRegistrationUnitFormat` (6),
`DelegatingEicEqualsTaxpayerEic` (7).

`RenewalStatus`, `RenewalProblem`: viz `spec/10-interface.md` kap. 4.
