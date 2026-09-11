#!/usr/bin/env node
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { pointInGeoJson, stationPayload, validateStation, vehiclePayload } from './lib/dach-air-rescue.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const mode = args.includes('--apply') ? 'apply' : args.includes('--dry-run') ? 'dry-run' : '';
const updateExisting = args.includes('--update-existing');
const valueAfter = (flag, fallback) => { const i = args.indexOf(flag); return i >= 0 ? resolve(args[i + 1] || '') : fallback; };
if (!mode) throw new Error('Genau einer der Modi --dry-run oder --apply ist erforderlich.');
const sourcePath = valueAfter('--source', resolve(root, 'data/dach-air-rescue.json'));
const reportDir = valueAfter('--report-dir', resolve(root, 'reports'));

function apiConfig(required) {
  const base = String(process.env.LSTTRAINING_API_BASE_URL || '').replace(/\/$/, '');
  const user = String(process.env.LSTTRAINING_API_USER || '');
  const password = String(process.env.LSTTRAINING_API_APP_PASSWORD || '').replace(/\s/g, '');
  if (!base && !required) return null;
  if (!base.startsWith('https://') || !user || !password) throw new Error('HTTPS-API-URL, Benutzer und Application Password werden benötigt.');
  return { base, authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}` };
}
async function apiRequest(api, path, init = {}) {
  const response = await fetch(`${api.base}${path}`, { ...init, headers: { Accept: 'application/json', Authorization: api.authorization, ...(init.body ? { 'Content-Type': 'application/json' } : {}) }, signal: AbortSignal.timeout(30000) });
  const body = await response.json().catch(() => ({}));
  if (!response.ok || body.ok === false) throw new Error(body.message || `API HTTP ${response.status}`);
  return body.data;
}
async function listAll(api, resource) {
  const rows = [];
  for (let page = 1; ; page += 1) {
    const data = await apiRequest(api, `/verwaltung/${resource}?page=${page}&per_page=200`);
    rows.push(...(data.items || []));
    if (page >= Number(data.pagination?.pages || 1)) return rows;
  }
}
const source = JSON.parse(await readFile(sourcePath, 'utf8'));
const stations = Array.isArray(source.stations) ? source.stations : [];
const api = apiConfig(mode === 'apply');
const [leitstellen, existingStations, existingVehicles] = api ? await Promise.all([listAll(api, 'leitstellen'), listAll(api, 'wachen'), listAll(api, 'fahrzeuge')]) : [[], [], []];
const stationByKey = new Map(existingStations.filter((x) => x.source_key).map((x) => [x.source_key, x]));
const vehicleByKey = new Map(existingVehicles.filter((x) => x.source_key).map((x) => [x.source_key, x]));
const report = { generated_at: new Date().toISOString(), source_version: source.version, mode, attribution: source.attribution || [], items: [], manual_review: source.review || [] };

for (const station of stations) {
  const errors = validateStation(station);
  const assigned = leitstellen.filter((ls) => { try { return pointInGeoJson(Number(station.longitude), Number(station.latitude), ls.geojson); } catch { return false; } }).map((ls) => Number(ls.id));
  const item = { source_key: station.sourceKey, country: station.country, operator: station.operator, name: station.name, coordinates: `${station.latitude},${station.longitude}`, coordinate_source: station.coordinateSource || '', leitstellen: assigned, vehicles: [], status: '', errors };
  if (errors.length) { item.status = 'unvollständig'; report.items.push(item); continue; }
  const existing = stationByKey.get(station.sourceKey);
  if (mode === 'dry-run') item.status = existing ? (updateExisting ? 'würde_aktualisieren' : 'übersprungen') : 'würde_anlegen';
  else if (existing && !updateExisting) item.status = 'übersprungen';
  else {
    const payload = stationPayload(station, assigned);
    const saved = existing ? await apiRequest(api, `/verwaltung/wachen/${existing.id}`, { method: 'PATCH', body: JSON.stringify(payload) }) : await apiRequest(api, '/verwaltung/wachen', { method: 'POST', body: JSON.stringify(payload) });
    stationByKey.set(station.sourceKey, saved); item.status = existing ? 'aktualisiert' : 'angelegt';
  }
  const wacheId = Number((stationByKey.get(station.sourceKey) || existing)?.id || 0);
  for (const vehicle of station.vehicles) {
    const payload = vehiclePayload(station, vehicle, wacheId);
    const old = vehicleByKey.get(payload.source_key);
    let status = old ? (updateExisting ? 'würde_aktualisieren' : 'übersprungen') : 'würde_anlegen';
    if (mode === 'apply' && wacheId && (!old || updateExisting)) {
      const saved = old ? await apiRequest(api, `/verwaltung/fahrzeuge/${old.id}`, { method: 'PATCH', body: JSON.stringify(payload) }) : await apiRequest(api, '/verwaltung/fahrzeuge', { method: 'POST', body: JSON.stringify(payload) });
      vehicleByKey.set(payload.source_key, saved); status = old ? 'aktualisiert' : 'angelegt';
    }
    item.vehicles.push({ callsign: vehicle.callsign, type: vehicle.type, duty: vehicle.dutyText, seasonal: !!vehicle.availabilityRule?.seasons?.length, status });
  }
  if (!assigned.length) item.assignment_warning = 'Keine Leitstelle enthält den Standort.';
  report.items.push(item);
}
report.summary = { stations: report.items.length, vehicles: report.items.reduce((n, x) => n + x.vehicles.length, 0), incomplete: report.items.filter((x) => x.errors.length).length, manual_review: report.manual_review.length, unassigned: report.items.filter((x) => !x.leitstellen.length).length };
await mkdir(reportDir, { recursive: true });
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const jsonPath = resolve(reportDir, `dach-air-rescue-${stamp}.json`);
await writeFile(jsonPath, `${JSON.stringify(report, null, 2)}\n`);
const csvPath = jsonPath.replace(/\.json$/, '.csv');
const csv = ['source_key;country;operator;name;coordinates;vehicles;status;assignment_warning', ...report.items.map((x) => [x.source_key,x.country,x.operator,x.name,x.coordinates,x.vehicles.map((v) => `${v.callsign}:${v.type}`).join('|'),x.status,x.assignment_warning || ''].map((v) => `"${String(v ?? '').replaceAll('"','""')}"`).join(';'))].join('\n');
await writeFile(csvPath, `${csv}\n`);
console.log(JSON.stringify(report.summary, null, 2)); console.log(`Berichte: ${jsonPath}, ${csvPath}`);
if (report.summary.incomplete) process.exitCode = 2;
