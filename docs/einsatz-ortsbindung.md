# Einsatz-Ortsbindung

Diese Notiz dokumentiert die Ortsbindung fuer automatisch erzeugte Einsatzvorlagen.

## Landscape-Ortsbindung

Einsatzvorlagen mit `scope_type = 'landscape'` nutzen `landscape_tags_json`, um erlaubte Flaechen oder Linienlayer festzulegen. Mehrere Werte sind als JSON-Array moeglich.

Die Ortsauswahl scannt passende Features im gesamten Einsatzgebiet und zieht daraus gewichtet zufaellig einen Punkt. Dass Tiles oder Layer in einer technischen Reihenfolge gelesen werden, bevorzugt daher keinen Gebietsteil. Bei `scope_type = 'anywhere'` werden typische Siedlungs- und Nutzflaechen realistisch hoeher gewichtet; ein freier Zufallspunkt im Einsatzgebiet bleibt als seltener Fallback moeglich.

Beispiele:

```json
["residential"]
["industrial", "commercial"]
["roads_lines"]
["roads_motorway"]
```

## Strassen und Autobahnen

Der Layer `roads_lines` nutzt die Datei `landuse/roads_lines.geojsonl.gz` und erzeugt Punkte auf Strassenlinien innerhalb des Leitstellengebiets.

Der Layer `roads_motorway` nutzt dieselbe Datei, filtert aber auf `properties.highway = "motorway"`. Damit entstehen Einsaetze auf Autobahn-Fahrbahnlinien.

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
