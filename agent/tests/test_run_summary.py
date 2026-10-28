"""Package filtering precedes research, limits and console progress."""
import argparse
import contextlib
import io
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from agent import run_state
from report_summary import summarize, render_summary

class PackageRunTests(unittest.TestCase):
    def run_fixture(self, directory, limit=None, all_outside=False):
        geometry={'type':'Polygon','coordinates':[[[0,0],[2,0],[2,2],[0,2],[0,0]]]}
        package=Path(directory)/'package.json'
        package.write_text(json.dumps({'country':'DE','state':'Testland','region':'Testgebiet','geometry':geometry,'geometry_source':'test fixture'}))
        opts=argparse.Namespace(output=directory,package=str(package),max_stations=limit,max_pages=3,no_llm=True)
        def station(ident,name,lat,lon):return {'id':ident,'name':name,'land':'Deutschland','bundesland':'Testland','typ':'FFW','latitude':lat,'longitude':lon}
        rows=[station(1,'Ausserhalb',4,4)]
        if not all_outside:
            rows += [station(2,'Innen A',1,1),station(3,'Innen B',1.5,1.5),station(4,'Koordinaten fehlen',None,None),station(5,'Grenzfall',1,0)]
        api=Mock();api.base='https://example.test';api.writes=0;api.station_vehicles.return_value=[]
        web=Mock();web.reverse.return_value={'country':'DE','state':'Testland','region':'Testgebiet','town':'Testort','adresse':'Teststrasse 1','url':'https://example.test/reverse'}
        snap={'wachen':rows,'fahrzeuge':[],'leitstellen':[],'nebenleitstellen':[],'types':set()}
        console=io.StringIO()
        with patch('agent.research_station',return_value=([],[],[],{})) as research,contextlib.redirect_stdout(console):
            status=run_state('Testland','DE',api,web,[],[],opts,snap)
        return status,api,web,research,console.getvalue()

    def test_only_inside_stations_enter_research_or_progress(self):
        with tempfile.TemporaryDirectory() as tmp:
            status,api,web,research,console=self.run_fixture(tmp)
            self.assertEqual([c.args[0] for c in api.station_vehicles.call_args_list],[2,3])
            self.assertEqual(research.call_count,2);self.assertEqual(web.reverse.call_count,2)
            self.assertIn('[Testgebiet] 1/2 Innen A',console)
            self.assertIn('[Testgebiet] 2/2 Innen B',console)
            self.assertNotIn('Ausserhalb',console);self.assertNotIn('Grenzfall',console)
            summary=status['summary']
            self.assertEqual(summary['api_stations_researched'],2)
            self.assertEqual(summary['outside_package'],1)
            self.assertEqual(summary['geography_unresolved'],2)
            self.assertEqual(summary['api_writes'],0)
            self.assertEqual(status['status'],'processed_with_unresolved')
            report=json.loads((Path(tmp)/'Testland.json').read_text(encoding='utf-8'))
            self.assertNotIn('Ausserhalb',str(report));self.assertNotIn('Koordinaten fehlen',str(report))
            self.assertTrue((Path(tmp)/'Testland-auswertung.md').exists())
            self.assertIn('| Gefundene Fahrzeugbelege / Änderungsvorschläge | 0 / 0 |',console)
            api.request.assert_not_called()

    def test_limit_counts_only_package_stations(self):
        with tempfile.TemporaryDirectory() as tmp:
            status,api,web,research,console=self.run_fixture(tmp,limit=1)
            self.assertEqual(research.call_count,1)
            self.assertEqual(api.station_vehicles.call_args.args,(2,))
            self.assertEqual(status['pending'],[3]);self.assertEqual(status['status'],'partial')
            self.assertEqual(status['summary']['pending'],1)
            self.assertEqual(status['summary']['api_stations_researched'],1)

    def test_empty_package_has_summary_and_no_searches(self):
        with tempfile.TemporaryDirectory() as tmp:
            status,api,web,research,console=self.run_fixture(tmp,all_outside=True)
            research.assert_not_called();web.reverse.assert_not_called();api.station_vehicles.assert_not_called()
            self.assertEqual(status['summary']['outside_package'],1)
            self.assertEqual(status['summary']['api_stations_researched'],0)
            self.assertIn('| API-Schreibaktionen | 0 |',console)

    def test_summary_counts_evidence_proposals_and_legacy_geography_separately(self):
        status={'state':'Test','status':'partial','api_writes':0,'pending':[9],
                'stations':[{'wache_id':1,'status':'processed','active_count':3},
                            {'wache_id':None,'status':'processed','active_count':1},
                            {'status':'outside'}, {'status':'boundary','stage':'geography'},
                            {'status':'unresolved','reason':'Adresse nicht eindeutig geokodierbar'},
                            {'status':'unresolved','stage':'inventory','reason':'API error'},
                            {'status':'unresolved','stage':'prepare','reason':'Reverse-Geocoding widerspricht Land/Bundesland'}],
                'unresolved':[{'reason':'Rufname an anderer Wache'}]}
        counts=summarize(status,[{'action':'ADD'},{'action':'UPDATE'}])
        self.assertEqual(counts['api_stations_researched'],1)
        self.assertEqual(counts['catalog_hints_researched'],1)
        self.assertEqual(counts['geography_unresolved'],3)
        self.assertEqual(counts['callsign_conflicts'],1)
        self.assertIn('| Gefundene Fahrzeugbelege / Änderungsvorschläge | 4 / 2 |',render_summary(status,counts))
