"""Polygon/MultiPolygon membership with explicit boundary results, independent of DACH names."""
from common import coordinates


def ring_contains(lon,lat,ring):
    inside=False
    for i,a in enumerate(ring):
        b=ring[i-1];x1,y1=a[:2];x2,y2=b[:2]
        dx,dy=x2-x1,y2-y1
        t=max(0,min(1,((lon-x1)*dx+(lat-y1)*dy)/(dx*dx+dy*dy))) if dx*dx+dy*dy else 0
        if (lon-x1-t*dx)**2+(lat-y1-t*dy)**2<1e-14:return 'boundary'
        if (y1>lat)!=(y2>lat) and lon<(x2-x1)*(lat-y1)/(y2-y1)+x1:inside=not inside
    return 'inside' if inside else 'outside'


def membership(station, geometry):
    point=coordinates(station)
    if not point:return 'unresolved'
    if isinstance(geometry,str):
        import json
        geometry=json.loads(geometry)
    if isinstance(geometry,list):
        if not geometry:raise ValueError('Leere Gebietsgeometrie')
        results=[membership(station,feature) for feature in geometry]
        return 'boundary' if 'boundary' in results else 'inside' if 'inside' in results else 'unresolved' if 'unresolved' in results else 'outside'
    if geometry['type']=='Feature':return membership(station,geometry['geometry'])
    if geometry['type']=='FeatureCollection':
        results=[membership(station,f) for f in geometry['features']]
        return 'boundary' if 'boundary' in results else 'inside' if 'inside' in results else 'outside'
    polygons=geometry['coordinates'] if geometry['type']=='MultiPolygon' else [geometry['coordinates']] if geometry['type']=='Polygon' else []
    if not polygons:raise ValueError('Polygon/MultiPolygon erforderlich')
    lat,lon=point
    for polygon in polygons:
        outer=ring_contains(lon,lat,polygon[0]);holes=[ring_contains(lon,lat,r) for r in polygon[1:]]
        if outer=='boundary' or 'boundary' in holes:return 'boundary'
        if outer=='inside' and 'inside' not in holes:return 'inside'
    return 'outside'
