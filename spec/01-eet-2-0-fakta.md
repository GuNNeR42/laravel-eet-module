# 01 – EET 2.0: závazná fakta ze zdrojů MF ČR

Tento soubor **není** feature specifikace. Shrnuje vnější kontrakt, který
balíček nemůže měnit: co předepisuje správce daně. Feature specifikace
(`spec/NN-*.md`) z něj přebírají číslovaná pravidla `R…`. Každý řádek má zdroj:

- **[R]** – Popis rozhraní v1.2, kapitola
- **[P]** – Popis položek datové zprávy v1.0, kapitola
- **[X]** – `EETXMLSchema.xsd` / `EETServiceSOAP.wsdl` 4.1
- **[PG]** / **[PROD]** – Přístupové a provozní informace playground / produkce v1.1
- **[CA]** – CA EET v 2.0, Postupy získání pokladního certifikátu + `caeetapi_jwt.yml`
- **[V]** – vzorová zpráva `CZ00000019.eet.v4.req.xml`

## 1. Komunikace

| Fakt | Zdroj |
|---|---|
| SOAP 1.1 (document/literal) přes HTTPS, HTTP/1.1, TLS ≥ 1.2, bez klientského TLS certifikátu. Klient musí ověřit TLS certifikát serveru (důvěryhodná CA, shoda jména). | R 1, 2.3, 5.1 |
| Operace `OdeslaniTrzby`, `SOAPAction: http://fs.gov.cz/eet/OdeslaniTrzby`, `Content-Type: text/xml; charset=utf-8`. | X, R 2.3.3, 3.1 |
| Request/response je synchronní. Odpověď je buď potvrzovací datová zpráva (`Potvrzeni`), nebo chybová datová zpráva (`Chyba`), plus 0–10× `Varovani`. | R 2.1, X |
| Playground: `https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4`. Produkce: `https://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4`. V URL se nesmí používat IP adresa; v produkci je třeba respektovat DNS TTL a při každém novém spojení DNS záznam znovu dotázat. | PG 2.1, PROD 2.1 |
| Hlavní verze rozhraní je v URL (`/v4`). Vedlejší verze (4.1 → 4.2) jsou kompatibilní, nová hlavní verze (v5) je nekompatibilní. Souběh hlavní verze a předchozí verze je podporován. | R 1.1, PG/PROD 2.3 |
| Maximální velikost zprávy včetně SOAP obálky je **12 kB** (jinak chyba 7). | R 2.2.3 |
| Všechny hodnoty používají pouze ASCII znaky s kódy 9, 10, 13, 32–126. | R 3.1 |
| Odpověď obsahuje HTTP hlavičku `X-Global-Transaction-Id` (≤ 64 znaků). Výrobcům se doporučuje ji logovat nebo ukládat. | R 5.4 |
| Pokud server vyhodnotí zprávu jako kybernetický útok, **neodpoví vůbec**. | R 2.2.3 |
| Servisní okno je zpravidla každý čtvrtek 20:00–6:00. Dostupnost produkce je 99,99 %. | PG/PROD 4.1 |

## 2. Datová zpráva evidované tržby (`Trzba`)

| # | Položka | XML | Povinná | Formát / maska | Zdroj |
|---|---|---|---|---|---|
| 1 | UUID zprávy | `Hlavicka/@uuid_zpravy` | ano | UUID dle RFC 9562, verze 1–5 (doporučená 4), varianta 8/9/a/b. **Pro každý pokus o odeslání nové UUID**, i při opakovaném zaslání, stornu a opravě. | R 3.3.3.1, P 1.1 |
| 2 | Datum a čas odeslání | `Hlavicka/@dat_odesl` | ano | `rrrr-mm-ddThh:mm:ss±hh:mm` nebo `Z`, bez zlomků sekund. V ČR se použije +01:00 (SEČ) nebo +02:00 (SELČ). | R 3.3.3.2, X |
| 3 | První zaslání | `Hlavicka/@prvni_zaslani` | ano | `true` při prvním pokusu (i když selhal), `false` při každém dalším pokusu o tutéž tržbu. | R 3.3.3.3, P 1.3 |
| 4 | Ověřovací mód | `Hlavicka/@overeni` | ne | `true` znamená ověřovací mód; chybějící nebo `false` znamená ostrý mód. | R 3.3.3.4 |
| 5 | EIČ poplatníka | `Data/@eic_popl` | ano | `^CZ[0-9]{8,10}$`. Má se shodovat s CN pokladního certifikátu; neshoda je jen varování 1. | R 3.3.3.5, PG 3.1.5 |
| 6 | EIČ pověřujícího poplatníka | `Data/@eic_poverujiciho` | ne | stejná maska jako EIČ; nesmí se rovnat `eic_popl` (jinak varování 7) | R 3.3.3.6, P 3 |
| 7 | Pověření více poplatníky | `Data/@povereni_vice_popl` | ne | boolean; chybějící hodnota znamená `false` | R 3.3.3.7 |
| 8 | Označení evidenční jednotky | `Data/@id_jednotky` | ano | `^[1-9][0-9]{0,8}$`. Přidělené číslo má obvykle ≥ 2 číslice a končí na 1–4 (jinak varování 6). | R 3.3.3.8, R 2.2.4 |
| 9 | Označení pokladního zařízení | `Data/@id_pokl` | ano | `^[0-9a-zA-Z.,:;/#\-_ ]{1,20}$` | R 3.3.3.9 |
| 10 | Pořadové číslo tržby | `Data/@porad_cis` | ano | `^[0-9a-zA-Z.,:;/#\-_ ]{1,25}$`; čísla tvoří logickou řadu | R 3.3.3.10, P 1.10 |
| 11 | Datum a čas uskutečnění tržby | `Data/@dat_trzby` | ano | stejný formát jako `dat_odesl` | R 3.3.3.11 |
| 12 | Celková částka tržby | `Data/@celk_trzba` | ano | `^((0\|-?[1-9]\d{0,7})\.\d\d\|-0\.(0[1-9]\|[1-9]\d))$`, \|x\| < 100 000 000 Kč, přesně 2 desetinná místa, bez vedoucích nul, žádné `-0.00` | R 3.3.3.12 |
| 13 | Částka určená k následnému čerpání nebo zúčtování | `Data/@urceno_cerp_zuct` | ne | stejná maska | R, P 1.13 |
| 14 | Částka, která je následným čerpáním nebo zúčtováním | `Data/@cerp_zuct` | ne | stejná maska | R, P 1.14 |

Platí pro všechny položky:

- Nepovinná položka, která není relevantní, se **vůbec neuvádí**. Prázdná hodnota (`=""`) je chyba. Chybějící částka ≠ 0. [R 3.3.2, R 3.3.3.12, P 1]
- Unikátní musí být: `(eic_popl, id_jednotky, id_pokl, dat_trzby)` pro pokladní zařízení a `(eic_popl, id_jednotky, id_pokl, porad_cis, dat_trzby)` pro tržbu. [R 3.3.3.9–10]
- **Jednoznačný kód tržby** je `eic_popl|id_jednotky|id_pokl|porad_cis|dat_trzby|celk_trzba` (ASCII, oddělovač `|`). Zpráva se stejným kódem je zaslání **téže** tržby. [R 4]
- Při opakovaném zaslání se mění jen `uuid_zpravy`, `dat_odesl` a `prvni_zaslani` (→ `false`); oblast `Data` zůstává beze změny. [P 6]
- Data neobsahují osobní údaje zákazníků, položky nákupu ani údaje o DPH. [P 1]

## 3. Odpovědi

| Situace | Odpověď | Zdroj |
|---|---|---|
| Ostrý mód, validní zpráva | `Potvrzeni/@pok` (39 znaků, `uuid-XX`), `Hlavicka/@dat_prij`, podepsaná odpověď | R 2.2.2, 3.4 |
| Ostrý mód, playground | POK končí na `-ff` (není platný), `test="true"` | R 3.4.2.3–4 |
| Ověřovací mód, validní zpráva | `Chyba kod="0"` („…v overovacim modu se podarilo zpracovat“), nepodepsaná odpověď, možná varování | R 2.2.2, 3.5 |
| Kritická chyba | `Chyba` s nenulovým kódem a textem (≤ 100 znaků, ASCII), `Hlavicka/@dat_odmit`, `uuid_zpravy` může chybět, odpověď není podepsaná | R 3.5 |

Chybové kódy [R 3.5.4]:

| Kód | Text | Povaha |
|---|---|---|
| −1 | Docasna technicka chyba zpracovani – odeslete prosim datovou zpravu pozdeji | dočasná |
| 0 | Datovou zpravu evidovane trzby v overovacim modu se podarilo zpracovat | úspěch ověření |
| 2 | Kodovani XML neni platne (místo ní může přijít i SOAP fault nebo žádná odpověď) | kritická |
| 3 | XML zprava nevyhovela kontrole XML schematu | kritická |
| 4 | Neplatny podpis SOAP zpravy | kritická |
| 6 | EIC poplatnika ma chybnou strukturu | kritická |
| 7 | Datova zprava je prilis velka | kritická |
| 8 | Datova zprava nebyla zpracovana kvuli technicke chybe nebo chybe dat | nejasná (viz otevřené otázky) |
| −999…−2, 1, 9…999 | rezervováno; kód 5 se používal před v4 | – |

Kódy varování (propustné chyby, POK se přesto vydá) [R 2.2.4, 3.4.4]:

| Kód | Text |
|---|---|
| 1 | EIC poplatnika v datove zprave se neshoduje s EIC v certifikatu |
| 2 | Chybny format EIC poverujiciho poplatnika |
| 4 | Datum a cas uskutecneni trzby je novejsi nez datum a cas prijeti zpravy (o více než 2 h) |
| 5 | Datum a cas uskutecneni trzby je vyrazne v minulosti (o více než 2 roky nebo před minimálním datem prostředí) |
| 6 | id_jednotky neodpovida formatem pridelenemu c. evidencni jednotky |
| 7 | EIC poverujiciho se shoduje s EIC poplatnika |
| 3, 8–999 | rezervováno |

Minimální `dat_trzby`: playground v4 **1. 7. 2026 00:00**. V produkci **1. 1. 2027 00:00**, v přechodném režimu už od **1. 11. 2026**. [R 2.2.4, PG 4.1, PROD 4.1]

## 4. Podpis (WS-Security)

| Fakt | Zdroj |
|---|---|
| Podepisuje se **právě jeden** element: `soap:Body` (s `wsu:Id`), na který odkazuje relativní reference. | R 5.2, V |
| Kanonizace Exclusive C14N (`http://www.w3.org/2001/10/xml-exc-c14n#`), digest SHA-256 (`…xmlenc#sha256`), podpis RSA-SHA256 (`…xmldsig-more#rsa-sha256`). | R 5.2 |
| Certifikát se vkládá jako `wsse:BinarySecurityToken` (X509v3, Base64Binary) a `ds:KeyInfo` na něj odkazuje přes `wsse:SecurityTokenReference`. | R 5.2, V |
| Žádné další hlavičky (Timestamp, WS-Addressing). Jinak hrozí odmítnutí jako útok. | R 5.2 |
| Vzorová zpráva používá `InclusiveNamespaces PrefixList="soapenv v4"` v SignedInfo a `"v4"` v transformaci těla. | V |
| Podpis se dělá certifikátem platným **v okamžiku odeslání**, i když při přijetí tržby platil jiný. | R 3.3 |
| Potvrzovací zprávu podepisuje správce daně certifikátem I.CA (`CN = I.CA Public CA/RSA 06/2022`). Chybová zpráva podepsaná není. | R 5.3, PG/PROD 3.3 |
| **Podpisový certifikát odpovědi (playground, ověřeno 2026-11-15 skutečnou odpovědí):** subjekt `CN=NEOSTRE - playground prostredi EET, O=Generální finanční ředitelství, organizationIdentifier=NTRCZ-72080043, C=CZ`, vydavatel `I.CA Public CA/RSA 06/2022`, platnost 8. 6. 2026 – 8. 6. 2027, `CA:FALSE`, KeyUsage digitalSignature + nonRepudiation + keyEncipherment, EKU emailProtection + clientAuth (stejné jako u veřejných certifikátů I.CA, odliší ho jen subjekt). Odpověď: Reference `#Body-…` na `wsu:Id` těla, Transform exc-c14n bez `InclusiveNamespaces`, KeyInfo → `SecurityTokenReference` → `BinarySecurityToken`; digest i podpis ověřeny. Produkční podpisový certifikát neověřen (předpoklad: stejný `organizationIdentifier` GFŘ). Vzorek: `spec/zadani/pg-vzorek/`. | pg-vzorek |
| Řetěz I.CA: `I.CA Public CA/RSA 06/2022` (sériové č. 0x05F5E527 = 100001063, platí 20. 6. 2022 – 17. 6. 2032, SHA-256 `DF:5B:AF:6D:7E:1A:7D:14:E9:91:1C:5B:8C:67:6E:C6:EB:CA:D9:35:4A:74:F4:AC:73:14:E1:33:E0:7A:94:DE`) je vydán `I.CA Root CA/RSA 05/2022` (0x05F5E102 = 100000002, platí do 3. 5. 2047, SHA-256 `D2:79:C0:1A:12:E8:DD:9A:62:30:E4:59:FA:A4:47:CE:B3:36:99:84:77:33:8C:2E:E4:13:5C:96:73:74:18:EB`). Čísla „100000002 a 100001063“ v PG/PROD 3.3 jsou tato sériová čísla. Soubory jsou v `spec/zadani/ica/`, řetěz ověřen `openssl verify`. | ica.cz/korenove-certifikaty (stáhnuto 2026-11-15) |

## 5. Pokladní certifikát

| Fakt | Zdroj |
|---|---|
| Poplatník certifikát získá v DIS+ (Správa pokladních certifikátů EET) jako **PKCS#12 (.p12)** s heslem (8 znaků A–Z, a–z, 0–9). Soubor obsahuje i certifikační cestu. | CA |
| `.p12` je v **legacy formátu (3DES-CBC)**. Na OpenSSL 3 se bez legacy provideru nemusí načíst. Doporučuje se převod do PEM nebo nového PKCS#12, případně uložení ve vaultu. | CA |
| Profil certifikátu: `CN = EIČ`, `C = CZ`, RSA 2048, platnost 366 dní, KeyUsage digitalSignature + nonRepudiation. Politika `…10.1.102.x.y` je produkční, `…10.4.102.x.y` je playground. | PROD 3.1, PG 3.1.5 |
| **Nesoulad zdrojů:** skutečné playground certifikáty z `CAEET_Playground_2026_v1.zip` mají politiku `1.2.203.19122063.10.2.102.0.1` (ne `…10.4…`) a platí do 14. 5. 2027 (ověřeno `openssl x509` 2026-11-15). Spolehlivé je jen „`…10.1.` = produkce“. | PG 3.1.5, PROD 3.1.2, zip |
| Hierarchie produkce je EETv2 NCA Root CA RSA → EETv2 NCA SubCA RSA. CRL je na `https://caeet.gov.cz/crldp`. Playground CRL nemá. | PROD 3.1, PG 3.1.4 |
| Poplatník může mít libovolný počet pokladních certifikátů. Jejich přiřazení pokladnám je na něm. | PROD 3.1 |
| Playground má sdílené testovací certifikáty pro EIČ CZ8551015704 (FO s RČ), CZ00000019 (PO) a CZ683555118 (VČP). | PG 3.1.6–8 |

## 6. Automatizovaná obnova certifikátu (CA EET API)

| Fakt | Zdroj |
|---|---|
| Base URL: test `https://ca.test.caeet.gov.cz/api`, zkušební `https://ca.zkus.caeet.gov.cz/api`, produkce `https://ca.caeet.gov.cz/api`. | CA (yml) |
| Autentizace: `Authorization: Bearer <JWT>`, JWS RS256 podepsaný **obnovovaným** certifikátem. Hlavička obsahuje `x5t#S256` = base64url(SHA-256(DER cert)), `x5c` se nepoužívá. Payload musí obsahovat `iat` a `exp`, přičemž `exp − iat ≤ 5 min`. | CA |
| Sekvence: `POST /request/renew` → opakovaně `GET /request/{reqId}/status` (dokud je stav `INPROCESS`, s pauzou podle `pollAfterSeconds` / `Retry-After`) → `POST /request/{reqId}/claim-download` (vrací PKCS#12 base64 + heslo) → `POST /request/{reqId}/ack-download`. | CA |
| Stavy žádosti: `INPROCESS`, `ISSUED`, `DELIVERING`, `FINISHED`, `REJECTED` (s `rejectedReason`). `GET /request/not-finished` vrátí neukončené žádosti. | CA (yml) |
| Obnova musí proběhnout **před** koncem platnosti certifikátu; doporučeno 2–3 týdny předem. Nevyzvednutý PKCS#12 se po 30 dnech smaže. Heslo a PKCS#12 se nesmí ukládat do cache ani logů. | CA |
| Chyby: 400, 401, 404, 409 (špatný stav), 429 (rate limit), 500, vždy s `ErrorDTO {code, message, additionalInfo}`. | CA (yml) |
| `POST /request/renew` vrací `201 {reqId}`. `claim-download` jde jen ve stavu `ISSUED`/`DELIVERING` a vrací `Pkcs12DTO {pkcs12 (base64), password ^[A-Za-z0-9]{8}$, certificate {sn (desítkově), validFrom, validTo, state, …}}`; po prvním stažení je žádost `DELIVERING` a stáhnout jde znovu až do `ack-download` (jen v `DELIVERING`, vrací `204`). `INPROCESS` nese `pollAfterSeconds ≥ 1` a `Retry-After`. `not-finished` vrací `{count, data: [reqId]}` pro EIČ obnovovaného certifikátu. | CA (yml) |
| **Nesoulad zdrojů:** OpenAPI uvádí „minimální podporovaný payload je {}“, Postupy CA vyžadují `iat` a `exp`. Balíček posílá `iat` i `exp` (Q63). Ve kterém formátu (legacy / nový PKCS#12) API vrací certifikát, zdroje neuvádějí (Q58). | CA, yml |

## 7. Povinnosti poplatníka, které ovlivňují pokladní software

| Fakt | Zdroj |
|---|---|
| První pokus o odeslání musí proběhnout **nejpozději při uskutečnění tržby**. Hromadění a dávkové odesílání z centrálního serveru je nepřípustné. | P 1.2, P 1.3 |
| **Mezní doba odezvy** se nastavuje v pokladním zařízení na **nejméně 2 s**. Po jejím překročení je třeba zprávu odeslat znovu bez odkladu po pominutí příčiny, nejpozději do **48 h**. Povinnost zaslat zprávu trvá i po 48 h. | P 6 |
| Storno nebo oprava se eviduje jako **nová tržba se zápornou částkou**, vlastním `porad_cis` a časem vrácení peněz, **bez technické vazby** na původní tržbu. Může jít z jiné evidenční jednotky nebo pokladního zařízení. | P 4 |
| Částka je skutečně přijatá částka v Kč včetně daní (po zaokrouhlení hotovosti). Při platbě více způsoby stačí jedna zpráva. Záloha a doplatek jsou samostatné tržby. | P 1.12, P 5 |
| Při evidenci v zastoupení a pověření (situace 1–5) se vyplňují `eic_popl`, `eic_poverujiciho` a `povereni_vice_popl` podle typu vztahu. Rozpoznat situaci musí obsluha nebo software. | P 3 |
| Při cloudovém řešení zodpovídá za ochranu privátního klíče poplatník. | P 7 |
| Volání ze serveru i přímo z prohlížeče (CORS) je technicky podporováno. | R 2.3.3 |

## 8. Evidenční jednotky (DIS+)

Evidenční jednotky zakládá poplatník v DIS+. Jejich číslo přiděluje správce
daně a pokladní software je jen používá. Hromadný import probíhá souborem CSV
(oddělovač `;`, ≤ 500 řádků, 16 sloupců, druhy jednotek `S M A I D P`).
[import evidenčních jednotek 3. 11. 2026] Jestli to balíček podporuje, je
otevřená otázka.
