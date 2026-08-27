# Simulation und Multiplayer

[Wiki-Start](README.md) · [Spielerhandbuch](spielerhandbuch.md) · [Administration](administration.md)

Diese Seite verbindet die Bedienperspektive mit den Regeln des gemeinsamen Simulationszustands.

## Instanz und Rollen

Jeder Spielstart erzeugt eine eigene Instanz. Stammdaten werden als Ausgangsbasis übernommen; spätere Fahrzeugzustände und Einsätze bleiben instanzbezogen.

| Rolle/Modus | Aufgabe |
|---|---|
| Einzelspieler | vollständige Bearbeitung der eigenen Lage |
| Multiplayer-Disponent | gemeinsame Annahme, Disposition und Funkbearbeitung |
| Einsatzleiter | Lagen vorgeben und administrative Live-Steuerung |
| Administrator | technische und fachliche Vollberechtigung |

Die konkrete Berechtigung wird serverseitig geprüft und nicht aus sichtbaren Buttons abgeleitet.

## Startparameter

Eine Instanz speichert unter anderem:

- Leitstelle;
- Datum, Uhrzeit und Startzeitpunkt;
- automatische oder manuelle Jahreszeit;
- Wetter beziehungsweise Wettervorhersage;
- Spielmodus und Schwierigkeit;
- automatische Einsatzgenerierung, Intervall und maximale aktive Einsätze;
- das Fahrzeugzustandsmodell `baseline_delta_v1`.

## Workspace

### Kopfbereich

Zeigt Leitstelle, Simulationszeit, Wetter, Pause, Geschwindigkeit und Layoutaktionen. Pause und Geschwindigkeit sind auf Einsatzleiter beziehungsweise Administratoren beschränkt.

### Karte

Darstellbare Ebenen:

- Einsätze;
- Fahrzeuge;
- Krankenhäuser;
- Wachen.

Die Karte kann zentriert, maximiert, schwebend dargestellt oder in ein eigenes Fenster ausgelagert werden.

### Fahrzeuge

Die Liste unterstützt Suche und Fachbereichsfilter. Sichtbar sind effektive, instanzbezogene Zustände. Fahrzeugaktionen umfassen je nach Situation Alarmierung, Funkkommando, Rückruf und Aufheben einer Zuordnung.

### Einsätze und Details

Die Einsatzliste führt offene Lagen. Der Detailbereich zeigt Anruf, Standort, benötigte Kräfte, zugeordnete Fahrzeuge, Patienten, Status und Ereignisse. Einsatzleiter können zusätzlich Einsätze vorgeben.

### Funk

Die Timeline kann nach Leitstelle, Fahrzeugen, Anrufer und System gefiltert werden. Rückmeldungen werden geöffnet beziehungsweise bestätigt, damit die Lagebearbeitung nachvollziehbar bleibt.

## Typischer serverseitiger Ablauf

```text
Tick erhält Instanz-Lock
  → Zeit und Zustände fortschreiben
  → automatische Spawn-Bedingungen prüfen
  → gegebenenfalls genau einen Einsatz erzeugen
  → Lock freigeben

Snapshot lesen
  → keine Zustände fortschreiben
  → nur aktuelle kompakte Änderungen liefern
```

Mehrere Browser dürfen Ticks anstoßen, aber nur ein Request erhält gleichzeitig den instanzbezogenen Datenbank-Lock.

## Fahrzeug-Baseline und Delta

- `fahrzeug_status` enthält den unveränderlichen Ausgangszustand der Instanz.
- `instanz_fahrzeug_status` enthält die aktuelle Abweichung.
- Kehrt der vollständige Zustand zur Baseline zurück, kann das Delta entfernt werden.
- Stammdatenänderungen dürfen nicht rückwirkend einen fremden laufenden Spielstand überschreiben.

## Snapshot-Regeln

Der normale Snapshot ist rein lesend. Das Positionsfeld enthält ein Fahrzeug nur, wenn es:

- mehr als 5 Meter von der Instanz-Baseline oder
- mehr als 50 Meter von seiner Wache

entfernt ist. Ziel-, Basis- und Wachenkoordinaten sowie frühere Bewegungen sind kein Teil dieser Positionsliste. Statusänderungen können separat ohne Position in `vehicle_statuses` erscheinen.

Dynamisch bereitgestellte Polizei- oder Nachbarfahrzeuge besitzen keine normale stationäre Bootstrap-Baseline und werden deshalb mit aktueller Position übertragen.

## Einsatzgenerierung

Eine Vorlage muss zum Einsatzgebiet, Ortsmodus, Zeitpunkt, zur Jahreszeit und zum Wetter passen. Danach werden Standort, Anruf, Patienten und Ressourcenbedarf erzeugt. Automatische Spawns werden durch den zentralen Tick serialisiert.

Details:

- [Einsatz-Ortsbindung](einsatz-ortsbindung.md)
- [Wetter und Nachbarleitstellen](wetter-und-nachbarleitstellen-auslastung.md)

## Patienten und Krankenhäuser

Patientenzustände werden nur im autorisierten Tick fortgeschrieben. Ein Zielkrankenhaus kann aus benötigten Fachbereichen und Erreichbarkeit ermittelt oder – sofern vorgesehen – manuell festgelegt werden.

[Ausführliche Krankenhauslogik](simulation-workspace-hospitals.md)

## Nachbarleitstellen-Unterstützung

Angebot und Auslastung einer Nachbarleitstelle hängen von der jeweiligen Konfiguration und Simulation ab. Eine Unterstützung wird angefordert, anschließend angenommen und als dynamisches Fahrzeug in die Instanz aufgenommen. Sie verändert nicht die Stammdaten der Nachbarleitstelle.

## Pause und Geschwindigkeit

- Pause stoppt zeitabhängigen Fortschritt und blockiert bestimmte Fahrzeugaktionen.
- Geschwindigkeiten `1`, `2` und `5` sind vorgesehen.
- Änderungen dürfen nur Einsatzleiter beziehungsweise Administratoren ausführen.
- Ein pausierter Live-Schreibzugriff kann mit HTTP `409` abgewiesen werden.

## Gespeicherte Spiele und Aufbewahrung

- Der Ersteller ist Verantwortlicher einer gemeinsamen Instanz.
- Öffnen und echte Spielaktionen aktualisieren die letzte Aktivität.
- Reines Snapshot-Polling verlängert die Aufbewahrungsfrist nicht.
- Nach einem Kalendermonat ohne Aktivität wird eine Erinnerung versendet.
- Erst nach erfolgreicher Erinnerung wird die Löschung 14 Tage später geplant.
- Teilnehmer können ein Spiel verlassen, ohne die gemeinsame Instanz zu löschen.

## Multiplayer-Abnahme

1. Dieselbe Instanz in zwei Browsern öffnen.
2. parallele Ticks erzeugen; es darf nur ein automatischer Einsatz entstehen.
3. eine Fahrzeugaktion in Browser A ausführen und in Browser B beobachten.
4. Snapshot mehrfach ohne Tick abrufen; die Datenbank darf sich nicht verändern.
5. Pause als Disponent versuchen; der Server muss ablehnen.
6. Pause als Einsatzleiter setzen; beide Browser müssen den Zustand sehen.
7. Fahrzeug zur Wache zurückführen; es darf aus dem Positionsdelta verschwinden.

---

[Zurück zur Wiki-Startseite](README.md) · [Weiter: Sicherheit und Migration](sicherheit-migration-multiplayer.md)
