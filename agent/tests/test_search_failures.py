"""Blocked providers remain visible without repeated challenge requests."""
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock
from urllib.parse import urlparse, urlencode
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from common import digest, save_json
from research import PublicWeb, SearchBlocked
from sources import research_station

CHALLENGE='<html><form id="anomaly-form">Bot challenge captcha</form></html>'
RSS='<rss><channel><item><title>Feuerwehr Potsdam</title><link>https://example.test/fahrzeuge</link></item></channel></rss>'

class SearchFailureTests(unittest.TestCase):
    def test_blocked_ddg_is_skipped_while_bing_continues(self):
        with tempfile.TemporaryDirectory() as tmp:
            web=PublicWeb(tmp, delay=0)
            web.get=Mock(side_effect=lambda url: RSS if urlparse(url).hostname=='www.bing.com' else CHALLENGE)
            first, errors=web.search('Potsdam Fahrzeuge')
            second, skipped=web.search('Potsdam Feuerwehr')
            self.assertEqual(first, second)
            self.assertEqual(len(first),1)
            self.assertEqual(errors[0]['code'],'provider_blocked')
            self.assertTrue(skipped[0]['skipped'])
            self.assertEqual(sum(urlparse(c.args[0]).hostname=='lite.duckduckgo.com' for c in web.get.call_args_list),1)
            self.assertEqual(sum(urlparse(c.args[0]).hostname=='www.bing.com' for c in web.get.call_args_list),2)

    def test_challenges_are_not_cached_and_old_challenge_cache_can_recover(self):
        with tempfile.TemporaryDirectory() as tmp:
            url='https://lite.duckduckgo.com/lite/?'+urlencode({'q':'Potsdam'})
            session=Mock();reply=Mock();reply.encoding='utf-8';reply.headers={};reply.url=url
            reply.iter_content.return_value=[CHALLENGE.encode()];session.get.return_value=reply
            web=PublicWeb(tmp,session=session,delay=0)
            with self.assertRaises(SearchBlocked):web.get(url)
            cache=Path(tmp)/(digest(url)+'.json')
            self.assertFalse(cache.exists())
            save_json(cache,{'text':CHALLENGE})
            with self.assertRaises(SearchBlocked):PublicWeb(tmp,session=session,offline=True).get(url)
            self.assertEqual(session.get.call_count,1)
            valid='<a class="result-link" href="https://example.test/fahrzeuge">Feuerwehr Potsdam</a>'
            reply.iter_content.return_value=[valid.encode()]
            self.assertEqual(web.get(url),valid)
            self.assertEqual(session.get.call_count,2)

    def test_known_sources_are_fetched_and_search_errors_are_structured(self):
        station={'name':'Feuerwehr Potsdam','country':'DE','state':'Brandenburg','region':'Potsdam','town':'Potsdam','typ':'BF','adresse':'Teststrasse 1','urls':['https://example.test/fahrzeuge']}
        web=Mock()
        web.search.return_value=([],[{'provider':'ddg','code':'provider_blocked','error':'Browserprüfung','skipped':True}])
        web.get.return_value='<html>Keine Fahrzeugbelege</html>'
        active,retired,audit,_=research_station(web,station,{'LF 10'},use_llm=False,bos_first=False)
        web.get.assert_called_once_with('https://example.test/fahrzeuge')
        failures=[r for r in audit if r['typ']=='search']
        self.assertEqual(len(failures),1)
        self.assertEqual(failures[0]['provider'],'ddg')
        self.assertEqual(failures[0]['befund'],'Browserprüfung')
        self.assertEqual(failures[0]['occurrences'],4)
        self.assertEqual(failures[0]['skipped_queries'],4)
        self.assertFalse(active)
        self.assertFalse(failures[0]['auswertbar'])

    def test_temporary_failure_does_not_permanently_disable_provider(self):
        with tempfile.TemporaryDirectory() as tmp:
            web=PublicWeb(tmp,delay=0)
            web.get=Mock(side_effect=[RSS,TimeoutError('timeout'),RSS,'<html>Keine Treffer</html>'])
            _,errors=web.search('Potsdam')
            self.assertEqual(errors[0]['code'],'search_failed')
            _,errors=web.search('Potsdam')
            self.assertEqual(errors,[])
            self.assertEqual(web.get.call_count,4)
