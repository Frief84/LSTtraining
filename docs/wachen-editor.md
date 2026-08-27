# Wachen-Editor

[Wiki-Start](README.md) · [Leitstellen-Editor](leitstellen-editor.md) · [Fahrzeug-Editor](fahrzeuge-editor.md)

**Backend-Menü:** LST Training → Wachen

Wachen sind stationäre Standorte für Fahrzeuge. Ihr vollständiger Leitstellen-Scope ergibt sich aus direkten Leitstellen- und indirekten Nebenleitstellen-Zuordnungen.

## Benötigte Berechtigung

- Bereichsrecht **Wachen**;
- Zugriff auf alle Leitstellen des bestehenden Datensatzes;
- beim Verschieben zusätzlich Zugriff auf alle Zielleitstellen.

## Listenansicht und Filter

Die Wachenliste unterstützt:

- Suche nach ID oder Name;
- Land und Bundesland;
- Leitstelle;
- Nebenleitstelle;
- Karten- und Polygonwerkzeuge für Zuordnungen.

Über einen Leitstellenkontext kann die Liste direkt aus dem Leitstellen-Editor geöffnet werden.

## Editorfelder

| Feld | Verwendung |
|---|---|
| Name | sichtbarer Standortname |
| Typ | `FW`, `FFW`, `SEG`, `RD` oder `FRRD` |
| Land/Bundesland | regionale Filterung |
| Leitstellen | direkte Hauptleitstellen-Zuordnung |
| Nebenleitstellen | indirekte Bereichszuordnung |
| Position | Karten- und Basisposition |
| Anfahrtsposition | Zielpunkt ankommender Fahrzeuge |
| Abfahrtsposition | Startpunkt ausrückender Fahrzeuge |
| Bild | optionale Standortgrafik |

Eine getrennte Anfahrts- und Abfahrtsposition kann verhindern, dass Routen auf einer falschen Straßenseite oder innerhalb eines Gebäudepolygons enden.

## Neue Wache anlegen

1. **Neue Wache** öffnen.
2. Name, Typ und Region auswählen.
3. direkte Leitstellen und/oder Nebenleitstellen zuordnen.
4. Position auf der Karte setzen.
5. Anfahrts- und Abfahrtsposition prüfen.
6. optional ein Bild hinterlegen.
7. speichern und anschließend Fahrzeuge anlegen.

Nicht-Administratoren dürfen keine unzugeordnete Wache erzeugen.

## Wachen per Polygon zuordnen

Die Kartenfunktionen können Wachen innerhalb eines Polygons finden sowie zuordnen oder Zuordnungen entfernen. Vor einer Massenänderung müssen Filter, Zielbereich und Benutzerrechte geprüft werden.

## Wache verschieben

Eine Verschiebung bedeutet eine Änderung der Leitstellen- beziehungsweise Nebenleitstellen-Zuordnung.

Erlaubt ist sie nur, wenn der Benutzer:

- den bisherigen vollständigen Scope bearbeiten darf;
- Wachenrecht besitzt;
- den vollständigen Ziel-Scope bearbeiten darf.

Die Fahrzeuge bleiben derselben Wache zugeordnet und wechseln dadurch ebenfalls ihren abgeleiteten Leitstellenbereich. Nach dem Speichern müssen deshalb Fahrzeuglisten und Benutzerzugriffe kontrolliert werden.

## Löschen

Löschen erfolgt per geschütztem POST-Endpunkt. Vorher prüfen, ob Fahrzeuge an der Wache liegen. Abhängige Fahrzeugdatensätze dürfen nicht unbeabsichtigt verloren gehen.

## Häufige Fehler

| Problem | Prüfung |
|---|---|
| Zielbereich nicht auswählbar | Leitstellenfreigabe des Benutzers prüfen |
| Fahrzeug verschwindet nach Verschiebung | neuer Scope ist für den Benutzer nicht freigegeben |
| Route startet falsch | Abfahrtsposition prüfen |
| Route endet falsch | Anfahrtsposition prüfen |
| Wache erscheint doppelt | direkte und indirekte Zuordnungen kontrollieren |
| Speichern wird abgelehnt | bestehenden und neuen Scope vollständig prüfen |

---

[Wiki-Start](README.md) · [Nächster Artikel: Fahrzeug-Editor](fahrzeuge-editor.md)
