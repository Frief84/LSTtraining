# Betrieb und Fehlerbehebung

[Wiki-Start](README.md) · [Erste Schritte](erste-schritte.md) · [Sicherheit und Migration](sicherheit-migration-multiplayer.md)

Diese Seite richtet sich an Administratoren und Betreiber. Sie sollte in der WordPress-Hilfe nur Benutzern mit `manage_options` angeboten werden.

## Vor jedem produktiven Update

1. Datenbank-Backup erstellen und Rücksicherungsmöglichkeit prüfen.
2. aktuellen Plugin-Stand beziehungsweise Commit notieren.
3. neue Version zuerst in Staging installieren.
4. statische Prüfungen ausführen.
5. Migration mit einem Administrator starten.
6. PHP-, WordPress- und Datenbanklogs kontrollieren.
7. Berechtigungs- und Multiplayer-Abnahme durchführen.

## Migration und Schema-Version

Die Schema-Version wird pro verwendeter Datenbank gespeichert. Migrationen laufen bei Aktivierung, beim ersten berechtigten Admin-Aufruf nach einem Upgrade oder manuell unter **LST Training → Einstellungen**.

Normale AJAX-, REST-, Frontend- und Snapshot-Aufrufe führen kein Schema-DDL aus.

### Migration fehlgeschlagen

1. Fehlermeldung und Serverlog sichern.
2. keine wiederholten manuellen Änderungen direkt in der Produktionsdatenbank durchführen.
3. Datenbankverbindung und Rechte prüfen.
4. kontrollieren, ob `database/schema.sql` vollständig vorhanden ist.
5. Ursache in Staging beheben.
6. idempotente Migration erneut starten.
7. falls Daten inkonsistent wurden, das geprüfte Backup zurückspielen.

Die Versionsnummer wird erst nach einem erfolgreichen Gesamtlauf gespeichert.

## Statische Prüfungen

Ohne zusätzliche npm-Pakete:

```bash
node tests/static-checks.mjs
```

Geprüft werden JavaScript und JSON, PHP-Struktur, Bootstrap-Dateien, lokale Assets, Basisschema, Laufzeit-DDL, GET-Löschungen, Berechtigungs-Scope, Nonces, Migration, Tick/Snapshot, REST-Routen, Benutzerrechte und Dokumentation.

Zusätzlich:

```bash
git diff --check
```

Diese Prüfungen ersetzen keinen realen WordPress-/PHP-/MySQL-/Browser-Test.

## Häufige Probleme

### Der Menüpunkt fehlt

- prüfen, ob der Benutzer angemeldet ist;
- prüfen, ob mindestens ein LSTtraining-Bereich freigegeben ist;
- bei Adminfunktionen `manage_options` prüfen;
- Bereichsrecht und Leitstellenliste gemeinsam kontrollieren.

Ein normaler Spieler ohne Verwaltungsrecht soll die administrativen Menüpunkte und die technische Dokumentation nicht sehen.

### Objekt ist nicht sichtbar

- Bereichsrecht prüfen;
- freigegebene Leitstellen prüfen;
- direkte und indirekte Zuordnung über Nebenleitstellen prüfen;
- gemeinsam zugeordnetes Objekt benötigt alle betroffenen Leitstellenrechte;
- unzugeordnete Objekte sind für Nicht-Administratoren absichtlich gesperrt.

### Wache oder Fahrzeug lässt sich nicht verschieben

Der Benutzer benötigt Zugriff auf den bisherigen Objektbereich und den vollständigen Zielbereich. Bei einem Fahrzeug wird der Zielbereich über die Zielwache ermittelt.

### Sicherheits-Token/Nonce ungültig

- Seite vollständig neu laden;
- WordPress-Anmeldung kontrollieren;
- Browsercache beziehungsweise sehr lange geöffnete Seite prüfen;
- kontrollieren, ob das JavaScript den richtigen aktionsgebundenen Nonce mitsendet.

### Aktion antwortet mit HTTP 405

Der Endpunkt wurde mit der falschen HTTP-Methode aufgerufen. Schreib- und Löschwege der klassischen Adminoberfläche erwarten `POST`; REST-Verwaltung verwendet je nach Vorgang `POST`, `PATCH` oder `DELETE`.

### Route kann nicht berechnet werden

- OpenRouteService-Schlüssel prüfen;
- Start- und Zielkoordinaten validieren;
- externe Erreichbarkeit des Routingdienstes prüfen;
- Rate-Limit und Serverlog kontrollieren;
- Wachen-Anfahrts-/Abfahrtspositionen prüfen.

### Signallichter oder Fahrzeugbild fehlen

Erwartete Dateien:

```text
img/fahrzeug/default.png
img/signal/beacon.svg
img/signal/strobe.svg
img/signal/lightbar.svg
img/signal/glow.svg
img/signal/editor-point.svg
```

Dateinamen, Groß-/Kleinschreibung, Webserverzugriff und Browsercache prüfen. Signal-SVGs müssen Teil des deployten Commits sein.

### Doppelte automatische Einsätze

- prüfen, ob alle Server denselben MySQL-Datenbankzustand und Advisory-Lock verwenden;
- kontrollieren, ob Ticks den instanzbezogenen Lock erhalten;
- `last_auto_spawn_at` und Tick-Logs prüfen;
- sicherstellen, dass keine alte Plugin-Version parallel aktiv ist.

### Snapshot verändert Daten

Der normale Snapshot muss `advance_state=false` verwenden. Nur der autorisierte Tick darf die Zustandsfortschreibung aktivieren. Mehrfaches Snapshot-Polling ohne Tick muss in einem Datenbankvergleich unverändert bleiben.

### Fahrzeugposition fehlt im Snapshot

Das kann korrekt sein: Positionen werden nur bei relevanter Abweichung von Baseline oder Wache übertragen. Reine Statusänderungen stehen getrennt in `vehicle_statuses`.

### Krankenhaus wird nicht vorgeschlagen

- Krankenhausfreigabe der Leitstelle prüfen;
- Koordinaten kontrollieren;
- benötigte und vorhandene Fachbereiche vergleichen;
- Patientenvorgabe und Einsatzvorlage prüfen;
- Routingfehler ausschließen.

### Einsatz entsteht am falschen Ort

- GeoJSON der Leitstelle validieren;
- Ortsmodus der Einsatzvorlage prüfen;
- Landscape- beziehungsweise POI-Auswahl prüfen;
- lokale OSM-Layer und Tile-Abdeckung kontrollieren;
- bei festem Punkt Koordinate und Radius prüfen.

[Vertiefung: Einsatz-Ortsbindung](einsatz-ortsbindung.md)

## Logs und Nachvollziehbarkeit

- WordPress-/PHP-Fehlerlog für Laufzeitfehler;
- MySQL-Log für Schema- und Abfragefehler;
- **LST Training → Verlauf / Aktivität** für fachliche Schreibaktionen;
- Browser-Netzwerkanalyse für Statuscodes und AJAX-/REST-Antworten.

Zugangsdaten, Application Passwords und API-Schlüssel dürfen beim Weitergeben von Logs nicht enthalten sein.

## Rollenbasierte Hilfe prüfen

1. als normaler Spieler anmelden: kein Admin-/API-/Datenbankbereich sichtbar;
2. als Bereichsbearbeiter anmelden: nur freigegebene Menüs und Objekte sichtbar;
3. als Administrator anmelden: Schema, Benutzerrechte, Updatehinweise und REST-Referenz sichtbar;
4. direkte Requests gegen nicht erlaubte Objekte senden: Server muss unabhängig von der UI ablehnen.

## Abnahme nach Änderungen

### Stammdaten

- Leitstelle, Nebenleitstelle, Krankenhaus, Wache und Fahrzeug jeweils lesen, anlegen, ändern und löschen.
- Einsatzvorlage und Anruferprofil inklusive Vorschau prüfen.
- fremden Leitstellen-Scope negativ testen.

### Simulation

- neue Instanz in jedem Modus starten;
- gespeichertes Spiel fortsetzen;
- Multiplayer mit zwei Browsern testen;
- Anruf annehmen, disponieren, alarmieren und Rückmeldung verarbeiten;
- Patient und Krankenhaus testen;
- Nachbarunterstützung testen;
- Pause und Geschwindigkeit mit erlaubter und unerlaubter Rolle prüfen.

### Daten und Transport

- Bootstrap einmalig laden;
- Snapshot ohne Seiteneffekt mehrfach lesen;
- Position über und unter den Schwellwerten testen;
- Rückkehr zur Wache testen;
- REST-Status und Verwaltungs-API mit erlaubten und unerlaubten Konten testen.

---

[Zurück zur Wiki-Startseite](README.md) · [Weiter: Entwicklerübersicht](entwickleruebersicht.md)
