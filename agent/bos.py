"""BOS-first discovery through the public station directory, with strict detail gates."""
import re
from urllib.parse import quote, urljoin, urlparse
from bs4 import BeautifulSoup
from common import HISTORICAL, norm
from extractor import callsigns, extract
from rufnamen_mapper import prefixes

ORIGIN = 'https://bos-fahrzeuge.info'
COUNTRIES = {'DE': 'Deutschland', 'AT': 'Österreich', 'CH': 'Schweiz'}
ORGANIZATIONS = {'FF': 'Freiwillige Feuerwehr', 'BF': 'Berufsfeuerwehr',
                 'WF': 'Werkfeuerwehr', 'DRK': 'Deutsches Rotes Kreuz',
                 'ASB': 'Arbeiter-Samariter', 'JUH': 'Johanniter', 'MHD': 'Malteser'}


def fields(html):
    soup = BeautifulSoup(html, 'html.parser')
    table = soup.select_one('table.summary')
    result = {}
    for th in (table or soup).select('th'):
        td = th.find_next_sibling('td')
        if td:
            result[norm(th.get_text(' ', strip=True))] = td
    for td in (table or soup).select('td'):
        match = re.match(r'^(Standort|Organisation):\s*(.+)', td.get_text(' ', strip=True))
        if match:
            # Listing cards use labelled cells rather than the detail table's th/td pairs.
            value = BeautifulSoup('<span></span>', 'html.parser').span
            value.string = match[2]
            result.setdefault(norm(match[1]), value)
    return soup, result


def station_id(url):
    parsed = urlparse(urljoin(ORIGIN, url))
    if parsed.hostname not in ('bos-fahrzeuge.info', 'www.bos-fahrzeuge.info'):
        return None
    match = re.match(r'^/wachen/(\d+)(?:/|$)', parsed.path)
    return match[1] if match else None


def station_name(value):
    value = re.sub(r'^\s*\[[^]]+\]\s*', '', value)
    value = norm(value.replace('ß', 'ss'))
    for phrase, short in [('freiwillige feuerwehr', 'ff'), ('berufsfeuerwehr', 'bf'),
                          ('feuer und rettungswache', 'furw'), ('frw', 'furw')]:
        value = re.sub(r'\b' + phrase + r'\b', short, value)
    return value


def region(value):
    value = norm(value)
    value = re.sub(r'^(landkreis|kreis|bezirk|kanton) ', '', value)
    return re.sub(r' (kreisfrei|kreisfreie stadt)$', '', value)


def scope_ok(html, station):
    _, data = fields(html)
    location = data.get('ort') or data.get('standort')
    organization = data.get('organisation')
    expected_org = ORGANIZATIONS.get(station.get('organisation'))
    if location is None or organization is None or not expected_org:
        return False
    # Compare hierarchy components, not loose substrings (Potsdam != Potsdam-Mittelmark).
    expected_country = norm(COUNTRIES.get(station.get('country'), ''))
    expected_state, expected_region = norm(station.get('state')), region(station.get('region', ''))
    if not all((expected_country, expected_state, expected_region)):
        return False
    # Parenthetical country translations / district codes are discarded before normalization.
    parts = [norm(re.sub(r'\([^)]*\)', '', x)) for x in re.split(r'[›»>]', location.get_text(' ', strip=True))]
    return (expected_country in parts and expected_state in parts
            and expected_region in [region(p) for p in parts]
            and norm(expected_org) in norm(organization.get_text(' ', strip=True)))


def station_identity(html, station):
    soup, data = fields(html)
    address = data.get('adresse')
    expected = norm(str(station.get('adresse') or '').replace('ß', 'ss'))
    if address is None or not expected:
        return False
    actual = norm(address.get_text(' ', strip=True).replace('ß', 'ss'))
    # Reverse geocoding sometimes returns the street without a house number.
    # Never ignore a supplied, conflicting house number. A missing number needs
    # an exact normalized station name in addition to street, postcode and town.
    house = r'\s+\d+[a-z]?(?:\s+\d+[a-z]?)?\s+(?=\d{4,5}\b)'
    missing_house = expected != actual and re.sub(house, ' ', expected) == expected and re.sub(house, ' ', actual) == expected
    if expected != actual and not missing_house:
        return False
    title = station_name(' '.join(n.get_text(' ', strip=True) for n in soup.select('h1')))
    names = [station['name']] + station.get('aliases', [])
    # Full normalized name, or all meaningful name tokens plus the exact address.
    if missing_house:
        title_tokens = set(title.split()) - {'wache', 'wachen'}
        town_tokens = set(station_name(station.get('town', '')).split())
        return any(set(station_name(name).split()) | town_tokens == title_tokens for name in names if station_name(name))
    return any(set(station_name(name).split()) <= set(title.split()) for name in names if station_name(name))


def detail_home(html, listing_url):
    _, data = fields(html)
    home = data.get('wache')
    ids = {station_id(a['href']) for a in home.select('a[href]')} if home else set()
    return ids == {station_id(listing_url)} and None not in ids


def research_bos(web, station, allowed, max_pages=8, samples=()):
    audit, active = [], []
    pages = 0
    def record(url, message, usable=False):
        audit.append({'url':url, 'typ':'bos', 'befund':message, 'auswertbar':usable})
    def get(url):
        nonlocal pages
        if pages >= max_pages:
            raise ValueError('BOS-Abruflimit erreicht; Recherche unvollständig')
        pages += 1
        return web.get(url)
    if not station.get('town') or not station.get('adresse') or not ORGANIZATIONS.get(station.get('organisation')):
        record(None, 'BOS-Zuordnung ungeklärt: Ort, Adresse oder Organisation fehlt')
        return active, audit
    try:
        # Shared town search is cached across stations. No external search engine is needed.
        search_url = ORIGIN + '/wachen/suche/' + quote(station['town'], safe='')
        search_html = get(search_url)
        soup = BeautifulSoup(search_html, 'html.parser')
        if 'wachen' not in norm(soup.get_text(' ', strip=True)):
            raise ValueError('BOS-Wachensuche nicht auswertbar')
        names = set(station_name(station['name']).split()) - {'ff', 'bf', 'fw', 'feuerwehr'}
        candidates = {}
        for a in soup.select('a[href]'):
            link = urljoin(ORIGIN, a['href'])
            if station_id(link) and a.get_text(' ', strip=True):
                candidates[link] = len(names & set(station_name(a.get_text(' ', strip=True)).split()))
        for url in station.get('urls', []):
            if station_id(url):
                candidates[url] = 100
        # Follow bounded search pagination if the first page has no full name match.
        def has_name_match():
            return any(set(station_name(station['name']).split()) <= set(station_name(a.get_text(' ', strip=True)).split())
                       for a in soup.select('a[href]') if station_id(a['href']))
        if not has_name_match():
            next_pages = [urljoin(ORIGIN, a['href']) for a in soup.select('a[href]')
                          if urljoin(ORIGIN, a['href']).startswith(search_url + '/page/')]
            for next_url in list(dict.fromkeys(next_pages))[:2]:
                if pages >= max_pages - 3:
                    break
                soup = BeautifulSoup(get(next_url), 'html.parser')
                for a in soup.select('a[href]'):
                    link = urljoin(ORIGIN, a['href'])
                    if station_id(link) and a.get_text(' ', strip=True):
                        candidates[link] = len(names & set(station_name(a.get_text(' ', strip=True)).split()))
                if has_name_match():
                    break
        ranked = sorted(candidates, key=lambda u: candidates[u], reverse=True)
        if not ranked:
            record(search_url, 'Keine BOS-Wache gefunden')
        matched = None
        for listing_url in ranked:
            html = get(listing_url)
            if not scope_ok(html, station):
                record(listing_url, 'Region/Organisation nicht passend oder unbekannt; Details gesperrt')
                continue
            if not station_identity(html, station):
                record(listing_url, 'Wachenname/Adresse nicht eindeutig passend; Details gesperrt')
                continue
            matched = listing_url
            record(listing_url, 'BOS-Wache über Gebiet, Organisation, Name und Adresse bestätigt')
            listing = BeautifulSoup(html, 'html.parser')
            fleet = next((urljoin(ORIGIN, a['href']) for a in listing.select('a[href]')
                          if station_id(a['href']) == station_id(listing_url)
                          and urlparse(a['href']).path.endswith('/einsatzfahrzeuge')), None)
            if not fleet:
                record(listing_url, 'Keine Fahrzeugübersicht verlinkt')
                break
            fleet_html = BeautifulSoup(get(fleet), 'html.parser')
            container = fleet_html.select_one('#vehicles-list')
            if container is None:
                record(fleet, 'Fahrzeugübersicht nicht auswertbar')
                break
            detail_urls = []
            for card in container.select('table'):
                heading = card.find_previous(class_='list-separator')
                context = heading.get_text(' ', strip=True) if heading else ''
                text = card.get_text(' ', strip=True)
                if HISTORICAL.search(context + ' ' + text):
                    continue
                if not scope_ok(str(card), station):
                    continue
                if not callsigns(text, prefixes(station['state'], station['region'], samples)):
                    continue
                link = next((urljoin(ORIGIN, a['href']) for a in card.select('a[href]')
                             if re.match(r'^/einsatzfahrzeuge/\d+/', urlparse(a['href']).path)), None)
                if link:
                    detail_urls.append(link.rstrip('/') + '/tab/detail')
            for detail in dict.fromkeys(detail_urls):
                detail_html = get(detail)
                if not scope_ok(detail_html, station) or not detail_home(detail_html, listing_url):
                    record(detail, 'Fahrzeugzuordnung widersprüchlich; kein Kandidat')
                    continue
                source = {'url':detail,'kind':'bos','authority':'secondary','verified':True,'home_verified':True}
                found, _ = extract(detail_html, station, source, allowed,
                                   prefixes(station['state'], station['region'], samples))
                active.extend(found)
                record(detail, 'Aktives Fahrzeug regelbasiert belegt' if found else 'Kein eindeutig aktiver Fahrzeugbeleg', bool(found))
            break
        if not matched and ranked:
            record(search_url, 'Keine eindeutig zugeordnete BOS-Wache')
    except Exception as exc:
        record(None, str(exc)[:250])
    return active, audit
