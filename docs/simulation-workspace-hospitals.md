# Simulations-Workspace: Krankenhäuser

## Datenquelle

Der Simulations-Workspace lädt Krankenhäuser über den AJAX-Endpunkt
`lsttraining_sim_get_workspace_hospitals` aus der bestehenden Tabelle
`krankenhaeuser`.

Die relevante SQL-Struktur liegt in `database/schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS `krankenhaeuser` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `poi_id`           VARCHAR(50) NOT NULL UNIQUE,
  `name`             VARCHAR(255) NOT NULL,
  `latitude`         DOUBLE NOT NULL,
  `longitude`        DOUBLE NOT NULL,
  `versorgungsstufe` ENUM('Grundversorgung','Schwerpunktversorger','Maximalversorger') NOT NULL DEFAULT 'Grundversorgung',
  `trauma_level`     TINYINT NOT NULL DEFAULT 0,
  `helipad`          TINYINT(1) NOT NULL DEFAULT 0,
  `departments`      JSON NOT NULL,
  PRIMARY KEY (`id`)
);
```

Die Zuordnung zur Leitstelle erfolgt, wenn vorhanden, über
`leitstellen.available_hospitals`. Der Workspace akzeptiert dort interne
Krankenhaus-IDs, `poi_id`-Werte sowie Objektformen mit `id`, `hospital_id`,
`krankenhaus_id` oder `poi_id`. Wenn die Zuordnung leer ist oder keine Treffer
liefert, fällt der Workspace auf alle Krankenhäuser zurück.

## Fachbereiche

Die Fachbereichsfarben und Labels kommen aus `data/departments.json`.

Der bestehende Fachbereichseditor speichert `krankenhaeuser.departments`
typischerweise als JSON-Liste einzelner Objekte:

```json
[
  { "CT": { "Lat": 52.0, "Long": 13.0 } },
  { "TRAU": { "Lat": 52.0, "Long": 13.0 } }
]
```

Der Workspace unterstützt zusätzlich ältere Formen wie:

```json
["CT", "TRAU"]
```

und Objektformen mit `code`, `department` oder `label`.

## Kartenanzeige

Krankenhäuser werden im Kartenpanel als cyanfarbene Marker mit `H` angezeigt.
Der Marker nutzt primär `krankenhaeuser.latitude` und
`krankenhaeuser.longitude`. Falls diese Koordinaten fehlen oder `0,0` sind,
wird die erste gültige Fachbereichskoordinate aus `departments` verwendet.

Ein Klick auf den Marker öffnet im Kartenstatus eine kompakte Karte mit Name,
Versorgungsstufe und Fachbereichs-Badges.
