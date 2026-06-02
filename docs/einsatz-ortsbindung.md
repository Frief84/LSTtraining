# Einsatz-Ortsbindung

Diese Notiz dokumentiert die Ortsbindung fuer automatisch erzeugte Einsatzvorlagen.

## Landscape-Ortsbindung

Einsatzvorlagen mit `scope_type = 'landscape'` nutzen `landscape_tags_json`, um erlaubte Flaechen oder Linienlayer festzulegen. Mehrere Werte sind als JSON-Array moeglich.

Die Ortsauswahl bestimmt zuerst einen zufaelligen Ausgangspunkt im Einsatzgebiet
und durchsucht anschliessend nur dessen Umgebung. Der Suchradius wird bei
Bedarf stufenweise von `250 m` ueber `1 km` und `3 km` bis maximal `10 km`
erweitert; danach werden hoechstens zwei weitere Ausgangspunkte versucht.
Innerhalb des lokalen Suchfensters werden gefundene Features weiterhin nach
ihrem Layergewicht zufaellig gewaehlt.

Bei `scope_type = 'landscape'` bleibt die gewaehlte Ortsbindung strikt:
Ein Wald-, Wiesen-, Acker-, Strassen- oder Autobahneinsatz weicht nicht auf
einen anderen Gebietstyp aus. Bei `scope_type = 'anywhere'` stehen geeignete
Strassen sowie Siedlungs- und Nutzflaechen einschliesslich Wald, Acker und
Wiese zur Auswahl.

Flaecheneinsaetze bleiben geometrisch auf der gewaehlten Flaeche. Fuer den
lesbaren Anrufort wird eine nahe geeignete Strasse aus lokalen Strassen-Tiles
gesucht; dadurch ist kein Laufzeitscan des gesamten Leitstellengebiets mehr
notwendig.

`fixed_point` verwendet weiterhin die konfigurierte Position beziehungsweise
den konfigurierten Radius. `poi_type` verwendet direkt einen passenden POI.
Eine lokal gefundene Strasse kann die lesbare Ortsangabe verbessern; fehlt
sie, blockiert dies Fixpunkt- oder POI-Einsaetze nicht.

Beispiele:

```json
["residential"]
["industrial", "commercial"]
["roads_lines"]
["roads_motorway"]
```

## Strassen und Autobahnen

Der Layer `roads_lines` liest zur Laufzeit ausschliesslich die Strassen-Tiles,
die das aktuelle lokale Suchfenster schneiden, und erzeugt Punkte auf
Strassenlinien innerhalb des Leitstellengebiets.

Der Layer `roads_motorway` nutzt dieselben lokal eingegrenzten Tiles, filtert
aber auf `properties.highway = "motorway"`. Damit entstehen Einsaetze auf
Autobahn-Fahrbahnlinien.

`motorway_junction` beschreibt Anschlussstellen-Punkte und wird nicht automatisch als Autobahn-Fahrbahn genutzt.

## SQL Nach Umsetzung

Verkehrsunfaelle allgemein auf Strassen:

```sql
UPDATE einsaetze
SET scope_type = 'landscape',
    landscape_tags_json = '["roads_lines"]',
    updated_at = NOW()
WHERE title IN (
    'VU ohne Verletzte mit auslaufenden Betriebsstoffen',
    'VU mit leichtverletzter Person'
);
```

Reine Autobahn-Einsaetze:

```sql
UPDATE einsaetze
SET scope_type = 'landscape',
    landscape_tags_json = '["roads_motorway"]',
    updated_at = NOW()
WHERE title LIKE '%Autobahn%';
```
