const DIACRITICS = /[\u0300-\u036f]/g;

export function normalizeName(value) {
  return String(value || '').normalize('NFD').replace(DIACRITICS, '').toLowerCase()
    .replace(/ae/g, 'a').replace(/oe/g, 'o').replace(/ue/g, 'u').replace(/ss/g, 's')
    .replace(/\b(integrierte?|zentrale?|kooperative?|kommunale?|regional(?:e|leitstelle)?|feuerwehrleitstelle|rettungsleitstelle|einsatzleitstelle|leitfunkstelle|leitstelle|feuerwehr|rettungsdienst|florian)\b/g, ' ')
    .replace(/[^a-z0-9]+/g, ' ').trim().replace(/\s+/g, ' ');
}

export function apiBaseUrl(value) {
  const root = String(value || '').trim().replace(/\/+$/, '');
  if (!/^https:\/\//i.test(root)) throw new Error('LSTTRAINING_API_BASE_URL muss eine HTTPS-URL sein.');
  return /\/wp-json\/lst\/v1$/i.test(root) ? root : `${root}/wp-json/lst/v1`;
}

export function pointInRing(lon, lat, ring) {
  let inside = false;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const [xi, yi] = ring[i].map(Number); const [xj, yj] = ring[j].map(Number);
    if (((yi > lat) !== (yj > lat)) && lon < ((xj - xi) * (lat - yi)) / (yj - yi) + xi) inside = !inside;
  }
  return inside;
}

export function pointOnRing(lon, lat, ring, tolerance = 1e-7) {
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const [x1, y1] = ring[j].map(Number); const [x2, y2] = ring[i].map(Number);
    const dx = x2 - x1; const dy = y2 - y1;
    const length2 = dx * dx + dy * dy;
    const t = length2 ? Math.max(0, Math.min(1, ((lon - x1) * dx + (lat - y1) * dy) / length2)) : 0;
    if (Math.hypot(lon - (x1 + t * dx), lat - (y1 + t * dy)) <= tolerance) return true;
  }
  return false;
}

function geometries(value) {
  const parsed = typeof value === 'string' ? JSON.parse(value) : value;
  if (!parsed) return [];
  if (parsed.type === 'FeatureCollection') return parsed.features.flatMap((f) => geometries(f));
  if (parsed.type === 'Feature') return geometries(parsed.geometry);
  return [parsed];
}

export function pointInGeoJson(lon, lat, value) {
  return geometries(value).some((geometry) => {
    const polygons = geometry.type === 'Polygon' ? [geometry.coordinates] : geometry.type === 'MultiPolygon' ? geometry.coordinates : [];
    return polygons.some((polygon) => pointInRing(lon, lat, polygon[0]) && !polygon.slice(1).some((hole) => pointInRing(lon, lat, hole)));
  });
}

export function pointOnGeoJsonBoundary(lon, lat, value) {
  return geometries(value).some((geometry) => {
    const polygons = geometry.type === 'Polygon' ? [geometry.coordinates] : geometry.type === 'MultiPolygon' ? geometry.coordinates : [];
    return polygons.some((polygon) => polygon.some((ring) => pointOnRing(lon, lat, ring)));
  });
}

export function haversineKm(aLat, aLon, bLat, bLon) {
  const rad = (x) => x * Math.PI / 180; const R = 6371;
  const dLat = rad(bLat - aLat); const dLon = rad(bLon - aLon);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(aLat)) * Math.cos(rad(bLat)) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
}

export function validateCandidate(item) {
  const errors = [];
  if (!item.catalogKey || !/^de-[a-z0-9-]+$/.test(item.catalogKey)) errors.push('catalogKey fehlt/ist ungültig');
  if (!item.name) errors.push('Name fehlt');
  if (!item.bundesland) errors.push('Bundesland fehlt');
  if (!Array.isArray(item.aufgaben) || item.aufgaben.length < 2) errors.push('Aufgaben fehlen');
  if (!Array.isArray(item.gebiet) || !item.gebiet.length) errors.push('Gebietsschlüssel fehlen');
  if (!item.address) errors.push('Anschrift fehlt');
  if (!Number.isFinite(Number(item.gps?.latitude)) || !Number.isFinite(Number(item.gps?.longitude))) errors.push('GPS fehlt');
  if (!item.geojson || !['FeatureCollection', 'Feature', 'Polygon', 'MultiPolygon'].includes(item.geojson.type)) errors.push('GeoJSON fehlt/ist ungültig');
  if (!Number.isInteger(Number(item.einwohner)) || Number(item.einwohner) < 1000) errors.push('Einwohnerzahl fehlt oder ist unplausibel');
  if (!Number.isFinite(Number(item.flaeche_km2)) || Number(item.flaeche_km2) < 1) errors.push('Fläche fehlt oder ist unplausibel');
  for (const field of ['bestand', 'aufgaben', 'adresse', 'gebiet']) if (!/^https:\/\//.test(item.sources?.[field] || '')) errors.push(`Quelle ${field} fehlt`);
  return errors;
}

export function findPossibleDuplicates(candidate, existing) {
  const names = [candidate.name, ...(candidate.aliases || [])].map(normalizeName).filter(Boolean);
  const result = [];
  for (const row of existing) {
    const existingName = normalizeName(row.name);
    const reasons = [];
    if (names.includes(existingName)) reasons.push('name_or_alias');
    const gps = parseGps(row.gps);
    if (gps && haversineKm(candidate.gps.latitude, candidate.gps.longitude, gps.latitude, gps.longitude) <= 1) reasons.push('location_within_1km');
    if (row.geojson && pointInGeoJson(candidate.gps.longitude, candidate.gps.latitude, row.geojson)) reasons.push('candidate_location_in_existing_area');
    const jurisdiction = normalizeName(row.zustandigkeit);
    if (jurisdiction && candidate.gebiet.some((g) => jurisdiction.includes(normalizeName(g.name)))) reasons.push('jurisdiction_name');
    if (reasons.includes('name_or_alias') || reasons.length >= 2) result.push({ id: Number(row.id), name: row.name, reasons });
  }
  return result;
}

export function parseGps(value) {
  if (value && typeof value === 'object') {
    const latitude = Number(value.latitude ?? value.lat); const longitude = Number(value.longitude ?? value.lon ?? value.lng);
    return Number.isFinite(latitude) && Number.isFinite(longitude) ? { latitude, longitude } : null;
  }
  const parts = String(value || '').split(/[,;\s]+/).filter(Boolean).map(Number);
  return parts.length >= 2 && parts.every(Number.isFinite) ? { latitude: parts[0], longitude: parts[1] } : null;
}

export function candidatePayload(item, wachen = []) {
  return {
    name: item.name,
    land: 'Deutschland',
    bundesland: item.bundesland,
    aufgaben: item.aufgaben.join(', '),
    zustandigkeit: item.gebiet.map((g) => `${g.name} (${g.key})`).join('; '),
    standorte: item.address,
    einwohner: Number(item.einwohner),
    flaeche_km2: Number(item.flaeche_km2),
    gps: `${Number(item.gps.latitude).toFixed(6)},${Number(item.gps.longitude).toFixed(6)}`,
    nachbarleitstelle: true,
    geojson: item.geojson,
    wachen
  };
}

function geoBounds(value) {
  const box = [Infinity, Infinity, -Infinity, -Infinity];
  const visit = (node) => {
    if (!Array.isArray(node)) return;
    if (node.length >= 2 && Number.isFinite(Number(node[0])) && Number.isFinite(Number(node[1]))) {
      const x = Number(node[0]); const y = Number(node[1]);
      box[0] = Math.min(box[0], x); box[1] = Math.min(box[1], y); box[2] = Math.max(box[2], x); box[3] = Math.max(box[3], y); return;
    }
    for (const child of node) visit(child);
  };
  for (const geometry of geometries(value)) visit(geometry.coordinates);
  return box;
}

export function assignWachen(candidates, wachen) {
  const byCandidate = new Map(candidates.map((c) => [c.catalogKey, []]));
  const indexed = candidates.map((candidate) => ({ candidate, bounds: geoBounds(candidate.geojson) }));
  const invalid = []; const boundary = []; const multiple = [];
  for (const wache of wachen) {
    const latitude = Number(wache.latitude); const longitude = Number(wache.longitude);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || Math.abs(latitude) > 90 || Math.abs(longitude) > 180) { invalid.push(Number(wache.id)); continue; }
    const nearby = indexed.filter(({ bounds }) => longitude >= bounds[0] && longitude <= bounds[2] && latitude >= bounds[1] && latitude <= bounds[3]).map((x) => x.candidate);
    const matches = nearby.filter((c) => pointInGeoJson(longitude, latitude, c.geojson));
    const edge = nearby.filter((c) => pointOnGeoJsonBoundary(longitude, latitude, c.geojson));
    if (edge.length) { boundary.push({ wache: Number(wache.id), candidates: edge.map((c) => c.catalogKey) }); continue; }
    if (matches.length > 1) { multiple.push({ wache: Number(wache.id), candidates: matches.map((c) => c.catalogKey) }); continue; }
    if (matches.length === 1) byCandidate.get(matches[0].catalogKey).push(Number(wache.id));
  }
  return { byCandidate, invalid, boundary, multiple };
}
