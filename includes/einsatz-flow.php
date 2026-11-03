<?php
if (!defined('ABSPATH')) { exit; }

/** Shared validation for the editor and the management REST API. */
function lsttraining_einsatz_flow_validate(array $rows): array {
    $keys = [];
    $initial = [];
    $arrival_defaults = 0;
    foreach ($rows as $index => &$row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('Ungültige Lagevariante.');
        }
        $role = (string) ($row['flow_role'] ?? '');
        if ($role === '') {
            $role = ($row['trigger_mode'] ?? '') === 'on_unit_arrival' && ($row['kind'] ?? '') === 'unit_report' ? 'initial' : 'development';
        }
        if (!in_array($role, ['initial', 'development'], true)) {
            throw new InvalidArgumentException('Ungültige Rolle der Lagevariante.');
        }
        $key = trim((string) ($row['flow_key'] ?? ''));
        if ($key === '') {
            $key = 'flow_' . bin2hex(random_bytes(8));
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{4,64}$/', $key) || isset($keys[$key])) {
            throw new InvalidArgumentException('Knotenkennungen müssen eindeutig sein.');
        }
        $keys[$key] = $role;
        $row['flow_key'] = $key;
        $row['flow_role'] = $role;
        $probability = $row['probability_percent'] ?? 100;
        if (!is_numeric($probability) || (int) $probability < 0 || (int) $probability > 100) {
            throw new InvalidArgumentException('Die Wahrscheinlichkeit muss zwischen 0 und 100 % liegen.');
        }
        $effect = $row['effect_json'] ?? [];
        if (is_string($effect)) { $effect = json_decode($effect, true); }
        if (is_array($effect) && ($effect['flow_stage'] ?? '') === 'followup_call'
            && ($role !== 'development' || ($row['kind'] ?? '') !== 'caller_answer' || ($row['speaker_type'] ?? '') !== 'caller')) {
            throw new InvalidArgumentException('Ein Folgeanruf muss als Anruferantwort angelegt sein.');
        }
        if ($role === 'initial') {
            $stage = is_array($effect) ? (string) ($effect['flow_stage'] ?? '') : '';
            if ($stage === 'arrival_default') {
                $arrival_defaults++;
                if ((int) $probability !== 0) {
                    throw new InvalidArgumentException('Die Standardlage hat keine eigene Wahrscheinlichkeit.');
                }
            } else {
                $initial[] = $row;
            }
            $probability = $row['probability_percent'] ?? 100;
            if (!is_numeric($probability) || (int) $probability < 0 || (int) $probability > 100) {
                throw new InvalidArgumentException('Die Wahrscheinlichkeit muss zwischen 0 und 100 % liegen.');
            }
            $row['kind'] = 'unit_report';
            $row['trigger_mode'] = 'on_unit_arrival';
        }
        $parents = $row['applicable_initial_keys'] ?? [];
        if (is_string($parents)) {
            $parents = json_decode($parents, true);
        }
        if (!is_array($parents) || array_values($parents) !== $parents) {
            throw new InvalidArgumentException('Erstlagen-Zuordnung muss eine Liste sein.');
        }
        $row['applicable_initial_keys'] = array_values(array_unique(array_map('strval', $parents)));
    }
    unset($row);
    if ($arrival_defaults > 1) {
        throw new InvalidArgumentException('Es darf nur eine Standardlage beim Eintreffen geben.');
    }
    $initial_sum = array_sum(array_map(static fn($row) => (int) ($row['probability_percent'] ?? 100), $initial));
    if (($arrival_defaults && $initial_sum > 100) || (!$arrival_defaults && $initial && $initial_sum !== 100)) {
        throw new InvalidArgumentException('Die Wahrscheinlichkeiten der Eintreffvarianten dürfen zusammen höchstens 100 % ergeben.');
    }
    foreach ($rows as $row) {
        if ($row['flow_role'] === 'initial' && $row['applicable_initial_keys']) {
            throw new InvalidArgumentException('Erstlagen können keine Erstlagen-Zuordnung haben.');
        }
        foreach ($row['applicable_initial_keys'] as $parent) {
            if (($keys[$parent] ?? '') !== 'initial') {
                throw new InvalidArgumentException('Eine Entwicklung verweist auf eine unbekannte Erstlage.');
            }
        }
    }
    $flow_parents = [];
    foreach ($rows as $row) {
        $effect = $row['effect_json'] ?? [];
        if (is_string($effect)) { $effect = json_decode($effect, true); }
        if (!is_array($effect) || !array_key_exists('flow_parent_keys', $effect)) { continue; }
        $parents = $effect['flow_parent_keys'];
        if (($row['flow_role'] ?? '') === 'development' && ($row['kind'] ?? '') === 'unit_report' && !$parents) {
            throw new InvalidArgumentException('Jede Folgelage benötigt mindestens einen Vorgänger.');
        }
        if (($row['kind'] ?? '') === 'unit_report' && array_key_exists('radio_report_text', $effect) && trim((string) $effect['radio_report_text']) === '') {
            throw new InvalidArgumentException('Jede Lage benötigt eine Rückmeldung an die Leitstelle.');
        }
        if (!is_array($parents) || array_values($parents) !== $parents) {
            throw new InvalidArgumentException('Vorgänger müssen eine Liste sein.');
        }
        $key = $row['flow_key'];
        $flow_parents[$key] = [];
        foreach ($parents as $parent) {
            if (!is_string($parent) || !isset($keys[$parent]) || $parent === $key) {
                throw new InvalidArgumentException('Ungültige Lageverbindung.');
            }
            $flow_parents[$key][] = $parent;
        }
    }
    $visiting = []; $visited = [];
    $check_cycle = static function (string $key) use (&$check_cycle, &$visiting, &$visited, $flow_parents): void {
        if (isset($visiting[$key])) { throw new InvalidArgumentException('Lageverbindungen dürfen keinen Kreis bilden.'); }
        if (isset($visited[$key])) { return; }
        $visiting[$key] = true;
        foreach ($flow_parents[$key] ?? [] as $parent) { $check_cycle($parent); }
        unset($visiting[$key]);
        $visited[$key] = true;
    };
    foreach (array_keys($flow_parents) as $key) { $check_cycle($key); }
    return $rows;
}

/** Largest remainder distribution retains the old relative probabilities. */
function lsttraining_einsatz_flow_percentages(array $weights): array {
    $weights = array_map(static fn($v) => max(0, (int) $v), $weights);
    $total = array_sum($weights);
    if ($total === 0) { $weights = array_fill(0, count($weights), 1); $total = count($weights); }
    if ($total === 0) { return []; }
    $values = []; $remainders = [];
    foreach ($weights as $index => $weight) {
        $exact = 100 * $weight / $total;
        $values[$index] = (int) floor($exact);
        $remainders[$index] = $exact - $values[$index];
    }
    arsort($remainders, SORT_NUMERIC);
    foreach (array_slice(array_keys($remainders), 0, 100 - array_sum($values)) as $index) { $values[$index]++; }
    ksort($values);
    return array_values($values);
}
