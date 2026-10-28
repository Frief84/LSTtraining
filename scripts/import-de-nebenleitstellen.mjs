#!/usr/bin/env node
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { apiBaseUrl, assignWachen, candidatePayload, findPossibleDuplicates, parseGps, pointInGeoJson, validateCandidate } from './lib/de-nebenleitstellen.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const mode = args.includes('--apply') ? 'apply' : args.includes('--dry-run') ? 'dry-run' : '';
if (!mode || (args.includes('--apply') && args.includes('--dry-run'))) throw new Error('Genau einer der Modi --dry-run oder --apply ist erforderlich.');
const valueAfter = (flag, fallback) => { const i = args.indexOf(flag); return i >= 0 ? resolve(args[i + 1] || '') : fallback; };
const sourcePath = valueAfter('--source', resolve(root, 'data/de-nebenleitstellen.json'));
const reportDir = valueAfter('--report-dir', resolve(root, 'reports'));
const snapshotPath = valueAfter('--snapshot', resolve(reportDir, 'de-nebenleitstellen-api-snapshot.json'));
const useSnapshot = args.includes('--use-snapshot');
const refreshCandidateDetails = args.includes('--refresh-candidate-details');

function apiConfig() {
  const base = apiBaseUrl(process.env.LSTTRAINING_API_BASE_URL);
  const user = String(process.env.LSTTRAINING_API_USER || '').trim();
  const password = String(process.env.LSTTRAINING_API_APP_PASSWORD || '').replace(/\s/g, '');
  if (!user || !password) throw new Error('LSTTRAINING_API_USER und LSTTRAINING_API_APP_PASSWORD werden benötigt.');
  return { base, authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}` };
}

async function apiRequest(api, path, init = {}) {
  const response = await fetch(`${api.base}${path}`, { ...init, headers: { Accept: 'application/json', Authorization: api.authorization, ...(init.body ? { 'Content-Type': 'application/json' } : {}) }, signal: AbortSignal.timeout(60000) });
  const body = await response.json().catch(() => ({}));
  if (!response.ok || body.ok === false) throw new Error(body.message || `API HTTP ${response.status} ${path}`);
  return body.data;
}

async function listAll(api, resource) {
  const rows = [];
  for (let page = 1; ; page += 1) {
    const data = await apiRequest(api, `/verwaltung/${resource}?page=${page}&per_page=200`);
    rows.push(...(data.items || []));
    if (page === 1 || page % 10 === 0 || page >= Number(data.pagination?.pages || 1)) console.error(`[API] ${resource}: Seite ${page}/${Number(data.pagination?.pages || 1)}, ${rows.length}/${Number(data.pagination?.total || rows.length)}`);
    if (page >= Number(data.pagination?.pages || 1)) return rows;
  }
}

async function mapLimit(values, limit, fn) {
  const result = new Array(values.length); let cursor = 0;
  async function worker() { while (cursor < values.length) { const i = cursor++; result[i] = await fn(values[i], i); } }
  await Promise.all(Array.from({ length: Math.min(limit, values.length) }, worker));
  return result;
}

function csvCell(value) { return `"${String(value ?? '').replaceAll('"', '""')}"`; }
function canonicalJson(value) {
  if (Array.isArray(value)) return value.map(canonicalJson);
  if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalJson(value[key])]));
  return value;
}

function compareExisting(candidate, row, wacheIds) {
  const expected = candidatePayload(candidate, wacheIds); const differences = [];
  for (const field of ['name','land','bundesland','aufgaben','zustandigkeit','standorte']) if (String(row?.[field] ?? '') !== String(expected[field])) differences.push(field);
  if (Number(row?.einwohner) !== expected.einwohner) differences.push('einwohner');
  if (Math.abs(Number(row?.flaeche_km2) - expected.flaeche_km2) > 0.01) differences.push('flaeche_km2');
  const gps = parseGps(row?.gps);
  if (!gps || Math.abs(gps.latitude - candidate.gps.latitude) > 1e-5 || Math.abs(gps.longitude - candidate.gps.longitude) > 1e-5) differences.push('gps');
  if (!row?.geojson || JSON.stringify(canonicalJson(row.geojson)) !== JSON.stringify(canonicalJson(candidate.geojson))) differences.push('geojson');
  const actualWachen = [...(row?.relations?.wachen || [])].map(Number).sort((a,b) => a-b).join(',');
  const wantedWachen = [...wacheIds].map(Number).sort((a,b) => a-b).join(',');
  if (actualWachen !== wantedWachen) differences.push('wachen');
  return differences;
}

const catalog = JSON.parse(await readFile(sourcePath, 'utf8'));
const candidates = Array.isArray(catalog.candidates) ? catalog.candidates : [];
const api = apiConfig();
let existingSummary; let existing; let wachen;
if (useSnapshot) {
  const snapshot = JSON.parse(await readFile(snapshotPath, 'utf8'));
  if (Date.now() - Date.parse(snapshot.generated_at) > 6 * 60 * 60 * 1000) throw new Error('API-Snapshot ist älter als sechs Stunden. Bitte ohne --use-snapshot neu abrufen.');
  existingSummary = await listAll(api, 'nebenleitstellen');
  const currentIds = existingSummary.map((x) => Number(x.id)).sort((a,b) => a-b).join(',');
  const cachedIds = snapshot.nebenleitstellen.map((x) => Number(x.id)).sort((a,b) => a-b).join(',');
  if (currentIds !== cachedIds) throw new Error('Nebenleitstellenbestand hat sich seit dem Snapshot geändert. Bitte neuen Dry-Run ohne --use-snapshot ausführen.');
  existing = snapshot.nebenleitstellen; wachen = snapshot.wachen;
  console.error(`[API] Geprüften Snapshot verwendet: ${existing.length} Nebenleitstellen, ${wachen.length} Wachen`);
} else {
  [existingSummary, wachen] = await Promise.all([listAll(api, 'nebenleitstellen'), listAll(api, 'wachen')]);
  let detailDone = 0;
  existing = await mapLimit(existingSummary, 16, async (row) => {
    const detail = await apiRequest(api, `/verwaltung/nebenleitstellen/${row.id}`);
    detailDone += 1;
    if (detailDone % 20 === 0 || detailDone === existingSummary.length) console.error(`[API] Nebenleitstellen-Details: ${detailDone}/${existingSummary.length}`);
    return detail;
  });
  await mkdir(reportDir, { recursive: true });
  await writeFile(snapshotPath, `${JSON.stringify({ generated_at: new Date().toISOString(), nebenleitstellen: existing, wachen }, null, 2)}\n`);
}
if (refreshCandidateDetails) {
  const rows = await mapLimit(candidates, 8, async (candidate) => {
    const summary = existingSummary.find((row) => String(row.name) === candidate.name);
    if (!summary) return null;
    return apiRequest(api, `/verwaltung/nebenleitstellen/${summary.id}`);
  });
  for (const row of rows.filter(Boolean)) {
    const index = existing.findIndex((old) => Number(old.id) === Number(row.id));
    if (index >= 0) existing[index] = row; else existing.push(row);
  }
  console.error(`[API] Neu angelegte Kandidaten erneut als Detail gelesen: ${rows.filter(Boolean).length}/${candidates.length}`);
}
const beforeCount = existing.length;
const assignments = assignWachen(candidates, wachen);
const duplicateKeyOwners = new Map();
for (const c of candidates) for (const area of c.gebiet || []) {
  if (duplicateKeyOwners.has(area.key)) throw new Error(`Gebietsschlüssel ${area.key} ist doppelt: ${duplicateKeyOwners.get(area.key)} / ${c.catalogKey}`);
  duplicateKeyOwners.set(area.key, c.catalogKey);
}

const report = {
  generated_at: new Date().toISOString(), mode, source_version: catalog.version, api_base: api.base,
  api_snapshot: { nebenleitstellen: existing.length, wachen: wachen.length },
  sources: { metric: catalog.metricSource, build: catalog.buildSources || {} },
  exclusions: catalog.exclusions || [],
  catalog_review: catalog.review || [],
  assignment_review: { invalid_coordinates: assignments.invalid, boundary_hits: assignments.boundary, multiple_area_hits: assignments.multiple },
  items: []
};

for (const candidate of candidates) {
  const errors = validateCandidate(candidate);
  const duplicates = errors.length ? [] : findPossibleDuplicates(candidate, existing);
  const exactExisting = duplicates.some((d) => d.reasons.includes('name_or_alias'));
  const exactRow = exactExisting ? existing.find((row) => Number(row.id) === duplicates.find((d) => d.reasons.includes('name_or_alias')).id) : null;
  const locationInside = !errors.length && pointInGeoJson(candidate.gps.longitude, candidate.gps.latitude, candidate.geojson);
  if (!locationInside) errors.push('Leitstellenstandort liegt nicht im Einsatzgebiet');
  const wacheIds = assignments.byCandidate.get(candidate.catalogKey) || [];
  const differences = exactRow ? compareExisting(candidate, exactRow, wacheIds) : [];
  report.items.push({
    catalog_key: candidate.catalogKey, name: candidate.name, bundesland: candidate.bundesland,
    gebiet: candidate.gebiet, address: candidate.address, gps: candidate.gps,
    einwohner: candidate.einwohner, flaeche_km2: candidate.flaeche_km2,
    sources: candidate.sources, fallbacks: String(candidate.gps?.source || '').includes('fallback') ? ['GPS: OpenStreetMap Nominatim'] : [],
    wachen: wacheIds, possible_duplicates: duplicates, differences, errors,
    status: errors.length ? 'blockiert_unvollständig' : differences.length ? 'bestehend_abweichung' : exactExisting ? 'bestehend_übersprungen' : duplicates.length ? 'manuelle_prüfung' : mode === 'apply' ? 'bereit' : 'würde_anlegen'
  });
}

if (mode === 'apply') {
  const blocked = report.items.filter((x) => ['blockiert_unvollständig', 'manuelle_prüfung', 'bestehend_abweichung'].includes(x.status));
  if (blocked.length) {
    report.apply_aborted = `Vorprüfung blockiert ${blocked.length} Datensätze; es wurde nichts angelegt.`;
  } else {
    for (const item of report.items.filter((x) => x.status === 'bereit')) {
      const candidate = candidates.find((c) => c.catalogKey === item.catalog_key);
      const saved = await apiRequest(api, '/verwaltung/nebenleitstellen', { method: 'POST', body: JSON.stringify(candidatePayload(candidate, item.wachen)) });
      item.id = Number(saved.id); item.status = 'angelegt'; existing.push(saved);
    }
    const after = await listAll(api, 'nebenleitstellen');
    const createdCount = report.items.filter((x) => x.status === 'angelegt').length;
    report.api_snapshot_after = { nebenleitstellen: after.length, expected: beforeCount + createdCount, matched: after.length === beforeCount + createdCount };
    if (!report.api_snapshot_after.matched) throw new Error('Abschlussbestand entspricht nicht der Zahl der API-Neuanlagen.');
    await writeFile(snapshotPath, `${JSON.stringify({ generated_at: new Date().toISOString(), nebenleitstellen: existing, wachen }, null, 2)}\n`);
  }
}

report.summary = {
  candidates: report.items.length,
  would_create: report.items.filter((x) => x.status === 'würde_anlegen').length,
  created: report.items.filter((x) => x.status === 'angelegt').length,
  manual_review: report.items.filter((x) => x.status === 'manuelle_prüfung').length,
  blocked: report.items.filter((x) => x.status === 'blockiert_unvollständig').length,
  mismatched_existing: report.items.filter((x) => x.status === 'bestehend_abweichung').length,
  skipped_existing: report.items.filter((x) => x.status === 'bestehend_übersprungen').length,
  assigned_wachen: report.items.reduce((n, x) => n + x.wachen.length, 0),
  excluded: report.exclusions.length,
  open_catalog_review: report.catalog_review.length
};
await mkdir(reportDir, { recursive: true });
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const basename = `de-nebenleitstellen-${mode}-${stamp}`;
const jsonPath = resolve(reportDir, `${basename}.json`);
const csvPath = resolve(reportDir, `${basename}.csv`);
await writeFile(jsonPath, `${JSON.stringify(report, null, 2)}\n`);
const header = ['catalog_key','name','bundesland','status','id','einwohner','flaeche_km2','wachen','possible_duplicates','differences','errors'];
const lines = [header.map(csvCell).join(';'), ...report.items.map((x) => [x.catalog_key,x.name,x.bundesland,x.status,x.id || '',x.einwohner,x.flaeche_km2,x.wachen.join('|'),x.possible_duplicates.map((d) => `${d.id}:${d.name}`).join('|'),x.differences.join('|'),x.errors.join('|')].map(csvCell).join(';'))];
await writeFile(csvPath, `${lines.join('\n')}\n`);
console.log(JSON.stringify(report.summary, null, 2));
console.log(`Berichte: ${jsonPath}, ${csvPath}`);
if (report.summary.blocked || report.summary.manual_review || report.summary.mismatched_existing || report.apply_aborted) process.exitCode = 2;
