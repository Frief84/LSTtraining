<?php
/**
 * AJAX proxy for administrative boundary search/import in the Einsatzgebiet editor.
 */

if (!defined('ABSPATH')) { exit; }

function lsttraining_boundary_user_can(string $context): bool {
    if (!is_user_logged_in()) {
        return false;
    }

    if ($context === 'neben') {
        return lsttraining_user_can('nebenstellen');
    }

    if (function_exists('lsttraining_user_can_view_leitstellen_admin') && lsttraining_user_can_view_leitstellen_admin()) {
        return true;
    }

    return lsttraining_user_can('leitstellen');
}

function lsttraining_boundary_sources(): array {
    return [
        'de' => [
            'label' => 'Deutschland',
            'attribution' => '© GeoBasis-DE / BKG, dl-de/by-2-0',
            'url' => 'https://sgx.geodatenzentrum.de/wfs_vg250',
            'levels' => [
                'gemeinde' => ['label' => 'Gemeinde', 'typename' => 'vg250_gem'],
                'kreis' => ['label' => 'Kreis', 'typename' => 'vg250_krs'],
                'bundesland' => ['label' => 'Bundesland', 'typename' => 'vg250_lan'],
            ],
        ],
        'at' => [
            'label' => 'Österreich',
            'attribution' => 'Statistik Austria, CC BY 4.0',
            'url' => 'https://www.statistik.at/gs-open/GEODATA/ows',
            'levels' => [
                'gemeinde' => ['label' => 'Gemeinde', 'typename' => 'GEODATA:STATISTIK_AUSTRIA_GEM_20260101'],
                'bezirk' => ['label' => 'Bezirk', 'typename' => 'GEODATA:STATISTIK_AUSTRIA_POLBEZ_20260101'],
                'bundesland' => ['label' => 'Bundesland', 'typename' => 'GEODATA:STATISTIK_AUSTRIA_BUNDESLAND_20260101'],
            ],
        ],
        'ch' => [
            'label' => 'Schweiz/Liechtenstein',
            'attribution' => 'swisstopo swissBOUNDARIES3D',
            'url' => '',
            'levels' => [
                'gemeinde' => ['label' => 'Gemeinde'],
                'bezirk' => ['label' => 'Bezirk'],
                'kanton' => ['label' => 'Kanton'],
            ],
        ],
    ];
}

function lsttraining_boundary_level_to_osm_admin_level(string $country, string $level): string {
    if ($country === 'de') {
        return $level === 'bundesland' ? '4' : ($level === 'kreis' ? '6' : '8');
    }
    if ($country === 'at') {
        return $level === 'bundesland' ? '4' : ($level === 'bezirk' ? '6' : '8');
    }
    if ($country === 'ch') {
        return $level === 'kanton' ? '4' : '8';
    }
    return '8';
}

function lsttraining_boundary_clean_query(string $value): string {
    $value = trim(wp_strip_all_tags($value));
    return function_exists('mb_substr') ? mb_substr($value, 0, 80) : substr($value, 0, 80);
}

function lsttraining_boundary_cache_key(string $suffix, array $parts): string {
    return 'lst_boundary_v8_' . $suffix . '_' . md5(wp_json_encode($parts));
}

function lsttraining_boundary_remote_json(string $url, array $args = []): ?array {
    $response = wp_remote_get($url, [
        'timeout' => 18,
        'redirection' => 3,
        'user-agent' => 'LSTtraining boundary assistant; ' . home_url('/'),
        'headers' => [
            'Accept' => 'application/json, application/geo+json;q=0.9, */*;q=0.5',
        ],
    ] + $args);

    if (is_wp_error($response)) {
        return null;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    if (!is_string($body) || trim($body) === '') {
        return null;
    }

    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

function lsttraining_boundary_feature_name(array $properties): string {
    foreach (['NAME', 'name', 'Name', 'GEN', 'gen', 'gemname', 'GEMNAME', 'g_name', 'G_NAME', 'BEZ_NAME', 'label'] as $key) {
        if (isset($properties[$key]) && trim((string)$properties[$key]) !== '') {
            return (string)$properties[$key];
        }
    }
    return 'Unbenannte Grenze';
}

function lsttraining_boundary_feature_id(array $properties, int $fallback): string {
    foreach (['ID', 'id', 'RS', 'rs', 'ARS', 'ars', 'AGS', 'ags', 'g_id', 'G_ID', 'GKZ', 'gkz', 'gde_nr', 'BFS_NUMMER'] as $key) {
        if (isset($properties[$key]) && trim((string)$properties[$key]) !== '') {
            return (string)$properties[$key];
        }
    }
    return 'feature-' . $fallback;
}

function lsttraining_boundary_feature_subtitle(array $properties): string {
    $parts = [];
    foreach (['ID', 'BEZ', 'bez', 'NUTS', 'nuts', 'RS', 'ARS', 'AGS', 'GKZ', 'g_id', 'kanton'] as $key) {
        if (isset($properties[$key]) && trim((string)$properties[$key]) !== '') {
            $parts[] = (string)$properties[$key];
        }
        if (count($parts) >= 3) {
            break;
        }
    }
    return implode(' · ', array_unique($parts));
}

function lsttraining_boundary_wfs_url(string $base, string $typename, array $extra): string {
    $is_statistik_at = strpos($typename, 'GEODATA:STATISTIK_AUSTRIA_') === 0;
    if ($is_statistik_at && isset($extra['COUNT'])) {
        $extra['maxFeatures'] = $extra['COUNT'];
        unset($extra['COUNT']);
    }

    $base_args = [
        'SERVICE' => 'WFS',
        'VERSION' => $is_statistik_at ? '1.0.0' : '2.0.0',
        'REQUEST' => 'GetFeature',
        ($is_statistik_at ? 'typeName' : 'TYPENAMES') => $typename,
        ($is_statistik_at ? 'srsName' : 'SRSNAME') => 'EPSG:4326',
        'OUTPUTFORMAT' => $is_statistik_at ? 'json' : 'application/json',
    ];

    return add_query_arg(array_merge($base_args, $extra), $base);
}

function lsttraining_boundary_geo_admin_layers(string $level): ?array {
    if ($level === 'gemeinde') {
        return [
            'layer' => 'ch.swisstopo.swissboundaries3d-gemeinde-flaeche.fill',
            'fields' => ['gemname', 'gde_nr', 'id'],
            'label_field' => 'gemname',
            'id_field' => 'gde_nr',
        ];
    }
    if ($level === 'bezirk') {
        return [
            'layer' => 'ch.swisstopo.swissboundaries3d-bezirk-flaeche.fill',
            'fields' => ['name', 'id'],
            'label_field' => 'name',
            'id_field' => 'id',
        ];
    }
    if ($level === 'kanton') {
        return [
            'layer' => 'ch.swisstopo.swissboundaries3d-kanton-flaeche.fill',
            'fields' => ['name', 'id', 'ak'],
            'label_field' => 'name',
            'id_field' => 'id',
        ];
    }
    return null;
}

function lsttraining_boundary_geo_admin_feature_from_result(array $result): ?array {
    $geometry = $result['geometry'] ?? null;
    if (empty($geometry) || !in_array($geometry['type'] ?? '', ['Polygon', 'MultiPolygon'], true)) {
        return null;
    }

    $properties = [];
    if (isset($result['properties']) && is_array($result['properties'])) {
        $properties = $result['properties'];
    } elseif (isset($result['attributes']) && is_array($result['attributes'])) {
        $properties = $result['attributes'];
    } elseif (isset($result['attrs']) && is_array($result['attrs'])) {
        $properties = $result['attrs'];
    }

    $feature_id = (string)($result['featureId'] ?? $result['id'] ?? lsttraining_boundary_feature_id($properties, 0));
    return [
        'type' => 'Feature',
        'id' => $feature_id,
        'properties' => $properties,
        'geometry' => $geometry,
    ];
}

function lsttraining_boundary_search_geo_admin(string $level, string $query): array {
    $cfg = lsttraining_boundary_geo_admin_layers($level);
    if (!$cfg) {
        return [];
    }

    $items = [];
    $seen = [];
    foreach (lsttraining_boundary_query_variants($query) as $variant) {
        foreach ($cfg['fields'] as $field) {
            $url = add_query_arg([
                'layer' => $cfg['layer'],
                'searchText' => $variant,
                'searchField' => $field,
                'contains' => 'true',
                'returnGeometry' => 'true',
                'geometryFormat' => 'geojson',
                'sr' => '4326',
                'lang' => 'de',
            ], 'https://api3.geo.admin.ch/rest/services/api/MapServer/find');

            $json = lsttraining_boundary_remote_json($url);
            if (empty($json['results']) || !is_array($json['results'])) {
                continue;
            }

            foreach ($json['results'] as $result) {
                if (!is_array($result)) {
                    continue;
                }
                $feature = lsttraining_boundary_geo_admin_feature_from_result($result);
                if (!$feature) {
                    continue;
                }

                $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
                if (array_key_exists('is_current_jahr', $properties) && !$properties['is_current_jahr']) {
                    continue;
                }

                $dedupe = $cfg['layer'] . ':' . (string)($feature['id'] ?? lsttraining_boundary_feature_id($properties, 0));
                if (isset($seen[$dedupe])) {
                    continue;
                }

                $name = (string)($properties[$cfg['label_field']] ?? $properties['label'] ?? lsttraining_boundary_feature_name($properties));
                $subtitle_parts = [];
                if (isset($properties[$cfg['id_field']])) {
                    $subtitle_parts[] = (string)$properties[$cfg['id_field']];
                }
                if (isset($properties['ak'])) {
                    $subtitle_parts[] = (string)$properties['ak'];
                }

                $items[] = [
                    'id' => lsttraining_boundary_store_inline_geojson($feature, 'swisstopo swissBOUNDARIES3D'),
                    'name' => $name,
                    'subtitle' => implode(' · ', array_unique(array_filter($subtitle_parts))),
                    'country' => 'ch',
                    'level' => $level,
                    'source' => 'official',
                    'attribution' => 'swisstopo swissBOUNDARIES3D',
                ];
                $seen[$dedupe] = true;
                if (count($items) >= 30) {
                    return $items;
                }
            }
        }
    }

    return $items;
}

function lsttraining_boundary_search_official(string $country, string $level, string $query): array {
    $sources = lsttraining_boundary_sources();
    if ($country === 'ch') {
        return lsttraining_boundary_search_geo_admin($level, $query);
    }

    if (empty($sources[$country]['levels'][$level]['typename']) || empty($sources[$country]['url'])) {
        return [];
    }

    $source = $sources[$country];
    $typename = $source['levels'][$level]['typename'];
    $safe = str_replace("'", "''", $query);
    $is_key = preg_match('/^[0-9A-Za-z.\-_]+$/', $query) === 1;

    if ($country === 'de') {
        $filters = ["gen ILIKE '%{$safe}%'", "bez ILIKE '%{$safe}%'", "GEN ILIKE '%{$safe}%'", "BEZ ILIKE '%{$safe}%'"];
        if ($is_key) {
            $filters[] = "rs LIKE '{$safe}%'";
            $filters[] = "ars LIKE '{$safe}%'";
            $filters[] = "ags LIKE '{$safe}%'";
            $filters[] = "RS LIKE '{$safe}%'";
            $filters[] = "ARS LIKE '{$safe}%'";
            $filters[] = "AGS LIKE '{$safe}%'";
        }
        $cql = implode(' OR ', $filters);
    } elseif ($country === 'at') {
        $filters = ["g_name LIKE '%{$safe}%'"];
        if ($is_key) {
            $filters[] = "g_id LIKE '{$safe}%'";
        }
        $cql = implode(' OR ', $filters);
    } else {
        return [];
    }

    $url = lsttraining_boundary_wfs_url($source['url'], $typename, [
        'COUNT' => 30,
        'CQL_FILTER' => $cql,
    ]);

    $json = lsttraining_boundary_remote_json($url);
    if (empty($json['features']) || !is_array($json['features'])) {
        return [];
    }

    $items = [];
    foreach ($json['features'] as $index => $feature) {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $id = lsttraining_boundary_feature_id($properties, (int)$index);
        $items[] = [
            'id' => $country . '|' . $level . '|' . rawurlencode($id),
            'name' => lsttraining_boundary_feature_name($properties),
            'subtitle' => lsttraining_boundary_feature_subtitle($properties),
            'country' => $country,
            'level' => $level,
            'source' => 'official',
            'attribution' => $source['attribution'],
        ];
    }

    return $items;
}

function lsttraining_boundary_osm_country_codes(string $country): string {
    if ($country === 'de') { return 'de'; }
    if ($country === 'at') { return 'at'; }
    if ($country === 'ch') { return 'ch,li'; }
    return '';
}

function lsttraining_boundary_query_variants(string $query): array {
    $query = trim($query);
    $variants = [$query];

    $lower = function_exists('mb_strtolower') ? mb_strtolower($query) : strtolower($query);
    $known = [
        'innbruck' => 'Innsbruck',
        'insbruck' => 'Innsbruck',
        'inssbruck' => 'Innsbruck',
        'zurich' => 'Zürich',
        'zuerich' => 'Zürich',
    ];
    if (isset($known[$lower])) {
        $variants[] = $known[$lower];
    }

    // Typische Auslassung eines Doppelkonsonanten: Innbruck -> Innsbruck ist oben
    // explizit enthalten; diese generische Variante hilft bei kleineren Tippfehlern.
    if (strlen($query) >= 5) {
        $variants[] = preg_replace('/([bcdfghjklmnpqrstvwxyz])\1/i', '$1', $query);
    }

    return array_values(array_unique(array_filter($variants)));
}

function lsttraining_boundary_store_inline_geojson(array $feature, string $attribution = '© OpenStreetMap contributors (ODbL)'): string {
    $token = wp_generate_password(20, false, false);
    set_transient('lst_boundary_inline_' . $token, [
        'feature' => $feature,
        'attribution' => $attribution,
    ], 30 * MINUTE_IN_SECONDS);
    return 'inline|' . $token;
}

function lsttraining_boundary_search_osm(string $country, string $level, string $query): array {
    $admin_level = lsttraining_boundary_level_to_osm_admin_level($country, $level);
    $country_codes = lsttraining_boundary_osm_country_codes($country);
    $country_label = $country === 'at' ? 'Österreich' : ($country === 'de' ? 'Deutschland' : 'Schweiz');
    $queries = [];
    foreach (lsttraining_boundary_query_variants($query) as $variant) {
        $queries[] = $variant;
        $queries[] = $variant . ', ' . $country_label;
    }
    $queries = array_values(array_unique($queries));
    $items = [];
    $seen = [];

    foreach ($queries as $search_query) {
        $url = add_query_arg([
            'format' => 'geojson',
            'limit' => 15,
            'addressdetails' => 1,
            'extratags' => 1,
            'polygon_geojson' => 1,
            'polygon_threshold' => 0.0002,
            'countrycodes' => $country_codes,
            'q' => $search_query,
        ], 'https://nominatim.openstreetmap.org/search');

        $json = lsttraining_boundary_remote_json($url);
        if (empty($json['features']) || !is_array($json['features'])) {
            continue;
        }

        foreach ($json['features'] as $feature) {
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $extra = is_array($properties['extratags'] ?? null) ? $properties['extratags'] : [];
            $address = is_array($properties['address'] ?? null) ? $properties['address'] : [];
            $osm_type = (string)($properties['osm_type'] ?? '');
            $osm_id = (string)($properties['osm_id'] ?? '');
            if ($osm_id === '' || $osm_type === '') {
                continue;
            }
            if (empty($feature['geometry']) || !in_array($feature['geometry']['type'] ?? '', ['Polygon', 'MultiPolygon'], true)) {
                continue;
            }
            $dedupe_key = strtolower($osm_type) . ':' . $osm_id;
            if (isset($seen[$dedupe_key])) {
                continue;
            }
            $is_admin_boundary = (($extra['boundary'] ?? '') === 'administrative')
                || (($properties['class'] ?? '') === 'boundary' && ($properties['type'] ?? '') === 'administrative');
            $is_place = ($properties['class'] ?? '') === 'place'
                || in_array(($properties['type'] ?? ''), ['city', 'town', 'village', 'municipality', 'administrative'], true);
            if (!$is_admin_boundary && !$is_place) {
                continue;
            }
            if ($is_admin_boundary && isset($extra['admin_level']) && (string)$extra['admin_level'] !== $admin_level) {
                continue;
            }

            $name = (string)($properties['name'] ?? $properties['display_name'] ?? 'OSM Grenze');
            $subtitle = (string)($properties['display_name'] ?? '');
            if (!empty($address['country_code'])) {
                $subtitle = strtoupper((string)$address['country_code']) . ($subtitle ? ' · ' . $subtitle : '');
            }

            $items[] = [
                'id' => lsttraining_boundary_store_inline_geojson($feature, '© OpenStreetMap contributors (ODbL)'),
                'name' => $name,
                'subtitle' => $subtitle,
                'country' => $country,
                'level' => $level,
                'source' => 'osm',
                'attribution' => '© OpenStreetMap contributors (ODbL)',
            ];
            $seen[$dedupe_key] = true;
            if (count($items) >= 15) {
                break 2;
            }
        }
    }

    return $items;
}

function lsttraining_boundary_fetch_inline(array $ids): array {
    $features = [];
    $attributions = [];
    foreach ($ids as $raw_id) {
        $parts = explode('|', (string)$raw_id, 2);
        if (count($parts) !== 2 || $parts[0] !== 'inline') {
            continue;
        }
        $token = preg_replace('/[^A-Za-z0-9]/', '', $parts[1]);
        if ($token === '') {
            continue;
        }
        $payload = get_transient('lst_boundary_inline_' . $token);
        if (is_array($payload) && isset($payload['feature']) && is_array($payload['feature'])) {
            $feature = $payload['feature'];
            if (!empty($feature['geometry'])) {
                $features[] = $feature;
                if (!empty($payload['attribution'])) {
                    $attributions[] = (string)$payload['attribution'];
                }
            }
        } elseif (is_array($payload) && !empty($payload['geometry'])) {
            $features[] = $payload;
        }
    }

    return [$features, array_values(array_unique($attributions))];
}

function lsttraining_boundary_fetch_official(array $ids): array {
    $sources = lsttraining_boundary_sources();
    $groups = [];

    foreach ($ids as $raw_id) {
        $parts = explode('|', (string)$raw_id, 3);
        if (count($parts) !== 3 || !isset($sources[$parts[0]]['levels'][$parts[1]])) {
            continue;
        }
        [$country, $level, $encoded] = $parts;
        if ($country === 'osm') {
            continue;
        }
        $groups[$country . '|' . $level][] = rawurldecode($encoded);
    }

    $features = [];
    $attributions = [];
    foreach ($groups as $group => $group_ids) {
        [$country, $level] = explode('|', $group, 2);
        if (empty($sources[$country]['levels'][$level]['typename']) || empty($sources[$country]['url'])) {
            continue;
        }

        $source = $sources[$country];
        $typename = $source['levels'][$level]['typename'];
        $clauses = [];
        foreach (array_slice(array_unique($group_ids), 0, 40) as $id) {
            $safe = str_replace("'", "''", $id);
            if ($country === 'de') {
                $clauses[] = "rs='{$safe}' OR ars='{$safe}' OR ags='{$safe}' OR RS='{$safe}' OR ARS='{$safe}' OR AGS='{$safe}'";
            } elseif ($country === 'at') {
                $clauses[] = "g_id='{$safe}'";
            }
        }
        if (!$clauses) {
            continue;
        }

        $url = lsttraining_boundary_wfs_url($source['url'], $typename, [
            'COUNT' => 80,
            'CQL_FILTER' => '(' . implode(') OR (', $clauses) . ')',
        ]);
        $json = lsttraining_boundary_remote_json($url);
        if (!empty($json['features']) && is_array($json['features'])) {
            foreach ($json['features'] as $feature) {
                if (!empty($feature['geometry'])) {
                    $features[] = $feature;
                }
            }
            $attributions[] = $source['attribution'];
        }
    }

    return [$features, $attributions];
}

function lsttraining_boundary_fetch_osm(array $ids): array {
    $osm_ids = [];
    foreach ($ids as $raw_id) {
        $parts = explode('|', (string)$raw_id, 3);
        if (count($parts) !== 3 || $parts[0] !== 'osm') {
            continue;
        }
        $type = strtolower($parts[1]);
        $id = preg_replace('/[^0-9]/', '', rawurldecode($parts[2]));
        if ($id === '') {
            continue;
        }
        $prefix = $type === 'node' ? 'N' : ($type === 'way' ? 'W' : 'R');
        $osm_ids[] = $prefix . $id;
    }
    if (!$osm_ids) {
        return [[], []];
    }

    $url = add_query_arg([
        'format' => 'geojson',
        'polygon_geojson' => 1,
        'osm_ids' => implode(',', array_slice(array_unique($osm_ids), 0, 40)),
    ], 'https://nominatim.openstreetmap.org/lookup');

    $json = lsttraining_boundary_remote_json($url);
    $features = [];
    if (!empty($json['features']) && is_array($json['features'])) {
        foreach ($json['features'] as $feature) {
            if (!empty($feature['geometry']) && in_array($feature['geometry']['type'] ?? '', ['Polygon', 'MultiPolygon'], true)) {
                $features[] = $feature;
            }
        }
    }

    return [$features, $features ? ['© OpenStreetMap contributors (ODbL)'] : []];
}

add_action('wp_ajax_lsttraining_boundary_search', function () {
    $context = sanitize_key($_GET['context'] ?? 'leitstelle');
    if (!lsttraining_boundary_user_can($context)) {
        wp_send_json_error(['message' => 'Nicht berechtigt.'], 403);
    }

    $country = sanitize_key($_GET['country'] ?? 'de');
    $level = sanitize_key($_GET['level'] ?? 'gemeinde');
    $query = lsttraining_boundary_clean_query(wp_unslash((string)($_GET['q'] ?? '')));
    $sources = lsttraining_boundary_sources();

    $query_length = function_exists('mb_strlen') ? mb_strlen($query) : strlen($query);
    if ($query_length < 2 || empty($sources[$country]['levels'][$level])) {
        wp_send_json_error(['message' => 'Bitte Land, Ebene und mindestens zwei Suchzeichen angeben.'], 400);
    }

    $cache_key = lsttraining_boundary_cache_key('search', [$country, $level, $query]);
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        wp_send_json_success($cached);
    }

    $items = lsttraining_boundary_search_official($country, $level, $query);
    $source = $items ? 'official' : 'osm';
    if (!$items) {
        $items = lsttraining_boundary_search_osm($country, $level, $query);
    }

    $payload = [
        'items' => $items,
        'source' => $source,
        'fallback' => $source === 'osm',
        'attribution' => $source === 'osm' ? '© OpenStreetMap contributors (ODbL)' : $sources[$country]['attribution'],
    ];
    set_transient($cache_key, $payload, 30 * MINUTE_IN_SECONDS);

    wp_send_json_success($payload);
});

add_action('wp_ajax_lsttraining_boundary_fetch', function () {
    $context = sanitize_key($_POST['context'] ?? 'leitstelle');
    if (!lsttraining_boundary_user_can($context)) {
        wp_send_json_error(['message' => 'Nicht berechtigt.'], 403);
    }

    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids = array_values(array_filter(array_map('sanitize_text_field', wp_unslash($ids))));
    if (!$ids) {
        wp_send_json_error(['message' => 'Keine Grenzen ausgewählt.'], 400);
    }

    $cache_key = lsttraining_boundary_cache_key('fetch', $ids);
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        wp_send_json_success($cached);
    }

    [$inline_features, $inline_attr] = lsttraining_boundary_fetch_inline($ids);
    [$official_features, $official_attr] = lsttraining_boundary_fetch_official($ids);
    [$osm_features, $osm_attr] = lsttraining_boundary_fetch_osm($ids);
    $features = array_merge($inline_features, $official_features, $osm_features);

    if (!$features) {
        wp_send_json_error(['message' => 'Für die Auswahl konnte keine Polygon-Geometrie geladen werden.'], 502);
    }

    $payload = [
        'geojson' => [
            'type' => 'FeatureCollection',
            'features' => $features,
        ],
        'attribution' => implode(' · ', array_unique(array_merge($inline_attr, $official_attr, $osm_attr))),
    ];
    set_transient($cache_key, $payload, 6 * HOUR_IN_SECONDS);

    wp_send_json_success($payload);
});
