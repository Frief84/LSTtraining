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
`POST` und `PATCH` erwarten ein JSON-Objekt. Unbekannte Felder werden mit HTTP
`400` abgelehnt und nicht still ignoriert.

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

## Eingabevalidierung und Schutz vor Fremdcode

Jeder schreibende Aufruf wird vor dem ersten Datenbankschreibzugriff strikt
validiert. Ungueltige Werte werden abgelehnt, nicht automatisch in einen
anderen Wert umgewandelt. Insbesondere gelten folgende Regeln:

- Der Body muss ein JSON-Objekt sein und darf einschliesslich optionaler
  Bilddaten hoechstens 16 MiB gross sein.
- Es sind ausschliesslich die fuer die Ressource dokumentierten Feldnamen
  erlaubt. Schreibgeschuetzte Felder wie `id`, `created_at` oder `relations`
  koennen nicht eingeschleust werden.
- Einfache Texte muessen gueltiges UTF-8 sein. HTML-Tags, PHP-Markierungen,
  ausfuehrbare URI-Schemata wie `javascript:` sowie Inline-Eventhandler werden
  abgelehnt. Steuerzeichen und zu lange Inhalte sind ebenfalls ungueltig.
- Zahlen, Ganzzahlen und JSON-Booleans werden typgetreu geprueft. Werte wie
  `"true"`, `"12abc"`, `NaN` oder unendliche Zahlen sind nicht erlaubt.
- Enum-Werte muessen exakt einem dokumentierten Wert entsprechen.
- ID- und Beziehungslisten muessen echte JSON-Listen aus positiven, vorhandenen
  Ganzzahlen sein. Ungueltige Eintraege werden nicht still entfernt.
- Alle SQL-Werte bleiben gebundene PDO-Parameter; Tabellen und Spalten stammen
  ausschliesslich aus serverseitigen Whitelists.
- `geojson`, `departments`, Bildreferenzen und Signallichtdaten besitzen
  zusaetzliche Formatvalidatoren, die unten beschrieben sind.

Diese Eingabepruefung ergaenzt die weiterhin notwendige kontextgerechte
Ausgabe-Escapierung in HTML, Attributen und JavaScript.

### Strukturierte Sonderfelder

`geojson` akzeptiert ausschliesslich `FeatureCollection`, `Feature`, `Polygon`
oder `MultiPolygon`. Die Geometrie darf nur aus Polygonen bestehen. Laengen- und
Breitengrade, geschlossene Polygonringe, Verschachtelung, Featureanzahl und eine
Obergrenze von 100.000 Koordinaten werden geprueft. Unbekannte Strukturfelder
und Codebestandteile in Properties werden abgelehnt.

`departments` akzeptiert nur Fachbereichscodes aus `data/departments.json`.
Zulaessig sind eine Liste von Codes oder Eintraege der Form
`{"CARD":{"Lat":52.51,"Long":13.40}}`. Koordinaten muessen in den
gueltigen Wertebereichen liegen; unbekannte, doppelte oder falsch aufgebaute
Fachbereiche werden abgelehnt.

Bildfelder akzeptieren keine beliebigen externen URLs, weil deren Inhalt beim
Schreibvorgang nicht verlaesslich geprueft werden koennte. Als String sind nur
bereits vorhandene lokale Plugin-Bilder und zuvor von dieser API bereinigte
Bilder im Verzeichnis `lsttraining-api-images` erlaubt. Andere URLs und Pfade,
Pfadtraversierung sowie protokollrelative URLs werden abgelehnt.

Alternativ kann das Bild selbst als JSON-Objekt uebertragen werden:

```json
{
  "bild_datei": {
    "filename": "rtw-1.png",
    "mime_type": "image/png",
    "data_base64": "iVBORw0KGgoAAA..."
  }
}
```

Fuer Bilddaten sind PNG, JPEG, GIF, WebP und SVG erlaubt. Der deklarierte
MIME-Typ muss mit dem tatsaechlichen Dateiinhalt uebereinstimmen. Rasterbilder
duerfen decodiert hoechstens 10 MiB, maximal `4096 x 4096` Pixel und insgesamt
hoechstens 12 Millionen Pixel besitzen. Nicht vollstaendig decodierbare Bilder
werden abgelehnt.

Ein akzeptiertes Rasterbild wird serverseitig vollstaendig decodiert und aus
den Pixeln neu codiert. Erst diese neu erzeugte Datei wird im
WordPress-Uploadverzeichnis gespeichert. Dadurch werden Originalmetadaten,
angehaengte Bytes und typische Bild-/Code-Polyglots nicht uebernommen. Bei GIF
wird nur das vom Bilddecoder gelieferte Einzelbild gespeichert. Ist keine
sichere Neucodierung verfuegbar, wird der Upload abgelehnt.

SVG wird als XML ohne Netzwerkzugriff geparst. Dokumenttypen und Entities sind
nicht erlaubt. Skripte, eingebettetes HTML, Stylesheets, Eventhandler,
Animationen, externe Referenzen, Daten-URLs sowie unbekannte Elemente und
Attribute werden entfernt. Gespeichert wird nur eine feste Positivliste
statischer SVG-Zeichenformen und Darstellungsattribute; das Ergebnis wird vor
dem Speichern erneut geprueft. SVG ist auf 2 MiB und dieselben Abmessungsgrenzen
beschraenkt.

Absichtlich in Pixelwerten oder sichtbaren Vektorpfaden versteckte Information
kann grundsaetzlich nicht zuverlaessig erkannt werden. Die bereinigten Dateien
enthalten jedoch keine vom Server akzeptierten aktiven Bild-Codepfade.

Signallichtdaten enthalten maximal 64 Lichtpunkte. Pro Punkt sind nur `x`, `y`,
`type`, `interval`, `phase` und `size` erlaubt. Werte ausserhalb ihrer Bereiche
oder unbekannte Typen werden abgelehnt statt begrenzt oder ersetzt.

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
| `geojson` | GeoJSON/null | Polygon-/MultiPolygon-Geometrie, Objekt oder JSON-String |
| `available_hospitals` | Integer-Liste | alle IDs muessen existieren |
| `police_vehicle_image` | Bildreferenz/Bilddaten/null | vertrauenswuerdiges lokales Bild oder validierter Base64-Upload |
| `police_signal_lights_json` | Signallicht-JSON/null | strikte Wertebereiche, maximal 64 Lichtpunkte |
| `rescue_vehicle_image` | Bildreferenz/Bilddaten/null | vertrauenswuerdiges lokales Bild oder validierter Base64-Upload |
| `rescue_signal_lights_json` | Signallicht-JSON/null | strikte Wertebereiche, maximal 64 Lichtpunkte |

Beziehungsfelder: `nebenleitstellen`, `wachen`. Neue Leitstellen duerfen nur
WordPress-Administratoren anlegen.

### Nebenleitstellen

| Feld | Typ | Pflicht/Regel |
|---|---|---|
| `name` | String | Pflicht, eindeutig, maximal 255 Zeichen |
| `aufgaben` | Text/null | maximal 4.000 Zeichen, kein HTML/Code |
| `zustandigkeit` | Text/null | maximal 4.000 Zeichen, kein HTML/Code |
| `standorte` | Text/null | maximal 4.000 Zeichen, kein HTML/Code |
| `einwohner` | Integer/null | mindestens 0 |
| `flaeche_km2` | Zahl/null | mindestens 0 |
| `gps` | Koordinatenpaar/null | Format `Breitengrad, Laengengrad` |
| `nachbarleitstelle` | Boolean/null | optional |
| `geojson` | GeoJSON/null | Polygon-/MultiPolygon-Geometrie |

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
| `arrival_pos` | Koordinatenpaar/null | Format `Breitengrad, Laengengrad` |
| `departure_pos` | Koordinatenpaar/null | Format `Breitengrad, Laengengrad` |
| `bild_datei` | Bildreferenz/Bilddaten | vertrauenswuerdiges lokales Bild oder validierter Base64-Upload |
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
| `bild_datei` | Bildreferenz/Bilddaten/null | vertrauenswuerdiges lokales Bild oder validierter Base64-Upload |
| `signal_lights_json` | Signallicht-JSON/null | strikt validierte Lichtpunkte |

Diese Route aendert Fahrzeug-Stammdaten. Der Zustand eines Fahrzeugs in einer
laufenden Simulation wird ueber die Status-API geschrieben.

### Krankenhaeuser

| Feld | Typ | Pflicht/Regel |
|---|---|---|
| `poi_id` | Kennung | maximal 50 Zeichen, festes Zeichenformat und eindeutig; beim Anlegen optional |
| `name` | String | Pflicht, maximal 255 Zeichen |
| `latitude` | Zahl | Pflicht, -90 bis 90 |
| `longitude` | Zahl | Pflicht, -180 bis 180 |
| `versorgungsstufe` | Enum | `Grundversorgung`, `Schwerpunktversorger`, `Maximalversorger` |
| `trauma_level` | Integer | 0 bis 9 |
| `helipad` | Boolean | Standard `false` |
| `departments` | Fachbereichsliste | nur bekannte Codes und optionale gueltige Koordinaten; Standard `[]` |

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
