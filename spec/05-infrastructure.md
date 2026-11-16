# 05 – Infrastruktura: podpůrná matice, CI a vydávání

> Stav: návrh · Verze: 0.4 · Vstup pro roli **devops** (`agents/devops.md`).
> Navazuje na: `AGENTS.md` (Technical Rules), `composer.json`,
> `spec/10-interface.md`, `spec/11-*.md`, `spec/20-*.md`. Odpovídá na N4
> v `spec/99-open-questions.md`.

## Účel

Popisuje, jak se balíček ověřuje v CI a jak se vydává. Balíček se distribuuje
jen přes Packagist; nemá kontejnery, deployment ani běhové prostředí. Pravidla
navazují na čísla `R1–R72` z feature specifikací a pokračují od `R73`.

## Výchozí stav (2026-11-15)

Ze scaffoldu už existují `.github/workflows/tests.yml`,
`.github/workflows/update-changelog.yml`, `.github/dependabot.yml`
a `.github/release.yml`. Tato specifikace je jejich kontrakt. Devops je
upraví tak, aby splňovaly pravidla níže, a nic dalšího nepřidává.

## Pravidla

### Podpůrná matice

- **R73** – Workflow `tests` spouští matici, která přesně odpovídá
  `composer.json` a `AGENTS.md`:

| Osa | Hodnoty |
|---|---|
| OS | `ubuntu-latest`, `windows-latest` |
| PHP | 8.3, 8.4, 8.5 |
| Laravel (`illuminate/*`) | 12.*, 13.* |
| Testbench | 10.* (k Laravelu 12), 11.* (k Laravelu 13) |
| Stabilita závislostí | `prefer-lowest`, `prefer-stable` |

  Verze, kterou `composer.json` povoluje, se testuje; testovaná verze musí
  být povolená. Když člověk změní constraint v `composer.json`, devops
  matici sladí. K těmto 24 liniím přibude **25. linie** podle R79. Pest 4/5
  a Guzzle 7/8 nejsou samostatné osy: verze vyplynou z resolveru (Pest 4
  na PHP 8.3, Pest 5 na 8.4+; Guzzle 8 v liniích `prefer-stable`). Takové
  pokrytí je dostačující.
- **R74** – Linie `prefer-lowest` musí skutečně nainstalovat nejnižší
  povolené verze runtime závislostí z `composer.json`: `guzzlehttp/guzzle`
  `^7.15.2||^8.0.1` a `illuminate/*` `^12.71||^13.40` (člověk je 2026-11-15
  zvedl na nejnižší verze bez bezpečnostního advisory, protože Composer ≥ 2.9
  starší verze ve výchozím stavu neinstaluje). Každá linie po instalaci
  vypíše nainstalované verze (`composer show` pro `laravel/framework`,
  `guzzlehttp/guzzle`, `orchestra/testbench`, `pestphp/pest`). Když nejnižší
  kombinace nejde nainstalovat, linie selže s chybou Composeru. Constraint
  ani `audit.block-insecure` se ve workflow nikdy neobchází.
- **R75** – PHP se instaluje s rozšířeními, která balíček a testy potřebují:
  `dom`, `libxml`, `openssl`, `curl`, `mbstring`, `sqlite`, `pdo_sqlite`
  (a ta, která už workflow uvádí). `openssl` musí být uvedené výslovně,
  protože ho vyžaduje `composer.json` (`ext-openssl`).

### Kroky v každé linii

- **R76** – Každá linie spustí v tomto pořadí:
  1. instalaci závislostí,
  2. PHPStan (`composer analyse`),
  3. kontrolu Pint (`composer lint:check`),
  4. type coverage (`composer test:types`, jen Linux),
  5. Pest (`composer test:unit` na Linuxu, `vendor/bin/pest` na Windows).

  Selhání kteréhokoli kroku je selháním linie; žádný krok nemá
  `continue-on-error` ani `|| true`. Na Windows se PHPStan spouští **bez
  paralelních procesů** (např. `vendor/bin/phpstan analyse --debug`), protože
  paralelní procesy Larastanu tam současně přepisují soubor
  `bootstrap/cache/services.php` v Testbench a analýza padá na `Access is
  denied` (Q49 → A). Na Linuxu zůstává analýza paralelní. Matice má `fail-fast: false`, takže
  vždy doběhnou všechny linie.
- **R77** – Testy v CI **nepřistupují k síti** mimo instalaci závislostí:
  - žádné volání EET (playground ani produkce) ani CA EET;
  - veškerá komunikace jde přes `Http::fake()` nebo `Eet::fake()`
    (R32, R57);
  - workflow nepotřebuje žádné secrets ani certifikáty;
  - testovací certifikáty jsou fixtures v `tests/`, které tam převedl
    tester (playground PEM, vlastní testovací CA).
  - smyčka `127.0.0.1` **není síťový přístup**: integrační testy transportu
    smí spustit lokální HTTP server (PHP built-in server spuštěný testem) na
    `127.0.0.1` a posílat na něj skutečné požadavky přes cURL handler bez
    `Http::fake()` (Q42 → A). Jen tak se ověří volby Guzzle na skutečném
    handleru.
- **R78** – Testy nepotřebují žádnou externí službu:
  - databáze je SQLite (Testbench);
  - cache a zámek používají `array` a fronta `sync`, což R43 a R40 v testech
    povolují (`runningUnitTests()`, Q35);
  - Redis, MySQL ani jiný kontejner se nespouští.
- **R79** – Pokrytí obou větví R70 (`Eet::supportsLegacyPkcs12()`):
  - aspoň jedna linie běží s OpenSSL **bez** legacy provideru
    (výchozí stav `ubuntu-latest`), kde import legacy `.p12` končí
    `UnsupportedLegacyFormat`;
  - právě jedna **samostatná 25. linie** (`ubuntu-latest`, PHP 8.5,
    Laravel 13.*, `prefer-stable`, příznak `openssl-legacy` v názvu) běží
    s legacy providerem povoleným přes `OPENSSL_CONF` s aktivním `default`
    i `legacy` providerem, kde import projde. Stejná kombinace bez příznaku
    běží dál jako běžná linie;
  - každá linie na Linuxu obsahuje **sondu**: vytvoří legacy `.p12`
    (`openssl pkcs12 -export -legacy`) a ověří přes PHP
    `openssl_pkcs12_read`, že výsledek odpovídá očekávání linie (`true`
    v legacy linii, `false` jinde). Při neshodě linie selže. Sonda je
    důkazem splnění R79, protože test R70 v Pestu větví podle
    `supportsLegacyPkcs12()` a z výsledku nejde poznat, která větev běžela;
  - konfigurační soubor OpenSSL vznikne v kroku workflow, ne v repozitáři.
    Na Windows se chování neovlivňuje, testy podle R70 projdou v obou
    větvích.
- **R80** – Výsledky testů nesmí záviset na datu spuštění. CI spoléhá na
  výchozí časovou zónu runneru (UTC) a nic nenastavuje; testy si čas mrazí
  samy (AC v `spec/20`). Ve workflow se nemění systémový čas.

### Spouštění, oprávnění a bezpečnost workflow

- **R81** – Workflow `tests` se spouští na `push` do `main` a `*.x`
  a na každý `pull_request`. Souběžné běhy téže reference se ruší
  (`concurrency` s `cancel-in-progress`).
- **R82** – Každý workflow deklaruje minimální `permissions`:
  - `tests` má jen `contents: read`;
  - `contents: write` má jen job, který zapisuje `CHANGELOG.md`.
- **R83** – Všechny akce jsou připnuté na plný commit SHA s verzí
  v komentáři. Každý job má `timeout-minutes`.
- **R84** – Workflow i inline shell projdou `actionlint` a `shellcheck`
  (z oficiálních kontejnerových obrazů, ne jako závislost projektu).

### Vydávání

- **R85** – Tagy a GitHub release vytváří **jen člověk**; nic se
  nepublikuje automaticky. Packagist se aktualizuje z tagu.
- **R86** – Workflow `Update Changelog` reaguje na `release: released`.
  Do `CHANGELOG.md` na `main` zapíše poznámky k vydání. Nic jiného nemění.
- **R87** – Kategorie poznámek k vydání (`.github/release.yml`) odpovídají
  štítkům repozitáře:
  - `breaking` (Breaking Changes), `enhancement`, `bug`, `documentation`,
    `dependencies`, `maintenance`;
  - ostatní spadnou do „Other Changes“;
  - `skip-changelog` a `duplicate` se vynechávají.

  Změna veřejného kontraktu (`spec/10-interface.md`, BREAKING) se do vydání
  dostane přes štítek `breaking`.
- **R88** – Distribuční archiv neobsahuje `spec/`, `tests/`, `workbench/`
  ani `.github/`. Zajišťuje to `.gitattributes` (`export-ignore`, mění jen
  člověk). Devops to jednorázově ověří (`git archive` a výpis obsahu),
  protože `spec/zadani/` obsahuje sdílené playground `.p12` a heslo.

### Dependabot

- **R89** – Dependabot jednou týdně kontroluje ekosystémy
  `github-actions` a `composer` a přidává štítek `dependencies`. Pull
  requesty Dependabotu procházejí stejnou maticí R73. Nová runtime
  závislost se nikdy neslučuje bez schválení člověkem (`AGENTS.md`).

## Hraniční případy

| Situace | Očekávané chování | Pravidlo |
|---|---|---|
| Laravel 12 + `prefer-lowest` + PHP 8.3 | nainstaluje Guzzle 7.15.2 a `illuminate/*` 12.71 (případně vyšší, pokud ho vynutí dev závislosti); výpis verzí je v logu | R73, R74 |
| Nová minor verze PHP (např. 8.6) | do matice se přidá až po změně `composer.json` člověkem | R73 |
| Test omylem volá síť | selže, protože `Http::fake()` s `preventStrayRequests()` (doporučení pro testera) nebo nedostupný EET | R77 |
| Linie bez legacy provideru | testy R70 ověří větev `UnsupportedLegacyFormat` | R79 |
| Dependabot navrhne Guzzle 9 | PR selže na constraintu, nebo ho člověk odmítne | R89 |

## Akceptační kritéria

- [ ] `tests.yml` obsahuje matici přesně podle R73 (24 linií + 25. legacy linie) s `fail-fast: false` a název linie obsahuje PHP, Laravel, stabilitu a OS. `[R73, R76]`
- [ ] Každá linie vypíše nainstalované verze; linie `prefer-lowest` s Laravelem 12 ukáže `guzzlehttp/guzzle` 7.15.x a `laravel/framework` 12.x ≥ 12.71. Workflow nenastavuje `audit.block-insecure`. `[R74]`
- [ ] Krok `setup-php` uvádí `openssl`, `dom`, `libxml`, `sqlite` a `pdo_sqlite`. `[R75]`
- [ ] Každá linie spouští kroky R76 v daném pořadí, bez `continue-on-error`. `[R76]`
- [ ] Workflow nepoužívá žádné `secrets.*` kromě `GITHUB_TOKEN` u changelogu a nemá žádné `services:`. `[R77, R78]`
- [ ] Existuje právě jedna (25.) linie s `OPENSSL_CONF` (legacy + default provider). Sonda v ní potvrdí `openssl_pkcs12_read` = `true` a v ostatních linkách na Linuxu `false`; neshoda linii shodí. `[R79]`
- [ ] Workflow nemění systémový čas ani časovou zónu. `[R80]`
- [ ] Spouštěče a `concurrency` odpovídají R81. `[R81]`
- [ ] `permissions` odpovídají R82; každý job má `timeout-minutes`; všechny akce jsou připnuté na SHA. `[R82, R83]`
- [ ] `actionlint` a `shellcheck` nic nehlásí. `[R84]`
- [ ] Žádný workflow nevytváří tag ani release a nepublikuje na Packagist. `[R85]`
- [ ] `update-changelog.yml` se spouští jen na `release: released` a mění jen `CHANGELOG.md`. `[R86]`
- [ ] `release.yml` obsahuje kategorie a vyloučení podle R87. `[R87]`
- [ ] `git archive HEAD` neobsahuje `spec/`, `tests/`, `workbench/` ani `.github/`. `[R88]`
- [ ] `dependabot.yml` odpovídá R89. `[R89]`
- [ ] `composer test` lokálně dál prochází. `[R76]`

## Mimo rozsah

- Deployment, kontejnerové obrazy, služby (Redis, MySQL) a měření výkonu.
- Testy proti skutečnému EET playgroundu nebo CA EET v CI. Ruční ověření na
  playgroundu dělá jen člověk nebo architekt se souhlasem (jako u Q28).
- Automatické vydávání, tagování a publikování na Packagist.
- Úpravy `composer.json`, `.gitattributes`, `phpunit.xml.dist` a dalšího
  tooling configu; ty mění jen člověk (devops potřebu jen nahlásí).
- Badge v `README.md` (mění implementer).
