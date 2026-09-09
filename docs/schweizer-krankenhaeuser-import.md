# Schweizer Krankenhäuser importieren

Das Script `scripts/import-swiss-hospitals.mjs` übernimmt Schweizer
Akutspital-Standorte aus den standortbezogenen BAG-Qualitätsindikatoren in die
REST-Verwaltungs-API. Es verändert keine Leitstellen und keine
`available_hospitals`-Zuordnungen.

## Voraussetzungen

- Node.js 20 oder neuer (wegen integriertem `fetch`);
- für einen echten Import ein WordPress-Benutzer mit Krankenhausrecht und ein
  Application Password;
- ausschließlich eine HTTPS-Adresse für die WordPress-API.

Die Zugangsdaten werden nur als Umgebungsvariablen gesetzt:

```powershell
$env:LSTTRAINING_API_BASE_URL = 'https://example.org/wp-json/lst/v1'
$env:LSTTRAINING_API_USER = 'api-import'
$env:LSTTRAINING_API_APP_PASSWORD = 'xxxx xxxx xxxx xxxx xxxx xxxx'
```

## Empfohlener Ablauf

Zuerst den offiziellen BAG-Datensatz automatisch laden, geokodieren und einen
Prüfbericht ohne Datenbankänderung erstellen:

```powershell
npm run import:swiss-hospitals -- --dry-run
```

Für einen vollständig reproduzierbaren Lauf kann die Datei
`qip24_tabdaten_stao.csv` lokal abgelegt werden:

```powershell
npm run import:swiss-hospitals -- --dry-run --source-dir C:\Import\BAG-2024
```

Das Script sucht dort nach `qip*tabdaten*stao*.csv`. Optional kann im selben
Verzeichnis `swiss-hospitals-supplement.csv` liegen. Sie dient für amtlich
geprüfte Koordinaten oder Angebote, die in den Qualitätsindikatoren fehlen:

```csv
site_id;name;address;postal_code;city;latitude;longitude;acute;emergency;departments;versorgungsstufe;trauma_level;helipad;source_key
bag-123;Beispielspital;Spitalweg 1;8000;Zürich;47.37;8.54;true;true;CARD|CAT;Schwerpunktversorger;0;true;
```

Unbekannte Fachbereichscodes brechen die Verarbeitung ab. Ohne eindeutige
Koordinaten wird ein Standort im Bericht als `unvollständig` geführt und nicht
geschrieben. Die automatische GeoAdmin-Auflösung akzeptiert absichtlich nur
genau einen Adresstreffer.

Geburtshäuser (`Geburtshaus`, `Maison/Centre de naissance`, `Casa maternità`)
werden ausdrücklich ausgeschlossen. Sie sind zwar in BAG-Auswertungen
enthalten, gelten für diesen Import aber nicht als rettungsdienstlich
anzufahrende Akutkrankenhäuser.

Nach Kontrolle der JSON- und CSV-Berichte unter `reports/` erfolgt der Import:

```powershell
npm run import:swiss-hospitals -- --apply --source-dir C:\Import\BAG-2024
```

Ein weiterer Lauf überspringt bereits vorhandene `ch-bag-*`-Kennungen. Nur mit
expliziter Option werden sie aktualisiert:

```powershell
npm run import:swiss-hospitals -- --apply --update-existing --source-dir C:\Import\BAG-2024
```

Gleichnamige manuelle Krankenhäuser werden nie automatisch zusammengeführt.
Sie erscheinen als `möglicher_konflikt` im Bericht.

## Fachbereiche

Die versionierte Zuordnung steht in
`data/swiss-hospital-department-map.json`. Ein Code wird nur übernommen, wenn
eine BAG-Indikatorzeile zum Standort eine positive Fallzahl enthält. Reine
Rubriküberschriften und aus dem Kliniknamen abgeleitete Vermutungen zählen
nicht. `trauma_level` und `helipad` bleiben ohne explizite Ergänzungsdaten bei
`0` beziehungsweise `false`.
