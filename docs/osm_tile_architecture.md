OSM Tile System – Architektur & Nutzung
1. Grundprinzip

Das System basiert auf einer einzigen globalen Karte, die in Tiles zerlegt ist.

Jede Tile existiert genau einmal

Tiles sind nicht leitstellenspezifisch

Leitstellen definieren nur räumliche Ausschnitte dieser globalen Tiles

Das System vermeidet bewusst:

doppelte Speicherung von Geodaten

leitstellenspezifische Kopien von OSM-Daten

vollständige Re-Downloads ganzer Layer

Stattdessen wird mit:

globalem Tile-Manifest

selektiver Aktualisierung

räumlicher Zuordnung

gearbeitet.

2. Tabelle: leitstellen_osm_layers
Rolle

Diese Tabelle ist die globale Wahrheit aller Tiles.

Ein Datensatz entspricht:

genau einer Tile eines Layers an einer festen Position (z/x/y)

Inhalt

Jede Tile enthält:

Identität:

layer_key

tile_z, tile_x, tile_y

Datenzustand:

sha1 → Hash des aktuellen Inhalts

file_relpath → Pfad zur Tile-Datei (GeoJSONL.gz)

feature_count, bytes_gz

Herkunft:

source (z. B. offline_tiles, overpass)

source_version → initialer Importstand

Update-Metadaten:

last_checked_at → wann zuletzt geprüft

last_changed_at → wann tatsächlich geändert

check_status → unchanged, changed, error, seeded

check_message → Diagnose / Fehlertext

etag_or_signature → externer Vergleichswert

is_dirty → Flag für erneute Prüfung

Bedeutung

Diese Tabelle beantwortet global:

existiert diese Tile?

wo liegt ihre Datei?

wie sieht ihr aktueller Stand aus?

wann wurde sie zuletzt geprüft oder geändert?

Wichtig:
Diese Tabelle ist nicht leitstellenspezifisch.

3. Tabelle: leitstelle_tile_scope
Rolle

Diese Tabelle definiert:

welche globalen Tiles im Einsatzgebiet einer Leitstelle liegen

Ein Datensatz bedeutet:

Leitstelle X nutzt Tile Y

Inhalt

leitstelle_id

layer_key

tile_z, tile_x, tile_y

Eigenschaften

keine Hashes

keine Versionen

keine Zustände

Nur räumliche Zuordnung.

Zweck

Diese Tabelle wird genutzt, um:

schnell relevante Tiles für eine Leitstelle zu finden

teure Geometrie-Berechnungen zu vermeiden

4. Tabelle: leitstelle_osm_update_lock
Rolle

Verhindert parallele Updates.

Ein Datensatz bedeutet:

Für diese Leitstelle und diesen Layer läuft aktuell ein Update

Inhalt

leitstelle_id

layer_key

lock_token

lock_until

Verhalten

vor Update: Lock setzen

nach Update: Lock entfernen oder auslaufen lassen

5. Tile-Dateien (Dateisystem)

Tiles liegen als Dateien vor, z. B.:

data/landuse_tiles_out/z{Z}/{layer_key}/{X}/{Y}.geojsonl.gz

Format:

GeoJSON Text Sequences (eine Feature pro Zeile)

gzip-komprimiert

Diese Dateien sind die eigentlichen Geodaten.
Die Datenbank enthält nur Metadaten und Referenzen.

6. Datenfluss
Initialer Zustand

Tiles werden offline erzeugt (z. B. DACH-Dump)

in leitstellen_osm_layers eingetragen

alle Tiles haben denselben source_version

check_status = 'seeded'

Laufender Betrieb

Der Button „OSM Cache“ führt folgenden Ablauf aus:

1. Leitstelle bestimmen

leitstelle_id laden

GeoJSON-Gebiet der Leitstelle auslesen

2. Relevante Tiles bestimmen

über leitstelle_tile_scope

nur diese Tiles werden betrachtet

3. Lock setzen

Eintrag in leitstelle_osm_update_lock

4. Tiles prüfen

Für jede relevante Tile:

Overpass-Request für genau diese Tile

neuen Inhalt erzeugen

Hash berechnen (sha1)

5. Vergleich

neuer Hash vs. vorhandener sha1

6. Entscheidung

Wenn unverändert:

last_checked_at aktualisieren

check_status = 'unchanged'

Wenn geändert:

Tile-Datei überschreiben

sha1 aktualisieren

feature_count, bytes_gz aktualisieren

last_checked_at setzen

last_changed_at setzen

check_status = 'changed'

7. Lock freigeben
7. Wichtige Designentscheidungen
1. Tiles sind global

keine Duplikate

keine leitstellenspezifischen Kopien

2. Leitstellen sind Filter

bestimmen nur den relevanten Ausschnitt

verändern keine Tile-Daten

3. Updates sind selektiv

nur betroffene Tiles werden geprüft

kein vollständiger Reload

4. Datenbank speichert keine Geometrie

nur Metadaten

Geodaten liegen im Dateisystem

5. Hash-basierter Vergleich

Änderungen werden über sha1 erkannt

keine aufwendigen Feature-Diffs nötig

8. Was dieses System NICHT tut

keine vollständigen Layer-Downloads pro Leitstelle

keine Speicherung von GeoJSON in der Datenbank

kein leitstellenspezifischer Tile-Zustand

keine Mehrfachhaltung identischer Daten

9. Ergebnis

Das System ermöglicht:

performante Kartenabfragen

inkrementelle Updates

geringe Datenlast

parallele Nutzung durch mehrere Leitstellen

bei gleichzeitig klarer Trennung zwischen:

globalem Datenbestand

räumlicher Nutzung