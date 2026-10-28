"""Serial selected writes. No automatic transition from research to writing."""
import json
from pathlib import Path
from common import HISTORICAL, TECHNICAL, key, now, valid_seal
from evaluator import match_station
from extractor import clean_text


class Writer:
    def __init__(self, api, web, log):
        self.api, self.web, self.log = api, web, Path(log)

    def record(self, entry, status, **detail):
        result={'timestamp':now(),'freigabe_id':entry['freigabe_id'],'action':entry['action'],'status':status,**detail}
        self.log.parent.mkdir(parents=True,exist_ok=True)
        with self.log.open('a',encoding='utf-8') as handle:handle.write(json.dumps(result,ensure_ascii=False)+'\n')
        return result

    def apply(self, entry):
        if not valid_seal(entry):raise ValueError('Freigabe-ID passt nicht zum Inhalt')
        if not entry.get('schreibfaehig') or entry.get('nur_lstsim') or not entry.get('home_verified'):raise ValueError('Eintrag nicht schreibfähig')
        self.api.global_scope()
        evidence=entry.get('beleg','')
        text=clean_text(self.web.get(entry['quelle'],fresh=True))
        if not evidence or evidence not in text:raise ValueError('Beleg inzwischen verändert/nicht mehr vorhanden')
        action=entry['action']
        if action!='SET_AD' and HISTORICAL.search(evidence):raise ValueError('Außer-Dienst-Beleg für aktive Aktion gesperrt')
        if action=='SET_AD' and not HISTORICAL.search(evidence):raise ValueError('Außer-Dienst-Beleg fehlt')
        if action=='ADD_WACHE':
            stations=self.api.list('wachen')
            hint=entry['station_hint']
            if match_station(hint,stations):return self.record(entry,'skipped_station_exists')
        else:
            station=self.api.detail('wachen',entry['wache_id'])
            if entry.get('station_before'):
                for field,value in entry['station_before'].items():
                    if station.get(field)!=value:raise ValueError('Heimatwache seit Report verändert')
            if action=='UPDATE_WACHE':
                if not TECHNICAL.fullmatch(station['name']):raise ValueError('Wache bereits umbenannt')
                for field in ('name','adresse','latitude','longitude'):
                    if station.get(field)!=entry['before'].get(field):raise ValueError('Wache seit Report verändert')
            else:
                vehicles=self.api.list('fahrzeuge')
                matches=[v for v in vehicles if key(v['rufname'])==key(entry['after']['callsign'])]
                if any(int(v['wache_id'])!=int(entry['wache_id']) for v in matches):return self.record(entry,'skipped_other_station')
                if action=='ADD' and matches:return self.record(entry,'skipped_existing')
                if action in ('UPDATE','SET_AD'):
                    current=self.api.detail('fahrzeuge',entry['vehicle_id'])
                    for field in ('wache_id','rufname','fahrzeugtyp','status','fms_status'):
                        if current.get(field)!=entry['before'].get(field):raise ValueError('Fahrzeug seit Report verändert')
        try:
            saved=self.api.request('/abgleich/aktion',method='POST',payload=entry)
            return self.record(entry,'written',result=saved)
        except Exception as exc:
            # All failures may be ambiguous. Re-read, record outcome, never retry POST.
            try:
                if action in ('ADD','UPDATE','SET_AD'):
                    state=self.api.list('fahrzeuge')
                    matches=[v for v in state if key(v['rufname'])==key(entry['after']['callsign'])]
                else:
                    state=self.api.list('wachen')
                    matches=[v for v in state if key(v['name'])==key(entry['after']['name'])]
                return self.record(entry,'write_result_requires_review',error=str(exc),observed=matches)
            except Exception as check:
                return self.record(entry,'write_result_unknown',error=str(exc),read_error=str(check))

    def writeWache(self, ident, values, entry):
        if entry['action']!='UPDATE_WACHE' or int(entry['wache_id'])!=int(ident) or entry['after']!=values:raise ValueError('Auswahl stimmt nicht überein')
        return self.apply(entry)

    def writeFahrzeug(self, wache_id, values, entry):
        if entry['action']!='ADD' or int(entry['wache_id'])!=int(wache_id) or entry['after']!=values:raise ValueError('Auswahl stimmt nicht überein')
        return self.apply(entry)
