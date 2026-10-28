#!/usr/bin/env python3
"""Autonomous local research runner. A report invocation never calls a write endpoint."""
import argparse
import csv
import json
import os
import sys
from pathlib import Path
from common import ROOT, COUNTRIES, STATES, TECHNICAL, coordinates, digest, load_env, now, norm, save_json, seal, slug, station_type
from catalogs import load_catalogs, sheet_states
from evaluator import compare_centers, delta_vehicle, match_station, page_identity, region_key
from extractor import clean_text
from geo import membership
from lst_api import LstApi
from research import PublicWeb
from sources import research_station
from waylog import log_way
from writer import Writer
from centers import discover_center_soll
from report_summary import summarize, render_summary
from center_scope import load_center_scope, scope_catalogs, print_scope

SHEET='https://docs.google.com/spreadsheets/d/1ksyKyRupqpxnx0-8yyFXBY3Rmqsdj69VZ0VQdUGKJPk/export?format=csv'


def output_station(station, vehicles, audit):
    return {'wache_id':station.get('id'),'name':station['name'],'adresse':str(station['adresse'])[:500] if station.get('adresse') else None,'typ':station_type(station),'organisation':station.get('organisation'),'quelle':next((v['quelle'] for v in vehicles),None),'quelle_bewertung':'ja' if any(v['schreibfaehig'] for v in vehicles) else 'bedingt' if vehicles else 'nein','quellen_zusatz':audit,'fahrzeuge':vehicles,'recherche_status':'belegte Teilmenge; Vollständigkeit nicht bestätigt' if vehicles else 'ungeklärt/unvollständig'}


def run_state(state,country,api,web,hints,provenance,options,snapshot):
    out=Path(options.output)
    label=slug(state) if country=='DE' else country+'-'+slug(state)
    path=out/(label+'.json');delta_path=out/('DELTA_'+label+'.json')
    rows=snapshot['wachen']; vehicles=snapshot['fahrzeuge']
    local=[h for h in hints if h['country']==country and norm(h['state'])==norm(state)]
    selected=[s for s in rows if norm(s.get('bundesland'))==norm(state) and norm(s.get('land')) in ('',norm(COUNTRIES[country]),norm(country))]
    jobs=[];assigned=set();unresolved=[]
    for hint in local:
        matches=match_station(hint,selected)
        if len(matches)>1:
            unresolved.append({'name':hint['name'],'reason':'Mehrdeutige API-Wache','ids':[s['id'] for s in matches]});continue
        if matches:
            s=matches[0]
            if int(s['id']) in assigned:continue
            assigned.add(int(s['id']))
            jobs.append(dict(hint,id=int(s['id']),name=s['name'],api_before=s,aliases=[hint['name']],latitude=s.get('latitude'),longitude=s.get('longitude'),adresse=s.get('adresse') or hint.get('adresse'),resolved_name=hint['name']))
        else:
            c=hint.get('coordinates') or {}
            jobs.append(dict(hint,id=None,latitude=c.get('lat'),longitude=c.get('lon')))
    for s in selected:
        if int(s['id']) not in assigned:
            org='FF' if s['typ']=='FFW' else 'BF' if s['typ']=='FW' else None
            jobs.append(dict(s,country=country,state=state,region='',town='',typ=station_type(s),organisation=org,api_before=s,urls=[]))
    # Record every technical name before geocoding, even if the run is later interrupted.
    for s in jobs:
        if TECHNICAL.fullmatch(s['name']):log_way(out/'way.log',s)
    package=None
    if options.package:
        package=json.loads(Path(options.package).read_text(encoding='utf-8-sig'))
        if package['country']!=country or norm(package['state'])!=norm(state):raise ValueError('Paket passt nicht zum Bundesland')
        if package.get('geometry_url') and not package.get('geometry'):
            package['geometry']=json.loads(web.get(package['geometry_url']))
        if not package.get('geometry') or not package.get('geometry_source'):raise ValueError('Amtliche Paketgeometrie mit Herkunft erforderlich')
    result={country:{state:{}}};deltas=[];mappings=[];station_status=[];regional_samples={}
    metadata={'research_strategy':'bos_first_then_other_sources','center_scope':snapshot.get('center_scope'),'package':{'region':package['region'],'geometry_source':package['geometry_source']} if package else None,'generated_at':now(),'country':country,'state':state,'catalogs':provenance,'api_writes':0,'inventory_complete':False,'global_scope':snapshot.get('global_scope',{}),'status':'running','unresolved':unresolved,'stations':station_status,'pending':[s.get('id') or s['name'] for s in jobs]}
    def checkpoint():
        save_json(path,result);save_json(delta_path,{'generated_at':metadata['generated_at'],'api_base':api.base,'entries':deltas,'status':metadata['status']});save_json(out/(label+'-status.json'),metadata);save_json(out/(label+'-rufnamen.json'),mappings)
    checkpoint()
    if package:
        scoped_jobs=[]
        for station in jobs:
            reason=None
            try:
                if not station.get('id') and not coordinates(station) and station.get('adresse'):
                    location=web.forward(station['adresse'],country)
                    station.update(latitude=location['lat'],longitude=location['lon'],coordinates=location)
                inside=membership(station,package['geometry'])
            except Exception as exc:
                inside='unresolved';reason=str(exc)[:300]
            if inside=='inside':
                scoped_jobs.append(station)
            else:
                record={'wache_id':station.get('id'),'name':station['name'],'status':inside,'stage':'geography'}
                if reason:record['reason']=reason
                elif inside=='unresolved':record['reason']='Koordinaten fehlen oder sind ungültig'
                elif inside=='boundary':record['reason']='Wache liegt auf der Paketgrenze'
                station_status.append(record)
        jobs=scoped_jobs
        metadata['pending']=[s.get('id') or s['name'] for s in jobs]
        checkpoint()
        counts=summarize(metadata,deltas)
        print(f"Paket {package['region']}: {len(jobs)} Einträge für Recherche; "
              f"{counts['outside_package']} außerhalb ausgeschlossen; "
              f"{counts['geography_unresolved']} geografisch ungeklärt.",flush=True)
    progress_label=package['region'] if package else state
    extra=json.loads((ROOT/'source-registry.json').read_text(encoding='utf-8-sig'))
    area_sources=json.loads((ROOT/'area-source-registry.json').read_text(encoding='utf-8-sig'))['areas']
    for index,s in enumerate(jobs):
        if options.max_stations and index>=options.max_stations:
            metadata['status']='partial';break
        print(f'[{progress_label}] {index+1}/{len(jobs)} {s["name"]}',flush=True)
        research_started=False;stage='prepare'
        try:
            if not s.get('id') and not coordinates(s) and s.get('adresse'):
                location=web.forward(s['adresse'],country)
                s.update(latitude=location['lat'],longitude=location['lon'],coordinates=location)
            reverse=None
            if coordinates(s):
                reverse=web.reverse(*coordinates(s))
                if reverse['country']!=country or reverse['state'] and norm(reverse['state'])!=norm(state):raise ValueError('Reverse-Geocoding widerspricht Land/Bundesland')
                if s.get('region') and reverse['region'] and region_key(s['region'])!=region_key(reverse['region']):raise ValueError('Landkreis aus Quelle und Koordinaten widersprechen sich')
                s['region']=reverse['region'] or s.get('region') or reverse['town']
                s['town']=reverse['town'] or s.get('town','')
                s['adresse']=reverse['adresse'] or s.get('adresse')
                if TECHNICAL.fullmatch(s['name']):log_way(out/'way.log',s,s['adresse'],reverse['url'])
            if not s.get('region'):raise ValueError('Gebiet nicht eindeutig belegt')
            if coordinates(s):
                for resource in ('leitstellen','nebenleitstellen'):
                    for center in snapshot[resource]:
                        registered=area_sources.get(f"{resource}-{center['id']}")
                        if registered and center.get('geojson') and membership(s,center['geojson'])=='inside':
                            s['urls']=list(dict.fromkeys(s.get('urls',[])+registered['seed_urls']))
            stage='inventory'
            if s.get('id'):
                current=api.station_vehicles(s['id'])
                vehicles=[v for v in vehicles if int(v['wache_id'])!=int(s['id'])]+current
            stage='research';research_started=True
            active,retired,audit,mapping=research_station(web,s,snapshot['types'],extra_sources=extra['sources'],use_llm=not options.no_llm,max_pages=options.max_pages,learned_samples=regional_samples.get(norm(s['region']),()))
            mappings.append(mapping)
            regional_samples[norm(s['region'])]=mapping.get('samples',[])
            # A technical name needs an independently checked human-readable identity.
            if TECHNICAL.fullmatch(s['name']):
                name=s.get('resolved_name');resolved=False
                if name and not TECHNICAL.fullmatch(name):
                    for source in audit:
                        if source.get('url') and source['typ']=='operator_candidate' and source.get('identity_verified'):
                            html=web.get(source['url']);probe=dict(s,name=name)
                            if page_identity(html,probe):
                                evidence=clean_text(html)
                                entry=seal({'action':'UPDATE_WACHE','wache_id':int(s['id']),'before':s['api_before'],'after':{'name':name,'adresse':s['adresse'][:500]},'beleg':evidence,'quelle':source['url'],'home_verified':True,'schreibfaehig':True,'nur_lstsim':False,'authority':'official','source_stamp':now()})
                                deltas.append(entry);resolved=True;log_way(out/'way.log',s,s['adresse'],source['url'],True);break
                if not resolved:
                    for v in active:v['schreibfaehig']=False
            if s.get('id'):
                entries,conflicts=delta_vehicle(s,active,retired,vehicles,now());deltas+=entries
                unresolved.extend(conflicts)
            else:
                # New station is permitted only as an individually reviewed, officially evidenced delta.
                official=next((a for a in audit if a.get('url') and a['typ']=='operator_candidate' and a.get('identity_verified')),None)
                if official and reverse and coordinates(s):
                    typ={'FF':'FFW','BF':'FW','RD':'RD','WF':'FW','FTZ':'FW'}[station_type(s)]
                    after={'name':s['name'],'adresse':s['adresse'][:500],'typ':typ,'land':COUNTRIES[country],'bundesland':state,'latitude':s['latitude'],'longitude':s['longitude'],'leitstellen':[],'nebenleitstellen':[]}
                    for resource in ('leitstellen','nebenleitstellen'):
                        for center in snapshot[resource]:
                            if center.get('geojson') and membership(s,center['geojson'])=='inside':after[resource].append(int(center['id']))
                    writable=any(after[k] for k in ('leitstellen','nebenleitstellen'))
                    deltas.append(seal({'action':'ADD_WACHE','wache_id':None,'before':None,'after':after,'station_hint':s,'beleg':clean_text(web.get(official['url'])),'quelle':official['url'],'home_verified':True,'schreibfaehig':writable,'nur_lstsim':False,'authority':'official','source_stamp':now()}))
            result[country][state].setdefault(s['region'],{}).setdefault(s.get('town') or 'ungeklärt',[]).append(output_station(s,active,audit))
            station_status.append({'wache_id':s.get('id'),'name':s['name'],'status':'processed','stage':'research','research_started':True,'active_count':len(active),'unresolved':not bool(active)})
        except Exception as exc:
            station_status.append({'wache_id':s.get('id'),'name':s['name'],'status':'unresolved','stage':stage,'research_started':research_started,'reason':str(exc)[:300]})
            result[country][state].setdefault(s.get('region') or 'ungeklärt',{}).setdefault(s.get('town') or 'ungeklärt',[]).append(output_station(s,[],[{'url':None,'typ':'error','befund':str(exc)[:300],'auswertbar':False}]))
        metadata['pending'].pop(0);checkpoint()
    if not metadata['pending']:metadata['status']='processed_with_unresolved' if any(s.get('unresolved') or s['status'] in ('unresolved','boundary') for s in station_status) else 'processed'
    metadata['api_writes']=api.writes
    if api.writes:raise AssertionError('Reportlauf hat API-Schreibaktionen ausgeführt')
    metadata['finished_at']=now()
    metadata['summary']=summarize(metadata,deltas)
    checkpoint()
    summary_text=render_summary(metadata,metadata['summary'])
    (out/(label+'-auswertung.md')).write_text(summary_text,encoding='utf-8')
    with (out/(label+'-fehlende-fahrzeuge.csv')).open('w',newline='',encoding='utf-8-sig') as handle:
        writer=csv.writer(handle);writer.writerow(['wache_id','fehlende Rufnamen','Quelle'])
        for e in deltas:
            if e['action']=='ADD':writer.writerow([e['wache_id'],e['after']['callsign'],e['quelle']])
    print('\n'+summary_text,flush=True)
    return metadata


def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    group=parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--bundesland');group.add_argument('--all',action='store_true');group.add_argument('--freigeben',type=Path)
    group.add_argument('--leitstelle',help='Leitstellen-ID oder eindeutiger Name; einschließlich zugeordneter Nebenstellen')
    parser.add_argument('--scope-only',action='store_true',help='Nur API-Zuordnungen anzeigen, ohne Quellenrecherche')
    parser.add_argument('--land',choices=COUNTRIES,default='DE');parser.add_argument('--id',action='append',default=[])
    parser.add_argument('--output',default=str(ROOT/'data'));parser.add_argument('--catalogs',default=str(ROOT/'catalogs'))
    parser.add_argument('--package');parser.add_argument('--max-stations',type=int);parser.add_argument('--max-pages',type=int,default=8)
    parser.add_argument('--wache-id',type=int,action='append',default=[],help='Mit --leitstelle nur diese bereits zugeordneten API-Wachen recherchieren')
    parser.add_argument('--offline',action='store_true');parser.add_argument('--no-llm',action='store_true')
    options=parser.parse_args(argv)
    if options.scope_only and not options.leitstelle:parser.error('--scope-only benötigt --leitstelle')
    if options.leitstelle and options.package:parser.error('--leitstelle und --package sind getrennte Gebietsauswahlen')
    if options.wache_id and not options.leitstelle:parser.error('--wache-id benötigt --leitstelle')
    if options.wache_id and options.scope_only:parser.error('--wache-id ist nur für einen Recherche-Nachlauf vorgesehen')
    if any(ident<=0 for ident in options.wache_id):parser.error('--wache-id muss positiv sein')
    if options.max_stations is not None and options.max_stations<1:parser.error('--max-stations muss positiv sein')
    load_env(ROOT/'.env');load_env(ROOT.parent/'.env')
    web=PublicWeb(ROOT/'cache',offline=options.offline)
    api=LstApi(writable=bool(options.freigeben))
    if options.freigeben:
        if not options.id:parser.error('--freigeben benötigt mindestens eine --id')
        report=json.loads(options.freigeben.read_text(encoding='utf-8-sig'))
        if report['api_base']!=api.base:raise ValueError('Report gehört zu einer anderen API')
        selected=[e for e in report['entries'] if e['freigabe_id'] in options.id]
        if len(selected)!=len(set(options.id)):raise ValueError('Freigabe-ID fehlt oder ist mehrdeutig')
        writer=Writer(api,web,Path(options.output)/'writes.log')
        # Validate all identities before the first operation; writes remain strictly serial.
        from common import valid_seal
        if not all(valid_seal(e) for e in selected):raise ValueError('Veränderte Report-Einträge')
        results=[writer.apply(e) for e in selected]
        print(json.dumps(results,ensure_ascii=False,indent=2));return 0 if all(r['status']=='written' or r['status'].startswith('skipped_') for r in results) else 2
    hints,provenance=load_catalogs(options.catalogs)
    states=[options.bundesland] if options.bundesland else list(dict.fromkeys(h['state'] for h in hints if h['country']==options.land))
    if options.all and options.land in ('AT','CH'):
        states=sorted(set(states)|{s.get('bundesland') for s in api.list('wachen') if s.get('bundesland') and norm(s.get('land'))==norm(COUNTRIES[options.land])})
    if options.all and options.land=='DE':
        try:states=sheet_states(web,os.environ.get('LST_STATES_CSV_URL',SHEET))
        except Exception as exc:
            print(f'Bundesländer-Tabelle nicht lesbar: {exc}; dokumentierter DE-Fallback mit 16 Bundesländern.',file=sys.stderr);states=STATES
        states=sorted(states,key=lambda s:(s!='Brandenburg',s))
    if not states and not options.leitstelle:raise ValueError('Keine Bundesländer/Kantone konfiguriert')
    snapshot={}
    try:snapshot['global_scope']=api.global_scope()
    except Exception as exc:snapshot['global_scope']={'global_read':False,'reason':str(exc)}
    resources=('wachen','leitstellen','nebenleitstellen') if options.scope_only else ('wachen','fahrzeuge','leitstellen','nebenleitstellen')
    for resource in resources:
        print('API laden: '+resource,flush=True);snapshot[resource]=api.list(resource)
    scope=None
    if options.leitstelle:
        scope,center,subs=load_center_scope(api,options.leitstelle,snapshot['leitstellen'],snapshot['nebenleitstellen'],snapshot['wachen'])
        if options.wache_id:
            selected_ids=set(options.wache_id)
            if not selected_ids <= set(scope['station_ids']):
                raise ValueError('Ausgewählte Wachen-ID gehört nicht zur Leitstelle oder ihren Nebenstellen')
            scope['selection']='Gezielter Nachlauf aus API-Zuordnungen einschließlich Nebenstellen'
            scope['selected_station_ids']=sorted(selected_ids)
            scope['station_ids']=sorted(selected_ids)
            scope['station_count']=len(selected_ids)
            scope['outside_scope_count']=len(snapshot['wachen'])-len(selected_ids)
            scope['groups']=[dict(group,station_ids=[ident for ident in group['station_ids'] if ident in selected_ids]) for group in scope['groups']]
            scope['groups']=[group for group in scope['groups'] if group['station_ids']]
            scope['unclassified_stations']=[row for row in scope['unclassified_stations'] if row['wache_id'] in selected_ids]
        if options.output==str(ROOT/'data'):
            options.output=str(ROOT/'data'/('Leitstelle-'+str(scope['leitstelle_id'])))
        scope_path=Path(options.output)/('leitstellen-vorschau.json' if options.scope_only else 'leitstellen-umfang.json')
        scope['global_scope']=snapshot['global_scope']
        save_json(scope_path,scope)
        print_scope(scope)
        if options.scope_only:return 0
        if options.wache_id:
            selected_ids=set(options.wache_id)
            hints=[hint for hint in hints if len(matches:=match_station(hint,snapshot['wachen']))==1 and int(matches[0]['id']) in selected_ids]
        hints=scope_catalogs(hints,snapshot['wachen'],scope,[center]+subs,web)
        save_json(scope_path,scope)
        scoped_ids=set(scope['station_ids'])
        snapshot['wachen']=[s for s in snapshot['wachen'] if int(s['id']) in scoped_ids]
        snapshot['leitstellen']=[center];snapshot['nebenleitstellen']=subs
        snapshot['center_scope']={'id':scope['leitstelle_id'],'name':scope['name']}
        states=[g['state'] for g in scope['groups']]
    else:
        for resource in ('leitstellen','nebenleitstellen'):
            snapshot[resource]=[api.detail(resource,r['id']) for r in snapshot[resource]]
    snapshot['types']=set(json.loads((ROOT/'vehicle-types.json').read_text(encoding='utf-8-sig')))
    catalog=json.loads((ROOT/'centers-soll.json').read_text(encoding='utf-8-sig'))
    relevant=snapshot['leitstellen'] if scope else [c for c in snapshot['leitstellen'] if norm(c.get('bundesland')) in {norm(s) for s in states}]
    catalog_entries,center_research=discover_center_soll(web,relevant,snapshot['nebenleitstellen'],catalog['entries'])
    save_json(Path(options.output)/'nebenstellen-recherche.json',center_research)
    save_json(Path(options.output)/'nebenstellen-abgleich.json',compare_centers(snapshot['leitstellen'],snapshot['nebenleitstellen'],catalog_entries))
    if scope:
        completed=[];visited=set();remaining=options.max_stations
        scope['status']='running'
        save_json(scope_path,scope)
        for group in scope['groups']:
            if remaining is not None and remaining<=0:break
            run_options=argparse.Namespace(**vars(options));run_options.max_stations=remaining
            status=run_state(group['state'],group['country'],api,web,hints,provenance,run_options,snapshot)
            completed.append(status)
            visited.update(s['wache_id'] for s in status['stations'] if s.get('wache_id') is not None)
            if remaining is not None:remaining-=len(status['stations'])
            scope['pending_station_ids']=sorted(set(scope['station_ids'])-visited-{s['wache_id'] for s in scope['unclassified_stations']})
            scope['completed_groups']=[{'country':s['country'],'state':s['state'],'status':s['status'],'summary':s['summary']} for s in completed]
            save_json(scope_path,scope)
        pending=sorted(set(scope['station_ids'])-visited-{s['wache_id'] for s in scope['unclassified_stations']})
        scope['pending_station_ids']=pending
        scope['status']='partial' if pending or any(s['pending'] for s in completed) else 'processed_with_unresolved' if scope['unclassified_stations'] or scope['catalog_scope']['unresolved'] or any(s['status']!='processed' for s in completed) else 'processed'
        scope['finished_at']=now();scope['api_writes']=api.writes
        counts={k:sum(s['summary'][k] for s in completed) for k in summarize({},[])}
        counts['pending']=len(pending)+sum(sum(not isinstance(p,int) for p in s['pending']) for s in completed)
        counts['api_writes']=api.writes
        counts['outside_package']+=scope['outside_scope_count']+scope['catalog_scope']['outside']
        counts['geography_unresolved']+=len(scope['unclassified_stations'])+sum(s.get('reason')!='Mehrdeutige API-Wache' for s in scope['catalog_scope']['unresolved'])
        scope['summary']=counts
        save_json(scope_path,scope)
        overall=render_summary(dict(scope,package={'region':scope['name']}),counts)
        overall+=f"\nNicht zur gewählten Leitstelle/Nebenstellen zugeordnet: {scope['outside_scope_count']} API-Wachen.\n"
        (Path(options.output)/'leitstellen-auswertung.md').write_text(overall,encoding='utf-8')
        print('\n'+overall,flush=True)
        if api.writes:raise AssertionError('Reportlauf hat API-Schreibaktionen ausgeführt')
    else:
        for state in states:run_state(state,options.land,api,web,hints,provenance,options,snapshot)
    return 0


if __name__=='__main__':
    try:raise SystemExit(main())
    except Exception as exc:
        print(f'Abbruch (kein vollständiger Abgleich): {exc}',file=sys.stderr);raise SystemExit(1)
