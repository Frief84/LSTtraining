<?php
if (!defined('ABSPATH')) { exit(); }

function lsttraining_sim_weather_types(): array {
    return ['clear', 'cloudy', 'rain', 'snow', 'storm', 'windy', 'fog', 'cold', 'hot'];
}

function lsttraining_sim_weather_label(string $type): string {
    return [
        'clear' => 'klar',
        'cloudy' => 'bewölkt',
        'rain' => 'Regen',
        'snow' => 'Schnee',
        'storm' => 'Sturm/Gewitter',
        'windy' => 'windig',
        'fog' => 'Nebel',
        'cold' => 'Kälte',
        'hot' => 'Hitze',
    ][$type] ?? $type;
}

function lsttraining_sim_weather_time_string(int $timestamp): string {
    return function_exists('lsttraining_sim_time_string') ? lsttraining_sim_time_string($timestamp) : wp_date('Y-m-d H:i:s', $timestamp);
}

function lsttraining_sim_weather_ts(?string $value): int {
    if (function_exists('lsttraining_sim_ts')) {
        return lsttraining_sim_ts($value);
    }
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, wp_timezone());
    if ($date instanceof DateTimeImmutable) {
        return $date->getTimestamp();
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, wp_timezone());
    if ($date instanceof DateTimeImmutable) {
        return $date->getTimestamp();
    }
    $timestamp = strtotime($value);
    return $timestamp ? (int) $timestamp : 0;
}

function lsttraining_sim_weather_values_from_code(int $code): array {
    $storm = in_array($code, [95, 96, 99], true);
    $snow = in_array($code, [71, 73, 75, 77, 85, 86], true);
    $rain = in_array($code, [51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82], true);
    $fog = in_array($code, [45, 48], true);
    $cloudy = in_array($code, [2, 3], true);
    return compact('storm', 'snow', 'rain', 'fog', 'cloudy');
}

function lsttraining_sim_weather_tags_from_values(array $values): array {
    $code = isset($values['weather_code']) ? (int) $values['weather_code'] : 0;
    $decoded = lsttraining_sim_weather_values_from_code($code);
    $temp = isset($values['temperature_c']) ? (float) $values['temperature_c'] : null;
    $apparent = isset($values['apparent_temperature_c']) ? (float) $values['apparent_temperature_c'] : $temp;
    $precip = max(0.0, (float) ($values['precipitation_mm'] ?? 0));
    $snowfall = max(0.0, (float) ($values['snowfall_cm'] ?? 0));
    $cloud = isset($values['cloud_cover']) ? (float) $values['cloud_cover'] : 0.0;
    $wind = isset($values['wind_kmh']) ? (float) $values['wind_kmh'] : 0.0;
    $gust = isset($values['gust_kmh']) ? (float) $values['gust_kmh'] : 0.0;
    $visibility = isset($values['visibility_m']) ? (float) $values['visibility_m'] : null;

    $tags = [];
    if ($decoded['storm'] || $gust >= 75.0) {
        $tags[] = 'storm';
    }
    if ($decoded['snow'] || $snowfall > 0.0 || ($precip > 0.1 && $temp !== null && $temp <= 1.0)) {
        $tags[] = 'snow';
    }
    if ($decoded['fog'] || ($visibility !== null && $visibility > 0 && $visibility <= 1800.0)) {
        $tags[] = 'fog';
    }
    if ($decoded['rain'] || $precip > 0.1) {
        $tags[] = 'rain';
    }
    if ($wind >= 35.0 || $gust >= 55.0) {
        $tags[] = 'windy';
    }
    if ($apparent !== null && $apparent >= 30.0) {
        $tags[] = 'hot';
    }
    if ($apparent !== null && $apparent <= 0.0) {
        $tags[] = 'cold';
    }
    if (!$tags && ($decoded['cloudy'] || $cloud >= 65.0)) {
        $tags[] = 'cloudy';
    }
    if (!$tags) {
        $tags[] = 'clear';
    }

    return array_values(array_intersect(lsttraining_sim_weather_types(), array_values(array_unique($tags))));
}

function lsttraining_sim_weather_primary(array $tags): string {
    $priority = ['storm', 'snow', 'fog', 'rain', 'windy', 'hot', 'cold', 'cloudy', 'clear'];
    foreach ($priority as $type) {
        if (in_array($type, $tags, true)) {
            return $type;
        }
    }
    return 'clear';
}

function lsttraining_sim_weather_severity(array $values, array $tags): float {
    $wind = (float) ($values['wind_kmh'] ?? 0);
    $gust = (float) ($values['gust_kmh'] ?? 0);
    $precip = (float) ($values['precipitation_mm'] ?? 0);
    $temp = isset($values['apparent_temperature_c']) ? (float) $values['apparent_temperature_c'] : (float) ($values['temperature_c'] ?? 12);
    $severity = 0.05;
    if (in_array('storm', $tags, true)) {
        $severity = max($severity, min(1.0, max($gust / 100.0, 0.65)));
    }
    if (in_array('windy', $tags, true)) {
        $severity = max($severity, min(0.8, max($wind / 70.0, $gust / 90.0)));
    }
    if (in_array('rain', $tags, true)) {
        $severity = max($severity, min(0.75, $precip / 8.0 + 0.2));
    }
    if (in_array('snow', $tags, true)) {
        $severity = max($severity, min(0.85, ((float) ($values['snowfall_cm'] ?? 0)) / 5.0 + 0.25));
    }
    if (in_array('hot', $tags, true)) {
        $severity = max($severity, min(0.75, ($temp - 28.0) / 12.0));
    }
    if (in_array('cold', $tags, true)) {
        $severity = max($severity, min(0.75, (2.0 - $temp) / 14.0));
    }
    if (in_array('fog', $tags, true)) {
        $severity = max($severity, 0.35);
    }
    return round(max(0.0, min(1.0, $severity)), 2);
}

function lsttraining_sim_weather_point(array $values): array {
    $tags = lsttraining_sim_weather_tags_from_values($values);
    return array_merge($values, [
        'primary' => lsttraining_sim_weather_primary($tags),
        'tags' => $tags,
        'severity' => lsttraining_sim_weather_severity($values, $tags),
    ]);
}

function lsttraining_sim_weather_fallback_values(int $timestamp, string $season, string $preferred = 'auto', int $seed = 0): array {
    $hour = (int) wp_date('G', $timestamp);
    $month = (int) wp_date('n', $timestamp);
    $base_temp = ['winter' => 2, 'spring' => 12, 'summer' => 23, 'autumn' => 11][$season] ?? 12;
    $daily = sin((($hour - 6) / 24) * 2 * M_PI) * 5.0;
    $roll = abs(crc32($seed . ':' . wp_date('Y-m-d-H', $timestamp) . ':weather')) % 100;
    $type = in_array($preferred, lsttraining_sim_weather_types(), true) ? $preferred : '';
    if ($type === '') {
        if ($season === 'winter' && $roll < 18) $type = 'snow';
        elseif ($season === 'summer' && $roll < 10) $type = 'storm';
        elseif ($roll < 18) $type = 'rain';
        elseif ($roll < 30) $type = 'cloudy';
        elseif ($roll < 38) $type = 'windy';
        elseif ($roll < 44 && in_array($month, [10, 11, 12, 1, 2], true)) $type = 'fog';
        elseif ($season === 'summer' && $roll > 90) $type = 'hot';
        elseif ($season === 'winter' && $roll > 88) $type = 'cold';
        else $type = 'clear';
    }
    $temp = $base_temp + $daily + (($roll % 7) - 3);
    if ($type === 'hot') {
        $temp = max($temp, 31.0 + (($roll % 5) / 2));
    } elseif ($type === 'cold') {
        $temp = min($temp, -2.0 - (($roll % 5) / 2));
    } elseif (in_array($type, ['rain', 'storm'], true)) {
        $temp = max($temp, 4.0);
    } elseif ($type === 'snow') {
        $temp = min($temp, 0.0);
    }
    $values = [
        'time' => lsttraining_sim_weather_time_string($timestamp),
        'temperature_c' => round($temp, 1),
        'apparent_temperature_c' => round($temp - ($type === 'windy' ? 2.0 : 0.0), 1),
        'precipitation_mm' => in_array($type, ['rain', 'storm'], true) ? round(0.4 + (($roll % 16) / 4), 1) : 0.0,
        'rain_mm' => in_array($type, ['rain', 'storm'], true) ? round(0.4 + (($roll % 12) / 4), 1) : 0.0,
        'showers_mm' => $type === 'storm' ? round(1.0 + (($roll % 10) / 3), 1) : 0.0,
        'snowfall_cm' => $type === 'snow' ? round(0.2 + (($roll % 8) / 4), 1) : 0.0,
        'weather_code' => ['clear' => 0, 'cloudy' => 3, 'rain' => 61, 'snow' => 71, 'storm' => 95, 'windy' => 2, 'fog' => 45, 'cold' => 2, 'hot' => 1][$type] ?? 0,
        'cloud_cover' => in_array($type, ['clear', 'hot', 'cold'], true) ? 20 : 82,
        'wind_kmh' => in_array($type, ['windy', 'storm'], true) ? 38 + ($roll % 18) : 8 + ($roll % 20),
        'gust_kmh' => in_array($type, ['storm'], true) ? 76 + ($roll % 20) : (in_array($type, ['windy'], true) ? 55 + ($roll % 18) : 14 + ($roll % 26)),
        'visibility_m' => $type === 'fog' ? 900 + (($roll % 8) * 80) : null,
    ];
    return lsttraining_sim_weather_point($values);
}

function lsttraining_sim_simulated_weather_forecast(array $leitstelle, array $settings, int $hours = 72): array {
    $start_ts = lsttraining_sim_weather_ts((string) ($settings['started_at'] ?? (($settings['start_date'] ?? '') . ' ' . ($settings['start_time'] ?? ''))));
    $start_ts = $start_ts > 0 ? $start_ts : time();
    $season = (string) ($settings['season'] ?? '');
    $preferred = (string) ($settings['weather'] ?? 'auto');
    $seed = (int) ($leitstelle['id'] ?? 0);
    $items = [];
    for ($i = 0; $i < $hours; $i++) {
        $hour_preferred = in_array($preferred, lsttraining_sim_weather_types(), true) ? $preferred : 'auto';
        $items[] = lsttraining_sim_weather_fallback_values($start_ts + ($i * 3600), $season, $hour_preferred, $seed);
    }
    return [
        'source' => 'simulated',
        'captured_at' => lsttraining_sim_weather_time_string(time()),
        'timezone' => wp_timezone_string(),
        'start_at' => lsttraining_sim_weather_time_string($start_ts),
        'hours' => $items,
    ];
}

function lsttraining_sim_capture_weather_forecast(array $leitstelle, array $settings): array {
    $lat = is_numeric($leitstelle['latitude'] ?? null) ? (float) $leitstelle['latitude'] : null;
    $lon = is_numeric($leitstelle['longitude'] ?? null) ? (float) $leitstelle['longitude'] : null;
    if (($settings['weather'] ?? 'auto') !== 'auto' || $lat === null || $lon === null || abs($lat) > 90 || abs($lon) > 180) {
        return lsttraining_sim_simulated_weather_forecast($leitstelle, $settings);
    }
    $url = add_query_arg([
        'latitude' => $lat,
        'longitude' => $lon,
        'current' => 'temperature_2m,apparent_temperature,precipitation,rain,showers,snowfall,weather_code,cloud_cover,wind_speed_10m,wind_gusts_10m',
        'hourly' => 'temperature_2m,apparent_temperature,precipitation,rain,showers,snowfall,weather_code,cloud_cover,wind_speed_10m,wind_gusts_10m,visibility',
        'forecast_days' => 3,
        'timezone' => wp_timezone_string(),
        'wind_speed_unit' => 'kmh',
        'precipitation_unit' => 'mm',
    ], 'https://api.open-meteo.com/v1/forecast');

    $response = wp_remote_get($url, ['timeout' => 4]);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
        return lsttraining_sim_simulated_weather_forecast($leitstelle, $settings);
    }
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    $hourly = is_array($data['hourly'] ?? null) ? $data['hourly'] : [];
    $times = is_array($hourly['time'] ?? null) ? $hourly['time'] : [];
    if (!$times) {
        return lsttraining_sim_simulated_weather_forecast($leitstelle, $settings);
    }
    $start_ts = lsttraining_sim_weather_ts((string) ($settings['started_at'] ?? (($settings['start_date'] ?? '') . ' ' . ($settings['start_time'] ?? ''))));
    $start_ts = $start_ts > 0 ? $start_ts : time();
    $items = [];
    foreach ($times as $idx => $time) {
        $ts = $start_ts + ((int) $idx * 3600);
        $items[] = lsttraining_sim_weather_point([
            'time' => lsttraining_sim_weather_time_string($ts),
            'source_time' => (string) $time,
            'temperature_c' => isset($hourly['temperature_2m'][$idx]) ? (float) $hourly['temperature_2m'][$idx] : null,
            'apparent_temperature_c' => isset($hourly['apparent_temperature'][$idx]) ? (float) $hourly['apparent_temperature'][$idx] : null,
            'precipitation_mm' => isset($hourly['precipitation'][$idx]) ? (float) $hourly['precipitation'][$idx] : 0.0,
            'rain_mm' => isset($hourly['rain'][$idx]) ? (float) $hourly['rain'][$idx] : 0.0,
            'showers_mm' => isset($hourly['showers'][$idx]) ? (float) $hourly['showers'][$idx] : 0.0,
            'snowfall_cm' => isset($hourly['snowfall'][$idx]) ? (float) $hourly['snowfall'][$idx] : 0.0,
            'weather_code' => isset($hourly['weather_code'][$idx]) ? (int) $hourly['weather_code'][$idx] : 0,
            'cloud_cover' => isset($hourly['cloud_cover'][$idx]) ? (float) $hourly['cloud_cover'][$idx] : 0.0,
            'wind_kmh' => isset($hourly['wind_speed_10m'][$idx]) ? (float) $hourly['wind_speed_10m'][$idx] : 0.0,
            'gust_kmh' => isset($hourly['wind_gusts_10m'][$idx]) ? (float) $hourly['wind_gusts_10m'][$idx] : 0.0,
            'visibility_m' => isset($hourly['visibility'][$idx]) ? (float) $hourly['visibility'][$idx] : null,
        ]);
    }
    return [
        'source' => 'open_meteo',
        'captured_at' => lsttraining_sim_weather_time_string(time()),
        'timezone' => (string) ($data['timezone'] ?? wp_timezone_string()),
        'start_at' => lsttraining_sim_weather_time_string($start_ts),
        'hours' => $items,
    ];
}

function lsttraining_sim_weather_point_for_timestamp(array $settings, int $timestamp): array {
    $forecast = is_array($settings['weather_forecast'] ?? null) ? $settings['weather_forecast'] : [];
    $hours = is_array($forecast['hours'] ?? null) ? $forecast['hours'] : [];
    $selected = null;
    $selected_ts = 0;
    $last_point = null;
    $last_ts = 0;
    foreach ($hours as $point) {
        if (!is_array($point)) {
            continue;
        }
        $ts = lsttraining_sim_weather_ts((string) ($point['time'] ?? ''));
        if ($ts > 0 && $ts >= $last_ts) {
            $last_point = $point;
            $last_ts = $ts;
        }
        if ($ts > 0 && $ts <= $timestamp) {
            $selected = $point;
            $selected_ts = $ts;
        } elseif ($ts > $timestamp) {
            break;
        }
    }
    if (is_array($last_point) && $last_ts > 0 && $timestamp >= ($last_ts + 3600)) {
        $selected = lsttraining_sim_weather_fallback_values(
            $timestamp,
            (string) ($settings['season'] ?? ''),
            (string) ($last_point['primary'] ?? ($settings['weather'] ?? 'auto')),
            (int) abs(crc32((string) ($last_point['time'] ?? '') . ':' . (string) ($last_point['primary'] ?? 'clear')))
        );
        $selected['source'] = 'simulated';
        $selected['extended_from_forecast'] = true;
        $selected_ts = $timestamp;
    }
    if (!$selected) {
        $selected = $hours[0] ?? null;
        $selected_ts = is_array($selected) ? lsttraining_sim_weather_ts((string) ($selected['time'] ?? '')) : 0;
    }
    if (!is_array($selected)) {
        $selected = lsttraining_sim_weather_fallback_values($timestamp, (string) ($settings['season'] ?? ''), (string) ($settings['weather'] ?? 'auto'));
        $selected_ts = $timestamp;
    }
    $selected['source'] = (string) ($forecast['source'] ?? 'simulated');
    if (!empty($selected['extended_from_forecast'])) {
        $selected['source'] = 'simulated';
    }
    $selected['timestamp'] = $selected_ts;
    $selected['label'] = lsttraining_sim_weather_label((string) ($selected['primary'] ?? 'clear'));
    return $selected;
}

function lsttraining_sim_weather_next_change(array $settings, int $timestamp): array {
    $current = lsttraining_sim_weather_point_for_timestamp($settings, $timestamp);
    $primary = (string) ($current['primary'] ?? '');
    $hours = is_array($settings['weather_forecast']['hours'] ?? null) ? $settings['weather_forecast']['hours'] : [];
    foreach ($hours as $point) {
        if (!is_array($point)) {
            continue;
        }
        $ts = lsttraining_sim_weather_ts((string) ($point['time'] ?? ''));
        if ($ts > $timestamp && (string) ($point['primary'] ?? '') !== $primary) {
            return [
                'time' => (string) ($point['time'] ?? ''),
                'primary' => (string) ($point['primary'] ?? ''),
                'label' => lsttraining_sim_weather_label((string) ($point['primary'] ?? '')),
            ];
        }
    }
    return [];
}

function lsttraining_sim_weather_factor(array $weather, string $domain = 'general'): float {
    $tags = is_array($weather['tags'] ?? null) ? $weather['tags'] : [(string) ($weather['primary'] ?? 'clear')];
    $severity = max(0.0, min(1.0, (float) ($weather['severity'] ?? 0.2)));
    $factor = 1.0;
    foreach ($tags as $tag) {
        if ($tag === 'storm') $factor += $domain === 'fw' ? 0.30 : 0.18;
        elseif ($tag === 'windy') $factor += $domain === 'fw' ? 0.16 : 0.08;
        elseif ($tag === 'rain') $factor += in_array($domain, ['rd', 'traffic'], true) ? 0.16 : 0.10;
        elseif ($tag === 'snow') $factor += in_array($domain, ['rd', 'traffic'], true) ? 0.22 : 0.12;
        elseif ($tag === 'fog') $factor += $domain === 'traffic' ? 0.18 : 0.08;
        elseif ($tag === 'hot') $factor += $domain === 'rd' ? 0.14 : 0.05;
        elseif ($tag === 'cold') $factor += $domain === 'rd' ? 0.12 : 0.06;
    }
    $factor = 1.0 + (($factor - 1.0) * max(0.35, $severity));
    return max(0.85, min(1.35, $factor));
}

function lsttraining_sim_weather_forecast_summary(array $settings, int $timestamp): array {
    $current = lsttraining_sim_weather_point_for_timestamp($settings, $timestamp);
    return [
        'source' => (string) ($current['source'] ?? 'simulated'),
        'label' => (string) ($current['label'] ?? ''),
        'primary' => (string) ($current['primary'] ?? 'clear'),
        'tags' => is_array($current['tags'] ?? null) ? $current['tags'] : [],
        'captured_at' => (string) ($settings['weather_forecast']['captured_at'] ?? ''),
        'next_change' => lsttraining_sim_weather_next_change($settings, $timestamp),
    ];
}
