<?php
if (!defined('ABSPATH')) { exit(); }

function lsttraining_sim_transport_is_air_unit(array $unit): bool {
    $text = strtoupper(trim(implode(' ', [
        (string) ($unit['resource_class'] ?? ''),
        (string) ($unit['resource_class_label'] ?? ''),
        (string) ($unit['fahrzeugtyp'] ?? ''),
        (string) ($unit['rufname'] ?? ''),
    ])));

    return preg_match('/\b(RTH|ITH|NAH)\b|HUBSCH|HELI/u', $text) === 1;
}

function lsttraining_sim_transport_route(float $from_lat, float $from_lon, float $to_lat, float $to_lon, bool $air_allowed = false): array {
    if (!$air_allowed) {
        if (function_exists('lsttraining_sim_ground_route_with_connector')) {
            $route = lsttraining_sim_ground_route_with_connector($from_lat, $from_lon, $to_lat, $to_lon);
            if (is_array($route) && count($route['coordinates'] ?? []) >= 2) {
                return $route;
            }
        }

        return [
            'coordinates' => [],
            'distance_m' => 0,
            'duration_sec' => 0,
            'route_source' => 'routing_unavailable',
        ];
    }

    $distance = lsttraining_sim_distance_m($from_lat, $from_lon, $to_lat, $to_lon);
    $duration = max(90, (int) round($distance / 13.9));
    $coordinates = [
        [round($from_lon, 6), round($from_lat, 6)],
        [round($to_lon, 6), round($to_lat, 6)],
    ];

    return [
        'coordinates' => $coordinates,
        'distance_m' => (int) round($distance),
        'duration_sec' => $duration,
        'route_source' => 'air',
        'route_segments' => function_exists('lsttraining_sim_route_segment')
            ? [lsttraining_sim_route_segment('air', $coordinates, $duration, (int) round($distance))]
            : [],
    ];
}

function lsttraining_sim_transport_patient_department_preferences(array $patient, array $incident): array {
    $text = strtolower(implode(' ', [
        (string) ($patient['injury_summary'] ?? ''),
        (string) ($incident['einsatzart'] ?? ''),
        (string) ($incident['einsatztyp'] ?? ''),
        (string) ($incident['caller_text'] ?? ''),
        (string) ($incident['lagemeldung'] ?? ''),
    ]));
    $triage = strtoupper((string) ($patient['triage_category'] ?? ''));

    if (preg_match('/schlaganfall|stroke|halbseit|neurolog/i', $text)) {
        return ['STRK', 'NEUR', 'CT', 'NOTF'];
    }
    if (preg_match('/herz|brustschmerz|infarkt|reanimation|kreislauf/i', $text)) {
        return ['CARD', 'CAT', 'INTX', 'NOTF'];
    }
    if (preg_match('/brand|verbrenn/i', $text)) {
        return ['BURN', 'CHIR', 'NOTF'];
    }
    if (preg_match('/vergift|intox|gas|rauch/i', $text)) {
        return ['TOXI', 'INTX', 'NOTF'];
    }
    if (preg_match('/kind|baby|saeugling|säugling/i', $text)) {
        return ['KINA', 'PED', 'KKH', 'NOTF'];
    }
    if ($triage === 'I' || $triage === 'II' || preg_match('/unfall|trauma|sturz|verletz|blutung|verkehr/i', $text)) {
        return ['TRAU', 'UNF', 'CHIR', 'CT', 'NOTF'];
    }

    return ['NOTF', 'IMED', 'CHIR'];
}

function lsttraining_sim_transport_available_hospitals(PDO $pdo, int $leitstelle_id): array {
    $leitstelle_columns = lsttraining_sim_workspace_table_columns($pdo, 'leitstellen');
    $available_raw = [];
    if (!empty($leitstelle_columns['available_hospitals'])) {
        $available_stmt = $pdo->prepare('SELECT available_hospitals FROM leitstellen WHERE id = ? LIMIT 1');
        $available_stmt->execute([$leitstelle_id]);
        $available_raw = lsttraining_sim_workspace_json_array($available_stmt->fetchColumn());
    }

    $available_ids = [];
    $available_poi_ids = [];
    foreach ($available_raw as $key => $value) {
        if (is_array($value)) {
            $value = $value['id'] ?? ($value['hospital_id'] ?? ($value['krankenhaus_id'] ?? ($value['poi_id'] ?? '')));
        } elseif ($value === true) {
            $value = $key;
        }
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        if (ctype_digit($value)) {
            $available_ids[] = (int) $value;
        } else {
            $available_poi_ids[] = $value;
        }
    }
    $available_ids = array_values(array_unique(array_filter($available_ids)));
    $available_poi_ids = array_values(array_unique($available_poi_ids));

    $hospital_columns = lsttraining_sim_workspace_table_columns($pdo, 'krankenhaeuser');
    $select_parts = [];
    foreach (['id', 'poi_id', 'name', 'latitude', 'longitude', 'departments', 'trauma_level', 'helipad'] as $column) {
        $select_parts[] = !empty($hospital_columns[$column])
            ? '`' . $column . '`'
            : 'NULL AS `' . $column . '`';
    }

    $sql = 'SELECT ' . implode(', ', $select_parts) . ' FROM krankenhaeuser';
    $params = [];
    $where = [];
    if ($available_ids && !empty($hospital_columns['id'])) {
        $where[] = 'id IN (' . implode(',', array_fill(0, count($available_ids), '?')) . ')';
        $params = array_merge($params, $available_ids);
    }
    if ($available_poi_ids && !empty($hospital_columns['poi_id'])) {
        $where[] = 'poi_id IN (' . implode(',', array_fill(0, count($available_poi_ids), '?')) . ')';
        $params = array_merge($params, $available_poi_ids);
    }
    if ($where) {
        $sql .= ' WHERE (' . implode(' OR ', $where) . ')';
    }
    $sql .= ' ORDER BY name ASC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows && $where) {
        $stmt = $pdo->query('SELECT ' . implode(', ', $select_parts) . ' FROM krankenhaeuser ORDER BY name ASC LIMIT 500');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    $items = [];
    foreach ($rows as $row) {
        $lat = is_numeric($row['latitude'] ?? null) ? (float) $row['latitude'] : null;
        $lon = is_numeric($row['longitude'] ?? null) ? (float) $row['longitude'] : null;
        $departments = lsttraining_sim_workspace_department_details($row['departments'] ?? '[]', $lat, $lon);
        $first_location = $departments['locations'] ? reset($departments['locations']) : null;
        if (($lat === null || $lon === null || ($lat === 0.0 && $lon === 0.0)) && is_array($first_location)) {
            $lat = (float) $first_location['latitude'];
            $lon = (float) $first_location['longitude'];
        }
        if ($lat === null || $lon === null || ($lat === 0.0 && $lon === 0.0)) {
            continue;
        }
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'latitude' => $lat,
            'longitude' => $lon,
            'departments' => $departments['codes'],
            'trauma_level' => (int) ($row['trauma_level'] ?? 0),
            'helipad' => (int) ($row['helipad'] ?? 0),
        ];
    }

    return $items;
}

function lsttraining_sim_transport_find_hospital(PDO $pdo, int $leitstelle_id, array $patient, array $incident): ?array {
    $lat = isset($incident['latitude']) ? (float) $incident['latitude'] : null;
    $lon = isset($incident['longitude']) ? (float) $incident['longitude'] : null;
    if ($lat === null || $lon === null) {
        return null;
    }

    $preferred = lsttraining_sim_transport_patient_department_preferences($patient, $incident);
    $best = null;
    foreach (lsttraining_sim_transport_available_hospitals($pdo, $leitstelle_id) as $hospital) {
        $departments = array_map('strtoupper', (array) ($hospital['departments'] ?? []));
        $matched_department = '';
        $department_score = 0;
        foreach ($preferred as $index => $code) {
            if (in_array($code, $departments, true)) {
                $matched_department = $code;
                $department_score = 100 - ($index * 8);
                break;
            }
        }
        if ($department_score <= 0 && $departments) {
            $matched_department = (string) $departments[0];
            $department_score = 20;
        }
        $distance = lsttraining_sim_distance_m($lat, $lon, (float) $hospital['latitude'], (float) $hospital['longitude']);
        $score = $department_score - min(40, $distance / 2500);
        if ($best === null || $score > $best['score']) {
            $best = [
                'score' => $score,
                'department' => $matched_department,
                'hospital' => $hospital,
                'distance_m' => $distance,
            ];
        }
    }

    if (!$best) {
        return null;
    }

    $hospital = $best['hospital'];
    $hospital['matched_department'] = $best['department'];
    $hospital['distance_m'] = (int) round($best['distance_m']);
    return $hospital;
}

function lsttraining_sim_transport_assignment_can_transport(array $assignment, array $patient): bool {
    $phase = (string) ($assignment['mission_phase'] ?? '');
    if (in_array($phase, ['to_hospital', 'handover', 'returning', 'available'], true)) {
        return false;
    }
    if ((string) ($assignment['assignment_status'] ?? '') !== 'vor_ort') {
        return false;
    }
    $class = (string) ($assignment['resource_class'] ?? '');
    if (!empty($patient['requires_rtw']) || !empty($patient['requires_notarzt'])) {
        return in_array($class, ['rettungswagen', 'notarzt', 'rth'], true);
    }
    if (!empty($patient['requires_ktw'])) {
        return in_array($class, ['krankentransport', 'rettungswagen'], true);
    }
    return in_array($class, ['krankentransport', 'rettungswagen', 'notarzt', 'rth'], true);
}

function lsttraining_sim_transport_start(PDO $pdo, int $instanz_id, array $incident, array &$patient, array $assignment, array $hospital, int $sim_now_ts = 0): bool {
    $event_id = (int) ($assignment['event_id'] ?? 0);
    $status_id = (int) ($assignment['status_id'] ?? 0);
    if ($event_id <= 0 || $status_id <= 0) {
        return false;
    }

    $start = is_array($assignment['last_position'] ?? null) ? $assignment['last_position'] : [
        'latitude' => (float) ($incident['latitude'] ?? 0),
        'longitude' => (float) ($incident['longitude'] ?? 0),
    ];
    $route = lsttraining_sim_transport_route(
        (float) ($start['latitude'] ?? $incident['latitude']),
        (float) ($start['longitude'] ?? $incident['longitude']),
        (float) $hospital['latitude'],
        (float) $hospital['longitude'],
        lsttraining_sim_transport_is_air_unit($assignment)
    );
    if (count($route['coordinates'] ?? []) < 2) {
        $patient['transport_status'] = 'ready';
        $patient['transport_note'] = 'Wartet auf Straßenroute zum Krankenhaus.';
        return true;
    }
    $sim_now_ts = $sim_now_ts > 0 ? $sim_now_ts : time();
    $started_at = lsttraining_sim_time_string($sim_now_ts);

    $stmt = $pdo->prepare('SELECT meta_json FROM instanz_einsatz_events WHERE id = ? AND instanz_einsatz_id = ? LIMIT 1');
    $stmt->execute([$event_id, (int) ($incident['id'] ?? 0)]);
    $meta = lsttraining_sim_decode_meta($stmt->fetchColumn() ?: '');
    if (($meta['event_type'] ?? '') !== 'vehicle_alarm' || !empty($meta['cancelled_at'])) {
        return false;
    }

    $meta['mission_phase'] = 'to_hospital';
    $meta['transport_patient_id'] = (string) ($patient['patient_id'] ?? '');
    $meta['transport_status'] = 'to_hospital';
    $meta['transport_started_at'] = $started_at;
    $meta['transport_hospital_id'] = (int) ($hospital['id'] ?? 0);
    $meta['transport_hospital_name'] = (string) ($hospital['name'] ?? '');
    $meta['transport_department'] = (string) ($hospital['matched_department'] ?? '');
    $meta['route_coordinates'] = $route['coordinates'];
    $meta['route_distance_m'] = $route['distance_m'];
    $meta['route_duration_sec'] = $route['duration_sec'];
    $meta['route_duration_normal_sec'] = $route['duration_sec'];
    $meta['route_source'] = (string) ($route['route_source'] ?? '');
    $meta['route_segments'] = is_array($route['route_segments'] ?? null) ? $route['route_segments'] : [];
    $meta['current_segment_index'] = 0;
    $meta['current_segment_progress'] = 0;
    $meta['current_progress'] = 0;
    $meta['last_position'] = $start;
    $meta['phase_started_at'] = $started_at;
    $meta['hospital_arrived_at'] = '';
    $meta['handover_completed_at'] = '';
    $meta['return_completed_at'] = '';
    $meta['sondersignal_allowed'] = false;

    $update_event = $pdo->prepare('UPDATE instanz_einsatz_events SET meta_json = ? WHERE id = ?');
    $update_event->execute([lsttraining_sim_encode_meta($meta), $event_id]);

    lsttraining_sim_update_vehicle_state($pdo, $instanz_id, $status_id, [
        'ziel_latitude' => (float) $hospital['latitude'],
        'ziel_longitude' => (float) $hospital['longitude'],
        'status' => 'besetzt',
        'fms_status' => '3',
        'sondersignal' => 0,
        'bemerkung' => 'Transport zum Krankenhaus.',
    ]);

    $patient['transport_ready'] = true;
    $patient['transport_status'] = 'to_hospital';
    $patient['transport_vehicle_status_id'] = $status_id;
    $patient['transport_vehicle_event_id'] = $event_id;
    $patient['transport_vehicle_rufname'] = (string) ($assignment['rufname'] ?? '');
    $patient['transport_hospital_id'] = (int) ($hospital['id'] ?? 0);
    $patient['transport_hospital_name'] = (string) ($hospital['name'] ?? '');
    $patient['transport_department'] = (string) ($hospital['matched_department'] ?? '');
    $patient['transport_started_at'] = $started_at;
    $patient['transport_note'] = '';

    lsttraining_sim_insert_unit_event($pdo, (int) ($incident['id'] ?? 0), (string) ($assignment['rufname'] ?? 'Fahrzeug') . ': Transport nach ' . (string) ($hospital['name'] ?? 'Krankenhaus'), [
        'event_type' => 'patient_transport_started',
        'status_id' => $status_id,
        'fahrzeug_id' => (int) ($assignment['fahrzeug_id'] ?? 0),
        'rufname' => (string) ($assignment['rufname'] ?? ''),
        'patient_id' => (string) ($patient['patient_id'] ?? ''),
        'hospital_id' => (int) ($hospital['id'] ?? 0),
        'hospital_name' => (string) ($hospital['name'] ?? ''),
        'department' => (string) ($hospital['matched_department'] ?? ''),
    ]);

    return true;
}

function lsttraining_sim_transport_try_start(PDO $pdo, int $instanz_id, array $incident, array &$patients, array $incident_assignments, int $sim_now_ts = 0): bool {
    $dirty = false;
    foreach ($patients as &$patient) {
        if (!is_array($patient)) {
            continue;
        }
        $status = (string) ($patient['transport_status'] ?? 'none');
        if (in_array($status, ['to_hospital', 'handover', 'completed'], true) || empty($patient['transport_ready'])) {
            continue;
        }
        if (($patient['patient_status'] ?? '') === 'deceased') {
            continue;
        }

        $assignment = null;
        foreach ($incident_assignments as $candidate) {
            if (lsttraining_sim_transport_assignment_can_transport($candidate, $patient)) {
                $assignment = $candidate;
                break;
            }
        }
        if (!$assignment) {
            $patient['transport_status'] = 'ready';
            $patient['transport_note'] = 'Wartet auf geeignetes Transportmittel am Einsatzort.';
            $dirty = true;
            continue;
        }

        $hospital = lsttraining_sim_transport_find_hospital($pdo, (int) ($incident['leitstelle_id'] ?? 0), $patient, $incident);
        if (!$hospital) {
            $patient['transport_status'] = 'ready';
            $patient['transport_note'] = 'Kein geeignetes Krankenhaus gefunden.';
            $dirty = true;
            continue;
        }

        if (lsttraining_sim_transport_start($pdo, $instanz_id, $incident, $patient, $assignment, $hospital, $sim_now_ts)) {
            $dirty = true;
        }
    }
    unset($patient);

    return $dirty;
}
