# Krankenhaus-Editor

[Wiki-Start](README.md) · [Leitstellen-Editor](leitstellen-editor.md) · [Krankenhäuser im Workspace](simulation-workspace-hospitals.md)

**Backend-Menü:** LST Training → Krankenhäuser

Krankenhäuser sind globale Stammdatensätze. Damit ein Haus regulär als Ziel einer Leitstelle verwendet wird, muss es anschließend im Leitstellen-Editor freigegeben werden.

## Benötigte Berechtigung

- Bereichsrecht **Krankenhäuser**;
- passende Leitstellenfreigabe bei leitstellenbezogenen Zuordnungen;
- Benutzer ohne dieses Recht sehen weder Menü noch Editorartikel.

## Listen- und Kartenansicht

Die Seite zeigt:

- Karte aller geladenen Krankenhäuser;
- Tabelle mit ID, Name, Versorgungsstufe, Trauma-Level und Koordinaten;
- Aktionen zum Anlegen, Bearbeiten und Löschen.

Tabellenspalten können zur Orientierung sortiert werden.

## Editorfelder

| Feld | Verwendung |
|---|---|
| Name | sichtbarer Klinikname und Pflichtfeld |
| Versorgungsstufe | Grundversorgung, Schwerpunktversorger oder Maximalversorger |
| Trauma-Level | numerische Einstufung des Traumazentrums |
| Koordinaten | Position von Notaufnahme beziehungsweise RTW-Anfahrt |
| Fachbereiche | medizinische Abteilungen |
| Helipad | Hubschrauberlandeplatz verfügbar |

Der Marker soll an der tatsächlichen RTW-Halteposition oder am Haupteingang der Notaufnahme liegen. Das verbessert die Routenberechnung.

## Fachbereichs-Editor

Über **Fachbereiche bearbeiten** werden die tatsächlich vorhandenen Abteilungen ausgewählt. Die Daten werden strukturiert gespeichert und bei der automatischen Krankenhauswahl mit dem Patientenbedarf verglichen.

Vorgehen:

1. Krankenhaus zunächst speichern.
2. Fachbereichs-Editor öffnen.
3. verfügbare Abteilungen auswählen.
4. speichern und erneut öffnen.
5. Patientenvorschau einer passenden Einsatzvorlage ausführen.

## Einer Leitstelle zuordnen

1. **LST Training → Leitstellen** öffnen.
2. gewünschte Leitstelle bearbeiten.
3. **Krankenhäuser bearbeiten** öffnen.
4. Krankenhaus auswählen und Zuordnung speichern.

Ein Krankenhaus kann mehreren Leitstellen zur Verfügung stehen.

## Löschen

Die Löschaktion verwendet POST, Nonce und Berechtigungsprüfung. Beim Löschen muss die Krankenhaus-ID außerdem aus den Leitstellenfreigaben entfernt werden.

Vorher prüfen:

- laufende oder gespeicherte Instanzen;
- Patientenvorlagen mit festem Klinikziel;
- Freigaben mehrerer Leitstellen;
- externe Integrationen über die REST-API.

## Häufige Fehler

| Problem | Prüfung |
|---|---|
| Krankenhaus fehlt in der Simulation | Leitstellenfreigabe kontrollieren |
| Route endet falsch | Markerposition an der Notaufnahme korrigieren |
| Patient erhält kein Ziel | benötigten und vorhandenen Fachbereich vergleichen |
| Fachbereiche fehlen nach Speichern | JSON-Antwort, Nonce und Datenbankschema prüfen |
| Editor wird nicht angezeigt | persönliches Krankenhausrecht prüfen |

---

[Wiki-Start](README.md) · [Vertiefung: Krankenhauslogik](simulation-workspace-hospitals.md)
