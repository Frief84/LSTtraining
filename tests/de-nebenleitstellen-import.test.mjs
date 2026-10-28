import test from 'node:test';
import assert from 'node:assert/strict';
import { apiBaseUrl, assignWachen, candidatePayload, findPossibleDuplicates, normalizeName, pointInGeoJson, validateCandidate } from '../scripts/lib/de-nebenleitstellen.mjs';

const square = (x1 = 0, y1 = 0, x2 = 10, y2 = 10) => ({ type: 'Polygon', coordinates: [[[x1,y1],[x2,y1],[x2,y2],[x1,y2],[x1,y1]]] });
const candidate = {
  catalogKey: 'de-test-musterstadt', name: 'Zentrale Leitstelle Musterstadt', aliases: ['Florian Musterstadt'],
  bundesland: 'Hessen', aufgaben: ['Brandschutz', 'Rettungsdienst'], gebiet: [{ key: '00000', name: 'Musterstadt' }],
  address: 'Teststraße 1', gps: { latitude: 5, longitude: 5 }, geojson: square(), einwohner: 100000, flaeche_km2: 100,
  sources: { bestand: 'https://example.org/a', aufgaben: 'https://example.org/b', adresse: 'https://example.org/c', gebiet: 'https://example.org/d' }
};

test('API-Basis ergänzt den WordPress-REST-Prefix genau einmal', () => {
  assert.equal(apiBaseUrl('https://frief.de/'), 'https://frief.de/wp-json/lst/v1');
  assert.equal(apiBaseUrl('https://frief.de/wp-json/lst/v1'), 'https://frief.de/wp-json/lst/v1');
});

test('Namensnormalisierung erkennt Funkrufnamen und Schreibvarianten', () => {
  assert.equal(normalizeName('Leitfunkstelle Mülheim'), normalizeName('Florian Muelheim'));
});

test('Polygonlöcher werden bei der Punktprüfung ausgeschlossen', () => {
  const area = { type: 'Polygon', coordinates: [square().coordinates[0], [[4,4],[6,4],[6,6],[4,6],[4,4]]] };
  assert.equal(pointInGeoJson(2, 2, area), true);
  assert.equal(pointInGeoJson(5, 5, area), false);
});

test('Dublettenprüfung berücksichtigt Aliase, Standort und Gebiet', () => {
  const matches = findPossibleDuplicates(candidate, [{ id: 7, name: 'Florian Musterstadt', gps: '5,5', zustandigkeit: 'Musterstadt', geojson: square() }]);
  assert.equal(matches.length, 1);
  assert.ok(matches[0].reasons.includes('name_or_alias'));
});

test('Wachenzuordnung blockiert Mehrfachtreffer', () => {
  const second = { ...candidate, catalogKey: 'de-test-zwei' };
  const result = assignWachen([candidate, second], [{ id: 9, latitude: 5, longitude: 5 }]);
  assert.equal(result.multiple.length, 1);
  assert.deepEqual(result.byCandidate.get(candidate.catalogKey), []);
});

test('Payload enthält vollständige Verwaltungsdaten und Relationen', () => {
  assert.deepEqual(validateCandidate(candidate), []);
  const payload = candidatePayload(candidate, [3, 4]);
  assert.equal(payload.land, 'Deutschland');
  assert.equal(payload.nachbarleitstelle, true);
  assert.deepEqual(payload.wachen, [3, 4]);
  assert.match(payload.zustandigkeit, /00000/);
});

test('verkürzte Einwohnerwerte blockieren den Import', () => {
  const errors = validateCandidate({ ...candidate, einwohner: 100 });
  assert.ok(errors.some((x) => x.includes('Einwohnerzahl')));
});
