# LSTtraining

**LSTtraining** ist ein spezialisiertes WordPress-Plugin zur Simulation und Schulung von Dispositionsabläufen für Feuerwehr- und Rettungsdienste. Es richtet sich an Ausbilder\:innen und Trainingsleiter\:innen, die realistische, kartengestützte Einsatzszenarien abbilden möchten – mit Fokus auf Visualisierung, Taktik und Wegesimulation.

Eine öffentliche **Demo** läuft aktuell unter: [https://frief.de/](https://frief.de/)

## 🔍 Features

* Echtzeit-Kartendarstellung mit **OpenLayers**
* Dynamische Anzeige von **Feuerwachen** und **Rettungswachen**
* Interaktiver Einsatzgebiets-Editor (Polygon zeichnen, ändern, importieren)
* Live-Simulation von **Fahrzeugbewegungen** auf berechneten Routen
* Integration mit **OpenRouteService** zur Wegberechnung
* Admin-Interface zur Verwaltung von Leitstellen, Nebenwachen und Fahrzeugen

## ⚙️ Installation

1. Plugin in das WordPress-Plugin-Verzeichnis kopieren:

   ```bash
   git clone https://github.com/Frief84/LSTtraining.git wp-content/plugins/lsttraining-plugin
   ```
2. Plugin im WordPress-Adminbereich unter **Plugins** aktivieren.
3. Schreibrechte für AJAX-Endpunkte (`get_wachen.php`, `get_route.php`) sicherstellen.
4. Datenbanktabellen importieren (siehe unten).

## 🧱 Datenbank

Das Schema in `database/schema.sql` definiert acht Tabellen:

1. **leitstellen**: Dispositionszentren mit Polygon-GeoJSON und Metadaten
2. **wachen**: Feuerwachen/Rettungswachen mit Name, Koordinaten, Typ und optionalem Bild
3. **fahrzeuge**: Zuweisung zu Wachen, Typ (ENUM), letzte bekannte Position
4. **fahrzeug\_status**: Live-Status und Positions-Tracking von Fahrzeugen
5. **spielinstanzen**, **instanz\_wachen**, **instanz\_user**: Multi-User-Instanzen für Trainingsszenarien
6. **einsatzvorlagen**: Vorlagen für wiederkehrende Übungen

Der Import erfolgt automatisch über `includes/schema_import.php` oder manuell über Tools wie Adminer/phpMyAdmin.

## 🏗️ Architektur und Aufbau

### 1. Haupt-Bootstrap (`lsttraining-plugin.php`)

Das zentrale Plugin-File lädt alle Module:

```php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/schema_import.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/admin-menu.php';
require_once __DIR__ . '/includes/admin-ui.php';
require_once __DIR__ . '/includes/ajax-handlers.php';
require_once __DIR__ . '/includes/map-override.php';
```

### 2. Datenbank-Layer

#### 2.1 Schema-Import

* `includes/schema_import.php` liest `database/schema.sql` und legt bei Aktivierung alle Tabellen an.

#### 2.2 Verbindungs-Helper

* `includes/db.php` stellt `lsttraining_get_connection()` bereit. Je nach Einstellung verbindet es zur internen WordPress-DB oder zu einer externen Datenbank.

### 3. Einstellungen & Admin-Menü

* **Einstellungen** (`includes/settings.php`) registrieren Optionen:

  * `lsttraining_map_page` (Shortcode-Zielseite)
  * `lsttraining_db_mode` (intern/extern)
  * Zugangsdaten für externe DB
  * `lsttraining_ors_key` (OpenRouteService-API-Key)

* **Admin-Menü** (`includes/admin-menu.php`):

  * Hauptmenüpunkt „LSTtraining“ für globale Einstellungen
  * Untermenüs für Leitstellen, Nebenwachen und Fahrzeuge

### 4. Admin-UI & Editor-Module

* **Assets** werden in `includes/admin-ui.php` geladen:

  * OpenLayers (lokal eingebettet)
  * Plugin-CSS (`css/admin-ui.css`)
  * JS-Module für Editor-Funktionalität:

    * `leitstellen_editor.js`, `nebenstellen_editor.js`, `einsatzgebiet-editor.js`, `admin-ui.js`

* **Editor-Dispatcher** rendert die jeweiligen PHP-Templates:

  * `leitstellen_editor.php`, `nebenstellen_editor.php`, etc.

### 5. CRUD & AJAX-Endpunkte

* Alle AJAX-Handler in `includes/ajax-handlers.php`:

  * Anlegen, Aktualisieren, Löschen von Leitstellen, Wachen, Fahrzeugen und Einsatzgebieten
  * JSON-Antworten für Frontend-Integration

* **get\_wachen.php** liefert alle Wachen mit Koordinaten und Bildpfad als JSON.

* **get\_route.php** leitet Routing-Anfragen an OpenRouteService weiter und gibt GeoJSON zurück.

## 📄 Daten- und Asset-Verwaltung

* **`database/`**: Beispiel-GeoJSON-Dateien (`4_kreise.geojson`) und `schema.sql`
* **`img/`**: Icons für Fahrzeuge, Marker
* **`css/`**, **`js/`**: Produktiv-Skripte und -Stile (DW-Sync-Ordner für Entwicklungszwecke)

## 🔄 Datenfluss im Überblick

1. **Setup**: API-Key und DB-Modus konfigurieren, Schema importieren
2. **Leitstelle anlegen**:

   * Admin lädt OpenLayers-Editor
   * GeoJSON-Polygone clientseitig bearbeiten
   * Änderungen via AJAX speichern in der WP-DB
3. **Frontend-Karte**:

   * Shortcode/Template lädt OpenLayers
   * `get_wachen.php` befüllt Karte mit Wachen
   * `get_route.php` berechnet Routen für Simulation
   * Positionsupdates aus `fahrzeug_status` für Live-Bewegung

## 📄 Hinweis zur Entwicklung / Testumgebung

Das Plugin läuft vollständig eingebettet in WordPress. Die Datei `index.html` im Root dient nur lokalen Tests (z.B. XAMPP) und ist nicht Teil der Frontend-Funktionalität.

## 🔐 Sicherheitshinweise

* Validierung und Escaping sollten für alle Eingaben ergänzt werden
* CSRF- und Nonce-Prüfungen für AJAX-Endpunkte implementieren
* Prepared Statements für Datenbankabfragen nutzen

## 🚧 Roadmap (geplant)

* [ ] Admin-Bereich Verwaltung Leitstellen
* [x] Interaktiver Einsatzgebiet-Editor
* [ ] Verwaltung Nebenwachen
* [ ] Verwaltung Fahrzeuge
* [ ] Zeitbasierte Trainingsszenarien

## 📄 Lizenz

MIT License. Siehe `LICENSE.md`.

## 🧑‍💻 Mitwirken

Pull Requests sind willkommen! Bitte öffne ein Issue für größere Feature-Vorschläge.

## ✅ Umgesetzte Funktionen (seit April 2025)

* Zentrale Auslagerung aller AJAX-Handler in `ajax-handlers.php`
* Dynamisches Nachladen und Anzeigen von Einsatzgebieten beim Bearbeiten von Leitstellen
* Integration eines eigenständigen Editors für Einsatzgebiete mit OpenLayers
* Fehlerbehandlung bei ungültigem oder leerem GeoJSON
* Automatische Markerpositionierung und Rückschreiben der Koordinaten
* Überarbeitung von `admin-ui.js` zur flexiblen Initialisierung
* Optische Anpassung des Buttons „Einsatzgebiet löschen"
