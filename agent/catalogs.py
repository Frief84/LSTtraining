"""Import attached catalogs as untrusted research hints, retaining origin and hashes."""
import csv
import io
import json
import re
from pathlib import Path
from urllib.parse import urlparse
from common import ROOT, STATES, digest, key, norm, station_type

URL_RE = re.compile(r'https?://[^\s<>"\[\]]+')


def urls(row):
    found = []
    for field in ('quelle', 'quelle_url', 'source_url'):
        if isinstance(row.get(field), str): found += URL_RE.findall(row[field])
    for extra in row.get('quellen_zusatz', []):
        if isinstance(extra, dict): found += URL_RE.findall(str(extra.get('url', '')))
    return list(dict.fromkeys(u.rstrip('.,);') for u in found))


def load_catalogs(directory=None):
    rows, provenance = {}, []
    for path in sorted(Path(directory or ROOT/'catalogs').glob('*.json')):
        data = json.loads(path.read_text(encoding='utf-8-sig'))
        provenance.append({'file': path.name, 'sha256': digest(data)})
        for country in ('DE', 'AT', 'CH'):
            for state, regions in data.get(country, {}).items():
                if not isinstance(regions, dict): continue
                for region, towns in regions.items():
                    if not isinstance(towns, dict): continue
                    for town, stations in towns.items():
                        if not isinstance(stations, list): continue
                        for item in stations:
                            if not isinstance(item, dict) or not item.get('name'): continue
                            ident = (country, key(state), key(region), key(town), key(item['name']))
                            row = rows.setdefault(ident, {'country': country, 'state': state, 'region': region, 'town': town, 'name': item['name'], 'adresse': item.get('adresse'), 'typ': station_type(item), 'organisation': item.get('organisation'), 'urls': [], 'origins': [], 'hints': [], 'coordinates': item.get('koordinaten')})
                            row['urls'] = list(dict.fromkeys(row['urls'] + urls(item)))
                            row['origins'].append(path.name)
                            # Claims remain hints; never feed them directly into active candidates.
                            row['hints'].extend(item.get('fahrzeuge', []))
    return list(rows.values()), provenance


def sheet_states(http, url):
    text = http.get(url)
    if '<html' in text[:500].lower(): raise ValueError('Google-Tabelle nicht öffentlich als CSV lesbar')
    cells = {norm(cell) for row in csv.reader(io.StringIO(text)) for cell in row}
    matched = [state for state in STATES if norm(state) in cells]
    if not matched: raise ValueError('Keine Bundesländer in Google-Tabelle erkannt')
    return matched


def source_kind(url):
    host = (urlparse(url).hostname or '').lower()
    if host == 'bos-fahrzeuge.info' or host.endswith('.bos-fahrzeuge.info'): return 'bos'
    if host == 'lstsim.de' or host.endswith('.lstsim.de'): return 'lstsim'
    return 'operator_candidate'
