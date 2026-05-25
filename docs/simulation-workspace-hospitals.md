# Simulations-Workspace: Krankenhäuser

## Datenquelle

Der Simulations-Workspace lädt Krankenhäuser über den AJAX-Endpunkt
`lsttraining_sim_get_workspace_hospitals` aus der bestehenden Tabelle
`krankenhaeuser`.

Die relevante SQL-Struktur liegt in `database/schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS `krankenhaeuser` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `poi_id`           VARCHAR(50) NOT NULL UNIQUE,
  `name`             VARCHAR(255) NOT NULL,
  `latitude`         DOUBLE NOT NULL,
  `longitude`        DOUBLE NOT NULL,
  `versorgungsstufe` ENUM('Grundversorgung','Schwerpunktversorger','Maximalversorger') NOT NULL DEFAULT 'Grundversorgung',
  `trauma_level`     TINYINT NOT NULL DEFAULT 0,
  `helipad`          TINYINT(1) NOT NULL DEFAULT 0,
  `departments`      JSON NOT NULL,
  PRIMARY KEY (`id`)
);
```

Die Zuordnung zur Leitstelle erfolgt, wenn vorhanden, über
`leitstellen.available_hospitals`. Der Workspace akzeptiert dort interne
Krankenhaus-IDs, `poi_id`-Werte sowie Objektformen mit `id`, `hospital_id`,
`krankenhaus_id` oder `poi_id`. Wenn die Zuordnung leer ist oder keine Treffer
liefert, fällt der Workspace auf alle Krankenhäuser zurück.

## Fachbereiche

Die Fachbereichsfarben und Labels kommen aus `data/departments.json`.

Der bestehende Fachbereichseditor speichert `krankenhaeuser.departments`
typischerweise als JSON-Liste einzelner Objekte:

```json
[
  { "CT": { "Lat": 52.0, "Long": 13.0 } },
  { "TRAU": { "Lat": 52.0, "Long": 13.0 } }
]
```

Der Workspace unterstützt zusätzlich ältere Formen wie:

```json
["CT", "TRAU"]
```

und Objektformen mit `code`, `department` oder `label`.

## Kartenanzeige

Krankenhäuser werden im Kartenpanel als cyanfarbene Marker mit `H` angezeigt.
Der Marker nutzt primär `krankenhaeuser.latitude` und
`krankenhaeuser.longitude`. Falls diese Koordinaten fehlen oder `0,0` sind,
wird die erste gültige Fachbereichskoordinate aus `departments` verwendet.

Ein Klick auf den Marker öffnet im Kartenstatus eine kompakte Karte mit Name,
Versorgungsstufe und Fachbereichs-Badges.

## Automatische Krankenhauszuweisung von Patienten

Die automatische Zielauswahl für einen Patiententransport ist in
`includes/simulation/transport.php` implementiert. Sie erfolgt erst, wenn:

1. der Patient transportbereit ist,
2. der Patient nicht verstorben und noch nicht in einem laufenden oder
   abgeschlossenen Transport ist,
3. ein geeignetes Transportmittel am Einsatzort den Status `vor_ort` hat.

Sobald die Voraussetzungen erfüllt sind, ermittelt die Simulation ein Ziel
und setzt das transportierende Fahrzeug auf die Route zum Krankenhaus. Im
Einsatzeditor kann pro Patient optional ein bevorzugter Fachbereich
festgelegt werden; die konkrete Klinik wird weiterhin anhand der verfügbaren
Häuser und des Scores ausgewählt.

### Manuelle Vorgabe pro Patient

Jede Patientenzeile im Grundeinsatz und in einer Lagevariante besitzt den
aufklappbaren Bereich `Zielklinik festlegen`. Standard ist `Automatisch nach
Lage und Triage`. Bei einer manuellen Auswahl wird im Patienten-JSON das Feld
`preferred_hospital_department` mit dem Fachbereichscode gespeichert:

```json
{
  "patient_id": "p1",
  "injury_summary": "Brustschmerz nach Aufprall",
  "preferred_hospital_department": "CARD"
}
```

Eine manuelle Vorgabe ersetzt für diesen Patienten die nachfolgend
dokumentierte Stichwortlogik. Sie ist keine harte Sperre: Die Präferenzliste
lautet zunächst gewählter Bereich, danach `NOTF`. Besitzt kein verfügbares
Krankenhaus einen dieser Bereiche, kann weiterhin ein Krankenhaus mit einem
anderen vorhandenen Fachbereich über die Fallback-Wertung ausgewählt werden.

Eine Lagevariante speichert ihre Auswahl selbst. Der Wert `Automatisch` in
einer Variante setzt damit eine manuelle Vorgabe des Grundpatienten für die
eingetretene Lage wieder zurück.

### Textquellen für die Fachbereichsauswahl

Für jeden transportbereiten Patienten werden folgende Textfelder zu einem
gemeinsam durchsuchten Text verbunden:

| Quelle | Bedeutung |
| --- | --- |
| `patient.injury_summary` | Verletzungsbild oder Zustand des einzelnen Patienten |
| `incident.einsatzart` | Einsatzart |
| `incident.einsatztyp` | Einsatztyp |
| `incident.caller_text` | Anruftext beziehungsweise ursprüngliche Meldung |
| `incident.lagemeldung` | Lagemeldung aus dem Einsatzverlauf |

Zusätzlich wird die strukturierte Triage-Kategorie des Patienten
(`patient.triage_category`) separat ausgewertet.

Die Stichworterkennung erfolgt als nicht auf Wortgrenzen beschränkte
Teilwortsuche ohne Unterscheidung von Groß- und Kleinschreibung. Beispielsweise
triggert `Verkehrsunfall`, weil darin `verkehr` und `unfall` vorkommen.
Die Abkürzung `VU` allein wird dagegen nicht erkannt.

### Triggerregeln und Priorität

Die Regeln werden in der folgenden Reihenfolge geprüft. Die erste zutreffende
Regel gewinnt; weitere passende Stichwörter werden danach nicht mehr
berücksichtigt.

| Priorität | Trigger: Text enthält eines der Teilwörter oder Bedingung | Bevorzugte Fachbereiche in Reihenfolge |
| ---: | --- | --- |
| 1 | `schlaganfall`, `stroke`, `halbseit`, `neurolog` | `STRK`, `NEUR`, `CT`, `NOTF` |
| 2 | `herz`, `brustschmerz`, `infarkt`, `reanimation`, `kreislauf` | `CARD`, `CAT`, `INTX`, `NOTF` |
| 3 | `brand`, `verbrenn` | `BURN`, `CHIR`, `NOTF` |
| 4 | `vergift`, `intox`, `gas`, `rauch` | `TOXI`, `INTX`, `NOTF` |
| 5 | `kind`, `baby`, `saeugling`, `säugling` | `KINA`, `PED`, `KKH`, `NOTF` |
| 6 | Triage `I` oder `II`, oder `unfall`, `trauma`, `sturz`, `verletz`, `blutung`, `verkehr` | `TRAU`, `UNF`, `CHIR`, `CT`, `NOTF` |
| 7 | Keine vorherige Regel trifft zu | `NOTF`, `IMED`, `CHIR` |

Beispiele für die Priorität:

| Erfasster Text / Patient | Ergebnis |
| --- | --- |
| `Verkehrsunfall, Fahrer verletzt` | `TRAU`, `UNF`, `CHIR`, `CT`, `NOTF` |
| `Verkehrsunfall, Patient mit halbseitiger Lähmung` | `STRK`, `NEUR`, `CT`, `NOTF` |
| `Verkehrsunfall, Brustschmerz nach Aufprall` | `CARD`, `CAT`, `INTX`, `NOTF` |
| `Verkehrsunfall mit Fahrzeugbrand` | `BURN`, `CHIR`, `NOTF` |
| Patient Triage `I` ohne weitere erkannte Stichwörter | `TRAU`, `UNF`, `CHIR`, `CT`, `NOTF` |

### Bewertung der Krankenhäuser

Berücksichtigt werden zunächst die über `leitstellen.available_hospitals`
zugeordneten Häuser. Ist keine verwendbare Zuordnung vorhanden oder liefert
sie keine Treffer, werden alle Einträge aus `krankenhaeuser` berücksichtigt.

Für jedes Krankenhaus wird aus der Fachbereichsreihenfolge der erste passende
vorhandene Fachbereich gesucht. Dieser Treffer liefert die Fachbereichspunkte:

| Treffer in der gewünschten Reihenfolge | Punkte |
| ---: | ---: |
| 1. Fachbereich | 100 |
| 2. Fachbereich | 92 |
| 3. Fachbereich | 84 |
| 4. Fachbereich | 76 |
| 5. Fachbereich | 68 |
| Kein gewünschter Treffer, aber mindestens ein Fachbereich vorhanden | 20 |
| Keine Fachbereiche vorhanden | 0 |

Danach wird die Entfernung vom Einsatzort zum Krankenhaus abgezogen:

```text
Score = Fachbereichspunkte - min(40, Entfernung_in_Metern / 2500)
```

Die Entfernung kann die Punktzahl damit maximal um 40 Punkte verringern. Das
Krankenhaus mit dem höchsten Score wird als Transportziel gespeichert. Bei
identischem Score gewinnt das zuerst verarbeitete Krankenhaus; die Abfrage
liefert Krankenhäuser alphabetisch nach Namen.

### Vorschau im Einsatzeditor

Der aufklappbare Klinikbereich zeigt für jeden Patienten eine Vorschau der
Präferenzliste. Bei manueller Auswahl wird beispielsweise `CARD > NOTF`
angezeigt und darauf hingewiesen, dass die Stichwortauswertung nicht
verwendet wird. Im Automatikmodus zeigt die Vorschau die erkannte Regel und
ihre Fachbereichsreihenfolge.

Die Vorschau basiert auf den stabilen Editordaten: Einsatzart, Einsatztyp,
Lagebeschreibung beziehungsweise Text der Lagevariante und dem
Patientenzustand. Der konkrete Anruftext entsteht teilweise erst beim
Simulationslauf und kann deshalb im Automatikmodus das endgültige Ergebnis
noch verändern.

### Derzeit nicht ausgewertete Kriterien

Für die Zielauswahl werden derzeit keine Belegung, Bettenkapazität,
Abteilungsabmeldung oder manuelle Auswahl eines konkreten Krankenhauses
berücksichtigt.
Die Felder `trauma_level` und `helipad` werden zwar mit den Krankenhausdaten
geladen, gehen aber nicht in den Score ein.

### Bekannte Auswirkungen der Stichwortsuche

Da Teilwörter in allen fünf Textquellen gemeinsam geprüft werden, können auch
allgemeine Einsatzbeschreibungen die Zielwahl dominieren:

| Textbeispiel | Auswirkung |
| --- | --- |
| `Fahrzeugbrand`, auch ohne dokumentierte Verbrennung | triggert die Brand-/Verbrennungsregel |
| `Kind` im Anruftext bei einem Verkehrsunfall | triggert die Kinderregel, sofern keine höher priorisierte Regel passt |
| `VU` ohne ausgeschriebenes `Verkehrsunfall` | triggert die Unfallregel nicht |
| Triage `I` oder `II` bei internistischem Patienten ohne erkannte Spezialstichwörter | triggert die Trauma-/Unfallregel |

Beispiel für eine manuelle Abweichung: Ein Patient aus einem Verkehrsunfall
mit der Vorgabe `CARD` wird mit `CARD > NOTF` bewertet. Der
Verkehrsunfalltext erzeugt für diesen Patienten dann nicht die automatische
Trauma-Reihenfolge.
