# Lokaler Wachen- und Fahrzeugagent

Der Ordner `agent` enthält einen eigenständig laufenden Python-Agenten. Er verwendet die LSTtraining-API, öffentliche Webseiten und optional ein **lokales Ollama**. Es gibt keine ChatGPT-, Codex- oder OpenAI-Aufrufe. Der Standardlauf recherchiert und schreibt ausschließlich lokale Dateien. Ein eigener Aufruf gibt genau ausgewählte Änderungen frei.

## Einrichtung und Start

Python 3.11 oder neuer installieren. Im Ordner `agent`:

```powershell
python -m venv .venv
.venv\Scripts\python.exe -m pip install -r requirements.txt
Copy-Item .env.example .env
# .env mit API-Basis, Benutzer und WordPress-App-Passwort ausfüllen.
ollama pull qwen2.5:14b
.venv\Scripts\python.exe agent.py --bundesland Brandenburg
```

Bei aktiviertem virtuellen Environment lautet der Aufruf einfach:

```sh
ollama pull qwen2.5:14b && python agent.py --bundesland Brandenburg
python agent.py --all
```

Ollama muss lokal laufen (`ollama serve`, wenn die Desktop-App nicht läuft). Standard: `http://127.0.0.1:11434`; ein vorhandener Dienst auf Port 11435 lässt sich über `OLLAMA_URL` verwenden. Das Modell bekommt `keep_alive: "10m"` und maximal 2.000 Zeichen bereinigten Quelltext je Anfrage. Ein Modellausfall erzeugt einen ungeklärten Befund; es gibt keinen Cloud-Fallback.

Auf diesem Entwicklungsrechner ist bereits `.venv\Scripts\python.exe` für lokale Tests vorhanden. Sie ist nicht Bestandteil der portablen Auslieferung. Nach Kopieren des Ordners eine eigene virtuelle Umgebung anlegen.

## API und Serverbereitstellung

Der tatsächliche WordPress-Endpunkt lautet `/wp-json/lst/v1/verwaltung/…`. Der Adapter `lst_api.py` lädt Wachen, Fahrzeuge, Leitstellen und Nebenleitstellen paginiert. `center_vehicles(id)` bildet den im Auftrag beschriebenen Leitstellen-Fahrzeugabruf über diese vorhandenen Routen ab, einschließlich Nebenleitstellen. Ein inkompatibler Fremdserver wird nicht stillschweigend angenommen.

Für Schreibaktionen muss die zugehörige Plugin-Version mit `includes/restapi/rest-abgleich.php` und Adressmigration **2026100701** auf dem Server vorhanden sein. Der normale Migrationsmechanismus ergänzt `wachen.adresse` idempotent; die Verwaltungs-API akzeptiert null oder höchstens 500 Zeichen. Danach bestätigt `GET /abgleich/status` die globale Bestandsansicht und den abgesicherten Schreibweg. Dieser benötigt einen WordPress-Administrator mit App-Passwort; eingeschränkte Bestandsansichten erlauben keine global abgesicherte Dublettenprüfung. Ohne diese Bestätigung sind Writes gesperrt und Reports kennzeichnen den unbekannten globalen Umfang.

Die Entwicklung startet keine produktive Migration und überträgt keine Freigaben. Die neue Route serialisiert Agent-Schreibvorgänge mit einem datenbankbezogenen MySQL-Advisory-Lock und führt Prüfung und Änderung innerhalb einer Transaktion aus. Sie prüft globale normalisierte Rufnamen nochmals unter der Sperre. Andere, ältere AJAX-/Import-Schreibwege nehmen nicht an diesem Lock teil; deren paralleler Betrieb muss bei einer Freigabesitzung ausgeschlossen werden.

## Quellen und tatsächliche Abdeckung

Die fünf übergebenen JSON-Dateien liegen unverändert in `catalogs`. Beim Einlesen werden Dateiname und Inhalts-Hash dokumentiert, doppelte Hinweise zusammengeführt und Fahrzeugbehauptungen ausdrücklich **nicht** als bestätigte Fahrzeuge importiert. Trotz des Namens enthält `DACH-Alle-Bundeslaender-Fahrzeuge-Quellen.json` aktuell nur deutsche Hinweise. Zusammen liefern die Dateien 64 unterschiedliche Wachenhinweise aus Brandenburg und Niedersachsen, keinen fertigen DACH-Bestand.

Der Agent startet zusätzlich für jede API-Wache eine regionale Suche über Bing-RSS und DuckDuckGo-Lite. Die Dateien sind Startpunkte, keine Voraussetzung für die Bearbeitung vorhandener Wachen anderer Gebiete. Suche, Quellenseiten und Reverse-Geocoding werden für 24 Stunden gecacht. Quellen vor Freigaben werden frisch geladen. Abruf-, Such- und Modellfehler erscheinen im jeweiligen Befund.

Quellenfolge seit 8. Oktober 2026: zuerst die direkte öffentliche BOS-Wachensuche, danach nur bei fehlenden verwertbaren aktiven BOS-Belegen die verifizierten Betreiber/Behörden, regional passenden Zusatzkataloge und zuletzt LstSim. Das deutsche Zusatzregister wurde nach `source-registry.json` übernommen. Gebietsspezifische Startseiten aus dem vorhandenen Projektregister liegen zusätzlich in `area-source-registry.json` und werden nur bei passender geografischer Leitstellenzuordnung verwendet. Fehlendes Land, Bundesland/Kanton, Gebiet oder Wachentyp gilt niemals als Wildcard. Betreiberidentität erfordert eine passende Wache/Adresse und erkennbare Kontakt-/Impressumsangaben. Ein Domainname oder eine vom Katalog behauptete Bewertung allein reicht nicht.

BOS-Fahrzeugdetails aus Suchtreffern werden nicht direkt abgerufen. Erst eine passende Wach-/Übersichtsseite mit Region im Breadcrumb, gleicher Organisation und passender Wachidentität erlaubt Detailabrufe; jeder Detailbeleg wird erneut zugeordnet. DRK wird nicht aus dem allgemeinen Wachentyp RD geraten. Bei unklarer Organisation bleibt BOS gesperrt. Die ersten fünf passenden BOS-Fahrzeugbelege liefern Prefix-Hinweise, die nur im selben Gebiet wiederverwendet werden. Brandenburg verwendet die ausdrücklich vorgegebenen Kreis-Prefixe; LDS/TF/MOL/OSL sind Kreiskennzeichen, kein ungeprüfter Zusatz jedes Rufnamens.

Nichts gefunden bedeutet ungeklärt, auch bei einer erfolgreich geladenen Quelle. `Nur LstSim` bleibt ohne unabhängigen Beleg nicht schreibfähig. HTML-Tabellen und BOS werden regelbasiert gelesen. PDF-Quellen werden derzeit als nicht auswertbar dokumentiert. Playwright ist als gewünschte optionale Laufzeitabhängigkeit aufgeführt; Browserinstallation ist für den HTTP-Lauf nicht erforderlich.

## Pakete und DACH

```powershell
python agent.py --bundesland Niedersachsen --package packages/Stade.json
python agent.py --bundesland Brandenburg --package packages/Potsdam.json --output data/Potsdam --no-llm
python agent.py --bundesland Brandenburg --max-stations 3 --no-llm
python agent.py --land AT --bundesland Tirol
python agent.py --land CH --bundesland Zürich
python agent.py --land AT --all
python agent.py --land CH --all
```

Stade verwendet eine amtliche BKG-Geometrie aus dem bestehenden Projektkatalog; Grenzpunkte und fehlende Koordinaten bleiben ungeklärt. Potsdam verwendet die amtliche BKG-Geometrie der kreisfreien Stadt (AGS 12054), ohne Potsdam-Mittelmark. Der obige Test schreibt nach `data/Potsdam` und benötigt mit `--no-llm` kein Ollama; ohne diese Option wird zusätzlich der lokale Freitext-Fallback verwendet. Neue Pakete enthalten `country`, `state`, `region`, `geometry` (GeoJSON Polygon/MultiPolygon/FeatureCollection) und `geometry_source`. Alternativ kann `geometry_url` eine öffentliche amtliche GeoJSON-Quelle laden. Geometrien dürfen nicht bloß als grobe Rechtecke angenähert werden.

Ohne Paket werden alle API-Wachen des Bundeslands einschließlich besetzter Wachen bearbeitet; Region und Adresse werden mit Reverse-Geocoding ermittelt. Neue Katalogwachen ohne Koordinaten können über ihre Adresse eindeutig vorwärts geokodiert werden; mehrere Treffer sperren die Anlage. Regionale Konflikte sperren die Zuordnung. AT/CH `--all` verwendet die Kantone/Bundesländer aus Katalogen und dem API-Bestand. DE `--all` liest die angegebene Google-Tabelle als öffentliche CSV; bei fehlender Freigabe wird der Fehler sichtbar gemeldet und die dokumentierte Liste aller 16 Bundesländer genutzt, beginnend mit Brandenburg.

## Ganze Leitstelle einschließlich Nebenstellen

Die Auswahl erfolgt über die aktuellen API-Zuordnungen. Für IRLS Nordwest (ID 1) aus dem Ordner `agent` zunächst den Umfang anzeigen:

```powershell
.\.venv\Scripts\python.exe agent.py --leitstelle 1 --scope-only --output data/Nordwest
```

Dieser Aufruf lädt nur Wachen, Leitstellen und Nebenstellen samt Zuordnungen. Er recherchiert keine Quellen und speichert die Auswahl in `data/Nordwest/leitstellen-vorschau.json`.

Den vollständigen Recherchelauf separat starten:

```powershell
.\.venv\Scripts\python.exe agent.py --leitstelle 1 --output data/Nordwest --no-llm
```

Er bearbeitet direkt zugeordnete Wachen und alle Wachen der zugeordneten Nebenstellen, auch über Bundeslandgrenzen hinweg. Mehrfach zugeordnete Wachen werden einmal bearbeitet, einschließlich Wachen mit vorhandenen Fahrzeugen. Der globale Fahrzeugbestand bleibt für Rufnamenkonflikte verfügbar. `--leitstelle` ersetzt hier `--bundesland` und ist nicht mit `--package` kombinierbar. Ein eindeutiger Leitstellenname ist ebenfalls zulässig. Ohne `--no-llm` wird zusätzlich der lokale Ollama-Fallback verwendet.

Vorhandene Kataloghinweise werden auf die Auswahl eingeschränkt: bekannte API-Wachen müssen zugeordnet sein; zusätzliche Hinweise benötigen einen Standort innerhalb einer gewählten API-Gebietsgeometrie. Diese Geometrie ist keine amtliche Vollständigkeitsbestätigung. Fehlende oder widersprüchliche Angaben bleiben ungeklärt. Zugeordnete Wachen ohne bekanntes Land/Bundesland stehen ausdrücklich in `unclassified_stations` und werden nicht stillschweigend einem Gebiet zugeteilt.

`leitstellen-umfang.json` dokumentiert Zuordnungen, ungeklärte Einträge, bearbeitete Gruppen und noch ausstehende Wachen. `leitstellen-auswertung.md` enthält die gemeinsame Abschlussauswertung; Rechercheberichte und `DELTA_*.json` entstehen je Bundesland/Kanton im selben Ausgabeordner. `--max-stations 3` begrenzt einen Testlauf insgesamt auf drei Einträge, nicht auf drei pro Bundesland. Der Lauf schreibt ausschließlich lokale Berichte; Änderungen benötigen weiterhin einen getrennten Aufruf mit ausgewählten Freigabe-IDs.

Nach Prüfung eines Vorschlags aus beispielsweise `data/Nordwest/DELTA_Brandenburg.json` führt dieser Aufruf die gewählte Änderung aus und schreibt das Ergebnis nach `data/Nordwest/writes.log`:

```powershell
.\.venv\Scripts\python.exe agent.py --freigeben data/Nordwest/DELTA_Brandenburg.json --id <vollständige-freigabe_id> --output data/Nordwest
```

Jede `DELTA_*.json` ist eine eigene Berichtsdatei; für ausgewählte IDs aus einem anderen Bundesland ist dessen Datei anzugeben. Mehrere IDs aus derselben Datei können mit wiederholtem `--id` seriell freigegeben werden. Vor dem Schreiben muss `GET /wp-json/lst/v1/abgleich/status` auf dem Server funktionieren; ein 404 bedeutet, dass die Serverroute noch nicht bereitgestellt ist. Der Agent bricht dann ohne Schreibaktion ab.

Nach einem Lauf erstellt `python review_reports.py data/Nordwest` eine lesbare `lauf-pruefung.md` sowie `freigabe-uebersicht.csv` mit allen Vorschlägen, Quellen und Freigabe-IDs. Diese Übersicht gibt selbst nichts frei und ruft die API nicht auf. Ein gezielter Nachlauf für bereits zugeordnete Wachen verwendet wieder einen vollständigen aktuellen API-Bestand, schreibt seine Berichte aber in einen eigenen Ordner:

```powershell
.\.venv\Scripts\python.exe agent.py --leitstelle 1 --wache-id 30843 --wache-id 4915 --output data/Nordwest-Nachlauf --no-llm
```

Wenn `/verwaltung/wachen` mit HTTP 500 antwortet und ein Wachendetail kein Feld `adresse` enthält, fehlt wahrscheinlich die Adressmigration auf dem Server. `includes/migrations.php` muss dort aktuell sein; ein WordPress-Administrator löst die idempotente Migration durch Aufruf des Adminbereichs aus. Danach den API-Abruf erneut prüfen. Der lokale Schreibadapter prüft zusätzlich einen Wachen-Listenabruf und das Adressfeld, bevor er eine ausgewählte Änderung sendet.

## Ergebnisse und Freigaben

Unter `agent/data` entstehen:

- `{Bundesland}.json`: Hierarchie Land → Bundesland/Kanton → Kreis/Bezirk → Ort → Wachenliste mit Belegen und Quellenbewertung. AT/CH-Dateien tragen zusätzlich das Länderkürzel.
- `DELTA_{Bundesland}.json`: Einzelvorschläge mit `freigabe_id`, Vorzustand, Zielwerten, Beleg und URL.
- `{Bundesland}-status.json`: bearbeitete, ungeklärte und noch ausstehende Wachen, Herkunft, globaler Sichtstatus, Zahl der API-Schreibaktionen und Abschlusszahlen unter `summary`.
- `{Bundesland}-auswertung.md`: dieselbe Abschlusstabelle wie im Terminal, einschließlich Belegen, Änderungsvorschlägen und Konflikten.
- `{Bundesland}-fehlende-fahrzeuge.csv`: ausschließlich ADD-Vorschläge mit Wachen-ID, Rufname, Quelle.
- `{Bundesland}-rufnamen.json`: feste/gelernte Prefixe und ungeklärte Mappings.
- `way.log`: append-only JSON-Zeilen für technische Namen, Reverse-Adresse, Quellen-URL und Auflösungsstatus.
- `nebenstellen-abgleich.json` und `nebenstellen-recherche.json`: Ist-Zuordnungen, geprüftes Soll oder ausdrücklich ungeklärte Sollstände und recherchierte Quellen.

Zwischenstände werden nach jeder Wache atomar gespeichert. `--max-stations` ist ein Testlimit und lässt ausstehende Wachen sichtbar stehen. Ein neuer Lauf recherchiert erneut mit Cache; er setzt nicht blind einen alten API-Snapshot fort. `--offline` gilt für öffentliche Quellen, **nicht** für den immer frisch zu ladenden API-Bestand. Eine Quelle mit Fahrzeugen bestätigt nur eine belegte Teilmenge, niemals automatisch einen vollständigen Fuhrpark.

Freigabe aus dem Ordner `agent`:

```powershell
python agent.py --freigeben data/DELTA_Brandenburg.json --id <vollständige-freigabe_id>
```

Mehrere `--id` werden seriell bearbeitet. IDs sind SHA-256-Hashes des gesamten Eintrags; geänderte Inhalte machen die Auswahl ungültig. Vor jedem Schreiben werden Quelle, Bestandsansicht, Zielwache und Rufnamen geprüft. Bei Timeout gibt es keinen automatischen zweiten POST; das Protokoll zeigt das neu gelesene Ergebnis und verlangt eine Prüfung.

Nach der zusätzlichen Nutzerfreigabe sind folgende Aktionen vorgesehen:

| Aktion | Wirkung |
|---|---|
| ADD | Ein belegtes aktives Fahrzeug ergänzen; vorhandener Rufname wird übersprungen. |
| UPDATE | Nur den belegten Typ eines vorhandenen Fahrzeugs ändern; keine Rufnamenänderung/Verlegung. |
| SET_AD | Bestehendes Fahrzeug auf `nicht einsatzbereit`, FMS 6 setzen und Außer-Dienst-Quellenhinweis speichern. |
| ADD_WACHE | Eindeutig belegte neue Wache mit Adresse, Koordinaten und Leitstellenzuordnung; erneute Standort-/Namensprüfung. |
| UPDATE_WACHE | Bestehenden technischen Namen und Adresse auflösen; Vorzustand muss unverändert sein. |

Historische Fahrzeuge gelangen nie in aktive Kandidaten oder Fehlbestandslisten. Der separat freigegebene SET_AD-Weg verwendet ausschließlich einen passenden Außer-Dienst-Beleg für ein vorhandenes API-Fahrzeug. Aktive und historische widersprüchliche Belege lösen keine automatische Statusänderung aus. Typen ohne eindeutiges Mapping bleiben nicht schreibfähig. Die API-Typen FW/FFW/RD und die Ausgabetypen BF/FF/WF/FTZ/RD sind getrennt; der Writer übersetzt die belegte Einordnung für das bestehende Plugin-Schema.

## Nebenstellen-Soll

Die API kennt kein Aktiv-Kennzeichen. `centers.py` sucht amtliche Belege regional und kann explizit als vollständig bezeichnete Tabellen mit Nebenleitstelle und Aktivstatus regelbasiert übernehmen. Nicht vorhandene Nebenstellen erscheinen dabei mit Name und leerer API-ID als fehlend. Bloße Erwähnungen mehrerer Leitstellen auf einer Seite beweisen keine vollständige organisatorische Zuordnung. Solche Fälle bleiben `Soll ungeklärt` mit `diff: null`, statt eine erfundene leere Soll-Liste zu verwenden. Bereits belastbar geprüfte Zuordnungen können in `centers-soll.json` stehen: `leitstelle_id`, `active: true`, `verified: true`, `source_url`, `nebenstellen: [{id, name}]`. Der mitgelieferte Katalog enthält bewusst keine erfundenen API-IDs oder Vollständigkeitsbehauptungen.

## Tests

```powershell
python -m unittest discover -s tests -v
# Vom Plugin-Hauptverzeichnis:
php tests/rest-abgleich.test.php
npm test
```

Die Python-Tests verwenden simulierte API-/Webantworten und schreiben nicht in die produktive API. Der PHP-Test prüft die echten Endpoint-Kontrollflüsse mit einem PDO-Testdouble, einschließlich Sperrkonflikten, Dubletten, veralteten Änderungen, neuen Wachen und wiederholter Adressmigration. Ein echter paralleler MySQL-/WordPress-Integrationstest auf einem Staging-System ist vor produktiven Freigaben zusätzlich erforderlich.


## Ergänzte Behördenquellen vom 7. Oktober 2026

Die zusätzliche Nutzerliste ist unverändert in `source-inputs/2026-10-07-behoerdenquellen.json` gespeichert. Ihre 30 Einträge für alle 16 deutschen Bundesländer sind in `source-registry.json` als kommunale `operator_discovery`-Einstiege hinterlegt. Land, Bundesland, Stadt und Wachentyp müssen passen; die Quellen gelten nicht pauschal für das gesamte Bundesland. Die mitgelieferten Bewertungen und Bestandszahlen sind ungeprüfte Herkunftsangaben.

Vier durch `[STRIPPED … bytes]` beschädigte URLs wurden durch recherchierte offizielle Einstiege für München, Hannover, Saarbrücken und Rostock ersetzt. Original-URL, Herkunfts-Hash und Prüfdatum bleiben gespeichert. Leipzig wurde zunächst wegen der beschädigten URL deaktiviert; die später vollständig übergebene URL ist unten dokumentiert. Dresden wurde mit der nachgereichten offiziellen Seite der Wache 1 Albertstadt ergänzt. Bei den vier Ersatzquellen ist lediglich der offizielle Herausgeber bestätigt, nicht der Fahrzeugbestand (`publisher_verified` ist getrennt von `verified`).

Übersichtsseiten dürfen zu passenden Detailseiten derselben Domain führen. Sie liefern ohne belegte Wachenidentität selbst keine Fahrzeugkandidaten. Beschädigte URLs werden nicht abgerufen. Weitere AT-/CH-Quellen enthält diese Ergänzung nicht.

Die zweite übergebene Fassung liegt in `source-inputs/2026-10-07-behoerdenquellen-2.json`. Sie wurde mit denselben 30 Quellen zusammengeführt. `submissions` erhält beide Herkunftsangaben und Hinweise; vollständige bzw. reparierte URLs werden durch gekürzte Folgeeinträge nicht verschlechtert.

Weitere URL-Prüfung: `source-inputs/2026-10-07-url-ergaenzungen.json` dokumentiert die drei nachgereichten Links. Hannover bestätigt die bestehende Übersicht; Dresden ergänzt eine konkrete Wachenseite. Der vorgeschlagene Rostocker Fahrzeuglink liefert HTTP 200 mit `invalid template`; deshalb bleibt dort der zuvor gefundene funktionierende Einstieg erhalten. Zu diesem Zwischenstand waren 29 der 30 Quellen als Rechercheeinstiege aktiviert.

Die sieben vollständig aus HTML übergebenen Links und ihre Prüfungen stehen in `source-inputs/2026-10-07-html-url-korrekturen.json`. Saarbrücken nutzt jetzt die bestätigte URL ohne `/le/`; Hannover und Dresden wurden erneut bestätigt. München leitet zur Branddirektion weiter, der spezifischere Feuerwachen-Einstieg bleibt erhalten. Rostock liefert weiterhin `invalid template`. Magdeburgs vorgeschlagener Pfad zeigt eine allgemeine Verwaltungsseite; stattdessen wurde eine erreichbare amtliche Berufsfeuerwehr-Seite recherchiert und hinterlegt.

Alle 30 kommunalen Einträge haben nun vollständige URLs und sind für Rechercheversuche aktiviert. Das bedeutet weder bestätigte Erreichbarkeit aller Quellen noch bestätigte Fahrzeugbestände. Leipzig bleibt nach Verbindungsabbruch ausdrücklich `complete_url_fetch_failed`, ohne bestätigten Herausgeber oder Inhalt; spätere Reportläufe dürfen den vollständigen Link erneut versuchen und müssen weitere Abruffehler als unvollständige Recherche behandeln. Originale und neue Prüfergebnisse bleiben nachvollziehbar erhalten. Die Gebiets- und Wachentypfilter gelten unverändert, auch für spätere AT-/CH-Quellen.

## Blockierte Suchanbieter

Verlangt DuckDuckGo eine Browserprüfung oder antwortet ein Suchanbieter mit HTTP 403/429, wird dieser Anbieter für den restlichen Prozesslauf übersprungen. Bing und passende bekannte Quellen werden weiterhin verwendet. Browserprüfungen werden nicht automatisch gelöst. Neue Browserprüfungsseiten werden nicht gecacht; alte gecachte Prüfseiten werden beim nächsten Online-Lauf frisch angefragt. Ein Neustart prüft die Verfügbarkeit erneut, garantiert aber keine Aufhebung der Blockade.

Der Report enthält pro Wache zusammengefasste Suchfehler mit `provider`, `code`, lesbarem `befund`, `occurrences` und `skipped_queries`. Übersprungene Suchen bleiben sichtbar; fehlende Treffer bestätigen keine Vollständigkeit. Bereits laufende Prozesse und vorhandene Reports ändern sich durch diese Codeänderung nicht.

## BOS zuerst (8. Oktober 2026)

Jede recherchierbare Wache wird zuerst über die direkte BOS-Wachensuche geprüft. Die Ortssuche wird zwischen Wachen gecacht; ein begrenzter Teil der Ergebnisseiten wird bei Bedarf nachgeladen. Vor Fahrzeugdetails müssen Land, Bundesland/Kanton, Kreis/Gebiet, Organisation sowie Wachenname und Adresse passen. Fehlt im Reverse-Geocoding die Hausnummer, sind zusätzlich zur gleichen Straße, PLZ und Stadt ein exakt normalisierter Wachenname erforderlich; widersprüchliche vorhandene Hausnummern bleiben gesperrt. Unbekannte oder widersprüchliche Angaben bleiben gesperrt. BOS-Details müssen dieselbe verlinkte Wachen-ID bestätigen. Historische Fahrzeugkarten werden vor dem Detailabruf verworfen; Sidebar-Links zu Oldtimern zählen nicht zum Fahrzeugbeleg. Die Auswertung erfolgt ohne LLM.

Bei mindestens einem belegten aktiven Fahrzeug mit bekanntem Typ erfolgt der vorhandene API-Bestandsvergleich; die weitere allgemeine Websuche entfällt für diese Wache. Unbekannte Typen bleiben nicht schreibfähig. Falls BOS keine verwertbaren aktiven Belege liefert oder der Abruf scheitert, werden die übrigen Quellen weiter recherchiert. Such- und Abruflimits sowie Zuordnungslücken erscheinen im Report; BOS-Treffer belegen weiterhin nur eine Teilmenge und keinen vollständigen Fuhrpark. Vorhandene Rufnamen an derselben Wache werden nicht erneut angelegt, Rufnamen an anderen Wachen werden als Konflikt ausgewiesen. Es gibt weiterhin keinen automatischen API-Schreibzugriff.

## Paketfortschritt und Abschlussauswertung

Der geometrische Paketfilter läuft vor der Recherche-Schleife. Einträge außerhalb, auf der Grenze oder ohne eindeutige Koordinaten erscheinen im Statusreport mit `stage: geography`, aber nicht als recherchierte Wachen im Fortschrittszähler oder in der Fahrzeughierarchie. Geokodierbare Kataloghinweise werden vor der Zuordnung anhand ihrer Adresse geprüft. Fehlende Koordinaten sind kein Beleg für einen Ausschluss aus dem Gebiet.

Die Konsole zeigt zuerst die Paketauswahl und anschließend zum Beispiel `[Potsdam] 1/21 …`. `--max-stations` begrenzt nur die ausgewählten Recherche-Einträge; ausgeschlossene Einträge verbrauchen das Limit nicht. Am Ende erscheinen die sechs Ergebniszeilen (API-Wachen, Kataloghinweise, außerhalb, geografisch ungeklärt, Fahrzeugbelege/Änderungsvorschläge, API-Schreibaktionen). Noch ausstehende Recherchen und Rufnamenkonflikte werden zusätzlich genannt. Ein schneller Lauf kann viele ausgeschlossene Wachen und gecachte Quellen enthalten; das bedeutet nicht, dass keine Recherche stattfand. Die gemeldete Recherchedauer schließt das vorherige globale API-Laden nicht ein.

## Server-Dateien nach REST-Umzug

Die REST-Implementierung liegt vollständig in `includes/restapi/`: `rest-api.php`, `rest-validation.php`, `rest-management-api.php` und `rest-abgleich.php`. Gemeinsam mit anderen Plugin-Bereichen genutzte Dateien (`db.php`, `regions.php`, `migrations.php` und Simulationslogik) verbleiben in `includes/`. Die öffentlichen REST-URLs ändern sich nicht.

Für die Umstellung auf dem Server werden die vier REST-Dateien, die aktualisierte `lsttraining-plugin.php`, `includes/migrations.php`, `includes/regions.php` sowie die beiden Datenkataloge `data/fahrzeugtypen.json` und `data/bundeslaender.json` benötigt. Bereits identische Dateien müssen nicht erneut übertragen werden. Agent, Zugangsdaten und Recherchedateien bleiben lokal. Beim Upload vorhandene Ordner zusammenführen. Zuerst neue Dateien und Abhängigkeiten übertragen, danach die Plugin-Hauptdatei. Nach erfolgreicher Umstellung können die alten Dateien `includes/rest-api.php`, `includes/rest-validation.php`, `includes/rest-management-api.php` und `includes/rest-abgleich.php` auf dem Server entfernt werden. Anschließend einmal das WordPress-Backend als Administrator öffnen, um ausstehende Migrationen auszuführen.
