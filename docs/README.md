# LSTtraining-Wiki

Willkommen in der zentralen Dokumentation von LSTtraining. Diese Startseite ordnet die Anleitungen nach Aufgabe und Zielgruppe. Jede Themenseite führt zur Wiki-Startseite und zu passenden Vertiefungen zurück.

> **Sichtbarkeit:** Dieses `docs/`-Verzeichnis gehört zum öffentlichen Repository. Es enthält deshalb keine Passwörter, API-Schlüssel oder sonstigen Geheimnisse. Die WordPress-Hilfeseite blendet administrative Inhalte rollenabhängig aus; echte Sicherheit wird immer serverseitig durch Berechtigungen gewährleistet.

## Schnellzugriff

| Ich möchte … | Einstieg |
|---|---|
| das Plugin installieren oder aktualisieren | [Erste Schritte](erste-schritte.md) |
| als Spieler eine Simulation bedienen | [Spielerhandbuch](spielerhandbuch.md) |
| Leitstellen einrichten | [Leitstellen-Editor](leitstellen-editor.md) |
| Nebenleitstellen einrichten | [Nebenleitstellen-Editor](nebenleitstellen-editor.md) |
| Krankenhäuser einrichten | [Krankenhäuser-Editor](krankenhaeuser-editor.md) |
| Wachen einrichten | [Wachen-Editor](wachen-editor.md) |
| Fahrzeuge einrichten | [Fahrzeuge-Editor](fahrzeuge-editor.md) |
| Polizei und Unterstützungsfahrzeuge konfigurieren | [Polizei und Unterstützungsfahrzeuge](polizei-und-unterstuetzungsfahrzeuge.md) |
| Multiplayer, Einsatzbearbeitung und Fahrzeugbewegung verstehen | [Simulation und Multiplayer](simulation-und-multiplayer.md) |
| Rechte sicher vergeben | [Sicherheit, Migration und Berechtigungen](sicherheit-migration-multiplayer.md) |
| ein Problem nach einem Update untersuchen | [Betrieb und Fehlerbehebung](betrieb-und-fehlerbehebung.md) |
| Code, Datenfluss oder Schnittstellen verstehen | [Entwicklerübersicht](entwickleruebersicht.md) |

## Dokumentation nach Zielgruppe

### Spieler und Disponenten

- [Spielerhandbuch](spielerhandbuch.md): Profil, Spielstart, Spielmodi, Workspace, Einsätze, Fahrzeuge, Funk, gespeicherte Spiele und häufige Meldungen
- [Simulation und Multiplayer](simulation-und-multiplayer.md): gemeinsamer Instanzzustand, Rollen, Tick, Snapshot, Wetter, Nachbarleitstellen und Krankenhäuser

### Bearbeiter und Ausbilder

- [Erste Schritte](erste-schritte.md): Grundeinrichtung, WordPress-Seiten, Shortcodes und Erstprüfung
- [Administration und Stammdaten](administration.md): alle Backend-Menüpunkte und empfohlene Einrichtungsreihenfolge
- [Leitstellen-Editor](leitstellen-editor.md): Leitstellen, Gebiet, Nachbarn, Krankenhäuser, POIs und Default-Fahrzeuge
- [Nebenleitstellen-Editor](nebenleitstellen-editor.md): Nebenleitstellen, GeoJSON und Zuordnungen
- [Krankenhäuser-Editor](krankenhaeuser-editor.md): Klinikdaten, Fachbereiche und Leitstellenfreigaben
- [Wachen-Editor](wachen-editor.md): Standorte, Typen und Bereichszuordnungen
- [Fahrzeuge-Editor](fahrzeuge-editor.md): Fahrzeugdaten, Wachenwechsel, Bilder und Signallichter
- [Polizei und Unterstützungsfahrzeuge](polizei-und-unterstuetzungsfahrzeuge.md): Default-Konfiguration im Leitstellen-Editor
- [Einsatz-Ortsbindung](einsatz-ortsbindung.md): Einsatzorte, Gebietstypen, Straßen- und Autobahnlayer
- [Krankenhäuser im Simulations-Workspace](simulation-workspace-hospitals.md): Fachabteilungen und Patientenzuweisung
- [Wetter und Nachbarleitstellen-Auslastung](wetter-und-nachbarleitstellen-auslastung.md)

### Administratoren und Betreiber

- [Sicherheit, Migration und Berechtigungen](sicherheit-migration-multiplayer.md)
- [Betrieb und Fehlerbehebung](betrieb-und-fehlerbehebung.md)
- [OSM-Tile-Architektur](osm_tile_architecture.md)

### Entwickler und Integratoren

- [Entwicklerübersicht](entwickleruebersicht.md)
- [REST-Verwaltungs-API](rest-management-api.md)
- [REST-Status-API](rest-status-api.md)
- [OSM-Tile-Architektur](osm_tile_architecture.md)

## Wiki-Seiten

| Seite | Inhalt | Hauptzielgruppe |
|---|---|---|
| [Erste Schritte](erste-schritte.md) | Installation, Update, Einstellungen, Shortcodes | Administratoren |
| [Spielerhandbuch](spielerhandbuch.md) | tägliche Bedienung der Simulation | Spieler |
| [Administration und Stammdaten](administration.md) | Leitstellen, Nebenstellen, Krankenhäuser, Wachen, Fahrzeuge, Einsätze, Anruferprofile | Bearbeiter/Ausbilder |
| [Leitstellen-Editor](leitstellen-editor.md) | Leitstellenstammdaten und zugehörige Verwaltungsfunktionen | Bearbeiter/Ausbilder |
| [Nebenleitstellen-Editor](nebenleitstellen-editor.md) | Nebenleitstellen und Zuordnungen | Bearbeiter/Ausbilder |
| [Krankenhäuser-Editor](krankenhaeuser-editor.md) | Klinikstammdaten, Fachbereiche und Freigaben | Bearbeiter/Ausbilder |
| [Wachen-Editor](wachen-editor.md) | Wachenstammdaten und Bereichswechsel | Bearbeiter/Ausbilder |
| [Fahrzeuge-Editor](fahrzeuge-editor.md) | Fahrzeugstammdaten, Bilder und Signallichter | Bearbeiter/Ausbilder |
| [Polizei und Unterstützungsfahrzeuge](polizei-und-unterstuetzungsfahrzeuge.md) | Polizei- und Rettungsdienst-Defaults | Bearbeiter/Ausbilder |
| [Simulation und Multiplayer](simulation-und-multiplayer.md) | Spielablauf und technische Zustandsregeln | Spieler/Ausbilder |
| [Sicherheit, Migration und Berechtigungen](sicherheit-migration-multiplayer.md) | serverseitige Rechte, CSRF, Schema und Abnahme | Administratoren/Entwickler |
| [Betrieb und Fehlerbehebung](betrieb-und-fehlerbehebung.md) | Backups, Updates, Diagnose und Checklisten | Betreiber |
| [Entwicklerübersicht](entwickleruebersicht.md) | Architektur, Module, Datenfluss, APIs und Tests | Entwickler |
| [REST-Verwaltungs-API](rest-management-api.md) | Stammdaten-CRUD und Felder | Integratoren |
| [REST-Status-API](rest-status-api.md) | Instanz- und Live-Fahrzeugzustände | Integratoren |
| [Einsatz-Ortsbindung](einsatz-ortsbindung.md) | Ermittlung zulässiger Einsatzorte | Ausbilder/Entwickler |
| [Krankenhäuser im Workspace](simulation-workspace-hospitals.md) | Fachbereichs- und Patientenzuordnung | Ausbilder/Entwickler |
| [Wetter und Nachbarleitstellen](wetter-und-nachbarleitstellen-auslastung.md) | Wetterwirkung und Unterstützungsangebot | Ausbilder/Entwickler |
| [OSM-Tile-Architektur](osm_tile_architecture.md) | lokale Kartendaten und OSM-Verarbeitung | Betreiber/Entwickler |

## Begriffe

- **Leitstelle:** Hauptbereich einer Simulation und Berechtigungs-Scope.
- **Nebenleitstelle:** verknüpfter beziehungsweise benachbarter Dispositionsbereich.
- **Wache:** Standort, dem Fahrzeuge und Leitstellenbereiche zugeordnet werden.
- **Instanz:** ein eigener, gespeicherter Spielstand mit Teilnehmern und Fahrzeugzuständen.
- **Baseline:** unveränderlicher Ausgangszustand eines Fahrzeugs innerhalb einer Instanz.
- **Delta:** aktuelle Abweichung von der Baseline.
- **Tick:** serverseitig serialisierter Fortschritt der Simulation.
- **Snapshot:** rein lesende, kompakte Sicht auf den aktuellen Spielzustand.

---

[Projekt-README](../README.md) · [Spielerhandbuch](spielerhandbuch.md) · [Administration](administration.md) · [Entwicklerübersicht](entwickleruebersicht.md)
