<?php
if (!defined('ABSPATH')) { exit; }

function lsttraining_region_map(): array {
    static $regions = null;
    if (is_array($regions)) {
        return $regions;
    }

    $path = LSTTRAINING_PATH . 'data/bundeslaender.json';
    $decoded = is_readable($path) ? json_decode((string) file_get_contents($path), true) : [];
    $regions = is_array($decoded) ? $decoded : [];

    return $regions;
}

function lsttraining_region_validate_selection(string $land, string $bundesland): array {
    $land = trim($land);
    $bundesland = trim($bundesland);
    $regions = lsttraining_region_map();

    if ($land === '') {
        return $bundesland === ''
            ? ['valid' => true, 'land' => '', 'bundesland' => '', 'message' => '']
            : ['valid' => false, 'land' => '', 'bundesland' => '', 'message' => 'Ein Bundesland benötigt ein Land.'];
    }
    if (!array_key_exists($land, $regions)) {
        return ['valid' => false, 'land' => '', 'bundesland' => '', 'message' => 'Das ausgewählte Land ist ungültig.'];
    }
    if ($bundesland !== '' && !in_array($bundesland, (array) $regions[$land], true)) {
        return ['valid' => false, 'land' => $land, 'bundesland' => '', 'message' => 'Das ausgewählte Bundesland gehört nicht zum ausgewählten Land.'];
    }

    return ['valid' => true, 'land' => $land, 'bundesland' => $bundesland, 'message' => ''];
}

function lsttraining_region_filter_values(array $request): array {
    $land = isset($request['land']) ? sanitize_text_field(wp_unslash((string) $request['land'])) : '';
    $bundesland = isset($request['bundesland']) ? sanitize_text_field(wp_unslash((string) $request['bundesland'])) : '';
    $regions = lsttraining_region_map();

    if ($land === '' || !array_key_exists($land, $regions)) {
        return ['', ''];
    }
    if ($bundesland !== '' && $bundesland !== '__none__' && !in_array($bundesland, (array) $regions[$land], true)) {
        $bundesland = '';
    }

    return [$land, $bundesland];
}

function lsttraining_region_normalize_match_text(string $value): string {
    $value = strtr($value, [
        'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
    ]);
    $value = strtolower($value);
    $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim((string) preg_replace('/\s+/', ' ', $value));
}

function lsttraining_region_detect_bundesland_for_land(string $land, string $name, string $zustandigkeit): string {
    $regions = lsttraining_region_map();
    if (!array_key_exists($land, $regions)) {
        return '';
    }

    $haystack = ' ' . lsttraining_region_normalize_match_text($name . ' ' . $zustandigkeit) . ' ';
    $candidates = [];
    foreach ((array) $regions[$land] as $bundesland) {
        $needle = lsttraining_region_normalize_match_text((string) $bundesland);
        if ($needle !== '') {
            $candidates[] = ['bundesland' => (string) $bundesland, 'needle' => $needle];
        }
    }
    if ($land === 'Deutschland') {
        foreach (['saechsisch', 'saechsische', 'saechsischen', 'saechsischer', 'saechsisches'] as $needle) {
            $candidates[] = ['bundesland' => 'Sachsen', 'needle' => $needle];
        }
    }
    usort($candidates, static fn(array $a, array $b): int => strlen($b['needle']) <=> strlen($a['needle']));

    foreach ($candidates as $candidate) {
        if (strpos($haystack, ' ' . $candidate['needle'] . ' ') !== false) {
            return $candidate['bundesland'];
        }
    }
    return '';
}

function lsttraining_region_detect(string $name, string $zustandigkeit): array {
    $haystack = ' ' . lsttraining_region_normalize_match_text($name . ' ' . $zustandigkeit) . ' ';
    if (preg_match('/\bsaechsisch(?:e|en|er|es)?\s+schweiz\b/', $haystack)) {
        return ['land' => 'Deutschland', 'bundesland' => 'Sachsen'];
    }
    $country_aliases = [
        'Deutschland' => ['deutschland', 'germany'],
        'Österreich' => ['oesterreich', 'austria'],
        'Schweiz' => ['schweiz', 'switzerland', 'suisse', 'svizzera'],
    ];
    $explicit_land = '';
    foreach ($country_aliases as $land => $aliases) {
        foreach ($aliases as $alias) {
            if ($land === 'Schweiz' && $alias === 'schweiz' && preg_match('/\bsaechsisch(?:e|en|er|es)?\s+schweiz\b/', $haystack)) {
                continue;
            }
            if (strpos($haystack, ' ' . $alias . ' ') !== false) {
                $explicit_land = $land;
                break 2;
            }
        }
    }

    $candidates = [];
    foreach (lsttraining_region_map() as $land => $bundeslaender) {
        if ($explicit_land !== '' && $land !== $explicit_land) {
            continue;
        }
        foreach ((array) $bundeslaender as $bundesland) {
            $needle = lsttraining_region_normalize_match_text((string) $bundesland);
            if ($needle !== '') {
                if ($explicit_land === '' && $land === 'Schweiz' && in_array($bundesland, ['Freiburg', 'Zug'], true)) {
                    $needle = 'kanton ' . $needle;
                }
                $candidates[] = ['land' => (string) $land, 'bundesland' => (string) $bundesland, 'needle' => $needle];
            }
        }
    }
    usort($candidates, static fn(array $a, array $b): int => strlen($b['needle']) <=> strlen($a['needle']));

    foreach ($candidates as $candidate) {
        if (strpos($haystack, ' ' . $candidate['needle'] . ' ') !== false) {
            return ['land' => $candidate['land'], 'bundesland' => $candidate['bundesland']];
        }
    }

    if ($explicit_land !== '') {
        return ['land' => $explicit_land, 'bundesland' => ''];
    }

    return ['land' => '', 'bundesland' => ''];
}

function lsttraining_region_country_for_bundesland(string $bundesland): string {
    foreach (lsttraining_region_map() as $land => $bundeslaender) {
        if (in_array($bundesland, (array) $bundeslaender, true)) {
            return (string) $land;
        }
    }
    return '';
}

function lsttraining_region_backfill_values(string $current_land, string $current_bundesland, string $name, string $zustandigkeit): array {
    $land = trim($current_land);
    $bundesland = trim($current_bundesland);
    $detected = lsttraining_region_detect($name, $zustandigkeit);

    if ($land === '' && $bundesland === '') {
        $land = (string) $detected['land'];
        $bundesland = (string) $detected['bundesland'];
    } elseif ($land === '' && $bundesland !== '') {
        $land = lsttraining_region_country_for_bundesland($bundesland);
    } elseif ($bundesland === '' && $land === $detected['land']) {
        $bundesland = (string) $detected['bundesland'];
    }

    return ['land' => $land, 'bundesland' => $bundesland];
}

function lsttraining_render_region_filter(string $selected_land, string $selected_bundesland): void {
    $regions = lsttraining_region_map();
    $bundeslaender = $selected_land !== '' ? (array) ($regions[$selected_land] ?? []) : [];
    $data_map = wp_json_encode($regions, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
    <div id="region-filter" class="filter-box filter-box--region<?php echo $selected_bundesland !== '' ? ' is-active' : ''; ?>">
      <div class="region-filter-heading">
        <h2>Region</h2>
        <span class="region-filter-badge">2 Schritte</span>
      </div>
      <p class="region-filter-hint">Das Land legt fest, welche Bundesländer im zweiten Schritt zur Auswahl stehen.</p>
      <div class="region-filter-steps">
        <p class="region-filter-step">
          <span class="region-filter-step-label">Schritt 1</span>
          <label for="land">Land auswählen</label>
          <select id="land" name="land">
            <option value="" <?php selected($selected_land, ''); ?>>Alle Länder</option>
            <?php foreach (array_keys($regions) as $land) : ?>
              <option value="<?php echo esc_attr($land); ?>" <?php selected($selected_land, $land); ?>><?php echo esc_html($land); ?></option>
            <?php endforeach; ?>
          </select>
        </p>
        <p class="region-filter-step">
          <span class="region-filter-step-label">Schritt 2</span>
          <label for="bundesland">Bundesland auswählen</label>
          <select id="bundesland" name="bundesland" data-map='<?php echo esc_attr((string) $data_map); ?>' aria-describedby="region-filter-status" <?php disabled($selected_land, ''); ?>>
            <option value="">— Bundesland wählen —</option>
            <?php if ($selected_land !== '') : ?>
              <option value="__none__" <?php selected($selected_bundesland, '__none__'); ?>>Ohne Bundesland</option>
              <?php foreach ($bundeslaender as $bundesland) : ?>
                <option value="<?php echo esc_attr($bundesland); ?>" <?php selected($selected_bundesland, $bundesland); ?>><?php echo esc_html($bundesland); ?></option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
          <small class="description">„Ohne Bundesland“ findet Einträge im gewählten Land mit NULL oder leerem Feld.</small>
        </p>
      </div>
      <p id="region-filter-status" class="region-filter-status" aria-live="polite">
        <?php if ($selected_land === '') : ?>
          Alle Länder werden angezeigt.
        <?php elseif ($selected_bundesland === '') : ?>
          Nächster Schritt: Bundesland für <?php echo esc_html($selected_land); ?> auswählen.
        <?php else : ?>
          Regionsfilter aktiv.
        <?php endif; ?>
      </p>
    </div>
    <?php
}
