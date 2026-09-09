import assert from 'node:assert/strict';
import test from 'node:test';
import {
  consolidateSites, mapDepartments, matchOfficialLocation, mergeSupplement, parseDelimited, parseQipCsv, parseSupplementCsv,
  stableSiteId, toApiPayload, validateHospital
} from '../scripts/lib/swiss-hospitals.mjs';

const allowed = new Set(['CARD', 'NEUR', 'OBST', 'PED', 'CAT']);
const mapping = {
  rules: [
    { code: 'CARD', patterns: ['Herzkrankheiten', 'Maladies cardiaques', 'Malattie cardiache'] },
    { code: 'NEUR', patterns: ['Neurologie'] },
    { code: 'OBST', patterns: ['Accouchement'] }
  ]
};

test('CSV-Parser verarbeitet Semikolon, Anführungszeichen und Zeilenumbrüche', () => {
  assert.deepEqual(parseDelimited('a;b\n"x;y";"z""q"\n'), [['a', 'b'], ['x;y', 'z"q']]);
});

test('BAG-Parser gruppiert französische Standortzeilen und positive Fallzahlen', () => {
  const csv = [
    'institution;indicator;nombre de cas 2024',
    'Hôpital Exemple, Rue Centrale 4, 1205 Genève;A Maladies cardiaques;',
    'Hôpital Exemple, Rue Centrale 4, 1205 Genève;A.1 Maladies cardiaques;12',
    'Hôpital Exemple, Rue Centrale 4, 1205 Genève;Neurologie;0',
    'Ospedale Esempio, Via Roma 2, 6900 Lugano;A Malattie cardiache;3'
  ].join('\n');
  const sites = parseQipCsv(csv, mapping, allowed);
  assert.equal(sites.length, 2);
  assert.equal(sites[0].postalCode, '1205');
  assert.deepEqual(sites[0].departments, ['CARD']);
  assert.deepEqual(sites[1].departments, ['CARD']);
});

test('BAG-Parser ignoriert wiederholte Sprachkopfzeilen und reine Reha-Standorte', () => {
  const csv = [
    'institution;indicator;nombre de cas 2024',
    'institution;indicator;nombre de cas 2024',
    'Clinique de Réadaptation, Rue du Lac 1, 1000 Lausanne;Neurologie;5',
    'Akutspital, Hauptstrasse 1, 8000 Zürich;Neurologie;4'
  ].join('\n');
  const sites = parseQipCsv(csv, mapping, allowed);
  assert.equal(sites.length, 1);
  assert.equal(sites[0].name, 'Akutspital');
});

test('BAG-Parser schließt Geburtshäuser in allen Landessprachen aus', () => {
  const csv = [
    'institution;indicator;nombre de cas 2024',
    'Geburtshaus Beispiel, Dorfstrasse 1, 3000 Bern;Accouchement;20',
    'Maison de naissance Exemple, Rue Centrale 2, 1000 Lausanne;Accouchement;20',
    'Casa maternità Esempio, Via Roma 3, 6900 Lugano;Accouchement;20',
    'Akutspital Beispiel, Spitalweg 4, 8000 Zürich;Maladies cardiaques;20'
  ].join('\n');
  const sites = parseQipCsv(csv, mapping, allowed);
  assert.equal(sites.length, 1);
  assert.equal(sites[0].name, 'Akutspital Beispiel');
});

test('Mapping ignoriert Überschriften und unbekannte Zielcodes', () => {
  const result = mapDepartments([
    { indicator: 'Maladies cardiaques', cases: null },
    { indicator: 'Neurologie', cases: 2 },
    { indicator: 'Accouchement', cases: 4 }
  ], { rules: [...mapping.rules, { code: 'UNKNOWN', patterns: ['Neurologie'] }] }, allowed);
  assert.deepEqual(result, ['NEUR', 'OBST']);
});

test('Stabile Standortkennung ist sprach- und schreibweisenrobust', () => {
  const first = stableSiteId({ name: 'Hôpital Exemple', address: 'Rue du Lac 1', postalCode: '1000', city: 'Lausanne' });
  const second = stableSiteId({ name: 'Hopital  Exemple', address: 'Rue-du-Lac 1', postalCode: '1000', city: 'Lausanne' });
  assert.equal(first, second);
  assert.match(toApiPayload({ siteId: first, name: 'Test', latitude: 46, longitude: 7, versorgungsstufe: 'Grundversorgung', trauma_level: 0, helipad: false, departments: [] }).poi_id, /^ch-bag-[a-f0-9]{20}$/);
});

test('Ergänzungsdaten überschreiben Koordinaten und explizite Fachbereiche', () => {
  const base = [{ siteId: 'abc', sourceKey: 'Klinik A', name: 'Klinik A', latitude: null, longitude: null, departments: [] }];
  const extra = parseSupplementCsv('site_id;name;latitude;longitude;acute;emergency;departments\nabc;Klinik A;47.1;8.2;true;true;CARD|CAT', allowed);
  const merged = mergeSupplement(base, extra);
  assert.equal(merged[0].latitude, 47.1);
  assert.deepEqual(merged[0].departments, ['CARD', 'CAT']);
});

test('Validierung weist fehlende Koordinaten und unbekannte Fachbereiche aus', () => {
  const errors = validateHospital({ siteId: 'abc', name: 'Test', latitude: null, longitude: 8, versorgungsstufe: 'Grundversorgung', trauma_level: 0, departments: ['NOPE'] }, allowed);
  assert.ok(errors.some((error) => error.includes('Breitengrad')));
  assert.ok(errors.some((error) => error.includes('NOPE')));
});

test('Doppelte Eingangsdaten führen zur selben poi_id', () => {
  const site = { name: 'Spital A', address: 'Hauptstrasse 1', postalCode: '8000', city: 'Zürich' };
  const ids = [site, { ...site }].map(stableSiteId);
  assert.equal(new Set(ids).size, 1);
});

test('Gleich adressierte BAG-Schreibweisen werden ohne doppelte poi_id zusammengeführt', () => {
  const sites = consolidateSites([
    { siteId: 'same', name: 'Hôpital A', address: 'Rue 1', postalCode: '1000', city: 'Lausanne', departments: ['CARD'], evidenceRows: 2 },
    { siteId: 'same', name: 'Hopital A', address: 'Rue 1', postalCode: '1000', city: 'Lausanne', departments: ['NEUR'], evidenceRows: 3 }
  ]);
  assert.equal(sites.length, 1);
  assert.deepEqual(sites[0].departments, ['CARD', 'NEUR']);
  assert.equal(sites[0].evidenceRows, 5);
});

test('Amtlicher Standortlayer ergänzt eine eindeutige Straße trotz fehlender Hausnummer', () => {
  const site = { name: 'LUKS Spitalbetriebe AG - LUKS Luzern', address: 'Spitalstrasse', postalCode: '6000', city: 'Luzern 16' };
  const matched = matchOfficialLocation(site, [
    { officialId: 1, name: 'LUKS Luzern', institution: 'Luzerner Kantonsspital', address: 'Spitalstrasse 12', postalCode: '6000', city: 'Luzern 16', latitude: 47.05, longitude: 8.3 },
    { officialId: 2, name: 'Andere Klinik', institution: 'Andere AG', address: 'Seestrasse 2', postalCode: '6000', city: 'Luzern', latitude: 47.04, longitude: 8.31 }
  ]);
  assert.equal(matched?.officialId, 1);
});

test('Amtlicher Standortlayer verwirft gleich bewertete Kandidaten', () => {
  const site = { name: 'Regionalspital', address: '', postalCode: '9000', city: 'St. Gallen' };
  const matched = matchOfficialLocation(site, [
    { officialId: 1, name: 'Regionalspital Ost', institution: '', address: 'A 1', postalCode: '9000', city: 'St. Gallen', latitude: 47.4, longitude: 9.3 },
    { officialId: 2, name: 'Regionalspital West', institution: '', address: 'B 2', postalCode: '9000', city: 'St. Gallen', latitude: 47.41, longitude: 9.31 }
  ]);
  assert.equal(matched, null);
});

test('Amtlicher Standortlayer akzeptiert doppelte Einrichtungen am identischen Kartenpunkt', () => {
  const site = { name: 'Spital Limmattal', address: 'Urdorferstrasse 100', postalCode: '8952', city: 'Schlieren' };
  const matched = matchOfficialLocation(site, [
    { officialId: 1, name: 'Spital Limmattal', institution: '', address: 'Urdorferstrasse 100', postalCode: '8952', city: 'Schlieren', latitude: 47.393, longitude: 8.43 },
    { officialId: 2, name: 'Rehaklinik Limmattal', institution: '', address: 'Urdorferstrasse 100', postalCode: '8952', city: 'Schlieren', latitude: 47.3931, longitude: 8.4301 }
  ]);
  assert.equal(matched?.officialId, 1);
});
