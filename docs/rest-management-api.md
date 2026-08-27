# REST-Verwaltungs-API

Die Verwaltungs-API bietet Erstellen, Lesen, Aendern und Loeschen fuer:

- `leitstellen`
- `nebenleitstellen`
- `wachen`
- `fahrzeuge`
- `krankenhaeuser`

Alle Aufrufe erfordern eine WordPress-Anmeldung. Im Browser werden Sitzung und
`X-WP-Nonce` verwendet; externe Clients koennen ueber HTTPS mit WordPress
Application Passwords arbeiten. Die vorhandenen Bereichs- und
Leitstellenrechte werden auch fuer REST-Aufrufe ausgewertet.

## Endpunkte

```text
GET    /wp-json/lst/v1/verwaltung/{ressource}
POST   /wp-json/lst/v1/verwaltung/{ressource}
GET    /wp-json/lst/v1/verwaltung/{ressource}/{id}
PATCH  /wp-json/lst/v1/verwaltung/{ressource}/{id}
DELETE /wp-json/lst/v1/verwaltung/{ressource}/{id}?confirm=true
```

Listen unterstuetzen `search`, `page` und `per_page` (maximal 200).
`POST` und `PATCH` erwarten ein JSON-Objekt. Unbekannte Felder werden nicht in
die Datenbank geschrieben.

Erfolgreiche Antworten verwenden immer diese Huelle:

```json
{
  "ok": true,
  "data": {}
}
```

Fehler enthalten `ok: false`, einen stabilen Wert in `error` und eine
menschenlesbare `message`. Live- und Verwaltungsantworten senden
`Cache-Control: no-store, private`.

## Vollstaendige Feldreferenz

### Leitstellen

| Feld | Typ | Pflicht/Regel |
|---|---|---|
| `name` | String | Pflicht, maximal 255 Zeichen |
| `ort` | String/null | maximal 255 Zeichen |
| `bundesland` | String/null | maximal 255 Zeichen |
| `land` | String/null | maximal 100 Zeichen, Standard `Deutschland` |
| `latitude` | Zahl/null | -90 bis 90 |
| `longitude` | Zahl/null | -180 bis 180 |
| `geojson` | JSON/null | Objekt oder JSON-String |
| `available_hospitals` | Integer-Liste | alle IDs muessen existieren |
| `police_vehicle_image` | String/null | maximal 255 Zeichen |
| `police_signal_lights_json` | Signallicht-JSON/null | Koordinaten werden auf 0 bis 1 begrenzt |
| `rescue_vehicle_image` | String/null | maximal 255 Zeichen |
| `rescue_signal_lights_json` | Signallicht-JSON/null | Koordinaten werden auf 0 bis 1 begrenzt |

Beziehungsfelder: `nebenleitstellen`, `wachen`. Neue Leitstellen duerfen nur
WordPress-Administratoren anlegen.

### Nebenleitstellen

| Feld | Typ | Pflicht/Regel |
|---|---|---|
| `name` | String | Pflicht, eindeutig, maximal 255 Zeichen |
| `aufgaben` | Text/null | optional |
| `zustandigkeit` | Text/null | optional |
| `standorte` | Text/null | optional |
| `einwohner` | Integer/null | mindestens 0 |
| `flaeche_km2` | Zahl/null | mindestens 0 |
| `gps` | String/null | maximal 255 Zeichen |
| `nachbarleitstelle` | Boolean/null | optional |
| `geojson` | JSON/null | Objekt oder JSON-String |

Beziehungsfelder: `leitstellen`, `wachen`. Nicht-Administratoren muessen beim
Anlegen mindestens eine erlaubte Leitstelle zuordnen.

### Wachen

| Feld | Typ | Pflicht/Regel |
|---|---|---|
| `name` | String | Pflicht, maximal 255 Zeichen |
| `typ` | String | Pflicht, maximal 50 Zeichen |
| `bundesland` | String/null | maximal 50 Zeichen |
| `land` | String/null | maximal 64 Zeichen, Standard `Deutschland` |
| `latitude` | Zahl | Pflicht, -90 bis 90 |
| `longitude` | Zahl | Pflicht, -180 bis 180 |
| `arrival_pos` | String/null | maximal 50 Zeichen |
| `departure_pos` | String/null | maximal 50 Zeichen |
| `bild_datei` | String | maximal 255 Zeichen |
| `exists_in_reality` | Boolean | Standard `true` |
| `source_note` | String/null | maximal 255 Zeichen |

Beziehungsfelder: `leitstellen`, `nebenleitstellen`. Detailantworten enthalten
zusaetzlich die nur lesbare Liste `relations.fahrzeuge`.

### Fahrzeuge

| Feld | Typ | Pflicht/Regel |
|---|---|---|
| `wache_id` | Integer | Pflicht, vorhandene und erlaubte Wache |
| `rufname` | String | Pflicht, innerhalb der Wache eindeutig, maximal 100 Zeichen |
| `fahrzeugtyp` | String | Pflicht, maximal 100 Zeichen |
| `source_note` | String/null | maximal 255 Zeichen |
| `is_first_responder` | Boolean | Standard `false` |
| `status` | Enum | `frei`, `besetzt`, `einsatzbereit`, `nicht einsatzbereit` |
| `fms_status` | Enum | `1` bis `6` |
| `sondersignal` | Boolean | Standard `false` |
| `dienstzeiten` | String/null | maximal 255 Zeichen |
| `latitude` | Zahl/null | -90 bis 90 |
| `longitude` | Zahl/null | -180 bis 180 |
| `bild_datei` | String/null | maximal 255 Zeichen |
| `signal_lights_json` | Signallicht-JSON/null | normalisierte Lichtpunkte |

Diese Route aendert Fahrzeug-Stammdaten. Der Zustand eines Fahrzeugs in einer
laufenden Simulation wird ueber die Status-API geschrieben.

### Krankenhaeuser

| Feld | Typ | Pflicht/Regel |
|---|---|---|
| `poi_id` | String | maximal 50 Zeichen und eindeutig; beim Anlegen optional |
| `name` | String | Pflicht, maximal 255 Zeichen |
| `latitude` | Zahl | Pflicht, -90 bis 90 |
| `longitude` | Zahl | Pflicht, -180 bis 180 |
| `versorgungsstufe` | Enum | `Grundversorgung`, `Schwerpunktversorger`, `Maximalversorger` |
| `trauma_level` | Integer | 0 bis 9 |
| `helipad` | Boolean | Standard `false` |
| `departments` | JSON | Standard `[]` |

Fehlt `poi_id`, erzeugt der Server `manual-<UUID>`. Detailantworten enthalten
unter `relations.leitstellen` alle Leitstellen, die das Krankenhaus freigeben.

## Beispiele

Eine Wache mit Zuordnungen anlegen:

```http
POST /wp-json/lst/v1/verwaltung/wachen
Content-Type: application/json
X-WP-Nonce: <REST-NONCE>

{
  "name": "Feuer- und Rettungswache Mitte",
  "typ": "FRRD",
  "latitude": 52.52,
  "longitude": 13.405,
  "leitstellen": [3],
  "nebenleitstellen": [7]
}
```

Ein Fahrzeug anlegen:

```http
POST /wp-json/lst/v1/verwaltung/fahrzeuge
Content-Type: application/json
X-WP-Nonce: <REST-NONCE>

{
  "wache_id": 12,
  "rufname": "Florian 1-46-1",
  "fahrzeugtyp": "HLF 20",
  "status": "einsatzbereit",
  "fms_status": "2"
}
```

Ein Krankenhaus aktualisieren:

```http
PATCH /wp-json/lst/v1/verwaltung/krankenhaeuser/9
Content-Type: application/json
X-WP-Nonce: <REST-NONCE>

{
  "helipad": true,
  "departments": [
    {"CARD": {"Lat": 52.51, "Long": 13.40}}
  ]
}
```

## Beziehungen

- Leitstellen akzeptieren `nebenleitstellen`, `wachen` und
  `available_hospitals` als ID-Listen.
- Nebenleitstellen akzeptieren `leitstellen` und `wachen` als ID-Listen.
- Wachen akzeptieren `leitstellen` und `nebenleitstellen` als ID-Listen.
- Fahrzeuge werden ueber `wache_id` einer Wache zugeordnet.
- Krankenhausfreigaben werden ueber `available_hospitals` an der Leitstelle
  gepflegt.

Beziehungsfelder ersetzen bei `PATCH` jeweils die komplette bestehende
Zuordnung dieses Feldes. Nicht uebermittelte Beziehungen bleiben unveraendert.
Schreibvorgaenge mit mehreren Tabellen laufen in einer Datenbanktransaktion.

`DELETE` verlangt immer `confirm=true`. Abhaengige Datensaetze werden gemaess
den Fremdschluesseln des Schemas mitgeloescht. Beim Loeschen eines Krankenhauses
wird dessen ID ausserdem aus allen Leitstellenfreigaben entfernt.

## HTTP-Statuscodes und Fehlerwerte

| Status | Bedeutung | Typische `error`-Werte |
|---|---|---|
| `400` | Anfrage oder Felder ungueltig | `invalid_json`, `validation_failed`, `no_changes` |
| `401` | nicht angemeldet | `lst_manage_not_logged_in` |
| `403` | Bereichs-, Scope- oder Objektberechtigung fehlt | `lst_manage_forbidden`, `forbidden` |
| `404` | Ressource oder Datensatz fehlt | `lst_manage_unknown_resource`, `not_found` |
| `409` | Eindeutigkeits- oder Fremdschluesselkonflikt | `name_conflict`, `rufname_conflict`, `poi_id_conflict`, `conflict` |
| `500` | Datenbankverbindung oder Schreiben fehlgeschlagen | `db_connection_failed`, `db_query_failed`, `db_write_failed` |

Alle Schreiboperationen werden mit Quelle `rest-management` im
Aktivitaetsprotokoll erfasst.
