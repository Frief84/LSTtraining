<?php
if (!defined('ABSPATH')) { exit; }

/** Editable dispatch keyword catalog. The seed is a historical Brandenburg starting point. */
function lsttraining_stichwoerter_defaults(): array {
    $rows = [
        // Code | title | clear vehicle-class defaults | source note
        'B:Klein|Kleinbrand|loeschfahrzeug:1|Papierkorb, Container; keine Ausbreitungsgefahr',
        'B:Pkw|Fahrzeugbrand Pkw|loeschfahrzeug:1|Pkw oder Motorrad',
        'B:Lkw|Fahrzeugbrand Lkw|loeschfahrzeug:2|Lkw, Bus, Traktor oder Zug',
        'B:Schornstein|Schornsteinbrand|loeschfahrzeug:1|Drehleiter nach örtlicher Festlegung',
        'B:Gebäude klein|Gebäudebrand klein|loeschfahrzeug:2|Garage, Schuppen, Bungalow oder Trafo',
        'B:Gebäude groß|Gebäudebrand groß|loeschfahrzeug:4|Zimmer-, Keller- oder Dachstuhlbrand',
        'B:Sonderobjekt|Brand Sonderobjekt||Objektbezogene Alarm- und Ausrückeordnung',
        'B:BMA|Brandmeldeanlage||Örtliche Festlegung',
        'B:Fläche|Flächenbrand|loeschfahrzeug:2|1 LF und 1 TLF',
        'B:Wald|Waldbrand|loeschfahrzeug:2|1 LF und 1 TLF',
        'B:Wald groß|Waldbrand groß|loeschfahrzeug:5|2 LF und 3 TLF',
        'B:Wald im WSP|Waldbrand im Waldschutzplan|loeschfahrzeug:5|Alternative Bezeichnung derselben PDF-Zeile; 2 LF und 3 TLF',
        'B:Schiene|Brand Schienenfahrzeug|loeschfahrzeug:2,ruestung:1|Bahn und Bundespolizei nach Lage',
        'B:Boot|Bootsbrand|loeschfahrzeug:1|Boot zusätzlich erforderlich',
        'B:Schiff|Schiffsbrand|loeschfahrzeug:4|Zwei Boote zusätzlich erforderlich',
        'B:Gefahrgut|Brand Gefahrgut|loeschfahrzeug:2,ruestung:1,gefahrgut:1,fuehrung:1|ABC-Zug laut Vorlage',
        'B:Kleinflugzeug|Brand Kleinflugzeug|loeschfahrzeug:2|1 LF und 1 TLF',
        'B:Großflugzeug|Brand Großflugzeug|loeschfahrzeug:4,ruestung:1,gefahrgut:1|ABC-Zug laut Vorlage',
        'B:Explosion|Explosion|loeschfahrzeug:4,ruestung:1|Weitere Mittel nach Lage',
        'H:klein|Hilfeleistung klein|loeschfahrzeug:1|Wasser im Keller oder Baum auf Straße',
        'H:Natur|Naturereignis|loeschfahrzeug:1|Witterungsbedingte Schäden',
        'H:Hilfeleistung|Allgemeine Hilfeleistung|loeschfahrzeug:1|Ausleuchten, Tragehilfe oder Hubschrauberlandung',
        'H:Türnotöffnung|Türnotöffnung|loeschfahrzeug:1|Rettungsdienst nach Lage',
        'H:VU ohne P|Verkehrsunfall ohne Verletzte|loeschfahrzeug:1|Blechschaden oder auslaufende Flüssigkeit',
        'H:VU mit P|Verkehrsunfall mit Verletzten|loeschfahrzeug:1,rettungswagen:1|Nicht eingeklemmte Personen',
        'H:VU Klemm|Verkehrsunfall eingeklemmte Person|loeschfahrzeug:2,rettungswagen:1|Notarzt nach Lage',
        'H:VU LKW/Bus|Verkehrsunfall Lkw oder Bus|loeschfahrzeug:2,rettungswagen:1,notarzt:1|MANV-Stufe nach Lage',
        'H:VU Schiene|Verkehrsunfall Schiene|loeschfahrzeug:4,ruestung:1,rettungswagen:1,notarzt:1|MANV-Stufe nach Lage',
        'H:VU Schiff|Verkehrsunfall Schiff|loeschfahrzeug:1,ruestung:1|Boot und Rettungsdienst nach Lage',
        'H:Flugzeugunfall klein|Kleinflugzeugunfall|loeschfahrzeug:2,rettungswagen:1|Notarzt nach Lage',
        'H:Flugzeugunfall groß|Großflugzeugunfall|loeschfahrzeug:2,ruestung:2|MANV-Stufe nach Lage',
        'H:Person auf Schiene|Person auf Schiene|loeschfahrzeug:1|Bahn und Bundespolizei nach Lage',
        'H:Person im Wasser/Eis|Person im Wasser oder Eis|loeschfahrzeug:1,rettungswagen:1|Boot und Notarzt nach Lage',
        'H:Rettung aus Höhen und Tiefen|Höhen- oder Tiefenrettung|loeschfahrzeug:1,rettungswagen:1|Höhenrettung nach Lage',
        'H:Gas|Gasgeruch oder Gasaustritt|loeschfahrzeug:2,ruestung:1|Gasversorger und Notarzt nach Lage',
        'H:Gefahrgut klein|Gefahrgut klein|loeschfahrzeug:2,ruestung:1,fuehrung:1|Fachberater Gefahrgut nach Lage',
        'H:Gefahrgut groß|Gefahrgut groß|loeschfahrzeug:2,ruestung:1,gefahrgut:1,fuehrung:1|ABC-Zug laut Vorlage',
        'H:Einsturz|Einsturz|loeschfahrzeug:2,ruestung:1,fuehrung:1|THW und Rettungsdienst nach Lage',
        'H:Öl Land|Öl auf Land||Umweltbehörde nach Lage',
        'H:Öl auf Wasser|Öl auf Wasser||Ölsperre und Boot nach Lage',
        'H:Tier in Not|Tier in Not||',
        'H:Kommunal|Kommunale Hilfeleistung||Amtshilfe oder Notarztzubringer',
        'H:Person-TMR|Technische Menschenrettung|loeschfahrzeug:2,rettungswagen:1|Notarzt nach Lage',
        'K1:KTW|Krankentransport|krankentransport:1|Sitzend, liegend oder Sauerstoff',
        'K2:KTW-Infektionsfahrt|Infektionsfahrt|krankentransport:1|I-KTW alternativ nach Lage',
        'R1N0 nt|Notfalltransport ohne Sondersignal|rettungswagen:1|Ohne Sondersignal',
        'R1N0|Rettungsdienst 1 RTW|rettungswagen:1|',
        'R2N0|Rettungsdienst 2 RTW|rettungswagen:2|',
        'R3N0|Rettungsdienst 3 RTW|rettungswagen:3|',
        'R1N1|Rettungsdienst 1 RTW und Notarzt|rettungswagen:1,notarzt:1|',
        'R2N1|Rettungsdienst 2 RTW und Notarzt|rettungswagen:2,notarzt:1|',
        'R3N1|Rettungsdienst 3 RTW und Notarzt|rettungswagen:3,notarzt:1|',
        'R4N1|Rettungsdienst 4 RTW und Notarzt|rettungswagen:4,notarzt:1|',
        'R2N2|Rettungsdienst 2 RTW und 2 Notärzte|rettungswagen:2,notarzt:2|',
        'R3N2|Rettungsdienst 3 RTW und 2 Notärzte|rettungswagen:3,notarzt:2|',
        'R4N2|Rettungsdienst 4 RTW und 2 Notärzte|rettungswagen:4,notarzt:2|',
        'R3N3|Rettungsdienst 3 RTW und 3 Notärzte|rettungswagen:3,notarzt:3|',
        'R4N3|Rettungsdienst 4 RTW und 3 Notärzte|rettungswagen:4,notarzt:3|',
        'R1N1 p|Polytrauma|rettungswagen:1,notarzt:1|RTH informatorisch vorschlagen',
        'R1N1 f|First Responder|rettungswagen:1,notarzt:1|First Responder informatorisch vorschlagen',
        'V1R|Verlegung mit RTW|rettungswagen:1|RTW-Verlegung alternativ',
        'V1N|Verlegung mit RTW und Notarzt|rettungswagen:1,notarzt:1|Abgebendes Krankenhaus prüfen',
        'V2R|Verlegung mit KTW oder RTW||Alternativen laut Vorlage',
        'V2N|Verlegung mit Notarzt|rettungswagen:1,notarzt:1|Sekundärtransport; RTW/RTW-Verlegung und NEF/V-NEF alternativ',
        'V3N|Verlegung mit Luftrettung und Notarzt||Alternativen laut Vorlage',
        'V4N|Verlegung Luftrettung|notarzt:1|ITH, RTH oder V-NEF alternativ',
        'V5R|Rücktransport|rettungswagen:1|RTW oder KTW alternativ',
        'MANV-Klein|Massenanfall klein||5 bis 9 Betroffene; örtliche Regelung',
        'MANV-Mittel|Massenanfall mittel||10 bis 19 Betroffene; örtliche Regelung',
        'MANV-Groß|Massenanfall groß||Mehr als 20 Betroffene; örtliche Regelung',
        'ÜMANV-Sofort|Überörtliche MANV-Hilfe||Eigene Maßnahmen bei MANV anderer Träger',
        'MAN-E|Massenanfall Erkrankte||Größere Zahl Erkrankter',
        'S1:Baby-NAW/Inkubator|Baby-Notarztwagen||Fahrt mit Inkubator',
        'S2:Notfallseelsorge|Notfallseelsorge||Nach Einsatzgeschehen',
        'S3:Org-Fahrten|Organisationsfahrt||Werkstattfahrt',
        'S4:Polizeiliche-Einsatzlage|Polizeiliche Einsatzlage||Einsatz durch Polizei geführt',
        'S5:Ausnahmelage|Ausnahmelage||Zum Beispiel Unwetter',
        'S6:Polizei-übergeben|Übergabe an Polizei||',
        'S7:Einsatz-Ablehnung|Einsatzablehnung||Nur Ablehnung, keine Höher- oder Niederstufung',
        'S8:Anforderung-Rettungshundestaffel|Rettungshundestaffel||Anforderung von Rettungshunden',
    ];
    $out = [];
    foreach ($rows as $line) {
        [$code, $title, $resources, $note] = explode('|', $line);
        $bedarf = [];
        foreach (array_filter(explode(',', $resources)) as $part) {
            [$type, $count] = explode(':', $part);
            $bedarf[] = ['type' => $type, 'count' => (int) $count];
        }
        $out[] = ['code' => $code, 'bezeichnung' => $title, 'einsatzart' => str_starts_with($code, 'B:') || str_starts_with($code, 'H:') ? 'FW' : 'RD', 'fahrzeuge' => $bedarf, 'hinweis' => $note];
    }
    return $out;
}

function lsttraining_stichwoerter_seed_leitstelle(PDO $pdo, int $leitstelle_id): void {
    if ($leitstelle_id <= 0) { return; }
    $stmt = $pdo->prepare('INSERT IGNORE INTO einsatzstichwoerter (leitstelle_id,code,bezeichnung,einsatzart,fahrzeuge_json,hinweis) VALUES (?,?,?,?,?,?)');
    $count = $pdo->prepare('SELECT COUNT(*) FROM einsatzstichwoerter WHERE leitstelle_id = ?');
    $count->execute([$leitstelle_id]);
    if ((int) $count->fetchColumn() > 0) { return; }
    foreach (lsttraining_stichwoerter_defaults() as $row) {
        $stmt->execute([$leitstelle_id, $row['code'], $row['bezeichnung'], $row['einsatzart'], wp_json_encode($row['fahrzeuge']), $row['hinweis']]);
    }
}

function lsttraining_stichwoerter_seed_all(PDO $pdo): void {
    foreach ($pdo->query('SELECT id FROM leitstellen')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
        lsttraining_stichwoerter_seed_leitstelle($pdo, (int) $id);
    }
}

function lsttraining_stichwoerter_list(PDO $pdo, int $leitstelle_id, bool $active_only = false): array {
    $stmt = $pdo->prepare('SELECT id,leitstelle_id,code,bezeichnung,beschreibung,einsatzart,fahrzeuge_json,hinweis,aktiv FROM einsatzstichwoerter WHERE leitstelle_id = ?' . ($active_only ? ' AND aktiv = 1' : '') . ' ORDER BY einsatzart,code');
    $stmt->execute([$leitstelle_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) { $row['fahrzeuge'] = json_decode((string) ($row['fahrzeuge_json'] ?? '[]'), true) ?: []; unset($row['fahrzeuge_json']); }
    return $rows;
}

function lsttraining_stichwoerter_get(PDO $pdo, int $leitstelle_id, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM einsatzstichwoerter WHERE id = ? AND leitstelle_id = ? LIMIT 1');
    $stmt->execute([$id, $leitstelle_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function lsttraining_stichwoerter_validate_resources($value): array {
    if (!is_array($value) || (array_values($value) !== $value)) { throw new InvalidArgumentException('fahrzeuge muss eine Liste sein.'); }
    $allowed = array_keys(lsttraining_sim_resource_class_labels());
    $out = [];
    foreach ($value as $row) {
        if (!is_array($row) || !in_array((string) ($row['type'] ?? ''), $allowed, true) || !filter_var($row['count'] ?? null, FILTER_VALIDATE_INT) || (int) $row['count'] < 1 || (int) $row['count'] > 100) {
            throw new InvalidArgumentException('Ungültige Fahrzeugklasse oder Anzahl.');
        }
        $out[] = ['type' => $row['type'], 'count' => (int) $row['count']];
    }
    return $out;
}

add_action('rest_api_init', static function (): void {
    $base = '/verwaltung/leitstellen/(?P<leitstelle_id>\d+)/einsatzstichwoerter';
    register_rest_route('lst/v1', $base, [
        ['methods' => WP_REST_Server::READABLE, 'callback' => 'lsttraining_stichwoerter_rest_list', 'permission_callback' => 'lsttraining_stichwoerter_rest_permission'],
        ['methods' => WP_REST_Server::CREATABLE, 'callback' => 'lsttraining_stichwoerter_rest_write', 'permission_callback' => 'lsttraining_stichwoerter_rest_permission'],
    ]);
    register_rest_route('lst/v1', $base . '/(?P<id>\d+)', [
        ['methods' => WP_REST_Server::READABLE, 'callback' => 'lsttraining_stichwoerter_rest_get', 'permission_callback' => 'lsttraining_stichwoerter_rest_permission'],
        ['methods' => WP_REST_Server::EDITABLE, 'callback' => 'lsttraining_stichwoerter_rest_write', 'permission_callback' => 'lsttraining_stichwoerter_rest_permission'],
        ['methods' => WP_REST_Server::DELETABLE, 'callback' => 'lsttraining_stichwoerter_rest_delete', 'permission_callback' => 'lsttraining_stichwoerter_rest_permission'],
    ]);
});
function lsttraining_stichwoerter_rest_permission(WP_REST_Request $request) {
    $id = (int) $request['leitstelle_id'];
    return is_user_logged_in() && lsttraining_user_can('leitstellen', $id)
        ? true : new WP_Error('lst_stichwort_forbidden', 'Keine Berechtigung für diese Leitstelle.', ['status' => 403]);
}
function lsttraining_stichwoerter_rest_list(WP_REST_Request $request) {
    try { return lst_manage_success(lsttraining_stichwoerter_list(lsttraining_get_connection(), (int) $request['leitstelle_id'])); }
    catch (Throwable $e) { return lst_manage_error('db_query_failed', 'Stichwörter konnten nicht geladen werden.', 500); }
}
function lsttraining_stichwoerter_rest_get(WP_REST_Request $request) {
    $row = lsttraining_stichwoerter_get(lsttraining_get_connection(), (int) $request['leitstelle_id'], (int) $request['id']);
    if (!$row) { return lst_manage_error('not_found', 'Stichwort nicht gefunden.', 404); }
    $row['fahrzeuge'] = json_decode((string) ($row['fahrzeuge_json'] ?? '[]'), true) ?: [];
    unset($row['fahrzeuge_json']);
    return lst_manage_success($row);
}
function lsttraining_stichwoerter_rest_write(WP_REST_Request $request) {
    $ls = (int) $request['leitstelle_id']; $id = (int) ($request['id'] ?? 0);
    try {
        $payload = lsttraining_rest_json_object($request, ['code','bezeichnung','beschreibung','einsatzart','fahrzeuge','hinweis','aktiv'], 65536);
        if ($id && !lsttraining_stichwoerter_get(lsttraining_get_connection(), $ls, $id)) { return lst_manage_error('not_found', 'Stichwort nicht gefunden.', 404); }
        if (!$id && (!isset($payload['code'], $payload['bezeichnung'], $payload['einsatzart']))) { throw new InvalidArgumentException('Code, Bezeichnung und Bereich sind erforderlich.'); }
        $data = [];
        foreach (['code' => 100, 'bezeichnung' => 255, 'beschreibung' => 4000, 'hinweis' => 4000] as $key => $max) {
            if (array_key_exists($key, $payload)) { $data[$key] = lsttraining_rest_assert_safe_string($key, $payload[$key], $max, in_array($key, ['beschreibung', 'hinweis'], true)); }
        }
        if (isset($data['code']) && $data['code'] === '' || isset($data['bezeichnung']) && $data['bezeichnung'] === '') { throw new InvalidArgumentException('Code und Bezeichnung dürfen nicht leer sein.'); }
        if (isset($payload['einsatzart'])) { if (!in_array($payload['einsatzart'], ['RD','FW'], true)) { throw new InvalidArgumentException('Ungültiger Bereich.'); } $data['einsatzart'] = $payload['einsatzart']; }
        if (isset($payload['fahrzeuge'])) { $data['fahrzeuge_json'] = wp_json_encode(lsttraining_stichwoerter_validate_resources($payload['fahrzeuge'])); }
        if (isset($payload['aktiv'])) { if (!is_bool($payload['aktiv'])) { throw new InvalidArgumentException('aktiv muss boolesch sein.'); } $data['aktiv'] = (int) $payload['aktiv']; }
        if (!$data) { throw new InvalidArgumentException('Keine Änderungen übermittelt.'); }
        $pdo = lsttraining_get_connection();
        if ($id) {
            $before = lsttraining_stichwoerter_get($pdo, $ls, $id);
            $pdo->beginTransaction();
            $set = implode(',', array_map(static fn($name) => $name . ' = ?', array_keys($data)));
            $pdo->prepare('UPDATE einsatzstichwoerter SET ' . $set . ' WHERE id = ? AND leitstelle_id = ?')->execute([...array_values($data), $id, $ls]);
            if ((isset($data['code']) && $data['code'] !== $before['code'])
                || (isset($data['einsatzart']) && $data['einsatzart'] !== $before['einsatzart'])
                || (isset($data['aktiv']) && !$data['aktiv'])) {
                $pdo->prepare('UPDATE einsaetze SET stichwort_id = NULL WHERE stichwort_id = ? AND leitstelle_id = ?')->execute([$id, $ls]);
            }
            $pdo->commit();
        } else {
            $columns = implode(',', array_keys($data));
            $marks = implode(',', array_fill(0, count($data), '?'));
            $pdo->prepare('INSERT INTO einsatzstichwoerter (leitstelle_id,' . $columns . ') VALUES (?,' . $marks . ')')->execute([$ls, ...array_values($data)]);
            $id = (int) $pdo->lastInsertId();
        }
        $saved = lsttraining_stichwoerter_get($pdo, $ls, $id);
        $saved['fahrzeuge'] = json_decode((string) ($saved['fahrzeuge_json'] ?? '[]'), true) ?: [];
        unset($saved['fahrzeuge_json']);
        return lst_manage_success($saved, $request->get_method() === 'POST' ? 201 : 200);
    } catch (InvalidArgumentException $e) { return lst_manage_error('validation_failed', $e->getMessage(), 400); }
    catch (PDOException $e) { if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); } return lst_manage_error('db_write_failed', $e->getCode() === '23000' ? 'Dieser Code existiert bereits.' : 'Stichwort konnte nicht gespeichert werden.', 409); }
    catch (Throwable $e) { if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); } return lst_manage_error('db_write_failed', 'Stichwort konnte nicht gespeichert werden.', 500); }
}
function lsttraining_stichwoerter_rest_delete(WP_REST_Request $request) {
    if (!in_array($request->get_param('confirm'), [true,1,'1','true'], true)) { return lst_manage_error('confirmation_required', 'confirm=true ist erforderlich.', 400); }
    $pdo = lsttraining_get_connection(); $ls = (int) $request['leitstelle_id']; $id = (int) $request['id'];
    if (!lsttraining_stichwoerter_get($pdo, $ls, $id)) { return lst_manage_error('not_found', 'Stichwort nicht gefunden.', 404); }
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE einsaetze SET stichwort_id = NULL WHERE stichwort_id = ? AND leitstelle_id = ?')->execute([$id, $ls]);
        $pdo->prepare('DELETE FROM einsatzstichwoerter WHERE id = ? AND leitstelle_id = ?')->execute([$id, $ls]);
        $pdo->commit();
        return lst_manage_success(['id' => $id, 'deleted' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return lst_manage_error('db_write_failed', 'Stichwort konnte nicht gelöscht werden.', 500);
    }
}

add_action('wp_ajax_lsttraining_stichwoerter_for_instance', static function (): void {
    lsttraining_sim_check_nonce();
    $pdo = lsttraining_get_connection(); $instance_id = absint($_POST['instanz_id'] ?? 0);
    if (!$instance_id || !lsttraining_sim_user_can_access_instance($pdo, $instance_id, (int) get_current_user_id())) { wp_send_json_error(['message' => 'Kein Zugriff.'], 403); }
    $stmt = $pdo->prepare('SELECT leitstelle_id FROM spielinstanzen WHERE id = ?'); $stmt->execute([$instance_id]);
    wp_send_json_success(['items' => lsttraining_stichwoerter_list($pdo, (int) $stmt->fetchColumn(), true)]);
});

add_action('wp_ajax_lst_get_stichwoerter', static function (): void {
    lsttraining_ajax_guard(['area' => 'leitstellen', 'nonce_action' => 'lsttraining_leitstellen', 'nonce_field' => 'nonce', 'method' => 'POST']);
    $id = absint($_POST['leitstelle_id'] ?? 0);
    if (!$id || !lsttraining_user_can('leitstellen', $id)) { wp_send_json_error(['message' => 'Keine Berechtigung.'], 403); }
    try { wp_send_json_success(['items' => lsttraining_stichwoerter_list(lsttraining_get_connection(), $id, true)]); }
    catch (Throwable $e) { wp_send_json_error(['message' => 'Stichwörter konnten nicht geladen werden.'], 500); }
});
