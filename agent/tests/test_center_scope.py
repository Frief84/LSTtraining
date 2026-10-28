"""Center selection covers explicit relations across state borders without widening the scope."""
import contextlib
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock,patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from center_scope import load_center_scope,select_center,scope_catalogs
from agent import main

CENTER={'id':1,'name':'IRLS Nordwest','land':'Deutschland','bundesland':'Brandenburg','relations':{'nebenleitstellen':[10,11],'wachen':[1,2]}}
SUBS=[{'id':10,'name':'Teil A','relations':{'wachen':[2,3]}},{'id':11,'name':'Teil B','relations':{'wachen':[3]}}]
def station(ident,state='Brandenburg'):
    return {'id':ident,'name':'FF Test '+str(ident),'typ':'FFW','land':'Deutschland','bundesland':state,'latitude':52+ident/100,'longitude':13,'adresse':'Teststrasse 1'}
STATIONS=[station(1),station(2),station(3,'Berlin'),station(4)]
def api_fixture():
    api=Mock();api.base='https://example.test';api.writes=0
    api.global_scope.return_value={'global_read':True,'guarded_writes':True}
    data={'wachen':STATIONS,'fahrzeuge':[{'id':7,'wache_id':4,'rufname':'Florian Test 1/19-01','fahrzeugtyp':'MTW'}],'leitstellen':[CENTER],'nebenleitstellen':SUBS}
    api.list.side_effect=lambda resource:data[resource]
    details={('leitstellen',1):CENTER,('nebenleitstellen',10):SUBS[0],('nebenleitstellen',11):SUBS[1]}
    api.detail.side_effect=lambda resource,ident:details[(resource,ident)]
    api.station_vehicles.return_value=[]
    return api

class CenterScopeTests(unittest.TestCase):
    def test_union_deduplicates_and_includes_other_states(self):
        api=api_fixture();scope,_,subs=load_center_scope(api,'Nordwest',[CENTER],SUBS,STATIONS)
        self.assertEqual(scope['station_ids'],[1,2,3])
        self.assertEqual(scope['outside_scope_count'],1)
        self.assertEqual({g['state'] for g in scope['groups']},{'Brandenburg','Berlin'})
        self.assertEqual(len(subs),2);api.request.assert_not_called()

    def test_ambiguous_name_and_incomplete_relations_fail(self):
        with self.assertRaises(ValueError):select_center('Nordwest',[CENTER,dict(CENTER,id=2)])
        self.assertEqual(select_center('1',[CENTER,dict(CENTER,id=2)])['id'],1)
        for rows in (STATIONS[:2],):
            with self.assertRaises(ValueError):load_center_scope(api_fixture(),'1',[CENTER],SUBS,rows)
        api=api_fixture();api.detail.side_effect=lambda resource,ident:dict(CENTER,relations={})
        with self.assertRaises(ValueError):load_center_scope(api,'1',[CENTER],SUBS,STATIONS)
        with self.assertRaises(ValueError):load_center_scope(api_fixture(),'1',[CENTER],SUBS[:1],STATIONS)

    def test_missing_country_or_state_is_explicitly_unclassified(self):
        rows=[dict(s,bundesland=None) if s['id']==3 else s for s in STATIONS]
        scope,_,_=load_center_scope(api_fixture(),'1',[CENTER],SUBS,rows)
        self.assertEqual(scope['station_count'],3)
        self.assertEqual(scope['unclassified_stations'][0]['wache_id'],3)

    def test_catalog_outside_scope_cannot_widen_selection(self):
        scope,_,_=load_center_scope(api_fixture(),'1',[CENTER],SUBS,STATIONS)
        hints=[{'name':'FF Test '+str(i),'country':'DE','state':'Brandenburg','region':'Test','town':'Test','typ':'FF','coordinates':{},'adresse':None} for i in (1,4)]
        web=Mock()
        chosen=scope_catalogs(hints,STATIONS,scope,[CENTER]+SUBS,web)
        self.assertEqual([h['name'] for h in chosen],['FF Test 1'])
        self.assertEqual(scope['catalog_scope']['outside'],1)
        web.forward.assert_not_called()

    def run_main(self,tmp,extra=(),active=None):
        api=api_fixture();web=Mock()
        web.reverse.side_effect=lambda lat,lon:{'country':'DE','state':'Berlin' if lat>52.025 else 'Brandenburg','region':'Test','town':'Test','adresse':'Teststrasse 1','url':'https://example.test/reverse'}
        with patch('agent.LstApi',return_value=api),patch('agent.PublicWeb',return_value=web),patch('agent.load_catalogs',return_value=([],[])),patch('agent.discover_center_soll',return_value=([],[])),patch('agent.research_station',return_value=(active or [],[],[],{})) as research,contextlib.redirect_stdout(io.StringIO()):
            code=main(['--leitstelle','1','--output',tmp,'--no-llm',*extra])
        return code,api,web,research

    def test_scope_only_never_fetches_vehicles_or_sources(self):
        with tempfile.TemporaryDirectory() as tmp:
            code,api,web,research=self.run_main(tmp,['--scope-only'])
            self.assertEqual(code,0);research.assert_not_called();web.get.assert_not_called();web.search.assert_not_called()
            self.assertNotIn('fahrzeuge',[c.args[0] for c in api.list.call_args_list])
            self.assertTrue((Path(tmp)/'leitstellen-vorschau.json').exists())
            self.assertFalse((Path(tmp)/'leitstellen-umfang.json').exists())

    def test_full_scope_checks_each_station_once_and_global_conflicts(self):
        vehicle={'callsign':'Florian Test 1/19-01','typ':'MTW','beleg':'Florian Test 1/19-01 MTW','quelle':'https://example.test/vehicle','authority':'secondary','home_verified':True,'schreibfaehig':True,'nur_lstsim':False}
        with tempfile.TemporaryDirectory() as tmp:
            code,api,_,research=self.run_main(tmp,active=[vehicle])
            self.assertEqual(code,0)
            self.assertEqual([c.args[0] for c in api.station_vehicles.call_args_list],[1,2,3])
            scope=json.loads((Path(tmp)/'leitstellen-umfang.json').read_text(encoding='utf-8'))
            self.assertEqual(scope['summary']['api_stations_researched'],3)
            self.assertEqual(scope['summary']['callsign_conflicts'],3)
            self.assertEqual(scope['summary']['change_proposals'],0)
            self.assertEqual(scope['summary']['outside_package'],1)
            self.assertEqual(scope['pending_station_ids'],[])
            self.assertTrue((Path(tmp)/'leitstellen-auswertung.md').exists())
            api.request.assert_not_called()

    def test_max_stations_is_global_across_groups(self):
        with tempfile.TemporaryDirectory() as tmp:
            _,api,_,research=self.run_main(tmp,['--max-stations','1'])
            self.assertEqual(research.call_count,1)
            scope=json.loads((Path(tmp)/'leitstellen-umfang.json').read_text(encoding='utf-8'))
            self.assertEqual(scope['status'],'partial')
            self.assertEqual(scope['pending_station_ids'],[2,3])
            self.assertEqual(scope['summary']['pending'],2)

    def test_selected_station_retry_stays_inside_center_and_does_not_research_others(self):
        with tempfile.TemporaryDirectory() as tmp:
            code,api,_,research=self.run_main(tmp,['--wache-id','1','--wache-id','3'])
            self.assertEqual(code,0)
            self.assertEqual([c.args[0] for c in api.station_vehicles.call_args_list],[1,3])
            self.assertEqual(research.call_count,2)
            scope=json.loads((Path(tmp)/'leitstellen-umfang.json').read_text(encoding='utf-8'))
            self.assertEqual(scope['selected_station_ids'],[1,3])
            self.assertEqual(scope['station_count'],2)
            self.assertEqual(scope['pending_station_ids'],[])
            with self.assertRaisesRegex(ValueError,'gehört nicht zur Leitstelle'):
                self.run_main(tmp,['--wache-id','4'])
