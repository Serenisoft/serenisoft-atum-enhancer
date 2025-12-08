I dette prosjektet skal vi lage en WooCommerce-plugin som utvider funksjonaliteten i en plugin som heter Atum Inventory-plugin.

ATUM inventory plugin kildekode
C:\dev\references\atum-stock-manager-for-woocommerce

# Automatisk Genererte Bestillingsforslag
ATUM har allerede funksjonalitet for å legge inn purchase orders. Vi skal utvide den med nye funksjon for å opprette disse også automatisk som bestillingsforslag med status draft. Bruk strukturen på PO som ATUM har fra før.

En bestilling, et bestillingsforslag skal alltid kun omhandle varer fra én leverandør.

Algoritmen som avklarer om noe bør bestilles, skal lages på en smart måte. Ut fra hvor mye som er på lager, bestillingstid angitt i atum, og også hvilke perioder i året dette produktet selger mest. For eksempel selger vi lite av enkelte på sommeren, mens på vinteren selger vi mer av disse varene. Gjør altså en analyse på når på året det selges mest av produktene når du vurderer behovet.

foreslå et smart system for å unngå at det foreslås mange små bestillingsforslag. Stort sett ønsker vi ikke å bestille mer enn 4 ganger i løpet av året fra de fleste leverandørene. Og på enkelte andre, kanskje bare 2 ganger, særlig der hvor det er frakt med båt, hvor fraktprisen er den samme. Nesten uavhengig av hvor mye som bestilles.

Når det først er laget en innkjøpsordre, et bestillingsforslag for en leverandør, kan det være naturlig å samtidig bestille andre varer fra den leverandøren hvor det nærmer seg bestillingsgrensen. Legg inn dette i bestillingsforslaget.

Det skal være mulig å legge på informasjon i innkjøpsordren som er fast per leverandør og per produkt. Sjekk om ATUM har dette fra før. Hvis ikke må vi legge det inn i vår plugin.

## Bestillingstid
Bestillingstid skal i første omgang angis i antall dager per leverandør.

## Automatisk kjøring
Algoritmen skal kjøres jevnlig for å se om det skal opprettes nye bestillingsforslag, og hvis det skjer automatisk så skal det sendes en e-post til en admin e-post som skal være konfigurerbar. Om at det er opprettet et nytt forslag, og helst med en link til bestillingsforslaget.

## Manuell kjøring
Det skal være en knapp for å kjøre generere automatiske bestillingsforslag. Vurder hvor det er best å legge denne for mest mulig brukervennlighet.

# Settings
ATUM plugin har allerede en settings-seksjon i wordpress som heter "ATUM Inventory". Legg våre settings under ATUM Inventory / "Settings (Enhancer)".

# Import av leverandører
Hvis ATUM ikke har dette fra før, lag en funksjon for import av leverandører. Ved import, Ikke overskriv leverandører med samme leverandørnummer eller navn.

Importer i ATUMs leverandørregister, sjekk nøye at det blir gjort korrekt.

Eksempel på CSV-fil for import. Leverandørnummer i denne filen settes til ekstern referanse leverandørnummer for regnskapssystem.
Kundenummer;Leverandørnummer;Navn;Organisasjonsnummer;Telefonnummer;Telefonnr. mobil;Faksnummer;E-postadresse;Postadresse Linje;Postadresse Linje;Postadresse Postnr.;Postadresse Sted;Postadresse Land;Forretningsadresse Linje;Forretningsadresse Linje;Forretningsadresse Postnr.;Forretningsadresse Sted;Forretningsadresse Land;Kategori 1 Nummer;Kategori 1 Navn;Kategori 2 Nummer;Kategori 2 Navn;Kategori 3 Nummer;Kategori 3 Navn
;20100;"Shenzhen MINGSHANG Industrial Co.,LTD";;;;;;;;;;;;;;;;;;;;;