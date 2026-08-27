# Fahrzeug-Editor

[Wiki-Start](README.md) · [Wachen-Editor](wachen-editor.md) · [Polizei und Unterstützung](polizei-und-unterstuetzungsfahrzeuge.md)

**Backend-Menü:** LST Training → Fahrzeuge

Der Fahrzeug-Editor verwaltet reguläre Fahrzeug-Stammdaten. Jedes Fahrzeug gehört genau zu einer Wache; daraus wird sein Leitstellen-Scope serverseitig ermittelt.

## Benötigte Berechtigung

- Bereichsrecht **Fahrzeuge**;
- Zugriff auf den vollständigen Scope der bisherigen Wache;
- beim Wechsel der Wache zusätzlich Zugriff auf den vollständigen Scope der Zielwache.

## Listenansicht

Filter und Funktionen:

- Suche nach Rufname oder Wachenname;
- Bundesland;
- Leitstelle;
- Nebenleitstelle;
- Anzahl pro Seite und Seitennavigation;
- Sortierung nach ID, Rufname oder Wache;
- direkter Kontext einer ausgewählten Wache.

Die Liste enthält nur Fahrzeuge aus erlaubten Leitstellenbereichen.

## Editorfelder

| Feld | Verwendung |
|---|---|
| Rufname | Funkname; innerhalb einer Wache eindeutig |
| Fahrzeugtyp | Auswahl aus dem Fahrzeugtypen-Katalog |
| Land/Bundesland | Filtert die auswählbaren Wachen |
| Wache | stationärer Standort und Berechtigungs-Scope |
| Quelle | Herkunft beziehungsweise interner Hinweis |
| FMS-Status | Default für neue Instanzen, üblicherweise `2` oder `6` |
| Dienstzeiten | textliche Verfügbarkeit |
| Bild-Datei | individuelle Fahrzeugdarstellung |
| First Responder | besondere Einsatzfunktion |
| Blaulichter | normalisierte Lichtpunkte auf dem Bild |

Wache und Rufname sind Pflichtfelder.

## Fahrzeugbild

Ein Bild kann hochgeladen und anschließend in der Vorschau geprüft werden. Ohne individuelles Bild verwendet die Darstellung den vorgesehenen Fallback.

Die Bildposition sollte vor dem Signallicht-Editor feststehen, weil die Lichtpunkte relativ zum Bild gespeichert werden.

## Blaulicht-Editor

Vorlagen:

- RTW/KTW;
- NEF;
- Feuerwehr;
- Polizei;
- Leer.

Lichttypen:

- Rundumleuchte (`beacon`);
- Frontblitzer (`strobe`);
- Lichtbalken (`bar`);
- Glow (`glow`).

Bedienung:

1. Fahrzeugbild auswählen.
2. Vorlage wählen oder mit einer leeren Konfiguration beginnen.
3. auf das Bild klicken, um ein Licht zu setzen.
4. Licht per Ziehen positionieren.
5. Typ, Intervall, Phase und Größe einstellen.
6. nicht benötigte Lichter löschen.
7. Fahrzeug speichern und Vorschau kontrollieren.

## Fahrzeug in eine andere Wache verschieben

Beim Speichern prüft der Server sowohl das bestehende Fahrzeugobjekt als auch die Zielwache. Eine fremde Wachen-ID im Request reicht nicht aus.

Nach einem Wechsel kontrollieren:

- Sichtbarkeit in Fahrzeug- und Wachenlisten;
- Leitstellen- und Nebenleitstellen-Scope;
- vorhandene Benutzerfreigaben;
- Auswirkungen auf neue Instanzen.

## Stammdaten und Spielzustand

Der Editor verändert Stammdaten. Bereits laufende Instanzen verwenden eine eigene Baseline und instanzbezogene Deltas. FMS, Position oder Sondersignal einer laufenden Simulation werden nicht über diesen Editor gesteuert.

## Löschen

Die Aktion verlangt POST, Nonce und Objektberechtigung. Vorher prüfen, ob das Fahrzeug in gespeicherten oder laufenden Instanzen referenziert wird.

## Häufige Fehler

| Problem | Prüfung |
|---|---|
| Zielwache fehlt | Region, Filter und Zielberechtigung prüfen |
| Rufname kann nicht gespeichert werden | Eindeutigkeit innerhalb der Wache prüfen |
| Bild fehlt | Dateipfad, Upload und Fallback kontrollieren |
| Blaulichter fehlen | Lichtpunkte speichern und Signal-SVGs deployen |
| Live-FMS ändert sich nicht | Stammdateneditor nicht mit Instanzstatus verwechseln |
| Verschieben wird abgelehnt | Rechte für bisherigen und neuen Scope prüfen |

---

[Wiki-Start](README.md) · [Polizei und Unterstützungsfahrzeuge](polizei-und-unterstuetzungsfahrzeuge.md)
