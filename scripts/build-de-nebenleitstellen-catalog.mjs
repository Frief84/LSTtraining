#!/usr/bin/env node
import { readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { pointInGeoJson, validateCandidate } from './lib/de-nebenleitstellen.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const valueAfter = (flag, fallback) => { const i = args.indexOf(flag); return i >= 0 ? resolve(args[i + 1] || '') : fallback; };
const sourcePath = valueAfter('--source', resolve(root, 'data/de-nebenleitstellen.json'));
const outputPath = valueAfter('--output', sourcePath);
const refreshGps = args.includes('--refresh-gps');
const catalog = JSON.parse(await readFile(sourcePath, 'utf8'));
const NRW = 'https://www.im.nrw/system/files/media/document/file/230602-leitstellen-feuerwehr-nrw_jede-kommune.csv';

async function getJson(url, headers = {}) {
  let last;
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    try {
      const response = await fetch(url, { headers: { Accept: 'application/geo+json, application/json', 'User-Agent': 'LSTtraining data catalog (contact via frief.de)', ...headers }, signal: AbortSignal.timeout(60000) });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      return await response.json();
    } catch (error) { last = error; if (attempt < 3) await new Promise((r) => setTimeout(r, attempt * 1000)); }
  }
  throw last;
}

function bkgMatch(feature, key) {
  const p = feature.properties || {};
  return String(p.ags_0 || '') === key || String(p.ars || '') === key || String(p.ags || '') === key;
}

function compactFeature(feature) {
  const p = feature.properties || {};
  return { type: 'Feature', properties: { name: p.gen, ags: p.ags_0 || p.ags, rs: p.ars, ewz: p.ewz, kfl: p.kfl, stand: '2024-12-31' }, geometry: feature.geometry };
}

function polygonProbe(polygon) {
  const ring = polygon[0] || [];
  if (!ring.length) return [NaN, NaN];
  const sum = ring.reduce((a, p) => [a[0] + Number(p[0]), a[1] + Number(p[1])], [0, 0]);
  return [sum[0] / ring.length, sum[1] / ring.length];
}

function removeNeuwerk(hamburgFeature, neuwerk) {
  const geometry = structuredClone(hamburgFeature.geometry);
  if (geometry.type !== 'MultiPolygon') throw new Error('Hamburg wird im BKG-Datensatz nicht als MultiPolygon geliefert.');
  const before = geometry.coordinates.length;
  geometry.coordinates = geometry.coordinates.filter((polygon) => {
    const [lon, lat] = polygonProbe(polygon);
    const ring = polygon[0] || [];
    const touchesNeuwerk = pointInGeoJson(lon, lat, neuwerk)
      || ring.some((point, index) => index % Math.max(1, Math.floor(ring.length / 25)) === 0 && pointInGeoJson(Number(point[0]), Number(point[1]), neuwerk));
    return !touchesNeuwerk;
  });
  return { feature: { ...hamburgFeature, geometry }, removedPolygons: before - geometry.coordinates.length };
}

async function geocode(address) {
  const url = new URL('https://nominatim.openstreetmap.org/search');
  url.searchParams.set('format', 'jsonv2'); url.searchParams.set('limit', '1'); url.searchParams.set('countrycodes', 'de'); url.searchParams.set('q', address);
  const rows = await getJson(url);
  if (!rows[0] || !Number.isFinite(Number(rows[0].lat)) || !Number.isFinite(Number(rows[0].lon))) throw new Error(`Adresse nicht geokodierbar: ${address}`);
  return { latitude: Number(rows[0].lat), longitude: Number(rows[0].lon), source: 'OpenStreetMap Nominatim fallback', sourceUrl: url.toString(), checkedAt: new Date().toISOString() };
}

async function fetchBkg(level, typename) {
  const keys = [...new Set(catalog.candidates.flatMap((c) => c.gebiet.filter((g) => g.level === level).map((g) => g.key)))];
  if (!keys.length) return { url: null, features: [] };
  const cql = keys.map((key) => key.length === 8 ? `ags_0='${key}'` : `ars='${key}'`).join(' OR ');
  const url = new URL(catalog.metricSource.wfs);
  for (const [k, v] of Object.entries({ SERVICE: 'WFS', VERSION: '2.0.0', REQUEST: 'GetFeature', TYPENAMES: typename, SRSNAME: 'EPSG:4326', OUTPUTFORMAT: 'application/json', COUNT: '100', CQL_FILTER: `(${cql})` })) url.searchParams.set(k, v);
  const result = await getJson(url);
  if (!Array.isArray(result.features)) throw new Error(`BKG-Antwort ${typename} enthält keine Features.`);
  return { url, features: result.features };
}
const bkgLayers = { kreis: await fetchBkg('kreis', 'vg250_krs'), gemeinde: await fetchBkg('gemeinde', 'vg250_gem') };

let neuwerk = null;
const hamburg = catalog.candidates.find((c) => c.catalogKey === 'de-hh-hamburg');
if (hamburg) {
  const url = new URL('https://geodienste.hamburg.de/wfs_regionalstatistische_daten_stadtteile');
  for (const [k, v] of Object.entries({ SERVICE: 'WFS', VERSION: '2.0.0', REQUEST: 'GetFeature', TYPENAMES: 'de.hh.up:regionalstatistische_daten_stadtteile', SRSNAME: 'EPSG:4326', OUTPUTFORMAT: 'application/geo+json', CQL_FILTER: "stadtteil='Neuwerk'" })) url.searchParams.set(k, v);
  const result = await getJson(url);
  const rows = (result.features || []).filter((f) => f.geometry).sort((a, b) => String(b.properties?.jahr || '').localeCompare(String(a.properties?.jahr || '')));
  neuwerk = rows[0] || null;
  if (!neuwerk) throw new Error('Amtliche Neuwerk-Geometrie fehlt.');
}

for (const candidate of catalog.candidates) {
  if (candidate.bundesland === 'Nordrhein-Westfalen') candidate.sources = { bestand: NRW, aufgaben: NRW, adresse: NRW, gebiet: NRW, ...(candidate.sources || {}) };
  let features = candidate.gebiet.map((g) => bkgLayers[g.level]?.features.find((f) => bkgMatch(f, g.key))).filter(Boolean);
  if (features.length !== candidate.gebiet.length) {
    const found = features.map((f) => f.properties?.ags_0 || f.properties?.ars);
    throw new Error(`${candidate.catalogKey}: BKG-Gebiet unvollständig (${found.join(', ')})`);
  }
  let einwohner = features.reduce((n, f) => n + Number(f.properties?.ewz || 0), 0);
  let flaeche = features.reduce((n, f) => n + Number(f.properties?.kfl || 0), 0);
  if (candidate.catalogKey === 'de-hh-hamburg') {
    const adjusted = removeNeuwerk(features[0], neuwerk);
    features = [adjusted.feature];
    einwohner -= Number(neuwerk.properties?.bev_insgesamt || 0);
    flaeche -= Number(neuwerk.properties?.flaeche_brutto_ha || 0) / 100;
    candidate.geometryException = { excluded: 'Neuwerk', source: candidate.sources.gebiet, sourceYear: String(neuwerk.properties?.jahr || ''), removedBkgPolygons: adjusted.removedPolygons, note: adjusted.removedPolygons ? 'Neuwerk-Inselpolygone aus BKG-Geometrie entfernt.' : 'Neuwerk ist in der generalisierten BKG-Kreisebene nicht als eigenes Polygon enthalten; amtliche Hamburger Geometrie zur Negativprüfung verwendet.' };
  } else if (candidate.includeNeuwerk) {
    features.push({ type: 'Feature', properties: { gen: 'Neuwerk', ags_0: '02000000', ars: '02000', ewz: Number(neuwerk.properties?.bev_insgesamt || 0), kfl: Number(neuwerk.properties?.flaeche_brutto_ha || 0) / 100 }, geometry: neuwerk.geometry });
    einwohner += Number(neuwerk.properties?.bev_insgesamt || 0);
    flaeche += Number(neuwerk.properties?.flaeche_brutto_ha || 0) / 100;
    candidate.geometryException = { included: 'Neuwerk', source: candidate.sources.gebiet, sourceYear: String(neuwerk.properties?.jahr || ''), note: 'Neuwerk wird aufgrund der amtlich dokumentierten rettungsdienstlichen Zuständigkeit der Stadt Cuxhaven zugeschlagen.' };
  }
  candidate.einwohner = Math.round(einwohner);
  candidate.flaeche_km2 = Number(flaeche.toFixed(2));
  candidate.geojson = { type: 'FeatureCollection', features: features.map(compactFeature) };
  if (!candidate.gps || refreshGps) { candidate.gps = await geocode(candidate.address); await new Promise((r) => setTimeout(r, 1100)); }
  candidate.sources.gps = candidate.gps.sourceUrl;
  const errors = validateCandidate(candidate);
  if (errors.length) throw new Error(`${candidate.catalogKey}: ${errors.join('; ')}`);
}

catalog.builtAt = new Date().toISOString();
catalog.buildSources = { bkgRequests: Object.fromEntries(Object.entries(bkgLayers).filter(([,x]) => x.url).map(([k,x]) => [k, x.url.toString()])), hamburgNeuwerkWfs: 'https://geodienste.hamburg.de/wfs_regionalstatistische_daten_stadtteile' };
await writeFile(outputPath, `${JSON.stringify(catalog, null, 2)}\n`);
console.log(JSON.stringify({ output: outputPath, candidates: catalog.candidates.length, bkgFeatures: Object.fromEntries(Object.entries(bkgLayers).map(([k,x]) => [k,x.features.length])), referenceDate: catalog.metricSource.referenceDate }, null, 2));
