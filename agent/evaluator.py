"""Source scope, identity and delta policies independent of network calls."""
import math
import re
from urllib.parse import urlparse
from bs4 import BeautifulSoup
from common import TECHNICAL, HISTORICAL, coordinates, norm, key, seal, station_type


def region_key(value):
    return re.sub(r'^(landkreis|kreis|bezirk|district|kanton) ', '', norm(value))


def scope_matches(source, station):
    return bool(source.get('country') and source.get('state') and source.get('region') and source.get('typ') and source['country']==station['country'] and norm(source['state'])==norm(station['state']) and region_key(source['region'])==region_key(station['region']) and station_type(source)==station_type(station))


def bos_prefilter(metadata, station):
    return bool(metadata.get('breadcrumb_verified') and metadata.get('breadcrumb_url') and metadata.get('organisation') and station.get('organisation') and norm(metadata['organisation'])==norm(station['organisation']) and scope_matches(metadata,station))


def page_identity(html, station):
    soup=BeautifulSoup(html,'html.parser')
    title=' '.join(n.get_text(' ',strip=True) for n in soup.select('title,h1'))
    names=[station['name']]+station.get('aliases',[])
    name_match=any(norm(n) and norm(n) in norm(title) for n in names if not TECHNICAL.fullmatch(n))
    address=norm(station.get('adresse'))
    page=norm(soup.get_text(' ',strip=True))
    return bool(name_match and address and address in page)


def official_page(url, html, station, approved_domains=()):
    host=(urlparse(url).hostname or '').lower().removeprefix('www.')
    if host in approved_domains: return True
    # Name alone is insufficient. Impressum/contact and a matching station address are required.
    owner=any(token in host for token in ('feuerwehr','drk-','roteskreuz','rettung','stadt-','gemeinde','landkreis','gv.at','admin.ch'))
    return bool(owner and re.search(r'impressum|kontakt',html,re.I) and page_identity(html,station))


def distance(a,b):
    pa,pb=coordinates(a),coordinates(b)
    if not pa or not pb: return math.inf
    lat1,lon1,lat2,lon2=map(math.radians,(*pa,*pb))
    h=math.sin((lat2-lat1)/2)**2+math.cos(lat1)*math.cos(lat2)*math.sin((lon2-lon1)/2)**2
    return 6371*2*math.asin(min(1,math.sqrt(h)))


def match_station(hint, stations):
    same=[s for s in stations if norm(s.get('land')) in ('', norm({'DE':'Deutschland','AT':'Österreich','CH':'Schweiz'}[hint['country']])) and norm(s.get('bundesland'))==norm(hint['state']) and station_type(s)==station_type(hint)]
    coords=hint.get('coordinates') or {}
    probe={'latitude':coords.get('lat'),'longitude':coords.get('lon')}
    matches=[]
    for s in same:
        address=norm(hint.get('adresse'))
        addr=bool(address and address==norm(s.get('adresse')))
        name=key(s['name'])==key(hint['name'])
        nearby=distance(s,probe)<=0.15
        if coords and coordinates(probe) and distance(s,probe)>0.75:
            continue
        if addr or name or nearby: matches.append(s)
    return matches


def delta_vehicle(station, vehicles, retired, api_vehicles, source_stamp):
    result, conflicts=[],[]
    grouped={}
    for v in vehicles:
        if HISTORICAL.search(v['beleg']): continue
        grouped.setdefault(key(v['callsign']),[]).append(v)
    for callsign, group in grouped.items():
        if len({v['typ'] for v in group if v['typ']})>1:
            conflicts.append({'callsign':group[0]['callsign'],'reason':'Widersprüchliche Typbelege'});continue
        v=next((v for v in group if v['schreibfaehig']),group[0])
        existing=[r for r in api_vehicles if key(r['rufname'])==callsign]
        if any(int(r['wache_id'])!=int(station['id']) for r in existing):
            conflicts.append({'callsign':v['callsign'],'reason':'Rufname an anderer Wache'});continue
        action=None
        if not existing: action='ADD'
        elif len(existing)==1 and v['typ'] and existing[0]['fahrzeugtyp']!=v['typ']: action='UPDATE'
        if action:
            result.append(seal({'action':action,'wache_id':int(station['id']),'vehicle_id':int(existing[0]['id']) if existing else None,'before':existing[0] if existing else None,'after':{'callsign':v['callsign'],'type':v['typ']},'beleg':v['beleg'],'quelle':v['quelle'],'authority':v['authority'],'home_verified':v['home_verified'],'schreibfaehig':v['schreibfaehig'],'nur_lstsim':v['nur_lstsim'],'source_stamp':source_stamp,'station_name':station['name'],'station_before':{k:station.get('api_before',station).get(k) for k in ('name','adresse','latitude','longitude')}}))
    for v in retired:
        existing=[r for r in api_vehicles if key(r['rufname'])==key(v['callsign'])]
        if len(existing)==1 and int(existing[0]['wache_id'])==int(station['id']) and existing[0].get('status')!='nicht einsatzbereit' and key(v['callsign']) not in grouped:
            result.append(seal({'action':'SET_AD','wache_id':int(station['id']),'vehicle_id':int(existing[0]['id']),'before':existing[0],'after':{'callsign':v['callsign'],'status':'nicht einsatzbereit','fms_status':'6'},'beleg':v['beleg'],'quelle':v['quelle'],'authority':v['authority'],'home_verified':True,'schreibfaehig':v['authority']=='official','nur_lstsim':False,'source_stamp':source_stamp,'station_name':station['name'],'station_before':{k:station.get('api_before',station).get(k) for k in ('name','adresse','latitude','longitude')}}))
    return result,conflicts


def compare_centers(centers, secondary, catalog):
    output=[]
    for center in centers:
        matches=[c for c in catalog if c.get('leitstelle_id')==int(center['id'])]
        c=matches[0] if len(matches)==1 else {}
        actual=center.get('relations',{}).get('nebenleitstellen')
        expected=c.get('nebenstellen')
        valid=c.get('verified') is True and c.get('active') is True and c.get('source_url') and isinstance(expected,list) and isinstance(actual,list) and all((n.get('id') is None and n.get('name')) or any(int(s['id'])==int(n['id']) for s in secondary) for n in expected)
        ids=[int(n['id']) for n in expected if n.get('id') is not None] if valid else []
        missing_names=[n for n in expected if n.get('id') is None] if valid else []
        output.append({'leitstelle_id':int(center['id']),'soll-Nebenstellen':expected,'ist-Nebenstellen':actual,'diff':{'fehlend':sorted(set(ids)-set(actual))+missing_names,'zusaetzlich':sorted(set(actual)-set(ids))} if valid else None,'status':'geprüft' if valid else 'Soll ungeklärt','quelle':c.get('source_url')})
    return output


def resolve_technical_identity(html, station):
    if not TECHNICAL.fullmatch(station['name']) or not station.get('adresse'): return None
    soup=BeautifulSoup(html,'html.parser')
    if norm(station['adresse']) not in norm(soup.get_text(' ',strip=True)): return None
    for tag in soup.select('h1,title'):
        name=tag.get_text(' ',strip=True).split('|')[0].strip()
        if re.search(r'feuerwehr|rettungswache|rettungsstation|rotes kreuz|drk',name,re.I) and station.get('town') and norm(station['town']) in norm(name) and len(name)<=255:
            return name
    return None
