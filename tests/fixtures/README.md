# Testovací fixtures

Soubory jsou vygenerované jednou a commitnuté. Znovu je vytvoří
`tests/fixtures/generate.sh` (OpenSSL ≥ 3.4). Každé spuštění vytvoří nové
klíče, takže se změní všechny vygenerované soubory najednou. Všechny klíče
jsou jen testovací a nikde jinde se nepoužívají.

Testy s těmito certifikáty **vždy mrazí čas** (`$this->travelTo()`), protože
certifikáty mají pevnou platnost (spec 20, akceptační kritéria).

## `playground/` – pokladní certifikáty CA EET (playground)

| Soubor | Obsah |
|---|---|
| `CA_EET-Playground-*.p12`, `ca_eet-*-playground.crt`, `password_pokladni_cert_playground.txt` | originál ze `spec/zadani/mfcr/CAEET_Playground_2026_v1.zip`; legacy PKCS#12 (certifikáty RC2-40, klíč 3DES), heslo `aaaa1111`; platnost 13. 5. 2026 – 14. 5. 2027 |
| `<EIČ>.pem` | certifikát + nešifrovaný klíč + certifikační cesta (převod `openssl pkcs12 -legacy … -nodes`) |
| `<EIČ>.encrypted.pem` | certifikát + klíč šifrovaný heslem `Heslo123` (AES-256) + certifikační cesta |
| `<EIČ>.cert.pem`, `<EIČ>.key.pem`, `<EIČ>.key.encrypted.pem` | jednotlivé části |
| `<EIČ>.modern.p12` | nový PKCS#12 (AES-256, MAC SHA-256), heslo `Abcd1234`; nepotřebuje legacy provider |
| `CZ00000019.foreign-key.pem` | certifikát CZ00000019 s klíčem CZ683555118 (`KeyMismatch`) |

EIČ: `CZ00000019`, `CZ683555118`, `CZ8551015704`.

## `cash-register/` – vlastní pokladní certifikáty

Vydané vlastní testovací CA (`ca.pem`), RSA 2048, KeyUsage digitalSignature +
nonRepudiation. Slouží pro data, která playground certifikáty nepokryjí. Každý
soubor obsahuje certifikát a nešifrovaný klíč.

| Soubor | CN | Platnost (UTC) |
|---|---|---|
| `CZ00000019-until-2027-12-31.pem` | CZ00000019 | 2026-01-01 – 2027-12-31 23:59:59 |
| `CZ683555118-until-2027-12-31.pem` | CZ683555118 | 2026-01-01 – 2027-12-31 23:59:59 |
| `CZ00000019-until-2027-10-01-a.pem`, `-b.pem` | CZ00000019 | 2026-01-01 – 2027-10-01 (stejné `validTo`) |
| `CZ00000019-until-2027-03-01-noon.pem` | CZ00000019 | 2026-01-01 – 2027-03-01 12:00:00 |
| `CZ00000019-expired-2025.pem` | CZ00000019 | 2025-01-01 – 2025-12-31 23:59:59 |
| `CZ00000019-from-2027-01-01.pem` | CZ00000019 | 2027-01-01 – 2027-12-31 23:59:59 |
| `TEST-cn.pem` | TEST | 2026-01-01 – 2027-12-31 23:59:59 |

## `response-ca/` – vlastní CA pro podepsané odpovědi EET

Používá se přes config `response_trust_store`, aby šly testovat platně
podepsané, ale věcně vadné odpovědi (R33, R35, R65, R71). Odpovědi podepisuje
testovací helper `tests/Support/EetResponse.php` stejně jako skutečný
playground (exc-c14n, SHA-256, RSA-SHA256, `BinarySecurityToken`).

| Soubor | Popis |
|---|---|
| `root.pem/.key` | self-signed kotva `CN=Test Root CA`, platí 2025–2045 |
| `intermediate.pem/.key` | `CN=Test Public CA`, `CA:TRUE`, keyCertSign, vydal root |
| `intermediate-no-keycertsign.pem/.key` | `CA:TRUE` bez keyCertSign, vydal root |
| `rogue-intermediate.pem/.key` | stejný subjekt jako `intermediate`, jiný klíč, self-signed; není v žádném trust store |
| `signer-playground` | `CN=NEOSTRE - playground prostredi EET`, `organizationIdentifier=NTRCZ-72080043`, 2026–2027 |
| `signer-production` | `CN=EET produkcni prostredi` (bez „playground“), GFŘ orgId |
| `signer-other-cn` | `CN=Jiny podepisujici certifikat`, GFŘ orgId |
| `signer-wrong-org` | playground CN, `organizationIdentifier=NTRCZ-12345678` |
| `signer-two-orgids` | `organizationIdentifier` dvakrát |
| `signer-two-cns` | CN dvakrát |
| `signer-ec` | klíč EC P-256 |
| `signer-rsa1024` | RSA 1024 |
| `signer-2025` | platí jen 2025-01-01 – 2025-12-31 |
| `signer-rogue` | vydal `rogue-intermediate` |
| `signer-under-no-keycertsign` | vydal `intermediate-no-keycertsign` |

Všichni podepisující mají `CA:FALSE`, KeyUsage digitalSignature +
nonRepudiation + keyEncipherment a EKU emailProtection + clientAuth (jako
skutečný certifikát GFŘ).

Trust store: `trust-store.pem` (root + intermediate), `trust-store-no-keycertsign.pem`,
`trust-store-with-leaf.pem` (obsahuje certifikát bez `CA:TRUE`),
`trust-store-without-anchor.pem`, `trust-store-without-intermediate.pem`,
`trust-store-3.pem` (3 certifikáty), `trust-store-11.pem` (11 certifikátů).

## `pg-vzorek/` a `xsd/`

Kopie `spec/zadani/pg-vzorek/` (skutečná podepsaná odpověď playgroundu:
UUID `60b96f2c-e521-47e1-92aa-cb60f3034f6e`, `dat_prij`
2026-11-15T17:23:53+01:00, podpisový certifikát platí do 8. 6. 2027) a
`spec/zadani/mfcr/EETXMLSchema.xsd` (validace odeslané zprávy, R30).
Podpis vzorku i vzorového požadavku ověřuje i testovací helper
`tests/Support/SoapMessage.php::verifySignature()`.

## `renewal/` – obnova pokladního certifikátu (spec/12)

Vygeneruje je samostatně `tests/fixtures/generate-renewal.sh` (ostatní
fixtures nemění). Vlastní testovací CA (`ca.pem`) zastupuje CA EET. Testy obnovy
mrazí čas na 2026-11-19 00:00 UTC (`CaEetApi::NOW`).

| Soubor | Obsah |
|---|---|
| `CZ00000019-until-2026-12-10.pem` | obnovovaný certifikát (politika `1.2.203.19122063.10.1.102.0.1`), 2025-12-10 – 2026-12-10 00:00 UTC, přesně 21 dní po `NOW` |
| `CZ00000019-until-2026-12-11.pem` | totéž, končí o den později (22 dní, mimo okno) |
| `CZ00000019-until-2026-12-05.pem`, `CZ00000019-until-2027-06-30.pem` | další produkční certifikáty (v okně / mimo okno) |
| `CZ683555118-until-2026-12-10.pem` | produkční certifikát jiného poplatníka |
| `CZ00000019-policy-10-1-other.pem` / `-policy-10-10.pem` | politika `…10.1.5` (produkční) / `…10.10.1` (není produkční) |
| `successor.p12` | nástupce (nový PKCS#12, heslo `Renew123`), sériové číslo `24961511666821687692`, 2026-11-18 – 2027-11-19 |
| `successor.legacy.p12` | týž nástupce jako legacy PKCS#12 |
| `successor-without-key.p12` | týž certifikát bez klíče |
| `successor-other-cn.p12` | CN `CZ683555118` |
| `successor-same-valid-to.p12` | `validTo` = `validTo` obnovovaného certifikátu |
| `successor-CZ683555118.p12` | nástupce `CZ683555118-until-2026-12-10.pem` |
| `successor-rogue-ca.p12` | stejný DN vydavatele, jiný klíč i AKI (podvržená CA) |
| `successor-rogue-same-aki.p12` | stejný DN vydavatele i AKI, podpis jiným klíčem |
| `successor-other-issuer.p12` | jiný DN vydavatele |
| `successor-no-policy.p12`, `successor-playground-policy.p12` | vydal `ca.pem`, bez politiky / s politikou playground |
| `successor-self-signed.p12` | self-signed, CN `CZ00000019`, produkční politika |

Všechny listy mají `subjectKeyIdentifier` a `authorityKeyIdentifier`;
nástupci kromě uvedených výjimek vydala `ca.pem` a mají stejný AKI jako
obnovované certifikáty.

PEM soubory obsahují certifikát, nešifrovaný klíč a `ca.pem`.
