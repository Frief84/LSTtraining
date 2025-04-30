# LSTtraining

**LSTtraining** ist ein spezialisiertes WordPress-Plugin zur Simulation und Schulung von Dispositionsabläufen für Feuerwehr- und Rettungsdienste. Es richtet sich an Ausbilder:innen und Trainingsleiter:innen, die realistische, kartengestützte Einsatzszenarien abbilden möchten – mit Fokus auf Visualisierung, Taktik und Wegesimulation.

Eine öffentliche **Demo** läuft aktuell unter: [https://frief.de/](https://frief.de/)

## 🔍 Features

- Echtzeit-Kartendarstellung mit **OpenLayers**
- Dynamische Anzeige von **Feuerwachen / Rettungswachen**
- Visualisierung von **Einsatzgebieten**
- Live-Simulation von **Fahrzeugbewegungen auf Routen**
- Integration mit **OpenRouteService** zur Wegberechnung
- Admin-Interface zur Verwaltung von Wachen, Gebieten und Einsätzen (geplant)

---

## ⚙️ Installation

1. Lade das Plugin manuell in dein WordPress-Plugin-Verzeichnis `wp-content/plugins/lsttraining-plugin/` – entweder durch Download des Repositories als ZIP oder per Git-Klon:
   ```bash
   git clone https://github.com/Frief84/LSTtraining.git wp-content/plugins/lsttraining-plugin
   ```

2. Aktiviere das Plugin im WordPress-Adminbereich unter **Plugins**.

3. Stelle sicher, dass dein Server PHP-Dateien korrekt verarbeitet und die erforderlichen Schreibrechte besitzt (insbesondere für AJAX-Aufrufe wie `get_wachen.php` und `get_route.php`).

4. Die Karten- und Skriptdateien (JavaScript, OpenLayers etc.) befinden sich bereits im Plugin und werden automatisch geladen – es ist **keine zusätzliche Einrichtung von OpenLayers erforderlich**, da die Bibliothek lokal eingebunden ist.

5. Richte die Datenbanktabellen ein (siehe unten).

---

## 🧱 Datenbank

Die Datei `database/schema.sql` enthält das notwendige Datenbankschema:

- `wachen`: Speichert Informationen zu Wachen inkl. Name, Koordinaten und Typ
- Weitere Tabellen für Einsatzgebiete und Einsätze sind geplant

Zum Einrichten kannst du z. B. Adminer, phpMyAdmin oder das WordPress-Datenbanktool nutzen.

---

## 📄 Hinweis zur Entwicklung / Testumgebung

Das Plugin ist **nicht als Standalone-Anwendung gedacht**, sondern läuft vollständig eingebettet in WordPress.

Die Datei `index.html` dient lediglich internen Tests und Entwicklungszwecken und sollte nur in einer lokal konfigurierten Serverumgebung genutzt werden (z. B. XAMPP mit aktivem Apache + PHP). Sie ist **kein Bestandteil der Plugin-Funktionalität im WordPress-Frontend**.

---

## 🔐 Sicherheitshinweise

- Validierung und Escaping fehlen aktuell teilweise – dies ist bei Benutzereingaben unbedingt nachzurüsten
- CSRF- und Nonce-Prüfungen sollten für AJAX-Endpunkte ergänzt werden
- Prepared Statements für alle Datenbankabfragen sind empfohlen

---

## 🚧 Roadmap (geplant)

- [ ] Admin-Bereich zur Verwaltung von Leistellen
- [X]Interaktiver Einsatzgebiet-Editor (Polygon zeichnen, ändern, löschen, importieren)
- [ ] Admin-Bereich zur Verwaltung von Wachen
- [ ] Admin-Bereich zur Verwaltung von Fahrzeugen
- [ ] Zeichnung und Speicherung benutzerdefinierter Einsatzgebiete
- [ ] Zeitbasierte Einsatzabläufe / Trainingsszenarien

---

## 📄 Lizenz

MIT License. Siehe `LICENSE.md`.

---

## 🧑‍💻 Mitwirken

Pull Requests sind willkommen! Bitte öffne ein Issue für größere Feature-Vorschläge.


## ✅ Umgesetzte Funktionen (seit April 2025)

- [X] Zentrale Auslagerung aller AJAX-Handler in `ajax-handlers.php`
- [X] Dynamisches Nachladen und Anzeigen von Einsatzgebieten beim Bearbeiten von Leitstellen
- [X] Integration eines eigenständigen Editors für Einsatzgebiete mit OpenLayers
- [X] Fehlerbehandlung bei ungültigem oder leerem GeoJSON (kein JS-Fehler mehr)
- [X] Automatische Markerpositionierung inkl. Verschiebung und Rückschreiben der Koordinaten
- [X] Überarbeitung von `admin-ui.js` zur flexiblen Initialisierung
- [X] Button "Einsatzgebiet löschen" optisch angepasst (rechtsbündig, rot)
