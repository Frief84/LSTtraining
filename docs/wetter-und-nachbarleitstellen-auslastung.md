# Wetter und Nachbarleitstellen-Auslastung

## Wetter-Forecast

Beim Start einer Simulation wird fuer die gewaehlte Leitstelle eine stündliche Wettervorhersage erzeugt. Wenn die Leitstelle gueltige Koordinaten hat und Open-Meteo erreichbar ist, wird die Forecast-API genutzt. Andernfalls erzeugt LST Training eine interne statistische Vorhersage aus Startdatum, Uhrzeit, Jahreszeit und Zufall.

Die geladenen Forecast-Werte werden an die gewaehlte Simulations-Startzeit gelegt. Dadurch kann die Spieluhr frei gewaehlt werden, waehrend sich das Wetter trotzdem stündlich mit der Simulation weiterentwickelt.

Die Vorhersage wird in `spielinstanzen.settings_json` gespeichert:

- `weather_forecast`: Quelle, Erfassungszeitpunkt und stündliche Wetterpunkte
- `weather_snapshot`: Wetterlage zum Simulationsstart
- `weather`: bestehender Wert, bleibt fuer manuelle Overrides und Kompatibilitaet erhalten

Waehrend der Simulation wird keine Wetter-API erneut abgefragt. Die gespeicherte Vorhersage ist die Wetterwahrheit der laufenden Instanz.

Wenn `weather = auto` gesetzt ist, folgt die Simulation dem gespeicherten Forecast. Wird beim Start ein konkreter Wettertyp gewaehlt, bleibt diese manuelle Vorgabe kompatibel erhalten und wird als statistische Kurve mit diesem Wettertyp abgebildet.

## Wettertypen

Die Simulation nutzt nur die bestehenden Wettertypen aus der Einsatzverwaltung:

- `clear`
- `cloudy`
- `rain`
- `snow`
- `storm`
- `windy`
- `fog`
- `cold`
- `hot`

Jeder stündliche Wetterpunkt hat einen dominanten Typ (`primary`) und mehrere Tags (`tags`). Beispiel: Regen mit Wind und niedriger Temperatur kann `primary = rain` und `tags = [rain, windy, cold]` haben.

Die Priorität fuer den dominanten Typ lautet:

`storm > snow > fog > rain > windy > hot/cold > cloudy > clear`

## Wirkung auf die Einsatzlage

Das aktuelle Forecast-Wetter wird aus der Simulationszeit bestimmt. Pause friert den Wetterzustand ein; Zeitraffer laesst Wetterwechsel entsprechend schneller eintreten.

Ist das Ende der gespeicherten Forecast-Stunden erreicht, wird die letzte Lage statistisch fortgeschrieben. Dadurch bleibt die Simulation auch bei langen Laufzeiten wetterdynamisch, ohne die API erneut abzufragen.

Wetter wirkt moderat:

- Regen beguenstigt RD-, Verkehrs- und THL-Lagen.
- Schnee und Kaelte beguenstigen Sturz-, Verkehrs- und internistische Lagen.
- Sturm und Wind beguenstigen Feuerwehr-/THL-Lagen und binden Sonderfahrzeuge staerker.
- Nebel beguenstigt Verkehrslagen.
- Hitze beguenstigt RD-, Vegetations- und Freizeitlagen.

Der Wetterfaktor ist absichtlich gedeckelt, damit schlechtes Wetter die Simulation spuerbar, aber nicht katastrophal beeinflusst.

## Nachbarleitstellen-Auslastung

Fahrzeuge von Nachbarleitstellen werden nicht direkt disponiert. Bei einer Anfrage berechnet die Simulation eine Momentaufnahme der Nachbarleitstelle.

Die Verfügbarkeit entsteht aus:

- Einwohnerzahl der Nebenleitstelle
- Flaeche der Nebenleitstelle
- Bundeslandstatistik aus `data/einsatzbelastung-bundeslaender.json`
- Simulationszeit und Tagesgang
- Jahreszeit
- aktuellem Forecast-Wetter
- Fahrzeugtyp bzw. Ressourcenklasse

Einwohnerzahl bestimmt vor allem die Grundlast. Flaeche erhoeht die angenommene Bindungsdauer. Wetter und Tageszeit verschieben die Wahrscheinlichkeit, ob Fahrzeuge im Heimatbereich gebunden sind.

Bereits angeforderte Fremdfahrzeuge ueberschreiben die Statistik. Sie bleiben nicht verfuegbar, bis sie aus dem Fremdeinsatz zur Heimatleitstelle zurueckkehren.

## Nachbarangebot

Ein Nachbarangebot ist eine Momentaufnahme. Es bleibt im aktuell geöffneten Dialog stabil und gueltig, bis der Spieler den Dialog schliesst.

Beim Schliessen wird der lokale Angebotsentwurf verworfen. Wird der Dialog erneut geöffnet und eine neue Anfrage gestellt, berechnet die Nachbarleitstelle ein neues Angebot aus aktueller Simulationszeit, aktuellem Wetter und aktueller statistischer Heimatlage.

Gespeicherte Anfrage-Events bleiben im Einsatzprotokoll erhalten, werden aber nicht automatisch als neues Dialogangebot wiederverwendet.

## Fallbacks

Wenn Open-Meteo nicht erreichbar ist oder eine Leitstelle keine nutzbaren Koordinaten besitzt, startet die Simulation trotzdem. In diesem Fall wird eine plausible statistische Wetterkurve erzeugt.

Alte Instanzen ohne `weather_forecast` erhalten beim Laden einen berechneten Wetterpunkt aus Startzeit, Jahreszeit und dem bestehenden `weather`-Wert.
