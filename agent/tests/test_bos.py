"""BOS-first integration and negative pre-fetch gates using representative HTML."""
import sys
import unittest
from pathlib import Path
from unittest.mock import Mock, patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from bos import research_bos, scope_ok
from sources import research_station
from evaluator import delta_vehicle
from extractor import callsigns

STATION={'id':54,'name':'[P] FF Potsdam-Babelsberg-Klein Glienicke','adresse':'Tuchmacherstraße 30, 14482 Potsdam','country':'DE','state':'Brandenburg','region':'Potsdam','town':'Potsdam','typ':'FF','organisation':'FF'}
ROOT='https://bos-fahrzeuge.info'
LIST=ROOT+'/wachen/23518/FF_Potsdam-BabelsbergKlein_Glienicke'
DETAIL=ROOT+'/einsatzfahrzeuge/100879/Florian_Potsdam_0719-01/tab/detail'
LOCATION='Europa › Deutschland (Germany) › Brandenburg › Potsdam (P)'
ORG='Freiwillige Feuerwehr (FF)'
def row(label,value):return '<tr><th>'+label+'</th><td>'+value+'</td></tr>'
LISTING='<h1>Wache: FF Potsdam-Babelsberg/Klein Glienicke</h1><table class="summary">'+row('Ort',LOCATION)+row('Organisation',ORG)+row('Adresse','Tuchmacherstraße 30<br>14482 Potsdam')+'</table><a href="'+LIST+'/einsatzfahrzeuge">Einsatzfahrzeuge</a>'
def card(ident='100879',name='Florian Potsdam 07/19-01',location=LOCATION,org=ORG):
    return '<table><tr><th><a href="/einsatzfahrzeuge/'+ident+'/Florian_Potsdam_0719-01">'+name+'</a></th></tr><tr><td>Standort: '+location+'</td></tr><tr><td>Organisation: '+org+'</td></tr></table>'
FLEET='<div id="vehicles-list"><div class="list-separator">Fahrzeuge/Einsatzmittel aktiv</div>'+card()+'<div class="list-separator">Fahrzeuge/Einsatzmittel a.D.</div>'+card('2','Florian Potsdam 07/23-01')+'</div>'
VEHICLE='<aside>Oldtimer Museum</aside><table class="summary">'+row('Funkrufname','Florian Potsdam 07/19-01')+row('Standort',LOCATION)+row('Organisation',ORG)+row('Wache','<a href="'+LIST+'">FF Potsdam-Babelsberg/Klein Glienicke</a>')+row('Beschreibung','Mannschaftstransportwagen (MTW) der Feuerwehr')+row('Außerdienststellung','k.A.')+'</table>'
def web_for(listing=LISTING,fleet=FLEET,detail=VEHICLE):
    pages={ROOT+'/wachen/suche/Potsdam':'<h1>Wachen-Suche</h1><a href="'+LIST+'">FF Potsdam-Babelsberg/Klein Glienicke</a>',LIST:listing,LIST+'/einsatzfahrzeuge':fleet,DETAIL:detail}
    web=Mock();web.get.side_effect=lambda url: pages[url];web.search.return_value=([],[])
    return web

class BosFirstTests(unittest.TestCase):
    def test_direct_bos_skips_other_searches_and_filters_historical_cards(self):
        web=web_for()
        active,retired,audit,_=research_station(web,STATION,{'MTW'},use_llm=False)
        self.assertEqual([v['callsign'] for v in active],['Florian Potsdam 07/19-01'])
        self.assertEqual(active[0]['typ'],'MTW')
        self.assertTrue(active[0]['schreibfaehig'])
        self.assertFalse(retired)
        web.search.assert_not_called()
        self.assertEqual(web.get.call_count,4)
        self.assertFalse(any('/einsatzfahrzeuge/2/' in c.args[0] for c in web.get.call_args_list))

    def test_wrong_region_state_country_and_organization_block_details(self):
        for old,new in [('Deutschland (Germany)','Österreich'),('Brandenburg','Bayern'),('Potsdam (P)','Potsdam-Mittelmark'),(ORG,'Deutsches Rotes Kreuz')]:
            with self.subTest(new=new):
                web=web_for(listing=LISTING.replace(old,new))
                active,_=research_bos(web,STATION,{'MTW'})
                self.assertFalse(active)
                self.assertFalse(any('/einsatzfahrzeuge' in c.args[0] for c in web.get.call_args_list))

    def test_wrong_address_and_unknown_org_block_details(self):
        for station in (dict(STATION,adresse='Andere Straße 1, 14482 Potsdam'),dict(STATION,organisation=None)):
            web=web_for();active,_=research_bos(web,station,{'MTW'})
            self.assertFalse(active)
            self.assertFalse(any('/einsatzfahrzeuge' in c.args[0] for c in web.get.call_args_list))

    def test_reverse_address_without_house_needs_exact_name(self):
        from bos import station_identity
        street=dict(STATION,adresse='Tuchmacherstraße, 14482 Potsdam')
        self.assertTrue(station_identity(LISTING,street))
        self.assertFalse(station_identity(LISTING,dict(street,name='FF Potsdam')))
        self.assertFalse(station_identity(LISTING,dict(STATION,adresse='Tuchmacherstraße 31, 14482 Potsdam')))

    def test_vehicle_card_wrong_org_prevents_fetch(self):
        web=web_for(fleet=FLEET.replace(ORG,'Deutsches Rotes Kreuz'))
        active,_=research_bos(web,STATION,{'MTW'})
        self.assertFalse(active)
        self.assertFalse(any('/tab/detail' in c.args[0] for c in web.get.call_args_list))

    def test_other_home_on_detail_is_rejected(self):
        web=web_for(detail=VEHICLE.replace('/wachen/23518/','/wachen/999/'))
        self.assertFalse(research_bos(web,STATION,{'MTW'})[0])

    def test_all_historical_markers_prevent_card_fetch(self):
        for marker in ('a.D.','außer Dienst','ausser Dienst','ehem.','ehemalig','historisch','Museum','inaktiv','verkauft'):
            web=web_for(fleet='<div id="vehicles-list">'+card(name='Florian Potsdam 07/19-01 '+marker)+'</div>')
            self.assertFalse(research_bos(web,STATION,{'MTW'})[0])
            self.assertFalse(any('/tab/detail' in c.args[0] for c in web.get.call_args_list))

    def test_retirement_date_and_unknown_type(self):
        self.assertFalse(research_bos(web_for(detail=VEHICLE.replace('k.A.','2020')),STATION,{'MTW'})[0])
        active,_=research_bos(web_for(),STATION,{'LF 10'})
        self.assertIsNone(active[0]['typ']);self.assertFalse(active[0]['schreibfaehig'])

    def test_empty_bos_triggers_secondary_discovery(self):
        web=web_for(fleet='<div id="vehicles-list"></div>')
        with patch('sources._research_fallback',return_value=([],[],[],{})) as fallback:
            research_station(web,STATION,{'MTW'},use_llm=False)
            fallback.assert_called_once()

    def test_found_vehicle_compared_to_existing_and_other_station(self):
        active,_=research_bos(web_for(),STATION,{'MTW'})
        existing={'id':7,'wache_id':54,'rufname':'Florian Potsdam 07-19/01','fahrzeugtyp':'MTW'}
        self.assertEqual(delta_vehicle(STATION,active,[],[existing],'now'),([],[]))
        missing,_=delta_vehicle(STATION,active,[],[],'now')
        self.assertEqual([e['action'] for e in missing],['ADD'])
        delta,conflicts=delta_vehicle(STATION,active,[],[dict(existing,wache_id=55)],'now')
        self.assertFalse(delta);self.assertEqual(len(conflicts),1)

    def test_limit_visible_and_wildcards_not_truncated(self):
        active,audit=research_bos(web_for(),STATION,{'MTW'},max_pages=3)
        self.assertFalse(active)
        self.assertTrue(any('Abruflimit' in a['befund'] for a in audit))
        self.assertFalse(callsigns('Florian Potsdam 07/19-xx'))
        self.assertFalse(callsigns('Florian Potsdam 01/59-0x'))
