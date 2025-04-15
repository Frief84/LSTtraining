import json
import requests
import time
import re

# Konfiguration
INPUT_JSON = "leitstellen_deutschland_geo_final.json"
VORLAGE_JSON = "wachen_pro_leitstelle_vorlage_mit_namen.json"
OUTPUT_JSON = "wachen_germany_per_LST_overpass.json"
OVERPASS_URL = "https://overpass-api.de/api/interpreter"

def polygon_to_overpass_coords(geojson):
    coords = geojson['geometry']['coordinates'][0]
    return " ".join([f"{lat} {lon}" for lon, lat in coords])

def fetch_overpass_data(polygon_str):
    query = f"""
    [out:json][timeout:60];
    (
      node["amenity"="fire_station"](poly:"{polygon_str}");
      node["emergency"="ambulance_station"](poly:"{polygon_str}");
      node["emergency"="fire_station"](poly:"{polygon_str}");

      way["amenity"="fire_station"](poly:"{polygon_str}");
      way["emergency"="ambulance_station"](poly:"{polygon_str}");
      way["emergency"="fire_station"](poly:"{polygon_str}");
    );
    out center tags;
    """
    response = requests.post(OVERPASS_URL, data={"data": query})
    if response.status_code == 200:
        return response.json()
    else:
        print(f"Overpass-Fehler: {response.status_code}")
        return None

def clean_area_name(text):
    return re.sub(r"\[.*?\]|<.*?>|\*+|\(.*?\)|\]\]", "", text).strip()

def fetch_by_area(area_name):
    area_name_clean = clean_area_name(area_name)
    query = f"""
    [out:json][timeout:60];
    area[name="{area_name_clean}"]->.searchArea;
    (
      node["amenity"="fire_station"](area.searchArea);
      node["emergency"="ambulance_station"](area.searchArea);
      node["emergency"="fire_station"](area.searchArea);

      way["amenity"="fire_station"](area.searchArea);
      way["emergency"="ambulance_station"](area.searchArea);
      way["emergency"="fire_station"](area.searchArea);
    );
    out center tags;
    """
    response = requests.post(OVERPASS_URL, data={"data": query})
    if response.status_code == 200:
        return response.json()
    else:
        print(f"Overpass-Fehler für Area {area_name}: {response.status_code}")
        return None

def parse_results(elements):
    seen_coords = set()
    wachen = []
    for el in elements:
        tags = el.get("tags", {})
        name = tags.get("name") or tags.get("alt_name") or "Unbenannte Wache"
        lat = el.get("lat") or el.get("center", {}).get("lat")
        lon = el.get("lon") or el.get("center", {}).get("lon")

        if lat and lon:
            coord_key = (round(lat, 6), round(lon, 6))
            if coord_key in seen_coords:
                continue
            seen_coords.add(coord_key)

            typ = ""
            if tags.get("amenity") == "fire_station" or tags.get("emergency") == "fire_station":
                typ = "FW"
            elif tags.get("emergency") == "ambulance_station":
                typ = "RW"

            wachen.append({
                "name": name,
                "typ": typ,
                "latitude": lat,
                "longitude": lon
            })
    return wachen

# Lade Leitstellen-Vorlage (mit Namen)
with open(VORLAGE_JSON, "r", encoding="utf-8") as f:
    wachen_vorlage = json.load(f)

# Lade vollständige Leitstellen mit GeoJSON
with open(INPUT_JSON, "r", encoding="utf-8") as f:
    geo_liste = json.load(f)

# Mapping: leitstelle_id => geojson/zuständigkeit
geo_map = {idx + 1: eintrag for idx, eintrag in enumerate(geo_liste)}

# Verarbeitung starten
resultate = []

for eintrag in wachen_vorlage:
    leitstelle_id = eintrag["leitstelle_id"]
    name_raw = eintrag.get("_comment", f"Leitstelle {leitstelle_id}")
    name_clean = clean_area_name(name_raw)
    print(f"Verarbeite: {name_clean}")

    daten = geo_map.get(leitstelle_id, {})
    geojsons = daten.get("zustandigkeit_geojson", [])
    zustandigkeit_namen = [clean_area_name(z) for z in daten.get("zustandigkeit", [])]
    alle_wachen = []
    seen_coords = set()

    # 1. Versuche GeoJSON
    if geojsons:
        for gj in geojsons:
            try:
                poly_str = polygon_to_overpass_coords(gj)
                data = fetch_overpass_data(poly_str)
                if data:
                    wachen = parse_results(data.get("elements", []))
                    print(f"  → {len(wachen)} Wachen via Polygon gefunden")
                    for w in wachen:
                        coord = (round(w['latitude'], 6), round(w['longitude'], 6))
                        if coord not in seen_coords:
                            alle_wachen.append(w)
                            seen_coords.add(coord)
                time.sleep(1.5)
            except Exception as e:
                print(f"Polygon-Fehler bei {name_clean}: {e}")

    # 2. Falls leer: Versuche Zuständigkeit
    if not alle_wachen and zustandigkeit_namen:
        for gebiet in zustandigkeit_namen:
            try:
                data = fetch_by_area(gebiet)
                if data:
                    wachen = parse_results(data.get("elements", []))
                    print(f"  → {len(wachen)} Wachen via Flächenname '{gebiet}' gefunden")
                    for w in wachen:
                        coord = (round(w['latitude'], 6), round(w['longitude'], 6))
                        if coord not in seen_coords:
                            alle_wachen.append(w)
                            seen_coords.add(coord)
                time.sleep(1.5)
            except Exception as e:
                print(f"Flächen-Fehler bei {name_clean}: {e}")

    # 3. Falls immer noch leer: Versuche Leitstellenname
    if not alle_wachen:
        try:
            data = fetch_by_area(name_clean)
            if data:
                wachen = parse_results(data.get("elements", []))
                print(f"  → {len(wachen)} Wachen via Name '{name_clean}' gefunden")
                for w in wachen:
                    coord = (round(w['latitude'], 6), round(w['longitude'], 6))
                    if coord not in seen_coords:
                        alle_wachen.append(w)
                        seen_coords.add(coord)
            time.sleep(1.5)
        except Exception as e:
            print(f"Namens-Fehler bei {name_clean}: {e}")

    print(f"= Gesamt für {name_clean}: {len(alle_wachen)} Wachen\n")

    resultate.append({
        "_comment": name_clean,
        "leitstelle_id": leitstelle_id,
        "wachen": alle_wachen
    })

# Speichern
with open(OUTPUT_JSON, "w", encoding="utf-8") as f:
    json.dump(resultate, f, ensure_ascii=False, indent=2)

print(f"Fertig gespeichert unter: {OUTPUT_JSON}")
