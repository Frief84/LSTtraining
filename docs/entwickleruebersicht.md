# Entwicklerübersicht

[Wiki-Start](README.md) · [REST-Verwaltungs-API](rest-management-api.md) · [REST-Status-API](rest-status-api.md)

Diese Seite beschreibt die technische Orientierung im Repository. Sie enthält keine Zugangsdaten und ist nicht als Ersatz für serverseitige Berechtigungen gedacht.

## Stack

- WordPress-Plugin in PHP;
- PDO-Zugriff auf die WordPress- oder externe MySQL-/MariaDB-Datenbank;
- WordPress AJAX und REST API;
- JavaScript/jQuery für Admin- und Simulationsoberflächen;
- OpenLayers für Karten;
- OpenRouteService für Routing;
- GeoJSON und lokale OSM-Datenlayer.

## Einstiegspunkte

| Datei | Verantwortung |
|---|---|
| `lsttraining-plugin.php` | Konstanten, Modul-Bootstrap und Aktivierungsmigration |
| `includes/admin-menu.php` | rollenabhängige Backend-Menüs |
| `includes/admin-ui.php` | Assets und Render-Callbacks |
| `includes/frontend.php` | Shortcodes, Startseite, Profil und Vollbildsimulation |
| `includes/documentation.php` | sicherer Markdown-Renderer, Artikelkatalog, Rollenfilter und `[lsttraining_docs]` |
| `includes/simulation-workspace.php` | HTML und Assets des aktuellen Workspaces |
| `includes/ajax-handlers.php` | kompatibler Einstieg in die AJAX-Module |
| `includes/ajax/ajax_index.php` | modulare AJAX-Registrierung |
| `includes/rest-api.php` | Routing- sowie Instanz-/Status-Routen |
| `includes/rest-management-api.php` | geschützte Stammdatenverwaltung per REST |

## Verzeichnisübersicht

```text
LSTtraining/
├─ database/        Basisschema
├─ data/            statische Kataloge
├─ docs/            Wiki und Fachartikel
├─ includes/        PHP-Module und Templates
│  ├─ ajax/         AJAX-Endpunkte
│  ├─ simulation/   Spawn-, Wetter- und Simulationslogik
│  └─ osm/          OSM-Verarbeitung
├─ js/              Admin-, Frontend- und Workspace-Logik
├─ css/             Stylesheets
├─ img/             Fahrzeug-, Marker- und Signallicht-Assets
├─ openlayers/      lokale OpenLayers-Auslieferung
└─ tests/           dependency-freie statische Prüfungen
```

`css/documentation.css` ergänzt das aktive WordPress-Theme um das responsive Wiki-Raster, ohne ein eigenes Seitentemplate zu ersetzen.

## Datenbank-Layer

- `includes/db.php` stellt `lsttraining_get_connection()` bereit.
- `database/schema.sql` ist das idempotente Basisschema.
- `includes/migrations.php` führt versionierte Schema- und Datenmigrationen aus.
- `includes/schema_import.php` stellt den geschützten manuellen Auslöser bereit.

Laufzeitcode außerhalb der Migration darf kein `CREATE TABLE` oder `ALTER TABLE` ausführen.

## Berechtigungen

`includes/permissions.php` kombiniert:

1. WordPress-Administratorstatus;
2. Bereichsrecht;
3. erlaubte Leitstellen;
4. aus der Datenbank ermittelten Objekt-Scope.

Objektpfade:

```text
Fahrzeug → Wache → Leitstelle/Nebenleitstelle → Leitstelle
Wache → Leitstelle/Nebenleitstelle → Leitstelle
Nebenleitstelle → Leitstelle
```

Neue Endpunkte müssen Anmeldung, Methode, Nonce beziehungsweise REST-Authentifizierung und Objektberechtigung prüfen. IDs aus einem Request dürfen nicht allein als Berechtigungsbeweis gelten.

## AJAX-Module

| Modul | Bereich |
|---|---|
| `ajax_common.php` | zentraler Guard und gemeinsame Antworten |
| `ajax_leitstellen.php` | Leitstellenaktionen |
| `ajax_nebenstellen.php` | Nebenleitstellen |
| `ajax_wachen.php` | Wachen, Zuordnungen und Polygonsuche |
| `ajax_fahrzeuge.php` | Fahrzeuglisten und CRUD |
| `ajax_hospitals.php` | Krankenhäuser, Fachbereiche und Freigaben |
| `ajax_pois.php` | Leitstellen-POIs |
| `ajax_einsaetze.php` | Einsatzvorlagen, Patienten und Vorschauen |
| `ajax_anruferprofile.php` | Anruferprofile und Sprachbausteine |
| `ajax_frontend.php` | Start, Beitritt und gespeicherte Instanzen |
| `ajax_simulation.php` | Tick, Snapshot und Spielaktionen |
| `ajax_osm_layers.php` | Aktualisierung lokaler OSM-Layer |
| `ajax_users.php` | Benutzerrechte |

## Simulationsdatenfluss

```text
Stammdaten + Startparameter
  → neue Spielinstanz
  → Fahrzeug-Baseline
  → autorisierter, serialisierter Tick
  → Instanz-Deltas und Einsatzereignisse
  → rein lesender Snapshot
  → Browser-Workspace
```

Der Snapshot darf nicht als verdeckter Tick verwendet werden. Schreibender Fortschritt muss über die zentrale Tick-Autorität laufen.

## REST API

Basis: `/wp-json/lst/v1`

- [REST-Verwaltungs-API](rest-management-api.md): Leitstellen, Nebenleitstellen, Wachen, Fahrzeuge und Krankenhäuser
- [REST-Status-API](rest-status-api.md): Instanzstatus und effektive Fahrzeugzustände
- `GET /wachen`: geschützte Karten-/Wachenabfrage
- `POST /route`: geschützte Routinganfrage

Browser verwenden WordPress-Sitzung und `X-WP-Nonce`. Externe Integrationen verwenden Application Passwords nur über HTTPS. Antworten mit Live-Daten werden nicht öffentlich gecacht.

## Karten und Ortslogik

- [Einsatz-Ortsbindung](einsatz-ortsbindung.md)
- [OSM-Tile-Architektur](osm_tile_architecture.md)
- [Wetter und Nachbarleitstellen](wetter-und-nachbarleitstellen-auslastung.md)

Der Einsatzeditor speichert fachliche Ortsregeln. Die Simulation wählt zur Laufzeit innerhalb des Leitstellengebiets geeignete Punkte aus lokalen Layern, POIs oder Fixpunkten.

## Krankenhäuser und Patienten

[Krankenhäuser im Simulations-Workspace](simulation-workspace-hospitals.md) beschreibt Datenquelle, Fachbereiche, Trigger, Bewertung und derzeit nicht ausgewertete Kriterien.

## Tests

```bash
node tests/static-checks.mjs
git diff --check
```

Für produktionsnahe Abnahme zusätzlich:

- PHP-Lint und Unit-/Integrationstests in der Zielversion;
- frische WordPress-Installation;
- Upgrade einer realistischen Datenbankkopie;
- Rollen- und Objektmatrix;
- zwei parallele Browser;
- REST-Tests mit Sitzung und Application Password;
- Browserprüfung aller lokalen Assets.

## Dokumentationsregeln

- `docs/README.md` bleibt die Wiki-Startseite.
- jede neue Fachseite wird dort und in `_Sidebar.md` verlinkt;
- Bedienhandlungen und technische Verträge werden getrennt beschrieben;
- Zielgruppe und erforderliche Rolle stehen am Seitenanfang;
- keine Zugangsdaten, geheimen Schlüssel oder produktiven personenbezogenen Daten dokumentieren;
- Codeänderungen mit sichtbarem Verhalten aktualisieren Spieler- oder Administrationshandbuch;
- API-Änderungen aktualisieren die jeweilige Routenreferenz;
- Datenbankänderungen aktualisieren Migration, Schema und Betriebsdokumentation.

---

[Zurück zur Wiki-Startseite](README.md) · [Betrieb und Fehlerbehebung](betrieb-und-fehlerbehebung.md)
