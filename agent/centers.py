"""Find official center evidence without turning missing evidence into an empty Soll."""
from urllib.parse import urlparse
from common import norm, now
from extractor import clean_text
from bs4 import BeautifulSoup
import re


def discover_center_soll(web, centers, secondary, catalog):
    entries=list(catalog)
    research=[]
    for center in centers:
        if any(int(c.get('leitstelle_id',0))==int(center['id']) and c.get('verified') for c in entries):continue
        hits,errors=web.search(center['name']+' '+str(center.get('bundesland') or '')+' Zuständigkeit Nebenleitstellen')
        record={'leitstelle_id':int(center['id']),'checked_at':now(),'sources':[],'errors':errors,'status':'Soll ungeklärt'}
        for hit in hits[:3]:
            host=(urlparse(hit['url']).hostname or '').lower()
            if not any(x in host for x in ('landkreis','stadt','feuerwehr','leitstelle','rettung','gv.at','admin.ch')):continue
            try:
                html=web.get(hit['url']);text=clean_text(html)
                mentions=[{'id':int(n['id']),'name':n['name']} for n in secondary if norm(n['name']) in norm(text)]
                record['sources'].append({'url':hit['url'],'mentioned_nebenstellen':mentions,'befund':'Erwähnung belegt keine vollständige Soll-Zuordnung'})
                resolved=parse_complete_soll(html,center,secondary,hit['url'])
                if resolved:
                    entries.append(resolved);record['status']='geprüft';break
                # Co-occurrence without an explicit complete active list is insufficient.
            except Exception as exc:record['errors'].append({'url':hit['url'],'error':str(exc)[:200]})
        research.append(record)
    return entries,research


def parse_complete_soll(html, center, secondary, url):
    """Only explicit complete, active subsidiary lists establish a machine-verifiable Soll."""
    soup=BeautifulSoup(html,'html.parser')
    heading=' '.join(t.get_text(' ',strip=True) for t in soup.select('title,h1,h2'))
    if norm(center['name']) not in norm(heading):return None
    for table in soup.select('table'):
        caption=table.find('caption') or table.find_previous(['h2','h3'])
        label=norm(caption.get_text(' ',strip=True)) if caption else ''
        if not any(token in label for token in ('alle nebenleitstellen','vollstandige liste der nebenleitstellen')):continue
        rows=table.select('tr')
        if not rows:continue
        headers=[norm(c.get_text(' ',strip=True)) for c in rows[0].select('th,td')]
        if 'nebenleitstelle' not in headers or 'status' not in headers:continue
        name_col,status_col=headers.index('nebenleitstelle'),headers.index('status')
        expected=[];valid=True
        for row in rows[1:]:
            cells=[c.get_text(' ',strip=True) for c in row.select('th,td')]
            if max(name_col,status_col)>=len(cells):valid=False;break
            status=norm(cells[status_col])
            if status in ('geschlossen','inaktiv','ausser betrieb'):continue
            if status not in ('aktiv','in betrieb'):valid=False;break
            matches=[n for n in secondary if norm(n['name'])==norm(cells[name_col])]
            if len(matches)>1:valid=False;break
            expected.append({'id':int(matches[0]['id']) if matches else None,'name':cells[name_col]})
        if valid and len(rows)>1:
            return {'leitstelle_id':int(center['id']),'active':True,'verified':True,'source_url':url,'checked_at':now(),'nebenstellen':expected}
    return None
