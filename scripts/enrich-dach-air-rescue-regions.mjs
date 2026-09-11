#!/usr/bin/env node
import { readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const catalogPath = resolve('data/dach-air-rescue.json');
const cachePath = resolve('reports/dach-air-rescue-regions-cache.json');
const catalog = JSON.parse(await readFile(catalogPath, 'utf8'));
let cache = {};
try { cache = JSON.parse(await readFile(cachePath, 'utf8')); } catch {}

const regions = {
  'DE-BW':'Baden-Württemberg','DE-BY':'Bayern','DE-BE':'Berlin','DE-BB':'Brandenburg','DE-HB':'Bremen','DE-HH':'Hamburg',
  'DE-HE':'Hessen','DE-MV':'Mecklenburg-Vorpommern','DE-NI':'Niedersachsen','DE-NW':'Nordrhein-Westfalen','DE-RP':'Rheinland-Pfalz',
  'DE-SL':'Saarland','DE-SN':'Sachsen','DE-ST':'Sachsen-Anhalt','DE-SH':'Schleswig-Holstein','DE-TH':'Thüringen',
  'AT-1':'Burgenland','AT-2':'Kärnten','AT-3':'Niederösterreich','AT-4':'Oberösterreich','AT-5':'Salzburg','AT-6':'Steiermark',
  'AT-7':'Tirol','AT-8':'Vorarlberg','AT-9':'Wien',
  'CH-AG':'Aargau','CH-AI':'Appenzell Innerrhoden','CH-AR':'Appenzell Ausserrhoden','CH-BE':'Bern','CH-BL':'Basel-Landschaft',
  'CH-BS':'Basel-Stadt','CH-FR':'Freiburg','CH-GE':'Genf','CH-GL':'Glarus','CH-GR':'Graubünden','CH-JU':'Jura','CH-LU':'Luzern',
  'CH-NE':'Neuenburg','CH-NW':'Nidwalden','CH-OW':'Obwalden','CH-SG':'St. Gallen','CH-SH':'Schaffhausen','CH-SO':'Solothurn',
  'CH-SZ':'Schwyz','CH-TG':'Thurgau','CH-TI':'Tessin','CH-UR':'Uri','CH-VD':'Waadt','CH-VS':'Wallis','CH-ZG':'Zug','CH-ZH':'Zürich',
};
const aliases = new Map([
  ['baden-wurttemberg','Baden-Württemberg'],['north rhine-westphalia','Nordrhein-Westfalen'],['lower saxony','Niedersachsen'],
  ['mecklenburg-vorpommern','Mecklenburg-Vorpommern'],['saxony','Sachsen'],['saxony-anhalt','Sachsen-Anhalt'],
  ['thuringia','Thüringen'],['carinthia','Kärnten'],['lower austria','Niederösterreich'],['upper austria','Oberösterreich'],
  ['styria','Steiermark'],['tyrol','Tirol'],['vienna','Wien'],['vaud','Waadt'],['geneva','Genf'],['genève','Genf'],
  ['ticino','Tessin'],['valais','Wallis'],['canton of bern','Bern'],['kanton bern','Bern'],
]);
const stationOverrides = {
  // Rega 2 liegt auf dem binationalen EuroAirport physisch in Frankreich,
  // gehört betrieblich aber zur Schweizer Rega-Basis Basel.
  'ch-rthinfo-105': 'Basel-Stadt',
};
const normalize = (value='') => value.normalize('NFKD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/^canton de |^canton of |^kanton /,'').trim();
const sleep = (ms) => new Promise((resolveSleep) => setTimeout(resolveSleep, ms));

const unresolved = [];
for (let i = 0; i < catalog.stations.length; i += 1) {
  const station = catalog.stations[i];
  if (stationOverrides[station.sourceKey]) {
    station.region = stationOverrides[station.sourceKey];
    continue;
  }
  const key = `${station.latitude.toFixed(6)},${station.longitude.toFixed(6)}`;
  let address = cache[key];
  if (!address) {
    const url = new URL('https://nominatim.openstreetmap.org/reverse');
    url.search = new URLSearchParams({ format:'jsonv2', lat:String(station.latitude), lon:String(station.longitude), zoom:'5', addressdetails:'1' });
    const response = await fetch(url, { headers: { 'User-Agent':'LSTtraining DACH air rescue importer/1.0 (https://frief.de/)' }, signal:AbortSignal.timeout(20000) });
    if (!response.ok) throw new Error(`Nominatim HTTP ${response.status} für ${station.sourceKey}`);
    address = (await response.json()).address || {};
    cache[key] = address;
    await writeFile(cachePath, JSON.stringify(cache, null, 2) + '\n');
    await sleep(1100);
  }
  const iso = address['ISO3166-2-lvl4'] || address['ISO3166-2-lvl3'] || address['ISO3166-2-lvl6'] || '';
  const state = address.state || address.region || '';
  station.region = regions[iso] || aliases.get(normalize(state)) || state.replace(/^Kanton\s+/i, '');
  if (!station.region) unresolved.push({ source_key:station.sourceKey, state, iso, coordinates:key });
  if ((i + 1) % 10 === 0 || i + 1 === catalog.stations.length) console.log(`${i + 1}/${catalog.stations.length}`);
}

await writeFile(catalogPath, JSON.stringify(catalog, null, 2) + '\n');
console.log(JSON.stringify({ stations:catalog.stations.length, assigned:catalog.stations.length-unresolved.length, unresolved }, null, 2));
if (unresolved.length) process.exitCode = 2;
