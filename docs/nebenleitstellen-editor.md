# Nebenleitstellen-Editor

[Wiki-Start](README.md) · [Leitstellen-Editor](leitstellen-editor.md) · [Wachen-Editor](wachen-editor.md)

**Backend-Menü:** LST Training → Nebenstellen

Nebenleitstellen beschreiben zusätzliche, angrenzende oder unterstützende Dispositionsbereiche. Sie können Hauptleitstellen und Wachen zugeordnet werden.

## Benötigte Berechtigung

- Bereichsrecht **Nebenstellen**;
- Freigabe für alle Leitstellen, denen die Nebenleitstelle zugeordnet ist;
- nicht zugeordnete Nebenleitstellen sind für Nicht-Administratoren gesperrt.

## Listenansicht

Die vorhandenen Nebenleitstellen können durchsucht, geöffnet und – mit passender Berechtigung – gelöscht werden. Die Liste soll nur Datensätze aus freigegebenen Leitstellenbereichen enthalten.

## Editorfelder

| Feld | Verwendung |
|---|---|
| Name | eindeutige sichtbare Bezeichnung |
| Zuständigkeit | fachlicher Zuständigkeitsbereich |
| Einwohner | Einflussgröße der simulierten Einsatzlast |
| Fläche in km² | statistische und räumliche Kenngröße |
| Standort | GPS-Wert im Format `Latitude, Longitude` |
| Standortkarte | visuelle Positionierung |
| Einsatzgebiet | GeoJSON-Polygon des Bereichs |

Die Fläche kann aus dem Einsatzgebiet berechnet werden.

## Einsatzgebiet bearbeiten

Der GeoJSON-Editor erlaubt Zeichnen, Ändern und Importieren des Polygons. Das Gebiet wird unter anderem bei der Ortswahl und der Darstellung der Nebenleitstelle verwendet.

## Wachen zuordnen

Nach dem ersten Speichern kann **Zuordnung der Wachen bearbeiten** geöffnet werden. Wachen können über die Nebenleitstelle indirekt zum Scope einer Hauptleitstelle gehören.

Diese indirekte Beziehung wird bei Benutzerrechten genauso berücksichtigt wie eine direkte Leitstellenzuordnung.

## Leitstelle als Vorlage übernehmen

Die Kopierfunktion kann Standort, Einsatzgebiet und Wachen einer vorhandenen Leitstelle übernehmen.

Nach dem Kopieren immer kontrollieren:

- Name und Zuständigkeit;
- Einwohner und Fläche;
- Standort und GeoJSON;
- übernommene Wachen und Fahrzeuge;
- Verknüpfung mit der vorgesehenen Hauptleitstelle;
- Kennzeichnung und Nutzung als Nachbarleitstelle.

## Verschieben und Mehrfachzuordnung

Gehört eine Nebenleitstelle zu mehreren Hauptleitstellen, benötigt ein Nicht-Administrator Zugriff auf alle betroffenen Leitstellen. Eine manipulierte Leitstellen-ID aus dem Browser ersetzt diese serverseitige Prüfung nicht.

## Häufige Fehler

| Problem | Prüfung |
|---|---|
| Wachen sind nicht sichtbar | Zuordnung und Rechte aller Hauptleitstellen prüfen |
| Fläche ist falsch | GeoJSON validieren und neu berechnen |
| Kopie enthält unerwartete Daten | übernommene Zuordnungen einzeln kontrollieren |
| Unterstützung wird nicht angeboten | Nachbarbeziehung in der Hauptleitstelle und Auslastung prüfen |
| Speichern wird verweigert | Bereichsrecht, Nonce und vollständigen Leitstellen-Scope prüfen |

---

[Wiki-Start](README.md) · [Nächster Artikel: Wachen-Editor](wachen-editor.md)
