"""Shared, deterministic data rules. No model or network side effects."""
import hashlib
import json
import os
import re
import unicodedata
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent
COUNTRIES = {'DE': 'Deutschland', 'AT': 'Österreich', 'CH': 'Schweiz'}
STATES = ['Baden-Württemberg', 'Bayern', 'Berlin', 'Brandenburg', 'Bremen', 'Hamburg', 'Hessen', 'Mecklenburg-Vorpommern', 'Niedersachsen', 'Nordrhein-Westfalen', 'Rheinland-Pfalz', 'Saarland', 'Sachsen', 'Sachsen-Anhalt', 'Schleswig-Holstein', 'Thüringen']
TECHNICAL = re.compile(r'^(?:way|node|relation)/\d+$', re.I)
HISTORICAL = re.compile(r'\ba\s*\.\s*d\s*\.|au(?:ß|ss)er\s+dienst|\behem\.|\behemalig\w*|\bhistorisch\w*|\bmuseum\b|\boldtimer\b|\binaktiv\b|\bverkauft\b', re.I)


def now():
    return datetime.now(timezone.utc).isoformat()


def norm(value):
    value = unicodedata.normalize('NFD', str(value or '').casefold())
    return re.sub(r'[^a-z0-9]+', ' ', ''.join(c for c in value if not unicodedata.combining(c))).strip()


def key(value):
    return norm(value).replace(' ', '')


def slug(value):
    for a, b in [('ä', 'ae'), ('ö', 'oe'), ('ü', 'ue'), ('Ä', 'Ae'), ('Ö', 'Oe'), ('Ü', 'Ue'), ('ß', 'ss')]:
        value = value.replace(a, b)
    return re.sub(r'[^A-Za-z0-9_-]', '-', value)


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest()


def seal(entry):
    content = {k: v for k, v in entry.items() if k != 'freigabe_id'}
    return dict(content, freigabe_id=digest(content))


def valid_seal(entry):
    return bool(entry.get('freigabe_id')) and seal(entry)['freigabe_id'] == entry['freigabe_id']


def save_json(path, data):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + '.tmp')
    temporary.write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    temporary.replace(path)


def load_env(path):
    if Path(path).exists():
        for line in Path(path).read_text(encoding='utf-8-sig').splitlines():
            match = re.match(r'^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$', line)
            if match:
                os.environ.setdefault(match[1], match[2].strip('\"\''))


def station_type(row):
    value = norm(row.get('typ'))
    if 'technisch' in value or value == 'ftz': return 'FTZ'
    if 'berufs' in value or value in ('bf', 'fw'): return 'BF'
    if 'werk' in value or value == 'wf': return 'WF'
    if value in ('rd', 'frrd', 'seg', 'air') or 'rettung' in value: return 'RD'
    return 'FF'


def coordinates(row):
    try:
        lat, lon = float(row['latitude']), float(row['longitude'])
        if -90 <= lat <= 90 and -180 <= lon <= 180 and (lat or lon): return lat, lon
    except (ValueError, TypeError, KeyError):
        pass
    return None
