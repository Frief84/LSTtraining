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

## Verfuegbare Karten- und Ortslayer

Die folgenden Layer koennen im Einsatzeditor fuer `scope_type = 'landscape'`
ausgewaehlt werden. Die Auswahlwerte werden in `landscape_tags_json` ohne das
Praefix `landuse_` gespeichert. Bei der Ortsauswahl werden sie intern auf den
jeweiligen Datenlayer abgebildet, zum Beispiel `forest` auf
`landuse_forest`.

Die Kartenlayer sind in erster Linie Datenquellen fuer die automatische
Ortsauswahl. Sie muessen deshalb nicht als sichtbare, ein- und ausblendbare
Flaechen auf der Livekarte erscheinen.

| Auswahlwert | Interner Datenlayer | Inhalt | Geometrie | Gewicht | Bei `anywhere` |
| --- | --- | --- | --- | ---: | :---: |
| `residential` | `landuse_residential` | Wohngebiete aus OSM mit `landuse=residential` | Flaeche | 40 | ja |
| `industrial` | `landuse_industrial` | Industrie- und Produktionsflaechen mit `landuse=industrial` | Flaeche | 24 | ja |
| `commercial` | `landuse_commercial` | Gewerbe- und Bueroflaechen mit `landuse=commercial` | Flaeche | 30 | ja |
| `retail` | `landuse_retail` | Einzelhandels- und Einkaufsflaechen mit `landuse=retail` | Flaeche | 28 | ja |
| `allotments` | `landuse_allotments` | Kleingartenanlagen mit `landuse=allotments` | Flaeche | 10 | ja |
| `farmland` | `landuse_farmland` | Landwirtschaftlich genutzte Acker- und Anbauflaechen mit `landuse=farmland` | Flaeche | 4 | ja |
| `animal_keeping` | `landuse_animal_keeping` | Flaechen fuer Tierhaltung mit `landuse=animal_keeping` | Flaeche | 8 | nein |
| `forest` | `landuse_forest` | Wald- und forstwirtschaftlich genutzte Flaechen mit `landuse=forest` | Flaeche | 5 | ja |
| `logging` | `landuse_logging` | Holzeinschlags- und Abholzungsflaechen mit `landuse=logging` | Flaeche | 8 | nein |
| `meadow` | `landuse_meadow` | Wiesen- und Weideflaechen mit `landuse=meadow` | Flaeche | 3 | ja |
| `recreation_ground` | `landuse_recreation_ground` | Freizeit- und Erholungsflaechen mit `landuse=recreation_ground` | Flaeche | 12 | ja |
| `railway` | `landuse_railway` | Fuer den Bahnbetrieb genutzte Flaechen, zum Beispiel Bahnhoefe und Rangierbereiche, mit `landuse=railway` | Flaeche | 20 | nein |
| `cemetery` | `landuse_cemetery` | Friedhoefe mit `landuse=cemetery` | Flaeche | 8 | nein |
| `landfill` | `landuse_landfill` | Deponie- und Ablagerungsflaechen mit `landuse=landfill` | Flaeche | 8 | nein |
| `quarry` | `landuse_quarry` | Steinbrueche, Tagebau- und andere Abbaugebiete mit `landuse=quarry` | Flaeche | 8 | nein |
| `religious` | `landuse_religious` | Flaechen religioeser Einrichtungen mit `landuse=religious` | Flaeche | 8 | nein |
| `roads_lines` | `roads_lines` | Benannte oder ueber eine Referenz bezeichnete, befahrbare Strassen aus OSM-Objekten mit `highway=*`; private oder fuer Fahrzeuge gesperrte Wege werden ausgeschlossen | Linie | 35 | ja |
| `roads_motorway` | virtueller Filter auf `roads_lines` | Autobahnen und autobahnaehnliche Fahrbahnen der Typen `motorway`, `motorway_link`, `trunk` und `trunk_link`, sofern eine verwertbare Strassenreferenz vorhanden ist | Linie | 38 | nein, aber Autobahnen koennen ueber `roads_lines` enthalten sein |

Das Gewicht steuert die relative Zufallsauswahl, wenn im lokalen Suchfenster
Kandidaten aus mehreren erlaubten Layern gefunden wurden. Ein hoeheres Gewicht
macht die Auswahl wahrscheinlicher; es garantiert sie nicht.

Bei `scope_type = 'anywhere'` werden nur die in der letzten Spalte mit `ja`
markierten Layer automatisch durchsucht. Speziellere Layer wie Friedhof,
Bahngelaende, Deponie oder Steinbruch werden nur verwendet, wenn sie in einer
Landscape-Ortsbindung ausdruecklich ausgewaehlt sind.

Die Flaechenlayer werden aus OSM-`way`- und OSM-`relation`-Objekten mit dem
jeweiligen `landuse`-Wert gebildet. `roads_lines` wird dagegen aus OSM-`way`-
Objekten mit einem `highway`-Tag aufgebaut. `roads_motorway` besitzt keine
eigene Tile-Datei und verwendet dieselben lokal eingegrenzten Strassen-Tiles.

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
aber auf die Strassentypen `motorway`, `motorway_link`, `trunk` und
`trunk_link`. Zusaetzlich muss eine verwertbare Strassenreferenz vorhanden
sein. Damit entstehen Einsaetze auf Autobahnen und autobahnaehnlichen
Fahrbahnlinien.

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
