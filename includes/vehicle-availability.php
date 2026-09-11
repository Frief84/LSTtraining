<?php
if (!defined('ABSPATH') && PHP_SAPI !== 'cli') { exit; }

/**
 * Evaluate a structured vehicle availability rule at a simulation timestamp.
 * Rules use local civil time at the vehicle base. Supported windows are fixed
 * HH:MM values or the solar tokens "sunrise" and "sunset".
 */
function lsttraining_vehicle_availability(array $rule, int $timestamp, float $latitude, float $longitude): array {
    if (!$rule || !empty($rule['always'])) {
        return ['available' => true, 'reason' => 'im Dienst'];
    }
    $timezone_name = (string) ($rule['timezone'] ?? 'Europe/Berlin');
    try { $timezone = new DateTimeZone($timezone_name); } catch (Throwable $e) { $timezone = new DateTimeZone('Europe/Berlin'); }
    $local = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);

    $seasons = is_array($rule['seasons'] ?? null) ? $rule['seasons'] : [];
    if ($seasons) {
        $md = $local->format('m-d');
        $in_season = false;
        foreach ($seasons as $season) {
            $from = (string) ($season['from'] ?? '01-01');
            $to = (string) ($season['to'] ?? '12-31');
            if (($from <= $to && $md >= $from && $md <= $to) || ($from > $to && ($md >= $from || $md <= $to))) {
                $in_season = true; break;
            }
        }
        if (!$in_season) return ['available' => false, 'reason' => 'außerhalb der Saison'];
    }

    $windows = is_array($rule['windows'] ?? null) ? $rule['windows'] : [];
    if (!$windows) return ['available' => false, 'reason' => 'Dienstzeit nicht freigegeben'];
    $weekday = (int) $local->format('N');
    $minute = ((int) $local->format('G') * 60) + (int) $local->format('i');
    foreach ($windows as $window) {
        $days = array_map('intval', is_array($window['days'] ?? null) ? $window['days'] : [1,2,3,4,5,6,7]);
        if (!in_array($weekday, $days, true)) continue;
        $start = lsttraining_vehicle_availability_minute((string) ($window['start'] ?? '00:00'), $timestamp, $latitude, $longitude, $timezone, true, (int) ($window['start_offset_min'] ?? 0));
        $end = lsttraining_vehicle_availability_minute((string) ($window['end'] ?? '24:00'), $timestamp, $latitude, $longitude, $timezone, false, (int) ($window['end_offset_min'] ?? 0));
        if ($start === null || $end === null) continue;
        if (($start <= $end && $minute >= $start && $minute < $end) || ($start > $end && ($minute >= $start || $minute < $end))) {
            return ['available' => true, 'reason' => 'im Dienst'];
        }
    }
    return ['available' => false, 'reason' => 'außer Dienst'];
}

function lsttraining_vehicle_availability_minute(string $value, int $timestamp, float $lat, float $lon, DateTimeZone $timezone, bool $is_start, int $offset): ?int {
    $value = strtolower(trim($value));
    if (in_array($value, ['sunrise', 'sunset'], true)) {
        $zenith = $value === 'sunrise' ? 90.833333 : 90.833333;
        $utc_hour = $value === 'sunrise'
            ? date_sunrise($timestamp, SUNFUNCS_RET_DOUBLE, $lat, $lon, $zenith, 0)
            : date_sunset($timestamp, SUNFUNCS_RET_DOUBLE, $lat, $lon, $zenith, 0);
        if ($utc_hour === false) return null;
        $midnight = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $solar = $midnight->modify('+' . (int) round($utc_hour * 60) . ' minutes')->setTimezone($timezone);
        return ((int) $solar->format('G') * 60) + (int) $solar->format('i') + $offset;
    }
    if ($value === '24:00') return 1440 + $offset;
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $match)) return null;
    $minute = ((int) $match[1] * 60) + (int) $match[2] + $offset;
    return max(0, min(1440, $minute));
}

function lsttraining_vehicle_availability_from_json($json, int $timestamp, float $latitude, float $longitude): array {
    $rule = is_array($json) ? $json : json_decode((string) $json, true);
    if (!is_array($rule) || !$rule) return ['available' => true, 'reason' => 'keine Dienstzeitbeschränkung'];
    return lsttraining_vehicle_availability($rule, $timestamp, $latitude, $longitude);
}
