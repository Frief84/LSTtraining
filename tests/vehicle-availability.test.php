<?php
require_once dirname(__DIR__) . '/includes/vehicle-availability.php';
function check_availability($condition, $message) { if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); } }
$tz = new DateTimeZone('Europe/Berlin');
$day = (new DateTimeImmutable('2026-07-15 08:00:00', $tz))->getTimestamp();
check_availability(lsttraining_vehicle_availability(['always' => true], $day, 48.1, 11.5)['available'], '24/7 fehlgeschlagen');
check_availability(lsttraining_vehicle_availability(['windows' => [['start' => '07:00', 'end' => '20:00']]], $day, 48.1, 11.5)['available'], 'Festzeit fehlgeschlagen');
$night = (new DateTimeImmutable('2026-07-15 02:00:00', $tz))->getTimestamp();
check_availability(!lsttraining_vehicle_availability(['windows' => [['start' => '07:00', 'end' => '20:00']]], $night, 48.1, 11.5)['available'], 'Nachtsperre fehlgeschlagen');
$winter = (new DateTimeImmutable('2026-01-10 12:00:00', $tz))->getTimestamp();
check_availability(lsttraining_vehicle_availability(['seasons' => [['from' => '12-01', 'to' => '04-15']], 'windows' => [['start' => '00:00', 'end' => '24:00']]], $winter, 47, 11)['available'], 'Jahreswechsel-Saison fehlgeschlagen');
echo "vehicle availability ok\n";
