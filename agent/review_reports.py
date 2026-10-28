#!/usr/bin/env python3
"""Build a human review index from completed local reports; never calls the API."""
import argparse
import csv
import json
from collections import defaultdict
from pathlib import Path

from common import key, valid_seal


def read_json(path):
    return json.loads(path.read_text(encoding='utf-8-sig'))


def review(directory):
    directory = Path(directory)
    scope = read_json(directory / 'leitstellen-umfang.json')
    reports = sorted(directory.glob('DELTA_*.json'))
    entries, states, errors = [], [], []
    for path in reports:
        state = path.stem.removeprefix('DELTA_')
        report = read_json(path)
        status = read_json(directory / (state + '-status.json'))
        if report.get('status') == 'running' or status.get('status') == 'running':
            raise ValueError(f'{state}: Recherche läuft noch; Freigabeübersicht nicht abschließen')
        rows = report.get('entries')
        if not isinstance(rows, list):
            raise ValueError(f'{state}: Änderungsvorschläge fehlen')
        states.append((state, status, rows))
        for item in rows:
            entries.append((state, path.name, item))
        for station in status.get('stations', []):
            if station.get('status') not in ('processed', 'outside'):
                errors.append((state, station))

    by_callsign = defaultdict(list)
    for state, file_name, item in entries:
        callsign = (item.get('after') or {}).get('callsign')
        if callsign:
            by_callsign[key(callsign)].append(item)
    conflicting = {callsign for callsign, rows in by_callsign.items()
                   if len({str(row.get('wache_id')) for row in rows}) > 1}

    columns = ['bundesland', 'datei', 'aktion', 'wache_id', 'wache', 'rufname', 'typ',
               'technisch_schreibfaehig', 'pruefhinweis', 'quelle', 'freigabe_id', 'beleg_auszug']
    output = directory / 'freigabe-uebersicht.csv'
    with output.open('w', encoding='utf-8-sig', newline='') as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, delimiter=';')
        writer.writeheader()
        for state, file_name, item in entries:
            after = item.get('after') or {}
            callsign = after.get('callsign') or ''
            notes = []
            if not valid_seal(item):
                notes.append('Freigabe-ID passt nicht zum Eintrag')
            if not item.get('schreibfaehig'):
                notes.append('Agent sperrt Schreiben')
            if callsign and key(callsign) in conflicting:
                notes.append('Rufname an mehreren Report-Wachen')
            writer.writerow({
                'bundesland': state, 'datei': file_name, 'aktion': item.get('action'),
                'wache_id': item.get('wache_id'), 'wache': item.get('station_name') or (item.get('station_before') or {}).get('name'),
                'rufname': callsign, 'typ': after.get('type') or '',
                'technisch_schreibfaehig': 'ja' if item.get('schreibfaehig') and valid_seal(item) else 'nein',
                'pruefhinweis': '; '.join(notes), 'quelle': item.get('quelle'),
                'freigabe_id': item.get('freigabe_id'), 'beleg_auszug': ' '.join(str(item.get('beleg') or '').split())[:300],
            })

    total = scope.get('station_count', 0)
    researched = sum(status.get('summary', {}).get('api_stations_researched', 0) for _, status, _ in states)
    proposals = len(entries)
    writable = sum(bool(item.get('schreibfaehig')) and valid_seal(item) for _, _, item in entries)
    catalog = scope.get('catalog_scope') or {}
    catalog_geo = sum(row.get('reason') != 'Mehrdeutige API-Wache' for row in catalog.get('unresolved', []))
    api_geo = sum(row.get('stage') in ('geography', 'prepare') for _, row in errors)
    inventory = sum(row.get('stage') == 'inventory' for _, row in errors)
    rows = [
        '# Prüfung des Leitstellenlaufs', '',
        f"Leitstelle: {scope.get('name')} (ID {scope.get('leitstelle_id')}); Laufstatus: {scope.get('status')}.", '',
        '| Ergebnis | Anzahl |', '| --- | ---: |',
        f'| Zugeordnete API-Wachen | {total} |',
        f'| Bis zur Recherche gelangt | {researched} |',
        f'| Vor Recherche abgebrochen | {total - researched} |',
        f'| Geografisch ungeklärte Kataloghinweise | {catalog_geo} |',
        f'| Geografisch ungeklärte API-Wachen | {api_geo} |',
        f'| Abruffehler beim Fahrzeugbestand | {inventory} |',
        f'| Änderungsvorschläge | {proposals} |',
        f'| Davon technisch schreibfähig | {writable} |',
        f"| API-Schreibaktionen im Recherchelauf | {scope.get('api_writes', 0)} |", '',
        'Technisch schreibfähig bedeutet keine Freigabe. Vor jedem Schreiben Quelle, Rufname, Typ und Heimatwache prüfen. '
        'Der Schreibweg lädt den aktuellen Bestand erneut; inzwischen veränderte Einträge können deshalb ausfallen.', '',
        '## Vorschläge nach Bundesland', '',
        '| Bundesland | Vorschläge | Technisch schreibfähig |', '| --- | ---: | ---: |',
    ]
    for state, _, items in states:
        rows.append(f"| {state} | {len(items)} | {sum(bool(item.get('schreibfaehig')) and valid_seal(item) for item in items)} |")
    rows += ['', '## Vor der Recherche abgebrochene Wachen', '',
             '| Bundesland | Wachen-ID | Name | Schritt | Grund |', '| --- | ---: | --- | --- | --- |']
    for state, station in errors:
        def clean(value):
            return str(value or '').replace('|', '\\|').replace('\n', ' ')
        rows.append(f"| {clean(state)} | {station.get('wache_id') or ''} | {clean(station.get('name'))} | {clean(station.get('stage'))} | {clean(station.get('reason'))} |")
    rows += ['', f'Alle {proposals} Vorschläge mit Datei, Quelle und Freigabe-ID stehen in `freigabe-uebersicht.csv`.',
             'Die Recherche hat den Fuhrpark keiner Wache als vollständig bestätigt.', '']
    (directory / 'lauf-pruefung.md').write_text('\n'.join(rows), encoding='utf-8')
    return {'stations': total, 'researched': researched, 'errors': len(errors),
            'proposals': proposals, 'technically_writable': writable,
            'catalog_geography_unresolved': catalog_geo, 'api_geography_unresolved': api_geo,
            'inventory_errors': inventory}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    args = parser.parse_args()
    print(json.dumps(review(args.directory), ensure_ascii=False, indent=2))
