import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';

export function parseDelimited(text, delimiter = null) {
  const source = String(text).replace(/^\uFEFF/, '');
  const sep = delimiter || ((source.split(/\r?\n/, 1)[0].match(/;/g) || []).length >= (source.split(/\r?\n/, 1)[0].match(/,/g) || []).length ? ';' : ',');
  const rows = [];
  let row = [], value = '', quoted = false;
  for (let i = 0; i < source.length; i += 1) {
    const char = source[i];
    if (quoted) {
      if (char === '"' && source[i + 1] === '"') { value += '"'; i += 1; }
      else if (char === '"') quoted = false;
      else value += char;
    } else if (char === '"') quoted = true;
    else if (char === sep) { row.push(value); value = ''; }
    else if (char === '\n') { row.push(value.replace(/\r$/, '')); rows.push(row); row = []; value = ''; }
    else value += char;
  }
  if (value !== '' || row.length) { row.push(value.replace(/\r$/, '')); rows.push(row); }
  return rows.filter((item) => item.some((cell) => String(cell).trim() !== ''));
}

export function normalizeText(value) {
  return String(value || '').normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
}

export function parseNumber(value) {
  const normalized = String(value ?? '').trim().replace(/[’']/g, '').replace(/\s/g, '').replace(',', '.').replace(/%$/, '');
  if (!normalized || !/^-?\d+(?:\.\d+)?$/.test(normalized)) return null;
  const number = Number(normalized);
  return Number.isFinite(number) ? number : null;
}

export function splitInstitution(value) {
  const raw = String(value || '').trim();
  const parts = raw.split(',').map((part) => part.trim()).filter(Boolean);
  const locality = parts.at(-1)?.match(/^(\d{4})\s+(.+)$/u);
  if (!locality) return { name: raw, address: '', postalCode: '', city: '', raw };
  const candidates = parts.slice(0, -1);
  let addressIndex = -1;
  for (let index = candidates.length - 1; index >= 0; index -= 1) {
    const normalized = normalizeText(candidates[index]);
    if (/\d/.test(candidates[index]) && !/(?:postfach|case postale|casella postale)/.test(normalized)) { addressIndex = index; break; }
  }
  if (addressIndex < 1) addressIndex = candidates.length - 1;
  return {
    name: candidates.slice(0, addressIndex).join(', ').trim(), address: candidates[addressIndex]?.trim() || '',
    postalCode: locality[1], city: locality[2].trim(), raw
  };
}

export function stableSiteId(site) {
  const seed = normalizeText([site.name, site.address, site.postalCode, site.city].join('|'));
  return createHash('sha256').update(seed).digest('hex').slice(0, 20);
}

function significantTokens(value) {
  const ignored = new Set(['ag', 'sa', 'sagl', 'spital', 'hospital', 'hopital', 'hopitaux', 'clinique', 'klinik', 'ospedale', 'zentrum', 'centre']);
  return new Set(normalizeText(value).split(' ').filter((token) => token.length > 2 && !ignored.has(token)));
}

function tokenSimilarity(left, right) {
  const a = significantTokens(left), b = significantTokens(right);
  if (!a.size || !b.size) return 0;
  return [...a].filter((token) => b.has(token)).length / Math.min(a.size, b.size);
}

function streetKey(value) {
  return normalizeText(value).replace(/\b\d+[a-z]?\b/g, '').trim();
}

export function matchOfficialLocation(site, facilities) {
  const siteTown = normalizeText(site.city).replace(/\b\d+\b/g, '').trim();
  const siteZip = String(site.postalCode || '').match(/\d{4}/)?.[0] || '';
  const ranked = [];
  for (const facility of facilities) {
    const town = normalizeText(facility.city).replace(/\b\d+\b/g, '').trim();
    const zip = String(facility.postalCode || '').match(/\d{4}/)?.[0] || '';
    const townMatches = siteTown && town && (siteTown.includes(town) || town.includes(siteTown));
    const zipMatches = siteZip && zip && siteZip === zip;
    if (!townMatches && !zipMatches) continue;
    const exactAddress = normalizeText(site.address) && normalizeText(site.address) === normalizeText(facility.address);
    const sameStreet = streetKey(site.address).length >= 4 && streetKey(site.address) === streetKey(facility.address);
    const similarity = Math.max(tokenSimilarity(site.name, facility.name), tokenSimilarity(site.name, `${facility.institution} ${facility.name}`));
    const score = (townMatches ? 20 : 0) + (zipMatches ? 20 : 0) + (exactAddress ? 80 : 0) + (sameStreet ? 45 : 0) + Math.round(similarity * 50);
    if (Number.isFinite(facility.latitude) && Number.isFinite(facility.longitude)) ranked.push({ ...facility, score });
  }
  ranked.sort((a, b) => b.score - a.score);
  const best = ranked[0], second = ranked[1];
  if (!best || best.score < 70) return null;
  if (second && best.score - second.score < 15) {
    const tied = ranked.filter((candidate) => best.score - candidate.score < 15);
    const sameVerifiedPoint = tied.every((candidate) =>
      normalizeText(candidate.address) === normalizeText(site.address)
      && Math.abs(candidate.latitude - best.latitude) <= 0.0005
      && Math.abs(candidate.longitude - best.longitude) <= 0.0005
    );
    if (!sameVerifiedPoint) return null;
  }
  return best;
}

export function mapDepartments(indicators, mapping, allowedCodes) {
  const found = new Set();
  for (const item of indicators) {
    if (!(item.cases > 0)) continue;
    const text = normalizeText(item.indicator);
    for (const rule of mapping.rules || []) {
      if (!allowedCodes.has(rule.code)) continue;
      if ((rule.patterns || []).some((pattern) => text.includes(normalizeText(pattern)))) found.add(rule.code);
    }
  }
  return [...found].sort();
}

export function parseQipCsv(text, mapping, allowedCodes) {
  const rows = parseDelimited(text);
  if (rows.length < 2) return [];
  const headers = rows[0].map(normalizeText);
  const institutionIndex = headers.findIndex((h) => ['institution', 'institut', 'istituzione'].includes(h));
  const indicatorIndex = headers.findIndex((h) => ['indicator', 'indicateur', 'indikator', 'indicatore'].includes(h));
  let casesIndex = headers.findIndex((h) => /(?:nombre de cas|fallzahl|numero di casi|number of cases).*(?:2024)?/.test(h));
  if (casesIndex < 0) casesIndex = headers.length - 1;
  if (institutionIndex < 0 || indicatorIndex < 0) throw new Error('BAG-CSV: Spalten institution und indicator wurden nicht gefunden.');
  const grouped = new Map();
  for (const row of rows.slice(1)) {
    const institution = String(row[institutionIndex] || '').trim();
    if (!institution || ['institution', 'institut', 'istituzione', 'instituzione'].includes(normalizeText(institution))) continue;
    if (!grouped.has(institution)) grouped.set(institution, []);
    grouped.get(institution).push({ indicator: row[indicatorIndex] || '', cases: parseNumber(row[casesIndex]) });
  }
  return [...grouped.entries()].map(([institution, indicators]) => {
    const site = splitInstitution(institution);
    return {
      sourceKey: institution,
      siteId: stableSiteId(site),
      ...site,
      acute: !/(?:rehab|rehabilitation|readaptation|riabilitazione)/.test(normalizeText(institution)),
      emergency: indicators.some((item) => item.cases > 0 && /(?:notfall|urgence|emergenza)/.test(normalizeText(item.indicator))),
      latitude: null,
      longitude: null,
      versorgungsstufe: 'Grundversorgung',
      trauma_level: 0,
      helipad: false,
      departments: mapDepartments(indicators, mapping, allowedCodes),
      evidenceRows: indicators.filter((item) => item.cases > 0).length
    };
  }).filter((site) => {
    const name = normalizeText(site.name);
    const standalonePsychiatry = /(?:psychiatr|services psychiatriques|servizi psichiatrici)/.test(name);
    const birthCenter = /(?:geburtshaus|maison de naissance|centre de naissance|casa maternita|birth cent(?:er|re)|maternite alpine)/.test(name);
    return Boolean(site.name) && site.acute && !birthCenter && (!standalonePsychiatry || site.emergency);
  });
}

function boolValue(value, fallback = false) {
  if (value === undefined || value === null || value === '') return fallback;
  return ['1', 'true', 'yes', 'ja', 'oui', 'si'].includes(normalizeText(value));
}

export function parseSupplementCsv(text, allowedCodes) {
  const rows = parseDelimited(text);
  if (!rows.length) return [];
  const headers = rows[0].map((header) => normalizeText(header).replaceAll(' ', '_'));
  return rows.slice(1).map((row) => Object.fromEntries(headers.map((header, index) => [header, row[index] ?? '']))).map((item) => {
    const departments = String(item.departments || '').split(/[|,]/).map((code) => code.trim().toUpperCase()).filter(Boolean);
    const unknown = departments.filter((code) => !allowedCodes.has(code));
    if (unknown.length) throw new Error(`Ergänzungs-CSV enthält unbekannte Fachbereiche: ${unknown.join(', ')}`);
    const site = {
      siteId: item.site_id || '', name: item.name || '', address: item.address || '', postalCode: item.postal_code || '', city: item.city || ''
    };
    return {
      ...site,
      siteId: site.siteId || stableSiteId(site),
      sourceKey: item.source_key || '',
      latitude: parseNumber(item.latitude), longitude: parseNumber(item.longitude),
      acute: boolValue(item.acute, true), emergency: boolValue(item.emergency, false),
      versorgungsstufe: item.versorgungsstufe || item.care_level || 'Grundversorgung',
      trauma_level: parseNumber(item.trauma_level) ?? 0,
      helipad: boolValue(item.helipad), departments, evidenceRows: 0
    };
  });
}

export function mergeSupplement(sites, supplements) {
  const bySource = new Map(sites.map((site) => [normalizeText(site.sourceKey), site]));
  const byId = new Map(sites.map((site) => [site.siteId, site]));
  for (const extra of supplements) {
    const current = (extra.sourceKey && bySource.get(normalizeText(extra.sourceKey))) || byId.get(extra.siteId);
    if (current) Object.assign(current, Object.fromEntries(Object.entries(extra).filter(([, value]) => value !== '' && value !== null)));
    else sites.push(extra);
  }
  return sites;
}

export function consolidateSites(sites) {
  const consolidated = new Map();
  for (const site of sites) {
    const current = consolidated.get(site.siteId);
    if (!current) {
      consolidated.set(site.siteId, { ...site, departments: [...new Set(site.departments || [])], mergedSourceRows: 1 });
      continue;
    }
    const sameLocation = normalizeText(current.address) === normalizeText(site.address)
      && String(current.postalCode) === String(site.postalCode)
      && normalizeText(current.city) === normalizeText(site.city);
    if (!sameLocation) throw new Error(`Standortkennung ${site.siteId} bezeichnet widersprüchliche Adressen.`);
    current.departments = [...new Set([...(current.departments || []), ...(site.departments || [])])].sort();
    current.evidenceRows = (current.evidenceRows || 0) + (site.evidenceRows || 0);
    current.mergedSourceRows += 1;
  }
  return [...consolidated.values()];
}

export function validateHospital(site, allowedCodes) {
  const errors = [];
  if (!site.name) errors.push('Name fehlt');
  if (!site.siteId || !/^[A-Za-z0-9._:-]+$/.test(site.siteId)) errors.push('Standortkennung fehlt oder ist ungültig');
  if (!Number.isFinite(site.latitude) || site.latitude < -90 || site.latitude > 90) errors.push('Breitengrad fehlt oder ist ungültig');
  if (!Number.isFinite(site.longitude) || site.longitude < -180 || site.longitude > 180) errors.push('Längengrad fehlt oder ist ungültig');
  if (!['Grundversorgung', 'Schwerpunktversorger', 'Maximalversorger'].includes(site.versorgungsstufe)) errors.push('Versorgungsstufe ist ungültig');
  if (!Number.isInteger(site.trauma_level) || site.trauma_level < 0 || site.trauma_level > 9) errors.push('trauma_level ist ungültig');
  for (const code of site.departments || []) if (!allowedCodes.has(code)) errors.push(`Unbekannter Fachbereich ${code}`);
  return errors;
}

export function toApiPayload(site) {
  return {
    poi_id: `ch-bag-${site.siteId}`.slice(0, 50), name: site.name.slice(0, 255),
    land: 'Schweiz',
    latitude: site.latitude, longitude: site.longitude,
    versorgungsstufe: site.versorgungsstufe, trauma_level: site.trauma_level,
    helipad: Boolean(site.helipad), departments: [...new Set(site.departments || [])]
  };
}

export async function loadJson(path) {
  return JSON.parse((await readFile(path, 'utf8')).replace(/^\uFEFF/, ''));
}
