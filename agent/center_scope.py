"""Select a center and every assigned subsidiary through explicit API relations."""
import json
from collections import defaultdict
from common import COUNTRIES, coordinates, norm, now
from evaluator import match_station
from geo import membership


def relation_ids(row, relation):
    values = row.get('relations', {}).get(relation)
    if not isinstance(values, list):
        raise ValueError(f"Unvollständige Zuordnung {relation} bei {row.get('name', row.get('id'))}")
    try:
        result = {int(value) for value in values}
    except (ValueError, TypeError):
        raise ValueError('Ungültige API-Zuordnungs-ID')
    if any(value <= 0 for value in result):
        raise ValueError('Ungültige API-Zuordnungs-ID')
    return result


def select_center(selector, centers):
    if str(selector).isdigit():
        matches = [c for c in centers if int(c['id']) == int(selector)]
    else:
        exact = [c for c in centers if norm(c.get('name')) == norm(selector)]
        matches = exact or [c for c in centers if norm(selector) and norm(selector) in norm(c.get('name'))]
    if len(matches) != 1:
        raise ValueError('Leitstelle fehlt oder Name ist mehrdeutig; eindeutige ID verwenden')
    return matches[0]


def load_center_scope(api, selector, centers, secondary, stations):
    chosen = select_center(selector, centers)
    center = api.detail('leitstellen', chosen['id'])
    sub_ids = relation_ids(center, 'nebenleitstellen')
    known = {int(s['id']) for s in secondary}
    if not sub_ids <= known:
        raise ValueError('Zugeordnete Nebenstellen fehlen im vollständigen API-Bestand')
    subsidiaries = [api.detail('nebenleitstellen', ident) for ident in sorted(sub_ids)]
    assigned = relation_ids(center, 'wachen')
    summaries = []
    for sub in subsidiaries:
        ids = relation_ids(sub, 'wachen')
        assigned.update(ids)
        summaries.append({'id':int(sub['id']), 'name':sub['name'], 'station_count':len(ids)})
    inventory = {int(s['id']):s for s in stations}
    if not assigned <= inventory.keys():
        raise ValueError('Zugeordnete Wachen fehlen im vollständigen API-Bestand: ' + ', '.join(map(str, sorted(assigned-inventory.keys()))))
    countries = {norm(name):code for code, name in COUNTRIES.items()}
    countries.update({norm(code):code for code in COUNTRIES})
    groups, unclassified = defaultdict(list), []
    for ident in sorted(assigned):
        station = inventory[ident]
        country, state = countries.get(norm(station.get('land'))), str(station.get('bundesland') or '').strip()
        if not country or not state:
            unclassified.append({'wache_id':ident,'name':station['name'],'reason':'Land/Bundesland fehlt oder ist unbekannt'})
        else:
            groups[(country, state)].append(ident)
    scope = {'generated_at':now(), 'leitstelle_id':int(center['id']), 'name':center['name'],
             'selection':'API-Zuordnungen einschließlich aller zugeordneten Nebenstellen',
             'direct_station_count':len(relation_ids(center, 'wachen')),
             'nebenstellen':summaries, 'station_ids':sorted(assigned),
             'station_count':len(assigned), 'outside_scope_count':len(stations)-len(assigned),
             'groups':[{'country':c,'state':s,'station_ids':ids} for (c,s),ids in groups.items()],
             'unclassified_stations':unclassified, 'api_writes':0,
             'status':'scope_checked', 'inventory_complete':False}
    return scope, center, subsidiaries


def scope_catalogs(hints, all_stations, scope, centers, web):
    """Existing stations follow relations; new hints need coordinates inside a selected API area."""
    assigned = set(scope['station_ids'])
    groups = {(g['country'], norm(g['state'])) for g in scope['groups']}
    geometries, missing_areas = [], []
    for center in centers:
        geometry = center.get('geojson')
        try:
            if isinstance(geometry, str):
                geometry = json.loads(geometry)
            if geometry:
                geometries.append(geometry)
            else:
                missing_areas.append(center['name'])
        except (ValueError, TypeError):
            missing_areas.append(center['name'])
    selected, unresolved, outside = [], [], 0
    for original in hints:
        hint = dict(original)
        if (hint['country'], norm(hint['state'])) not in groups:
            continue
        matches = match_station(hint, all_stations)
        if len(matches) > 1:
            unresolved.append({'name':hint['name'],'reason':'Mehrdeutige API-Wache','ids':[s['id'] for s in matches]})
            continue
        if matches:
            if int(matches[0]['id']) in assigned:
                selected.append(hint)
            else:
                outside += 1
            continue
        try:
            point = hint.get('coordinates') or {}
            probe = {'latitude':point.get('lat'), 'longitude':point.get('lon')}
            if not coordinates(probe) and hint.get('adresse'):
                point = web.forward(hint['adresse'], hint['country'])
                probe = {'latitude':point['lat'], 'longitude':point['lon']}
            if not coordinates(probe):
                raise ValueError('Koordinaten des zusätzlichen Kataloghinweises ungeklärt')
            checks = [membership(probe, geo) for geo in geometries]
            if 'inside' in checks:
                hint['coordinates'] = {'lat':probe['latitude'], 'lon':probe['longitude']}
                selected.append(hint)
            elif 'boundary' in checks or not geometries or missing_areas:
                raise ValueError('Gebietszuordnung des zusätzlichen Kataloghinweises ungeklärt')
            else:
                outside += 1
        except Exception as exc:
            unresolved.append({'name':hint['name'],'reason':str(exc)[:300]})
    scope['catalog_scope'] = {'selected':len(selected), 'outside':outside, 'unresolved':unresolved,
                              'areas_without_geometry':missing_areas,
                              'basis':'API-Gebietsgeometrie; keine amtliche Vollständigkeitsbestätigung'}
    return selected


def print_scope(scope):
    print(f"Leitstelle: {scope['name']} (ID {scope['leitstelle_id']})", flush=True)
    print(f"{scope['direct_station_count']} direkt zugeordnete Wachen; {len(scope['nebenstellen'])} Nebenstellen:", flush=True)
    for sub in scope['nebenstellen']:
        print(f"  {sub['id']}: {sub['name']} — {sub['station_count']} Zuordnungen", flush=True)
    print(f"Gesamt: {scope['station_count']} eindeutige API-Wachen; {scope['outside_scope_count']} außerhalb der Auswahl.", flush=True)
    for group in scope['groups']:
        print(f"  {group['country']} / {group['state']}: {len(group['station_ids'])} Wachen", flush=True)
    print(f"Land/Bundesland ungeklärt: {len(scope['unclassified_stations'])}", flush=True)
