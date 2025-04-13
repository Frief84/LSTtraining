# LSTtraining – Leitstellen-Simulations-Plugin für WordPress

**LSTtraining** ist ein modulares Framework zur Simulation von Einsätzen im Rettungsdienst- und Feuerwehrbereich – aktuell in aktiver Entwicklung (Stand: April 2025).

> ⚠️ Hinweis: Das Plugin befindet sich derzeit im **Beta-Status**. Viele Funktionen sind bereits nutzbar, aber noch nicht final.

---

## ✅ Bereits implementiert

- Verwaltung von Leitstellen inkl. Positionsmarker & Einsatzgebiet
- OpenLayers-Integration zur Kartendarstellung
- Zeichnen und Bearbeiten von Einsatzgebieten (Polygon)
- Drag&Drop-fähige Kartenmarker für Leitstellenpositionen
- Live-Routing mit OpenRouteService (ORS)
- Dynamisch animierte Fahrzeugbewegung anhand realer Wegdaten
- Auswahl zwischen WordPress-Datenbank und externer MySQL
- Admin-Bereich im WordPress-Menü mit Einstellungsmöglichkeiten

---

## ⚙️ Einrichtung

1. Plugin hochladen nach `/wp-content/plugins/lsttraining-plugin`
2. Aktivieren im WordPress-Backend
3. Unter **LSTtraining > Einstellungen**:
   - Zielseite für Kartendarstellung auswählen
   - ORS API-Key eintragen
   - Tabellen installieren (Button)
   - Optional: Externe Datenbank angeben

4. Wechsel zu **Leitstellen**, um Einträge zu erstellen/bearbeiten

---

## 🔜 Geplante Funktionen

- Benutzerrollen (Admin, Editor, Spieler)
- Verwaltung & Verknüpfung von Wachen, Fahrzeugen & Einsätzen
- Zufällige Einsatzgenerierung nach Kriterien (Uhrzeit, Wetter, POI)
- Gruppenübungen & Mehrbenutzermodus
- Statistiken & Trainingsauswertung
- Eigenes Routing-Backend (lokal statt API)

---

## 🧱 Projektstruktur
lsttraining-plugin/ ├── css/ # Admin-Styles (z. B. Flexbox-Layout) ├── js/ # Karten- & Adminlogik ├── includes/ # Backend-Funktionen ├── openlayers/ # Vollständige JS-Bibliothek lokal ├── get_wachen.php # REST: liefert Wachen (GeoJSON) ├── get_route.php # REST: Routing mit ORS ├── index.html # Legacy / statisch └── database/schema.sql # Tabellenstruktur

---

## 🔗 Demo

🧪 [https://frief.de](https://frief.de) – Kartenansicht live

---

## 📩 Feedback

Fehler gefunden? Featurewunsch?  
> Starte ein Issue oder kontaktiere [Frief84](https://github.com/Frief84)

---

© 2025 by Frief84 – Dieses Plugin ist ein nicht-kommerzielles Trainingswerkzeug für Ausbildungszwecke.


