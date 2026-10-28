"""Region-specific callsign prefixes. Never reuse a sample across counties."""
import re
from common import norm

BRANDENBURG = {
 'Dahme-Spreewald':'Florian Spreewald', 'Teltow-Fläming':'Florian Fläming',
 'Märkisch-Oderland':'Florian Oderland', 'Oberspreewald-Lausitz':'Florian Spree-Lausitz',
 'Barnim':'Florian Barnim','Elbe-Elster':'Florian Elbe-Elster','Havelland':'Florian Havelland',
 'Oberhavel':'Florian Oberhavel','Oder-Spree':'Florian Oder-Spree','Prignitz':'Florian Prignitz',
 'Spree-Neiße':'Florian Spree-Neiße','Uckermark':'Florian Uckermark','Potsdam':'Florian Potsdam','Cottbus':'Florian Cottbus'}
CODES = {'Dahme-Spreewald':'LDS','Teltow-Fläming':'TF','Märkisch-Oderland':'MOL','Oberspreewald-Lausitz':'OSL'}
BASE = ['Florian','Rotkreuz','Rot Kreuz','Sama','Akkon','Johannes','Malteser','Pelikan','Christoph','Kater','Rettung']


def prefixes(state, region, verified_samples=()):
    if norm(state) == norm('Brandenburg'):
        for county, prefix in BRANDENBURG.items():
            if norm(region).removeprefix('landkreis ') == norm(county): return [prefix]+BASE[1:]
    found=[]
    for callsign in list(verified_samples)[:5]:
        match = re.match(r'(.+?)\s+\d', callsign)
        if match and match[1] not in found: found.append(match[1])
    # Generic services permit discovery, not an assertion about a regional callsign system.
    return found+BASE


def mapping_record(state, region, samples):
    return {'state':state,'region':region,'prefixes':prefixes(state,region,samples), 'method':'fixed-user-mapping' if norm(state)==norm('Brandenburg') and any(norm(region).removeprefix('landkreis ')==norm(r) for r in BRANDENBURG) else ('verified-first-five' if samples else 'unresolved'), 'samples':list(samples)[:5], 'county_codes': CODES if state=='Brandenburg' else {}}
