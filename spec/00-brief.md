# 00 – Zadání (brief)

## Zadání od člověka (2026-11-15)

> Vytvoř spec pro tento balíček – EET modul, který se bude používat v pokladních
> a fakturačních systémech podle specifikace v `spec/zadani/mfcr`. Kdyby
> cokoliv chybělo, dohledat můžeš na
> <https://eet.gov.cz/cs/o-eet/dokumenty-k-eet-2-0?category=provyvojare>.
> Výslovně zakázáno je sbírat informace z jiných zdrojů než na uvedené stránce.
> Dej si pozor na verzi EET. My používáme EET 2.0.

## Rámující rozhodnutí

| # | Rozhodnutí | Zdroj |
|---|---|---|
| B1 | Balíček implementuje **EET 2.0**, tj. rozhraní **verze 4.x** (aktuálně 4.1), jmenný prostor `http://fs.gov.cz/eet/schema/v4`. Rozhraní EET 1 (v3: PKP, BKP, DIČ, rozpis DPH) se nepodporuje a jeho pojmy se nepoužívají. | zadání; popis rozhraní v1.2, kap. 1.1 |
| B2 | Jediné přípustné zdroje faktů: dokumenty v `spec/zadani/mfcr/` a dokumenty na uvedené stránce eet.gov.cz. Od 2026-11-15 také <https://www.ica.cz> (povolil člověk; certifikáty CA pro ověření podpisu odpovědi). Co v nich není, jde do `spec/99-open-questions.md`. | zadání |
| B3 | Balíček je knihovna pro hostitelské Laravel aplikace (pokladní a fakturační systémy); nemá vlastní UI pro koncového zákazníka. | `AGENTS.md` |

## Použité zdroje

Lokální (`spec/zadani/mfcr/`):

| Soubor | Obsah | Verze |
|---|---|---|
| `EET_popis_rozhrani_v1.2.pdf` | Formát a struktura údajů o evidované tržbě a popis datového rozhraní | 1.2, 25. 8. 2026 |
| `EET_popis_polozek_datove_zpravy.pdf` | Věcný popis položek datové zprávy s příklady | 1.0, 5. 10. 2026 |
| `EETXMLSchema.xsd`, `EETServiceSOAP.wsdl` | Formální definice zprávy a služby | 4.1 |
| `caeetapi_jwt.zip` (`caeetapi_jwt.yml`) | OpenAPI CA EET pro automatizovanou obnovu pokladního certifikátu | 1.0.0 |
| `CAEET_Playground_2026_v1.zip` | Testovací pokladní certifikáty (3 EIČ) a certifikáty CA pro playground | 2026 v1 |
| `import_evidencnich_jednotek-popis_strukt.pdf` | CSV import evidenčních jednotek do DIS+ | 3. 11. 2026 |

Z webu (eet.gov.cz – stažené 2026-11-15, nejsou v repozitáři; ica.cz – uložené v `spec/zadani/ica/`):

| Dokument | Verze |
|---|---|
| EET 2.0 – Neprodukční prostředí (playground), Přístupové a provozní informace (označeno **TLP:AMBER**) | 1.1, 30. 6. 2026 |
| EET 2.0 – Produkční prostředí, Přístupové a provozní informace | 1.1, 10. 7. 2026 |
| CA EET v 2.0 – Postupy získání pokladního certifikátu | v2, 22. 6. 2026 |
| Vzorová datová zpráva `CZ00000019.eet.v4.req.xml` | – |
| I.CA – kořenové certifikáty (`spec/zadani/ica/`) | stáhnuto 2026-11-15 |
| Skutečná zpráva a podepsaná odpověď z playgroundu (`spec/zadani/pg-vzorek/`, Q28 → A, sdílený testovací certifikát CZ00000019) | 2026-11-15 |

Výtah závazných faktů z těchto zdrojů je v [`01-eet-2-0-fakta.md`](01-eet-2-0-fakta.md).
