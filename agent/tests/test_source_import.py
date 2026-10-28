"""Municipal source hints cannot widen scope or become vehicle evidence by rating."""
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from sources import registry_source_matches,research_station,usable_source_url

ROOT=Path(__file__).resolve().parents[1]
ORIGIN='source-inputs/2026-10-07-behoerdenquellen.json'
STATION={'id':1,'name':'Feuerwehr Potsdam Wache 1','adresse':'Teststraße 1, 14467 Potsdam','country':'DE','state':'Brandenburg','region':'Potsdam','town':'Potsdam','typ':'BF','organisation':'BF'}


class ImportedSourceTests(unittest.TestCase):
    def sources(self):
        return [s for s in json.loads((ROOT/'source-registry.json').read_text(encoding='utf-8-sig'))['sources'] if s.get('origin')==ORIGIN]

    def test_provenance_scope_and_repair_counts(self):
        rows=self.sources()
        self.assertEqual(len(rows),30)
        self.assertEqual(len({r['state'] for r in rows}),16)
        self.assertEqual(sum(r['enabled'] for r in rows),30)
        self.assertEqual(sum(r['url_status']=='official_replacement_found' for r in rows),5)
        self.assertTrue(all(r['verified'] is False and r['origin_sha256'] for r in rows))
        self.assertEqual({r['towns'][0] for r in rows if not r['enabled']},set())

    def test_second_submission_does_not_duplicate_or_degrade_urls(self):
        rows=self.sources()
        self.assertTrue(all(len(s['submissions'])==2 for s in rows))
        magdeburg=next(s for s in rows if s['towns']==['Magdeburg'])
        self.assertTrue(usable_source_url(magdeburg['url']))
        self.assertIn('[STRIPPED',magdeburg['submissions'][1]['url'])

    def test_verified_url_corrections_preserve_better_rostock_source(self):
        rows=self.sources()
        dresden=next(s for s in rows if s['towns']==['Dresden'])
        self.assertTrue(dresden['enabled']);self.assertTrue(dresden['url'].endswith('/c_01.php'))
        self.assertFalse(dresden['verified'])
        rostock=next(s for s in rows if s['towns']==['Rostock'])
        self.assertTrue(rostock['url'].endswith('/350502'))
        self.assertEqual(rostock['url_checks'][0]['content_status'],'invalid_template')

    def test_html_corrections_preserve_provenance_and_unresolved_fetch(self):
        origin='source-inputs/2026-10-07-html-url-korrekturen.json'
        supplied=json.loads((ROOT/origin).read_text(encoding='utf-8'))['sources']
        self.assertEqual(len(supplied),7)
        for row in supplied:
            self.assertTrue(usable_source_url(row['url']))
            registered=next(s for s in self.sources() if row['town'] in s['towns'])
            self.assertTrue(any(c['url']==row['url'] and c['origin']==origin for c in registered['url_checks']))
            self.assertFalse(registered['verified'])
        leipzig=next(s for s in self.sources() if s['towns']==['Leipzig'])
        self.assertTrue(leipzig['enabled'])
        self.assertTrue(usable_source_url(leipzig['url']))
        self.assertFalse(leipzig['publisher_verified'])
        self.assertEqual(leipzig['url_status'],'complete_url_fetch_failed')
        magdeburg=next(s for s in self.sources() if s['towns']==['Magdeburg'])
        self.assertIn('object=tx%2C37.21825.1',magdeburg['url'])
        saar=next(s for s in self.sources() if 'Saarbrücken' in s['towns'])
        self.assertNotIn('/le/',saar['url'])

    def test_source_applies_only_to_municipality_and_type(self):
        source=next(s for s in self.sources() if s['towns']==['Potsdam'])
        self.assertTrue(registry_source_matches(source,STATION))
        for values in ({'country':'AT'},{'state':'Berlin'},{'town':'Cottbus'},{'town':''},{'typ':'WF'}):
            self.assertFalse(registry_source_matches(source,dict(STATION,**values)))

    def test_incomplete_urls_are_never_fetched(self):
        self.assertFalse(usable_source_url('https://www.dresden.[STRIPPED 93 bytes].php'))
        self.assertFalse(usable_source_url('https://user:password@example.org/'))
        web=Mock();web.search.return_value=([],[])
        broken=dict(next(s for s in self.sources() if s['towns']==['Leipzig']))
        broken['url']=broken['original_url']
        station=dict(STATION,state='Sachsen',region='Leipzig',town='Leipzig',urls=[broken['url']])
        research_station(web,station,{'LF 10'},extra_sources=[broken],use_llm=False,bos_first=False)
        web.get.assert_not_called()

    def test_existing_supplemental_filter_stays_strict(self):
        source={'url':'https://example.org','country':'DE','state':'Brandenburg','towns':['Potsdam'],'regions':[],'station_types':['BF'],'role':'discovery'}
        self.assertFalse(registry_source_matches(source,STATION))

    def test_overview_is_navigation_not_evidence(self):
        url='https://www.potsdam.de/standorte'
        source={'url':url,'country':'DE','state':'Brandenburg','towns':['Potsdam'],'station_types':['BF'],'role':'operator_discovery','declared_rating':'ja','verified':False}
        web=Mock();web.search.return_value=([],[])
        web.get.side_effect=['<h1>Alle Standorte</h1><p>Florian Potsdam 1/10-1 LF 10</p><a href="/feuerwache-1">Feuerwehr Potsdam Wache 1</a><a href="https://unrelated.test/feuerwache">Feuerwache</a>','<h1>Keine Belege</h1>']
        active,retired,audit,_=research_station(web,STATION,{'LF 10'},extra_sources=[source],use_llm=False,bos_first=False)
        self.assertEqual([c.args[0] for c in web.get.call_args_list],[url,'https://www.potsdam.de/feuerwache-1'])
        self.assertFalse(active);self.assertFalse(retired)
        self.assertFalse(audit[0]['auswertbar'])


if __name__=='__main__':unittest.main()
