"""Geographic isolation of the supplied Potsdam research package."""
import json
from pathlib import Path
import sys
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from geo import membership

class PotsdamPackageTests(unittest.TestCase):
    def test_city_scope_and_uncertainty(self):
        package=json.loads((Path(__file__).resolve().parents[1]/'packages/Potsdam.json').read_text(encoding='utf-8'))
        self.assertEqual((package['country'],package['state'],package['region']),('DE','Brandenburg','Potsdam'))
        self.assertEqual(package['geometry']['features'][0]['properties']['ags'],'12054')
        geometry=package['geometry']
        for lat,lon in ((52.400,13.055),(52.395,13.095)):
            self.assertEqual(membership({'latitude':lat,'longitude':lon},geometry),'inside')
        for lat,lon in ((52.520,13.405),(52.142,12.593)):
            self.assertEqual(membership({'latitude':lat,'longitude':lon},geometry),'outside')
        self.assertEqual(membership({},geometry),'unresolved')
        lon,lat=geometry['features'][0]['geometry']['coordinates'][0][0][0]
        self.assertEqual(membership({'latitude':lat,'longitude':lon},geometry),'boundary')
