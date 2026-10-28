"""Regional discovery, pre-fetch BOS gates and evidence extraction."""
import re
from urllib.parse import urljoin, urlparse
from bs4 import BeautifulSoup
from catalogs import source_kind
from common import norm, station_type
from evaluator import bos_prefilter, official_page, page_identity, region_key, resolve_technical_identity
from extractor import blocks, extract, llm_fallback
from rufnamen_mapper import prefixes, mapping_record


def breadcrumb_metadata(html, url, station):
    soup=BeautifulSoup(html,'html.parser')
    nodes=soup.select('[class*="breadcrumb"], [aria-label*="readcrumb"], [itemtype*="BreadcrumbList"]')
    text=' '.join(n.get_text(' ',strip=True) for n in nodes)
    page=soup.get_text(' ',strip=True)
    organization=station.get('organisation')
    org_markers={'FF':['freiwillige feuerwehr'],'BF':['berufsfeuerwehr'],'WF':['werkfeuerwehr'],'DRK':['deutsches rotes kreuz','drk'],'ASB':['arbeiter samariter','asb'],'JUH':['johanniter'],'MHD':['malteser']}
    valid=norm(station['state']) in norm(text) and region_key(station['region']) in norm(text) and any(norm(x) in norm(page) for x in org_markers.get(organization,[]))
    return dict(station,breadcrumb_verified=bool(valid),breadcrumb_url=url,organisation=organization)



def relevant_hit(hit, station):
    text=norm(hit.get('title','')+' '+hit.get('snippet','')+' '+hit.get('url',''))
    places=[station.get('town'),region_key(station.get('region',''))]
    return any(place and norm(place) in text for place in places)



def usable_source_url(url):
    if not isinstance(url,str) or re.search(r'\s|\[|\]|STRIPPED',url,re.I):return False
    try:
        parsed=urlparse(url)
        return parsed.scheme in ('http','https') and bool(parsed.hostname) and not parsed.username and not parsed.password
    except ValueError:
        return False


def registry_source_matches(source,station):
    if source.get('enabled') is False or not usable_source_url(source.get('url')):return False
    if source.get('country')!=station['country'] or not source.get('state') or norm(source['state'])!=norm(station['state']):return False
    if station_type(station) not in source.get('station_types',[]):return False
    if source.get('role')=='operator_discovery':
        # These are municipal entry points, never sources for an entire Bundesland.
        return bool(station.get('town')) and any(norm(t)==norm(station['town']) for t in source.get('towns',[]))
    return bool(station.get('region')) and any(r and region_key(r)==region_key(station['region']) for r in source.get('regions',[]))


def discovery_links(html,url,station):
    links=[]
    for a in BeautifulSoup(html,'html.parser').select('a[href]'):
        link=urljoin(url,a['href'])
        if not usable_source_url(link) or urlparse(link).hostname!=urlparse(url).hostname:continue
        label=norm(a.get_text(' ',strip=True)+' '+a['href'])
        if not re.search(r'fahrzeug|fuhrpark|standort|feuerwache|rettungswache',label):continue
        names=[station['name']]+station.get('aliases',[])
        targeted=any(norm(n) in label for n in names if n)
        links.append((not targeted,link))
    return list(dict.fromkeys(link for _,link in sorted(links,key=lambda item:item[0])))[:5]


def _research_fallback(web, station, allowed, extra_sources=(), approved_domains=(), use_llm=True, max_pages=8, learned_samples=()):
    findings,retired,audit,samples=[],[],[],list(learned_samples)[:5]
    def record_search_errors(errors):
        for error in errors:
            message = error.get('error', str(error))
            provider = error.get('provider')
            code = error.get('code', 'search_failed')
            existing = next((row for row in audit if row['typ']=='search'
                             and row.get('provider')==provider and row.get('code')==code
                             and row['befund']==message), None)
            if existing:
                existing['occurrences'] += 1
                existing['skipped_queries'] += int(bool(error.get('skipped')))
            else:
                audit.append({'url':None,'typ':'search','provider':provider,'code':code,
                              'befund':message,'action':error.get('action'),'auswertbar':False,
                              'occurrences':1,'skipped_queries':int(bool(error.get('skipped')))})
    pages=0;catalog_urls=set();discovery_urls=set();publisher_domains=set(approved_domains)
    seeds=list(station.get('urls',[]))
    hits,errors=web.search(' '.join(filter(None,[station['name'],station['region'],'Fahrzeuge Funkrufname'])))
    record_search_errors(errors)
    hits=[h for h in hits if usable_source_url(h.get('url')) and relevant_hit(h,station)]
    usable=[h['url'] for h in hits if source_kind(h['url'])=='operator_candidate']
    if not usable and station.get('adresse'):
        more,errors=web.search(' '.join([station['adresse'],station.get('town',''),'Rettungsdienst' if station_type(station)=='RD' else 'Feuerwehr','Fahrzeuge']))
        hits += [h for h in more if relevant_hit(h,station)]
        record_search_errors(errors)
    for source in extra_sources:
        # Empty/missing scope is never a wildcard.
        if registry_source_matches(source,station):
            seeds.append(source['url'])
            if source.get('role')=='operator_discovery':
                discovery_urls.add(source['url'])
                if source.get('publisher_verified') is True:publisher_domains.add((urlparse(source['url']).hostname or '').removeprefix('www.'))
            else:catalog_urls.add(source['url'])
    seeds+= [h['url'] for h in hits]
    for domain in ('bos-fahrzeuge.info/wachen','lstsim.de'):
        found,failures=web.search(' '.join([f'site:{domain}',station.get('resolved_name') or station['name'],station['state'],station['region'],station.get('town','')]))
        seeds += [h['url'] for h in found if relevant_hit(h,station)]
        record_search_errors(failures)
    queue=list(dict.fromkeys(u for u in seeds if usable_source_url(u)))
    def kind_for(u):return 'catalog' if u in catalog_urls and source_kind(u)=='operator_candidate' else source_kind(u)
    queue.sort(key=lambda u:{'operator_candidate':0,'catalog':1,'bos':2,'lstsim':3}[kind_for(u)])
    seen=set()
    while queue and pages<max_pages:
        url=queue.pop(0)
        if url in seen or not usable_source_url(url):continue
        seen.add(url);kind=kind_for(url)
        # A detail URL from a search hit is never fetched without a checked listing.
        if kind=='bos' and '/einsatzfahrzeuge/' in url:
            audit.append({'url':url,'typ':kind,'befund':'Detailabruf gesperrt: Breadcrumb/Organisation fehlen','auswertbar':False});continue
        if kind=='lstsim' and any(v['schreibfaehig'] for v in findings):continue
        try:
            html=web.get(url);pages+=1
            resolved=resolve_technical_identity(html,station) if kind=='operator_candidate' else None
            probe=dict(station,name=resolved) if resolved else station
            home=page_identity(html,probe)
            verified=official_page(url,html,probe,publisher_domains) if kind=='operator_candidate' else False
            authority='official' if verified else 'secondary'
            if resolved and verified:
                station['resolved_name']=resolved
                station['aliases']=list(set(station.get('aliases',[])+[resolved]))
            if url in discovery_urls and not home:
                # An overview can lead to a station, but cannot itself assert that station's inventory.
                for link in reversed(discovery_links(html,url,station)):
                    if link not in seen and link not in queue:queue.insert(0,link)
                audit.append({'url':url,'typ':kind,'befund':'Regionaler Sucheinstieg; Wachenidentität noch nicht belegt','auswertbar':False})
                continue
            if kind=='operator_candidate' and not verified:
                audit.append({'url':url,'typ':kind,'befund':'Betreiberidentität nicht bestätigt','auswertbar':False});continue
            if kind=='bos':
                metadata=breadcrumb_metadata(html,url,station)
                if not bos_prefilter(metadata,station):
                    audit.append({'url':url,'typ':kind,'befund':'Region/Organisation im Breadcrumb nicht belegt; Details gesperrt','auswertbar':False});continue
                if not home:
                    audit.append({'url':url,'typ':kind,'befund':'Heimatwache/Adresse nicht bestätigt; Details gesperrt','auswertbar':False});continue
                links=[urljoin(url,a['href']) for a in BeautifulSoup(html,'html.parser').select('a[href]') if '/einsatzfahrzeuge/' in a['href']]
                for detail in list(dict.fromkeys(links))[:min(5,max_pages-pages)]:
                    detail_html=web.get(detail);pages+=1
                    # Listing breadcrumbs authorize the request, but the detail must confirm its own home.
                    source={'url':detail,'kind':'bos','authority':'secondary','verified':True,'home_verified':page_identity(detail_html,station)}
                    active,old=extract(detail_html,station,source,allowed,prefixes(station['state'],station['region'],samples))
                    findings+=active;retired+=old;samples += [v['callsign'] for v in active][:max(0,5-len(samples))]
                    audit.append({'url':detail,'typ':'bos','befund':'geprüft' if active else 'Keine eindeutig belegten aktiven Fahrzeuge','auswertbar':bool(active)})
                continue
            source={'url':url,'kind':kind,'authority':authority,'verified':verified or kind in ('catalog','lstsim'),'home_verified':home}
            active,old=extract(html,station,source,allowed,prefixes(station['state'],station['region'],samples))
            if not active and use_llm and kind!='lstsim':
                for block,structured in blocks(html):
                    if not structured and home and len(block)>30:
                        active+=llm_fallback(block,station,source,allowed,prefixes(station['state'],station['region'],samples))
                        break
            findings+=active;retired+=old
            audit.append({'url':url,'typ':kind,'befund':('Nur LstSim' if kind=='lstsim' else 'geprüft') if active else 'Keine eindeutig belegten aktiven Fahrzeuge','auswertbar':bool(active),'identity_verified':bool(home and verified)})
            # Follow only on-domain fleet/contact links; cap protects against unlimited crawls.
            if kind=='operator_candidate':
                for a in BeautifulSoup(html,'html.parser').select('a[href]'):
                    link=urljoin(url,a['href'])
                    if urlparse(link).hostname==urlparse(url).hostname and re.search(r'fahrzeug|fuhrpark|technik|kontakt|impressum',a.get_text(' ',strip=True)+' '+a['href'],re.I) and link not in seen and link not in queue:
                        queue.insert(0,link)
        except Exception as exc:
            audit.append({'url':url,'typ':kind,'befund':str(exc)[:250],'auswertbar':False})
    if queue:audit.append({'url':None,'typ':'limit','befund':'Suchlimit erreicht; Recherche unvollständig','auswertbar':False})
    return findings,retired,audit,mapping_record(station['state'],station['region'],samples)


def research_station(web, station, allowed, extra_sources=(), approved_domains=(), use_llm=True, max_pages=8, learned_samples=(), bos_first=True):
    from bos import research_bos
    active, bos_audit = research_bos(web, station, allowed, max_pages, learned_samples) if bos_first else ([], [])
    samples = list(learned_samples)
    samples += [v['callsign'] for v in active][:max(0, 5-len(samples))]
    if any(v.get('typ') and v.get('home_verified') for v in active):
        return active, [], bos_audit, mapping_record(station['state'], station['region'], samples)
    found, retired, audit, mapping = _research_fallback(web, station, allowed, extra_sources, approved_domains, use_llm, max_pages, samples)
    return active + found, retired, bos_audit + audit, mapping
