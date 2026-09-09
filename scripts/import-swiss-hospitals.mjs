#!/usr/bin/env node
import { mkdir, readFile, readdir, writeFile } from 'node:fs/promises';
import { basename, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { consolidateSites, loadJson, matchOfficialLocation, mergeSupplement, normalizeText, parseQipCsv, parseSupplementCsv, toApiPayload, validateHospital } from './lib/swiss-hospitals.mjs';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const DEFAULT_SOURCE_URL = 'https://opendata.bagnet.ch/?r=/download&path=L3FpcDI0L3FpcDI0X3RhYmRhdGVuX3N0YW8uY3N2';
const OFFICIAL_LOCATION_URL = 'https://services3.arcgis.com/oPre3pOfRfefL8y0/arcgis/rest/services/Hospitals/FeatureServer/0/query?where=1%3D1&outFields=OBJECTID%2CFacility%2CAddress__Facility_%2CZIP__Facility_%2CTown__Facility_%2CInstitution&returnGeometry=true&outSR=4326&f=json';

function usage() {
  console.log(`Schweizer BAG-Zielkliniken importieren

  node scripts/import-swiss-hospitals.mjs --dry-run [--source-dir DIR] [--report-dir DIR]
  node scripts/import-swiss-hospitals.mjs --apply [--update-existing] [--source-dir DIR]

Umgebungsvariablen für API-Abgleich/Import:
  LSTTRAINING_API_BASE_URL=https://example.org/wp-json/lst/v1
  LSTTRAINING_API_USER=<wordpress-benutzer>
  LSTTRAINING_API_APP_PASSWORD=<application-password>

Optionale Ergänzungsdatei im source-dir: swiss-hospitals-supplement.csv
Spalten: site_id,name,address,postal_code,city,latitude,longitude,acute,emergency,
departments,versorgungsstufe,trauma_level,helipad,source_key`);
}

function parseArgs(argv) {
  const options = { mode: '', updateExisting: false, sourceDir: '', reportDir: join(root, 'reports'), noGeocode: false };
  for (let i = 0; i < argv.length; i += 1) {
    const arg = argv[i];
    if (arg === '--dry-run') options.mode = 'dry-run';
    else if (arg === '--apply') options.mode = 'apply';
    else if (arg === '--update-existing') options.updateExisting = true;
    else if (arg === '--no-geocode') options.noGeocode = true;
    else if (arg === '--source-dir') options.sourceDir = resolve(argv[++i] || '');
    else if (arg === '--report-dir') options.reportDir = resolve(argv[++i] || '');
    else if (arg === '--help' || arg === '-h') { usage(); process.exit(0); }
    else throw new Error(`Unbekanntes Argument: ${arg}`);
  }
  if (!options.mode) throw new Error('Genau einer der Modi --dry-run oder --apply ist erforderlich.');
  return options;
}

async function fetchText(url) {
  const response = await fetch(url, { headers: { 'User-Agent': 'LSTtraining Swiss hospital importer/1.0' }, signal: AbortSignal.timeout(120000) });
  if (!response.ok) throw new Error(`Download fehlgeschlagen: HTTP ${response.status}`);
  return response.text();
}

async function sourceFiles(options) {
  if (!options.sourceDir) return { qipName: 'qip24_tabdaten_stao.csv', qipText: await fetchText(DEFAULT_SOURCE_URL), supplementText: '' };
  const names = await readdir(options.sourceDir);
  const qipName = names.find((name) => /^qip.*tabdaten.*stao.*\.csv$/i.test(name)) || names.find((name) => /\.csv$/i.test(name) && !/supplement/i.test(name));
  if (!qipName) throw new Error(`Keine BAG-Standort-CSV in ${options.sourceDir} gefunden.`);
  const supplementName = names.find((name) => /^swiss-hospitals-supplement\.csv$/i.test(name));
  return {
    qipName, qipText: await readFile(join(options.sourceDir, qipName), 'utf8'),
    supplementText: supplementName ? await readFile(join(options.sourceDir, supplementName), 'utf8') : ''
  };
}

function geocodeQuery(site) {
  return [site.address, `${site.postalCode} ${site.city}`].filter(Boolean).join(', ').split(/\s+/).slice(0, 10).join(' ');
}

async function geocode(site) {
  const query = geocodeQuery(site);
  if (!query) return null;
  const url = new URL('https://api3.geo.admin.ch/rest/services/api/SearchServer');
  url.search = new URLSearchParams({ searchText: query, type: 'locations', origins: 'address', limit: '5', sr: '4326' });
  const response = await fetch(url, { headers: { 'User-Agent': 'LSTtraining Swiss hospital importer/1.0' }, signal: AbortSignal.timeout(20000) });
  if (!response.ok) throw new Error(`Geokodierung HTTP ${response.status}`);
  const data = await response.json();
  const candidates = (data.results || []).filter((item) => item?.attrs?.origin === 'address');
  const address = normalizeText(site.address);
  const exactAddress = candidates.filter((item) => normalizeText(String(item.attrs.label || '').replace(/<[^>]+>/g, '')).includes(address));
  const selected = exactAddress.length === 1 ? exactAddress[0] : (candidates.length === 1 ? candidates[0] : null);
  if (!selected) return null;
  const attrs = selected.attrs;
  const latitude = Number(attrs.lat ?? attrs.y);
  const longitude = Number(attrs.lon ?? attrs.x);
  return Number.isFinite(latitude) && Number.isFinite(longitude) ? { latitude, longitude, coordinateSource: 'geo.admin.ch-address', coordinateLabel: String(attrs.label || '').replace(/<[^>]+>/g, '') } : null;
}

async function officialFacilities() {
  const response = await fetch(OFFICIAL_LOCATION_URL, { headers: { 'User-Agent': 'LSTtraining Swiss hospital importer/1.0' }, signal: AbortSignal.timeout(30000) });
  if (!response.ok) throw new Error(`BAG-Standortlayer HTTP ${response.status}`);
  const data = await response.json();
  if (data.error || !Array.isArray(data.features)) throw new Error(data.error?.message || 'BAG-Standortlayer ist ungültig.');
  return data.features.map((feature) => ({
    officialId: feature.attributes.OBJECTID, name: feature.attributes.Facility || '', institution: feature.attributes.Institution || '',
    address: feature.attributes.Address__Facility_ || '', postalCode: feature.attributes.ZIP__Facility_ || '', city: feature.attributes.Town__Facility_ || '',
    longitude: Number(feature.geometry?.x), latitude: Number(feature.geometry?.y)
  }));
}

function apiConfig(required) {
  const base = String(process.env.LSTTRAINING_API_BASE_URL || '').replace(/\/$/, '');
  const user = String(process.env.LSTTRAINING_API_USER || '');
  const password = String(process.env.LSTTRAINING_API_APP_PASSWORD || '').replace(/\s/g, '');
  if (!base && !required) return null;
  if (!base.startsWith('https://')) throw new Error('LSTTRAINING_API_BASE_URL muss HTTPS verwenden.');
  if (!user || !password) throw new Error('API-Benutzer und Application Password fehlen.');
  return { base, authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}` };
}

async function apiRequest(config, path, init = {}) {
  const response = await fetch(`${config.base}${path}`, {
    ...init, headers: { Accept: 'application/json', Authorization: config.authorization, ...(init.body ? { 'Content-Type': 'application/json' } : {}), ...(init.headers || {}) },
    signal: AbortSignal.timeout(30000)
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok || body.ok === false) {
    const error = new Error(body.message || `API HTTP ${response.status}`);
    error.status = response.status; error.code = body.error || ''; throw error;
  }
  return body.data;
}

async function existingHospitals(config) {
  const items = [];
  for (let page = 1; ; page += 1) {
    const data = await apiRequest(config, `/verwaltung/krankenhaeuser?page=${page}&per_page=200`);
    items.push(...(data.items || []));
    if (page >= (data.pagination?.pages || 1)) return items;
  }
}

function csvEscape(value) {
  const string = Array.isArray(value) ? value.join('|') : String(value ?? '');
  return /[;"\r\n]/.test(string) ? `"${string.replaceAll('"', '""')}"` : string;
}

async function writeReport(options, report) {
  await mkdir(options.reportDir, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const base = join(options.reportDir, `swiss-hospitals-${stamp}`);
  await writeFile(`${base}.json`, `${JSON.stringify(report, null, 2)}\n`, 'utf8');
  const columns = ['status', 'poi_id', 'api_id', 'name', 'source_address', 'latitude', 'longitude', 'coordinate_source', 'coordinate_label', 'departments', 'message'];
  const csv = [columns.join(';'), ...report.items.map((item) => columns.map((column) => csvEscape(item[column])).join(';'))].join('\n');
  await writeFile(`${base}.csv`, `${csv}\n`, 'utf8');
  return [`${base}.json`, `${base}.csv`];
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  const departments = await loadJson(join(root, 'data', 'departments.json'));
  const mapping = await loadJson(join(root, 'data', 'swiss-hospital-department-map.json'));
  const allowedCodes = new Set(Object.keys(departments));
  const source = await sourceFiles(options);
  let sites = parseQipCsv(source.qipText, mapping, allowedCodes);
  if (source.supplementText) sites = mergeSupplement(sites, parseSupplementCsv(source.supplementText, allowedCodes));
  sites = consolidateSites(sites.filter((site) => site.acute || site.emergency));

  let facilities = [];
  if (!options.noGeocode) {
    try { facilities = await officialFacilities(); } catch (error) { console.warn(`Amtlicher BAG-Standortlayer nicht verfügbar: ${error.message}`); }
  }

  for (let index = 0; index < sites.length; index += 1) {
    const site = sites[index];
    if ((site.latitude === null || site.longitude === null) && !options.noGeocode) {
      try { Object.assign(site, await geocode(site) || {}); } catch (error) { site.geocodeError = error.message; }
      if (site.latitude === null || site.longitude === null) {
        const official = matchOfficialLocation(site, facilities);
        if (official) Object.assign(site, { latitude: official.latitude, longitude: official.longitude, coordinateSource: 'bag-fso-arcgis', coordinateLabel: `${official.name}, ${official.address}, ${official.postalCode} ${official.city}`, officialLocationId: official.officialId });
      }
      if (index < sites.length - 1) await new Promise((resolveDelay) => setTimeout(resolveDelay, 75));
    }
  }

  const api = apiConfig(options.mode === 'apply');
  const existing = api ? await existingHospitals(api) : [];
  const byPoi = new Map(existing.map((item) => [String(item.poi_id), item]));
  const manualByName = new Map(existing.filter((item) => !String(item.poi_id).startsWith('ch-bag-')).map((item) => [normalizeText(item.name), item]));
  const report = { generated_at: new Date().toISOString(), mode: options.mode, source: basename(source.qipName), mapping_version: mapping.version, summary: {}, items: [] };

  for (const site of sites) {
    const payload = toApiPayload(site);
    const errors = validateHospital(site, allowedCodes);
    const existingItem = byPoi.get(payload.poi_id);
    const manualConflict = manualByName.get(normalizeText(payload.name));
    const item = { status: '', poi_id: payload.poi_id, api_id: existingItem?.id || '', name: payload.name, source_address: [site.address, site.postalCode, site.city].filter(Boolean).join(', '), latitude: payload.latitude, longitude: payload.longitude, coordinate_source: site.coordinateSource || '', coordinate_label: site.coordinateLabel || '', departments: payload.departments, message: '' };
    try {
      if (errors.length) { item.status = 'unvollständig'; item.message = errors.join('; ') + (site.geocodeError ? `; ${site.geocodeError}` : ''); }
      else if (manualConflict) { item.status = 'möglicher_konflikt'; item.message = `Gleichnamiger manueller Datensatz #${manualConflict.id}`; }
      else if (existingItem && !options.updateExisting) { item.status = 'übersprungen'; item.message = 'poi_id bereits vorhanden'; }
      else if (options.mode === 'dry-run') item.status = existingItem ? 'würde_aktualisieren' : 'würde_anlegen';
      else if (existingItem) {
        const updatePayload = { ...payload }; delete updatePayload.poi_id;
        const result = await apiRequest(api, `/verwaltung/krankenhaeuser/${existingItem.id}`, { method: 'PATCH', body: JSON.stringify(updatePayload) });
        item.status = 'aktualisiert'; item.api_id = result.id;
      } else {
        const result = await apiRequest(api, '/verwaltung/krankenhaeuser', { method: 'POST', body: JSON.stringify(payload) });
        item.status = 'angelegt'; item.api_id = result.id;
      }
    } catch (error) { item.status = 'fehlgeschlagen'; item.message = `${error.status || ''} ${error.code || ''} ${error.message}`.trim(); }
    report.items.push(item);
  }
  report.summary = report.items.reduce((summary, item) => ({ ...summary, [item.status]: (summary[item.status] || 0) + 1 }), { gesamt: report.items.length });
  const paths = await writeReport(options, report);
  console.log(JSON.stringify(report.summary, null, 2));
  console.log(`Berichte: ${paths.join(', ')}`);
  if (report.items.some((item) => item.status === 'fehlgeschlagen')) process.exitCode = 2;
}

main().catch((error) => { console.error(`Import abgebrochen: ${error.message}`); process.exitCode = 1; });
