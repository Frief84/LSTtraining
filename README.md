# LSTtraining

LSTtraining ist ein spezialisiertes WordPress-Plugin zur Simulation und Schulung von Dispositionsabläufen für Feuerwehr- und Rettungsdienste. Es richtet sich an Ausbilder:innen und Trainingsleiter:innen, die realistische, kartengestützte Einsatzszenarien abbilden möchten – mit Fokus auf Visualisierung, Taktik und Wegesimulation.

## 🔍 Features

- Echtzeit-Kartendarstellung mit **OpenLayers**
- Dynamische Anzeige von **Feuerwachen / Rettungswachen**
- Visualisierung von **Einsatzgebieten**
- Live-Simulation von **Fahrzeugbewegungen auf Routen**
- Integration mit **OpenRouteService** zur Wegberechnung
- Admin-Interface zur Verwaltung von Wachen, Gebieten und Einsätzen (geplant)

---

## 🗺️ Kartenfunktionen

- **OpenStreetMap** als Grundkarte
- Anzeige aller Wachen aus der Datenbank mit SVG-Icons
- Animation eines Fahrzeugs zwischen zwei Koordinaten
- Zeichnung von Einsatzgebieten (Polygon-Funktion in Arbeit)

---

## ⚙️ Installation

1. Klone das Repository in dein WordPress-Plugin-Verzeichnis:
   ```bash
   git clone https://github.com/Frief84/LSTtraining.git wp-content/plugins/lsttraining
   ```

2. Aktiviere das Plugin im WordPress-Adminbereich.

3. Stelle sicher, dass OpenLayers im Plugin-Verzeichnis unter `/openlayers/` verfügbar ist (z. B. `ol.js`, `ol.css`).

4. Richte die Datenbanktabellen ein (siehe unten).

---

## 🧱 Datenbank

Die Datei `database/schema.sql` enthält das notwendige Datenbankschema:

- `wachen`: Speichert Informationen zu Wachen inkl. Name, Koordinaten und Typ
- Weitere Tabellen für Einsatzgebiete und Einsätze sind geplant

Beispiel zur Ausführung:
```sql
SOURCE /path/to/lsttraining/database/schema.sql;
```

---

## 📦 Verzeichnisstruktur

```
lsttraining/
├── js/                    # JavaScript (OpenLayers Logik)
│   └── app.js
├── img/                   # Icons für Fahrzeuge und Wachen
│   └── fahrzeug/
│   └── wachen/
├── openlayers/            # Eingebettete OpenLayers-Bibliothek
├── get_route.php          # Backend-Endpunkt für Routenberechnung
├── get_wachen.php         # Backend-Endpunkt für Wachendaten
├── index.html             # Standalone HTML-Testumgebung
├── lsttraining-plugin.php # Haupt-Plugin-Datei
├── shortcode-map.php      # (Noch nicht aktiv genutzt)
└── README.md
```

---

## 🧪 Testen der Karte lokal

Du kannst die Karte direkt per `index.html` im Browser öffnen, um die JavaScript- und Kartenfunktionen zu testen, unabhängig von WordPress. Voraussetzung: Ein lokaler Server, der PHP-Dateien ausführt (z. B. XAMPP oder MAMP).

---

## 🔐 Sicherheitshinweise

- Validierung und Escaping fehlen aktuell teilweise – dies ist bei Benutzereingaben unbedingt nachzurüsten
- CSRF- und Nonce-Prüfungen sollten für AJAX-Endpunkte ergänzt werden
- Prepared Statements für alle Datenbankabfragen sind empfohlen

---

## 🚧 Roadmap (geplant)

- [ ] Admin-Bereich zur Verwaltung von Wachen und Einsatzgebieten
- [ ] Zeichnung und Speicherung benutzerdefinierter Einsatzgebiete
- [ ] Zeitbasierte Einsatzabläufe / Trainingsszenarien
- [ ] Logging & Replay-Funktion
- [ ] Shortcode für Kartenanzeige in Beiträgen (`[lst_map]`)

---

## 📄 Lizenz

MIT License. Siehe `LICENSE.md`.

---

## 🧑‍💻 Mitwirken

Pull Requests sind willkommen! Bitte öffne ein Issue für größere Feature-Vorschläge.