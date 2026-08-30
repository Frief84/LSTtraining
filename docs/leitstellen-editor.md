# Leitstellen-Editor

[Wiki-Start](README.md) · [Administration](administration.md) · [Nebenleitstellen-Editor](nebenleitstellen-editor.md)

**Backend-Menü:** LST Training → Leitstellen

Der Leitstellen-Editor verwaltet den Hauptbereich einer Simulation. Leitstellen bilden gleichzeitig den räumlichen und berechtigungsbezogenen Scope für Wachen, Fahrzeuge, Krankenhäuser, POIs, Nachbarunterstützung und Spielinstanzen.

## Benötigte Berechtigung

- Bereichsrecht **Leitstellen**;
- bei bestehenden Datensätzen zusätzlich Freigabe für die konkrete Leitstelle;
- WordPress-Administratoren mit `manage_options` dürfen alle Leitstellen verwalten.

Normale Spieler sehen diesen Editor und seinen Dokumentationsartikel nicht.

## Listenansicht

Die Tabelle zeigt ID, Name, Ort, Bundesland, Land und Koordinaten. Über die Suche kann nach Name oder ID gefiltert werden.

Aktionen:

- **Neue Leitstelle:** öffnet einen leeren Editor;
- **Bearbeiten:** lädt den vorhandenen Datensatz einschließlich Nachbarn und Default-Fahrzeugen;
- **Löschen:** geschützte POST-Aktion mit datensatzbezogener Nonce.

Vor dem Löschen müssen abhängige Wachen, Fahrzeuge, Instanzen und Zuordnungen geprüft werden.

## Stammdaten

| Feld | Verwendung |
|---|---|
| Name | sichtbare Bezeichnung und Pflichtfeld |
| Ort | regionale Anzeige |
| Bundesland | Filter und regionale Zuordnung |
| Land | Länderzuordnung |
| Latitude/Longitude | Mittelpunkt und Ausgangspunkt der Kartenansicht |

Koordinaten werden als Dezimalgrad gespeichert. Ein Marker auf der Karte unterstützt die Positionierung.

## Verwaltungsfunktionen

### Einsatzgebiet bearbeiten

Öffnet den GeoJSON-Editor. Das Polygon begrenzt die zulässigen Einsatzorte und lokalen Kartendaten. Änderungen können gezeichnet, importiert oder als GeoJSON übernommen werden.

### Wachen bearbeiten

Öffnet die Wachenansicht mit der aktuellen Leitstelle als Kontext. Die eigentlichen Wachen bleiben eigenständige Datensätze.

### Krankenhäuser bearbeiten

Ordnet vorhandene Krankenhäuser der Leitstelle zu. Nur freigegebene Häuser stehen der Simulation als reguläre Transportziele zur Verfügung.

### POIs bearbeiten

POIs besitzen Typ, Name, Kommentar, grammatisches Geschlecht und Koordinaten. Einsatzvorlagen mit Ortsmodus **POI-Typ** verwenden diese Daten.

### OSM Tiles sync

Wendet geänderte lokale OSM-Tiles auf die Leitstelle an. Vor einem produktiven Lauf müssen Gebiet und verfügbare Layer geprüft werden.

### Zuordnung der Wachen bearbeiten

Der Button wird nach dem ersten Speichern aktiv. Er öffnet die Karten-/Zuordnungsansicht für Wachen.

## Nachbarleitstellen

Die Mehrfachauswahl und Karte bestimmen, welche Nebenleitstellen als angrenzend gelten. Ausgewählte Nachbarn können in laufenden Einsätzen Unterstützungsangebote liefern.

Die Zuordnung allein garantiert kein verfügbares Fahrzeug. Das Angebot hängt zusätzlich von der simulierten Auslastung ab.

## Default-Fahrzeuge

Der Leitstellen-Editor enthält zwei eingebettete Konfigurationen:

- Polizei-Fahrzeugbild und Polizei-Signallichter;
- Default-Rettungsfahrzeug und dessen Signallichter.

Diese Defaults werden für dynamisch erzeugte Unterstützungsfahrzeuge verwendet. Reguläre Fahrzeuge werden im [Fahrzeug-Editor](fahrzeuge-editor.md) gepflegt.

[Details zu Polizei und Unterstützungsfahrzeugen](polizei-und-unterstuetzungsfahrzeuge.md)

## Empfohlener Arbeitsablauf

1. Leitstelle mit Name und Mittelpunkt anlegen.
2. einmal speichern, damit eine ID vorhanden ist.
3. Einsatzgebiet bearbeiten.
4. Nachbarleitstellen auswählen.
5. Wachen, Krankenhäuser und POIs zuordnen.
6. Polizei- und Rettungsdienst-Defaults einrichten.
7. OSM-Layer synchronisieren.
8. speichern und Datensatz erneut öffnen.
9. Testsimulation starten und Gebiet, Wachen, Kliniken sowie Unterstützung prüfen.

## Häufige Fehler

| Problem | Prüfung |
|---|---|
| Editor meldet keine Berechtigung | Bereichsrecht und Leitstellenfreigabe kontrollieren |
| Einsätze außerhalb des erwarteten Bereichs | GeoJSON und Ortsbindung der Einsatzvorlage prüfen |
| Wachen fehlen | direkte und Nebenleitstellen-Zuordnungen kontrollieren |
| Krankenhaus fehlt | Krankenhausfreigabe der Leitstelle prüfen |
| Nachbarunterstützung fehlt | Nachbarzuordnung und simulierte Auslastung prüfen |
| Polizei hat falsches Bild | Default-Fahrzeugbild und gespeicherten Bildpfad prüfen |
| Signallichter fehlen | Bild, Lichtpunkte und Dateien unter `img/signal/` prüfen |

---

[Wiki-Start](README.md) · [Nächster Artikel: Nebenleitstellen-Editor](nebenleitstellen-editor.md)
