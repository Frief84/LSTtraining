# Spielerhandbuch

[Wiki-Start](README.md) · [Erste Schritte](erste-schritte.md) · [Simulation und Multiplayer](simulation-und-multiplayer.md)

Diese Seite enthält ausschließlich die für Spieler und Disponenten notwendige Bedienhilfe. Administrative Datenbank-, Rechte- und API-Funktionen gehören nicht zur normalen Spieleransicht.

## Anmeldung und Profil

Für Start, Fortsetzen und Teilnahme an einer Simulation ist eine WordPress-Anmeldung erforderlich.

Auf der Profilseite mit `[lsttraining_profile]` können Spieler:

- Anzeigename, E-Mail, Vorname und Nachname pflegen;
- ein neues Passwort setzen;
- den Schwierigkeitsgrad neuer eigener Simulationen wählen;
- die Fahrzeugdarstellung auf der Karte auswählen.

### Schwierigkeitsgrade

| Stufe | Verhalten |
|---|---|
| Einsteiger | ruhiger Einstieg und längere Abstände zwischen Lagen |
| Normal | ausgewogene Belastung |
| Anspruchsvoll | kürzere Abstände und mehr parallele Einsätze |
| Realistisch | hohe Grundlast und stärkerer Simulationsdruck |

Die Auswahl wirkt auf neu erstellte Instanzen. Die konkrete Einsatzhäufigkeit wird zusätzlich durch Leitstelle, Zeit, Wetter und Lage beeinflusst.

### Fahrzeugdarstellung

- **Marker:** kompakte FMS-Kreise mit Rufnamen;
- **Bild:** hinterlegtes Fahrzeugbild;
- **Taktisch:** reduzierte taktische Kennzeichnung.

## Neue Simulation starten

Auf der Seite mit `[lsttraining_start]`:

1. eine freigegebene Leitstelle auswählen;
2. Datum und Uhrzeit festlegen oder **Jetzt verwenden** wählen;
3. Jahreszeit automatisch bestimmen lassen oder überschreiben;
4. einen Spielmodus wählen;
5. **Simulation starten** anklicken.

Der Start erzeugt immer eine neue Spielinstanz. Er überschreibt keinen vorhandenen Spielstand.

## Spielmodi

| Modus | Beschreibung |
|---|---|
| Einzelspieler | eine Person steuert die Leitstelle |
| Multiplayer | mehrere Disponenten arbeiten in derselben Instanz |
| Einsatzleiter | ein Leiter gibt Lagen vor, während Disponenten reagieren |

Nur Einsatzleiter und Administratoren dürfen administrative Live-Aktionen wie Pause, Geschwindigkeit oder erzwungene Einsätze ausführen.

## Gespeicherte und offene Spiele

### Meine gespeicherten Spiele

- **Fortsetzen** öffnet die bestehende Instanz.
- Der verantwortliche Ersteller oder ein Administrator darf die Instanz vollständig löschen.
- Ein normaler Teilnehmer kann ein gemeinsames Spiel verlassen. Dadurch wird nur seine Teilnahme entfernt.

### Offene Spiele beitreten

Offene Multiplayer-Instanzen erscheinen im Bereich **Offene Spiele beitreten**. Nach dem Beitritt arbeiten alle Teilnehmer mit demselben serverseitigen Instanzzustand.

## Der Simulations-Workspace

Der Workspace besteht aus verschiebbaren beziehungsweise andockbaren Bereichen:

- **Karte:** Einsätze, Fahrzeuge, Krankenhäuser und Wachen;
- **Fahrzeuge:** Suche, Fachbereichsfilter und aktueller Zustand;
- **Einsätze:** offene Lagen und Auswahl;
- **Einsatzdetails:** Anruf, Adresse, Anforderungen, Patienten und Bearbeitungsstand;
- **Funk:** Meldungen von Leitstelle, Fahrzeugen, Anrufern und System.

Das Layout kann gespeichert und später wieder geladen werden. Kartenebenen lassen sich einzeln ein- und ausblenden.

## Typischer Einsatzablauf

1. Eingehenden Anruf beziehungsweise neue Lage auswählen.
2. Anruf annehmen und die übermittelten Informationen prüfen.
3. Einsatz öffnen und benötigte Kräfte beurteilen.
4. geeignete Fahrzeuge auswählen und disponieren.
5. Fahrzeuge alarmieren.
6. FMS- und Funkmeldungen verfolgen.
7. Rückmeldungen öffnen und bestätigen.
8. bei Bedarf Krankenhaus oder Nachbarunterstützung berücksichtigen.
9. Einsatzstatus aktualisieren und die Lage abschließen.

Eine pausierte Simulation verhindert zeitabhängigen Fortschritt und bestimmte Live-Aktionen.

## Karte und Fahrzeugpositionen

Der Browser erhält beim Start stabile Basisdaten. Danach liefert der Live-Snapshot nur aktuell relevante Änderungen. Ein Fahrzeug erscheint im Positionsanteil, wenn es wesentlich von seiner Ausgangsposition oder Wache abweicht. Frühere Fahrwege sind dort nicht als Verlauf gespeichert.

Kehrt ein Fahrzeug zur Basis zurück, kann es aus der Liste veränderter Positionen verschwinden. Die Oberfläche rekonstruiert seinen stationären Zustand aus den Basisdaten.

## Wetter, Krankenhäuser und Unterstützung

- Die Wetteranzeige zeigt die aktuelle simulierte Lage und gegebenenfalls die nächste Änderung.
- Patienten können passend zu benötigten Fachbereichen einem Krankenhaus zugewiesen werden.
- Falls eigene Kräfte nicht ausreichen, kann – abhängig von der Lage und Berechtigung – Unterstützung aus einer Nachbarleitstelle angefordert werden.

Vertiefungen:

- [Krankenhäuser im Workspace](simulation-workspace-hospitals.md)
- [Wetter und Nachbarleitstellen](wetter-und-nachbarleitstellen-auslastung.md)

## Häufige Meldungen

| Meldung/Situation | Bedeutung und nächster Schritt |
|---|---|
| Sicherheits-Token ungültig | Seite neu laden; die Sitzung beziehungsweise Nonce war abgelaufen |
| Keine Berechtigung | die Leitstelle, Instanz oder Funktion ist für den Benutzer nicht freigegeben |
| Simulation pausiert | auf Fortsetzung durch Einsatzleiter/Administrator warten |
| Route konnte nicht berechnet werden | Verbindung, Routing-Schlüssel und Koordinaten prüfen lassen |
| Keine Fahrzeuge verfügbar | Filter prüfen; Fahrzeuge können gebunden, nicht einsatzbereit oder nicht zugeordnet sein |
| Spiel nicht mehr sichtbar | Teilnahme, Eigentümerstatus oder Aufbewahrungsfrist durch Administrator prüfen lassen |

## Was normale Spieler nicht sehen sollen

Die WordPress-Hilfeseite zeigt normalen Spielern keine Datenbankkonfiguration, Benutzerrechte, REST-API-Referenz, Migrationssteuerung oder Entwicklerprüfungen. Der Server prüft Berechtigungen unabhängig von der sichtbaren Oberfläche.

---

[Zurück zur Wiki-Startseite](README.md) · [Weiter: Simulation und Multiplayer](simulation-und-multiplayer.md)
