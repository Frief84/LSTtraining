"""Strictly paginated WordPress management adapter; read-only unless selected writer."""
import os
from urllib.parse import urlparse
import requests
from common import COUNTRIES


class LstApi:
    def __init__(self, base=None, user=None, password=None, session=None, writable=False):
        base = (base or os.environ.get('LSTTRAINING_API_BASE_URL', '')).rstrip('/')
        if urlparse(base).scheme != 'https': raise ValueError('LSTTRAINING_API_BASE_URL muss HTTPS verwenden')
        self.base = base if base.endswith('/wp-json/lst/v1') else base + '/wp-json/lst/v1'
        self.session = session or requests.Session()
        self.session.auth = (user or os.environ.get('LSTTRAINING_API_USER', ''), ''.join((password or os.environ.get('LSTTRAINING_API_APP_PASSWORD', '')).split()))
        if not all(self.session.auth): raise ValueError('LST API-Zugangsdaten fehlen')
        self.writable, self.reads, self.writes = writable, 0, 0

    def request(self, path, method='GET', payload=None):
        import re
        if method != 'GET' and (not self.writable or path != '/abgleich/aktion'):
            raise ValueError('Schreibzugriff im Abgleich gesperrt')
        if method == 'GET': self.reads += 1
        else: self.writes += 1
        # Never retry a write or follow redirects with API credentials.
        response = self.session.request(method, self.base + path, json=payload, timeout=(10, 60), allow_redirects=False)
        response.raise_for_status()
        data = response.json()
        if data.get('ok') is not True: raise ValueError('API-Antwort nicht vollständig/erfolgreich')
        return data['data']

    def list(self, resource):
        rows, seen, expected, page = [], set(), None, 1
        while True:
            data = self.request(f'/verwaltung/{resource}?page={page}&per_page=200')
            p = data.get('pagination', {})
            try: total, pages, size, actual = (int(p[k]) for k in ('total', 'pages', 'per_page', 'page'))
            except (KeyError, ValueError, TypeError): raise ValueError('Pagination fehlt')
            items = data.get('items')
            if not isinstance(items, list) or total < 0 or size < 1 or pages != (total + size - 1)//size or actual != page:
                raise ValueError('Pagination ungültig')
            if expected is not None and total != expected: raise ValueError('Bestand hat sich während Pagination verändert')
            expected = total
            for row in items:
                ident = int(row['id'])
                if ident <= 0 or ident in seen: raise ValueError('Ungültige oder doppelte API-ID')
                seen.add(ident)
                rows.append(row)
            if page >= pages: break
            if not items or page > 10000: raise ValueError('Pagination abgebrochen')
            page += 1
        if len(rows) != expected: raise ValueError('API-Bestand unvollständig')
        return rows

    def detail(self, resource, ident):
        return self.request(f'/verwaltung/{resource}/{int(ident)}')

    def station_vehicles(self, ident):
        station = self.detail('wachen', ident)
        ids = station.get('relations', {}).get('fahrzeuge')
        if not isinstance(ids, list): raise ValueError('Fahrzeugzuordnung fehlt')
        rows = [self.detail('fahrzeuge', item) for item in ids]
        if any(int(r['wache_id']) != int(ident) for r in rows): raise ValueError('Wachenzuordnung während Abruf verändert')
        return rows

    def center_vehicles(self, ident):
        # This repository has no /api/leitstellen/{id}/fahrzeuge route.
        # Resolve its equivalent through the actual API, including secondary centers.
        center = self.detail('leitstellen', ident)
        stations = set(center['relations']['wachen'])
        for sub in center['relations']['nebenleitstellen']:
            stations.update(self.detail('nebenleitstellen', sub)['relations']['wachen'])
        return [v for v in self.list('fahrzeuge') if int(v['wache_id']) in set(map(int, stations))]

    def global_scope(self):
        status = self.request('/abgleich/status')
        if status.get('global_read') is not True or status.get('guarded_writes') is not True:
            raise ValueError('Globaler Bestand und abgesicherte Schreib-API müssen bestätigt sein')
        probe = self.request('/verwaltung/wachen?page=1&per_page=1')
        items = probe.get('items')
        pagination = probe.get('pagination', {})
        if not isinstance(items, list) or int(pagination.get('total', -1)) < 0 or (items and 'adresse' not in items[0]):
            raise ValueError('Wachen-Bestand oder Adressmigration nicht lesbar')
        return status
