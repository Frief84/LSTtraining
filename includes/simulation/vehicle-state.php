<?php
if (!defined('ABSPATH')) { exit(); }

function lsttraining_sim_vehicle_state_model(): string {
    return 'baseline_delta_v1';
}

function lsttraining_sim_instance_uses_vehicle_delta_model(PDO $pdo, int $instanz_id): bool {
    $stmt = $pdo->prepare('SELECT settings_json FROM spielinstanzen WHERE id = ? LIMIT 1');
    $stmt->execute([$instanz_id]);
    $settings = json_decode((string) ($stmt->fetchColumn() ?: ''), true);

    return is_array($settings)
        && (string) ($settings['vehicle_state_model'] ?? '') === lsttraining_sim_vehicle_state_model();
}

function lsttraining_sim_require_vehicle_delta_model(PDO $pdo, int $instanz_id): void {
    if (!lsttraining_sim_instance_uses_vehicle_delta_model($pdo, $instanz_id)) {
        throw new RuntimeException('Diese Simulation nutzt ein altes Fahrzeugstatusmodell. Bitte starten Sie eine neue Simulation.');
    }
}

function lsttraining_sim_fetch_effective_vehicle_state(PDO $pdo, int $instanz_id, int $status_id): ?array {
    $stmt = $pdo->prepare('
        SELECT
            fs.id AS status_id,
            fs.instanz_id,
            fs.fahrzeug_id,
            fs.wache_id,
            CASE WHEN ifs.id IS NULL THEN fs.latitude ELSE ifs.latitude END AS latitude,
            CASE WHEN ifs.id IS NULL THEN fs.longitude ELSE ifs.longitude END AS longitude,
            CASE WHEN ifs.id IS NULL THEN fs.ziel_latitude ELSE ifs.ziel_latitude END AS ziel_latitude,
            CASE WHEN ifs.id IS NULL THEN fs.ziel_longitude ELSE ifs.ziel_longitude END AS ziel_longitude,
            CASE WHEN ifs.id IS NULL THEN fs.status ELSE ifs.status END AS status,
            CASE WHEN ifs.id IS NULL THEN fs.fms_status ELSE ifs.fms_status END AS fms_status,
            CASE WHEN ifs.id IS NULL THEN fs.sondersignal ELSE ifs.sondersignal END AS sondersignal,
            CASE WHEN ifs.id IS NULL THEN fs.bemerkung ELSE ifs.bemerkung END AS bemerkung,
            fs.latitude AS baseline_latitude,
            fs.longitude AS baseline_longitude,
            fs.ziel_latitude AS baseline_ziel_latitude,
            fs.ziel_longitude AS baseline_ziel_longitude,
            fs.status AS baseline_status,
            fs.fms_status AS baseline_fms_status,
            fs.sondersignal AS baseline_sondersignal,
            fs.bemerkung AS baseline_bemerkung,
            ifs.id AS delta_id
        FROM fahrzeug_status fs
        LEFT JOIN instanz_fahrzeug_status ifs
          ON ifs.instanz_id = fs.instanz_id
         AND ifs.fahrzeug_status_id = fs.id
        WHERE fs.id = ? AND fs.instanz_id = ?
        LIMIT 1
    ');
    $stmt->execute([$status_id, $instanz_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function lsttraining_sim_vehicle_state_values_equal(string $field, $left, $right): bool {
    if ($left === null || $right === null) {
        return $left === null && $right === null;
    }

    if (in_array($field, ['latitude', 'longitude', 'ziel_latitude', 'ziel_longitude'], true)) {
        return abs((float) $left - (float) $right) < 0.0000001;
    }
    if ($field === 'sondersignal') {
        return (int) $left === (int) $right;
    }

    return (string) $left === (string) $right;
}

function lsttraining_sim_update_vehicle_state(PDO $pdo, int $instanz_id, int $status_id, array $updates): void {
    lsttraining_sim_require_vehicle_delta_model($pdo, $instanz_id);

    $state = lsttraining_sim_fetch_effective_vehicle_state($pdo, $instanz_id, $status_id);
    if (!$state) {
        throw new RuntimeException('Fahrzeug-Baseline wurde nicht gefunden.');
    }

    $fields = ['latitude', 'longitude', 'ziel_latitude', 'ziel_longitude', 'status', 'fms_status', 'sondersignal', 'bemerkung'];
    $values = [];
    foreach ($fields as $field) {
        $values[$field] = array_key_exists($field, $updates) ? $updates[$field] : ($state[$field] ?? null);
    }
    $values['sondersignal'] = (int) ($values['sondersignal'] ?? 0);

    $has_delta = false;
    foreach (['latitude', 'longitude', 'ziel_latitude', 'ziel_longitude', 'status', 'fms_status', 'sondersignal'] as $field) {
        if (!lsttraining_sim_vehicle_state_values_equal($field, $values[$field], $state['baseline_' . $field] ?? null)) {
            $has_delta = true;
            break;
        }
    }

    if (!$has_delta) {
        $delete = $pdo->prepare('DELETE FROM instanz_fahrzeug_status WHERE instanz_id = ? AND fahrzeug_status_id = ?');
        $delete->execute([$instanz_id, $status_id]);
        return;
    }

    $stmt = $pdo->prepare('
        INSERT INTO instanz_fahrzeug_status
            (instanz_id, fahrzeug_status_id, latitude, longitude, ziel_latitude, ziel_longitude,
             status, fms_status, sondersignal, bemerkung, letzte_aktualisierung)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            ziel_latitude = VALUES(ziel_latitude),
            ziel_longitude = VALUES(ziel_longitude),
            status = VALUES(status),
            fms_status = VALUES(fms_status),
            sondersignal = VALUES(sondersignal),
            bemerkung = VALUES(bemerkung),
            letzte_aktualisierung = CURRENT_TIMESTAMP
    ');
    $stmt->execute([
        $instanz_id,
        $status_id,
        $values['latitude'],
        $values['longitude'],
        $values['ziel_latitude'],
        $values['ziel_longitude'],
        $values['status'],
        $values['fms_status'],
        $values['sondersignal'],
        $values['bemerkung'],
    ]);
}
