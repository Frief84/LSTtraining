export function normalizeSourceKey(value) {
  return String(value || '').trim().toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-|-$/g, '');
}

export function validateStation(station) {
  const errors = [];
  if (!/^(DE|AT|CH)$/.test(String(station.country || ''))) errors.push('country muss DE, AT oder CH sein');
  if (!normalizeSourceKey(station.sourceKey)) errors.push('sourceKey fehlt');
  if (!station.name) errors.push('Name fehlt');
  if (!Number.isFinite(Number(station.latitude)) || Math.abs(Number(station.latitude)) > 90) errors.push('Breitengrad fehlt/ist ungültig');
  if (!Number.isFinite(Number(station.longitude)) || Math.abs(Number(station.longitude)) > 180) errors.push('Längengrad fehlt/ist ungültig');
  if (!station.sourceUrl || !/^https:\/\//.test(station.sourceUrl)) errors.push('HTTPS-Quelle fehlt');
  if (!Array.isArray(station.vehicles) || station.vehicles.length === 0) errors.push('Kein Fahrzeug');
  for (const vehicle of station.vehicles || []) {
    if (!vehicle.callsign) errors.push('Fahrzeugrufname fehlt');
    if (!['RTH', 'ITH', 'SAR'].includes(vehicle.type)) errors.push(`Ungültiger Fahrzeugtyp ${vehicle.type || '(leer)'}`);
    if (!vehicle.dutyText) errors.push(`Dienstzeit für ${vehicle.callsign || 'Fahrzeug'} fehlt`);
    if (!vehicle.availabilityRule || typeof vehicle.availabilityRule !== 'object') errors.push(`Verfügbarkeitsregel für ${vehicle.callsign || 'Fahrzeug'} fehlt`);
  }
  return errors;
}

export function pointInRing(longitude, latitude, ring) {
  let inside = false;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const xi = Number(ring[i][0]); const yi = Number(ring[i][1]);
    const xj = Number(ring[j][0]); const yj = Number(ring[j][1]);
    if (((yi > latitude) !== (yj > latitude)) && longitude < ((xj - xi) * (latitude - yi)) / (yj - yi) + xi) inside = !inside;
  }
  return inside;
}

export function pointInGeoJson(longitude, latitude, geojson) {
  if (!geojson) return false;
  const value = typeof geojson === 'string' ? JSON.parse(geojson) : geojson;
  const geometries = value.type === 'FeatureCollection' ? value.features.map((f) => f.geometry)
    : value.type === 'Feature' ? [value.geometry] : [value];
  return geometries.some((geometry) => {
    const polygons = geometry?.type === 'Polygon' ? [geometry.coordinates] : geometry?.type === 'MultiPolygon' ? geometry.coordinates : [];
    return polygons.some((polygon) => pointInRing(longitude, latitude, polygon[0]) && !polygon.slice(1).some((hole) => pointInRing(longitude, latitude, hole)));
  });
}

export function stationPayload(station, leitstellen = []) {
  const countries = { DE: 'Deutschland', AT: 'Österreich', CH: 'Schweiz' };
  return {
    name: station.name, typ: 'AIR', bundesland: station.region || null,
    land: countries[station.country], latitude: Number(station.latitude), longitude: Number(station.longitude),
    exists_in_reality: true, source_note: station.operator || '', source_key: normalizeSourceKey(station.sourceKey),
    source_url: station.sourceUrl, source_checked_at: station.sourceCheckedAt, leitstellen
  };
}

export function vehiclePayload(station, vehicle, wacheId) {
  return {
    wache_id: wacheId, rufname: vehicle.callsign, fahrzeugtyp: vehicle.type,
    status: 'frei', fms_status: '2', sondersignal: false, is_first_responder: false,
    dienstzeiten: vehicle.dutyText, availability_rule_json: vehicle.availabilityRule,
    source_note: station.operator || '', source_key: `${normalizeSourceKey(station.sourceKey)}-${normalizeSourceKey(vehicle.callsign)}`,
    source_url: vehicle.sourceUrl || station.sourceUrl, source_checked_at: station.sourceCheckedAt
  };
}
