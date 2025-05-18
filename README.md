# LSTtraining

**LSTtraining** ist ein spezialisiertes WordPress-Plugin zur Simulation und Schulung von Dispositionsabläufen für Feuerwehr- und Rettungsdiensten. Es richtet sich an Ausbilder:innen und Trainingsleiter:innen, die realistische, kartengestützte Einsatzszenarien abbilden möchten – mit Fokus auf Visualisierung, Taktik und Wegesimulation.
---

---

> **⚠️ Work in Progress**  
> Dieses Plugin befindet sich aktuell in aktiver Entwicklung.  
> API-Endpunkte, Datenbank-Schema, UI und interne Abläufe **können sich jederzeit ändern**.  
> Feedback und Mitwirkung sind sehr willkommen, aber bitte achtet darauf, dass Breaking Changes möglich sind!  
>  
> **Demo-Hinweis:** Unter der öffentlichen Demo [https://frief.de/](https://frief.de/) sieht man aktuell nur wenig – es wird momentan vor allem am Backend und an der Datenbankstruktur gearbeitet.

---


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
3. Schreibrechte für AJAX-Endpunkte (`admin-ajax.php`) sicherstellen.
4. Datenbanktabellen importieren (siehe `database/schema.sql`).

## 🧱 Datenbank

Das Schema in `database/schema.sql` definiert acht Tabellen:

1. **leitstellen**: Dispositionszentren mit Polygon-GeoJSON und Metadaten  
2. **wachen**: Feuerwachen/Rettungswachen mit Name, Koordinaten, Typ und optionalem Bild  
3. **fahrzeuge**: Zuweisung zu Wachen, Typ (ENUM), letzte bekannte Position  
4. **fahrzeug_status**: Live-Status und Positions-Tracking von Fahrzeugen  
5. **spielinstanzen**, **instanz_wachen**, **instanz_user**: Multi-User-Instanzen für Trainingsszenarien  
6. **einsatzvorlagen**: Vorlagen für wiederkehrende Übungen  

## 🏥 Krankenhäuser

Wir haben jetzt eine vollständige statische „Hospitalkatalog“-Tabelle für die Simulation definiert. Die SQL-Definition dient nur als Referenz – in der README beschreiben wir die Felder:

| Feld               | Typ                                    | Beschreibung                                                          |
|--------------------|----------------------------------------|-----------------------------------------------------------------------|
| **id**             | INT, PK, AUTO_INCREMENT                | Interner Primärschlüssel                                              |
| **poi_id**         | VARCHAR(50), UNIQUE                    | Externe POI-ID (z.B. OSM-ID oder GeoJSON-ID)                          |
| **name**           | VARCHAR(255)                           | Name des Krankenhauses                                                |
| **latitude**       | DOUBLE                                 | Breitengrad                                                           |
| **longitude**      | DOUBLE                                 | Längengrad                                                            |
| **versorgungsstufe** | ENUM                                 | Versorgungsstufe:  
  - `Grundversorgung`  
  - `Schwerpunktversorger`  
  - `Maximalversorger`  
| **trauma_level**   | TINYINT                                | Trauma-Level (0 = keiner, 1–3)                                        |
| **helipad**        | BOOLEAN                                | Hubschrauberlandeplatz vorhanden? (`true` / `false`)                  |
| **departments**    | JSON                                   | Liste der Fachabteilungen als JSON-Array (siehe unten)                |
| **last_update**    | TIMESTAMP                              | Zeitpunkt der letzten Änderung (automatisch aktualisiert)            |
| **created_at**     | TIMESTAMP                              | Erstellungszeitpunkt (automatisch gesetzt)                           |

### 📋 Fachabteilungen (`departments` JSON)

Das Feld `departments` ist ein JSON-Array mit Objekten für jede Abteilung. Um Konsistenz sicherzustellen, dürfen nur folgende **Codes** verwendet werden:

| Code | Name                                 |
|------|--------------------------------------|
| NOTF | Innere Notaufnahme                   |
| KINA | Kinder-Notaufnahme                   |
| CHIR | Chirurgie                            |
| ISTX | Chirurgische Intensivstation         |
| CT   | Computertomographie                  |
| DERM | Dermatologie                         |
| DRAM | Druckkammer                          |
| VASG | Gefäßchirurgie                       |
| GYNO | Gynäkologie                          |
| HNOK | HNO-Heilkunde                        |
| INTX | Innere Intensivstation               |
| CARD | Kardiologie                          |
| KESS | Kreißsaal                            |
| MRT  | Magnetresonanztomographie            |
| MKGC | MKG-Chirurgie                        |
| NECH | Neurochirurgie                       |
| NEUR | Neurologie                           |
| NOTO | Notoperation                         |
| NUKL | Nuklearmedizin                       |
| ONKO | Onkologie                            |
| PSYC | Psychiatrie                          |
| PED  | Pädiatrie                            |
| KKH  | Kinderkrankenhaus                    |
| STRK | Stroke Unit                          |
| UROL | Urologie                             |
| BURN | Brandverletzten-Station              |
| CAT  | Herzkatheteruntersuchung             |

#### Aufbau eines `departments`-Eintrags

Jedes Array-Element ist ein Objekt mit folgenden Feldern:

```json
{
  "code":     "CHIR",     // einer der obigen Codes
  "name":     "Chirurgie",
  "priority": 2,          // 1 = höchste Priorität, höhere Zahlen = weniger wichtig
  "capacity": 24          // optional: Betten- bzw. Behandlungsplätze
}

> Hinweis:
> Die Felder versorgungsstufe, trauma_level und helipad
> beeinflussen das Routing/Handling in der Simulation.
> last_update wird automatisch auf den aktuellen Zeitstempel gesetzt,
> wenn sich Daten ändern.
> Nur Codes aus der obigen Liste sind gültig — Erweiterungen müssen hier dokumentiert werden.

## 🏗️ Architektur und Aufbau

### 1. Haupt-Bootstrap (`lsttraining-plugin.php`)
Lädt alle Module und initialisiert das Plugin.

### 2. Datenbank-Layer
- **includes/schema_import.php**: Importiert `database/schema.sql`  
- **includes/db.php**: Helper `lsttraining_get_connection()`

### 3. Einstellungen & Admin-Menü
- **includes/settings.php**: Plugin-Optionen (DB-Modus, API-Key)  
- **includes/admin-menu.php**: Menüs und Subpages

### 4. Admin-UI & Editor-Module
- **includes/admin-ui.php**: Enqueue von CSS/JS (OpenLayers, Admin-UI, wachen.js usw.)  
- Templates: `leitstellen_editor.php`, `nebenstellen_editor.php`, `wachen.php`  

### 5. CRUD & AJAX-Endpunkte
Alle AJAX-Handler in **includes/ajax-handlers.php**:

| Action                                | Zweck                                              |
|---------------------------------------|----------------------------------------------------|
| `lsttraining_get_einsatzgebiet`       | Lädt GeoJSON einer Leitstelle                     |
| `lsttraining_save_einsatzgebiet`      | Speichert GeoJSON einer Leitstelle                |
| `lsttraining_get_neben_einsatzgebiet` | Lädt GeoJSON einer Nebenleitstelle                |
| `lsttraining_save_neben_einsatzgebiet`| Speichert GeoJSON einer Nebenleitstelle           |
| `lsttraining_get_wachen`              | Liefert alle Wachen (Filter: Leitstelle/Nebenleitstelle) |
| `lsttraining_get_wache`               | Lädt Rohdaten für eine einzelne Wache              |
| `lsttraining_save_wache`              | Speichert Änderungen einer Wache                   |

## 🗂️ Includes-Verzeichnis

Im Ordner `includes/` befinden sich alle zentralen PHP-Komponenten des Plugins:

- **db.php**  
  Stellt die Funktion `lsttraining_get_connection()` bereit, die je nach Einstellung entweder die interne WordPress-Datenbank oder eine externe Datenbankverbindung aufbaut.

- **schema_import.php**  
  Liest beim Plugin-Aktivieren die Datei `database/schema.sql` ein und legt die erforderlichen Tabellen (`leitstellen`, `wachen`, `fahrzeuge` u. a.) in der Datenbank an.

- **settings.php**  
  Registriert und verwaltet alle Plugin-Einstellungen (`lsttraining_map_page`, `lsttraining_db_mode`, ORS-API-Key etc.) im WordPress-Options-System.

- **admin-menu.php**  
  Legt das Haupt- und Untermenü im WordPress-Admin an („LSTtraining“ → Leitstellen, Nebenwachen, Wachen, Fahrzeuge).

- **admin-ui.php**  
  Lädt alle benötigten CSS- und JS-Assets (OpenLayers, `admin-ui.css`, `leitstellen_editor.js`, `wachen.js` etc.) bedarfsgerecht in den jeweiligen Admin-Seiten.

- **ajax-handlers.php**  
  Definiert alle `wp_ajax_…`-Hooks für CRUD-Operationen und zum Laden/Speichern von GeoJSON-Einsatzgebieten, Wachen und Fahrzeugdaten. (Übersicht siehe oben im Abschnitt **AJAX-Handler**.)

- **leitstellen_editor.php**  
  Die PHP-Template-Datei für das Backend-Formular und die OpenLayers-Karte zum Anlegen/Bearbeiten von Leitstellen (inklusive GeoJSON-Editor).

- **nebenstellen_editor.php**  
  Analog zu `leitstellen_editor.php`, aber für Nebenleitstellen. Stellt eine eigene Karte und GeoJSON-Eingabe bereit.

- **wachen.php**  
  Rendert im Admin die Seite „Wachen verwalten“ mit Filter-Dropdowns, Karte und Tabelle. Enthält das Modal-Markup und das Mustache-ähnliche Template für den Wachen-Editor.

- **fahrzeuge_editor.php**  
  (Falls vorhanden) Template und JS-Integration zum Anlegen und Bearbeiten von Fahrzeugen in einer ausgewählten Wache.

- **map-override.php**  
  (Optional) Überschreibt bzw. erweitert die Ausgabe der Frontend-Karte, z. B. um eigene Marker-Icons oder Routing-Layer einzufügen.

Jede dieser Dateien kapselt genau einen Verantwortungsbereich und hält so das Plugin modular, leicht wartbar und erweiterbar. ```

## 🗂️ js-Verzeichnis

Im Ordner `js/` liegen alle JavaScript-Module, die das interaktive Verhalten im Admin- und Frontend steuern:

- **admin-ui.js**  
  Initialisiert allgemeine UI-Komponenten im Backend (z. B. Tabs, Dialoge, interaktive Controls), die nicht spezifisch zu Leitstellen, Wachen oder Fahrzeugen gehören.

- **einsatzgebiet-editor.js**  
  Bindet die OpenLayers-Map für den GeoJSON-Editor in den Leitstellen- und Nebenleitstellen-Formularen ein, verwaltet Zeichen- und Bearbeitungswerkzeuge sowie das Import-/Export-Handling.

- **leitstellen_editor.js**  
  Spezifisches Frontend-Skript für die Seite „Leitstellen verwalten“: lädt per AJAX das GeoJSON, bindet den Editor, behandelt Save-/Cancel-Events und aktualisiert das Dropdown mit Leitstellen.

- **nebenstellen_editor.js**  
  Entspricht `leitstellen_editor.js`, aber für die Nebenleitstellen-Seite. Lädt und speichert GeoJSON-Polygone der Nebenleitstellen.

- **wachen.js**  
  Verantwortlich für die Seite „Wachen verwalten“:
  - Laden und Rendern von Wachen-Marker auf der OpenLayers-Karte per AJAX
  - Konfiguration der Marker-Farben je nach Wache-Typ
  - Anzeigen eines Tooltips mit Name und Edit-Button
  - Öffnen und Absenden des Wachen-Bearbeitungs-Modals
  - Synchronisation von Karte und Tabelle bei Filteränderung

- **fahrzeuge_editor.js**  
  (Falls vorhanden) Steuert das Laden, Anzeigen und Speichern der Fahrzeuge einer ausgewählten Wache oder Nebenwachengruppe per AJAX, inklusive Drag-and-Drop für Positions-Updates.

- **main.js**  
  (Optional) Sammlung allgemeiner Helper-Funktionen und globaler Event-Handler, die auf mehreren Admin-Seiten Verwendung finden.

Jedes Modul ist als eigenständige Datei umgesetzt, um die Verantwortlichkeiten klar zu trennen und die Wiederverwendbarkeit im Plugin zu erhöhen. ```


## 📄 Daten- und Asset-Verwaltung

* **`database/`**: Beispiel-GeoJSON und `schema.sql`  
* **`css/`**, **`js/`**: Frontend- und Admin-Assets  
* **`img/`**: Marker-Icons

## 🔄 Datenfluss im Überblick

1. **Setup**: Schema importieren, API-Key konfigurieren  
2. **Leitstelle/Nebenleitstelle bearbeiten**: GeoJSON via AJAX-Editor  
3. **Wachen verwalten**: Karte & Liste laden Daten über `lsttraining_get_wachen`  
4. **Wache bearbeiten**: Pop-up-Formular per AJAX (`lsttraining_get_wache`/`lsttraining_save_wache`)

## 📄 Lizenz

MIT License. Siehe `LICENSE.md`.

## 🧑‍💻 Mitwirken

Pull Requests sind willkommen! Bitte öffne ein Issue für größere Vorschläge.
