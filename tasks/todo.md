# Tasks: SereniSoft ATUM Enhancer

## Status
- Start dato: 2025-12-08
- Fase: Fase 4 fullført - PO-algoritme implementert

---

## Fullførte Oppgaver

### Fase 0: Dokumentasjon ✅
- [x] Opprett CLAUDE.md med prosjektinformasjon
- [x] Legg til flerspråkstøtte-dokumentasjon (WP CLI)

### Fase 1: Grunnstruktur ✅
- [x] Opprett composer.json med PSR-4 autoloading
- [x] Opprett serenisoft-atum-enhancer.php hovedfil
- [x] Opprett classes/Bootstrap.php med singleton pattern
- [x] Opprett languages/.gitkeep
- [x] Kjør composer dump-autoload
- [x] Opprett Git repository og push til GitHub

### Fase 2: Settings ✅
- [x] Opprett classes/Settings/Settings.php
- [x] Integrer med ATUM Settings (ny "Enhancer" tab)
- [x] Legg til General settings (admin email, auto suggestions)
- [x] Legg til PO Algorithm settings (orders per year, min days, threshold, seasonal)

### Fase 3: Leverandørimport ✅
- [x] Opprett classes/SupplierImport/SupplierImport.php
- [x] CSV-import med semikolon-delimiter
- [x] Kolonne-mapping for norsk CSV-format
- [x] Duplikatsjekk på leverandørkode og navn
- [x] AJAX import med progress feedback

### Fase 4: PO-algoritme ✅
- [x] Opprett classes/PurchaseOrderSuggestions/POSuggestionAlgorithm.php
- [x] Analyser lagerstatus og gjennomsnittlig daglig salg
- [x] Sesonganalyse fra salgshistorikk
- [x] Opprett classes/PurchaseOrderSuggestions/POSuggestionGenerator.php
- [x] Opprett draft POs per leverandør
- [x] E-postvarsler ved nye forslag
- [x] Legg til "Generate" knapp i Settings

---

## Neste Faser (TODO)

### Fase 5: Automatisering
- [ ] Scheduled task (WP Cron) for automatisk kjøring
- [ ] Konfigurerbar kjørefrekvens

### Fase 6: Testing og Polish
- [ ] Test med reelle data
- [ ] Feilhåndtering og edge cases
- [ ] Oversettelser (POT-fil)

---

## Fremdriftslogg

| Dato | Oppgave | Commit |
|------|---------|--------|
| 2025-12-08 | Initial commit: Plugin structure | 7042444 |
| 2025-12-08 | Add ATUM settings integration | 906187d |
| 2025-12-08 | Add supplier CSV import | 90ee1dd |
| 2025-12-08 | Add PO suggestion algorithm | bab1653 |

---

## Filstruktur

```
serenisoft-atum-enhancer/
├── serenisoft-atum-enhancer.php
├── composer.json
├── .gitignore
├── CLAUDE.md
├── PROJECT_DESC.MD
├── classes/
│   ├── Bootstrap.php
│   ├── Settings/
│   │   └── Settings.php
│   ├── SupplierImport/
│   │   └── SupplierImport.php
│   └── PurchaseOrderSuggestions/
│       ├── POSuggestionAlgorithm.php
│       └── POSuggestionGenerator.php
├── languages/
│   └── .gitkeep
├── tasks/
│   └── todo.md
└── vendor/
    └── autoload.php
```

---

## Review: Utvikling 2025-12-08

### Implementert funksjonalitet:

1. **Grunnstruktur**
   - WordPress plugin med ATUM dependency
   - PSR-4 autoloading via Composer
   - HPOS-kompatibilitet deklarert

2. **Settings**
   - Ny "Enhancer" tab i ATUM Settings
   - Konfigurerbare parametere for algoritmen
   - Manuell trigger-knapp for PO-generering

3. **Leverandørimport**
   - CSV-import med norsk kolonne-format
   - Duplikatbeskyttelse
   - AJAX-basert med progress feedback

4. **PO-algoritme**
   - Analyserer lagerstatus vs salgshistorikk
   - Sesongbaserte justeringer
   - Respekterer min dager mellom bestillinger
   - Oppretter draft POs per leverandør
   - Sender e-postvarsler

### GitHub Repository
https://github.com/Serenisoft/serenisoft-atum-enhancer
