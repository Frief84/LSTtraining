# DACH-Luftrettungsimport

Der Import legt Luftrettungsstandorte als Wachen vom Typ `AIR` und die öffentlich dokumentierten Rufnamen als Fahrzeuge vom Typ `RTH`, `ITH` oder `SAR` an. Die versionierte Quelldatei liegt in `data/dach-air-rescue.json`.

## Prüfung

```text
npm run import:dach-air-rescue -- --dry-run
```

Der Dry-Run verändert keine Stammdaten und schreibt JSON- und CSV-Berichte nach `reports/`. Ein Datensatz ohne eindeutige Koordinate, Betreiberquelle, Dienstzeit oder strukturierte Verfügbarkeitsregel wird als `unvollständig` gemeldet.

## Import und Aktualisierung

```text
LSTTRAINING_API_BASE_URL=https://example.org/wp-json/lst/v1
LSTTRAINING_API_USER=benutzer
LSTTRAINING_API_APP_PASSWORD=xxxx-xxxx-xxxx-xxxx
npm run import:dach-air-rescue -- --apply
npm run import:dach-air-rescue -- --apply --update-existing
```

Der API-Benutzer benötigt globale Verwaltungsrechte für Leitstellen, Wachen und Fahrzeuge. Vor dem ersten Lauf muss die Schema-Migration `2026090904` installiert sein. Stabile `source_key`-Werte steuern die Upserts; gleichnamige manuelle Datensätze ohne diesen Schlüssel werden nicht verändert.

Leitstellenzuordnungen entstehen durch einen Punkt-in-Polygon-Test gegen die gespeicherten Einsatzgebiete. Mehrfachtreffer sind zulässig. Fehlende Treffer erscheinen als Warnung im Bericht und müssen anschließend fachlich geprüft werden.

## Dienstzeitregeln

`availabilityRule` unterstützt `always`, feste Uhrzeiten, `sunrise`, `sunset`, optionale Minuten-Offsets, Wochentage `1` bis `7` sowie Saisonfenster im Format `MM-TT`. Maßgeblich ist die beschleunigte beziehungsweise pausierte Simulationszeit in der Zeitzone des Standorts. Bereits laufende Einsätze werden beim Ende einer Dienstzeit nicht beendet.

Quellen und Koordinaten müssen vor jedem Katalog-Release erneut geprüft und mit `sourceCheckedAt` datiert werden. OSM-Koordinaten erfordern den Quellenvermerk „© OpenStreetMap-Mitwirkende, ODbL 1.0“; reine Krankenhaus-Helipads sind keine Luftrettungswachen.

Der mitgelieferte Katalog ist eine geprüfte Startfassung, kein automatisch als vollständig geltendes Behördenregister. Weitere Betreiberstandorte werden erst nach belegter Koordinate und exakter Dienst- beziehungsweise Saisonzeit ergänzt; der Bericht macht den jeweiligen Abdeckungsstand sichtbar.
