import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeSourceKey, pointInGeoJson, stationPayload, validateStation, vehiclePayload } from '../scripts/lib/dach-air-rescue.mjs';

const station = { country: 'DE', sourceKey: 'de-test-christoph-1', name: 'Luftrettungszentrum Test', operator: 'Test', region: 'Bayern', latitude: 48.1, longitude: 11.5, sourceUrl: 'https://example.test/station', sourceCheckedAt: '2026-09-09 00:00:00', vehicles: [{ callsign: 'Christoph 1', type: 'RTH', dutyText: '07:00 bis Sonnenuntergang', availabilityRule: { timezone: 'Europe/Berlin', windows: [{ start: '07:00', end: 'sunset' }] } }] };

test('validiert vollständige Stationen und normalisiert Schlüssel', () => {
  assert.deepEqual(validateStation(station), []);
  assert.equal(normalizeSourceKey(' CH Rega 1 '), 'ch-rega-1');
});
test('ordnet Punkte in Polygonen und Löchern korrekt zu', () => {
  const polygon = { type: 'Polygon', coordinates: [[[10,47],[12,47],[12,49],[10,49],[10,47]], [[11,48],[11.2,48],[11.2,48.2],[11,48.2],[11,48]]] };
  assert.equal(pointInGeoJson(10.5, 48, polygon), true);
  assert.equal(pointInGeoJson(11.1, 48.1, polygon), false);
  assert.equal(pointInGeoJson(13, 48, polygon), false);
});
test('erzeugt stabile Wachen- und Fahrzeugpayloads', () => {
  assert.equal(stationPayload(station, [4]).typ, 'AIR');
  const vehicle = vehiclePayload(station, station.vehicles[0], 7);
  assert.equal(vehicle.source_key, 'de-test-christoph-1-christoph-1');
  assert.equal(vehicle.fahrzeugtyp, 'RTH');
});
