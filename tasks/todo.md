# Tasks: SereniSoft ATUM Enhancer

## Status
- Start dato: 2025-12-08
- Fase: Grunnstruktur opprettet

---

## Fullførte Oppgaver

### Fase 0: Dokumentasjon
- [x] Opprett CLAUDE.md med prosjektinformasjon
- [x] Legg til flerspråkstøtte-dokumentasjon (WP CLI)

### Fase 1: Grunnstruktur ✅
- [x] Opprett composer.json med PSR-4 autoloading
- [x] Opprett serenisoft-atum-enhancer.php hovedfil
- [x] Opprett classes/Bootstrap.php med singleton pattern
- [x] Opprett languages/.gitkeep
- [x] Kjør composer dump-autoload

---

## Neste Faser (TODO)

### Fase 2: Settings
- [ ] Integrer med ATUM Settings
- [ ] Legg til settings for admin e-post
- [ ] Legg til settings for bestillingsfrekvens per leverandør

### Fase 3: Leverandørimport
- [ ] CSV-import funksjonalitet
- [ ] Mapping til ATUM leverandørregister
- [ ] Duplikatsjekk (leverandørnummer/navn)

### Fase 4: PO-algoritme
- [ ] Analyser lagerstatus
- [ ] Beregn basert på lead time
- [ ] Sesonganalyse fra salgshistorikk
- [ ] Smart bunting av produkter fra samme leverandør

### Fase 5: Automatisering
- [ ] Scheduled task for automatisk kjøring
- [ ] E-postvarsler ved nye forslag
- [ ] Manuell trigger-knapp i UI

---

## Fremdriftslogg

| Dato | Oppgave | Status |
|------|---------|--------|
| 2025-12-08 | Opprettet CLAUDE.md | Fullført |
| 2025-12-08 | Opprettet grunnstruktur | Fullført |

---

## Review: Fase 1 - Grunnstruktur

### Filer opprettet:
1. `composer.json` - PSR-4 autoloading med namespace `SereniSoft\AtumEnhancer\`
2. `serenisoft-atum-enhancer.php` - Hovedfil med plugin header og konstanter
3. `classes/Bootstrap.php` - Singleton med dependency-sjekk og HPOS-kompatibilitet
4. `languages/.gitkeep` - Placeholder for oversettelser
5. `vendor/autoload.php` - Generert av Composer

### Mønstre implementert:
- WordPress plugin header med `Requires Plugins`
- Konstanter med SAE_ prefix
- Singleton Bootstrap pattern (fra ATUM)
- Dependency checking for WooCommerce og ATUM
- HPOS-kompatibilitet deklarert
- Tekstdomene lastet for i18n

### Notater:
- Plugin er nå aktiverbar i WordPress
- Viser feilmeldinger hvis WooCommerce eller ATUM mangler
- Klar for neste fase: Settings-integrasjon
