<?php
if (!defined('ABSPATH')) { exit(); }

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/geo.php';
require_once dirname(__DIR__) . '/anrufer_names.php';

/*
 * Dynamisches Auto-Spawn-Beispiel in spielinstanzen.settings_json:
 * {
 *   "auto_spawn": true,
 *   "spawn_mode": "dynamic",
 *   "base_interval_min_sec": 60,
 *   "base_interval_max_sec": 300,
 *   "leitstelle_load_factor": 1.0,
 *   "max_active_einsaetze": 6
 * }
 *
 * Wirkung: nachts laenger, tagsueber normal, Rushhour kuerzer,
 * Sommer/Winter leicht erhoeht und jeder Abstand zufaellig.
 */

function lsttraining_sim_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function lsttraining_sim_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function lsttraining_sim_decode_json($raw, $fallback = []) {
    if ($raw === null || $raw === '') {
        return $fallback;
    }

    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function lsttraining_sim_speed_multiplier($value): int {
    $speed = (int) $value;
    return in_array($speed, [1, 2, 5], true) ? $speed : 1;
}

function lsttraining_sim_ts(?string $value): int {
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, wp_timezone());
    if ($date instanceof DateTimeImmutable) {
        return $date->getTimestamp();
    }

    $timestamp = strtotime($value);
    return $timestamp ? (int) $timestamp : 0;
}

function lsttraining_sim_time_string(int $timestamp): string {
    return wp_date('Y-m-d H:i:s', $timestamp);
}

function lsttraining_sim_initial_game_timestamp(array $settings, array $instance = [], int $real_now = 0): int {
    $real_now = $real_now > 0 ? $real_now : time();
    $configured = trim((string) (($settings['start_date'] ?? '') . ' ' . ($settings['start_time'] ?? '')));
    if ($configured !== '') {
        $timestamp = lsttraining_sim_ts(strlen($configured) <= 16 ? $configured . ':00' : $configured);
        if ($timestamp > 0) {
            return $timestamp;
        }
    }

    $started = lsttraining_sim_ts((string) ($instance['started_at'] ?? ($settings['started_at'] ?? '')));
    return $started > 0 ? $started : $real_now;
}

function lsttraining_sim_runtime_state(array $settings, array $instance = [], int $real_now = 0): array {
    $real_now = $real_now > 0 ? $real_now : time();
    $speed = lsttraining_sim_speed_multiplier($settings['sim_speed_multiplier'] ?? 1);
    $paused = (string) ($instance['sim_state'] ?? '') === 'paused' || !empty($settings['sim_paused']);
    $anchor_game = lsttraining_sim_ts((string) ($settings['sim_anchor_game_at'] ?? ''));
    if ($anchor_game <= 0) {
        $anchor_game = lsttraining_sim_initial_game_timestamp($settings, $instance, $real_now);
    }
    $anchor_real = lsttraining_sim_ts((string) ($settings['sim_anchor_real_at'] ?? ''));
    if ($anchor_real <= 0) {
        $anchor_real = lsttraining_sim_ts((string) ($instance['started_at'] ?? ($settings['started_at'] ?? '')));
    }
    if ($anchor_real <= 0) {
        $anchor_real = $real_now;
    }

    $game_now = $paused ? $anchor_game : $anchor_game + (int) floor(max(0, $real_now - $anchor_real) * $speed);
    return [
        'speed' => $speed,
        'paused' => $paused,
        'real_now_ts' => $real_now,
        'game_now_ts' => $game_now,
        'sim_now' => lsttraining_sim_time_string($game_now),
        'anchor_game_ts' => $anchor_game,
        'anchor_real_ts' => $anchor_real,
        'anchor_game_at' => lsttraining_sim_time_string($anchor_game),
        'anchor_real_at' => lsttraining_sim_time_string($anchor_real),
    ];
}

function lsttraining_sim_materialize_runtime_settings(array $settings, array $instance = [], ?int $speed = null, ?bool $paused = null, int $real_now = 0): array {
    $runtime = lsttraining_sim_runtime_state($settings, $instance, $real_now);
    $settings['sim_anchor_game_at'] = $runtime['sim_now'];
    $settings['sim_anchor_real_at'] = lsttraining_sim_time_string($runtime['real_now_ts']);
    $settings['sim_speed_multiplier'] = $speed !== null ? lsttraining_sim_speed_multiplier($speed) : $runtime['speed'];
    $settings['sim_paused'] = $paused !== null ? (bool) $paused : $runtime['paused'];
    return $settings;
}

function lsttraining_sim_reset_speed_settings(array $settings, array $instance = [], int $real_now = 0): array {
    return lsttraining_sim_materialize_runtime_settings($settings, $instance, 1, false, $real_now);
}

function lsttraining_sim_resource_class_labels(): array {
    return [
        'rettungswagen' => 'Rettungswagen',
        'krankentransport' => 'Krankentransportwagen',
        'notarzt' => 'Notarztmittel',
        'loeschfahrzeug' => 'Löschfahrzeug',
        'hubrettung' => 'Hubrettungsfahrzeug',
        'ruestung' => 'Rüst-/Hilfeleistungsfahrzeug',
        'fuehrung' => 'Führungsfahrzeug',
        'logistik' => 'Logistik',
        'gefahrgut' => 'Gefahrgut',
        'atemschutz_messung' => 'Atemschutz/Messung',
        'san_betreuung' => 'Sanitäts-/Betreuungskomponente',
        'thw_bergung' => 'THW-Bergung',
        'thw_fuehrung' => 'THW-Führung',
        'thw_logistik' => 'THW-Logistik',
        'sonderkomponente' => 'Sonderkomponente',
    ];
}

function lsttraining_sim_resource_class_label(string $type): string {
    $class = lsttraining_sim_resource_class_from_type($type);
    $labels = lsttraining_sim_resource_class_labels();
    return $class !== '' && isset($labels[$class]) ? $labels[$class] : trim($type);
}

function lsttraining_sim_resource_class_from_type(string $type): string {
    $raw = trim($type);
    if ($raw === '') {
        return '';
    }

    $canonical = strtolower((string) preg_replace('/[^a-zA-Z0-9_]+/', '_', $raw));
    $canonical = trim($canonical, '_');
    $labels = lsttraining_sim_resource_class_labels();
    if (isset($labels[$canonical])) {
        return $canonical;
    }

    $value = strtoupper($raw);
    if (strpos($value, 'THW MTW') !== false || strpos($value, 'TRUPPFÜHRER') !== false || strpos($value, 'TRUPPFUEHRER') !== false) {
        return 'thw_fuehrung';
    }
    if (strpos($value, 'THW LKW') !== false || strpos($value, 'MZGW') !== false || strpos($value, 'MLW') !== false) {
        return 'thw_logistik';
    }
    if (strpos($value, 'THW') === 0 || strpos($value, 'GKW') !== false) {
        return 'thw_bergung';
    }
    if (preg_match('/^(NEF|NAW|RTH|ITH|BABY-NAW)/', $value)) {
        return 'notarzt';
    }
    if (preg_match('/^(KTW|KTW-B|KTW-4)/', $value)) {
        return 'krankentransport';
    }
    if (preg_match('/^(RTW|NKTW|ITW|GRTW)/', $value)) {
        return 'rettungswagen';
    }
    if (strpos($value, 'GW-SAN') !== false || strpos($value, 'BETREU') !== false || strpos($value, 'MANV') !== false || strpos($value, 'SAN') !== false) {
        return 'san_betreuung';
    }
    if (strpos($value, 'DEKON') !== false || strpos($value, 'GEFAHR') !== false || strpos($value, 'GW-G') !== false) {
        return 'gefahrgut';
    }
    if (strpos($value, 'ATEMSCHUTZ') !== false || strpos($value, 'GW-MESS') !== false || strpos($value, 'MESS') !== false) {
        return 'atemschutz_messung';
    }
    if (preg_match('/^(DLK|DLA|TMB)/', $value)) {
        return 'hubrettung';
    }
    if (preg_match('/^(RW|VRW|VLF)/', $value)) {
        return 'ruestung';
    }
    if (preg_match('/^(ELW|KDOW|KDO|ORGL|LNA)/', $value)) {
        return 'fuehrung';
    }
    if (strpos($value, 'LOGISTIK') !== false || strpos($value, 'GW-L') !== false || strpos($value, 'WLF') !== false || strpos($value, 'AB-') === 0) {
        return 'logistik';
    }
    if (preg_match('/^(HLF|LF|TLF|FLF|LÖSCHBOOT|LOESCHBOOT|FLB)/', $value)) {
        return 'loeschfahrzeug';
    }

    return 'sonderkomponente';
}

function lsttraining_sim_normalize_required_resources($raw): array {
    $rows = is_array($raw) ? $raw : lsttraining_sim_decode_json($raw, []);
    if (isset($rows['resources']) && is_array($rows['resources'])) {
        $rows = $rows['resources'];
    } elseif ($rows && array_keys($rows) !== range(0, count($rows) - 1)) {
        $assoc = [];
        foreach ($rows as $type => $count) {
            $assoc[] = ['type' => $type, 'count' => $count];
        }
        $rows = $assoc;
    }

    $summary = [];
    foreach ((array) $rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $type = trim((string) ($row['type'] ?? ($row['vehicle_type'] ?? ($row['fahrzeugtyp'] ?? ''))));
        $class = lsttraining_sim_resource_class_from_type($type);
        if ($class === '') {
            continue;
        }
        $count = max(1, (int) ($row['count'] ?? ($row['amount'] ?? ($row['anzahl'] ?? 1))));
        $summary[$class] = ($summary[$class] ?? 0) + $count;
    }

    $out = [];
    foreach ($summary as $class => $count) {
        $out[] = [
            'type' => $class,
            'label' => lsttraining_sim_resource_class_label($class),
            'count' => $count,
        ];
    }
    return $out;
}

function lsttraining_sim_patient_status_from_percent(int $percent): string {
    if ($percent <= 0) {
        return 'deceased';
    }
    return $percent >= 100 ? 'transport_ready' : 'in_care';
}

function lsttraining_sim_default_triage_for_patient(array $patient): string {
    $percent = max(0, min(100, (int) ($patient['care_progress_percent'] ?? ($patient['percent'] ?? 50))));
    if ($percent <= 0) {
        return 'V';
    }
    if (!empty($patient['requires_notarzt'])) {
        return 'I';
    }
    if (!empty($patient['requires_rtw'])) {
        return 'II';
    }
    return 'III';
}

function lsttraining_sim_hospital_department_catalog(): array {
    static $catalog = null;
    if (is_array($catalog)) {
        return $catalog;
    }

    $catalog = [];
    $path = defined('LSTTRAINING_PATH')
        ? LSTTRAINING_PATH . 'data/departments.json'
        : dirname(__DIR__, 2) . '/data/departments.json';
    if (!is_readable($path)) {
        return $catalog;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $catalog;
    }

    foreach ($decoded as $code => $details) {
        $normalized = strtoupper(trim((string) $code));
        if ($normalized !== '') {
            $catalog[$normalized] = is_array($details) ? $details : [];
        }
    }

    return $catalog;
}

function lsttraining_sim_normalize_hospital_department($value): string {
    $code = strtoupper(trim((string) $value));
    return $code !== '' && array_key_exists($code, lsttraining_sim_hospital_department_catalog()) ? $code : '';
}

function lsttraining_sim_normalize_patients($raw, array $fallback_requirements = []): array {
    $rows = is_array($raw) ? $raw : lsttraining_sim_decode_json($raw, []);
    if (isset($rows['patients']) && is_array($rows['patients'])) {
        $rows = $rows['patients'];
    }

    $patients = [];
    foreach ((array) $rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $progress = max(0, min(100, (int) ($row['care_progress_percent'] ?? ($row['progress_percent'] ?? ($row['percent'] ?? 50)))));
        $target = max(1, min(100, (int) ($row['care_target_percent'] ?? ($row['target_percent'] ?? 100))));
        $patient = [
            'patient_id' => (string) ($row['patient_id'] ?? ('p' . ((int) $index + 1))),
            'label' => trim((string) ($row['label'] ?? ('Patient ' . ((int) $index + 1)))),
            'triage_category' => strtoupper(trim((string) ($row['triage_category'] ?? ''))),
            'injury_summary' => trim((string) ($row['injury_summary'] ?? ($row['description'] ?? ''))),
            'requires_ktw' => !empty($row['requires_ktw']),
            'requires_rtw' => !empty($row['requires_rtw']),
            'requires_notarzt' => !empty($row['requires_notarzt']),
            'preferred_hospital_department' => lsttraining_sim_normalize_hospital_department($row['preferred_hospital_department'] ?? ''),
            'care_progress_percent' => $progress,
            'care_target_percent' => $target,
            'patient_status' => (string) ($row['patient_status'] ?? ''),
            'transport_ready' => false,
            'transport_status' => (string) ($row['transport_status'] ?? 'none'),
            'transport_vehicle_status_id' => (int) ($row['transport_vehicle_status_id'] ?? 0),
            'transport_vehicle_event_id' => (int) ($row['transport_vehicle_event_id'] ?? 0),
            'transport_vehicle_rufname' => trim((string) ($row['transport_vehicle_rufname'] ?? '')),
            'transport_hospital_id' => (int) ($row['transport_hospital_id'] ?? 0),
            'transport_hospital_name' => trim((string) ($row['transport_hospital_name'] ?? '')),
            'transport_department' => strtoupper(trim((string) ($row['transport_department'] ?? ''))),
            'transport_started_at' => (string) ($row['transport_started_at'] ?? ''),
            'transport_arrived_at' => (string) ($row['transport_arrived_at'] ?? ''),
            'handover_completed_at' => (string) ($row['handover_completed_at'] ?? ''),
            'transport_note' => trim((string) ($row['transport_note'] ?? '')),
        ];
        if (!in_array($patient['triage_category'], ['I', 'II', 'III', 'IV', 'V'], true)) {
            $patient['triage_category'] = lsttraining_sim_default_triage_for_patient($patient);
        }
        if ($progress <= 0) {
            $patient['patient_status'] = 'deceased';
            $patient['triage_category'] = 'V';
            $patient['transport_ready'] = false;
            $patient['requires_ktw'] = false;
            $patient['requires_rtw'] = false;
            $patient['requires_notarzt'] = false;
        } else {
            $patient['patient_status'] = $patient['patient_status'] !== '' ? $patient['patient_status'] : lsttraining_sim_patient_status_from_percent($progress);
            $patient['transport_ready'] = $progress >= $target;
        }
        $patients[] = $patient;
    }

    if ($patients) {
        return $patients;
    }

    $total = max(0, (int) ($fallback_requirements['total'] ?? 0));
    $ktw = max(0, (int) ($fallback_requirements['ktw'] ?? 0));
    $rtw = max(0, (int) ($fallback_requirements['rtw'] ?? 0));
    $notarzt = max(0, (int) ($fallback_requirements['notarzt'] ?? 0));
    $count = max($total, $ktw + $rtw, $notarzt, 0);
    for ($i = 0; $i < $count; $i++) {
        $requires_notarzt = $i < $notarzt;
        $requires_rtw = $i < $rtw || $requires_notarzt;
        $requires_ktw = !$requires_rtw && $i < ($rtw + $ktw);
        $patients[] = [
            'patient_id' => 'p' . ($i + 1),
            'label' => 'Patient ' . ($i + 1),
            'triage_category' => $requires_notarzt ? 'I' : ($requires_rtw ? 'II' : 'III'),
            'injury_summary' => '',
            'requires_ktw' => $requires_ktw,
            'requires_rtw' => $requires_rtw,
            'requires_notarzt' => $requires_notarzt,
            'preferred_hospital_department' => '',
            'care_progress_percent' => 50,
            'care_target_percent' => 100,
            'patient_status' => 'in_care',
            'transport_ready' => false,
        ];
    }
    return $patients;
}

function lsttraining_sim_patient_requirements_from_resources(array $resources, int $total = 0): array {
    $requirements = ['total' => max(0, $total), 'ktw' => 0, 'rtw' => 0, 'notarzt' => 0];
    foreach (lsttraining_sim_normalize_required_resources($resources) as $row) {
        $type = (string) ($row['type'] ?? '');
        $count = max(0, (int) ($row['count'] ?? 0));
        if ($type === 'krankentransport') {
            $requirements['ktw'] += $count;
        } elseif ($type === 'rettungswagen') {
            $requirements['rtw'] += $count;
        } elseif ($type === 'notarzt') {
            $requirements['notarzt'] += $count;
        }
    }
    $requirements['total'] = max($requirements['total'], $requirements['ktw'] + $requirements['rtw'], $requirements['notarzt']);
    return $requirements;
}

function lsttraining_sim_required_resources_from_patient_text(string $text): array {
    $counts = ['krankentransport' => 0, 'rettungswagen' => 0, 'notarzt' => 0];
    if (preg_match_all('/(\d+)\s*x?\s*(ktw|krankentransport|rtw|rettungswagen|notarzt|notarztmittel|nef|rth|naw|ith)/iu', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $count = max(0, (int) ($match[1] ?? 0));
            $type = strtolower((string) ($match[2] ?? ''));
            if ($type === 'ktw' || $type === 'krankentransport') {
                $counts['krankentransport'] += $count;
            } elseif ($type === 'rtw' || $type === 'rettungswagen') {
                $counts['rettungswagen'] += $count;
            } elseif (in_array($type, ['notarzt', 'notarztmittel', 'nef', 'rth', 'naw', 'ith'], true)) {
                $counts['notarzt'] += $count;
            }
        }
    }

    $labels = [
        'krankentransport' => 'Krankentransportwagen',
        'rettungswagen' => 'Rettungswagen',
        'notarzt' => 'Notarztmittel',
    ];
    $resources = [];
    foreach ($counts as $type => $count) {
        if ($count > 0) {
            $resources[] = ['type' => $type, 'label' => $labels[$type], 'count' => $count];
        }
    }
    return $resources;
}

function lsttraining_sim_load_area(PDO $pdo, int $leitstelle_id): array {
    $stmt = $pdo->prepare('
        SELECT id, name, ort, bundesland, latitude, longitude, geojson
        FROM leitstellen
        WHERE id = ?
        LIMIT 1
    ');
    $stmt->execute([$leitstelle_id]);
    $leitstelle = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$leitstelle) {
        throw new RuntimeException('Leitstelle nicht gefunden.');
    }

    $geojson = trim((string) ($leitstelle['geojson'] ?? ''));
    if ($geojson === '') {
        throw new RuntimeException('Kein gültiges Einsatzgebiet vorhanden.');
    }

    $decoded = json_decode($geojson, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Kein gültiges Einsatzgebiet vorhanden.');
    }

    $mpoly = lst_geo_to_multipolygon($decoded);
    $mpoly = lst_normalize_mpoly_to_wgs84($mpoly);
    if (!$mpoly) {
        throw new RuntimeException('Kein gültiges Einsatzgebiet vorhanden.');
    }

    return [
        'leitstelle' => $leitstelle,
        'mpoly' => $mpoly,
        'bbox' => lst_mpoly_bbox($mpoly),
    ];
}

function lsttraining_sim_point_inside_area(array $point, array $area): bool {
    return lst_point_in_mpoly([(float) $point[0], (float) $point[1]], $area['mpoly']);
}

function lsttraining_sim_bbox_intersects(array $a, array $b): bool {
    return !(
        (float) $a[2] < (float) $b[0] ||
        (float) $a[0] > (float) $b[2] ||
        (float) $a[3] < (float) $b[1] ||
        (float) $a[1] > (float) $b[3]
    );
}

function lsttraining_sim_random_float(float $min, float $max): float {
    if ($max <= $min) {
        return $min;
    }
    return $min + (($max - $min) * (mt_rand() / mt_getrandmax()));
}

function lsttraining_sim_weighted_pick(array $items): ?array {
    $sum = 0;
    foreach ($items as $item) {
        $sum += max(0, (int) ($item['weight'] ?? 0));
    }

    if ($sum <= 0) {
        return null;
    }

    $needle = mt_rand(1, $sum);
    $cursor = 0;
    foreach ($items as $item) {
        $cursor += max(0, (int) ($item['weight'] ?? 0));
        if ($needle <= $cursor) {
            return $item;
        }
    }

    return $items[array_key_last($items)] ?? null;
}

function lsttraining_sim_time_factor(DateTimeImmutable $sim_time): float {
    $hour = (int) $sim_time->format('G');

    if ($hour >= 0 && $hour < 5) {
        return 0.45;
    }

    if ($hour >= 5 && $hour < 8) {
        return 0.80;
    }

    if ($hour >= 8 && $hour < 16) {
        return 1.00;
    }

    if ($hour >= 16 && $hour < 20) {
        return 1.35;
    }

    if ($hour >= 20 && $hour < 23) {
        return 1.10;
    }

    return 0.75;
}

function lsttraining_sim_season_factor(string $season): float {
    switch ($season) {
        case 'winter':
            return 1.10;

        case 'spring':
            return 0.95;

        case 'summer':
            return 1.15;

        case 'autumn':
            return 1.05;

        default:
            return 1.00;
    }
}

function lsttraining_sim_next_spawn_delay(array $settings, DateTimeImmutable $sim_time): int {
    $min = max(10, (int) ($settings['base_interval_min_sec'] ?? 60));
    $max = max($min, (int) ($settings['base_interval_max_sec'] ?? 300));

    $base_random_delay = mt_rand($min, $max);

    $leitstelle_factor = max(0.1, (float) ($settings['leitstelle_load_factor'] ?? 1.0));
    $time_factor = lsttraining_sim_time_factor($sim_time);
    $season_factor = lsttraining_sim_season_factor((string) ($settings['season'] ?? ''));

    $load_factor = max(0.1, $leitstelle_factor * $time_factor * $season_factor);

    return max(10, (int) round($base_random_delay / $load_factor));
}

function lsttraining_sim_random_point_in_ring(array $ring, array $area, int $max_attempts = 80): ?array {
    $bbox = lst_mpoly_bbox([$ring]);
    if (!$bbox || $bbox[0] === $bbox[2] || $bbox[1] === $bbox[3]) {
        return null;
    }

    for ($i = 0; $i < $max_attempts; $i++) {
        $lon = lsttraining_sim_random_float((float) $bbox[0], (float) $bbox[2]);
        $lat = lsttraining_sim_random_float((float) $bbox[1], (float) $bbox[3]);
        $point = [$lon, $lat];
        if (lst_pip_ring($point, $ring) && lsttraining_sim_point_inside_area($point, $area)) {
            return ['longitude' => $lon, 'latitude' => $lat];
        }
    }

    $centroid = lst_polygon_centroid($ring);
    if (lst_pip_ring($centroid, $ring) && lsttraining_sim_point_inside_area($centroid, $area)) {
        return ['longitude' => (float) $centroid[0], 'latitude' => (float) $centroid[1]];
    }

    return null;
}

function lsttraining_sim_random_point_in_area(array $area, int $max_attempts = 160): ?array {
    $bbox = $area['bbox'];
    for ($i = 0; $i < $max_attempts; $i++) {
        $lon = lsttraining_sim_random_float((float) $bbox[0], (float) $bbox[2]);
        $lat = lsttraining_sim_random_float((float) $bbox[1], (float) $bbox[3]);
        if (lsttraining_sim_point_inside_area([$lon, $lat], $area)) {
            return ['longitude' => $lon, 'latitude' => $lat];
        }
    }

    foreach ($area['mpoly'] as $ring) {
        $point = lsttraining_sim_random_point_in_ring($ring, $area, 30);
        if ($point) {
            return $point;
        }
    }

    return null;
}

function lsttraining_sim_feature_mpoly(array $feature): array {
    $geometry = $feature['geometry'] ?? null;
    if (!is_array($geometry)) {
        return [];
    }

    $mpoly = lst_geo_to_multipolygon($geometry);
    return lst_normalize_mpoly_to_wgs84($mpoly);
}

function lsttraining_sim_feature_candidate_point(array $feature, array $area): ?array {
    $mpoly = lsttraining_sim_feature_mpoly($feature);
    foreach ($mpoly as $ring) {
        $point = lsttraining_sim_random_point_in_ring($ring, $area, 80);
        if ($point) {
            return $point;
        }
    }

    return null;
}

function lsttraining_sim_valid_lon_lat($coord): ?array {
    if (!is_array($coord) || count($coord) < 2 || !is_numeric($coord[0]) || !is_numeric($coord[1])) {
        return null;
    }

    $lon = (float) $coord[0];
    $lat = (float) $coord[1];
    if ($lon < -180.0 || $lon > 180.0 || $lat < -90.0 || $lat > 90.0) {
        return null;
    }

    return [$lon, $lat];
}

function lsttraining_sim_segment_bbox_intersects_area(array $a, array $b, array $area): bool {
    $bbox = $area['bbox'];
    $min_lon = min($a[0], $b[0]);
    $max_lon = max($a[0], $b[0]);
    $min_lat = min($a[1], $b[1]);
    $max_lat = max($a[1], $b[1]);

    return !($max_lon < $bbox[0] || $min_lon > $bbox[2] || $max_lat < $bbox[1] || $min_lat > $bbox[3]);
}

function lsttraining_sim_line_segments_from_geometry(array $geometry): array {
    $type = (string) ($geometry['type'] ?? '');
    $coords = $geometry['coordinates'] ?? null;
    $lines = [];

    if ($type === 'LineString' && is_array($coords)) {
        $lines[] = $coords;
    } elseif ($type === 'MultiLineString' && is_array($coords)) {
        foreach ($coords as $line) {
            if (is_array($line)) {
                $lines[] = $line;
            }
        }
    }

    $segments = [];
    foreach ($lines as $line) {
        $previous = null;
        foreach ($line as $coord) {
            $current = lsttraining_sim_valid_lon_lat($coord);
            if (!$current) {
                $previous = null;
                continue;
            }

            if ($previous) {
                $segments[] = [$previous, $current];
            }
            $previous = $current;
        }
    }

    return $segments;
}

function lsttraining_sim_feature_line_candidate_point(array $feature, array $area): ?array {
    $geometry = $feature['geometry'] ?? null;
    if (!is_array($geometry)) {
        return null;
    }

    $segments = array_values(array_filter(
        lsttraining_sim_line_segments_from_geometry($geometry),
        static function (array $segment) use ($area): bool {
            return lsttraining_sim_segment_bbox_intersects_area($segment[0], $segment[1], $area);
        }
    ));

    if (!$segments) {
        return null;
    }

    for ($i = 0; $i < 24; $i++) {
        $segment = $segments[array_rand($segments)];
        $t = lsttraining_sim_random_float(0.0, 1.0);
        $lon = $segment[0][0] + (($segment[1][0] - $segment[0][0]) * $t);
        $lat = $segment[0][1] + (($segment[1][1] - $segment[0][1]) * $t);

        if (lsttraining_sim_point_inside_area([$lon, $lat], $area)) {
            return [
                'longitude' => $lon,
                'latitude' => $lat,
                'road_segment_start' => $segment[0],
                'road_segment_end' => $segment[1],
                'road_bearing_deg' => lsttraining_sim_bearing_deg($segment[0], $segment[1]),
            ];
        }
    }

    foreach ($segments as $segment) {
        $lon = ($segment[0][0] + $segment[1][0]) / 2.0;
        $lat = ($segment[0][1] + $segment[1][1]) / 2.0;
        if (lsttraining_sim_point_inside_area([$lon, $lat], $area)) {
            return [
                'longitude' => $lon,
                'latitude' => $lat,
                'road_segment_start' => $segment[0],
                'road_segment_end' => $segment[1],
                'road_bearing_deg' => lsttraining_sim_bearing_deg($segment[0], $segment[1]),
            ];
        }
    }

    return null;
}

function lsttraining_sim_bearing_deg(array $from, array $to): ?float {
    if (count($from) < 2 || count($to) < 2) {
        return null;
    }

    $lon1 = deg2rad((float) $from[0]);
    $lat1 = deg2rad((float) $from[1]);
    $lon2 = deg2rad((float) $to[0]);
    $lat2 = deg2rad((float) $to[1]);
    $dLon = $lon2 - $lon1;
    $y = sin($dLon) * cos($lat2);
    $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLon);
    $bearing = rad2deg(atan2($y, $x));
    return fmod($bearing + 360.0, 360.0);
}

function lsttraining_sim_geo_distance_m(float $lon1, float $lat1, float $lon2, float $lat2): float {
    $earth = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLon / 2) ** 2);
    return 2 * $earth * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
}

function lsttraining_sim_distance_point_to_segment_m(float $lon, float $lat, array $a, array $b): float {
    $scale = max(0.1, cos(deg2rad($lat)));
    $px = $lon * $scale;
    $py = $lat;
    $ax = ((float) $a[0]) * $scale;
    $ay = (float) $a[1];
    $bx = ((float) $b[0]) * $scale;
    $by = (float) $b[1];

    $dx = $bx - $ax;
    $dy = $by - $ay;
    if ($dx == 0.0 && $dy == 0.0) {
        return lsttraining_sim_geo_distance_m($lon, $lat, (float) $a[0], (float) $a[1]);
    }

    $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / (($dx * $dx) + ($dy * $dy));
    $t = max(0.0, min(1.0, $t));
    $closestLon = ((float) $a[0]) + ((((float) $b[0]) - ((float) $a[0])) * $t);
    $closestLat = ((float) $a[1]) + ((((float) $b[1]) - ((float) $a[1])) * $t);

    return lsttraining_sim_geo_distance_m($lon, $lat, $closestLon, $closestLat);
}

function lsttraining_sim_readable_road_name(array $properties): string {
    $highway = trim((string) ($properties['highway'] ?? ''));
    $ref = trim((string) ($properties['ref'] ?? ''));
    if (lsttraining_sim_spawn_is_motorway_road($highway, $ref, (string) ($properties['name'] ?? ''))) {
        $motorwayRef = lsttraining_sim_spawn_motorway_ref($ref, (string) ($properties['name'] ?? ''));
        if ($motorwayRef !== '') {
            return $motorwayRef;
        }
    }

    $name = trim((string) ($properties['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    if ($ref !== '') {
        return $ref;
    }

    return '';
}

function lsttraining_sim_spawn_is_motorway_road(string $highway, string $ref, string $name = ''): bool {
    $highway = strtolower(trim($highway));
    $ref = strtoupper(trim($ref));
    return in_array($highway, ['motorway', 'motorway_link', 'trunk', 'trunk_link'], true)
        || preg_match('/^A\s*\d+/', $ref) === 1
        || lsttraining_sim_spawn_motorway_ref($ref, $name) !== '';
}

function lsttraining_sim_spawn_motorway_ref(string $ref, string $name = ''): string {
    foreach ([$ref, $name] as $value) {
        $value = strtoupper(trim($value));
        if ($value === '') {
            continue;
        }
        if (preg_match('/\b([AB])\s*[- ]?\s*(\d+[A-Z]?)\b/u', $value, $match)) {
            return $match[1] . ' ' . $match[2];
        }
    }
    return '';
}

function lsttraining_sim_spawn_motorway_label_has_ref(string $value): bool {
    return lsttraining_sim_spawn_motorway_ref('', $value) !== '';
}

function lsttraining_sim_spawn_is_generic_motorway_label(string $value): bool {
    $value = trim($value);
    if ($value === '') {
        return true;
    }

    return preg_match('/\b(?:Autobahn|Schnellstra(?:ß|ss)e)\s+im\s+Einsatzgebiet\b/iu', $value) === 1
        || (preg_match('/\b(?:Autobahn|Schnellstra(?:ß|ss)e)\b/iu', $value) === 1 && !lsttraining_sim_spawn_motorway_label_has_ref($value))
        || preg_match('/^(?:IRLS|ILS|Leitstelle)\b/iu', $value) === 1
        || preg_match('/^im\s+Einsatzgebiet\b/iu', $value) === 1;
}

function lsttraining_sim_spawn_motorway_label_is_complete(string $value): bool {
    $value = trim($value);
    if ($value === '' || lsttraining_sim_spawn_is_generic_motorway_label($value)) {
        return false;
    }

    if (
        preg_match('/\bAbschnitt\s+(.+?)(?:,\s+bei\s+(.+))?$/iu', $value, $sectionMatch) !== 1
        || lsttraining_sim_spawn_is_generic_motorway_label((string) ($sectionMatch[1] ?? ''))
    ) {
        return false;
    }

    $place = trim((string) ($sectionMatch[2] ?? ''));
    if ($place !== '' && lsttraining_sim_spawn_is_generic_motorway_label($place)) {
        return false;
    }

    return lsttraining_sim_spawn_motorway_label_has_ref($value)
        && preg_match('/\bRichtung\s+(?:Norden|Nordosten|Osten|Südosten|Süden|Suedosten|Sueden|Südwesten|Suedwesten|Westen|Nordwesten)\b/iu', $value) === 1
        && preg_match('/\bAbschnitt\s+\S/iu', $value) === 1;
}

function lsttraining_sim_spawn_motorway_same_part(string $a, string $b): bool {
    $a = trim((string) preg_replace('/\s+/', ' ', $a));
    $b = trim((string) preg_replace('/\s+/', ' ', $b));
    return $a !== '' && $b !== '' && strcasecmp($a, $b) === 0;
}

function lsttraining_sim_spawn_motorway_label(string $ref, string $direction, string $section, string $place = '', bool $trunk = false): string {
    $ref = lsttraining_sim_spawn_motorway_ref($ref);
    $direction = trim($direction);
    $section = trim($section);
    $place = trim($place);
    if ($ref === '' || $direction === '' || $section === '' || lsttraining_sim_spawn_is_generic_motorway_label($section)) {
        return '';
    }

    $prefix = stripos($ref, 'B ') === 0 || $trunk ? 'Schnellstraße ' : 'Autobahn ';
    $label = $prefix . $ref . ' Richtung ' . $direction . ', Abschnitt ' . $section;
    if ($place !== '' && !lsttraining_sim_spawn_is_generic_motorway_label($place) && !lsttraining_sim_spawn_motorway_same_part($section, $place)) {
        $label .= ', bei ' . $place;
    }
    return $label;
}

function lsttraining_sim_spawn_motorway_direction_label($bearing): string {
    if (!is_numeric($bearing)) {
        return '';
    }

    $bearing = fmod(((float) $bearing) + 360.0, 360.0);
    $labels = [
        'Norden',
        'Nordosten',
        'Osten',
        'Südosten',
        'Süden',
        'Südwesten',
        'Westen',
        'Nordwesten',
    ];
    $index = (int) floor(($bearing + 22.5) / 45.0) % 8;
    return $labels[$index];
}

function lsttraining_sim_spawn_first_property(array $values): string {
    foreach ($values as $value) {
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map('strval', $value)));
        }
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function lsttraining_sim_spawn_motorway_section(array $road, array $area): string {
    $roadName = trim((string) ($road['road_name'] ?? ''));
    $motorwayRef = lsttraining_sim_spawn_motorway_ref((string) ($road['road_ref'] ?? ''), $roadName);
    $section = lsttraining_sim_spawn_first_property([
        $road['road_destination'] ?? '',
        $road['road_destination_forward'] ?? '',
        $road['road_destination_backward'] ?? '',
        $road['road_junction_ref'] ?? '',
        $road['road_exit_to'] ?? '',
    ]);

    if ($section !== '' && $motorwayRef !== '' && strcasecmp($section, $motorwayRef) === 0) {
        $section = '';
    }
    if ($section === '' && $roadName !== '' && strcasecmp($roadName, $motorwayRef) !== 0 && strcasecmp($roadName, 'Autobahn') !== 0) {
        $section = $roadName;
    }
    if ($section === '') {
        $section = lsttraining_sim_address_city_from_area($area);
    }
    return $section;
}

function lsttraining_sim_spawn_motorway_place(array $road, array $area): string {
    $place = lsttraining_sim_spawn_first_property([
        $road['address_suburb'] ?? '',
        $road['address_city'] ?? '',
        $road['nearest_place'] ?? '',
        $road['road_exit_to'] ?? '',
        $road['road_junction_ref'] ?? '',
    ]);

    if ($place !== '' && !lsttraining_sim_spawn_is_generic_motorway_label($place)) {
        return $place;
    }

    $section = lsttraining_sim_spawn_motorway_section($road, $area);
    if ($section !== '' && !lsttraining_sim_spawn_is_generic_motorway_label($section)) {
        return $section;
    }

    return lsttraining_sim_address_city_from_area($area);
}

function lsttraining_sim_spawn_motorway_location_context(array $road, array $area): array {
    $highway = (string) ($road['road_highway'] ?? '');
    $roadRef = (string) ($road['road_ref'] ?? '');
    $roadName = (string) ($road['road_name'] ?? '');
    if (!lsttraining_sim_spawn_is_motorway_road($highway, $roadRef, $roadName)) {
        return [];
    }

    $ref = lsttraining_sim_spawn_motorway_ref($roadRef, implode(' ', [
        $roadName,
        (string) ($road['road_destination'] ?? ''),
        (string) ($road['road_destination_forward'] ?? ''),
        (string) ($road['road_destination_backward'] ?? ''),
    ]));
    if ($ref === '') {
        return [];
    }
    $section = lsttraining_sim_spawn_motorway_section($road, $area);
    $direction = lsttraining_sim_spawn_motorway_direction_label($road['road_bearing_deg'] ?? null);
    $place = lsttraining_sim_spawn_motorway_place($road, $area);
    $isTrunk = in_array(strtolower(trim($highway)), ['trunk', 'trunk_link'], true);
    $label = lsttraining_sim_spawn_motorway_label($ref, $direction, $section, $place, $isTrunk);
    if ($label === '') {
        return [];
    }

    return [
        'motorway_ref' => $ref,
        'motorway_section' => $section,
        'motorway_direction' => $direction,
        'motorway_bearing' => is_numeric($road['road_bearing_deg'] ?? null) ? round((float) $road['road_bearing_deg'], 1) : null,
        'motorway_place' => $place,
        'motorway_location_label' => $label,
    ];
}

function lsttraining_sim_fill_motorway_context_from_nearest(PDO $pdo, int $leitstelle_id, array $road, array $loc, array $area): array {
    $ref = lsttraining_sim_spawn_motorway_ref((string) ($road['road_ref'] ?? ''), (string) ($road['road_name'] ?? ''));
    if ($ref !== '') {
        return $road;
    }

    $nearest = lsttraining_sim_find_nearest_motorway_road($pdo, $leitstelle_id, $loc, $area);
    if (!$nearest) {
        return $road;
    }

    $nearestRef = lsttraining_sim_spawn_motorway_ref((string) ($nearest['road_ref'] ?? ''), (string) ($nearest['road_name'] ?? ''));
    if ($nearestRef === '') {
        return $road;
    }

    foreach (['road_ref', 'road_name', 'road_destination', 'road_destination_forward', 'road_destination_backward', 'road_junction_ref', 'road_exit_to', 'road_bearing_deg'] as $key) {
        if (empty($road[$key]) && array_key_exists($key, $nearest)) {
            $road[$key] = $nearest[$key];
        }
    }

    return $road;
}

function lsttraining_sim_find_nearest_motorway_road(PDO $pdo, int $leitstelle_id, array $loc, array $area, int $max_features = 60000): ?array {
    $tileState = lsttraining_sim_road_tile_state($pdo, $leitstelle_id);
    if (empty($tileState['complete'])) {
        return null;
    }

    $lon = (float) ($loc['longitude'] ?? 0.0);
    $lat = (float) ($loc['latitude'] ?? 0.0);
    $search_delta = 0.06;
    $checked = 0;
    $best = null;
    $bestDistance = INF;

    foreach ($tileState['paths'] as $path) {
        foreach (lsttraining_sim_open_gzip_lines($path) as $line) {
            if (strpos($line, 'LineString') === false) {
                continue;
            }

            $feature = json_decode($line, true);
            if (!is_array($feature)) {
                continue;
            }

            $properties = lsttraining_sim_osm_feature_properties($feature);
            $highway = (string) ($properties['highway'] ?? '');
            $roadRef = (string) ($properties['ref'] ?? '');
            $roadName = (string) ($properties['name'] ?? '');
            if (!lsttraining_sim_spawn_is_motorway_road($highway, $roadRef, $roadName)) {
                continue;
            }
            if (lsttraining_sim_spawn_motorway_ref($roadRef, $roadName) === '') {
                continue;
            }

            foreach (lsttraining_sim_line_segments_from_geometry($feature['geometry'] ?? []) as $segment) {
                $minLon = min((float) $segment[0][0], (float) $segment[1][0]);
                $maxLon = max((float) $segment[0][0], (float) $segment[1][0]);
                $minLat = min((float) $segment[0][1], (float) $segment[1][1]);
                $maxLat = max((float) $segment[0][1], (float) $segment[1][1]);
                if ($maxLon < ($lon - $search_delta) || $minLon > ($lon + $search_delta) || $maxLat < ($lat - $search_delta) || $minLat > ($lat + $search_delta)) {
                    continue;
                }
                if (++$checked > $max_features) {
                    break 3;
                }

                $mid = [
                    (((float) $segment[0][0]) + ((float) $segment[1][0])) / 2.0,
                    (((float) $segment[0][1]) + ((float) $segment[1][1])) / 2.0,
                ];
                if (!lsttraining_sim_point_inside_area($mid, $area)) {
                    continue;
                }

                $distance = lsttraining_sim_distance_point_to_segment_m($lon, $lat, $segment[0], $segment[1]);
                if ($distance >= $bestDistance) {
                    continue;
                }

                $bestDistance = $distance;
                $best = [
                    'road_name' => $roadName,
                    'road_ref' => $roadRef,
                    'road_highway' => $highway,
                    'road_label' => lsttraining_sim_readable_road_name($properties),
                    'distance_m' => (int) round($distance),
                    'road_destination' => (string) ($properties['destination'] ?? ''),
                    'road_destination_forward' => (string) ($properties['destination:forward'] ?? ''),
                    'road_destination_backward' => (string) ($properties['destination:backward'] ?? ''),
                    'road_junction_ref' => (string) ($properties['junction:ref'] ?? ''),
                    'road_exit_to' => (string) ($properties['exit_to'] ?? ''),
                    'road_segment_start' => $segment[0],
                    'road_segment_end' => $segment[1],
                    'road_bearing_deg' => lsttraining_sim_bearing_deg($segment[0], $segment[1]),
                ];
            }
        }
    }

    return $best;
}

function lsttraining_sim_spawn_road_section_label(string $roadName, string $city): string {
    $roadName = trim($roadName);
    $city = trim($city);
    if ($roadName === '') {
        return $city;
    }
    return $city !== '' ? $roadName . ', Abschnitt ' . $city : $roadName;
}

function lsttraining_sim_open_gzip_lines(string $path) {
    if (!is_readable($path)) {
        if (false) {
            yield '';
        }
        return;
    }

    $handle = gzopen($path, 'rb');
    if (!$handle) {
        if (false) {
            yield '';
        }
        return;
    }

    try {
        while (!gzeof($handle)) {
            $line = gzgets($handle);
            if ($line === false) {
                continue;
            }
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (isset($line[0]) && $line[0] === "\x1e") {
                $line = substr($line, 1);
            }
            yield $line;
        }
    } finally {
        gzclose($handle);
    }
}

function lsttraining_sim_osm_feature_properties(array $feature): array {
    $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
    $tags = is_array($properties['tags'] ?? null) ? $properties['tags'] : [];
    unset($properties['tags']);

    foreach ($tags as $key => $value) {
        if (!array_key_exists($key, $properties)) {
            $properties[$key] = $value;
        }
    }

    return $properties;
}

function lsttraining_sim_road_tile_state(PDO $pdo, int $leitstelle_id): array {
    $state = [
        'leitstelle_id' => $leitstelle_id,
        'total' => 0,
        'available' => 0,
        'readable' => 0,
        'paths' => [],
        'complete' => false,
        'query_error' => '',
        'invalid_paths' => 0,
        'unreadable_examples' => [],
    ];
    if ($leitstelle_id <= 0) {
        return $state;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS scoped_tiles,
                    SUM(CASE WHEN m.file_relpath IS NOT NULL AND m.file_relpath <> '' THEN 1 ELSE 0 END) AS available_files
             FROM leitstelle_tile_scope s
             LEFT JOIN leitstellen_osm_layers m
               ON m.layer_key = s.layer_key
              AND m.tile_z = s.tile_z
              AND m.tile_x = s.tile_x
              AND m.tile_y = s.tile_y
             WHERE s.leitstelle_id = ?
               AND s.layer_key = 'roads_lines'"
        );
        $stmt->execute([$leitstelle_id]);
        $coverage = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $state['total'] = (int) ($coverage['scoped_tiles'] ?? 0);
        $state['available'] = (int) ($coverage['available_files'] ?? 0);

        $files = $pdo->prepare(
            "SELECT m.file_relpath
             FROM leitstelle_tile_scope s
             INNER JOIN leitstellen_osm_layers m
               ON m.layer_key = s.layer_key
              AND m.tile_z = s.tile_z
              AND m.tile_x = s.tile_x
              AND m.tile_y = s.tile_y
             WHERE s.leitstelle_id = ?
               AND s.layer_key = 'roads_lines'
               AND m.file_relpath IS NOT NULL
               AND m.file_relpath <> ''
             ORDER BY m.tile_z ASC, m.tile_x ASC, m.tile_y ASC"
        );
        $files->execute([$leitstelle_id]);
        foreach (($files->fetchAll(PDO::FETCH_COLUMN) ?: []) as $relativePath) {
            $relativePath = str_replace('\\', '/', ltrim((string) $relativePath, '/\\'));
            if (strpos($relativePath, 'data/osm_tiles/') !== 0) {
                $state['invalid_paths']++;
                continue;
            }

            $absolutePath = LSTTRAINING_PATH . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (is_readable($absolutePath)) {
                $state['paths'][] = $absolutePath;
                continue;
            }

            if (count($state['unreadable_examples']) < 3) {
                $state['unreadable_examples'][] = $relativePath;
            }
        }
    } catch (Throwable $e) {
        $state['query_error'] = $e->getMessage();
        return $state;
    }

    $state['readable'] = count($state['paths']);
    $state['complete'] = $state['total'] > 0
        && $state['available'] >= $state['total']
        && $state['readable'] >= $state['total'];

    return $state;
}

function lsttraining_sim_road_tile_error(array $state): string {
    $leitstelle_id = (int) ($state['leitstelle_id'] ?? 0);
    $total = (int) ($state['total'] ?? 0);
    $available = (int) ($state['available'] ?? 0);
    $readable = (int) ($state['readable'] ?? 0);
    $queryError = trim((string) ($state['query_error'] ?? ''));
    if ($queryError !== '') {
        return sprintf(
            'Straßen-Tiles für Leitstelle %d konnten nicht aus der Datenbank gelesen werden: %s',
            $leitstelle_id,
            $queryError
        );
    }
    if ($total <= 0) {
        return sprintf(
            'Für Leitstelle %d sind keine roads_lines-Scope-Tiles registriert.',
            $leitstelle_id
        );
    }
    if ($available < $total) {
        return sprintf(
            'Straßen-Tiles unvollständig: %d/%d Dateipfade für Leitstelle %d registriert.',
            max(0, $available),
            $total,
            $leitstelle_id
        );
    }
    if ($readable < $total) {
        $example = '';
        if (!empty($state['unreadable_examples'][0])) {
            $example = ' Beispiel: ' . (string) $state['unreadable_examples'][0] . '.';
        }
        return sprintf(
            'Straßen-Tile-Dateien nicht lesbar: %d/%d Dateien für Leitstelle %d erreichbar.%s',
            max(0, $readable),
            $total,
            $leitstelle_id,
            $example
        );
    }

    return sprintf(
        'Straßen-Tiles für Leitstelle %d sind nicht verwendbar.',
        $leitstelle_id
    );
}

function lsttraining_sim_diag_road_tile_state(&$diagnostics, array $state): void {
    if (!is_array($diagnostics)) {
        return;
    }

    $diagnostics['road_tiles_leitstelle_id'] = (int) ($state['leitstelle_id'] ?? 0);
    $diagnostics['road_tiles_total'] = (int) ($state['total'] ?? 0);
    $diagnostics['road_tiles_file_paths'] = (int) ($state['available'] ?? 0);
    $diagnostics['road_tiles_readable'] = (int) ($state['readable'] ?? 0);
    if (empty($state['complete'])) {
        $diagnostics['road_tiles_error'] = lsttraining_sim_road_tile_error($state);
    }
}

function lsttraining_sim_layer_path(string $layer_key): string {
    $safe = preg_replace('/[^a-z0-9_\\-]/i', '', $layer_key);
    if ($safe === 'roads_lines' || $safe === 'roads_motorway') {
        return LSTTRAINING_PATH . 'landuse/roads_lines.geojsonl.gz';
    }

    return LSTTRAINING_PATH . 'landuse/' . $safe . '.geojsonl.gz';
}

function lsttraining_sim_normalize_landuse_layer(string $value): string {
    $value = sanitize_key($value);
    if ($value === '') {
        return '';
    }

    if (in_array($value, ['road', 'roads', 'roads_lines', 'landuse_roads_lines'], true)) {
        return 'roads_lines';
    }

    if (in_array($value, ['motorway', 'autobahn', 'roads_motorway', 'landuse_roads_motorway'], true)) {
        return 'roads_motorway';
    }

    return strpos($value, 'landuse_') === 0 ? $value : 'landuse_' . $value;
}

function lsttraining_sim_is_road_layer(string $layer_key): bool {
    return in_array($layer_key, ['roads_lines', 'roads_motorway'], true);
}

function lsttraining_sim_road_highway_filter(string $layer_key): ?string {
    return $layer_key === 'roads_motorway' ? 'motorway' : null;
}

function lsttraining_sim_road_vehicle_access_allows_incident(array $properties): bool {
    foreach (['access', 'vehicle', 'motor_vehicle'] as $key) {
        $value = strtolower(trim((string) ($properties[$key] ?? '')));
        if (in_array($value, ['no', 'private'], true)) {
            return false;
        }
    }

    return true;
}

function lsttraining_sim_road_is_dispatchable(array $properties, string $layer_key = 'roads_lines'): bool {
    if (!lsttraining_sim_road_vehicle_access_allows_incident($properties)) {
        return false;
    }

    $highway = strtolower(trim((string) ($properties['highway'] ?? '')));
    $name = trim((string) ($properties['name'] ?? ''));
    $ref = trim((string) ($properties['ref'] ?? ''));
    $motorwayTypes = ['motorway', 'motorway_link', 'trunk', 'trunk_link'];

    if ($layer_key === 'roads_motorway') {
        return in_array($highway, $motorwayTypes, true)
            && lsttraining_sim_spawn_motorway_ref($ref, $name) !== '';
    }

    $dispatchableTypes = [
        'motorway',
        'motorway_link',
        'trunk',
        'trunk_link',
        'primary',
        'primary_link',
        'secondary',
        'secondary_link',
        'tertiary',
        'tertiary_link',
        'unclassified',
        'residential',
        'living_street',
    ];
    if (!in_array($highway, $dispatchableTypes, true)) {
        return false;
    }

    if (in_array($highway, $motorwayTypes, true)) {
        return lsttraining_sim_spawn_motorway_ref($ref, $name) !== '';
    }

    return $name !== '' || $ref !== '';
}

function lsttraining_sim_landuse_tile_zoom(string $layer_key): ?int {
    $zoom = [
        'landuse_meadow' => 13,
        'landuse_farmland' => 13,
        'landuse_forest' => 13,
        'landuse_residential' => 13,
        'landuse_industrial' => 12,
        'landuse_commercial' => 12,
        'landuse_allotments' => 12,
        'landuse_railway' => 12,
        'landuse_cemetery' => 11,
        'landuse_retail' => 11,
        'landuse_quarry' => 11,
        'landuse_recreation_ground' => 11,
        'landuse_landfill' => 10,
        'landuse_religious' => 10,
        'landuse_animal_keeping' => 10,
        'landuse_logging' => 10,
    ];

    return $zoom[$layer_key] ?? null;
}

function lsttraining_sim_lonlat_to_tile(float $lon, float $lat, int $z): array {
    $lat = max(-85.05112878, min(85.05112878, $lat));
    $n = 2 ** $z;
    $x = (int) floor(($lon + 180.0) / 360.0 * $n);
    $lat_rad = deg2rad($lat);
    $y = (int) floor((1.0 - log(tan($lat_rad) + (1.0 / cos($lat_rad))) / M_PI) / 2.0 * $n);

    return [
        max(0, min($n - 1, $x)),
        max(0, min($n - 1, $y)),
    ];
}

function lsttraining_sim_tile_paths_for_area(string $layer_key, array $area): array {
    $z = lsttraining_sim_landuse_tile_zoom($layer_key);
    if ($z === null) {
        return [];
    }

    $bbox = $area['bbox'];
    [$min_x, $max_y] = lsttraining_sim_lonlat_to_tile((float) $bbox[0], (float) $bbox[1], $z);
    [$max_x, $min_y] = lsttraining_sim_lonlat_to_tile((float) $bbox[2], (float) $bbox[3], $z);
    $n = 2 ** $z;
    $padding = 1;

    $raw_min_x = min($min_x, $max_x);
    $raw_max_x = max($min_x, $max_x);
    $raw_min_y = min($min_y, $max_y);
    $raw_max_y = max($min_y, $max_y);

    $min_x = max(0, min($n - 1, $raw_min_x - $padding));
    $max_x = max(0, min($n - 1, $raw_max_x + $padding));
    $min_y = max(0, min($n - 1, $raw_min_y - $padding));
    $max_y = max(0, min($n - 1, $raw_max_y + $padding));

    $paths = [];
    for ($x = $min_x; $x <= $max_x; $x++) {
        for ($y = $min_y; $y <= $max_y; $y++) {
            $path = LSTTRAINING_PATH . 'landuse/landuse_tiles_out/z' . $z . '/' . $layer_key . '/' . $x . '/' . $y . '.geojsonl.gz';
            if (is_readable($path)) {
                $paths[] = $path;
            }
        }
    }

    return $paths;
}

function lsttraining_sim_landuse_tile_layer_available(string $layer_key): bool {
    $z = lsttraining_sim_landuse_tile_zoom($layer_key);
    if ($z === null) {
        return false;
    }

    return is_dir(LSTTRAINING_PATH . 'landuse/landuse_tiles_out/z' . $z . '/' . $layer_key);
}

function lsttraining_sim_diag_inc(&$diagnostics, string $key, int $amount = 1): void {
    if (!is_array($diagnostics)) {
        return;
    }

    $diagnostics[$key] = (int) ($diagnostics[$key] ?? 0) + $amount;
}

function lsttraining_sim_diag_missing_file(&$diagnostics, string $relative_path): void {
    if (!is_array($diagnostics)) {
        return;
    }

    if (!isset($diagnostics['missing_files']) || !is_array($diagnostics['missing_files'])) {
        $diagnostics['missing_files'] = [];
    }

    if (!in_array($relative_path, $diagnostics['missing_files'], true)) {
        $diagnostics['missing_files'][] = $relative_path;
    }
}

function lsttraining_sim_diag_merge(&$diagnostics, array $additional): void {
    if (!is_array($diagnostics)) {
        return;
    }

    foreach ($additional as $key => $value) {
        if (is_int($value)) {
            lsttraining_sim_diag_inc($diagnostics, (string) $key, $value);
            continue;
        }

        if (is_array($value)) {
            if (!isset($diagnostics[$key]) || !is_array($diagnostics[$key])) {
                $diagnostics[$key] = [];
            }
            foreach ($value as $item) {
                if (!in_array($item, $diagnostics[$key], true)) {
                    $diagnostics[$key][] = $item;
                }
            }
            continue;
        }

        if (!isset($diagnostics[$key]) || $diagnostics[$key] === '') {
            $diagnostics[$key] = $value;
        }
    }
}

function lsttraining_sim_consider_weighted_location(array &$selection, array $candidate, &$diagnostics = null): void {
    $weight = max(0, (int) ($candidate['weight'] ?? 0));
    if ($weight <= 0) {
        return;
    }

    lsttraining_sim_diag_inc($diagnostics, 'location_candidates_selectable');
    lsttraining_sim_diag_inc($diagnostics, 'location_candidate_weight_total', $weight);

    $total_weight = (int) ($selection['total_weight'] ?? 0) + $weight;
    $selection['total_weight'] = $total_weight;
    // Weighted reservoir sampling visits every candidate without retaining an order-biased capped list.
    if (!isset($selection['picked']) || mt_rand(1, $total_weight) <= $weight) {
        $selection['picked'] = $candidate;
    }
}

function lsttraining_sim_select_road_point_from_tiles(PDO $pdo, int $leitstelle_id, string $layer_key, array $area, array &$selection, &$diagnostics = null): void {
    $tileState = lsttraining_sim_road_tile_state($pdo, $leitstelle_id);
    lsttraining_sim_diag_road_tile_state($diagnostics, $tileState);
    if (empty($tileState['complete'])) {
        return;
    }

    $required_highway = lsttraining_sim_road_highway_filter($layer_key);
    foreach ($tileState['paths'] as $path) {
        foreach (lsttraining_sim_open_gzip_lines($path) as $line) {
            if (strpos($line, 'LineString') === false) {
                continue;
            }

            $feature = json_decode($line, true);
            if (!is_array($feature)) {
                continue;
            }

            lsttraining_sim_diag_inc($diagnostics, 'road_line_features_checked');
            lsttraining_sim_diag_inc($diagnostics, 'location_features_checked');
            $properties = lsttraining_sim_osm_feature_properties($feature);
            $highway = (string) ($properties['highway'] ?? '');
            if ($required_highway === 'motorway' && !in_array(strtolower(trim($highway)), ['motorway', 'motorway_link', 'trunk', 'trunk_link'], true)) {
                lsttraining_sim_diag_inc($diagnostics, 'road_highway_mismatch');
                continue;
            }
            if ($required_highway !== null && $required_highway !== 'motorway' && $highway !== $required_highway) {
                lsttraining_sim_diag_inc($diagnostics, 'road_highway_mismatch');
                continue;
            }
            if (!lsttraining_sim_road_is_dispatchable($properties, $layer_key)) {
                lsttraining_sim_diag_inc($diagnostics, 'road_not_dispatchable');
                continue;
            }

            $point = lsttraining_sim_feature_line_candidate_point($feature, $area);
            if (!$point) {
                lsttraining_sim_diag_inc($diagnostics, 'road_no_point_in_area');
                continue;
            }

            lsttraining_sim_consider_weighted_location($selection, [
                'longitude' => $point['longitude'],
                'latitude' => $point['latitude'],
                'weight' => lsttraining_sim_landuse_weight($layer_key),
                'density_source' => 'road',
                'landuse_layer' => $layer_key,
                'road_highway' => $highway,
                'road_name' => (string) ($properties['name'] ?? ''),
                'road_ref' => (string) ($properties['ref'] ?? ''),
                'road_destination' => (string) ($properties['destination'] ?? ''),
                'road_destination_forward' => (string) ($properties['destination:forward'] ?? ''),
                'road_destination_backward' => (string) ($properties['destination:backward'] ?? ''),
                'road_junction_ref' => (string) ($properties['junction:ref'] ?? ''),
                'road_exit_to' => (string) ($properties['exit_to'] ?? ''),
                'road_segment_start' => $point['road_segment_start'] ?? null,
                'road_segment_end' => $point['road_segment_end'] ?? null,
                'road_bearing_deg' => $point['road_bearing_deg'] ?? null,
            ], $diagnostics);
        }
    }
}

function lsttraining_sim_select_landuse_point_from_tile_files(string $layer_key, array $area, array &$selection, &$diagnostics = null): void {
    $bbox = $area['bbox'];
    $paths = lsttraining_sim_tile_paths_for_area($layer_key, $area);
    if (!$paths) {
        if (lsttraining_sim_landuse_tile_layer_available($layer_key)) {
            lsttraining_sim_diag_inc($diagnostics, 'landuse_no_tiles_in_area');
        }
        return;
    }

    lsttraining_sim_diag_inc($diagnostics, 'landuse_tile_files_read', count($paths));
    foreach ($paths as $path) {
        $lines = lsttraining_sim_open_gzip_lines($path);
        if (!$lines) {
            continue;
        }

        foreach ($lines as $line) {
            $feature = json_decode($line, true);
            if (!is_array($feature)) {
                continue;
            }

            lsttraining_sim_diag_inc($diagnostics, 'landuse_features_checked');
            lsttraining_sim_diag_inc($diagnostics, 'location_features_checked');
            $mpoly = lsttraining_sim_feature_mpoly($feature);
            if (!$mpoly) {
                lsttraining_sim_diag_inc($diagnostics, 'landuse_invalid_geometry');
                continue;
            }

            $fbbox = lst_mpoly_bbox($mpoly);
            if (!lsttraining_sim_bbox_intersects($fbbox, $bbox)) {
                lsttraining_sim_diag_inc($diagnostics, 'landuse_outside_area_bbox');
                continue;
            }

            $point = lsttraining_sim_feature_candidate_point($feature, $area);
            if (!$point) {
                lsttraining_sim_diag_inc($diagnostics, 'landuse_no_point_in_area');
                continue;
            }

            lsttraining_sim_consider_weighted_location($selection, [
                'longitude' => $point['longitude'],
                'latitude' => $point['latitude'],
                'weight' => lsttraining_sim_landuse_weight($layer_key),
                'density_source' => 'landuse',
                'landuse_layer' => $layer_key,
            ], $diagnostics);
        }
    }
}

function lsttraining_sim_select_landuse_point_from_source_file(string $layer_key, array $area, array &$selection, &$diagnostics = null): void {
    $bbox = $area['bbox'];
    $path = lsttraining_sim_layer_path($layer_key);
    if (!is_readable($path)) {
        lsttraining_sim_diag_inc($diagnostics, 'landuse_source_missing');
        lsttraining_sim_diag_missing_file($diagnostics, 'landuse/' . $layer_key . '.geojsonl.gz');
        return;
    }

    $lines = lsttraining_sim_open_gzip_lines($path);
    foreach ($lines as $line) {
        $feature = json_decode($line, true);
        if (!is_array($feature)) {
            continue;
        }

        lsttraining_sim_diag_inc($diagnostics, 'landuse_features_checked');
        lsttraining_sim_diag_inc($diagnostics, 'location_features_checked');
        $mpoly = lsttraining_sim_feature_mpoly($feature);
        if (!$mpoly) {
            lsttraining_sim_diag_inc($diagnostics, 'landuse_invalid_geometry');
            continue;
        }

        $fbbox = lst_mpoly_bbox($mpoly);
        if (!lsttraining_sim_bbox_intersects($fbbox, $bbox)) {
            lsttraining_sim_diag_inc($diagnostics, 'landuse_outside_area_bbox');
            continue;
        }

        $point = lsttraining_sim_feature_candidate_point($feature, $area);
        if (!$point) {
            lsttraining_sim_diag_inc($diagnostics, 'landuse_no_point_in_area');
            continue;
        }

        lsttraining_sim_consider_weighted_location($selection, [
            'longitude' => $point['longitude'],
            'latitude' => $point['latitude'],
            'weight' => lsttraining_sim_landuse_weight($layer_key),
            'density_source' => 'landuse',
            'landuse_layer' => $layer_key,
        ], $diagnostics);
    }
}

function lsttraining_sim_pick_landuse_point_from_files(PDO $pdo, int $leitstelle_id, array $layer_keys, array $area, array $seed_candidates = [], &$diagnostics = null): ?array {
    $selection = [
        'picked' => null,
        'total_weight' => 0,
    ];
    foreach ($seed_candidates as $candidate) {
        if (is_array($candidate)) {
            lsttraining_sim_consider_weighted_location($selection, $candidate, $diagnostics);
        }
    }

    foreach ($layer_keys as $layer_key) {
        $layer_key = lsttraining_sim_normalize_landuse_layer((string) $layer_key);
        if ($layer_key === '') {
            continue;
        }

        lsttraining_sim_diag_inc($diagnostics, 'landuse_layers_checked');
        if (lsttraining_sim_is_road_layer($layer_key)) {
            lsttraining_sim_select_road_point_from_tiles($pdo, $leitstelle_id, $layer_key, $area, $selection, $diagnostics);
            continue;
        }

        if (lsttraining_sim_landuse_tile_layer_available($layer_key)) {
            lsttraining_sim_select_landuse_point_from_tile_files($layer_key, $area, $selection, $diagnostics);
        } else {
            lsttraining_sim_select_landuse_point_from_source_file($layer_key, $area, $selection, $diagnostics);
        }
    }

    return is_array($selection['picked']) ? $selection['picked'] : null;
}

function lsttraining_sim_landuse_weight(string $layer_key): int {
    $weights = [
        'roads_motorway' => 38,
        'roads_lines' => 35,
        'landuse_residential' => 40,
        'landuse_commercial' => 30,
        'landuse_retail' => 28,
        'landuse_industrial' => 24,
        'landuse_railway' => 20,
        'landuse_recreation_ground' => 12,
        'landuse_allotments' => 10,
        'landuse_cemetery' => 8,
        'landuse_forest' => 5,
        'landuse_farmland' => 4,
        'landuse_meadow' => 3,
        'landuse_quarry' => 8,
        'landuse_landfill' => 8,
    ];

    return $weights[$layer_key] ?? 8;
}

function lsttraining_sim_anywhere_layers(): array {
    return [
        'landuse_residential',
        'landuse_commercial',
        'landuse_retail',
        'landuse_industrial',
        'landuse_recreation_ground',
        'landuse_allotments',
        'landuse_forest',
        'landuse_farmland',
        'landuse_meadow',
    ];
}

function lsttraining_sim_pick_poi(PDO $pdo, int $leitstelle_id, string $poi_type, array $area): ?array {
    if ($poi_type === '' || !lsttraining_sim_table_exists($pdo, 'leitstellen_pois')) {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT id, poi_type, name, latitude, longitude
        FROM leitstellen_pois
        WHERE leitstelle_id = ? AND poi_type = ?
        ORDER BY id ASC
        LIMIT 600
    ');
    $stmt->execute([$leitstelle_id, $poi_type]);

    $items = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $lat = isset($row['latitude']) ? (float) $row['latitude'] : 0.0;
        $lon = isset($row['longitude']) ? (float) $row['longitude'] : 0.0;
        if (!lsttraining_sim_point_inside_area([$lon, $lat], $area)) {
            continue;
        }
        $items[] = [
            'id' => (int) $row['id'],
            'poi_type' => (string) $row['poi_type'],
            'poi_name_snapshot' => (string) ($row['name'] ?? ''),
            'latitude' => $lat,
            'longitude' => $lon,
            'weight' => 20,
            'density_source' => 'poi',
        ];
    }

    return lsttraining_sim_weighted_pick($items);
}

function lsttraining_sim_pick_fixed_point(array $einsatz, array $area): ?array {
    $lat = isset($einsatz['fixed_latitude']) ? (float) $einsatz['fixed_latitude'] : 0.0;
    $lon = isset($einsatz['fixed_longitude']) ? (float) $einsatz['fixed_longitude'] : 0.0;
    if (!$lat || !$lon || !lsttraining_sim_point_inside_area([$lon, $lat], $area)) {
        return null;
    }

    $radius = isset($einsatz['fixed_radius_m']) ? max(0, (int) $einsatz['fixed_radius_m']) : 0;
    if ($radius > 0) {
        for ($i = 0; $i < 40; $i++) {
            $distance = lsttraining_sim_random_float(0.0, (float) $radius);
            $bearing = lsttraining_sim_random_float(0.0, 2.0 * M_PI);
            $dlat = ($distance * cos($bearing)) / 111320.0;
            $dlon = ($distance * sin($bearing)) / max(1.0, 111320.0 * cos(deg2rad($lat)));
            $candidate = [$lon + $dlon, $lat + $dlat];
            if (lsttraining_sim_point_inside_area($candidate, $area)) {
                return [
                    'latitude' => $candidate[1],
                    'longitude' => $candidate[0],
                    'density_source' => 'fixed_point',
                    'weight' => 1,
                ];
            }
        }
    }

    return [
        'latitude' => $lat,
        'longitude' => $lon,
        'density_source' => 'fixed_point',
        'weight' => 1,
    ];
}

function lsttraining_sim_resolve_location(PDO $pdo, array $einsatz, int $leitstelle_id, array $area, &$diagnostics = null): ?array {
    $scope = (string) ($einsatz['scope_type'] ?? 'anywhere');

    if ($scope === 'fixed_point') {
        return lsttraining_sim_pick_fixed_point($einsatz, $area);
    }

    if ($scope === 'poi_type') {
        return lsttraining_sim_pick_poi($pdo, $leitstelle_id, (string) ($einsatz['poi_type'] ?? ''), $area);
    }

    if ($scope === 'landscape') {
        $layers = lsttraining_sim_decode_json($einsatz['landscape_tags_json'] ?? '', []);
        $layers = array_values(array_filter(array_map('lsttraining_sim_normalize_landuse_layer', array_map('strval', $layers))));
        if (!$layers) {
            return null;
        }
        return lsttraining_sim_pick_landuse_point_from_files($pdo, $leitstelle_id, $layers, $area, [], $diagnostics);
    }

    $fallback_candidates = [];
    $fallback = lsttraining_sim_random_point_in_area($area);
    if ($fallback) {
        $fallback['weight'] = 4;
        $fallback['density_source'] = 'fallback';
        $fallback_candidates[] = $fallback;
    }

    return lsttraining_sim_pick_landuse_point_from_files($pdo, $leitstelle_id, lsttraining_sim_anywhere_layers(), $area, $fallback_candidates, $diagnostics);
}

function lsttraining_sim_time_window_matches(PDO $pdo, int $einsatz_id, DateTimeImmutable $sim_time): bool {
    if (!lsttraining_sim_table_exists($pdo, 'einsatz_time_windows')) {
        return true;
    }

    $stmt = $pdo->prepare('SELECT day_type, start_time, end_time FROM einsatz_time_windows WHERE einsatz_id = ?');
    $stmt->execute([$einsatz_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        return true;
    }

    $day = strtolower($sim_time->format('l'));
    $is_weekend = in_array($day, ['saturday', 'sunday'], true);
    $time = $sim_time->format('H:i:s');

    foreach ($rows as $row) {
        $day_type = (string) ($row['day_type'] ?? 'any');
        $day_ok = $day_type === 'any'
            || ($day_type === 'weekday' && !$is_weekend)
            || ($day_type === 'weekend' && $is_weekend)
            || $day_type === $day;
        if (!$day_ok) {
            continue;
        }

        $start = (string) ($row['start_time'] ?? '00:00:00');
        $end = (string) ($row['end_time'] ?? '23:59:59');
        $time_ok = $start <= $end
            ? ($time >= $start && $time <= $end)
            : ($time >= $start || $time <= $end);
        if ($time_ok) {
            return true;
        }
    }

    return false;
}

function lsttraining_sim_relation_matches(PDO $pdo, string $table, string $column, int $einsatz_id, string $value): bool {
    if (!lsttraining_sim_table_exists($pdo, $table)) {
        return true;
    }

    $count = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE einsatz_id = ?");
    $count->execute([$einsatz_id]);
    if ((int) $count->fetchColumn() === 0) {
        return true;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE einsatz_id = ? AND `{$column}` = ?");
    $stmt->execute([$einsatz_id, $value]);
    return (int) $stmt->fetchColumn() > 0;
}

function lsttraining_sim_leitstelle_allowed(PDO $pdo, int $einsatz_id, int $leitstelle_id): bool {
    if (!lsttraining_sim_table_exists($pdo, 'einsatz_excluded_leitstellen')) {
        return true;
    }

    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM einsatz_excluded_leitstellen
        WHERE einsatz_id = ? AND leitstelle_id = ?
    ');
    $stmt->execute([$einsatz_id, $leitstelle_id]);
    return (int) $stmt->fetchColumn() === 0;
}

function lsttraining_sim_spawn_diagnostics_message(array $diagnostics): string {
    $parts = [];

    $enabled = (int) ($diagnostics['enabled_templates'] ?? 0);
    $accepted = (int) ($diagnostics['accepted_templates'] ?? 0);
    $parts[] = $enabled . ' aktive Vorlagen geprüft';
    $parts[] = $accepted . ' platzierbar';

    $skips = [
        'skipped_leitstelle' => 'Leitstelle ausgeschlossen',
        'skipped_season' => 'Jahreszeit passt nicht',
        'skipped_weather' => 'Wetter passt nicht',
        'skipped_time' => 'Zeitfenster passt nicht',
        'skipped_location' => 'kein Ort im Einsatzgebiet',
        'skipped_final_area' => 'final außerhalb Gebiet',
        'landuse_layers_checked' => 'Landuse-Layer geprüft',
        'landuse_tile_files_read' => 'Landuse-Tiles gelesen',
        'landuse_no_tiles_in_area' => 'keine Landuse-Tiles im Gebiet',
        'landuse_features_checked' => 'Landuse-Features geprüft',
        'landuse_outside_area_bbox' => 'Landuse außerhalb Gebiets-BBox',
        'landuse_no_point_in_area' => 'Landuse ohne Punkt im Gebiet',
        'landuse_source_missing' => 'Landuse-Quelle fehlt',
        'road_source_missing' => 'Straßenquelle fehlt',
        'location_features_checked' => 'Ortsfeatures geprüft',
        'location_candidates_selectable' => 'wählbare Ortskandidaten',
        'location_candidate_weight_total' => 'Summengewicht Ortskandidaten',
        'road_line_features_checked' => 'Straßenlinien geprüft',
        'road_no_point_in_area' => 'Straßen ohne Punkt im Gebiet',
        'road_highway_mismatch' => 'Straßen nicht Autobahn',
        'road_not_dispatchable' => 'Straßen nicht als befahrbarer Einsatzort geeignet',
        'location_missing_fixed_point' => 'Fixpunkt außerhalb/fehlt',
        'location_missing_poi_type' => 'POI im Gebiet fehlt',
        'location_missing_landscape' => 'keine passende Fläche im Gebiet',
        'location_missing_anywhere' => 'kein Zufallspunkt im Gebiet',
    ];

    foreach ($skips as $key => $label) {
        if (!empty($diagnostics[$key])) {
            $parts[] = $label . ': ' . (int) $diagnostics[$key];
        }
    }

    if (!empty($diagnostics['missing_files']) && is_array($diagnostics['missing_files'])) {
        $parts[] = 'fehlende/nicht lesbare Dateien: ' . implode(', ', array_map('strval', $diagnostics['missing_files']));
    }
    if (!empty($diagnostics['road_tiles_error'])) {
        $parts[] = (string) $diagnostics['road_tiles_error'];
    }

    return implode('; ', $parts) . '.';
}

function lsttraining_sim_fetch_candidates(PDO $pdo, int $leitstelle_id, array $settings, DateTimeImmutable $sim_time, array $area, &$diagnostics = null, int $selected_einsatz_id = 0, bool $ignore_context_filters = false): array {
    if ($selected_einsatz_id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM einsaetze WHERE enabled = 1 AND id = ? LIMIT 1');
        $stmt->execute([$selected_einsatz_id]);
    } else {
        $stmt = $pdo->query('SELECT * FROM einsaetze WHERE enabled = 1 ORDER BY id ASC');
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $season = (string) ($settings['season'] ?? '');
    $weather = (string) ($settings['weather'] ?? 'auto');

    if (is_array($diagnostics)) {
        $diagnostics['enabled_templates'] = count($rows);
        if ($selected_einsatz_id > 0) {
            $diagnostics['selected_einsatz_id'] = $selected_einsatz_id;
        }
    }

    $candidates = [];
    foreach ($rows as $row) {
        $einsatz_id = (int) $row['id'];
        if (!lsttraining_sim_leitstelle_allowed($pdo, $einsatz_id, $leitstelle_id)) {
            lsttraining_sim_diag_inc($diagnostics, 'skipped_leitstelle');
            continue;
        }
        if (!$ignore_context_filters && $season !== '' && !lsttraining_sim_relation_matches($pdo, 'einsatz_seasons', 'season', $einsatz_id, $season)) {
            lsttraining_sim_diag_inc($diagnostics, 'skipped_season');
            continue;
        }
        if (!$ignore_context_filters && $weather !== 'auto' && !lsttraining_sim_relation_matches($pdo, 'einsatz_weather_conditions', 'weather_type', $einsatz_id, $weather)) {
            lsttraining_sim_diag_inc($diagnostics, 'skipped_weather');
            continue;
        }
        if (!$ignore_context_filters && !lsttraining_sim_time_window_matches($pdo, $einsatz_id, $sim_time)) {
            lsttraining_sim_diag_inc($diagnostics, 'skipped_time');
            continue;
        }

        $location_diagnostics = [];
        $location = lsttraining_sim_resolve_location($pdo, $row, $leitstelle_id, $area, $location_diagnostics);
        lsttraining_sim_diag_merge($diagnostics, $location_diagnostics);
        if (!$location) {
            $scope = (string) ($row['scope_type'] ?? 'anywhere');
            lsttraining_sim_diag_inc($diagnostics, 'skipped_location');
            lsttraining_sim_diag_inc($diagnostics, 'location_missing_' . sanitize_key($scope));
            continue;
        }

        if (!lsttraining_sim_point_inside_area([(float) $location['longitude'], (float) $location['latitude']], $area)) {
            lsttraining_sim_diag_inc($diagnostics, 'skipped_final_area');
            continue;
        }

        $row['_spawn_location'] = $location;
        $row['_spawn_location_diagnostics'] = $location_diagnostics;
        $row['weight'] = max(1, (int) ($row['weight_base'] ?? 100));
        $candidates[] = $row;
    }

    if (is_array($diagnostics)) {
        $diagnostics['accepted_templates'] = count($candidates);
    }

    return $candidates;
}

function lsttraining_sim_pick_enabled_text(array $rows): string {
    $enabled = array_values(array_filter($rows, static function ($row): bool {
        return isset($row['enabled']) ? (int) $row['enabled'] === 1 : true;
    }));
    $source = $enabled ?: $rows;
    if (!$source) {
        return '';
    }

    $row = $source[array_rand($source)];
    return trim((string) ($row['text'] ?? ''));
}

function lsttraining_sim_fetch_einsatz_caller_parts(PDO $pdo, int $einsatz_id): array {
    $grouped = [
        'problem' => [],
        'observation' => [],
        'extra' => [],
    ];

    if (!lsttraining_sim_table_exists($pdo, 'einsatz_caller_parts')) {
        return $grouped;
    }

    $stmt = $pdo->prepare('
        SELECT part_key, text, sort_order, enabled
        FROM einsatz_caller_parts
        WHERE einsatz_id = ?
        ORDER BY part_key ASC, sort_order ASC, id ASC
    ');
    $stmt->execute([$einsatz_id]);
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $key = (string) ($row['part_key'] ?? '');
        if (!isset($grouped[$key])) {
            $grouped[$key] = [];
        }
        $grouped[$key][] = $row;
    }

    return $grouped;
}

function lsttraining_sim_fetch_profile_parts(PDO $pdo, int $profile_id): array {
    $grouped = [
        'greeting' => [],
        'self_intro' => [],
        'location_intro' => [],
        'problem_intro' => [],
        'urgency' => [],
        'closing' => [],
        'callback_request' => [],
    ];

    if (!lsttraining_sim_table_exists($pdo, 'anrufer_profile_parts')) {
        return $grouped;
    }

    $stmt = $pdo->prepare('
        SELECT part_key, text, sort_order, enabled
        FROM anrufer_profile_parts
        WHERE profile_id = ?
        ORDER BY part_key ASC, sort_order ASC, id ASC
    ');
    $stmt->execute([$profile_id]);
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $key = (string) ($row['part_key'] ?? '');
        if (!isset($grouped[$key])) {
            $grouped[$key] = [];
        }
        $grouped[$key][] = $row;
    }

    return $grouped;
}

function lsttraining_sim_pick_caller_profile(PDO $pdo, int $einsatz_id): ?array {
    if (!lsttraining_sim_table_exists($pdo, 'anrufer_profile')) {
        return null;
    }

    $items = [];
    if (lsttraining_sim_table_exists($pdo, 'einsatz_anrufer_profiles')) {
        $stmt = $pdo->prepare('
            SELECT ap.*, eap.weight AS assignment_weight
            FROM einsatz_anrufer_profiles eap
            INNER JOIN anrufer_profile ap ON ap.id = eap.profile_id
            WHERE eap.einsatz_id = ? AND ap.enabled = 1
            ORDER BY ap.sort_order ASC, ap.name ASC, ap.id ASC
        ');
        $stmt->execute([$einsatz_id]);

        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $row['weight'] = max(1, (int) ($row['assignment_weight'] ?? 100));
            $row['caller_profile_source'] = 'assigned';
            $items[] = $row;
        }
        if ($items) {
            return lsttraining_sim_weighted_pick($items);
        }
    }

    $stmt = $pdo->query('
        SELECT *
        FROM anrufer_profile
        WHERE enabled = 1
        ORDER BY sort_order ASC, name ASC, id ASC
    ');
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $row['weight'] = 100;
        $row['caller_profile_source'] = 'random_active';
        $items[] = $row;
    }
    return lsttraining_sim_weighted_pick($items);
}

function lsttraining_sim_pseudo_house_number(array $loc, int $seed): int {
    $raw = abs(crc32(sprintf('%.6F:%.6F:%d', (float) ($loc['latitude'] ?? 0), (float) ($loc['longitude'] ?? 0), $seed)));
    return max(1, (int) (($raw % 198) + 1));
}

function lsttraining_sim_find_nearest_road(PDO $pdo, int $leitstelle_id, array $loc, array $area, int $max_features = 60000): ?array {
    $tileState = lsttraining_sim_road_tile_state($pdo, $leitstelle_id);
    if (empty($tileState['complete'])) {
        return null;
    }

    $lon = (float) ($loc['longitude'] ?? 0.0);
    $lat = (float) ($loc['latitude'] ?? 0.0);
    $search_delta = 0.045;
    $checked = 0;
    $best = null;
    $bestDistance = INF;

    foreach ($tileState['paths'] as $path) {
        foreach (lsttraining_sim_open_gzip_lines($path) as $line) {
            if (strpos($line, 'LineString') === false) {
                continue;
            }

            $feature = json_decode($line, true);
            if (!is_array($feature)) {
                continue;
            }

            $properties = lsttraining_sim_osm_feature_properties($feature);
            if (!lsttraining_sim_road_is_dispatchable($properties)) {
                continue;
            }
            $name = lsttraining_sim_readable_road_name($properties);
            if ($name === '') {
                continue;
            }

            foreach (lsttraining_sim_line_segments_from_geometry($feature['geometry'] ?? []) as $segment) {
                $minLon = min((float) $segment[0][0], (float) $segment[1][0]);
                $maxLon = max((float) $segment[0][0], (float) $segment[1][0]);
                $minLat = min((float) $segment[0][1], (float) $segment[1][1]);
                $maxLat = max((float) $segment[0][1], (float) $segment[1][1]);
                if ($maxLon < ($lon - $search_delta) || $minLon > ($lon + $search_delta) || $maxLat < ($lat - $search_delta) || $minLat > ($lat + $search_delta)) {
                    continue;
                }
                if (++$checked > $max_features) {
                    break 3;
                }

                $mid = [
                    (((float) $segment[0][0]) + ((float) $segment[1][0])) / 2.0,
                    (((float) $segment[0][1]) + ((float) $segment[1][1])) / 2.0,
                ];
                if (!lsttraining_sim_point_inside_area($mid, $area)) {
                    continue;
                }

                $distance = lsttraining_sim_distance_point_to_segment_m($lon, $lat, $segment[0], $segment[1]);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = [
                        'road_name' => (string) ($properties['name'] ?? ''),
                        'road_ref' => (string) ($properties['ref'] ?? ''),
                        'road_highway' => (string) ($properties['highway'] ?? ''),
                        'road_label' => $name,
                        'distance_m' => (int) round($distance),
                        'road_destination' => (string) ($properties['destination'] ?? ''),
                        'road_destination_forward' => (string) ($properties['destination:forward'] ?? ''),
                        'road_destination_backward' => (string) ($properties['destination:backward'] ?? ''),
                        'road_junction_ref' => (string) ($properties['junction:ref'] ?? ''),
                        'road_exit_to' => (string) ($properties['exit_to'] ?? ''),
                        'road_segment_start' => $segment[0],
                        'road_segment_end' => $segment[1],
                        'road_bearing_deg' => lsttraining_sim_bearing_deg($segment[0], $segment[1]),
                    ];
                }
            }
        }
    }

    return $best;
}

function lsttraining_sim_format_osm_address(array $properties): string {
    $street = trim((string) ($properties['addr:street'] ?? ''));
    $houseNumber = trim((string) ($properties['addr:housenumber'] ?? ''));

    if ($street === '' || $houseNumber === '') {
        return '';
    }

    return trim($street . ' ' . $houseNumber);
}

function lsttraining_sim_address_city_from_area(array $area): string {
    $leitstelle = is_array($area['leitstelle'] ?? null) ? $area['leitstelle'] : [];
    $city = trim((string) ($area['ort'] ?? ($leitstelle['ort'] ?? '')));
    if ($city !== '') {
        return $city;
    }

    return trim((string) ($area['name'] ?? ($leitstelle['name'] ?? '')));
}

function lsttraining_sim_first_address_city(array $values, array $area): string {
    foreach (['city', 'town', 'village', 'municipality', 'suburb'] as $key) {
        $value = trim((string) ($values[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return lsttraining_sim_address_city_from_area($area);
}

function lsttraining_sim_format_visible_address(string $street, string $houseNumber, string $city): string {
    $address = trim(trim($street) . ' ' . trim($houseNumber));
    $city = trim($city);
    if ($address === '') {
        return $city;
    }
    if ($city === '') {
        return $address;
    }

    return $address . ', ' . $city;
}

function lsttraining_sim_find_nearest_building_address(array $loc, array $area, int $max_features = 250000): ?array {
    $path = LSTTRAINING_PATH . 'landuse/buildings_centroids.geojsonl.gz';
    if (!is_readable($path)) {
        return null;
    }

    $lon = (float) ($loc['longitude'] ?? 0.0);
    $lat = (float) ($loc['latitude'] ?? 0.0);
    if ($lon < -180.0 || $lon > 180.0 || $lat < -90.0 || $lat > 90.0) {
        return null;
    }

    $maxDistanceM = 300.0;
    $searchDeltaLat = $maxDistanceM / 111320.0;
    $searchDeltaLon = $maxDistanceM / max(1.0, 111320.0 * cos(deg2rad($lat)));
    $checked = 0;
    $best = null;
    $bestDistance = INF;

    foreach (lsttraining_sim_open_gzip_lines($path) as $line) {
        if (strpos($line, 'addr:street') === false || strpos($line, 'addr:housenumber') === false) {
            continue;
        }

        $feature = json_decode($line, true);
        if (!is_array($feature)) {
            continue;
        }

        $address = lsttraining_sim_format_osm_address($feature['properties'] ?? []);
        if ($address === '') {
            continue;
        }

        $coord = lsttraining_sim_valid_lon_lat($feature['geometry']['coordinates'] ?? null);
        if (!$coord) {
            continue;
        }

        if (
            $coord[0] < ($lon - $searchDeltaLon) ||
            $coord[0] > ($lon + $searchDeltaLon) ||
            $coord[1] < ($lat - $searchDeltaLat) ||
            $coord[1] > ($lat + $searchDeltaLat)
        ) {
            continue;
        }
        if (++$checked > $max_features) {
            break;
        }

        if (!lsttraining_sim_point_inside_area($coord, $area)) {
            continue;
        }

        $distance = lsttraining_sim_geo_distance_m($lon, $lat, $coord[0], $coord[1]);
        if ($distance > $maxDistanceM || $distance >= $bestDistance) {
            continue;
        }

        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $street = trim((string) ($properties['addr:street'] ?? ''));
        $houseNumber = trim((string) ($properties['addr:housenumber'] ?? ''));
        $city = lsttraining_sim_first_address_city([
            'city' => $properties['addr:city'] ?? '',
            'town' => $properties['addr:town'] ?? '',
            'village' => $properties['addr:village'] ?? '',
            'municipality' => $properties['addr:municipality'] ?? '',
            'suburb' => $properties['addr:suburb'] ?? '',
        ], $area);
        $bestDistance = $distance;
        $best = [
            'address_full' => lsttraining_sim_format_visible_address($street, $houseNumber, $city),
            'street' => $street,
            'housenumber' => $houseNumber,
            'postcode' => trim((string) ($properties['addr:postcode'] ?? '')),
            'city' => $city,
            'suburb' => trim((string) ($properties['addr:suburb'] ?? '')),
            'longitude' => $coord[0],
            'latitude' => $coord[1],
            'distance_m' => (int) round($distance),
        ];
    }

    return $best;
}

function lsttraining_sim_nominatim_street_from_address(array $address): string {
    $keys = ['road', 'pedestrian', 'footway', 'residential', 'living_street', 'service', 'path', 'cycleway'];
    foreach ($keys as $key) {
        $value = trim((string) ($address[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function lsttraining_sim_nominatim_city_from_address(array $address): string {
    return lsttraining_sim_first_address_city([
        'city' => $address['city'] ?? '',
        'town' => $address['town'] ?? '',
        'village' => $address['village'] ?? '',
        'municipality' => $address['municipality'] ?? '',
        'suburb' => $address['suburb'] ?? '',
    ], []);
}

function lsttraining_sim_reverse_geocode_nominatim(array $loc, int $seed): ?array {
    if (!function_exists('wp_remote_get')) {
        return null;
    }

    $lon = (float) ($loc['longitude'] ?? 0.0);
    $lat = (float) ($loc['latitude'] ?? 0.0);
    if ($lon < -180.0 || $lon > 180.0 || $lat < -90.0 || $lat > 90.0) {
        return null;
    }

    $coord_key = sprintf('%.5F_%.5F', $lat, $lon);
    $cache_key = 'lsttraining_reverse_nominatim_' . md5($coord_key);
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $lock_key = 'lsttraining_reverse_nominatim_lock';
    if (get_transient($lock_key)) {
        return null;
    }
    set_transient($lock_key, 1, 1);

    $url = add_query_arg([
        'format' => 'jsonv2',
        'lat' => sprintf('%.7F', $lat),
        'lon' => sprintf('%.7F', $lon),
        'zoom' => 18,
        'addressdetails' => 1,
        'accept-language' => 'de',
    ], 'https://nominatim.openstreetmap.org/reverse');

    $referer = function_exists('home_url') ? home_url('/') : '';
    $response = wp_remote_get($url, [
        'timeout' => 5,
        'redirection' => 0,
        'limit_response_size' => 32768,
        'headers' => [
            'User-Agent' => 'LSTtraining-Plugin/1.0 (' . ($referer ?: 'WordPress') . ')',
            'Referer' => $referer,
        ],
    ]);

    if (is_wp_error($response)) {
        return null;
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        return null;
    }

    $json = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($json)) {
        return null;
    }

    $address = is_array($json['address'] ?? null) ? $json['address'] : [];
    $street = lsttraining_sim_nominatim_street_from_address($address);
    $houseNumber = trim((string) ($address['house_number'] ?? ''));
    if ($street !== '' && $houseNumber === '') {
        $houseNumber = (string) lsttraining_sim_pseudo_house_number($loc, $seed);
    }

    $displayName = trim((string) ($json['display_name'] ?? ''));
    $city = lsttraining_sim_nominatim_city_from_address($address);
    $addressFull = $street !== '' ? lsttraining_sim_format_visible_address($street, $houseNumber, $city) : $displayName;
    if ($street === '' && preg_match('/^-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?/', $addressFull)) {
        return null;
    }
    if ($addressFull === '') {
        return null;
    }

    $result = [
        'address_full' => $addressFull,
        'street' => $street,
        'housenumber' => $houseNumber,
        'postcode' => trim((string) ($address['postcode'] ?? '')),
        'city' => $city,
        'suburb' => trim((string) ($address['suburb'] ?? ($address['neighbourhood'] ?? ''))),
        'display_name' => $displayName,
    ];

    $cacheTtl = defined('DAY_IN_SECONDS') ? 30 * DAY_IN_SECONDS : 30 * 24 * 60 * 60;
    set_transient($cache_key, $result, $cacheTtl);
    return $result;
}

function lsttraining_sim_build_address_context(PDO $pdo, int $leitstelle_id, array $einsatz, array $loc, array $area): array {
    $poiName = trim((string) ($loc['poi_name_snapshot'] ?? ''));
    $companyName = $poiName;
    $locRoadHighway = (string) ($loc['road_highway'] ?? '');
    $locRoadRef = (string) ($loc['road_ref'] ?? '');
    $locRoadName = (string) ($loc['road_name'] ?? '');
    $locIsMotorway = lsttraining_sim_spawn_is_motorway_road($locRoadHighway, $locRoadRef, $locRoadName);

    if (!$locIsMotorway && $poiName !== '') {
        return [
            'address_full' => $poiName,
            'address_source' => 'poi',
            'poi_name' => $poiName,
            'company_name' => $companyName,
            'road_name' => '',
            'road_ref' => '',
            'road_highway' => '',
            'address_distance_m' => null,
            'address_postcode' => '',
            'address_city' => lsttraining_sim_address_city_from_area($area),
            'address_suburb' => '',
            'address_street' => '',
            'address_housenumber' => '',
        ];
    }

    $tileState = lsttraining_sim_road_tile_state($pdo, $leitstelle_id);
    if (empty($tileState['complete'])) {
        throw new RuntimeException(lsttraining_sim_road_tile_error($tileState));
    }

    $roadName = lsttraining_sim_readable_road_name([
        'name' => $locRoadName,
        'ref' => $locRoadRef,
        'highway' => $locRoadHighway,
    ]);
    if (!lsttraining_sim_road_is_dispatchable([
        'name' => $locRoadName,
        'ref' => $locRoadRef,
        'highway' => $locRoadHighway,
    ])) {
        $roadName = '';
    }
    $road = [
        'road_name' => $locRoadName,
        'road_ref' => $locRoadRef,
        'road_highway' => $locRoadHighway,
        'road_label' => $roadName,
        'distance_m' => null,
        'road_destination' => (string) ($loc['road_destination'] ?? ''),
        'road_destination_forward' => (string) ($loc['road_destination_forward'] ?? ''),
        'road_destination_backward' => (string) ($loc['road_destination_backward'] ?? ''),
        'road_junction_ref' => (string) ($loc['road_junction_ref'] ?? ''),
        'road_exit_to' => (string) ($loc['road_exit_to'] ?? ''),
        'road_segment_start' => $loc['road_segment_start'] ?? null,
        'road_segment_end' => $loc['road_segment_end'] ?? null,
        'road_bearing_deg' => $loc['road_bearing_deg'] ?? null,
    ];

    if ($roadName === '') {
        $nearest = lsttraining_sim_find_nearest_road($pdo, $leitstelle_id, $loc, $area);
        if ($nearest) {
            $road = $nearest;
            $roadName = (string) ($nearest['road_label'] ?? '');
        }
    }

    if ($roadName !== '') {
        $city = lsttraining_sim_address_city_from_area($area);
        $isMotorway = lsttraining_sim_spawn_is_motorway_road((string) ($road['road_highway'] ?? ''), (string) ($road['road_ref'] ?? ''), (string) ($road['road_name'] ?? ''));
        if ($isMotorway) {
            $road = lsttraining_sim_fill_motorway_context_from_nearest($pdo, $leitstelle_id, $road, $loc, $area);
        }
        $motorwayContext = $isMotorway ? lsttraining_sim_spawn_motorway_location_context($road, $area) : [];
        if ($isMotorway && empty($motorwayContext['motorway_location_label'])) {
            throw new RuntimeException('Autobahnnummer, Richtung oder Abschnitt konnte aus den Straßen-Tiles nicht vollständig ermittelt werden.');
        }
        $orientationNumber = '';
        if (!$isMotorway && trim((string) ($road['road_name'] ?? '')) !== '') {
            $orientationNumber = (string) lsttraining_sim_pseudo_house_number($loc, (int) ($einsatz['id'] ?? 0));
        }
        $addressFull = $isMotorway && !empty($motorwayContext['motorway_location_label'])
            ? (string) $motorwayContext['motorway_location_label']
            : lsttraining_sim_format_visible_address(
                $orientationNumber !== '' ? $roadName . ' auf Höhe Hausnummer' : $roadName,
                $orientationNumber,
                $city
            );
        $hasSpawnRoad = !empty($loc['road_highway']) || !empty($loc['road_name']) || !empty($loc['road_ref']);
        $addressSource = $hasSpawnRoad ? 'spawn_road' : 'nearest_road';
        if (!$isMotorway) {
            $addressSource .= '_orientation';
        }
        return array_merge([
            'address_full' => $addressFull,
            'address_source' => $addressSource,
            'poi_name' => $poiName,
            'company_name' => $companyName,
            'road_name' => (string) ($road['road_name'] ?? ''),
            'road_ref' => (string) ($road['road_ref'] ?? ''),
            'road_highway' => (string) ($road['road_highway'] ?? ''),
            'address_distance_m' => $road['distance_m'] ?? null,
            'address_postcode' => '',
            'address_city' => $city,
            'address_suburb' => (string) ($road['address_suburb'] ?? ''),
            'address_street' => $roadName,
            'address_housenumber' => $orientationNumber,
            'address_housenumber_approximate' => $orientationNumber !== '',
        ], $motorwayContext);
    }

    throw new RuntimeException('Keine Straßen- oder Ortsangabe aus den lokalen Straßen-Tiles ermittelbar.');
}

function lsttraining_sim_replace_raw_gps_in_text(string $text, string $address): string {
    $address = trim($address);
    if ($text === '' || $address === '') {
        return $text;
    }

    return (string) preg_replace(
        '/\bGPS\s+-?\d+(?:[.,]\d+)?\s*,\s*-?\d+(?:[.,]\d+)?\b/i',
        $address,
        $text
    );
}

function lsttraining_sim_text_contains_raw_gps(string $text): bool {
    return preg_match('/\bGPS\s+-?\d+(?:[.,]\d+)?\s*,\s*-?\d+(?:[.,]\d+)?\b/i', $text) === 1;
}

function lsttraining_sim_profile_tokens(array $tokens, ?array $profile): array {
    if (!$profile) {
        return $tokens;
    }

    if ((int) ($profile['uses_name'] ?? 1) === 0) {
        foreach (['first_name', 'last_name', 'full_name', 'formal_name', 'title_last_name', 'person'] as $key) {
            $tokens[$key] = '';
        }
    }
    if ((int) ($profile['uses_address'] ?? 1) === 0) {
        foreach (['address_full', 'address_street', 'address_housenumber', 'address_postcode', 'address_city', 'address_suburb', 'location'] as $key) {
            $tokens[$key] = '';
        }
    }
    if ((int) ($profile['uses_poi_name'] ?? 0) === 0) {
        $tokens['poi_name'] = '';
    }
    if ((int) ($profile['uses_company_name'] ?? 0) === 0) {
        $tokens['company_name'] = '';
    }

    return $tokens;
}

function lsttraining_sim_add_part_fragment(array &$fragments, array &$usedKeys, string $partKey, array $rows, array $tokens): void {
    $text = lsttraining_sim_pick_enabled_text($rows);
    if ($text === '') {
        return;
    }

    $filled = lsttraining_fill_anrufer_placeholders($text, $tokens);
    if ($filled === '') {
        return;
    }

    $fragments[] = $filled;
    $usedKeys[] = $partKey;
}

function lsttraining_sim_default_caller_name(array $tokens): string {
    $formalName = trim((string) ($tokens['formal_name'] ?? ''));
    $fullName = trim((string) ($tokens['full_name'] ?? ''));
    $firstName = trim((string) ($tokens['first_name'] ?? ''));
    $lastName = trim((string) ($tokens['last_name'] ?? ''));

    if ($formalName !== '' && preg_match('/^(Herr|Frau)\s+\S+/u', $formalName)) {
        return $formalName;
    }
    if ($fullName !== '') {
        return $fullName;
    }
    if (trim($firstName . ' ' . $lastName) !== '') {
        return trim($firstName . ' ' . $lastName);
    }
    if ($formalName !== '') {
        return $formalName;
    }
    return 'Max Mustermann';
}

function lsttraining_sim_default_caller_opener(array $tokens, bool $includeGreeting = true): string {
    $name = lsttraining_sim_default_caller_name($tokens);
    return ($includeGreeting ? 'Hallo, hier ist ' : 'Hier ist ') . $name . '.';
}

function lsttraining_sim_default_location_intro(array $tokens): string {
    $motorway = trim((string) ($tokens['motorway_location_label'] ?? ''));
    if ($motorway !== '') {
        return 'Ich bin auf der ' . $motorway . '.';
    }

    $address = trim((string) ($tokens['address_full'] ?? ($tokens['location'] ?? '')));
    return $address !== '' ? 'Ich bin bei ' . $address . '.' : '';
}

function lsttraining_sim_apply_motorway_location_phrase(string $text, array $addressContext): string {
    $motorway = trim((string) ($addressContext['motorway_location_label'] ?? ''));
    if ($text === '' || $motorway === '') {
        return $text;
    }

    $quoted = preg_quote($motorway, '/');
    $text = (string) preg_replace('/\bbei\s+(?:der\s+)?' . $quoted . '\b/iu', 'auf der ' . $motorway, $text);
    $ref = trim((string) ($addressContext['motorway_ref'] ?? ''));
    if ($ref !== '') {
        $text = (string) preg_replace('/\bbei\s+(?:der\s+)?' . preg_quote($ref, '/') . '\b/iu', 'auf der ' . $motorway, $text);
    }
    return (string) preg_replace('/\bbei\s+(?:der\s+)?Autobahn\b/iu', 'auf der ' . $motorway, $text);
}

function lsttraining_sim_fragments_contain_caller_identity(array $fragments, array $tokens): bool {
    $text = trim((string) preg_replace('/\s+/', ' ', implode(' ', $fragments)));
    if ($text === '') {
        return false;
    }

    $needles = [
        lsttraining_sim_default_caller_name($tokens),
        trim((string) ($tokens['formal_name'] ?? '')),
        trim((string) ($tokens['full_name'] ?? '')),
        trim((string) ($tokens['last_name'] ?? '')),
    ];
    foreach (array_unique(array_filter($needles)) as $needle) {
        if (strlen($needle) >= 3 && stripos($text, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function lsttraining_sim_ensure_caller_opener(array &$fragments, array &$profilePartKeys, array $tokens, int $insertAt, int $openingCount): void {
    $openingFragments = array_slice($fragments, $insertAt, $openingCount);
    if (lsttraining_sim_fragments_contain_caller_identity($openingFragments, $tokens)) {
        return;
    }

    $fallback = lsttraining_sim_default_caller_opener($tokens, $openingCount <= 0);
    array_splice($fragments, $insertAt + $openingCount, 0, [$fallback]);
    $profilePartKeys[] = 'system_greeting_fallback';
}

function lsttraining_sim_compose_system_caller_text(array $tokens, array $messageFragments): string {
    $fragments = [lsttraining_sim_default_caller_opener($tokens, true)];
    $fragments = array_merge($fragments, $messageFragments);
    $location = lsttraining_sim_default_location_intro($tokens);
    if ($location !== '') {
        $fragments[] = $location;
    }

    return trim((string) preg_replace('/\s+/', ' ', implode(' ', array_filter($fragments))));
}

function lsttraining_sim_caller_text_needs_rebuild(string $text, array $tokens): bool {
    if (trim($text) === '') {
        return true;
    }
    if (stripos($text, 'Adresse:') !== false) {
        return true;
    }
    return !lsttraining_sim_fragments_contain_caller_identity([$text], $tokens);
}

function lsttraining_sim_build_caller_payload(PDO $pdo, int $leitstelle_id, array $einsatz, array $loc, array $area): array {
    $parts = lsttraining_sim_fetch_einsatz_caller_parts($pdo, (int) ($einsatz['id'] ?? 0));
    $problem = lsttraining_sim_pick_enabled_text($parts['problem'] ?? []);
    $observation = lsttraining_sim_pick_enabled_text($parts['observation'] ?? []);
    $extra = lsttraining_sim_pick_enabled_text($parts['extra'] ?? []);
    if ($problem === '') {
        $problem = trim((string) ($einsatz['caller_what'] ?? ''));
    }

    $addressContext = lsttraining_sim_build_address_context($pdo, $leitstelle_id, $einsatz, $loc, $area);
    if (trim((string) ($addressContext['address_full'] ?? '')) === '') {
        throw new RuntimeException('Keine Adresse zur GPS-Position gefunden.');
    }

    $profile = lsttraining_sim_pick_caller_profile($pdo, (int) ($einsatz['id'] ?? 0));
    $genderKey = null;
    if ($profile && (string) ($profile['category'] ?? '') === 'private') {
        $genderKey = null;
    }

    $tokens = lsttraining_build_anrufer_tokens($pdo, $genderKey, array_merge($addressContext, [
        'problem' => $problem,
        'observation' => $observation,
        'extra' => $extra,
        'greeting' => '',
        'person' => trim((string) ($einsatz['caller_who'] ?? '')),
        'location' => trim((string) ($addressContext['address_full'] ?? '')),
    ]));

    $fragments = [];
    $messageFragments = [];
    $locationFragments = [];
    $profileSuffixFragments = [];
    $profilePartKeys = [];
    $messagePartKeys = [];
    $profileParts = [];
    if ($profile) {
        $profileTokens = lsttraining_sim_profile_tokens($tokens, $profile);
        $profileParts = lsttraining_sim_fetch_profile_parts($pdo, (int) ($profile['id'] ?? 0));
        $openingStart = count($fragments);
        lsttraining_sim_add_part_fragment($fragments, $profilePartKeys, 'greeting', $profileParts['greeting'] ?? [], $tokens);
        lsttraining_sim_add_part_fragment($fragments, $profilePartKeys, 'self_intro', $profileParts['self_intro'] ?? [], $tokens);
        lsttraining_sim_ensure_caller_opener($fragments, $profilePartKeys, $tokens, $openingStart, count($fragments) - $openingStart);
        lsttraining_sim_add_part_fragment($fragments, $profilePartKeys, 'problem_intro', $profileParts['problem_intro'] ?? [], $profileTokens);
        lsttraining_sim_add_part_fragment($locationFragments, $profilePartKeys, 'location_intro', $profileParts['location_intro'] ?? [], $tokens);
        $locationFallback = lsttraining_sim_default_location_intro($tokens);
        if (!in_array('location_intro', $profilePartKeys, true) && $locationFallback !== '') {
            $locationFragments[] = $locationFallback;
            $profilePartKeys[] = 'location_intro_fallback';
        }
        lsttraining_sim_add_part_fragment($profileSuffixFragments, $profilePartKeys, 'urgency', $profileParts['urgency'] ?? [], $profileTokens);
        lsttraining_sim_add_part_fragment($profileSuffixFragments, $profilePartKeys, 'closing', $profileParts['closing'] ?? [], $profileTokens);
        lsttraining_sim_add_part_fragment($profileSuffixFragments, $profilePartKeys, 'callback_request', $profileParts['callback_request'] ?? [], $profileTokens);
    } else {
        $fragments[] = lsttraining_sim_default_caller_opener($tokens, true);
        $profilePartKeys[] = 'system_greeting_fallback';
        $locationFallback = lsttraining_sim_default_location_intro($tokens);
        if ($locationFallback !== '') {
            $locationFragments[] = $locationFallback;
            $profilePartKeys[] = 'system_location_fallback';
        }
    }

    foreach ([
        'problem' => $problem,
        'observation' => $observation,
        'extra' => $extra,
    ] as $partKey => $messageText) {
        $messageText = trim((string) $messageText);
        if ($messageText === '') {
            continue;
        }
        $filled = lsttraining_fill_anrufer_placeholders($messageText, $tokens);
        if ($filled !== '') {
            $messageFragments[] = $filled;
            $messagePartKeys[] = $partKey;
        }
    }

    $fragments = array_merge($fragments, $messageFragments);
    $fragments = array_merge($fragments, $locationFragments);
    $fragments = array_merge($fragments, $profileSuffixFragments);

    $text = trim((string) preg_replace('/\s+/', ' ', implode(' ', array_filter($fragments))));
    if (lsttraining_sim_caller_text_needs_rebuild($text, $tokens)) {
        $text = lsttraining_sim_compose_system_caller_text($tokens, $messageFragments);
        $profilePartKeys[] = 'system_rebuild_fallback';
    }
    $text = lsttraining_sim_replace_raw_gps_in_text($text, (string) ($addressContext['address_full'] ?? ''));
    $text = lsttraining_sim_apply_motorway_location_phrase($text, $addressContext);
    if (lsttraining_sim_text_contains_raw_gps($text)) {
        throw new RuntimeException('Keine Adresse zur GPS-Position gefunden.');
    }

    return [
        'text' => $text,
        'meta' => [
            'caller_text_builder_version' => 'profile_v2',
            'caller_profile_id' => $profile ? (int) ($profile['id'] ?? 0) : null,
            'caller_profile_name' => $profile ? (string) ($profile['name'] ?? '') : '',
            'caller_name' => [
                'first_name' => (string) ($tokens['first_name'] ?? ''),
                'last_name' => (string) ($tokens['last_name'] ?? ''),
                'full_name' => (string) ($tokens['full_name'] ?? ''),
                'formal_name' => (string) ($tokens['formal_name'] ?? ''),
                'gender_key' => (string) ($tokens['gender_key'] ?? ''),
            ],
            'caller_profile_source' => $profile ? (string) ($profile['caller_profile_source'] ?? '') : 'system_default',
            'profile_part_keys' => array_values(array_unique($profilePartKeys)),
            'message_part_keys' => array_values(array_unique($messagePartKeys)),
            'generated_address' => (string) ($addressContext['address_full'] ?? ''),
            'address_source' => (string) ($addressContext['address_source'] ?? ''),
            'road_name' => (string) ($addressContext['road_name'] ?? ''),
            'road_ref' => (string) ($addressContext['road_ref'] ?? ''),
            'road_highway' => (string) ($addressContext['road_highway'] ?? ''),
            'motorway_ref' => (string) ($addressContext['motorway_ref'] ?? ''),
            'motorway_section' => (string) ($addressContext['motorway_section'] ?? ''),
            'motorway_direction' => (string) ($addressContext['motorway_direction'] ?? ''),
            'motorway_bearing' => $addressContext['motorway_bearing'] ?? null,
            'motorway_place' => (string) ($addressContext['motorway_place'] ?? ''),
            'motorway_location_label' => (string) ($addressContext['motorway_location_label'] ?? ''),
            'address_distance_m' => $addressContext['address_distance_m'] ?? null,
            'address_postcode' => (string) ($addressContext['address_postcode'] ?? ''),
            'address_city' => (string) ($addressContext['address_city'] ?? ''),
            'address_suburb' => (string) ($addressContext['address_suburb'] ?? ''),
            'address_street' => (string) ($addressContext['address_street'] ?? ''),
            'address_housenumber' => (string) ($addressContext['address_housenumber'] ?? ''),
            'address_housenumber_approximate' => !empty($addressContext['address_housenumber_approximate']),
            'nominatim_display_name' => (string) ($addressContext['nominatim_display_name'] ?? ''),
        ],
    ];
}

function lsttraining_sim_build_caller_text(array $einsatz): string {
    return trim(implode(' ', array_filter([
        (string) ($einsatz['caller_who'] ?? ''),
        (string) ($einsatz['caller_where'] ?? ''),
        (string) ($einsatz['caller_what'] ?? ''),
    ])));
}

function lsttraining_sim_spawn_one(PDO $pdo, int $instanz_id, array $options = []): array {
    $force_spawn = !empty($options['force']);
    $selected_einsatz_id = isset($options['einsatz_id']) ? max(0, (int) $options['einsatz_id']) : 0;

    $stmt = $pdo->prepare('
        SELECT si.*, l.geojson, l.name AS leitstelle_name
        FROM spielinstanzen si
        INNER JOIN leitstellen l ON l.id = si.leitstelle_id
        WHERE si.id = ?
        LIMIT 1
    ');
    $stmt->execute([$instanz_id]);
    $instance = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$instance) {
        throw new RuntimeException('Simulation nicht gefunden.');
    }

    $settings = lsttraining_sim_decode_json($instance['settings_json'] ?? '', []);
    if (empty($settings['auto_spawn']) && !$force_spawn) {
        return ['spawned' => false, 'message' => 'Automatische Einsatzerzeugung ist deaktiviert.'];
    }

    $state = (string) ($instance['sim_state'] ?? 'created');
    if (!in_array($state, ['created', 'running'], true)) {
        return ['spawned' => false, 'message' => 'Simulation ist nicht im Spawn-Zustand.'];
    }

    $leitstelle_id = (int) $instance['leitstelle_id'];
    $area = lsttraining_sim_load_area($pdo, $leitstelle_id);
    $runtime_state = lsttraining_sim_runtime_state($settings, $instance);
    $sim_now_ts = (int) $runtime_state['game_now_ts'];
    $sim_time = (new DateTimeImmutable('@' . $sim_now_ts))->setTimezone(wp_timezone());

    $spawn_mode = (string) ($settings['spawn_mode'] ?? 'fixed');
    if (!$force_spawn && $spawn_mode === 'dynamic') {
        $next_spawn_at = !empty($settings['next_auto_spawn_at'])
            ? lsttraining_sim_ts((string) $settings['next_auto_spawn_at'])
            : 0;

        if ($next_spawn_at > $sim_now_ts) {
            return [
                'spawned' => false,
                'message' => 'Nächster Spawn-Zeitpunkt noch nicht erreicht.',
                'next_auto_spawn_at' => $settings['next_auto_spawn_at'],
            ];
        }
    } elseif (!$force_spawn) {
        $spawn_interval = max(10, (int) ($settings['spawn_interval_sec'] ?? 120));
        $last_spawn_at = isset($settings['last_auto_spawn_at']) ? lsttraining_sim_ts((string) $settings['last_auto_spawn_at']) : 0;

        if ($last_spawn_at > 0 && ($sim_now_ts - $last_spawn_at) < $spawn_interval) {
            return ['spawned' => false, 'message' => 'Spawn-Intervall noch nicht erreicht.'];
        }
    }

    $max_active = max(1, (int) ($settings['max_active_einsaetze'] ?? 5));
    $active_stmt = $pdo->prepare("SELECT COUNT(*) FROM instanz_einsaetze WHERE instanz_id = ? AND state IN ('new','active')");
    $active_stmt->execute([$instanz_id]);
    if ((int) $active_stmt->fetchColumn() >= $max_active) {
        return ['spawned' => false, 'message' => 'Maximale Anzahl aktiver Einsätze erreicht.'];
    }

    $diagnostics = [];
    $manual_selected_spawn = $force_spawn && $selected_einsatz_id > 0;
    $candidates = lsttraining_sim_fetch_candidates($pdo, $leitstelle_id, $settings, $sim_time, $area, $diagnostics, $selected_einsatz_id, $manual_selected_spawn);
    $picked = lsttraining_sim_weighted_pick($candidates);
    if (!$picked) {
        $message = $selected_einsatz_id > 0
            ? 'Die gewählte Einsatzvorlage konnte im Einsatzgebiet nicht erzeugt werden.'
            : 'Keine passende Einsatzvorlage im Einsatzgebiet gefunden.';
        $show_diagnostics = $force_spawn || current_user_can('manage_options');
        if ($force_spawn || current_user_can('manage_options')) {
            $message .= ' Diagnose: ' . lsttraining_sim_spawn_diagnostics_message($diagnostics);
        }

        $result = [
            'spawned' => false,
            'message' => $message,
        ];
        if ($show_diagnostics) {
            $result['diagnostics'] = $diagnostics;
        }

        return $result;
    }

    $loc = $picked['_spawn_location'];
    if (!lsttraining_sim_point_inside_area([(float) $loc['longitude'], (float) $loc['latitude']], $area)) {
        return ['spawned' => false, 'message' => 'Erzeugter Einsatzort liegt außerhalb des Einsatzgebiets.'];
    }

    $meta = [
        'spawn_reason' => $selected_einsatz_id > 0 ? 'manual_selected' : ($force_spawn ? 'manual_test' : 'auto'),
        'leitstelle_id' => $leitstelle_id,
        'scope_type' => (string) ($picked['scope_type'] ?? 'anywhere'),
        'gebiet_check' => 'inside',
        'density_source' => (string) ($loc['density_source'] ?? 'fallback'),
        'landuse_layer' => (string) ($loc['landuse_layer'] ?? ''),
        'road_highway' => (string) ($loc['road_highway'] ?? ''),
        'road_name' => (string) ($loc['road_name'] ?? ''),
        'road_ref' => (string) ($loc['road_ref'] ?? ''),
        'road_bearing_deg' => $loc['road_bearing_deg'] ?? null,
        'poi_id' => isset($loc['id']) ? (int) $loc['id'] : null,
        'density_weight' => isset($loc['weight']) ? (int) $loc['weight'] : null,
        'season' => (string) ($settings['season'] ?? ''),
        'sim_time' => $sim_time->format('Y-m-d H:i:s'),
        'spawn_mode' => (string) ($settings['spawn_mode'] ?? 'fixed'),
        'leitstelle_load_factor' => (float) ($settings['leitstelle_load_factor'] ?? 1.0),
        'time_factor' => lsttraining_sim_time_factor($sim_time),
        'season_factor' => lsttraining_sim_season_factor((string) ($settings['season'] ?? '')),
        'call_status' => 'ringing',
    ];
    $location_diagnostics = is_array($picked['_spawn_location_diagnostics'] ?? null)
        ? $picked['_spawn_location_diagnostics']
        : [];
    foreach (['location_features_checked', 'location_candidates_selectable', 'location_candidate_weight_total'] as $diagnostic_key) {
        if (isset($location_diagnostics[$diagnostic_key])) {
            $meta[$diagnostic_key] = (int) $location_diagnostics[$diagnostic_key];
        }
    }
    if ($selected_einsatz_id > 0) {
        $meta['manual_selected_einsatz_id'] = $selected_einsatz_id;
    }
    $required_resources = lsttraining_sim_normalize_required_resources($picked['base_required_resources_json'] ?? '');
    if (!$required_resources) {
        $required_resources = lsttraining_sim_required_resources_from_patient_text((string) ($picked['patient_anforderung'] ?? ''));
    }
    if ($required_resources) {
        $meta['required_resources'] = $required_resources;
    }
    $patient_requirements = lsttraining_sim_patient_requirements_from_resources($required_resources, (int) ($picked['patientenzahl'] ?? 0));
    $patients = lsttraining_sim_normalize_patients($picked['patient_profile_json'] ?? '', $patient_requirements);
    if ($patients) {
        $meta['patients'] = $patients;
        $meta['patient_requirements'] = $patient_requirements;
    }

    try {
        $caller_payload = lsttraining_sim_build_caller_payload($pdo, $leitstelle_id, $picked, $loc, $area);
    } catch (RuntimeException $e) {
        return [
            'spawned' => false,
            'message' => $e->getMessage(),
        ];
    }
    $caller_text = trim((string) ($caller_payload['text'] ?? ''));
    $meta['caller'] = is_array($caller_payload['meta'] ?? null) ? $caller_payload['meta'] : [];
    $meta['caller_text_builder_version'] = (string) ($meta['caller']['caller_text_builder_version'] ?? 'profile_v2');
    $meta['caller_profile_id'] = isset($meta['caller']['caller_profile_id']) ? $meta['caller']['caller_profile_id'] : null;
    $meta['caller_profile_name'] = (string) ($meta['caller']['caller_profile_name'] ?? '');
    $meta['caller_profile_source'] = (string) ($meta['caller']['caller_profile_source'] ?? 'system_default');
    $meta['profile_part_keys'] = is_array($meta['caller']['profile_part_keys'] ?? null) ? $meta['caller']['profile_part_keys'] : [];
    $meta['message_part_keys'] = is_array($meta['caller']['message_part_keys'] ?? null) ? $meta['caller']['message_part_keys'] : [];
    if (!empty($meta['caller']['caller_profile_id'])) {
        $meta['caller_profile_id'] = (int) $meta['caller']['caller_profile_id'];
        $meta['caller_profile_name'] = (string) ($meta['caller']['caller_profile_name'] ?? '');
    }
    if (!empty($meta['caller']['caller_name']) && is_array($meta['caller']['caller_name'])) {
        $meta['caller_name'] = $meta['caller']['caller_name'];
    }
    if (!empty($meta['caller']['generated_address'])) {
        $meta['generated_address'] = (string) $meta['caller']['generated_address'];
        $meta['address_source'] = (string) ($meta['caller']['address_source'] ?? '');
        $meta['address_distance_m'] = $meta['caller']['address_distance_m'] ?? null;
        $meta['address_postcode'] = (string) ($meta['caller']['address_postcode'] ?? '');
        $meta['address_city'] = (string) ($meta['caller']['address_city'] ?? '');
        $meta['address_suburb'] = (string) ($meta['caller']['address_suburb'] ?? '');
        $meta['address_street'] = (string) ($meta['caller']['address_street'] ?? '');
        $meta['address_housenumber'] = (string) ($meta['caller']['address_housenumber'] ?? '');
        $meta['address_housenumber_approximate'] = !empty($meta['caller']['address_housenumber_approximate']);
        $meta['nominatim_display_name'] = (string) ($meta['caller']['nominatim_display_name'] ?? '');
    }
    if (!empty($meta['caller']['road_name'])) {
        $meta['road_name'] = (string) $meta['caller']['road_name'];
    }
    if (!empty($meta['caller']['road_ref'])) {
        $meta['road_ref'] = (string) $meta['caller']['road_ref'];
    }
    if (!empty($meta['caller']['road_highway'])) {
        $meta['road_highway'] = (string) $meta['caller']['road_highway'];
    }
    foreach (['motorway_ref', 'motorway_section', 'motorway_direction', 'motorway_bearing', 'motorway_place', 'motorway_location_label'] as $motorwayKey) {
        if (array_key_exists($motorwayKey, $meta['caller'])) {
            $meta[$motorwayKey] = $meta['caller'][$motorwayKey];
        }
    }
    $lagemeldung = trim((string) ($picked['lagemeldung'] ?? ''));
    if ($caller_text === '') {
        $caller_text = (string) ($picked['title'] ?: $picked['einsatztyp']);
    }
    if ($lagemeldung === '') {
        $lagemeldung = (string) ($picked['description'] ?: $picked['einsatztyp']);
    }

    $pdo->beginTransaction();
    $insert = $pdo->prepare('
        INSERT INTO instanz_einsaetze
            (instanz_id, leitstelle_id, source, source_id, einsatzart, einsatztyp, weather, uhrzeit_fenster,
             latitude, longitude, poi_type, poi_name_snapshot, caller_text, lagemeldung, state, meta_json)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $insert->execute([
        $instanz_id,
        $leitstelle_id,
        'template',
        (int) $picked['id'],
        (string) $picked['einsatzart'],
        (string) $picked['einsatztyp'],
        (string) ($settings['weather'] ?? 'auto'),
        $sim_time->format('H:i'),
        (float) $loc['latitude'],
        (float) $loc['longitude'],
        (string) ($picked['poi_type'] ?: ($loc['poi_type'] ?? '')),
        (string) ($loc['poi_name_snapshot'] ?? ''),
        $caller_text,
        $lagemeldung,
        'new',
        wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $einsatz_id = (int) $pdo->lastInsertId();

    $event = $pdo->prepare('
        INSERT INTO instanz_einsatz_events (instanz_einsatz_id, kind, text, meta_json)
        VALUES (?, ?, ?, ?)
    ');
    $event->execute([
        $einsatz_id,
        'system',
        'Neuer Notruf eingegangen.',
        wp_json_encode(['source_id' => (int) $picked['id'], 'source' => 'template'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    if (!$force_spawn) {
        $settings['last_auto_spawn_at'] = lsttraining_sim_time_string($sim_now_ts);
        if (($settings['spawn_mode'] ?? 'fixed') === 'dynamic') {
            $delay = lsttraining_sim_next_spawn_delay($settings, $sim_time);
            $settings['next_auto_spawn_at'] = lsttraining_sim_time_string($sim_now_ts + $delay);
            $settings['last_spawn_delay_sec'] = $delay;
        }
    }
    $settings = lsttraining_sim_reset_speed_settings($settings, $instance);
    $settings_update = $pdo->prepare('UPDATE spielinstanzen SET settings_json = ? WHERE id = ?');
    $settings_update->execute([
        wp_json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $instanz_id,
    ]);
    $pdo->commit();

    return [
        'spawned' => true,
        'message' => $selected_einsatz_id > 0 ? 'Ausgewählter Einsatz erzeugt.' : ($force_spawn ? 'Testeinsatz erzeugt.' : 'Einsatz erzeugt.'),
        'einsatz_id' => $einsatz_id,
        'next_auto_spawn_at' => $settings['next_auto_spawn_at'] ?? null,
        'last_spawn_delay_sec' => $settings['last_spawn_delay_sec'] ?? null,
        'einsatz' => [
            'id' => $einsatz_id,
            'einsatzart' => (string) $picked['einsatzart'],
            'einsatztyp' => (string) $picked['einsatztyp'],
            'latitude' => (float) $loc['latitude'],
            'longitude' => (float) $loc['longitude'],
            'caller_text' => $caller_text,
            'lagemeldung' => $lagemeldung,
            'state' => 'new',
            'meta' => $meta,
        ],
    ];
}

function lsttraining_sim_fetch_updates(PDO $pdo, int $instanz_id, int $since_id = 0): array {
    $stmt = $pdo->prepare('
        SELECT id, einsatzart, einsatztyp, latitude, longitude, poi_type, poi_name_snapshot,
               caller_text, lagemeldung, state, meta_json, created_at, updated_at
        FROM instanz_einsaetze
        WHERE instanz_id = ? AND id > ?
        ORDER BY id ASC
        LIMIT 100
    ');
    $stmt->execute([$instanz_id, $since_id]);
    $items = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $row['meta'] = lsttraining_sim_decode_json($row['meta_json'] ?? '', []);
        unset($row['meta_json']);
        $items[] = $row;
    }

    return $items;
}
