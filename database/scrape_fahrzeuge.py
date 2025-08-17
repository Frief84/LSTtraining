import re
import csv
import json
import time
import argparse
from urllib.parse import urlparse
import requests
from bs4 import BeautifulSoup
import yaml

FRN_PATTERNS = [
    r"\bFlorian\s+[A-ZÄÖÜa-zäöüß\- ]+\s+\d{1,2}/\d{2}(?:-\d{1,2})?\b",
    r"\b\d{1,2}/\d{2}(?:-\d{1,2})?\b",
]
FRN_REGEX = re.compile("|".join(FRN_PATTERNS))

TYPE_HINTS = {
    "HLF": "HLF 20",
    "LF 10": "LF 10",
    "LF10": "LF 10",
    "LF 16/12": "LF 16/12",
    "DLK": "DLK",
    "DLK 23": "DLK 23",
    "RTW": "RTW",
    "NEF": "NEF",
    "MTW": "MTW",
    "ELW": "ELW",
    "TLF": "TLF",
}

HEADERS = {"User-Agent": "LSTtraining-Fahrzeuge/1.0 (+https://frief.de/lsttraining/)"}

def fetch(url, timeout=20):
    r = requests.get(url, headers=HEADERS, timeout=timeout)
    r.raise_for_status()
    return r.text

def text_blocks(soup):
    # Yield relevant text chunks
    for tag in soup.find_all(["h1","h2","h3","h4","p","li","td","th","span","div"]):
        txt = " ".join(tag.get_text(" ", strip=True).split())
        if txt:
            yield txt

def guess_vehicle_type(text):
    # Simple heuristic mapping
    for k,v in TYPE_HINTS.items():
        if re.search(r"\b" + re.escape(k) + r"\b", text, re.IGNORECASE):
            return v
    return ""

def parse_potsdam_official(html, base_url):
    # Seiten wie /feuerwache-babelsberg -> liefert oft nur Typen (HLF, DLK), selten FRN
    soup = BeautifulSoup(html, "html.parser")
    out = []
    for txt in text_blocks(soup):
        # Erfasse "HLF", "DLK", "RTW" etc.
        t = guess_vehicle_type(txt)
        if t:
            out.append({
                "name": "",
                "frn": "",
                "typ": t,
                "quelle": "potsdam_official",
                "url": base_url
            })
        # Falls FRN doch mal vorkommt:
        for m in FRN_REGEX.findall(txt):
            out.append({
                "name": "",
                "frn": m.strip(),
                "typ": guess_vehicle_type(txt),
                "quelle": "potsdam_official",
                "url": base_url
            })
    return out

def parse_ff_wordpress(html, base_url):
    # Ortswehrseiten: Headings + Absätze, oft "Funkrufname: ..."
    soup = BeautifulSoup(html, "html.parser")
    out = []
    # Tabellen oder Infoblöcke
    for blk in text_blocks(soup):
        frns = FRN_REGEX.findall(blk)
        if frns:
            out.extend([{
                "name": "",
                "frn": frn.strip(),
                "typ": guess_vehicle_type(blk),
                "quelle": "ff_wordpress",
                "url": base_url
            } for frn in frns])
        else:
            # ggf. "Funkrufname:" explizit
            if "funkrufname" in blk.lower():
                m = FRN_REGEX.search(blk)
                if m:
                    out.append({
                        "name": "",
                        "frn": m.group(0).strip(),
                        "typ": guess_vehicle_type(blk),
                        "quelle": "ff_wordpress",
                        "url": base_url
                    })
    return out

def parse_bos_fahrzeuge(html, base_url):
    soup = BeautifulSoup(html, "html.parser")
    out = []
    page_text = " ".join([t for t in text_blocks(soup)])
    # FRN
    frns = FRN_REGEX.findall(page_text)
    frn = frns[0].strip() if frns else ""
    # Typ aus Breadcrumbs/Tabellen
    typ = ""
    # typischerweise stehen Typen wie "Rettungswagen (RTW)" im Text
    m = re.search(r"\b(NEF|RTW|HLF ?\d{0,2}|LF ?\d{0,2}|DLK(?:\s*\d+/\d+)?)\b", page_text, re.IGNORECASE)
    if m:
        typ = m.group(0).upper().replace("  ", " ").strip()
        typ = TYPE_HINTS.get(typ, typ)
    out.append({
        "name": "",
        "frn": frn,
        "typ": typ,
        "quelle": "bos_fahrzeuge",
        "url": base_url
    })
    return out

def pick_adapter(url):
    h = urlparse(url).hostname or ""
    if "potsdam.de" in h:
        return parse_potsdam_official
    if "bos-fahrzeuge.info" in h:
        return parse_bos_fahrzeuge
    return parse_ff_wordpress

def normalize_frn(frn):
    if not frn:
        return ""
    frn = frn.replace("Florian", "").strip()
    frn = re.sub(r"\s+", " ", frn)
    return frn

def merge_records(records):
    # key: (normalized FRN or text type when FRN absent)
    merged = {}
    for r in records:
        key = normalize_frn(r["frn"]) or f"TYPE::{r['typ']}"
        if key in merged:
            merged[key]["quellen"].append({"quelle": r["quelle"], "url": r["url"]})
            if not merged[key]["typ"] and r["typ"]:
                merged[key]["typ"] = r["typ"]
        else:
            merged[key] = {
                "name": r.get("name",""),
                "frn": normalize_frn(r["frn"]),
                "typ": r.get("typ",""),
                "quellen": [{"quelle": r["quelle"], "url": r["url"]}]
            }
    return list(merged.values())

def run(config_path, out_json, out_csv, geojson_path=None):
    with open(config_path, "r", encoding="utf-8") as f:
        cfg = yaml.safe_load(f)

    all_out = []
    for st in cfg.get("stations", []):
        wache = st["wache_name"]
        ort = st.get("ort","")
        station_items = []
        for src in st.get("sources", []):
            url = src["url"]
            try:
                html = fetch(url)
                parser = pick_adapter(url)
                items = parser(html, url)
                station_items.extend(items)
                time.sleep(0.8)  # höflich
            except Exception as e:
                station_items.append({
                    "name": "",
                    "frn": "",
                    "typ": "",
                    "quelle": f"ERROR({type(e).__name__})",
                    "url": url
                })
        merged = merge_records(station_items)
        for m in merged:
            all_out.append({
                "wache": wache,
                "ort": ort,
                "frn": m["frn"],
                "typ": m["typ"],
                "quellen": m["quellen"]
            })

    # Export JSON
    with open(out_json, "w", encoding="utf-8") as f:
        json.dump({"stations": all_out}, f, ensure_ascii=False, indent=2)

    # Export CSV
    with open(out_csv, "w", encoding="utf-8", newline="") as f:
        w = csv.writer(f, delimiter=";")
        w.writerow(["wache","ort","frn","typ","quellen"])
        for r in all_out:
            w.writerow([r["wache"], r["ort"], r["frn"], r["typ"], json.dumps(r["quellen"], ensure_ascii=False)])

if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--config", required=True, help="Pfad zur YAML-Konfiguration")
    ap.add_argument("--out-json", default="fahrzeuge_pro_wache.json")
    ap.add_argument("--out-csv", default="fahrzeuge_pro_wache.csv")
    ap.add_argument("--geojson", default=None, help="Optional: GeoJSON der Wachen für spätere Verknüpfung")
    args = ap.parse_args()
    run(args.config, args.out_json, args.out_csv, args.geojson)
