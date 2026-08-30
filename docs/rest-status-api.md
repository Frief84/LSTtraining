# REST-API fuer Leitstellen- und Fahrzeugstatus

Die Status-API ist Teil des WordPress-Plugins und greift serverseitig ueber
`lsttraining_get_connection()` auf die konfigurierte WordPress- oder externe
MySQL-Datenbank zu. Datenbank-Zugangsdaten werden nicht an den Browser
uebertragen.

## Authentifizierung und Zugriff

Alle Endpunkte erfordern einen angemeldeten WordPress-Benutzer. Der Benutzer
muss als verbundener Teilnehmer der angefragten Spielinstanz eingetragen sein.
WordPress-Administratoren koennen jede Instanz lesen.

Im Browser wird die normale WordPress-Sitzung zusammen mit dem REST-Nonce
verwendet:

```js
const response = await fetch('/wp-json/lst/v1/instanzen/42/status', {
  credentials: 'same-origin',
  headers: { 'X-WP-Nonce': window.lsttrainingWorkspace.rest_nonce }
});
const payload = await response.json();
```

Externe Clients koennen ueber HTTPS die von WordPress bereitgestellten
Application Passwords verwenden. Es gibt keinen anonymen Zugriff.

## Leitstellen-/Simulationsstatus

`GET /wp-json/lst/v1/instanzen/{instanz_id}/status`

Die Antwort enthaelt:

- Zustand, Aktivitaet, Pause und Geschwindigkeit der Spielinstanz
- Name und Ort der zugehoerigen Leitstelle
- Fahrzeuganzahl gruppiert nach Betriebs- und FMS-Status
- Anzahl der Fahrzeuge mit Sondersignal
- Anzahl offener Einsaetze und verbundener Teilnehmer

Beispielantwort:

```json
{
  "ok": true,
  "data": {
    "instance": {"id": 42, "state": "running", "paused": false, "speed": 1},
    "leitstelle": {"id": 3, "name": "Leitstelle Beispiel"},
    "vehicles": {
      "total": 18,
      "by_status": {"einsatzbereit": 14, "besetzt": 4},
      "by_fms_status": {"2": 14, "3": 4},
      "with_special_signal": 4
    },
    "incidents": {"open": 2},
    "participants": {"connected": 1}
  }
}
```

## Aktuelle Fahrzeugzustaende

`GET /wp-json/lst/v1/instanzen/{instanz_id}/fahrzeuge`

Der Endpunkt liefert fuer jedes Fahrzeug den aktuellen effektiven Zustand. Ist
in `instanz_fahrzeug_status` ein Delta vorhanden, ersetzt dieses die Baseline
aus `fahrzeug_status`. Dadurch sind Status, FMS, Sondersignal, Position und Ziel
immer instanzbezogen.

Optionale Filter:

- `wache_id`
- `fahrzeug_id`
- `fms_status`

Beispiel:

`GET /wp-json/lst/v1/instanzen/42/fahrzeuge?fms_status=3`

Jeder Eintrag enthaelt `status_id`, `fahrzeug_id`, `wache_id`, Rufname,
Fahrzeugtyp, Wache, Position, Ziel, Betriebsstatus, FMS-Status, Sondersignal,
Bemerkung, letzte Aktualisierung und `has_delta`.

Live-Antworten werden mit `Cache-Control: no-store, private` ausgeliefert.

## Live-Zustand schreiben

Nur Einsatzleiter der Instanz und WordPress-Administratoren duerfen diese
Endpunkte verwenden.

Jeder Schreib-Body muss ein JSON-Objekt sein. Unbekannte Felder, falsche
Datentypen, HTML-/Codebestandteile, Steuerzeichen und uebergrosse Inhalte werden
vor dem Datenbankschreiben mit HTTP `400` und `validation_failed` abgelehnt.
Booleans muessen echte JSON-Werte `true` oder `false` sein; Zahlen muessen
endlich sein und innerhalb der dokumentierten Bereiche liegen. Texte wie
`bemerkung` werden als reiner Text ohne HTML oder ausfuehrbare URI-Schemata
akzeptiert und sind auf 2.000 Zeichen begrenzt.

`PATCH /wp-json/lst/v1/instanzen/{instanz_id}/status`

Akzeptiert `state` (`created`, `running`, `paused`), `paused` und `speed`
(`1`, `2` oder `5`). Werden `state` und `paused` gemeinsam gesendet, muessen
beide Angaben denselben Zustand beschreiben.

```json
{"paused": true, "speed": 1}
```

`PATCH /wp-json/lst/v1/instanzen/{instanz_id}/fahrzeuge/{status_id}`

Akzeptiert die Felder `status`, `fms_status`, `sondersignal`, `bemerkung`,
`latitude`, `longitude`, `ziel_latitude` und `ziel_longitude`. Die Aenderung
wird als instanzbezogenes Delta gespeichert und veraendert nicht die
Fahrzeug-Stammdaten. Breiten- und Laengengrade werden gegen `-90..90`
beziehungsweise `-180..180` validiert; `null` entfernt eine optionale Position.

```json
{
  "fms_status": "3",
  "sondersignal": true,
  "bemerkung": "Auf Anfahrt"
}
```

Eine pausierte Simulation antwortet bei Fahrzeugaenderungen mit HTTP `409` und
`simulation_paused`. Veraltete Instanzen ohne Delta-Modell antworten mit HTTP
`409` und `legacy_vehicle_state_model`.

Schreibvorgaenge werden im Aktivitaetsprotokoll mit Quelle `rest-status`
erfasst. Antworten werden nicht gecacht.

Die allgemeinen Stammdaten-Endpunkte sind in
[`rest-management-api.md`](rest-management-api.md) beschrieben.
