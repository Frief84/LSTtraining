"""Append-only log: technical names are never deleted."""
import json
from pathlib import Path
from common import now


def log_way(path, station, address=None, source=None, resolved=False):
    Path(path).parent.mkdir(parents=True, exist_ok=True)
    entry = {'timestamp': now(), 'wache_id': station.get('id'), 'technischer_name': station.get('name'), 'lat': station.get('latitude'), 'lon': station.get('longitude'), 'reverseGeocode Adresse': address, 'quelle_url': source, 'status': 'aufgelöst' if resolved else 'ungeklärt'}
    with Path(path).open('a', encoding='utf-8') as handle:
        handle.write(json.dumps(entry, ensure_ascii=False)+'\n')
