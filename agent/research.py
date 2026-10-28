"""Cached public GETs, two search providers and address resolution."""
import json
import re
import time
from pathlib import Path
from urllib.parse import urlparse, urljoin, parse_qs
import requests
from bs4 import BeautifulSoup
from common import digest, save_json


class SearchBlocked(ValueError):
    """A search provider requires interactive verification."""


def ddg_challenge(url, html):
    return (urlparse(url).hostname == 'lite.duckduckgo.com'
            and any(marker in html.lower() for marker in ('captcha', 'anomaly', 'bot challenge'))
            and not BeautifulSoup(html, 'html.parser').select('a.result-link'))


class PublicWeb:
    def __init__(self, cache, session=None, delay=1.2, offline=False):
        self.cache, self.session, self.delay, self.offline = Path(cache), session or requests.Session(), delay, offline
        self.last, self.errors = 0, []
        self.blocked_search_providers = {}
        self.session.headers.update({'User-Agent': 'LSTtraining research agent (contact: https://frief.de/)'})

    def get(self, url, fresh=False):
        if urlparse(url).scheme not in ('http', 'https'): raise ValueError('Ungültige Quellen-URL')
        path = self.cache/(digest(url)+'.json')
        if path.exists() and (self.offline or not fresh and time.time()-path.stat().st_mtime < 86400):
            cached = json.loads(path.read_text(encoding='utf-8'))['text']
            if not ddg_challenge(url, cached):
                return cached
            if self.offline:
                raise SearchBlocked('DuckDuckGo: gespeicherte Browserprüfung; keine Suchergebnisse')
            # A cached challenge must not prevent recovery in a new online run.
        if self.offline: raise ValueError('Quelle fehlt im Offline-Cache')
        for attempt in range(3):
            time.sleep(max(0, self.delay-(time.monotonic()-self.last)))
            self.last = time.monotonic()
            try:
                response = self.session.get(url, timeout=(10, 30), stream=True)
                response.raise_for_status()
                chunks, total = [], 0
                for chunk in response.iter_content(65536):
                    total += len(chunk)
                    if total > 3_000_000: raise ValueError('Quelle größer als 3 MB; separate Auswertung erforderlich')
                    chunks.append(chunk)
                content = b''.join(chunks)
                if 'pdf' in response.headers.get('Content-Type', ''): raise ValueError('PDF-Quelle benötigt separate Extraktion')
                text = content.decode(response.encoding if response.encoding and response.encoding.lower() != 'iso-8859-1' else 'utf-8', errors='replace')
                if ddg_challenge(url, text):
                    raise SearchBlocked('DuckDuckGo verlangt eine Browserprüfung; automatische Suche blockiert')
                save_json(path, {'url': url, 'final_url': response.url, 'text': text})
                return text
            except requests.RequestException as exc:
                status = getattr(getattr(exc, 'response', None), 'status_code', None)
                if attempt == 2 or status and status not in (408, 425, 429) and status < 500: raise
                time.sleep(attempt+1)
        raise RuntimeError('Quelle nicht abrufbar')

    def search(self, query):
        from urllib.parse import urlencode
        found, failures = [], []
        endpoints = [('bing', 'https://www.bing.com/search?'+urlencode({'q': query, 'format': 'rss'})), ('ddg', 'https://lite.duckduckgo.com/lite/?'+urlencode({'q': query}))]
        for name, url in endpoints:
            if name in self.blocked_search_providers:
                failures.append(dict(self.blocked_search_providers[name], skipped=True))
                continue
            try:
                html = self.get(url)
                if name == 'bing':
                    import xml.etree.ElementTree as ET
                    for item in ET.fromstring(html).findall('.//item'):
                        found.append({'url': item.findtext('link', ''), 'snippet': item.findtext('description', ''), 'title': item.findtext('title', '')})
                else:
                    soup = BeautifulSoup(html, 'html.parser')
                    for a in soup.select('a.result-link'):
                        href = urljoin(url, a.get('href', ''))
                        href = parse_qs(urlparse(href).query).get('uddg', [href])[0]
                        found.append({'url': href, 'snippet': '', 'title': a.get_text(' ', strip=True)})
                    if not soup.select('a.result-link') and any(x in html.lower() for x in ('captcha', 'anomaly', 'bot challenge')): raise SearchBlocked('DuckDuckGo verlangt eine Browserprüfung; automatische Suche blockiert')
            except Exception as exc:
                status = getattr(getattr(exc, 'response', None), 'status_code', None)
                blocked = isinstance(exc, SearchBlocked) or status in (403, 429)
                failure = {'provider': name, 'error': str(exc)[:200],
                           'code': 'provider_blocked' if blocked else 'search_failed'}
                if blocked:
                    failure['action'] = 'Für den restlichen Lauf übersprungen; andere Suchanbieter und Quellen bleiben verfügbar'
                    self.blocked_search_providers[name] = failure
                failures.append(failure)
        unique = {x['url']: x for x in found if urlparse(x['url']).scheme in ('http', 'https')}
        from common import norm
        stop={'feuerwehr','fahrzeuge','funkrufname','freiwillige','rettungsdienst','site','www','info','de','com'}
        terms={w for w in norm(query).split() if len(w)>2 and w not in stop}
        ranked=sorted(unique.values(),key=lambda h:sum(w in norm(h['url']+' '+h['title']+' '+h['snippet']) for w in terms),reverse=True)
        return ranked[:10], failures

    def reverse(self, lat, lon):
        from urllib.parse import urlencode
        url = 'https://nominatim.openstreetmap.org/reverse?'+urlencode({'format': 'jsonv2', 'lat': lat, 'lon': lon, 'addressdetails': 1, 'accept-language': 'de'})
        data = json.loads(self.get(url))
        if not data.get('address'): raise ValueError('Reverse-Geocoding ohne Adresse')
        a = data['address']
        return {'adresse': ', '.join(filter(None, [(' '.join(filter(None, [a.get('road'), a.get('house_number')]))), ' '.join(filter(None, [a.get('postcode'), a.get('city') or a.get('town') or a.get('village')]))])), 'state': a.get('state', ''), 'region': a.get('county', ''), 'town': a.get('city') or a.get('town') or a.get('village', ''), 'country': a.get('country_code', '').upper(), 'url': url}


    def forward(self, address, country):
        from urllib.parse import urlencode
        url='https://nominatim.openstreetmap.org/search?'+urlencode({'format':'jsonv2','q':address,'countrycodes':country.lower(),'addressdetails':1,'limit':2,'accept-language':'de'})
        data=json.loads(self.get(url))
        if not isinstance(data,list) or len(data)!=1:raise ValueError('Adresse nicht eindeutig geokodierbar')
        row=data[0]
        if row.get('address',{}).get('country_code','').upper()!=country:raise ValueError('Geokodierung in anderem Land')
        return {'lat':float(row['lat']),'lon':float(row['lon']),'source_url':url}
