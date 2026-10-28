import argparse
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from common import HISTORICAL, seal, valid_seal
from lst_api import LstApi
from extractor import extract, llm_fallback, vehicle_type
from evaluator import bos_prefilter, compare_centers, delta_vehicle, match_station
from geo import membership
from writer import Writer
from waylog import log_way
from rufnamen_mapper import prefixes
from catalogs import load_catalogs
from centers import parse_complete_soll
from sources import research_station
from agent import run_state

STATION={'id':1,'name':'Feuerwehr Stade','adresse':'Teststraße 1, 21680 Stade','country':'DE','state':'Niedersachsen','region':'Stade','town':'Stade','typ':'FF','organisation':'FF','latitude':53.6,'longitude':9.5}
SOURCE={'url':'https://feuerwehr-stade.de/fahrzeuge','kind':'operator_candidate','authority':'official','verified':True,'home_verified':True}
TEXT='Florian Stade 20/48-01 LF 10 Feuerwehr Stade'
TYPES={'LF 10','LF 20','RTW','ELW 1','LF'}


def response(data):
    r=Mock();r.json.return_value=data;r.raise_for_status.return_value=None;return r


def api_for(pages):
    session=Mock();session.request.side_effect=[response({'ok':True,'data':p}) for p in pages]
    return LstApi('https://example.test','u','p',session=session)


def page(items,total=1,number=1,size=200):
    return {'items':items,'pagination':{'page':number,'per_page':size,'total':total,'pages':(total+size-1)//size}}


class ApiTests(unittest.TestCase):
    def test_pagination_and_zero_writes(self):
        a=api_for([page([{'id':1}],2,1,1),page([{'id':2}],2,2,1)])
        self.assertEqual(len(a.list('wachen')),2);self.assertEqual(a.writes,0)
    def test_empty(self):self.assertEqual(api_for([page([],0)]).list('wachen'),[])
    def test_incomplete(self):
        with self.assertRaises(ValueError):api_for([page([],1)]).list('wachen')
    def test_duplicate_and_changed_total(self):
        for pages in ([page([{'id':1}],2,1,1),page([{'id':1}],2,2,1)],[page([{'id':1}],2,1,1),page([{'id':2}],3,2,1)]):
            with self.assertRaises(ValueError):api_for(pages).list('wachen')
    def test_report_cannot_write(self):
        with self.assertRaises(ValueError):api_for([]).request('/abgleich/aktion','POST',{})
    def test_global_scope_fails_closed(self):
        a=api_for([{'global_read':False,'guarded_writes':True}])
        with self.assertRaises(ValueError):a.global_scope()
        status={'global_read':True,'guarded_writes':True}
        a=api_for([status,page([{'id':1}],1,1,1)])
        with self.assertRaisesRegex(ValueError,'Adressmigration'):a.global_scope()
        a=api_for([status,page([{'id':1,'adresse':None}],1,1,1)])
        self.assertTrue(a.global_scope()['guarded_writes'])


class ExtractionTests(unittest.TestCase):
    def test_active_and_historical_rows(self):
        for status in ('a.D.','außer Dienst','ausser Dienst','ehem.','ehemalig','historisch','Museum','Oldtimer','inaktiv','verkauft'):
            html=f'<table><tr><td>{TEXT}</td></tr><tr><td>Florian Stade 20/49-01 LF 20 {status}</td></tr></table>'
            active,old=extract(html,STATION,SOURCE,TYPES)
            self.assertEqual([v['callsign'] for v in active],['Florian Stade 20/48-01'])
            self.assertEqual(len(old),1)
    def test_historical_heading(self):
        active,_=extract('<h2>Ehemalige Fahrzeuge</h2><table><tr><td>'+TEXT+'</td></tr></table>',STATION,SOURCE,TYPES)
        self.assertFalse(active)
    def test_trailer_and_unknown_type(self):
        self.assertFalse(extract('Anhänger Schlauch Feuerwehr Stade',STATION,SOURCE,TYPES)[0])
        active,_=extract('Florian Stade 20/48-01 Unbekannt',STATION,SOURCE,TYPES)
        self.assertFalse(active[0]['schreibfaehig'])
    def test_conflicting_types(self):
        active,_=extract(TEXT+' LF 20',STATION,SOURCE,TYPES)
        self.assertIsNone(active[0]['typ'])
    def test_legacy_subtype_is_not_shorter_type(self):
        self.assertIsNone(vehicle_type('Löschgruppenfahrzeug LF 20/16', {'LF 20'}))
        self.assertEqual(vehicle_type('Löschgruppenfahrzeug LF 20', {'LF 20'}), 'LF 20')
    def test_llm_limits_and_literal_evidence(self):
        session=Mock();session.post.return_value=response({'response':json.dumps({'vehicles':[{'callsign':'Florian Stade 20/48-01','typ':'LF 10','beleg':TEXT}]})})
        rows=llm_fallback(TEXT+' x'*2000,STATION,SOURCE,TYPES,session=session)
        payload=session.post.call_args.kwargs['json']
        self.assertEqual(payload['model'],'qwen2.5:14b');self.assertEqual(payload['keep_alive'],'10m')
        self.assertLessEqual(len(payload['prompt'].split('QUELLTEXT:\n')[1]),2000);self.assertEqual(len(rows),1)
        session.post.return_value=response({'response':json.dumps({'vehicles':[{'callsign':'Florian Stade 20/48-01','typ':'LF 10','beleg':'erfunden'}]})})
        self.assertFalse(llm_fallback(TEXT,STATION,SOURCE,TYPES,session=session))

    def test_bos_key_value_table_keeps_type_with_callsign(self):
        html='<table><tr><td>Rufname</td><td>Florian Stade 20/48-01</td></tr><tr><td>Typ</td><td>LF 10</td></tr></table>'
        active,_=extract(html,STATION,dict(SOURCE,kind='bos'),TYPES)
        self.assertEqual(active[0]['typ'],'LF 10')
    def test_model_outage_is_reported_and_not_candidate(self):
        session=Mock();session.post.side_effect=TimeoutError('model offline')
        with self.assertRaises(TimeoutError):llm_fallback(TEXT,STATION,SOURCE,TYPES,session=session)

    def test_lstsim_not_writable(self):
        active,_=extract(TEXT,STATION,dict(SOURCE,kind='lstsim'),TYPES)
        self.assertFalse(active[0]['schreibfaehig']);self.assertTrue(active[0]['nur_lstsim'])


class ScopeTests(unittest.TestCase):
    def test_bos_scope(self):
        m=dict(STATION,breadcrumb_verified=True,breadcrumb_url='https://bos-fahrzeuge.info/wachen/1')
        self.assertTrue(bos_prefilter(m,STATION))
        for field,value in [('country','AT'),('state','Brandenburg'),('region','Barnim'),('organisation','DRK'),('breadcrumb_verified',False)]:
            self.assertFalse(bos_prefilter(dict(m,**{field:value}),STATION))
    def test_bos_detail_not_fetched_without_listing(self):
        web=Mock();web.search.return_value=([{'url':'https://bos-fahrzeuge.info/einsatzfahrzeuge/123','snippet':'Stade','title':'Feuerwehr Stade'}],[])
        research_station(web,STATION,TYPES,use_llm=False,bos_first=False)
        web.get.assert_not_called()
    def test_wrong_breadcrumb_prevents_detail_fetch(self):
        web=Mock();web.search.return_value=([{'url':'https://bos-fahrzeuge.info/wachen/123','snippet':'Stade','title':'Feuerwehr Stade'}],[])
        web.get.return_value='<nav class="breadcrumb">Bayern München</nav><p>Freiwillige Feuerwehr</p><a href="/einsatzfahrzeuge/7">LF</a>'
        research_station(web,STATION,TYPES,use_llm=False,bos_first=False)
        self.assertEqual(web.get.call_count,1)
    def test_geography(self):
        geo={'type':'Polygon','coordinates':[[[0,0],[2,0],[2,2],[0,2],[0,0]]]}
        for lat,lon,result in [(1,1,'inside'),(1,0,'boundary'),(3,3,'outside')]:self.assertEqual(membership({'latitude':lat,'longitude':lon},geo),result)
        self.assertEqual(membership({},geo),'unresolved')
        listed=[{'type':'Feature','geometry':geo,'properties':{}}]
        self.assertEqual(membership({'latitude':1,'longitude':1},listed),'inside')
        self.assertEqual(membership({'latitude':3,'longitude':3},listed),'outside')
    def test_centers_unknown_is_not_empty_soll(self):
        row=compare_centers([{'id':1,'relations':{'nebenleitstellen':[3]}}],[{'id':3}],[])[0]
        self.assertIsNone(row['diff']);self.assertIsNone(row['soll-Nebenstellen'])

    def test_explicit_complete_soll_can_find_missing_secondary(self):
        html='<h1>Leitstelle Test</h1><h2>Alle Nebenleitstellen</h2><table><tr><th>Nebenleitstelle</th><th>Status</th></tr><tr><td>Nebenstelle Neu</td><td>aktiv</td></tr></table>'
        center={'id':1,'name':'Leitstelle Test','relations':{'nebenleitstellen':[]}}
        verified=parse_complete_soll(html,center,[],'https://landkreis-test.de/')
        report=compare_centers([center],[],[verified])[0]
        self.assertEqual(report['diff']['fehlend'],[{'id':None,'name':'Nebenstelle Neu'}])

    def test_centers_ambiguous(self):
        entry={'leitstelle_id':1,'verified':True,'active':True,'source_url':'https://example.test','nebenstellen':[{'id':3}]}
        self.assertIsNone(compare_centers([{'id':1,'relations':{'nebenleitstellen':[3]}}],[{'id':3}],[entry,entry])[0]['diff'])
    def test_mapping_region_scoped(self):
        self.assertIn('Florian Spreewald',prefixes('Brandenburg','Dahme-Spreewald'))
        self.assertIn('Florian München',prefixes('Bayern','München',['Florian München 1/40-1']))
        self.assertNotIn('Florian München',prefixes('Bayern','Nürnberg'))
    def test_supplied_catalogs_are_hints(self):
        rows,origins=load_catalogs();self.assertEqual(len(origins),5);self.assertGreater(len(rows),50)
        self.assertTrue(all('hints' in r for r in rows));self.assertFalse(any(r.get('verified') for r in rows))


class WriteTests(unittest.TestCase):
    def entry(self):
        return seal({'action':'ADD','wache_id':1,'vehicle_id':None,'before':None,'after':{'callsign':'Florian Stade 20/48-01','type':'LF 10'},'beleg':TEXT,'quelle':SOURCE['url'],'home_verified':True,'schreibfaehig':True,'nur_lstsim':False})
    def writer(self,tmp,vehicles=()):
        api=Mock();api.list.return_value=list(vehicles);api.detail.return_value=STATION;api.request.return_value={'id':4}
        web=Mock();web.get.return_value=TEXT
        return Writer(api,web,Path(tmp)/'writes.log')
    def test_content_bound_approval(self):
        e=self.entry();e['after']['type']='LF 20';self.assertFalse(valid_seal(e))
        with tempfile.TemporaryDirectory() as tmp:
            writer=self.writer(tmp)
            with self.assertRaises(ValueError):writer.apply(e)
            writer.api.request.assert_not_called()
    def test_duplicates_same_and_other_station(self):
        for station in (1,2):
            with tempfile.TemporaryDirectory() as tmp:
                writer=self.writer(tmp,[{'id':5,'wache_id':station,'rufname':'florian stade 20-48/01'}])
                r=writer.apply(self.entry());self.assertTrue(r['status'].startswith('skipped_'));writer.api.request.assert_not_called()
    def test_reloads_for_each_write(self):
        with tempfile.TemporaryDirectory() as tmp:
            writer=self.writer(tmp);writer.apply(self.entry());writer.apply(self.entry());self.assertEqual(writer.api.list.call_count,2)
    def test_timeout_no_retry(self):
        with tempfile.TemporaryDirectory() as tmp:
            writer=self.writer(tmp);writer.api.request.side_effect=TimeoutError('timeout')
            r=writer.apply(self.entry());self.assertEqual(r['status'],'write_result_requires_review');self.assertEqual(writer.api.request.call_count,1);self.assertEqual(writer.api.list.call_count,2)
    def test_incomplete_global_inventory_blocks(self):
        with tempfile.TemporaryDirectory() as tmp:
            writer=self.writer(tmp);writer.api.list.side_effect=ValueError('incomplete')
            with self.assertRaises(ValueError):writer.apply(self.entry())
            writer.api.request.assert_not_called()
    def test_stale_update(self):
        with tempfile.TemporaryDirectory() as tmp:
            e=self.entry();e.update(action='UPDATE',vehicle_id=5,before={'wache_id':1,'rufname':e['after']['callsign'],'fahrzeugtyp':'LF 20','status':'frei','fms_status':'2'});e=seal(e)
            writer=self.writer(tmp);writer.api.detail.return_value={'wache_id':2}
            with self.assertRaises(ValueError):writer.apply(e)
            writer.api.request.assert_not_called()
    def test_set_ad_separate_from_active_candidates(self):
        active,old=extract(TEXT+' außer Dienst',STATION,SOURCE,TYPES)
        existing=[{'id':8,'wache_id':1,'rufname':'Florian Stade 20/48-01','fahrzeugtyp':'LF 10','status':'frei','fms_status':'2'}]
        delta,_=delta_vehicle(STATION,active,old,existing,'today')
        self.assertFalse(active);self.assertEqual([e['action'] for e in delta],['SET_AD'])


class IntegrationTests(unittest.TestCase):
    def test_waylog_append(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp)/'way.log';s=dict(STATION,name='way/123')
            log_way(p,s);log_way(p,s,'Adresse','https://example.test',True)
            rows=[json.loads(r) for r in p.read_text(encoding='utf-8').splitlines()]
            self.assertEqual([r['status'] for r in rows],['ungeklärt','aufgelöst'])
    def test_stade_empty_and_occupied_stations(self):
        with tempfile.TemporaryDirectory() as tmp:
            opts=argparse.Namespace(output=tmp,package=None,max_stations=None,max_pages=3,no_llm=True)
            api=Mock();api.base='https://example.test/wp-json/lst/v1';api.writes=0;api.station_vehicles.return_value=[]
            web=Mock();web.reverse.return_value={'country':'DE','state':'Niedersachsen','region':'Stade','town':'Stade','adresse':'Teststraße 1, 21680 Stade','url':'https://example.test/reverse'}
            stations=[dict(STATION,id=i,name='Feuerwehr Stade '+str(i),typ='FFW',land='Deutschland',bundesland='Niedersachsen') for i in (1,2)]
            snapshot={'wachen':stations,'fahrzeuge':[{'id':10,'wache_id':2,'rufname':'vorhanden'}],'leitstellen':[],'nebenleitstellen':[],'types':TYPES}
            with patch('agent.research_station',return_value=([],[],[],{})):
                status=run_state('Niedersachsen','DE',api,web,[],[],opts,snapshot)
            self.assertEqual(api.station_vehicles.call_count,2);self.assertEqual(status['api_writes'],0);self.assertFalse(status['pending']);api.request.assert_not_called()
    def test_reverse_failure_is_unresolved(self):
        with tempfile.TemporaryDirectory() as tmp:
            opts=argparse.Namespace(output=tmp,package=None,max_stations=None,max_pages=3,no_llm=True)
            api=Mock();api.base='https://example.test';api.writes=0
            web=Mock();web.reverse.side_effect=ValueError('no geocode')
            snapshot={'wachen':[dict(STATION,name='way/123',typ='FFW',land='Deutschland',bundesland='Niedersachsen')],'fahrzeuge':[],'leitstellen':[],'nebenleitstellen':[],'types':TYPES}
            status=run_state('Niedersachsen','DE',api,web,[],[],opts,snapshot)
            self.assertEqual(status['stations'][0]['status'],'unresolved');self.assertTrue((Path(tmp)/'way.log').exists());api.request.assert_not_called()


if __name__=='__main__':unittest.main()
