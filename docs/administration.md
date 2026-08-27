# Administration und Stammdaten

[Wiki-Start](README.md) · [Erste Schritte](erste-schritte.md) · [Sicherheit und Berechtigungen](sicherheit-migration-multiplayer.md)

Diese Seite dokumentiert alle fachlichen Backend-Bereiche. Menüpunkte werden nur angezeigt, wenn der angemeldete Benutzer das jeweilige Bereichsrecht besitzt. Zusätzlich wird jedes Objekt serverseitig gegen die freigegebenen Leitstellen geprüft.

## Empfohlene Reihenfolge

```text
Leitstelle
  ├─ Nebenleitstellen und Nachbarn
  ├─ Krankenhäuser und POIs
  ├─ Wachen
  │    └─ Fahrzeuge
  ├─ Einsatzvorlagen
  │    └─ Anrufbausteine und Patienten
  └─ Anruferprofile
```

Erst danach sollten Benutzerrechte vergeben und Testsimulationen gestartet werden.

## Leitstellen

**Menü:** LST Training → Leitstellen
**Bereichsrecht:** Leitstellen

Eine Leitstelle bildet den Haupt-Scope für Stammdaten, Benutzerrechte und Spielinstanzen.

### Wichtige Felder

| Feld/Funktion | Bedeutung |
|---|---|
| Name | sichtbare Bezeichnung der Leitstelle |
| Ort, Bundesland, Land | regionale Einordnung |
| Latitude/Longitude | Mittelpunkt beziehungsweise Kartenstart |
| Einsatzgebiet/GeoJSON | zulässiger räumlicher Simulationsbereich |
| Nachbarleitstellen | angrenzende Bereiche für Unterstützung |
| verfügbare Krankenhäuser | Klinikziele dieser Leitstelle |
| POIs | besondere Orte für Einsatzvorlagen |
| Polizei-/Rettungsdienst-Standardbild | Darstellung dynamischer Unterstützungsfahrzeuge |
| Signallicht-Konfiguration | normalisierte Lichtpunkte auf dem Fahrzeugbild |

### Leitstelle anlegen oder bearbeiten

1. **Neu hinzufügen** beziehungsweise einen bestehenden Eintrag öffnen.
2. Stammdaten und Kartenposition eintragen.
3. Einsatzgebiet im GeoJSON-Editor zeichnen, importieren oder bearbeiten.
4. Nachbarleitstellen auswählen.
5. Krankenhäuser und POIs zuordnen.
6. Standardbilder und Signallichter prüfen.
7. speichern und die Leitstelle erneut öffnen.

### Löschen

Löschen erfolgt ausschließlich über eine geschützte POST-Aktion mit datensatzbezogenem Sicherheits-Token. Wegen abhängiger Daten muss vorher geprüft werden, welche Wachen, Instanzen und Zuordnungen betroffen sind. Vor umfangreichen Löschungen ist ein Backup erforderlich.

## Nebenleitstellen

**Menü:** LST Training → Nebenstellen
**Bereichsrecht:** Nebenstellen

Nebenleitstellen bilden zusätzliche oder benachbarte Dispositionsbereiche. Sie können mehreren Hauptleitstellen zugeordnet und als Nachbarleitstelle gekennzeichnet werden.

### Wichtige Felder

- Name;
- Zuständigkeit und Aufgaben;
- Standorte;
- Einwohnerzahl und Fläche in km²;
- GPS-Position;
- Nachbarleitstellen-Kennzeichen;
- Einsatzgebiet als GeoJSON;
- Zuordnungen zu Leitstellen und Wachen.

Einwohnerzahl und Fläche können in die simulierte Einsatzlast einfließen.

### Bedienablauf

1. Nebenleitstelle anlegen oder auswählen.
2. Stammdaten und Position eintragen.
3. Einsatzgebiet auf der Karte prüfen.
4. mit den zuständigen Hauptleitstellen verbinden.
5. Wachen zuordnen.
6. optional **Leitstelle übernehmen** verwenden, um geeignete Daten einer vorhandenen Leitstelle zu kopieren.

Eine Übernahme sollte anschließend immer manuell auf Name, Gebiet, Wachen und Fahrzeuge geprüft werden.

## Krankenhäuser

**Menü:** LST Training → Krankenhäuser
**Bereichsrecht:** Krankenhäuser

Krankenhäuser sind mögliche Transportziele. Sie werden zunächst global angelegt und anschließend einer oder mehreren Leitstellen freigegeben.

### Wichtige Felder

| Feld | Bedeutung |
|---|---|
| Name | sichtbarer Klinikname |
| POI-ID | eindeutige interne/externe Kennung; bei REST-Neuanlage optional automatisch |
| Koordinaten | Routing- und Kartenposition |
| Versorgungsstufe | Grund-, Schwerpunkt- oder Maximalversorgung |
| Trauma-Level | numerische Einstufung |
| Helipad | Hubschrauberlandeplatz vorhanden |
| Fachbereiche | verfügbare medizinische Abteilungen als strukturierte Daten |

### Fachbereiche und Leitstellenfreigabe

1. Krankenhaus speichern.
2. **Fachbereiche bearbeiten** öffnen und die tatsächlich verfügbaren Abteilungen auswählen.
3. In der Leitstelle **Krankenhäuser bearbeiten** öffnen.
4. Krankenhaus für die Leitstelle freigeben.
5. in einer Testsimulation die Kartenanzeige und Patientenzuordnung prüfen.

Die Auswahl eines Zielkrankenhauses berücksichtigt insbesondere benötigte Fachbereiche und Routing. Details stehen unter [Krankenhäuser im Simulations-Workspace](simulation-workspace-hospitals.md).

## Wachen

**Menü:** LST Training → Wachen
**Bereichsrecht:** Wachen

Wachen sind stationäre Standorte für Fahrzeuge. Eine Wache kann direkt Leitstellen und zusätzlich Nebenleitstellen zugeordnet sein.

### Wichtige Felder

- Name;
- Typ: `FW`, `FFW`, `SEG`, `RD` oder `FRRD`;
- Land und Bundesland;
- Leitstellen- und Nebenleitstellen-Zuordnungen;
- Position;
- separate Anfahrts- und Abfahrtsposition;
- optionales Bild;
- Realitäts- und Quellenhinweise, soweit im verwendeten Importweg verfügbar.

### Filtern und Zuordnen

Die Liste kann unter anderem nach Suche, Bundesland, Leitstelle und Nebenleitstelle gefiltert werden. Kartenwerkzeuge unterstützen das Finden sowie Zuordnen von Wachen innerhalb eines Polygons.

### Verschieben in einen anderen Bereich

Ein Benutzer darf eine Wache nur verschieben, wenn er sowohl den bisherigen vollständigen Scope als auch alle Zielleitstellen bearbeiten darf. Direkte Leitstellen- und indirekte Nebenleitstellen-Zuordnungen werden gemeinsam geprüft.

Nach einer Verschiebung müssen die Fahrzeuge der Wache und deren Sichtbarkeit für beteiligte Benutzer kontrolliert werden.

## Fahrzeuge

**Menü:** LST Training → Fahrzeuge
**Bereichsrecht:** Fahrzeuge

Ein Fahrzeug gehört genau zu einer Wache. Sein Leitstellen-Scope wird ausschließlich serverseitig über diese Wache ermittelt.

### Wichtige Felder

| Feld | Bedeutung |
|---|---|
| Wache | stationärer Standort und Berechtigungs-Scope |
| Rufname | innerhalb der Wache eindeutige Bezeichnung |
| Fahrzeugtyp | fachliche Fahrzeugklasse |
| Status | Betriebszustand der Stammdaten |
| FMS-Status | Status `1` bis `6` |
| Sondersignal | Ausgangswert für neue Instanzen |
| First Responder | besondere Einsatzfunktion |
| Dienstzeiten | optionale zeitliche Verfügbarkeit |
| Position | Ausgangsposition; normalerweise an der Wache |
| Bild | individuelle Darstellung |
| Signallichter | normalisierte Lichtpunkte auf dem Bild |
| Quellenhinweis | Herkunft beziehungsweise Bemerkung |

### Stammdaten und laufende Instanz unterscheiden

Änderungen im Fahrzeugeditor betreffen die Stammdaten und damit neue Instanzen. Der Zustand eines Fahrzeugs in einer bereits laufenden Instanz wird als Delta zu deren Baseline gespeichert. Er darf nicht durch eine Stammdatenänderung unbemerkt auf andere Instanzen übertragen werden.

### Fahrzeug in eine andere Leitstelle verschieben

Technisch wird das Fahrzeug einer anderen Wache zugeordnet. Erforderlich sind:

- Fahrzeugrecht;
- Zugriff auf den bisherigen Fahrzeug-Scope;
- Zugriff auf den vollständigen Scope der Zielwache.

Fehlt eine Zielberechtigung, lehnt der Server die Änderung auch dann ab, wenn eine manipulierte Anfrage die fremde Wachen-ID enthält.

## Einsatzvorlagen

**Menü:** LST Training → Einsätze
**Bereichsrecht:** derzeit an das Leitstellenrecht gekoppelt

Einsatzvorlagen bestimmen, welche Lagen automatisch oder manuell erzeugt werden können.

### Allgemeine Angaben

- Anzeigename;
- Einsatzstichwort/Typ;
- Fachbereich Rettungsdienst oder Feuerwehr;
- aktiv/inaktiv;
- interne Beschreibung;
- optionale Tags.

### Ortsbindung

| Modus | Verwendung |
|---|---|
| Überall | zufälliger geeigneter Ort im Einsatzgebiet |
| Gebietstyp | ausgewählte Landschafts-, Nutzungs- oder Straßenlayer |
| POI-Typ | passender gespeicherter Point of Interest |
| Fester Punkt | feste Koordinate mit optionalem Radius |

Gebietstypen umfassen beispielsweise Wohnen, Industrie, Gewerbe, Einzelhandel, Landwirtschaft, Wald, Freizeitflächen, Straßen, Autobahnen, Bahnflächen oder Friedhöfe. Die vollständige Layerliste und Gewichtung steht unter [Einsatz-Ortsbindung](einsatz-ortsbindung.md).

### Zeit, Jahreszeit und Wetter

Vorlagen können auf Zeitfenster, Wochentage, Jahreszeiten und Wetterbedingungen eingeschränkt werden. Eine Vorlage ist nur plausibel, wenn die festgelegten Bedingungen zur Simulationszeit passen.

### Anruf der Einsatzvorlage

Die Vorlage liefert die einsatzspezifischen Teile:

- `problem`: gemeldetes Problem;
- `observation`: sichtbare beziehungsweise zusätzliche Beobachtung;
- `extra`: weitere Information wie Zugang oder Gefahr.

Diese Teile werden mit einem Anruferprofil kombiniert. Die Vorschaufunktion erzeugt Beispielanrufe, bevor die Vorlage gespeichert wird.

### Lage, Patienten und Fahrzeugbedarf

- Die Start-Lagebeschreibung erscheint in der Einsatzkarte.
- Patienten werden einzeln mit Zustand, Entwicklung, Transportfähigkeit, Rettungsmittelbedarf und optionalem Klinikziel beschrieben.
- Zusätzliche Fahrzeugklassen werden nur für Bedarf eingetragen, der nicht bereits aus den Patienten entsteht.
- Weitere Lageschritte beziehungsweise Rückmeldungen können den Einsatzverlauf verändern.

Nach dem Speichern sollten Ortsvorschau, Anrufvorschau, Patientenrouting und Ressourcenbedarf getestet werden.

## Anrufe und Anruferprofile

**Menü:** LST Training → Anruferprofile
**Bereichsrecht:** derzeit an das Leitstellenrecht gekoppelt

Anruferprofile beschreiben, **wie** eine Person spricht. Einsatzvorlagen beschreiben, **was** passiert ist.

### Allgemeine Profilfelder

- Name und Aktivstatus;
- Kategorie, zum Beispiel privat, Angehörige, Pflege, Firma, Schule, Behörde oder Sicherheitsdienst;
- Tonfall von ruhig bis panisch beziehungsweise professionell/knapp;
- Emotionslevel 1 bis 4;
- Sortierung und interne Notizen.

### Verhalten

Ein Profil kann festlegen, ob der Anrufer Name, Adresse, POI-Name oder Firmen-/Einrichtungsname nennt. Unabhängig davon stellt die Simulation sicher, dass ein nutzbarer Gesprächseinstieg und eine Ortsangabe vorhanden sind.

### Sprachbausteine

| Baustein | Aufgabe |
|---|---|
| `greeting` | Begrüßung |
| `self_intro` | Selbstvorstellung |
| `problem_intro` | Überleitung zur Meldung |
| Einsatzteile | `problem`, `observation`, `extra` aus der Einsatzvorlage |
| `location_intro` | Orts-/Adressnennung |
| `urgency` | Dringlichkeit und Verhalten |
| `closing` | Gesprächsabschluss |
| `callback_request` | Rückruf beziehungsweise Erreichbarkeit |

Die verbindliche Reihenfolge ist:

```text
Begrüßung → Vorstellung → Überleitung → Was → Wo → Dringlichkeit → Abschluss → Rückruf
```

### Platzhalter

Unter anderem stehen `{full_name}`, `{first_name}`, `{last_name}`, `{formal_name}`, `{address_full}`, `{location}`, `{poi_name}`, `{company_name}`, `{problem}`, `{observation}` und `{extra}` zur Verfügung.

Die Vorschau kann mit Beispielgeschlecht, Adresse, POI, Firma, Problem, Beobachtung und Zusatzinformation mehrere vollständige Testanrufe erzeugen.

### Profilwahl im Einsatz

Sind einer Einsatzvorlage Profile mit Gewichtung zugeordnet, wird aus diesen gewählt. Ohne Zuordnung wird aus den aktiven Profilen gewählt. Deaktivierte Profile sollen nicht für neue Anrufe verwendet werden.

## Benutzer

**Menü:** LST Training → Benutzer
**Berechtigung:** nur WordPress-Administratoren

Pro Benutzer werden Bereichsrechte und erlaubte Leitstellen kombiniert. Details und Testmatrix stehen unter [Sicherheit, Migration und Berechtigungen](sicherheit-migration-multiplayer.md).

## Verlauf / Aktivität

**Menü:** LST Training → Verlauf / Aktivität
**Berechtigung:** nur WordPress-Administratoren

Das Protokoll kann nach Zeitraum, Benutzer-ID, Objektbereich und Aktion gefiltert werden. Es unterstützt die Nachvollziehbarkeit von Erstellen, Ändern, Löschen und Rechteänderungen. Es ersetzt kein extern gesichertes Audit- oder Serverlog.

## Einstellungen, Schema und Hilfe

- **Einstellungen:** Kartenseite, Datenbankmodus, Routing-Schlüssel und Standardfahrzeugbild.
- **Schema-Prüfung:** manueller, geschützter Lauf der versionierten Migrationen.
- **Dokumentation auf Seite:** rendert die freigegebenen Markdown-Artikel innerhalb des aktiven WordPress-Themes; alternativ steht `[lsttraining_docs]` bereit.
- **Hilfe & Dokumentation:** rollenabhängige Bedienhilfe im Backend. Administrator- und API-Inhalte werden normalen Benutzern nicht angezeigt.

## Abnahme der Stammdaten

Vor einer Schulung mindestens prüfen:

- Leitstelle besitzt Mittelpunkt und gültiges Einsatzgebiet.
- Wachen sind richtig zugeordnet und liegen plausibel.
- Fahrzeuge haben eindeutige Rufnamen, Typen und Ausgangszustände.
- Krankenhäuser sind freigegeben und haben passende Fachbereiche.
- aktive Einsatzvorlagen finden gültige Orte und erzeugen verständliche Anrufe.
- Patientenbedarf und zusätzliche Fahrzeuge ergeben keine Doppelzählung.
- Benutzer sehen ausschließlich erlaubte Bereiche und Leitstellen.

---

[Zurück zur Wiki-Startseite](README.md) · [Weiter: Simulation und Multiplayer](simulation-und-multiplayer.md)
