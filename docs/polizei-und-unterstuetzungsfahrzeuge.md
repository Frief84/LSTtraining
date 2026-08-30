# Polizei und Unterstützungsfahrzeuge

[Wiki-Start](README.md) · [Leitstellen-Editor](leitstellen-editor.md) · [Fahrzeug-Editor](fahrzeuge-editor.md)

Polizeifahrzeuge werden derzeit nicht in einem eigenständigen Polizei-Stammdateneditor verwaltet. Die sichtbare Standardkonfiguration befindet sich im **Leitstellen-Editor → Default-Fahrzeuge**.

## Benötigte Berechtigung

Der Artikel und die Konfiguration sind für Benutzer mit Leitstellen- oder Fahrzeugrecht sowie für Administratoren vorgesehen. Normale Spieler sehen die technische Editorbeschreibung nicht.

## Aktueller Aufbau

| Fahrzeugart | Konfiguration |
|---|---|
| reguläre Feuerwehr-/Rettungsfahrzeuge | Fahrzeug-Editor und zugeordnete Wache |
| dynamisches Polizeifahrzeug | Polizei-Default der konkreten Leitstelle |
| dynamisches Rettungs-/Unterstützungsfahrzeug | Rettungsdienst-Default der konkreten Leitstelle |
| Nachbarleitstellen-Fahrzeug | dynamisch aus Unterstützungsangebot und Instanzzustand |

## Polizei im Leitstellen-Editor konfigurieren

1. **LST Training → Leitstellen** öffnen.
2. gewünschte Leitstelle bearbeiten.
3. Abschnitt **Default-Fahrzeuge** öffnen.
4. bei **Polizei-Fahrzeugbild** einen Bildpfad beziehungsweise eine Mediendatei auswählen.
5. **Blaulichter bearbeiten** öffnen.
6. Vorlage **Polizei** wählen oder Lichtpunkte manuell setzen.
7. Leitstelle speichern.
8. Testeinsatz mit Polizeibeteiligung beziehungsweise dynamischer Unterstützung starten.

Die Default-Konfiguration wird pro Leitstelle gespeichert:

- `police_vehicle_image`;
- `police_signal_lights_json`;
- `rescue_vehicle_image`;
- `rescue_signal_lights_json`.

## Signallicht-Editor

Ein Klick auf das Fahrzeugbild setzt einen Lichtpunkt. Vorhandene Punkte können verschoben und gelöscht werden.

Einstellbar sind:

- Typ: Rundumleuchte, Frontblitzer, Lichtbalken oder Glow;
- Intervall zwischen 120 und 2000 Millisekunden;
- Phasenverschiebung;
- Größe zwischen 0,4 und 2,5;
- relative X-/Y-Position auf dem Bild.

Die Polizei-Vorlage setzt versetzte Lichtbalkenpunkte. Die Speicherung erfolgt als normalisiertes JSON, damit absolute Bildabmessungen keine Rolle spielen.

## Benötigte Grafikdateien

```text
img/fahrzeug/default.png
img/signal/beacon.svg
img/signal/strobe.svg
img/signal/lightbar.svg
img/signal/glow.svg
img/signal/editor-point.svg
```

Fehlt ein spezielles Polizeibild, wird `img/fahrzeug/default.png` verwendet.

## Verhalten in der Simulation

Dynamische Polizei- und Nachbarfahrzeuge besitzen keine normale stationäre Fahrzeug-Baseline aus dem Bootstrap. Ihre aktuelle Position wird deshalb im Snapshot übertragen, solange sie Teil der Instanz sind.

Sie verändern keine globalen Fahrzeug-Stammdaten. Zustand und Position gehören ausschließlich zur jeweiligen Spielinstanz.

## Abgrenzung zum Fahrzeug-Editor

Die Polizei-Vorlage im Fahrzeug-Editor ist nur eine Signallicht-Vorlage für ein regulär angelegtes Fahrzeug. Sie erzeugt keinen besonderen Polizei-Datensatz und ändert nicht die Default-Polizei einer Leitstelle.

Wenn künftig dauerhaft stationierte Polizeifahrzeuge mit eigenen Wachen, Rufnamen, Dienstzeiten und Status benötigt werden, ist dafür ein eigener fachlicher Datenbereich beziehungsweise eine Erweiterung des bestehenden Fahrzeugmodells erforderlich. Dieser eigenständige Polizei-CRUD-Editor existiert aktuell nicht.

## Häufige Fehler

| Problem | Prüfung |
|---|---|
| falsches Polizeibild | Default der tatsächlich gespielten Leitstelle prüfen |
| keine Blaulichter | JSON speichern und Signal-SVGs kontrollieren |
| Polizei-Vorlage verändert reguläres Fahrzeug | Unterschied zwischen Fahrzeugeditor-Preset und Leitstellen-Default beachten |
| Position fehlt | prüfen, ob das dynamische Fahrzeug wirklich zur Instanz gehört |
| Bildänderung wirkt nicht in altem Spiel | gespeicherte Instanz und Browsercache prüfen; neue Instanz gegen Stammdaten testen |

---

[Wiki-Start](README.md) · [Zurück zum Leitstellen-Editor](leitstellen-editor.md)
