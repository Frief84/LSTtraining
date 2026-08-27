# Erste Schritte

[Wiki-Start](README.md) · [Spielerhandbuch](spielerhandbuch.md) · [Administration](administration.md)

Diese Seite führt Administratoren von der Installation bis zur ersten startfähigen Simulation.

## Voraussetzungen

- eine funktionierende WordPress-Installation;
- eine von WordPress unterstützte PHP-Version mit PDO-MySQL;
- eine MySQL-/MariaDB-Datenbank, intern oder extern;
- Schreibzugriff auf das Plugin-Verzeichnis während der Installation;
- optional ein OpenRouteService-API-Schlüssel für die Routenberechnung.

Versionsanforderungen sollten vor einem produktiven Einsatz zusätzlich gegen die aktuell eingesetzte WordPress-, PHP- und Datenbankversion getestet werden.

## Installation

1. Das Repository nach `wp-content/plugins/lsttraining-plugin` kopieren oder klonen.
2. **LSTtraining** unter **Plugins** aktivieren.
3. Als Administrator eine WordPress-Adminseite öffnen. Aktivierung beziehungsweise `admin_init` führt erforderliche Migrationen aus.
4. Unter **LST Training → Einstellungen** den Datenbankmodus, die Kartenseite und gegebenenfalls den OpenRouteService-Schlüssel eintragen.
5. **Datenbankschema prüfen und aktualisieren** ausführen. Der wiederholte Lauf muss ohne Dubletten und ohne Fehler abschließen.

Vor der Aktivierung eines Upgrades ist ein Datenbank-Backup erforderlich. MySQL-DDL kann implizite Commits ausführen und lässt sich deshalb nicht zuverlässig als eine einzige Transaktion zurückrollen.

## Datenbankmodus

### WordPress-Datenbank

Die Plugin-Tabellen liegen in derselben Datenbank wie WordPress. Zusätzliche Zugangsdaten sind nicht erforderlich.

### Externe Datenbank

Unter **LST Training → Einstellungen** werden Host, Benutzer, Passwort und Datenbankname hinterlegt. Der Datenbankbenutzer benötigt die für Installation und Migration notwendigen Schema-Rechte sowie im Betrieb Lese- und Schreibrechte auf den Plugin-Tabellen.

Zugangsdaten gehören nicht in die Dokumentation, in Screenshots oder in das Repository.

## WordPress-Seiten und Shortcodes

Das Plugin stellt zwei zentrale Shortcodes bereit:

| Shortcode | Zweck |
|---|---|
| `[lsttraining_start]` | Spielstart, gespeicherte Spiele und offene Multiplayer-Spiele |
| `[lsttraining_profile]` | Benutzerprofil, Schwierigkeit und Fahrzeugdarstellung |

Empfohlener Aufbau:

1. eine Seite **Simulation** mit `[lsttraining_start]` anlegen;
2. diese Seite unter **LST Training → Einstellungen → Kartenanzeige auf Seite** auswählen;
3. eine Seite **Profil** mit `[lsttraining_profile]` anlegen;
4. beide Seiten nur angemeldeten Benutzern zugänglich machen.

Die eigentliche Simulation öffnet sich in einer eigenen Vollbildansicht.

## Grundeinstellungen

| Einstellung | Bedeutung |
|---|---|
| Kartenanzeige auf Seite | WordPress-Seite für Start und Simulation |
| Datenbank-Modus | WordPress-Datenbank oder externe Datenbank |
| Externe DB-Felder | Verbindung zur externen Datenbank |
| OpenRouteService API-Key | Routing für Fahrzeugwege |
| Default-Fahrzeugbild | Fallback für Fahrzeuge ohne eigenes Bild |

## Empfohlene Ersteinrichtung

Die Reihenfolge vermeidet unzugeordnete Objekte:

1. Leitstelle mit Position und Einsatzgebiet anlegen.
2. Nebenleitstellen und Nachbarbeziehungen anlegen.
3. Krankenhäuser und Fachbereiche anlegen und der Leitstelle freigeben.
4. Wachen anlegen und Leitstellen beziehungsweise Nebenleitstellen zuordnen.
5. Fahrzeuge den Wachen zuordnen.
6. POIs, OSM-Layer und Einsatzvorlagen pflegen.
7. Anruferprofile anlegen.
8. Benutzerrechte pro Bereich und Leitstelle vergeben.
9. Testsimulation als Administrator durchführen.
10. Testsimulation mit einem eingeschränkten Benutzer durchführen.

Weitere Details stehen unter [Administration und Stammdaten](administration.md).

## Erste Funktionsprüfung

- Die Backend-Menüs erscheinen nur entsprechend der Rechte.
- Leitstelle, Wache und Fahrzeug lassen sich anlegen und erneut öffnen.
- Die Startseite lädt die erlaubten Leitstellen.
- Eine neue Einzelspielerinstanz öffnet den Workspace.
- Karte, Fahrzeuge, Wachen und Krankenhäuser werden geladen.
- Eine Route lässt sich berechnen.
- Reines Aktualisieren des Snapshots verändert keinen Zustand.
- Unter **Hilfe & Dokumentation** sieht ein Nicht-Administrator keine Administrator- oder API-Bereiche.

## Update einer bestehenden Installation

1. Datenbank und Plugin-Dateien sichern.
2. neuen Code zunächst in Staging bereitstellen;
3. Plugin aktivieren beziehungsweise als Administrator das Backend öffnen;
4. Schema-Version und Fehlermeldungen kontrollieren;
5. statische Tests und die Abnahme-Checkliste ausführen;
6. erst danach produktiv übernehmen.

Die ausführliche Checkliste steht unter [Betrieb und Fehlerbehebung](betrieb-und-fehlerbehebung.md).

---

[Zurück zur Wiki-Startseite](README.md) · [Weiter: Administration und Stammdaten](administration.md)
