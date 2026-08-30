# REST-API Praxisanleitung

Diese Seite erklaert, wie die REST-APIs von LSTtraining praktisch genutzt
werden. Die Detailreferenzen stehen in
[REST-Verwaltungs-API](rest-management-api.md) und
[REST-Status-API](rest-status-api.md).

## Grundprinzip

Alle Routen beginnen mit:

```text
/wp-json/lst/v1
```

Es gibt zwei API-Bereiche:

- **Verwaltungs-API:** liest, erstellt, aendert und loescht Stammdaten wie
  Leitstellen, Nebenleitstellen, Wachen, Fahrzeuge und Krankenhaeuser.
- **Status-API:** liest und aendert den Live-Zustand einer laufenden
  Spielinstanz, zum Beispiel Simulationsstatus und instanzbezogene
  Fahrzeugzustaende.

Die API ersetzt keine Berechtigungspruefung im WordPress-Backend. Jede Anfrage
wird serverseitig gegen Anmeldung, Rolle, Bereichsrecht und Leitstellen-Scope
geprueft.

## Anmeldung und Nonce

Im Browser nutzt ein Client die normale WordPress-Sitzung:

```js
fetch('/wp-json/lst/v1/verwaltung/wachen', {
  method: 'GET',
  credentials: 'same-origin',
  headers: {
    'X-WP-Nonce': restNonce
  }
});
```

`restNonce` muss von WordPress fuer den angemeldeten Benutzer ausgegeben
werden. Schreibende Aufrufe benoetigen zusaetzlich `Content-Type:
application/json`.

Externe Clients koennen ueber HTTPS mit WordPress Application Passwords
arbeiten. Datenbank-Zugangsdaten werden nie an Clients weitergegeben.

## Verwaltungs-API

Die Verwaltungs-API arbeitet mit Ressourcen:

```text
leitstellen
nebenleitstellen
wachen
fahrzeuge
krankenhaeuser
```

Die allgemeinen Routen sind:

```text
GET    /verwaltung/{ressource}
POST   /verwaltung/{ressource}
GET    /verwaltung/{ressource}/{id}
PATCH  /verwaltung/{ressource}/{id}
DELETE /verwaltung/{ressource}/{id}?confirm=true
```

`POST` legt einen neuen Datensatz an. `PATCH` aendert einen vorhandenen
Datensatz. Bei `PATCH` muessen nur die Felder gesendet werden, die sich
aendern sollen.

Unbekannte Felder werden abgelehnt. Die API ignoriert sie nicht still.

## Daten hochladen

Mit "hochladen" ist bei der REST-API gemeint: Daten werden als JSON an den
Server gesendet. Es wird keine Datei per FTP verschoben.

Beispiele fuer typische Daten:

```json
{
  "name": "Rettungswache Mitte",
  "typ": "RW",
  "latitude": 52.52,
  "longitude": 13.405,
  "leitstellen": [3]
}
```

```json
{
  "geojson": {
    "type": "FeatureCollection",
    "features": []
  }
}
```

```json
{
  "departments": ["CARD", "NEURO"]
}
```

```json
{
  "signal_lights_json": {
    "version": 1,
    "lights": [
      { "x": 0.5, "y": 0.2, "type": "beacon", "interval": 420 }
    ]
  }
}
```

ID-Listen wie `leitstellen`, `nebenleitstellen`, `wachen` oder
`available_hospitals` werden als JSON-Arrays aus vorhandenen IDs uebertragen.
Wenn eine Beziehungsliste per `PATCH` gesendet wird, ersetzt sie die bisherige
Liste dieser Beziehung.

## Bilder hochladen

Bildfelder akzeptieren entweder eine sichere lokale Referenz oder neue
Bilddaten als Base64-Objekt.

Lokale Referenz:

```json
{
  "bild_datei": "img/fahrzeug/default.png"
}
```

Neues Bild:

```json
{
  "bild_datei": {
    "filename": "rtw-mitte.png",
    "mime_type": "image/png",
    "data_base64": "iVBORw0KGgoAAA..."
  }
}
```

Zulaessige Bildtypen sind PNG, JPEG, GIF, WebP und SVG. Rasterbilder werden
vollstaendig decodiert und neu codiert, bevor sie gespeichert werden. SVG wird
ohne Netzwerkzugriff geparst und auf statische, erlaubte Elemente reduziert.

Beliebige externe Bild-URLs sind nicht erlaubt. Damit wird verhindert, dass
der Server ungepruefte Fremdinhalte nachlaedt oder aktive Inhalte speichert.

## Objekte verschieben

"Verschieben" bedeutet in der API meistens: eine fachliche Zuordnung wird
geaendert.

Eine Wache einer anderen Leitstelle zuordnen:

```http
PATCH /wp-json/lst/v1/verwaltung/wachen/17
Content-Type: application/json
X-WP-Nonce: <REST-NONCE>

{
  "leitstellen": [5]
}
```

Eine Wache Nebenleitstellen zuordnen:

```json
{
  "nebenleitstellen": [8, 9]
}
```

Ein Fahrzeug in eine andere Wache verschieben:

```http
PATCH /wp-json/lst/v1/verwaltung/fahrzeuge/44
Content-Type: application/json
X-WP-Nonce: <REST-NONCE>

{
  "wache_id": 22
}
```

Ein Krankenhaus fuer eine Leitstelle freigeben:

```http
PATCH /wp-json/lst/v1/verwaltung/leitstellen/3
Content-Type: application/json
X-WP-Nonce: <REST-NONCE>

{
  "available_hospitals": [4, 11, 15]
}
```

Der Server prueft dabei immer, ob der Benutzer fuer den bisherigen und den
neuen Leitstellen-Scope berechtigt ist. Ein sichtbarer Button im Browser ist
nicht die Sicherheit; entscheidend ist die serverseitige Pruefung.

## Status-API

Die Status-API betrifft laufende Spielinstanzen:

```text
GET   /instanzen/{instanz_id}/status
PATCH /instanzen/{instanz_id}/status
GET   /instanzen/{instanz_id}/fahrzeuge
PATCH /instanzen/{instanz_id}/fahrzeuge/{status_id}
```

Status lesen:

```js
const response = await fetch('/wp-json/lst/v1/instanzen/42/status', {
  credentials: 'same-origin',
  headers: { 'X-WP-Nonce': restNonce }
});
const status = await response.json();
```

Simulationsstatus aendern:

```json
{
  "paused": true,
  "speed": 2
}
```

Fahrzeugstatus in einer Instanz aendern:

```http
PATCH /wp-json/lst/v1/instanzen/42/fahrzeuge/91
Content-Type: application/json
X-WP-Nonce: <REST-NONCE>

{
  "fms_status": "3",
  "sondersignal": true,
  "bemerkung": "auf Anfahrt"
}
```

Diese Aenderung betrifft den Zustand in der Spielinstanz. Sie aendert nicht die
Fahrzeug-Stammdaten. Eine pausierte Simulation kann Live-Fahrzeugstatusaenderungen
mit HTTP 409 ablehnen.

## Antworten und Fehler

Erfolgreiche Antworten haben diese Form:

```json
{
  "ok": true,
  "data": {}
}
```

Fehler enthalten:

```json
{
  "ok": false,
  "error": "validation_failed",
  "message": "Ungueltige Eingabe."
}
```

Typische Statuscodes:

| Status | Bedeutung |
|---|---|
| 400 | ungueltige Daten oder unbekannte Felder |
| 401 | nicht angemeldet |
| 403 | fehlende Rechte |
| 404 | Datensatz oder Instanz nicht gefunden |
| 409 | Konflikt, zum Beispiel pausierte Simulation |
| 500 | Server- oder Datenbankfehler |

## Sicherheitsregeln

- Schreibende Routen akzeptieren nur JSON-Objekte.
- Felder, IDs, GeoJSON, Fachbereiche, Bilddaten und Signallichter werden streng
  validiert.
- Loeschungen brauchen `confirm=true`.
- Mehrtabellen-Aenderungen laufen in Transaktionen.
- Live-Schreibzugriffe duerfen nur Einsatzleiter der Instanz oder
  Administratoren ausfuehren.
- Neue Leitstellen duerfen ueber die Verwaltungs-API nur Administratoren
  anlegen.

---

[Wiki-Startseite](README.md) · [REST-Verwaltungs-API](rest-management-api.md) · [REST-Status-API](rest-status-api.md)
