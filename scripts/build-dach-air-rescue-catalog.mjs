#!/usr/bin/env node
import { writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const base = 'https://www.rth.info/stationen.db';
const checkedAt = new Date().toISOString().slice(0, 10) + ' 00:00:00';
const categories = ['RTH', 'DUAL', 'ITH', 'SAR', 'EUR'];

const decode = (value = '') => value
  .replace(/<[^>]+>/g, ' ').replace(/&nbsp;/gi, ' ').replace(/&amp;/gi, '&')
  .replace(/&uuml;/gi, 'ü').replace(/&ouml;/gi, 'ö').replace(/&auml;/gi, 'ä')
  .replace(/&Uuml;/g, 'Ü').replace(/&Ouml;/g, 'Ö').replace(/&Auml;/g, 'Ä')
  .replace(/&szlig;/gi, 'ß').replace(/&#x([0-9a-f]+);/gi, (_, n) => String.fromCodePoint(parseInt(n, 16)))
  .replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(Number(n))).replace(/\s+/g, ' ').trim();
const slug = (value) => decode(value).normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
  .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
const get = async (url) => {
  const response = await fetch(url, { headers: { 'User-Agent': 'LSTtraining catalog builder/1.0' } });
  if (!response.ok) throw new Error(`${url}: HTTP ${response.status}`);
  return response.text();
};

const mapHtml = await get(`${base}/stationen.map.php`);
const markerRe = /north\s*=\s*([0-9.]+);\s*east\s*=\s*([0-9.]+);\s*setMarkerForBase\(north, east, '([^']*)', '([^']*)', (\d+)\)/g;
const markerById = new Map([...mapHtml.matchAll(markerRe)].map((m) => [Number(m[5]), {
  latitude: Number(m[1]), longitude: Number(m[2]), mapOperator: decode(m[3]), callsign: decode(m[4]),
}]));

const rows = [];
for (const category of categories) {
  const html = await get(`${base}/stationen.php?category=${category}`);
  for (const match of html.matchAll(/<tr\b[^>]*onclick="[^"]*station\.php\?id=(\d+)[\s\S]*?<\/tr>/gi)) {
    const id = Number(match[1]);
    const cells = [...match[0].matchAll(/<td\b[^>]*>([\s\S]*?)<\/td>/gi)].map((m) => decode(m[1]));
    const marker = markerById.get(id);
    if (!marker || cells.length < 6) continue;
    const city = cells[1].replace(/^(AT|CH)\s+/, '').trim();
    const country = /^AT\s/.test(cells[1]) ? 'AT' : /^CH\s/.test(cells[1]) ? 'CH' : category === 'EUR' ? '' : 'DE';
    if (!country) continue;
    rows.push({ id, category, country, callsign: cells[0].replace(/^📅\s*/, ''), city,
      site: cells[2], operator: cells[4] || marker.mapOperator, latitude: marker.latitude,
      longitude: marker.longitude, seasonal: /title="Saisonal"/i.test(match[0]),
      aroundTheClock: /24h\/Tag einsatzbereit/i.test(match[0]) });
  }
}

const operatorUrls = {
  ADAC: 'https://luftrettung.adac.de/stationen/', DRFL: 'https://www.drf-luftrettung.de/luftrettung/luftrettungsstationen',
  BMI: 'https://www.bbk.bund.de/DE/Themen/Ergaenzende-Ausstattung/Zivilschutzhubschrauber/zivilschutzhubschrauber_node.html',
  JUH: 'https://www.johanniter.de/juh/lv-hrs/wo-wir-in-unserer-region-vertreten-sind/johanniter-luftrettung/',
  BW: 'https://www.bundeswehr.de/de/meldungen/search-and-rescue-sar-fliegende-lebensretter-rettungsdienst',
  OAMTC: 'https://www.oeamtc.at/thema/flugrettung/', ARA: 'https://www.ara-flugrettung.at/',
  'Heli Austria': 'https://www.heli-austria.at/martin-flugrettung/standorte-stuetzpunkte-helikopter-pad.html',
  REGA: 'https://www.rega.ch/im-einsatz/standorte-infrastruktur', AAA: 'https://www.alpine-air-ambulance.ch/',
  AIRZ: 'https://www.air-zermatt.ch/', AIRGL: 'https://www.air-glaciers.ch/',
};
const operatorNames = { ADAC:'ADAC Luftrettung', DRFL:'DRF Luftrettung', BMI:'Bundesministerium des Innern/BBK', JUH:'Johanniter Luftrettung', BW:'Bundeswehr', OAMTC:'ÖAMTC-Flugrettung', ARA:'ARA Flugrettung', REGA:'Rega', AIRZ:'Air Zermatt', AIRGL:'Air-Glaciers' };

const review = [];
for (const row of rows.filter((item) => item.seasonal)) {
  review.push({ ...row, reason: 'Saisonstandort wird auf ausdrücklichen Wunsch mit vorläufigem Winterfenster 01.11.–30.04. importiert; exakte Betreiberzeiten sind nachzupflegen.' });
}
const countries = { DE: 'de', AT: 'at', CH: 'ch' };
const sourceKeyAliases = {
  1:'de-adac-christoph-01', 26:'de-adac-christoph-26', 71:'de-drf-bad-berka',
  80:'de-bundeswehr-sar-41', 195:'de-bundeswehr-sar-63', 86:'de-bundeswehr-sar-87',
  136:'at-oeamtc-christophorus-02', 112:'at-ara-rk-02', 104:'ch-rega-01',
  150:'ch-rega-08', 182:'ch-air-zermatt', 202:'at-ara-nassfeld',
};
const entries = rows.map((row) => {
  const role = row.category === 'SAR' ? 'SAR' : (row.category === 'ITH' || row.category === 'DUAL' || /\bITH\b/i.test(row.callsign)) ? 'ITH' : 'RTH';
  const timezone = row.country === 'AT' ? 'Europe/Vienna' : row.country === 'CH' ? 'Europe/Zurich' : 'Europe/Berlin';
  const dutyText = row.seasonal
    ? 'Saisonbetrieb; vorläufig 01.11.–30.04., Sonnenaufgang bis Sonnenuntergang (exakte Betreiberzeiten nachpflegen)'
    : row.aroundTheClock ? '24 Stunden' : 'Tagbetrieb; Sonnenaufgang bis Sonnenuntergang gemäß Betreiberangabe';
  const availabilityRule = row.seasonal
    ? { timezone, approximate: true, seasons: [{ from: '11-01', to: '04-30' }], windows: [{ start: 'sunrise', end: 'sunset' }] }
    : row.aroundTheClock ? { always: true, timezone } : { timezone, windows: [{ start: 'sunrise', end: 'sunset' }] };
  return {
    country: row.country, region: '', sourceKey: sourceKeyAliases[row.id] || `${countries[row.country]}-rthinfo-${row.id}`,
    name: `Luftrettungsstation ${row.city} – ${row.callsign}`, operator: operatorNames[row.operator] || row.operator,
    latitude: row.latitude, longitude: row.longitude, sourceUrl: operatorUrls[row.operator] || `${base}/station.php?id=${row.id}`,
    sourceCheckedAt: checkedAt, coordinateSource: `rth.info Stationskarte (Stations-ID ${row.id}), auf OSM-Basis`,
    vehicles: [{ callsign: row.callsign, type: role, dutyText, availabilityRule,
      sourceUrl: `${base}/station.php?id=${row.id}` }],
    _cityKey: slug(row.city.split('/')[0]), _id: row.id,
  };
});
const stations = [];
for (const entry of entries) {
  const existing = stations.find((candidate) => candidate.country === entry.country && candidate._cityKey === entry._cityKey
    && Math.abs(candidate.latitude - entry.latitude) < 0.015 && Math.abs(candidate.longitude - entry.longitude) < 0.015);
  if (existing) {
    existing.vehicles.push(...entry.vehicles);
    existing.name = `Luftrettungsstation ${entry.name.split(' – ')[0].replace(/^Luftrettungsstation /, '')}`;
    if (entry._id < existing._id) { existing._id = entry._id; existing.sourceKey = entry.sourceKey; }
  } else stations.push(entry);
}
for (const station of stations) { delete station._cityKey; delete station._id; }

const catalog = {
  version: checkedAt.slice(0, 10) + '.2',
  attribution: [
    'Stationsverzeichnis und Koordinaten: rth.info Stationsinfothek/Stationskarte',
    'Betreiber und allgemeine Dienstzeitmodelle: jeweilige Betreiberquelle',
    'Kartenkoordinaten basieren auf OpenStreetMap-Mitwirkenden',
  ],
  stations,
  review,
};
await writeFile(resolve('data/dach-air-rescue.json'), JSON.stringify(catalog, null, 2) + '\n');
console.log(JSON.stringify({ markers: markerById.size, parsed: rows.length, stations: stations.length, vehicles: stations.reduce((n, s) => n + s.vehicles.length, 0), review: review.length,
  countries: Object.fromEntries(['DE','AT','CH'].map((c) => [c, stations.filter((s) => s.country === c).length])) }, null, 2));
