# NN – <Název funkce>

> Stav: návrh | schváleno · Verze: 0.1 · Navazuje na: `spec/00-brief.md`, `spec/10-interface.md`

## Účel

Jedna až tři věty: jaký problém funkce řeší a pro koho (hostitelská aplikace,
obsluha pokladny, …).

## Pojmy

Jen pojmy, které nejsou v `spec/02-glossary.md` nebo mají v této funkci
zúžený význam.

## Pravidla

Každé pravidlo je číslované (`R1`, `R2`, …), testovatelné a popisuje
**chování**, ne implementaci. U pravidel převzatých z dokumentace MF ČR uveďte
zdroj (`[zdroj: popis rozhraní v1.2, 3.3.3.8]`).

- **R1** – …
- **R2** – …

## Hraniční a souběžné případy

| Situace | Očekávané chování | Pravidlo |
|---|---|---|
| … | … | R… |

## Stavový diagram

```mermaid
stateDiagram-v2
    [*] --> ...
```

## Datový model (ER)

```mermaid
erDiagram
    ...
```

## Veřejný kontrakt

Odkaz na dotčené části `spec/10-interface.md` (metody, eventy, příkazy,
konfigurace, tabulky, překlady). Změny, které rozbijí hostitelské aplikace,
označte **BREAKING**.

## Akceptační kritéria

Každé kritérium jde přímo převést na test a odkazuje na pravidlo.

- [ ] Daný … když … pak … `[R1]`

## Mimo rozsah

Musí být neprázdné.

- …
