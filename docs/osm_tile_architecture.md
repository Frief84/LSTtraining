OSM Tile Verarbeitungssystem – Ablauf & Logik
1. Systemziel

Das System verwaltet OSM-Daten tile-basiert, um:

Daten global einmal zu speichern
Updates inkrementell durchzuführen
nur relevante Daten pro Leitstelle zu verarbeiten

Es existiert kein leitstellenspezifischer Datenbestand, sondern nur:

globaler Tile-Speicher
leitstellenspezifische Referenzen darauf
2. Zentrale Datenstrukturen
2.1 Globales Tile-Manifest (leitstellen_osm_layers)

Primäre Datenquelle für alle Tiles.

Ein Datensatz entspricht genau:

(layer_key, z, x, y)

Gespeichert werden:

Dateipfad (file_relpath)
Hash (sha1)
Feature-Menge (feature_count)
Status (check_status)
Dirty-Flag (is_dirty)
Zeitstempel (last_checked_at, last_changed_at)

Diese Tabelle definiert den aktuellen Zustand jeder Tile global.

2.2 Leitstellen-Scope (leitstelle_tile_scope)

Mapping:

leitstelle_id → Menge von Tiles

Inhalt:

keine Geodaten
keine Hashes
nur Tile-Koordinaten

Zweck:

Filter auf globales Manifest
keine geometrische Berechnung im Laufbetrieb
2.3 Locking (leitstelle_osm_update_lock)

Verhindert parallele Verarbeitung:

(leittestelle_id, layer_key) → exklusiver Lock
3. Verarbeitungspipeline

Der gesamte Ablauf ist ein deterministischer State-Machine-Prozess:

idle → scan → download → idle
4. Schritt 1: Scope-Aufbau

Input:

leitstelle.geojson
Ziel-Zoom (z. B. z13)

Ablauf:

GeoJSON → Tile-Koordinaten
Speicherung in leitstelle_tile_scope
Sicherstellen, dass jede Tile im Manifest existiert (Seed)

Ergebnis:

Scope = Menge relevanter Tiles
5. Schritt 2: Scan-Phase (Änderungserkennung)

Ziel:

Nicht alle Tiles laden, sondern nur veränderte identifizieren.

5.1 Hierarchischer Scan

Start auf niedrigerem Zoom-Level (z. B. z10–z11):

Tiles werden zu größeren Bereichen aggregiert (Supertiles)
Für jeden Bereich:
Overpass: out count + changed:"timestamp"
5.2 Entscheidungslogik
if count == 0:
    → keine Änderung → ignorieren
else:
    if zoom < target:
        → weiter aufsplitten (Kinder-Tiles)
    else:
        → Tile als dirty markieren
5.3 Ergebnis
Nur betroffene z13-Tiles werden markiert:
is_dirty = 1
Gesamtanzahl:
dirty_total
6. Schritt 3: Download-Phase

Verarbeitet ausschließlich:

SELECT * FROM manifest WHERE is_dirty = 1 AND im Scope
6.1 Pro Tile
Overpass Full Query (Geometrie)
Response → Feature-Transformation
Speicherung:
data/osm_tiles/z{z}/{layer}/{x}/{y}.geojsonl.gz

Format:

GeoJSONL (1 Feature pro Zeile)
gzip-komprimiert
6.2 Vergleich (entscheidender Schritt)
new_sha1 = hash(file)
old_sha1 = manifest.sha1
Fall A: unverändert
check_status = 'unchanged'
is_dirty = 0
last_checked_at = now
Fall B: geändert
sha1 = new_sha1
feature_count aktualisieren
bytes_gz aktualisieren
last_changed_at = now
check_status = 'changed'
is_dirty = 0
Fall C: Fehler
check_status = 'error'
check_message setzen
7. Fortschritt & State

Der Zustand wird persistiert:

leitstelle_layer_sync_state

Wichtige Felder:

phase → scan / download / idle
dirty_total
dirty_done
scan_cursor_json (Queue)
scan_since (Zeitstempel)

Dadurch ist der Prozess:

unterbrechbar
fortsetzbar
parallel sicher
8. Laufzeitsteuerung

Jeder Request ist bewusst begrenzt:

max. Laufzeit ~18 Sekunden
Budget:
Scan: X Nodes
Download: X Tiles

Wenn Limit erreicht:

→ State speichern
→ später fortsetzen
9. Laden der Tiles (Runtime)

Zur Nutzung (Rendering, Simulation):

Scope laden
Tiles im Manifest finden
Datei laden:
file_relpath → .geojsonl.gz
Features streamen / dekodieren

Wichtig:

kein Overpass im Runtime-Pfad
nur lokale Dateien
10. Designprinzipien
10.1 Globaler Datenbestand

Keine Duplikate
Keine leitstellenspezifischen Kopien

10.2 Selektive Updates

Nur Tiles mit Änderungen werden geladen

10.3 Hash-basierter Vergleich

Keine Feature-Diffs notwendig

10.4 Dateisystem statt DB für Geometrie

DB enthält nur Metadaten

10.5 Deterministische Verarbeitung

Jeder Schritt ist reproduzierbar:

Input → Scan → Dirty → Download → Hash → Update
11. Ergebnisverhalten

Das System garantiert:

minimale Overpass-Last
inkrementelle Aktualisierung
konsistente Tile-Zustände
parallele Nutzbarkeit mehrerer Leitstellen
12. Kurzform als Ablaufdiagramm
Start
 ↓
Scope bestimmen
 ↓
SCAN:
  Hierarchisch prüfen (count)
  → dirty Tiles markieren
 ↓
DOWNLOAD:
  Nur dirty Tiles laden
  → speichern
  → Hash vergleichen
 ↓
Manifest aktualisieren
 ↓
Fertig