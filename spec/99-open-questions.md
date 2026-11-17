# 99 – Otevřené otázky

Každá otázka čeká na **jednovětou odpověď od člověka** (doplňte za
`Odpověď:`). Dokud nejsou všechny zodpovězené, architekt nepíše feature
specifikaci ani `spec/10-interface.md`. U otázek jsou nabídnuté možnosti a
doporučení architekta (★). Odpovědět můžete i jinak.

Fakta, na která se otázky odkazují, jsou v `spec/01-eet-2-0-fakta.md`.

---

## Rozsah a architektura

**Q1 – Rozsah první verze balíčku.** Co má balíček umět?
- a) jen evidence tržeb: sestavení, podpis a odeslání datové zprávy, zpracování odpovědi, opakované zaslání ★
- b) jako a) + automatizovaná obnova pokladního certifikátu přes CA EET API (JWT)
- c) jako b) + generátor CSV pro import evidenčních jednotek do DIS+

Odpověď: první iterace A, zbytek zapiš do gh jako todo

**Q2 – Jeden, nebo více poplatníků v jedné instalaci?** Fakturační systém
typu SaaS obvykle eviduje tržby za více poplatníků (víc EIČ a certifikátů).
- a) jeden poplatník a jeden certifikát z configu
- b) víc poplatníků; certifikáty a údaje poplatníka (EIČ, evidenční jednotky, pokladní zařízení) spravuje balíček v DB ★
- c) víc poplatníků; balíček definuje contract (např. `CertificateResolver`) a úložiště implementuje hostitelská aplikace

Odpověď: B

**Q3 – Uložení pokladního certifikátu.** `.p12` od CA EET je v legacy formátu
(3DES), který se na OpenSSL 3 bez legacy provideru nemusí načíst.
- a) balíček při importu (Artisan příkaz / Action) `.p12` jednorázově převede a uloží šifrovaně přes Laravel `encrypt()` v DB; heslo k `.p12` se neukládá ★
- b) cesta k `.p12` + heslo v configu; načítá se při každém podpisu (vyžaduje legacy provider na serveru)
- c) obojí: config pro jednoduché instalace, DB pro více poplatníků

Odpověď: C

**Q4 – Persistence.** Má balíček vlastní tabulky a migrace pro tržby a pokusy
o odeslání (UUID, POK, chyby, varování, `X-Global-Transaction-Id`, čas)?
- a) ano: tržba + pokusy o odeslání, publikovatelné migrace ★ (nutné pro opakované zaslání do 48 h)
- b) ne, balíček je bezstavový a vrací výsledek; ukládání a opakování řeší hostitel

Odpověď: A

## Odesílání a opakování

**Q5 – Režim odeslání a opakované zaslání.** Doporučený tok: tržba se odešle
synchronně s mezní dobou odezvy. Když se nepotvrdí (timeout, výpadek, kód −1),
vrátí se výsledek „neodesláno“ a balíček naplánuje opakované zaslání přes frontu
(Laravel queue job) s backoffem. Platí to? A jaká je výchozí mezní doba odezvy
(zákonné minimum je 2 s; konfigurovatelná)?
- a) ano, výchozí mezní doba odezvy 2 s, opakování přes queue ★
- b) ano, ale opakování spouští jen plánovaný Artisan příkaz (scheduler), ne queue
- c) jiná výchozí hodnota mezní doby odezvy: ___ s

Odpověď: A, ale mezní doba odezvy 4s

**Q6 – Co když se tržbu nepodaří potvrdit do 48 h?** Povinnost zaslat zprávu
trvá i po 48 h.
- a) balíček zkouší dál bez limitu a po překročení 48 h jednou vyvolá event (např. `SaleOverdue`), aby hostitel mohl upozornit obsluhu ★
- b) po N neúspěšných pokusech přestane a vyvolá event; další pokus jen ručně

Odpověď: A

**Q7 – Klasifikace chyb.** Kódy 2, 3, 4, 6 a 7 jsou chyby ve zprávě, opakování
nepomůže. Kód −1, timeout, chybějící odpověď, HTTP 5xx a SOAP fault jsou
dočasné, opakuje se. Dokumentace nerozlišuje **kód 8** („technická chyba nebo
chyba dat“) ani neznámé (rezervované) kódy.
- a) kód 8 i neznámé kódy se opakují s omezeným počtem pokusů a pak se tržba označí jako odmítnutá ★
- b) kód 8 i neznámé kódy jsou konečné odmítnutí
- c) jinak: ___

Odpověď: A

**Q8 – Ověření podpisu potvrzovací zprávy.** Odpověď podepisuje správce daně
certifikátem I.CA.
- a) balíček podpis ověřuje; při neplatném podpisu POK nepřijme a tržbu vede jako nepotvrzenou (kořen I.CA dodává hostitel přes config nebo ho balíček přibalí) ★
- b) ověřuje, ale jen to zaznamená (`signatureVerified = false`) a POK přijme
- c) neověřuje (spoléhá na TLS)

Odpověď: A

## Data tržby

**Q9 – Kdo přiděluje pořadové číslo tržby?**
- a) hostitelská aplikace vždy předá `sequenceNumber` (typicky číslo dokladu) ★
- b) balíček generuje číselnou řadu (např. per pokladní zařízení) a hostitel ji může přepsat
- c) obojí, řízené configem

Odpověď: A

**Q10 – Dvojí odeslání (double submit).** Co udělat, když hostitel zaeviduje
tržbu se stejným jednoznačným kódem tržby
(`eic|id_jednotky|id_pokl|porad_cis|dat_trzby|celk_trzba`), jaký už balíček zná?
- a) vrátit existující tržbu a výsledek, nic znovu neposílat (idempotence) ★
- b) vyhodit doménovou výjimku `DuplicateSale`
- c) poslat znovu jako opakované zaslání

Odpověď: A

**Q11 – Storno a oprava.** Podle MF se storno posílá jako nová tržba se
zápornou částkou, technicky bez vazby na původní tržbu.
- a) balíček nabídne pomocnou Action `reverse(Sale $original, Amount $amount, …)`, která vytvoří zápornou tržbu a lokálně si uloží odkaz na originál (do EET se neposílá) ★
- b) žádná zvláštní API; hostitel prostě zaeviduje tržbu se zápornou částkou

Odpověď: A

**Q12 – Vstup částky.** Jak hostitel předá částku do hodnotového objektu
`Amount` (float je zakázaný)?
- a) celé číslo v haléřích (`Amount::fromHalere(8160)`) a desetinný řetězec (`Amount::fromString('81.60')`) ★
- b) jen haléře
- c) jen řetězec

Odpověď: A

## Prostředí a veřejný povrch

**Q13 – Prostředí a ochrany.** Config bude obsahovat `environment`
(`playground`/`production`) a výchozí mód (ostrý/ověřovací). Mají platit ochrany?
- a) ano: POK končící `-ff` nebo s `test="true"` se nikdy nevydává za platný POK, a balíček nabídne `Eet::fake()` pro testy hostitele (bez sítě) ★
- b) jako a) + odmítnout produkční endpoint, když `app()->environment()` není `production`
- c) bez ochran

Odpověď: A

**Q14 – Veřejný povrch kromě facade a Actions.** Co z tohoto chcete?
(víc možností)
- a) Artisan příkazy: import certifikátu, ověření spojení (ověřovací mód), ruční opakované zaslání nepotvrzených tržeb, výpis stavu ★
- b) eventy: tržba potvrzena / odmítnuta / nepotvrzena / po lhůtě 48 h ★
- c) HTTP routy (např. JSON API nebo administrace)
- d) Blade komponenta pro tisk POK a údajů EET na doklad
- e) překlady chyb a varování (cs, en) ★

Odpověď: A, B, E

**Q15 – Logování a retence.** Ukládat surové XML požadavku a odpovědi
(neobsahují osobní údaje zákazníků, ale obsahují certifikát)? Jak dlouho držet
historii pokusů?
- a) ukládat odpověď + `X-Global-Transaction-Id` vždy, surový požadavek jen při chybě; retenci řídí config (výchozí: neomezeně, mazání příkazem `eet:prune`) ★
- b) neukládat surové XML vůbec
- c) jinak: ___

Odpověď: A

---

## Potřebné rozhodnutí mimo `spec/` (zapisuje jen člověk)

Nejde o otázky ke specifikaci. Architekt na ně narazil a nemá práva je změnit:

- **N1** – `composer.json` vyžaduje jen `illuminate/support`. Odesílání a podpis
  potřebují minimálně `ext-dom`, `ext-openssl` a HTTP klienta (`illuminate/http`
  + Guzzle). Smí se tyto závislosti přidat? Má se XML-DSig napsat vlastními
  silami nad `ext-dom`/`ext-openssl` (doporučeno, protože rozsah podpisu je pevně daný), nebo
  použít knihovnu (např. `robrichards/xmlseclibs`)? **ext-dom a openssl přidány, závislosti přidává vždy člověk, napiš tedy co dále potřebuješ. XML-DSig vlastními silami**
- **N2** – `AGENTS.md` uvádí „Money, quantities and VAT rates are value objects“.
  EET 2.0 žádné údaje o DPH ani množství nepřenáší. Má se věta upravit?. **Upraveno člověkem**
- **N3** – `AGENTS.md` obsahuje placeholder „[modul pro evidenci tržeb … doplnit
  celý popis]“. **Architekt má povolení doplnit**
- **N4** – V `spec/` chybí `05-infrastructure.md` (podpůrná matice, CI). Architekt
  ho může doplnit, ale potřebuje zadání (podporované verze stojí v `AGENTS.md`). **otázka člověka: jsou k něčemu potřeba?**

---

## Kolo 2 – doplňující otázky (2026-11-15)

Při psaní `spec/11-*.md` a `spec/20-*.md` vyšla najevo místa, která odpovědi
Q1–Q15 nepokrývají. Specifikace je napsaná podle doporučení (★) a dotčená
pravidla jsou označená „*čeká na Qxx*“. Když doporučení potvrdíte, stačí
odpovědět „★“. Jinou odpověď architekt do specifikace zapracuje.

**Q16 – Legacy `.p12` od CA EET.** Ověřeno lokálně: PHP 8.5 s OpenSSL 3.6
playground `.p12` **nenačte** (`openssl_pkcs12_read` vrací `false` s chybou
„unsupported“). Certifikáty uvnitř jsou šifrované RC2-40, klíč 3DES. (R9)
- a) Balíček `.p12` načte, jen pokud to umí běhové prostředí. Jinak hlásí
  `UnsupportedLegacyFormat` a README popíše převod do PEM
  (`openssl pkcs12 -legacy …`). Balíček přijímá i PEM. ★
- b) Balíček obsahuje vlastní dekodér PKCS#12 v PHP (RC2-40 + 3DES), který
  funguje všude. Pohodlnější pro uživatele, ale jde o vlastní kryptografický
  kód, který musí projít bezpečnostní revizí.

Odpověď: A

**Q17 – Výběr certifikátu pro podpis (R16).** Pořadí: připnutý k pokladnímu
zařízení (jen když je platný) → platný certifikát poplatníka s nejpozdějším
`validTo` → certifikát z configu → `CertificateUnavailable`. Když připnutý
certifikát vyprší, balíček **přejde na další krok** místo zablokování
evidence. ★ Souhlasí?

Odpověď: nepotvrzuji

*Diskuze (kolo 3):* **Potřebuje každé pokladní zařízení vlastní certifikát?
Podle MF ČR ne.**
- Produkce, Přístupové a provozní informace v1.1, kap. 3.1: „V souladu se
  ZoET může každý poplatník požádat o jeden nebo více pokladních certifikátů.
  Rozhodnutí o počtu certifikátů a jejich přiřazení pokladnám je plně
  v kompetenci poplatníka.“
- Profil certifikátu obsahuje jen `CN = EIČ poplatníka`, žádné označení
  pokladního zařízení ani evidenční jednotky (Produkce 3.1.2, PG 3.1.5).
- Kritické kontroly EET ověřují vydavatele, platnost a podpis certifikátu.
  Vazbu certifikátu na `id_pokl` ani `id_jednotky` neověřují (Popis rozhraní
  2.2.3). Propustná kontrola porovnává jen EIČ (2.2.4 bod 1).
- Popis položek uvádí příklady s jedním certifikátem pro víc koncových zařízení
  (kap. 1.9 příklad 3: síť prodejen se společným serverem; příklad 4: webová
  aplikace jako jedno pokladní zařízení).

Jedním certifikátem tedy smí poplatník podepisovat zprávy ze všech svých
pokladních zařízení i jednotek. Víc certifikátů je jeho volba, např. aby při
ztrátě zařízení stačilo zneplatnit jeden. **Q17a – který model chcete?**
- a) Certifikát patří poplatníkovi. Pokladní zařízení si ho volitelně může
  připnout. Výběr podle pořadí výše. ★
- b) Certifikát patří poplatníkovi a připínání neexistuje. Vždy se použije
  platný certifikát poplatníka s nejpozdějším `validTo` (jednodušší).
- c) Připínání je povinné. Pokladní zařízení bez certifikátu nebo s prošlým
  certifikátem neodešle nic (`CertificateUnavailable`).

**Q17b – připnutý certifikát vypršel (platí jen pro a).**
- a) Použít platný certifikát poplatníka, evidence se nezastaví. ★
- b) Neodeslat (`CertificateUnavailable`) a nechat to na obsluze.

Odpověď Q17a: A

Odpověď Q17b: A

**Q18 – Výchozí hodnoty opakování (R40, R41).** Backoff `[60, 300, 900,
3600]` s, potom každých 3600 s bez limitu. Pro kód 8 a neznámé kódy nejvýš
3 pokusy, pak `Rejected`. Obojí lze změnit v configu. ★ Souhlasí, nebo jiné
hodnoty?

Odpověď: Souhlasí

**Q19 – Lokální validace (R20, R21).** Před uložením se kontroluje jen to, co
by systém EET odmítl kritickou chybou (masky XML schématu). Věcné nesrovnalosti,
na které EET reaguje jen varováním (např. `delegatingEic` rovné EIČ
poplatníka), se neblokují. ★ Nebo je chcete blokovat výjimkou?

Odpověď: nesouhlasím

*Diskuze (kolo 3):* **Jaké nesrovnalosti můžou nastat.** U každé zvolte
**B** = blokovat (výjimka, nic se neuloží ani neodešle), **V** = přijmout a
označit (tržba se odešle a hostitel dostane informaci předem, ne až z
odpovědi EET), **P** = jen propustit (rozhodne EET).

Nesrovnalosti, na které EET reaguje varováním:

| # | Nesrovnalost | Co říká MF ČR | Kdy je legitimní | Návrh |
|---|---|---|---|---|
| a | `delegatingEic` = EIČ poplatníka | Situace 1–3: položka „nesmí být vyplněna (ani stejným EIČ)“ (Popis položek 3.1); EET dá varování 7 | nikdy | **B** |
| b | `occurredAt` je víc než 2 h v budoucnosti oproti `now()` | varování 4; dřívější evidence jen u „dostatečně určité“ objednávky (P 1.11) | předem placená objednávka (vzácně, max. pár hodin) | **V** |
| c | `occurredAt` je víc než 2 roky staré | varování 5 | prakticky nikdy | **B** |
| d | `occurredAt` je před minimálním datem prostředí (produkce 1. 11. 2026, playground 1. 7. 2026) | varování 5 | nikdy (tehdy povinnost neexistovala) | **B** |
| e | číslo evidenční jednotky nemá tvar z DIS+ (méně než 2 číslice nebo nekončí na 1–4) | varování 6; MF píše „obvykle“, ne vždy | výjimečně | **V** (kontrolovat už při založení jednotky) |
| f | CN certifikátu ≠ EIČ poplatníka | varování 1 | při změně EIČ (P 1.5) | **V** (viz Q21) |

Nesrovnalosti, které EET vůbec nekontroluje (vyplývají z věcného popisu MF
ČR):

| # | Nesrovnalost | Co říká MF ČR | Návrh |
|---|---|---|---|
| g | `amountForLaterDrawing` nebo `drawnAmount` má jiné znaménko než `totalAmount` | částka se „promítne též do celkové částky“ (P 1.13, 1.14, příklady) | **B** |
| h | \|`amountForLaterDrawing` + `drawnAmount`\| > \|`totalAmount`\| | dtto | **B** |
| i | `totalAmount` = 0 | technicky lze; MF doporučuje rozdělit na dvě zprávy (P 5.4 př. 3) | **P** |
| j | storno (`reverse`) vyšší než původní tržba nebo součet storen nad originál | MF neřeší; u vratných obalů může vratka převýšit nákup | **P** |

Pravidla B v tabulce převezmu přesně, jak je potvrdíte. Pravidla V
přidají tržbě seznam lokálních nálezů (`Sale::preflightWarnings()`) a
vyvolají event.

Odpověď (např. „a B, b V, c B, …“): vše podle návrhu

**Q20 – Prostředí a mód uložené u tržby (R23).** Tržba si při vzniku uloží
`environment` a `mode` z configu a opakovaná zaslání je použijí, i když se
config mezitím změní. Tržba z playgroundu se tak nikdy nepošle do produkce.
Výchozí `environment` je `playground`. ★ Souhlasí?

*Diskuze (kolo 3):* **Jak se to projeví v praxi a v kódu.**

V kódu: `eet_sales` má sloupce `environment` (`playground`/`production`)
a `mode` (`live`/`verification`) vyplněné z configu při `register()`. Při
každém opakovaném zaslání (job, `eet:resubmit`) se endpoint a atribut
`overeni` berou **z tržby, ne z configu**. Config tedy ovlivňuje jen nově
vznikající tržby.

V praxi hostitelská aplikace nastaví `EET_ENVIRONMENT` v `.env` (vývoj a
staging `playground`, produkce `production`). Rozdíl nastane jen tehdy, když
se config změní a v databázi zůstanou nepotvrzené tržby:

1. *Přechod z playgroundu do ostrého provozu.* Firma testuje na
   produkčním serveru s playgroundem a pak přepne na `production`.
   Nepotvrzené testovací tržby se dál posílají na playground, kde jsou
   neškodné. **Bez uložení by se testovací tržby odeslaly do produkce
   a správce daně by je vedl jako skutečné tržby.**
2. *Opačně při ladění.* Admin dočasně přepne produkční server na
   `playground`. Nepotvrzené ostré tržby se dál posílají do produkce
   a evidenční povinnost se splní. **Bez uložení by dostaly fiktivní POK
   `…-ff` a povinnost by se tiše nesplnila.**
3. *Kopie produkční databáze na staging.* S uložením by staging posílal
   kopírované ostré tržby do produkce (duplicity, ale stejný jednoznačný
   kód tržby, takže je EET bere jako „zaslání téže tržby“). Řeší se
   vypnutím fronty na stagingu nebo příkazem k označení starých tržeb.
   Případná pojistka je otázka **Q20b**.

**Q20a – kde žije prostředí?**
- a) Globálně v configu, uložené u tržby. ★
- b) Per poplatník (sloupec u `eet_taxpayers`), aby SaaS mohl mít jednoho
  poplatníka na playgroundu a ostatní v produkci. Pozor: playground přijímá
  jen testovací certifikáty vydané pro tři testovací EIČ, takže skutečný
  poplatník tam své certifikáty nevyzkouší. Proto ★ a).

**Q20b – pojistka proti scénáři 3:** odmítnout opakované zaslání tržby
`production`, když běží aplikace mimo `app()->environment('production')`?
- a) ne ★
- b) ano

Odpověď Q20a: B

Odpověď Q20b: A

**Q21 – Import certifikátu (R14, R18).** `eet:certificate:import` bez
`--taxpayer` založí poplatníka podle CN certifikátu, pokud neexistuje.
Neshoda CN s EIČ poplatníka import nezablokuje (při změně EIČ je legitimní),
jen vypíše varování. ★ Souhlasí?

*Diskuze (kolo 3), přeformulováno.* Jde o dvě samostatné otázky.

**Q21a – Komu certifikát přiřadit, když to příkaz nezná?** Spustíte
`php artisan eet:certificate:import cesta/k/souboru.p12` a neuvedete
`--taxpayer`. V certifikátu je zapsané EIČ (např. `CZ00000019`). Když
poplatník s tímto EIČ v databázi ještě není:
- a) příkaz ho sám založí a certifikát mu přiřadí ★ (pohodlné první
  nastavení)
- b) příkaz skončí chybou „poplatník CZ00000019 neexistuje, založte ho“

**Q21b – Co když certifikát patří někomu jinému?** Spustíte import
s `--taxpayer=CZ00000019`, ale certifikát byl vydán pro `CZ683555118`.
Většinou jde o omyl (cizí soubor). Jediný legitimní případ podle MF ČR:
poplatníkovi se změnilo EIČ (např. z rodného čísla na VČP). Do vydání nového
certifikátu smí posílat tržby s **novým** EIČ podepsané **starým**
certifikátem (Popis položek 1.5). EET pak jen vrací varování 1.
- a) přijmout a vypsat varování ★
- b) odmítnout; výjimku povolit přepínačem `--allow-eic-mismatch`
- c) vždy odmítnout

Odpověď Q21a: A

Odpověď Q21b: A

**Q22 – Co maže `eet:prune` (R52).** Jen surové XML požadavků a odpovědí.
Tržby, pokusy, POK a metadata zůstávají navždy, protože jsou dokladem o
splnění evidenční povinnosti. ★ Nebo mazat i celé staré záznamy?

Odpověď: jen XML. celé záznamy nikdy nemazat

**Q23 – Důvěryhodné CA pro podpis odpovědi (R35).** Odpověď podepisuje
certifikát od I.CA (`I.CA Public CA/RSA 06/2022`). Certifikáty I.CA jsou na
ica.cz, které podle zadání nesmím používat jako zdroj, a v repozitáři nejsou.
- a) Hostitel nastaví `response_trust_store` (cestu k PEM). Bez ní
  `register()` skončí `InvalidConfiguration`. README řekne, odkud CA stáhnout. ★
- b) Člověk dodá PEM soubory I.CA a balíček je přibalí jako výchozí (vyžaduje
  vydání nové verze balíčku při rotaci CA).

Odpověď: Možnost volby A + B

**N1 – navazující: další závislosti pro první iteraci.** Kromě `ext-dom`
a `ext-openssl` kód potřebuje tyto části Laravelu. V hostitelské aplikaci
s `laravel/framework` už jsou, ale `composer.json` je má deklarovat:
`illuminate/database` (modely, migrace), `illuminate/queue` (job),
`illuminate/console` (příkazy), `illuminate/events`, `illuminate/encryption`
(šifrovaný klíč), `illuminate/cache` (zámek R43), `illuminate/translation`
a `illuminate/http` + `guzzlehttp/guzzle` (HTTP klient s timeoutem a
TLS). Všechny ve verzích `^12.0||^13.0`, Guzzle `^7.8`. Alternativa k
`illuminate/http` + Guzzle je `ext-curl` napřímo.
UUID v4 lze generovat bez další závislosti.

Odpověď: nainstalovano

---

## Kolo 4 (2026-11-15)

**Q24 – Rozpoznání prostředí certifikátu (R60).** Plyne z Q20a → B (prostředí
per poplatník). Když se poplatník přepne do produkce a v databázi má jen
playground certifikát, produkce by zprávu odmítla chybou 4. To je **konečné**
odmítnutí, tržba by skončila `Rejected`. Proto se certifikát jiného prostředí
pro podpis nevybere (R16) a pokus skončí `CertificateUnavailable`, který se
opakuje, dokud hostitel nenaimportuje správný certifikát.

Prostředí certifikátu se určuje z politiky (OID). **Nesoulad zdrojů:**
playground dokument uvádí `1.2.203.19122063.10.4.102.x.y`, ale skutečné
playground certifikáty ze zipu mají `1.2.203.19122063.10.2.102.0.1` (ověřeno
`openssl x509`). Produkční dokument uvádí `…10.1.102.x.y`.
- a) `…10.1.` = produkce, cokoli jiného = playground. ★ (spolehlivé i při
  dalších změnách testovacích OID)
- b) `…10.1.` = produkce, `…10.2.` nebo `…10.4.` = playground, jiné OID =
  import se odmítne (`InvalidCertificate`, nový důvod `UnknownPolicy`).

Odpověď: prostředí určuje aplikace používající tento balíček - nesoulad v certifikátech zatím nech na EET serveru, jelikož neexistuje spolehlivá dokumentace

---

## Kolo 5 – po revizi (reviewer + security, 2026-11-15)

Otázky vycházejí ze zpráv rolí reviewer a security. Úpravy, které jsou
jen upřesněním nebo plynou přímo ze zdrojů, architekt zapracuje bez dotazu.
Zde jsou jen rozhodnutí.

**Q25 – Odmítnutá tržba (navazuje na Q24).** Po chybě 4 (neplatný podpis:
certifikát jiného prostředí, zneplatněný nebo prošlý certifikát, chyba
podpisu v balíčku, ale i podvržená nepodepsaná chybová odpověď) je tržba
`Rejected` navždy. Opětovné `register()` se stejnými daty vrátí odmítnutou
tržbu (R26). Registrace s novým pořadovým číslem by vytvořila **jinou**
tržbu, a pokud EET originál ve skutečnosti přijal, vznikne dvojí evidence.
Povinnost zaslat přitom trvá (P 6). Obě role doporučují variantu A.
- a) **Ruční opětovné zaslání.**
  - Hostitel výslovně zavolá `Eet::resubmit($sale)` nebo
    `eet:resubmit --sale=ID`. Tržba přejde `Rejected → Unconfirmed` a odešle se
    se stejnými `Data`, novým UUID, `prvni_zaslani="false"` a nově vybraným
    certifikátem.
  - Nikdy se to nestane automaticky.
  - Povolené jen pro odmítnutí kódem 4, 8 nebo neznámým kódem. U kódů 2, 3, 6
    a 7 jsou chybná data a opakování nepomůže.
  - Vyvolá se event `SaleReopened` (auditní stopa).
  - `eet:status` vypíše odmítnuté produkční tržby a skončí kódem 1. ★
- b) jako a), ale povolené pro všechny kódy
- c) jako a) + prevence: po importu certifikátu nebo změně prostředí
  poplatníka se pošle zpráva v ověřovacím módu. Certifikát, na který EET
  v daném prostředí vrátí chybu 4, se při výběru přeskočí. Pokus pak skončí
  `CertificateUnavailable` (opakuje se), ne `Rejected`. Znamená to síťové
  volání navíc a novou tabulku. Doporučeno spíš jako pozdější issue.

Odpověď: A

**Q26 – Tržba uvízlá v `Pending`.** Když proces spadne mezi uložením tržby
a dokončením pokusu (OOM, deploy, timeout PHP-FPM), tržba zůstane `Pending`.
Job, `eet:resubmit`, `SaleOverdue` ani `eet:status` s ní nepracují, takže by
se nikdy neodeslala.
- a) `Pending` starší než `2 × response_timeout_ms` + 60 s (výchozí tedy
  68 s) se všude chová jako `Unconfirmed`. Další pokus má
  `prvni_zaslani="false"` jen tehdy, když už existuje záznam pokusu. ★
- b) jiná hranice: ___ s (konfigurovatelná)

Odpověď: A

**Q27 – Certifikát z configu u více poplatníků.** Dnes (R16 krok 3) se
certifikát z configu použije pro **každého** poplatníka, který nemá vlastní
platný certifikát. V instalaci s více poplatníky by tak tržby poplatníka B
podepsal klíč poplatníka A. EET by vrátil jen varování 1, POK vydal a tržba
by vypadala jako úspěšná. Tohle pravidlo jsem navrhl já, z vašich odpovědí
neplyne.
- a) Certifikát z configu se použije jen pro poplatníka, jehož EIČ se rovná
  CN certifikátu. Jinak výsledek `CertificateUnavailable`. ★
- b) Certifikát z configu se použije, jen když je v databázi právě jeden
  poplatník.
- c) Ponechat jak je.

Odpověď: Certifikát z konfigu se nepoužije nikdy

**Q28 – Kterému podpisu odpovědi věřit.** Potvrzení od EET podepisuje
certifikát vydaný `I.CA Public CA/RSA 06/2022`. Tatáž CA ale vydává
certifikáty i veřejnosti. Kdo ovládne spojení (podvržený endpoint, firemní
TLS proxy), může si koupit certifikát od I.CA a podepsat jím falešný POK.
Tržba by pak byla `Confirmed` a už by se nikdy znovu neposlala. Řešením je
ověřit i samotný podpisový certifikát EET. Dokumentace MF ale uvádí jen
vydavatele, ne subjekt, takže ho je potřeba zjistit ze skutečné podepsané
odpovědi z playgroundu.
- a) Smím poslat **jednu** zprávu v ostrém módu na playground se sdíleným
  testovacím certifikátem, z podepsané odpovědi zjistit subjekt
  (`organizationIdentifier`/`O`) a pravidlo ověřovat podle něj. Subjekt se
  pak dá změnit v configu. ★
- b) Připnout SHA-256 otisk veřejného klíče podpisového certifikátu
  (přesnější, ale při výměně certifikátu na straně EET je potřeba nové
  vydání balíčku nebo změna configu).
- c) Ponechat jen ověření vydavatele.

Odpověď: A

**Q29 – Heslo v příkazu `eet:certificate:import`.** Volba `--password=`
uloží heslo do historie shellu a je vidět v seznamu procesů.
- a) Volbu zrušit. Heslo se zadá jen skrytým vstupem, nebo přes
  `--password-stdin` pro automatizaci. ★
- b) Ponechat `--password=` a v README varovat.

Odpověď: A

> **Stav 2026-11-15:** Kola 1–5 jsou zodpovězená a zapracovaná do `spec/10`,
> `spec/11` a `spec/20` (pravidla R1–R70). Kolo 6 (Q30–Q33) je zapracované
> také; pravidla jsou nyní R1–R72. N4 je vyřešené: `spec/05-infrastructure.md`
> (pravidla R73–R89) napsal architekt 2026-11-15.
> Kolo 7 (Q34–Q39) je zapracované.

---

## Kolo 6 – po 2. revizi (reviewer + security, 2026-11-15)

**Q30 – Identita podpisového certifikátu EET podle prostředí.** Na playgroundu
podepisuje odpovědi certifikát `CN=NEOSTRE - playground prostredi EET`,
`organizationIdentifier=NTRCZ-72080043` (GFŘ). Produkční certifikát jsem
neviděl, ale nejspíš má stejný `organizationIdentifier`. Security upozornila,
že dnešní kontrola (jen `organizationIdentifier`) nerozliší odpověď
playgroundu od produkce. Útočník na spojení by mohl produkční tržbě podstrčit
pravé, GFŘ podepsané potvrzení z playgroundu. Proti tomu architekt i bez
otázky doplní pravidlo, že produkční tržba nikdy nepřijme odpověď
s `test="true"` ani POK `-ff` (opakuje se, není `Confirmed`). Otázka je, zda
navíc kontrolovat CN podle prostředí:
- a) Ano. Playground: CN = `NEOSTRE - playground prostredi EET`. Produkce:
  CN **nesmí** obsahovat „playground“, dokud se skutečné produkční CN nezjistí
  z první produkční odpovědi. Pak se doplní do configu
  `response_signer.production.common_name`. ★
- b) Ne. Stačí `organizationIdentifier` + pravidlo o `test`/`-ff`.

Odpověď: A

**Q31 – Znovuotevření i pro kódy 2, 3, 6, 7 (doplňuje Q25).** Chybová odpověď
EET není podepsaná, takže útočník na spojení může podstrčit `Chyba kod="3"`
a tržba skončí trvale `Rejected` bez nápravy. Balíček generuje zprávy validní
vůči XSD, takže tyto kódy by legitimně nastat neměly a jejich výskyt je sám
podezřelý.
- a) Povolit s výslovným příznakem: `resubmit($sale, force: true)` /
  `eet:resubmit --sale=ID --force`. Bez něj platí Q25 → A. `eet:status`
  hlásí i produkční `Rejected` s kódy 2/3/6/7. ★
- b) Ponechat Q25 → A beze změny.

Odpověď: A

**Q32 – Přednost certifikátů s CN = EIČ poplatníka (doplňuje Q17).** Kdo smí
importovat certifikáty, může poplatníkovi přidat cizí certifikát s pozdějším
`validTo` a ten se automaticky stane výchozím pro všechny nepřipnuté pokladny
(dnes jen lokální varování).
- a) Při výběru (R16 krok 2) mají přednost kandidáti s CN = EIČ poplatníka.
  Certifikát s jiným CN se použije, jen když je připnutý nebo když jiný
  kandidát není (legitimní případ změny EIČ). ★
- b) Ponechat dnešní pořadí.

Odpověď: A

**Q33 – Fake a testovací hooky mimo testy.** `Eet::fake()` nebo
`Eet::createMessageUuidsUsing()` zavolané omylem v produkci (seeder, tinker,
ladicí kód) by tiše potlačilo evidenci: tržby `Confirmed` bez odeslání.
- a) Obě metody vyhodí výjimku, když aplikace neběží v testech
  (`app()->runningUnitTests()`). Továrna UUID musí vracet platné UUID v4,
  jinak výjimka. ★
- b) Bez pojistky, jen varování v README.

Odpověď: A

---

## Kolo 7 – po 3. revizi (reviewer + security, 2026-11-15)

**Q34 – Pravidlo R72 (zákaz `register()` uvnitř DB transakce) a testy.**
Standardní testovací traity Laravelu (`RefreshDatabase`,
`DatabaseTransactions`) obalují každý test transakcí. R72 by proto v každém
testu hostitele i balíčku vyhodilo `ActiveDatabaseTransaction`, i pod
`Eet::fake()`. Pravidlo navrhla security (rollback hostitele by smazal doklad
o tržbě, kterou EET už přijal), v žádném zdroji není.
- a) Ponechat R72, ale počítat úroveň transakcí relativně k úrovni, kterou
  otevřel testovací trait (zaznamená se při `runningUnitTests()`). Mimo
  testy platí beze změny. ★
- b) R72 zrušit, jen varovat v README.
- c) Ponechat R72 beze změny a v README předepsat `DatabaseMigrations` nebo
  `DatabaseTruncation`.

Odpověď: A

**Q35 – Zámky a fronta: zpřísnit config, ale nerozbít testy.**
- Dnešní kontrola vyřadí jen cache driver `array`. Projde ale `null` (zámek
  vždy uspěje), `memo` nad `array` i `failover` s `array` zálohou, takže dva
  pokusy můžou běžet souběžně.
- Fronta s driverem `sync`, `deferred`, `background` nebo `null` ignoruje
  zpoždění nebo job zahodí. Při výpadku EET by `sync` opakoval pokusy každé
  4 s přímo v requestu pokladny.
- `resubmission.backoff` nemá spodní mez, takže `[1]` znamená pokus každou
  sekundu donekonečna.
- Výchozí cache v Testbench a v testech Laravelu je `array`, takže přísné
  pravidlo by rozbilo testy.

Možnosti:
- a) Zpřísnit, s výjimkou pro testy:
  - `lock_store` jen drivery `redis`, `database`, `memcached`, `dynamodb`,
    `file` (u `memo`/`failover` se kontroluje podkladový store);
  - `resubmission.connection` nesmí mít driver `sync`, `deferred`,
    `background` ani `null`;
  - hodnoty `backoff` ≥ 30 s;
  - při `runningUnitTests()` se povolí i `array` a `sync`. ★
- b) Zpřísnit bez výjimky; testy si nastaví `database` store a frontu samy.
- c) Ponechat jak je.

Odpověď: A

**Q36 – Potvrzení hodnot bez zdroje.** Reviewer upozornil, že tyto hodnoty
nejsou ve zdrojích MF ani ve vašich odpovědích. Navrhli je architekt nebo
security:
- allow-list hostitelů endpointů (`trzbyeet.gov.cz`, `pg.trzbyeet.gov.cz`;
  zdroj zakazuje jen IP adresu);
- config `proxy` a ignorování `HTTP(S)_PROXY` z prostředí;
- limit odpovědi 64 KiB (65 536 B) a vypnutá dekomprese (odpověď
  s `Content-Encoding` je `TransportError`);
- tolerance času `dat_prij` 600 s (rozsah 1–3600);
- vlastní úložiště důvěry: 1–10 certifikátů;
- `response_timeout_ms` nejvýš 60 000;
- `porad_cis = "OVERENI"` u ověření spojení;
- spodní mez backoffu 30 s (Q35).

Možnosti:
- a) Potvrzuji všechny. ★
- b) Potvrzuji kromě: ___

Odpověď: potvrzuji jako defaultní hodnoty, musí být upravitelné přes config

**Q37 – Storno originálu z jiného prostředí nebo módu.** `reverse()` dnes
založí storno v *aktuálním* prostředí poplatníka a módu configu. Když
originál vznikl na playgroundu (nebo v ověřovacím módu) a poplatník se mezitím
přepnul do produkce, odejde do produkce záporná tržba bez kladného protějšku.
Tržby se tím podhodnotí.
- a) `reverse()` vyhodí výjimku `ReversalEnvironmentMismatch`, když se
  prostředí nebo mód originálu liší od aktuálních. ★
- b) Storno se založí v prostředí a módu originálu.
- c) Ponechat jak je.

Odpověď: A

**Q38 – Produkční podpisový certifikát EET, dokud neznáme jeho CN.**
Dokud je `response_signer.production.common_name = null`, platí pro produkci
jen „GFŘ `organizationIdentifier` + CN bez slova playground“. Jiný certifikát
GFŘ od I.CA, například zaměstnanecký nebo kompromitovaný, by spolu
s útokem na spojení prošel.
- a) Při prvním produkčním `Confirmed` se u pokusu uloží subjekt a otisk
  podpisového certifikátu. `eet:status` hlásí varování a končí kódem 1,
  dokud existuje produkční poplatník a `production.common_name` je `null`.
  Totéž platí, když je nastavené vlastní `response_trust_store` a existuje
  produkční poplatník (testovací CA v produkci). ★
- b) Navíc automaticky připnout otisk prvního produkčního podpisového
  certifikátu (TOFU). Hrozí falešné poplachy při rotaci certifikátu GFŘ.
- c) Nic navíc.

Odpověď: A

**Q39 – Rozpoznatelnost tržeb z `Eet::fake()`.** Pod fake dostane produkční
tržba „platný“ POK `<uuid>-01`. Test spuštěný proti sdílené nebo staging DB
by tak vytvořil tržby k nerozeznání od skutečných.
- a) Pokusy pod fake se uloží s příznakem `is_fake = true` (sloupec
  v `eet_submission_attempts` i `eet_sales`). `hasValidConfirmationCode()` se
  nemění, aby šla testovat i kladná větev. `eet:status` hlásí fake tržby
  v produkčním prostředí. ★
- b) Nic navíc (guard `runningUnitTests()` stačí).

Odpověď: A

---

## Kolo 8 – po revizi implementace (reviewer + security, 2026-11-15)

**Q40 – `float` v `src/`.** AGENTS.md říká „`float` is forbidden in `src/`“.
Implementace má float na třech místech, nikde u peněz:
- délka pokusu (`hrtime` / 1 000 000) a TTL zámku (`ceil`) – implementer je
  převede na celočíselné `intdiv()`, tady rozhodnutí potřeba není;
- **timeout pro Guzzle:** volba `timeout` se zadává v sekundách jako float
  (4000 ms → `4.0`); bez floatu ji nastavit nejde a testy ji kontrolují (R32).

Možnosti:
- a) Člověk upraví větu v AGENTS.md na „`float` is forbidden for money and
  quantities in `src/`“; technická převodní hodnota pro Guzzle je pak
  povolená. ★
- b) Pravidlo zůstane beze změny; timeout pro Guzzle je jediná výjimka,
  výslovně okomentovaná v kódu.

Odpověď: agents.md upraveno

---

## Kolo 9 – po kontrolní revizi oprav (reviewer + security, 2026-11-15)

Kontext: oprava limitu velikosti odpovědi přidala Guzzle volbu
`CURLOPT_MAXFILESIZE_LARGE`. Guzzle 8 ji odmítá u **každého** požadavku
a široký `catch (Throwable)` to tiše převedl na „chybu spojení“. V produkci
by se tak nikdy nic neodeslalo, přestože testy byly zelené, protože
`Http::fake()` skutečný cURL handler obchází. Volbu implementer odstraní
(to rozhodnutí nepotřebuje). Zbývají tři otázky.

**Q41 – Výjimky, které nejsou chybou přenosu.** Programátorská nebo
konfigurační chyba (`InvalidArgumentException` z Guzzle, `TypeError`, …)
během odesílání:
- a) Jako `TransportError` se uloží jen skutečné chyby přenosu
  (`TransferException` z Guzzle, `HttpClientException` z Laravelu, příliš
  velká odpověď). Ostatní výjimky propadnou ven, aby byla chyba hned vidět.
  Tržba zůstane `Pending` a po hranici R63 ji zachrání `eet:resubmit`. ★
- b) Vše jako `TransportError` s kategorií `connection` (dnešní stav; chybu
  skryje).
- c) Ostatní výjimky jako `TransportError` s novou kategorií `internal`
  (opakuje se, ale je v `eet:status` odlišitelná).

Odpověď: A

**Q42 – Test se skutečným cURL handlerem.** Žádný dnešní test neprochází
skutečným odesláním přes Guzzle, takže podobnou chybu by nic nezachytilo.
- a) Tester přidá integrační testy transportu proti lokálnímu HTTP serveru na
  `127.0.0.1` (PHP built-in server spuštěný testem), bez `Http::fake()`:
  běžná odpověď, limit velikosti s `Content-Length` i bez něj, přesměrování,
  timeout. `spec/05` R77 se upřesní, že smyčka `127.0.0.1` není síťový
  přístup. ★
- b) Ne, stačí ruční ověření na playgroundu před vydáním.

Odpověď: A

**Q43 – Globální middleware HTTP klienta hostitele.** Dnes se izolují jen
`Http::globalOptions()`. Globální middleware hostitele (`Http::globalMiddleware()`)
se na požadavky EET dál uplatní: může přidat hlavičky nebo změnit proxy či
ověření TLS. Laravel 12.71+ i 13.40+ má `Http::withoutGlobalConfiguration()`,
které odstraní globální volby **i** middleware a zachová `Http::fake()`.
- a) Požadavky na EET se sestavují úplně bez globální konfigurace hostitele
  (volby i middleware). Hostitel je pak nemůže logovat přes svůj middleware,
  ale může dál použít eventy `RequestSending`/`ResponseReceived`. ★
- b) Izolovat jen volby (dnešní stav), middleware nechat, v README upozornit.

Odpověď: A

---

## Kolo 10 – po poslední kontrolní revizi (reviewer + security, 2026-11-15)

Stav: `composer test` je zelený (651/651). Vážná chyba transportu je
opravená a ověřená na Guzzle 7 i 8. Security: nízké riziko, lze commitnout.
Reviewer: schváleno po opravách. Implementer **vyčerpal limit 8 opravných
iterací** (AGENTS.md), proto potřebuji rozhodnutí, jak pokračovat.

Zbývající nálezy:
- **N1 (reviewer):** na Guzzle 8 se timeout po přijetí hlaviček s HTTP ≥ 400
  (např. SOAP fault s 500) uloží jako `TransportError`/`connection` místo
  `Timeout`. Oba výsledky se opakují, tržba se neztratí, jen je chybná
  kategorie (R32, R33). Oprava: číst errno z `on_stats` + test.
- **N2 (security):** nepodepsaná `Chyba` se syntakticky platným, ale
  nemožným datem (`2026-13-45T25:61:61+01:00`) projde maskou a shodí pokus
  výjimkou místo `TransportError` (R65). Tržba zůstane `Pending`. Útočník musí
  mít pozici EET serveru nebo prolomit TLS. Oprava: striktní kontrola data
  + test.
- **N3 (reviewer):** zbytečný callback `progress` na Guzzle 7 zdrží zastavení
  příliš velké odpovědi až o 2 s (limit sám vynutí `sink`). Oprava: odstranit.

**Q44 – Jak dokončit zbývající nálezy N1–N3?**
- a) Povolit implementerovi **jednu další opravnou iteraci navíc** jen pro
  N1–N3; tester předem doplní testy (5xx s pauzou po hlavičkách, nemožné
  datum). Pak krátká kontrola a commit. ★
- b) Commitnout teď a N1–N3 zapsat do GitHub issues na pozdější opravu.
- c) Přijmout N1 jako známé chování (jen kategorie), opravit jen N2 a N3
  podle a).

Odpověď: A

**Q45 – Jedna problémová tržba zastaví celý `eet:resubmit`.** Když u jedné
tržby propadne neočekávaná výjimka (Q41 → A), smyčka v `eet:resubmit` skončí
a tržby s vyšším ID se v tom běhu nezpracují. Protože se řadí podle ID,
opakuje se to v každém běhu a pojistka R63 je pro ostatní tržby zablokovaná.
- a) Příkaz zachytí výjimku u každé tržby zvlášť, nahlásí ji (`report()`,
  ID tržby ve výstupu), pokračuje dalšími tržbami a skončí kódem `1`. Mimo
  příkaz (register, job) platí Q41 beze změny. ★
- b) Ponechat; první výjimka ukončí příkaz.

Odpověď: A

---

## Kolo 11 – po závěrečné kontrole (reviewer + security, 2026-11-16)

Reviewer i security celou sadu změn **schválili ke commitu** (`composer test`
zelený, 659/659 na Guzzle 8.5 i 7.15.2). Zbyly drobné neblokující nálezy:
- **D1:** `dat_prij`/`dat_odmit` s rokem `0000` nebo `9999` a offsetem
  projde kontrolou data. Na MySQL/PostgreSQL by insert pokusu nejspíš selhal
  výjimkou (útočník musí být server EET za ověřeným TLS). Oprava: omezit rok
  v R65, např. 1000–9998.
- **D2:** kontrola data odmítá `-00:00` a `T24:00:00`, ačkoli xs:dateTime je
  připouští. EET je v praxi neposílá.
- **D3:** v `eet:resubmit` jsou `refresh()` a upozornění na lhůtu
  (`SaleOverdue`) mimo zachytávání výjimek po tržbách. Výjimka z listeneru
  hostitele zastaví jen aktuální běh.
- **D4:** test pro R53 neověřuje, že se `report()` skutečně zavolá, a pokrývá
  jen běh bez `--sale`.

**Q46 – Co s drobnostmi D1–D4?**
- a) Commitnout teď do PR #4 a D1–D4 zapsat jako jedno GitHub issue
  („hardening po první iteraci“) na další iteraci. ★
- b) Opravit je ještě před commitem (další kolo tester → implementer →
  kontrola).

Odpověď: A

---

## Kolo 12 – CI na PR #4 po commitu implementace (2026-11-16)

CI selhalo ve všech 25 liniích (lokálně je vše zelené). Tři nezávislé
příčiny, žádná nesouvisí s logikou EET:
- **C1 – type coverage (Linux, prefer-stable):** CI nainstalovalo
  `phpstan/phpstan` **2.3.6** (lokálně 2.3.5). `pestphp/pest-plugin-type-coverage`
  5.1.0 s ním padá (`Call to undefined method AnalyserResult::getCollectedData()`).
  Nekompatibilita dvou cizích nástrojů.
- **C2 – PHPStan jen na Windows (prefer-stable):** 8× „Call to function
  is_string() with string will always evaluate to true“ / „Result of || is
  always false“ v `ImportCertificateCommand`, `PruneCommand`, `VerifyCommand`.
  Na Linuxu se stejnou verzí PHPStan projde. Oprava je v kódu (implementer).
- **C3 – testy na nejnižších verzích (prefer-lowest, např. Laravel 13.40,
  Carbon 3.8.4):** několik testů selže (`ImportCertificateTest`: čas posunutý
  o 3600 s, `ResponseOutcomeTest`: „false is true“). Je potřeba diagnostika
  a oprava v kódu, případně v testu.

**Q47 – C1: nekompatibilní PHPStan 2.3.6 s pluginem type coverage.**
`composer.json` mění jen člověk.
- a) Dočasně omezit vývojovou závislost: přidat do `require-dev`
  `"phpstan/phpstan": "^2.1.32 <2.3.6"` (nebo `!=2.3.6`) a otevřít issue na
  odstranění, až plugin vydá opravu. ★
- b) Počkat na opravu pluginu; do té doby nechat CI červené.

Odpověď: A

**Q48 – C2 a C3: kdo a v jakém rozsahu opraví.** Implementer už vyčerpal
8 iterací i povolenou iteraci navíc.
- a) Povolit nové, samostatné opravné kolo jen pro CI selhání C2 a C3
  (nejvýš 3 iterace). Implementer diagnostikuje příčiny na nejnižších
  verzích (lokálně `composer update --prefer-lowest` ve scratch kopii)
  a na Windows. Kde je chyba v testu, předá ji testerovi. Pak krátká kontrola
  a push. ★
- b) Jiný postup: ___

Odpověď: A

---

## Kolo 13 – zbylá selhání CI (2026-11-16)

Po opravách C2 a C3 prochází 14 z 25 linií (všechny Linux prefer-lowest
i linie s OpenSSL legacy). Zbývá:
- **9 Windows linií:** jeden test (R18 – skrytý dotaz na heslo). Opravuje
  implementer v poslední iteraci kola pro CI (Q48).
- **C4 – PHPStan na Windows (P8.3, Laravel 12, prefer-lowest):** dvakrát po
  sobě `rename(...\testbench-core\laravel\bootstrap\cache\services.php):
  Access is denied`. Paralelní procesy PHPStanu (Larastan v nich startuje
  aplikaci Testbench) na Windows současně přepisují stejný soubor. Nejde
  o chybu kódu balíčku; řeší se nastavením nástroje nebo workflow.
- **C5 – type coverage (P8.3, Laravel 13, prefer-stable, Linux):** proces
  skončil kódem 255 bez výpisu; ostatní linie se stejnými verzemi prošly.
  GitHub opakování jednotlivé linie nepovolil – ověří se dalším pushem.

**Q49 – C4: paralelní PHPStan na Windows.**
- a) Devops upraví workflow: na Windows liniích spustí PHPStan bez
  paralelních procesů (např. `vendor/bin/phpstan analyse --debug`, který
  paralelizaci vypne). Linux zůstává paralelní. `spec/05` R76 se upřesní. ★
- b) Člověk omezí paralelizaci globálně v `phpstan.neon.dist`
  (`parallel: maximumNumberOfProcesses: 1`); zpomalí analýzu všude.

Odpověď: A

---

## Kolo 14 – náhodné selhání type coverage (2026-11-16)

Po mergi PR #4 selhala na `main` jedna linie (P8.4, Laravel 13,
prefer-lowest) v kroku Type Coverage: `pest-plugin-type-coverage` při
paralelním zpracování (Pokio fork) poškodil vlastní cache
`vendor/pestphp/pest-plugin-type-coverage/.temp/v3.php` (`ParseError`,
exit 255). Opakovaný běh prošel. Stejné selhání se dříve objevilo na jiné
linii (P8.3, Laravel 13, stable). Chyba je v cizím pluginu, ne v balíčku.

**Q50 – Jak řešit náhodné pády type coverage?**
- a) Devops upraví krok Type Coverage tak, aby nebyl náchylný na souběh
  (spustit bez paralelizace, pokud to plugin umožňuje, a před během smazat
  `.temp` cache pluginu). Zároveň GitHub issue, aby se úprava vrátila, až
  plugin chybu opraví. ★
- b) Nechat jak je; při náhodném pádu linii ručně spustit znovu.

Odpověď: A

**Q51 – Přejít jen na Pest 5 (a tím zrušit PHP 8.3)?** Pest 5 i
`pest-plugin-type-coverage` 5 vyžadují **PHP ^8.4**. Na PHP 8.3 funguje jen
Pest 4, jehož plugin type coverage (4.0.0–4.0.4) má chybu se zámkem cache
(Q50). Pouze Pest 5 tedy znamená zvednout `"php": "^8.3"` na `"^8.4"`
a vyřadit 8 linií PHP 8.3 z CI (podpůrná matice v AGENTS.md a `spec/05`
R73). Pro hostitele na PHP 8.3 je to **breaking change**. PHP 8.3 má
bezpečnostní podporu do konce roku 2027, produkce EET 2.0 startuje
1. 1. 2027 a část hostitelů na 8.3 ještě bude.
- a) Ponechat PHP 8.3 a Pest 4 i 5; náhodné pády řeší úprava kroku
  Type Coverage z PR #8 (CI tam prošlo ve všech 25 liniích). Zrušení PHP 8.3
  zvážit až po skončení jeho podpory. ★
- b) Zrušit PHP 8.3: `php ^8.4`, Pest jen `^5.0` (+ pest-plugin-laravel
  a type-coverage `^5.0`), matice bez PHP 8.3, úprava z PR #8 se pak může
  vrátit. Mění člověk (`composer.json`, AGENTS.md); architekt upraví
  `spec/05`, devops workflow; zapsat do CHANGELOG jako breaking.

Odpověď: A

---

## Kolo 15 – obnova pokladního certifikátu přes CA EET API (issue #2, 2026-11-16)

Fakta: `spec/01-eet-2-0-fakta.md` kap. 5 a 6, `caeetapi_jwt.yml`. Navazuje
na import a výběr certifikátu ve `spec/11-poplatnik-a-certifikat.md`
(R9–R16, R69, R70). Dokud nejsou odpovědi, architekt nepíše
`spec/12-obnova-certifikatu.md` ani změny v `spec/10-interface.md`.

**Q52 – Které prostředí CA EET API a které certifikáty?** Balíček má dvě
prostředí (playground, produkce), CA EET API tři (test `ca.test.caeet.gov.cz`,
zkušební `ca.zkus.caeet.gov.cz`, produkce `ca.caeet.gov.cz`). Zdroje neříkají,
které API obnovuje playground certifikáty, a prostředí certifikátu balíček
spolehlivě pozná jen podle politiky `…10.1.…` = produkce (R60).
- a) Obnovují se jen produkční certifikáty (politika
  `1.2.203.19122063.10.1.…`) přes produkční API. Playground certifikáty
  (včetně sdílených testovacích ze zipu) se nikdy neobnovují. URL API je
  v configu (`renewal.endpoint`, výchozí produkce) s allowlistem hostitelů
  podle vzoru R66 (výchozí všechny tři hostitele CA EET), proxy se sdílí
  s `proxy`. ★
- b) Podle prostředí poplatníka (R59): produkce → produkční API,
  playground → zkušební API.
- c) Obojí jen podle configu; balíček prostředí certifikátu neřeší.

Odpověď: A (dle doporučení, 2026-11-16)

**Q53 – Jak se obnova spouští?** Balíček zatím sám nic neplánuje (10-interface
kap. 9).
- a) Nový příkaz `eet:certificate:renew {--taxpayer=*} {--certificate=*}
  {--dry-run}`, který hostitel naplánuje (README doporučí denně). Projde
  certifikáty v okně obnovy (Q54) a obnoví je. K tomu facade metoda
  `Eet::renewCertificate($certificate)` pro ruční obnovu jednoho
  certifikátu (i mimo okno). ★
- b) Jako a), ale balíček příkaz sám zaregistruje do scheduleru.
- c) Jen facade metoda; procházení a plánování řeší hostitel.

Odpověď: A (dle doporučení, 2026-11-16)

**Q54 – Kdy je certifikát v okně obnovy?** CA doporučuje 2–3 týdny před koncem
platnosti; obnovovaný certifikát musí být při volání platný.
- a) Config `renewal.days_before_expiry`, výchozí `21`, platné hodnoty
  int 1–365. V okně je certifikát, pro který `validTo − now() ≤ N dní`. ★
- b) Výchozí `14`.
- c) Pevně 21 dní bez configu.

Odpověď: A (dle doporučení, 2026-11-16)

**Q55 – Které certifikáty poplatníka se obnovují?** Poplatník může mít víc
platných certifikátů (R16), některé připnuté, některé s CN ≠ EIČ (R14).
- a) Každý platný a použitelný (R69) certifikát v okně obnovy, který ještě
  nemá nástupce. Nástupce = certifikát uložený obnovou z něj (balíček si
  vazbu předchůdce → nástupce pamatuje). Certifikát importovaný ručně se za
  nástupce nepovažuje. ★
- b) Jen certifikáty, které by se podle R16 právě použily k podpisu
  (připnuté + vybraný certifikát poplatníka).
- c) Jen certifikáty, které hostitel výslovně označí pro automatickou obnovu
  (opt-in, nový sloupec / metoda).

Odpověď: A (dle doporučení, 2026-11-16)

**Q56 – Uložení stavu žádosti a souběh.** Mezi `renew`, vydáním,
`claim-download` a `ack-download` může proces spadnout. Nevyzvednutý
PKCS#12 se po 30 dnech smaže.
- a) Nová tabulka `eet_certificate_renewals` (certifikát, `reqId`, stav,
  důvod chyby, časy). Další běh pokračuje v rozdělané žádosti. Před novou
  žádostí se balíček zeptá `GET /request/not-finished`, aby nevznikla
  duplicitní žádost. Souběh dvou běhů brání cache zámek na certifikát
  (`lock_store`, R43). ★
- b) Bez tabulky; stav se vždy zjistí z `GET /request/not-finished`.

Odpověď: A (dle doporučení, 2026-11-16)

**Q57 – Čekání na vydání (`INPROCESS`).**
- a) Příkaz / facade metoda čeká synchronně podle `pollAfterSeconds`
  (`Retry-After`) až do `renewal.max_wait_seconds` (výchozí `120`). Když
  certifikát do té doby není vydaný, žádost zůstane rozdělaná a dokončí ji
  další běh. ★
- b) Přes frontu: job se znovu zařadí se zpožděním `pollAfterSeconds`.

Odpověď: A (dle doporučení, 2026-11-16)

**Q58 – Stažený PKCS#12 nejde načíst (legacy formát) nebo neprojde
kontrolou.** Zdroje neříkají, zda API vrací PKCS#12 ve stejném legacy
formátu jako DIS+. Bez legacy provideru by import skončil
`UnsupportedLegacyFormat` (R9, R70). PKCS#12 ani heslo se nesmí ukládat do
cache ani logů.
- a) `ack-download` se pošle **až po** úspěšném uložení nového certifikátu
  (commit). Když import selže, ack se nepošle, žádost zůstane `DELIVERING`
  (stáhnout jde znovu až do 30 dnů od vydání), nic se neuloží, vyvolá se
  event selhání a `eet:status` to hlásí. Stažený PKCS#12 se nikam
  neukládá. ★
- b) Jako a), ale balíček před `POST /request/renew` odmítne obnovu, když
  `supportsLegacyPkcs12()` vrací `false`.
- c) Stažený PKCS#12 uložit zašifrovaný encrypterem a import zopakovat
  později (v rozporu s doporučením CA neukládat).

Odpověď: A (dle doporučení, 2026-11-16)

**Q59 – Kontrola nového certifikátu.** Co musí nový certifikát splnit, aby se
uložil (jinak postup podle Q58 a)?
- a) Stejné CN jako obnovovaný certifikát, klíč patří k certifikátu (R10),
  `validTo` je pozdější než u obnovovaného a sériové číslo i platnost
  odpovídají metadatům `certificate` z odpovědi. ★
- b) Jen běžná kontrola importu (R10); CN se nekontroluje.

Odpověď: A (dle doporučení, 2026-11-16)

**Q60 – Připnutí a starý certifikát po obnově.** Připnutý certifikát má
v R16 přednost, takže bez zásahu by se nový certifikát u pokladního zařízení
použil až po vypršení starého.
- a) Ve stejné transakci, ve které se uloží nový certifikát, se připnutí
  všech pokladních zařízení přepne ze starého na nový. Starý certifikát
  zůstane uložený (nic se nemaže); R16 bez připnutí stejně vybere nový
  (pozdější `validTo`). ★
- b) Připnutí se nemění; hostitel ho může přepnout sám po eventu.

Odpověď: A (dle doporučení, 2026-11-16)

**Q61 – Chyby API a opakování.**
- a) Dočasné chyby (síť, timeout, 429, 500) → žádost / pokus zůstane
  a zkusí se při dalším běhu, dokud certifikát platí. Konečné chyby
  (`REJECTED`, 400, 401, 404, 409 mimo očekávaný stav) → event selhání
  s důvodem a automatika pro tento certifikát se zastaví. Znovu jen ručně
  (`eet:certificate:renew --certificate=ID` nebo facade metoda). ★
- b) Konečné chyby také zkoušet znovu při každém běhu.

Odpověď: A (dle doporučení, 2026-11-16)

**Q62 – Eventy a hlášení.**
- a) Eventy `CertificateRenewed` (starý + nový certifikát)
  a `CertificateRenewalFailed` (certifikát + důvod jako enum
  `RenewalProblem`), vyvolané po commitu. `eet:status` navíc skončí kódem 1,
  když některý certifikát vyprší do `renewal.days_before_expiry` dní a nemá
  nástupce, nebo jeho obnova skončila konečnou chybou. ★
- b) Jen eventy, `eet:status` se nemění.

Odpověď: A (dle doporučení, 2026-11-16)

**Q63 – Parametry JWT.** Zdroje: RS256, `x5t#S256`, `iat` a `exp`,
`exp − iat ≤ 5 min`; OpenAPI připouští i prázdný payload, Postupy CA
vyžadují `iat`/`exp`.
- a) Nový token pro každé volání API, hlavička `{"alg":"RS256","typ":"JWT",
  "x5t#S256":…}`, payload jen `{"iat": now, "exp": now + 60}`. Bez nové
  Composer závislosti (podpis přes `ext-openssl`). ★
- b) `exp = now + 300` (maximum), jinak jako a).

Odpověď: A (dle doporučení, 2026-11-16)

**Q64 – Ověřování bez GitHub Actions.** Člověk 2026-11-16 vypnul Actions
kvůli spotřebě. DoD (AGENTS.md bod 1) a `spec/05` počítají s 25 liniemi CI;
lokálně jde ověřit jen jedna verze PHP na macOS.
- a) Během práce ověřovat lokálně (`composer test`, navíc
  `composer update --prefer-lowest` v dočasné kopii). Před mergem PR člověk
  Actions jednorázově zapne pro jeden běh celé matice. ★
- b) Ověřovat jen lokálně a mergovat bez CI; Windows a další verze se
  neověří.

Odpověď: A (dle doporučení, 2026-11-16)

---

## Kolo 16 – doplňující otázky k obnově certifikátu (2026-11-16)

Vyplynuly při psaní `spec/12-obnova-certifikatu.md`. Spec je napsaná podle
★; tester začne až po odpovědích.

**Q65 – Neznámá neukončená žádost v CA.** `GET /request/not-finished`
vrací všechny neukončené žádosti pro EIČ, i ty, které balíček nezná: podal
je jiný systém poplatníka, člověk v DIS+, nebo balíček sám, když spadl mezi
`POST /request/renew` a uložením `reqId`. Ze stavu žádosti nejde poznat, ke
kterému certifikátu patří.
- a) Novou žádost nepodat, obnova zůstane `Requesting` s dočasným
  problémem `UnknownPendingRequest` (s `reqId`), `eet:status` skončí kódem 1.
  Vyřeší člověk (dokončí žádost v DIS+, nebo počká na její ukončení). Cizí
  žádost se nikdy nepřevezme, aby ji balíček nesebral jinému systému. ★
- b) Neznámou žádost převzít, když je právě jedna a lokální obnova je
  `Requesting` po dočasném problému (pravděpodobně vlastní po pádu).
- c) Neznámé žádosti ignorovat a podat novou (riziko dvou certifikátů).

Odpověď: A

**Q66 – Timeout jednoho volání CA EET API.** Zdroje ho neuvádějí;
`response_timeout_ms` (4 s) patří k evidenci tržeb.
- a) Nový config `renewal.timeout_ms`, výchozí `10000`, platné hodnoty
  int ≥ 1000. ★
- b) Použít `response_timeout_ms`.

Odpověď: A

---

## Kolo 17 – nejasnosti z testů obnovy certifikátu (2026-11-16)

Tester našel místa, která `spec/12` nechává otevřená. Upřesnění bez
nového chování (R90 jen pro novou obnovu, `request_id` bez unique, formát
výstupu, `problem_detail` u PKCS#12 bez klíče) doplní architekt přímo do
spec. Rozhodnutí o chování:

**Q67 – `ack-download` vrátí 400 nebo 401.** Nástupce je v té chvíli už
uložený a pokladny přepnuté (R96). Doslovné čtení R101 dává `Failed`, takže
by certifikát ztratil nástupce (`successor()` počítá jen `Acknowledging`
a `Completed`) a mohl se obnovovat znovu.
- a) Konečný problém v `Acknowledging` → `Completed` s
  `acknowledged_at = null` a vyplněným `problem` (`RequestFailed`)
  a `problem_detail`; `CertificateRenewalFailed` se nevyvolá. Nástupce zůstává
  platný. ★
- b) `Failed` podle doslovného R101 (nástupce zůstane uložený, ale nebude
  nástupcem).

Odpověď: A (2026-11-16)

**Q68 – Exit kód `eet:certificate:renew` v okrajových případech.**
- a) Obsazený zámek („běží jinde“) a `--dry-run` → kód 0 (nic neselhalo);
  neočekávaná výjimka u jednoho certifikátu se vypíše (bez citlivých
  údajů), ostatní certifikáty se zpracují a příkaz skončí kódem 1. ★
- b) Obsazený zámek → kód 1 (monitoring ho uvidí).

Odpověď: A (2026-11-16)

---

## Kolo 18 – po implementaci obnovy certifikátu (2026-11-17)

**Q69 – Obnova v `Acknowledging`, když klíč starého certifikátu nejde
dešifrovat** (např. po změně `APP_KEY`). Nástupce je uložený a pokladny
přepnuté. Ack ale nejde podepsat. R97 řeší jen vypršení starého certifikátu,
R98 se na `Acknowledging` nevztahuje. Implementer zatím nechal obnovu
v `Acknowledging` s dočasným problémem `TemporaryError`
(`certificate_unusable`) do vypršení starého certifikátu. `eet:status` to
ale nehlásí (R106 hlásí jen `Failed` a `UnknownPendingRequest`).
- a) Stejně jako u vypršení: ack se nepošle a obnova přejde do `Completed`
  s `acknowledged_at = null` a `problem = CertificateUnusable`. Nástupce
  zůstává a CA data stejně smaže po 30 dnech. ★
- b) Ponechat chování implementace (dočasný problém do vypršení starého
  certifikátu).

Odpověď: A (2026-11-17)

---

## Kolo 19 – po revizi obnovy certifikátu (reviewer + security, 2026-11-17)

`composer test` 901/901. Reviewer: nic blokujícího, chyba F1 + upřesnění.
Security: jeden vysoký a jeden střední nález, zbytek zpevnění.

**Q70 – (security, vysoký) HTTP eventy prozradí PKCS#12 a heslo.**
`Http::withoutGlobalConfiguration()` vypne jen globální middleware, ale
Laravel dál vyvolává eventy `RequestSending` (s JWT) a `ResponseReceived`
(s celou odpovědí `claim-download`, tj. base64 PKCS#12 a heslem). Telescope
nebo jiný logger hostitele by je uložil, což porušuje R103. U odesílání tržeb
jsou eventy záměrně povolené (R66, Q43), protože v nich nic tajného není.
- a) Volání CA EET API se posílají **bez HTTP eventů**; `Http::fake()`
  (stuby, záznam, `preventStrayRequests`) dál platí. Test: listener na
  `ResponseReceived`/`RequestSending` nic z volání CA neuvidí. ★
- b) Eventy ponechat jen pro volání bez tajných dat (vše kromě
  `claim-download`).

Odpověď: A (2026-11-17)

**Q71 – (security, střední) Kontrola vydavatele nástupce.** R95 kontroluje
CN, `validTo` a shodu s metadaty ze **stejné** odpovědi. Podvržená odpověď
(kompromitovaný endpoint, zachycené TLS) by mohla podstrčit self-signed
certifikát se správným CN a R96 by na něj přepnul pokladny.
- a) Nástupce musí navíc mít produkční politiku (`…10.1.…`), stejný DN
  vydavatele a stejný identifikátor klíče autority (AKI) jako obnovovaný
  certifikát. Pokud je ve uložené certifikační cestě obnovovaného
  certifikátu certifikát vydavatele, musí podpis nástupce ověřit. Jinak
  `CertificateMismatch`. ★
- b) Jen produkční politika a DN vydavatele.
- c) Nic navíc (spolehnout se na TLS).

Odpověď: A (2026-11-17)

**Q72 – Zpevnění z revize (security, nízké).**
- a) Vše dle návrhu: ★
  1. TTL zámku R104 = `max_wait_seconds` + 5 × `renewal.timeout_ms` + 60 s
     a přechody stavu obnovy se zapisují jen ze stavu, ze kterého krok
     vycházel (souběžný zápis neprepíše `Acknowledging` na `Failed`);
  2. příkaz u neočekávané výjimky vypíše zprávu jen u výjimek balíčku,
     u ostatních (např. `QueryException` s bindingy) jen třídu;
  3. `problem_detail` se před uložením zbaví řídicích znaků (C0, C1, DEL);
  4. `reqId` v odpovědi `status` se musí shodovat s `request_id` obnovy,
     jinak `TemporaryError`;
  5. eventy `CertificateRenewed` a `CertificateRenewalFailed` používají
     `SerializesModels`.
- b) Jen body: ___

Odpověď: A (2026-11-17)

**Q73 – Upřesnění z revize (reviewer).**
- a) Vše dle návrhu: ★
  1. (F1, chyba) přechod do `Completed` po vypršení starého certifikátu
     vynuluje `problem` a `problem_detail` (předchozí dočasný problém
     nezůstane viset); výjimkou je jen `CertificateUnusable` (Q69)
     a `RequestFailed` (Q67);
  2. R93 doplnit chování implementace: `reqId` z `not-finished`, který patří
     ne-`Failed` obnově téhož certifikátu, se ignoruje; převzetí `reqId`
     z dřívější `Failed` obnovy má přednost před neznámými `reqId` ve stejné
     odpovědi;
  3. R101: **jakýkoli** HTTP status mimo dokumentovaný úspěch (renew 201,
     ack 204, ostatní 200) a mimo 400/401/404/409 je `TemporaryError`;
  4. `--dry-run` vyhodnotí R90 a certifikát, který obnovit nejde, vypíše
     jako „nelze obnovit“ (kód 1 stejně jako ostrý běh); `--certificate`
     s `--taxpayer` vypíše u certifikátu jiného poplatníka „nepatří
     zadanému poplatníkovi“ (kód 1);
  5. test na import `.p12` bez klíče (`MissingPrivateKey` místo dřívějšího
     `Unreadable`) a řádek do CHANGELOG (zapíše člověk).

Odpověď: A (2026-11-17)

---

## Kolo 20 – po kontrolní revizi obnovy (reviewer + security, 2026-11-17)

`composer test` 930/930, lokálně i s nejnižšími závislostmi (Laravel 12.71,
Testbench 10.2, Pest 4.6; PHP 8.5). Opravy bez nového rozhodnutí (spadají pod
R94, R96, R104): ošetření selhání commitu ruční transakce, přetečení
obřího `pollAfterSeconds`, souběžný insert nástupce (unique) → krok se
zahodí, přesnější hledání OID politiky, doplnění testů (TTL zámku,
souběh v kroku stažení, text u `--taxpayer`, hlídání privátních vlastností
`PendingRequest`).

**Q74 – Nástupce, když v uložené cestě chybí vydavatel.** Q71 ověřuje
podpis nástupce jen tehdy, když uložená certifikační cesta obnovovaného
certifikátu obsahuje jeho vydavatele. Jinak zbývají jen DN vydavatele, AKI
a OID politiky, a ty jde zkopírovat. Podvržená odpověď by tak prošla
a pokladny by se přepnuly na nepoužitelný certifikát (tržby by EET
odmítal; klíč by ale nebyl kompromitován). PKCS#12 z DIS+ cestu obsahuje.
U PEM importu bez cesty chybí.
- a) Fail closed: když v uložené cestě není certifikát se subjektem rovným
  DN vydavatele obnovovaného certifikátu, je to `CertificateMismatch`
  (a `eet:status` to nahlásí). Certifikát vydavatele se hledá podle DN
  a ověřuje se podpisem, ne jen podle SKI/AKI. README: importovat
  s certifikační cestou. ★
- b) Ponechat Q71: bez vydavatele v cestě se podpis neověřuje.
- c) Přibalit a připnout certifikáty CA EET (EETv2 NCA SubCA RSA) jako
  kotvu důvěry; zdroj certifikátů by ale musel povolit člověk (B2).

Odpověď: A (2026-11-17)

**Q75 – (security, nízké, follow-up) Šifrovaný klíč v `QueryException`.**
Když uložení certifikátu selže jinou chybou DB než duplicitou, Laravel vloží
do zprávy `QueryException` SQL s bindingy, tedy i PEM a **zašifrovaný**
`private_key`. Výjimka z `Eet::importCertificate()` i
`Eet::renewCertificate()` tak doputuje do logu hostitele. Na `main` se
`ImportCertificate` chová stejně, takže nejde o regresi. Doslova to R15
neporušuje (nejde o čistý klíč), ale log spolu s uniklým `APP_KEY` dává
klíč v otevřené podobě.
- a) R15 platí i pro šifrovaný klíč: v obou místech zápisu certifikátu se
  `QueryException` znovu vyhodí bez bindingů (stejné spojení a SQL,
  původní výjimka jako `previous`). Veřejný kontrakt se nemění. Udělat
  v tomto PR. ★
- b) Totéž, ale jako samostatné GitHub issue po mergi.
- c) Ponechat; šifrovaný text R15 nepokrývá.

Odpověď: A (2026-11-17). Upřesnění architekta: jako `previous` se předá výjimka ovladače (PDO), ne původní `QueryException`, protože Laravel skládá zprávu z `previous` a bindingy by se vrátily.

Follow-up po závěrečné revizi (security, nízké, není regrese; navrženo jako
GitHub issue): na PostgreSQL přidá PDO u porušení NOT NULL / CHECK (SQLSTATE
23502, 23514) do zprávy `DETAIL: Failing row contains (…)` se všemi
hodnotami řádku včetně PEM a šifrovaného klíče. Vlastní schéma balíčku to
nevyvolá, nastane to jen po úpravě schématu hostitelem. Návrh: `QueryErrors`
u SQLSTATE třídy 23 nahradí výjimku ovladače očištěnou `PDOException`
(jen SQLSTATE a první řádek zprávy).

## Kolo 21 – odstranění stropu PHPStanu (issue #6, 2026-11-17)

**Q76 – Strop `phpstan/phpstan <2.3.6` nejde odstranit pro linie s Pest 4.**
Plugin `pestphp/pest-plugin-type-coverage` opravil nekompatibilitu
s PHPStan 2.3.6 (Q47) jen v řadě 5.x (v5.1.1). Nejnovější 4.x (v4.0.4) dál
padá na `AnalyserResult::getCollectedData()`. Ověřeno lokálně bez stropu:
Laravel 12 / Testbench 10 prefer-stable (Pest 4.7.8, plugin 4.0.4,
PHPStan 2.3.6) → type coverage padá; prefer-lowest (PHPStan 2.1.32) a Pest 5
(plugin 5.1.1, PHPStan 2.3.6) projdou. Podmínku „PHPStan <2.3.6 jen
s pluginem 4.x“ `composer.json` vyjádřit neumí. `composer.json` mění jen
člověk, workflow devops.
- a) Strop v `composer.json` ponechat a issue #6 nechat otevřené, dokud
  plugin nevydá opravu i v řadě 4.x (nebo dokud matice nepřestane
  podporovat Pest 4). Nic se nemění. ★
- b) Strop z `composer.json` odstranit a v `tests.yml` u linií s Pest 4
  přidat `composer require "phpstan/phpstan:<2.3.6" --no-update` (devops).
  Pest 5 linie dostanou aktuální PHPStan; workflow nese výjimku navíc.
- c) Zvednout spodní hranici pluginu na `^5.1.1`, tedy fakticky ukončit
  podporu Pest 4 (mění podporovanou matici v `spec/05-infrastructure.md`,
  breaking pro vývoj, ne pro hostitele).

Odpověď: A (2026-11-17)

Doplnění k Q64 (2026-11-17): PR #9 (obnova certifikátu) člověk mergnul bez
běhu CI matice (odchylka → b pro tento PR). PHP 8.3 a Windows zůstávají
neověřené do příštího běhu CI.
