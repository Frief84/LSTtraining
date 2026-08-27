# Sicherheits-, Datenbank- und Multiplayer-Härtung

Stand: 26. August 2026
Schema-Version: `2026082601`

Diese Dokumentation beschreibt die verbindlichen Sicherheits- und Betriebsregeln des Plugins. Sie ergänzt die allgemeine Projektbeschreibung in der `README.md`.

## 1. Berechtigungsmodell

Administratoren können unter **LST Training → Benutzer** für jeden WordPress-Benutzer zwei Ebenen getrennt einstellen:

1. die erlaubten Bereiche **Leitstellen**, **Nebenstellen**, **Krankenhäuser**, **Wachen** und **Fahrzeuge**;
2. die Leitstellen, in deren Bereich diese Rechte gelten.

Die ausgewählten Leitstellen werden in `user_permissions.leitstellen_ids` gespeichert. Beim Speichern werden nur tatsächlich vorhandene Leitstellen-IDs akzeptiert. WordPress-Administratoren mit `manage_options` dürfen alle Bereiche verwalten.

### Serverseitige Objektprüfung

Die sichtbare Oberfläche ist kein Berechtigungsnachweis. Jeder relevante Endpunkt ermittelt den Objektbereich aus der Datenbank:

- **Fahrzeug:** Fahrzeug → Wache → direkte Leitstellenzuordnung und Zuordnung über Nebenleitstellen
- **Wache:** direkte Leitstellenzuordnung und Zuordnung über Nebenleitstellen
- **Nebenleitstelle:** Nebenleitstelle → verknüpfte Leitstellen
- **Leitstelle:** die ID des bestehenden Datensatzes

Nicht zugeordnete Objekte sind für Nicht-Administratoren gesperrt. Gehört ein Objekt zu mehreren Leitstellen, muss der Benutzer für alle betroffenen Leitstellen freigeschaltet sein.

### Verschieben zwischen Leitstellenbereichen

Eine Wache oder ein Fahrzeug darf in einen anderen Leitstellenbereich verschoben werden, wenn der Benutzer:

- den passenden Bereich bearbeiten darf;
- auf den bisherigen Objektbereich zugreifen darf; und
- für den vollständigen Zielbereich freigeschaltet ist.

Die Zielberechtigung wird aus den in der Datenbank vorhandenen Wachen-, Leitstellen- und Nebenleitstellen-Zuordnungen ermittelt. Eine aus dem Request übermittelte Leitstellen-ID gilt nie allein als Berechtigungsnachweis.

Auch Listen und Bearbeitungsansichten werden nach den erlaubten Leitstellen gefiltert.

## 2. CSRF-, REST-, Methoden- und Löschschutz

Die klassischen Admin-/AJAX-Endpunkte kombinieren:

1. einen angemeldeten WordPress-Benutzer;
2. eine gültige, aktionsgebundene Nonce;
3. die passende Bereichs- und Objektberechtigung einschließlich Leitstellen-Scope; und
4. die jeweils festgelegte HTTP-Methode, bei klassischen Schreibwegen in der
   Regel `POST`.

Eine falsche HTTP-Methode wird mit Status `405` abgewiesen. Löschaktionen verwenden kein `GET` und keinen Link der Form `?delete_id=…` mehr.

| Bereich | Nonce-Aktion | Request-Feld |
|---|---|---|
| Fahrzeuge | `lst_fahrzeuge_nonce` | `nonce` |
| Wachen | `lsttraining_wachen` | `nonce` |
| Krankenhäuser | `lsttraining_hospitals` | `nonce` |
| Leitstellen-/Krankenhaus-Zuordnung | `lsttraining_leitstellen` | `nonce` |
| Nebenleitstellen | `lst_nebenstellen_nonce` | `_ajax_nonce` |
| Wachen-Zuordnung | `lst_zuordnung` | `nonce` |
| Benutzerrechte | `lsttraining_save_permissions` | `lsttraining_nonce` |
| Schema-Aktualisierung | `lsttraining_install_schema` | `lsttraining_schema_nonce` |

Die klassische Leitstellen-Löschung verwendet zusätzlich eine datensatzbezogene Nonce. Nonces schützen vor fremdausgelösten Requests, ersetzen aber niemals die Objektberechtigung.

### REST-API

Die REST-Routen liegen unter `/wp-json/lst/v1`. Browser senden die angemeldete
WordPress-Sitzung und `X-WP-Nonce`; externe Clients verwenden WordPress
Application Passwords ausschliesslich ueber HTTPS. REST-Nonces ersetzen weder
Bereichs- noch Objektberechtigungen.

Die Verwaltungs-API verwendet `GET`, `POST`, `PATCH` und `DELETE`. Sie schreibt
nur Felder aus einer serverseitigen Whitelist, bindet alle Nutzwerte als
SQL-Parameter und fuehrt Aenderungen ueber mehrere Tabellen in einer
Transaktion aus. `DELETE` verlangt zusaetzlich `confirm=true`.

Live-Schreibzugriffe auf eine Spielinstanz duerfen nur deren Einsatzleiter und
WordPress-Administratoren ausfuehren. Fahrzeugaenderungen werden im
Baseline-/Delta-Modell der konkreten Instanz gespeichert. Stammdaten und andere
Spielinstanzen bleiben unveraendert.

Die vollstaendige Routen-, Feld-, Beziehungs- und Fehlerreferenz steht in:

- `docs/rest-management-api.md`
- `docs/rest-status-api.md`

Dieselbe Referenz ist fuer Administratoren unter **LST Training → Hilfe &
Dokumentation** direkt in WordPress sichtbar.

## 3. Versionierte Datenbankmigrationen

Die aktuelle Schema-Version ist in `LSTTRAINING_SCHEMA_VERSION` definiert. Der installierte Stand wird in der WordPress-Option `lsttraining_schema_versions` pro Datenbank gespeichert. Der Schlüssel ist ein Hash aus Modus, Host und Datenbankname; Passwörter werden nicht in den Fingerprint aufgenommen.

Migrationen laufen ausschließlich:

- bei Plugin-Aktivierung;
- beim ersten berechtigten Admin-Aufruf nach einem Upgrade; oder
- manuell unter **LST Training → Einstellungen**.

Normale Seiten-, Snapshot- und AJAX-Aufrufe führen keine spontanen `CREATE TABLE`- oder `ALTER TABLE`-Anweisungen mehr aus. Fehlt dort ein erforderliches Schema, soll der Aufruf kontrolliert fehlschlagen und durch eine Migration behoben werden.

### Idempotenz und Fehlerfall

- Basistabellen verwenden `CREATE TABLE IF NOT EXISTS`.
- Spalten und Indizes werden vor dem Anlegen über `INFORMATION_SCHEMA` geprüft.
- Die neue Versionsnummer wird erst nach einem vollständig erfolgreichen Lauf gespeichert.
- Ein fehlgeschlagener Lauf kann deshalb erneut gestartet werden.
- Da MySQL-DDL implizite Commits ausführt, muss vor produktiven Upgrades ein Datenbank-Backup erstellt werden. Bei nicht automatisch behebbaren Fehlern wird dieses Backup zurückgespielt.

### Reparaturen der Version `2026082601`

- doppelte beziehungsweise kollidierende Constraint-Namen beseitigt;
- alle Basistabellen wiederholt ausführbar gemacht;
- Signallicht-Felder für Leitstellen und Fahrzeuge ergänzt;
- `last_editor` für Krankenhäuser ergänzt;
- Patientenprofile und Anruferbausteine ergänzt;
- Instanz-, Teilnehmer- und Aufbewahrungsfelder ergänzt;
- Retention-Index ergänzt;
- exakte Dubletten im Anrufer-Namenspool vor dem Unique-Index bereinigt;
- Seeds auf `INSERT IGNORE` umgestellt;
- bestehende Einzelspieler- und Einsatzleiter-Instanzen, soweit eindeutig, einem Eigentümer zugeordnet.

## 4. Zentrale Multiplayer-Ticks

Browser dürfen den Tick weiterhin regelmäßig anstoßen, aber pro Spielinstanz kann nur ein Request die Simulation fortschreiben. Dafür wird ein MySQL-Advisory-Lock mit einem instanzbezogenen Namen verwendet.

Der Lock umfasst:

- zeitabhängige Fahrzeug-, Patienten- und Transportfortschritte;
- die Prüfung des letzten automatischen Spawns; und
- das Erzeugen eines neuen Einsatzes.

Ein paralleler Request erhält den Lock nicht und erzeugt deshalb weder doppelte Fortschritte noch einen doppelten Einsatz. Auch ein erzwungener Spawn nutzt dieselbe Serialisierung. Pausieren, Geschwindigkeit ändern und Spawn erzwingen bleiben Administratoren beziehungsweise Einsatzleitern vorbehalten.

## 5. Bootstrap und lesender Snapshot

Der Bootstrap enthält die stabilen Basisdaten der Instanz. Der normale Snapshot ist rein lesend: Nur der autorisierte Tick ruft die Snapshot-Ermittlung ausdrücklich mit aktivierter Zustandsfortschreibung auf.

### Übertragene Fahrzeugpositionen

Das Positionsfeld des Snapshots enthält nur Fahrzeuge, deren aktuelle Position relevant von ihrem Ausgangszustand abweicht:

- mehr als 5 Meter von der instanzbezogenen Baseline entfernt; oder
- mehr als 50 Meter vom Standort ihrer Wache entfernt.

Dabei werden nur die aktuell benötigten Positionsdaten übertragen. Zielkoordinaten, Basis-/Wachenkoordinaten, interne Delta-ID, Bildinformationen und eine frühere Bewegungshistorie sind nicht Bestandteil dieses Positions-Snapshots. Kehrt ein Fahrzeug zur Basis beziehungsweise Wache zurück, verschwindet es wieder aus der Positionsliste.

Statusänderungen können unabhängig davon im Feld `vehicle_statuses` vorkommen, enthalten dort aber keine Position. Dynamisch erzeugte Polizei- und Nachbarleitstellen-Unterstützung wird mit ihrer aktuellen Position übertragen, da sie keine normale stationäre Bootstrap-Baseline besitzt.

## 6. Signallicht-Grafiken

Die zuvor fehlenden Referenzen unter `img/signal/` wurden als SVG-Dateien vorbereitet:

- `beacon.svg`
- `strobe.svg`
- `lightbar.svg`
- `glow.svg`
- `editor-point.svg`

Die PHP- und JavaScript-Verweise verwenden diese SVGs. `.gitignore` lässt `img/signal/*.svg` ausdrücklich zu, damit die Dateien in einem separaten Commit aufgenommen werden können. Der fehlende Fallback `default_pol.png` wurde durch das vorhandene allgemeine Fahrzeugbild `img/fahrzeug/default.png` ersetzt.

## 7. Automatisierte Prüfungen

Die statischen Prüfungen können ohne zusätzliche npm-Pakete gestartet werden:

```bash
node tests/static-checks.mjs
```

Sie prüfen derzeit:

1. JavaScript-Syntax;
2. JSON-Dateien;
3. PHP-Klammerstruktur und Bootstrap-Abhängigkeiten;
4. lokale Admin-Assets;
5. ein idempotentes Basisschema;
6. fehlendes Laufzeit-DDL außerhalb der Migration;
7. das Verbot von GET-Löschaktionen;
8. Fahrzeug-Endpunkte und Objekt-Scope;
9. Nonces und POST-Methoden der Kern-Schreibwege;
10. die versionierte Migration;
11. Tick-Serialisierung und lesenden Snapshot;
12. Benutzerrechte pro Bereich und Leitstelle.

Diese Prüfungen ersetzen keinen echten Integrationslauf mit WordPress, PHP, MySQL und Browsern.

## 8. Deployment und Abnahme

### Vor dem Deployment

1. Datenbank-Backup erstellen.
2. Alle neuen Dateien, einschließlich `includes/migrations.php`, `tests/` und der Signal-SVGs, in den gewünschten Commit aufnehmen.
3. `node tests/static-checks.mjs` ausführen.
4. Änderungen zuerst in einer Staging-Installation aktivieren.

### Frische Installation

1. Plugin aktivieren und kontrollieren, dass die Schema-Version `2026082601` erreicht wird.
2. Alle Admin-Seiten öffnen.
3. Die manuelle Schema-Prüfung erneut ausführen; sie muss ohne neue Fehler oder Dubletten abschließen.

### Upgrade einer bestehenden Installation

1. Vorherigen Datenbestand sichern.
2. Plugin aktualisieren und als Administrator eine Admin-Seite öffnen.
3. Migrationsmeldung und PHP-/MySQL-Logs prüfen.
4. Die manuelle Schema-Prüfung erneut ausführen und damit die Idempotenz bestätigen.

### Berechtigungsmatrix

Für einen Testbenutzer ohne `manage_options` mindestens folgende Fälle prüfen:

| Fall | Erwartung |
|---|---|
| Bereich nicht freigegeben | Ansicht und Änderung verweigert |
| Bereich freigegeben, Leitstelle nicht freigegeben | Objekt nicht sichtbar und Änderung verweigert |
| Bereich und Leitstelle freigegeben | Lesen, Speichern und Löschen erlaubt |
| Objekt gehört zusätzlich zu einer fremden Leitstelle | Änderung verweigert |
| Verschieben in freigegebenen Zielbereich | erlaubt |
| Verschieben in nicht freigegebenen Zielbereich | verweigert |
| fehlende/falsche Nonce | verweigert |
| Schreibaktion per GET | Status 405 beziehungsweise keine Aktion |

### Multiplayer und Snapshot

1. Dieselbe Instanz in zwei Browsern öffnen und parallele Ticks auslösen: Es darf nur ein automatischer Einsatz entstehen.
2. Mehrfach nur den Snapshot abrufen: Datenbankzustände dürfen sich dadurch nicht verändern.
3. Fahrzeug unterhalb und oberhalb der Positionsschwelle bewegen und den Snapshot vergleichen.
4. Fahrzeug zur Wache zurückführen: Es darf nicht mehr in der Positionsliste stehen.
5. Eine reine Statusänderung prüfen: Sie darf ohne Positionsdaten in `vehicle_statuses` erscheinen.

## 9. Wichtige Implementierungsdateien

| Thema | Dateien |
|---|---|
| Berechtigungen | `includes/permissions.php`, `includes/benutzer.php` |
| Fahrzeug- und Wachen-Endpunkte | `includes/ajax/ajax_fahrzeuge.php`, `includes/ajax/ajax_wachen.php` |
| Weitere geschützte Schreibwege | `includes/ajax/ajax_hospitals.php`, `includes/ajax/ajax_nebenstellen.php`, `includes/ajax/ajax_users.php` |
| Migrationen | `includes/migrations.php`, `includes/schema_import.php`, `database/schema.sql` |
| Tick und Snapshot | `includes/ajax/ajax_simulation.php`, `js/simulation-workspace.js` |
| Admin-Hilfe | `includes/help.php`, `includes/admin-menu.php` |
| Prüfungen | `tests/static-checks.mjs` |
