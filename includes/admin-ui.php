<?php
/**
 * admin-ui.php
 *
 * Page-Render-Callbacks and Admin UI Dispatcher for the LST Training plugin.
 */
if (!defined('ABSPATH')) { exit; }

if (!function_exists('lsttraining_enqueue_nebenstellen_assets')) {
    function lsttraining_enqueue_nebenstellen_assets(): void {
        if (wp_script_is('lst-nebenstellen-editor', 'enqueued')) {
            return;
        }

        $plugin_file = dirname(__DIR__) . '/lsttraining-plugin.php';
        $root_url    = plugin_dir_url($plugin_file);
        $root_path   = plugin_dir_path($plugin_file);
        $version     = static function (string $relative) use ($root_path): string {
            $path = $root_path . ltrim($relative, '/\\');
            return is_readable($path) ? (string) filemtime($path) : '1.0.0';
        };

        wp_enqueue_style('lst-openlayers-css', $root_url . 'openlayers/ol.css', [], null);
        wp_enqueue_script('lst-openlayers', $root_url . 'openlayers/ol.js', [], null, true);

        wp_enqueue_script(
            'lsttraining-einsatzgebiet-editor',
            $root_url . 'js/einsatzgebiet-editor.js',
            ['jquery', 'lst-openlayers'],
            $version('js/einsatzgebiet-editor.js'),
            true
        );

        wp_enqueue_script(
            'lsttraining-turf',
            $root_url . 'js/turf.min.js',
            [],
            $version('js/turf.min.js'),
            true
        );

        wp_enqueue_script(
            'lst-nebenstellen-editor',
            $root_url . 'js/nebenstellen_editor.js',
            ['jquery', 'lst-openlayers', 'lsttraining-einsatzgebiet-editor', 'lsttraining-turf'],
            $version('js/nebenstellen_editor.js'),
            true
        );

        wp_enqueue_script(
            'lsttraining-einsatzgebiet-upload',
            $root_url . 'js/einsatzgebiet_upload.js',
            ['lsttraining-turf', 'lst-openlayers', 'lsttraining-einsatzgebiet-editor', 'lst-nebenstellen-editor'],
            $version('js/einsatzgebiet_upload.js'),
            true
        );

        wp_enqueue_script(
            'lst-zuordnung-inline',
            $root_url . 'js/zuordnung_modal.js',
            ['lst-openlayers', 'lst-nebenstellen-editor'],
            $version('js/zuordnung_modal.js'),
            true
        );

        $all_leitstellen = [];
        try {
            $pdo = lsttraining_get_connection();
            if ($pdo instanceof PDO) {
                $stmt = $pdo->query('SELECT id, name FROM leitstellen ORDER BY name');
                if ($stmt) {
                    $all_leitstellen = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        } catch (Throwable $e) {
            $all_leitstellen = [];
        }

        wp_localize_script('lst-nebenstellen-editor', 'LSTTRAINING', [
            'ajax_url'           => admin_url('admin-ajax.php'),
            'nonce_nebenstellen' => wp_create_nonce('lst_nebenstellen_nonce'),
        ]);

        wp_localize_script('lst-nebenstellen-editor', 'lstNebenstellenAjax', [
            'ajax_url'       => admin_url('admin-ajax.php'),
            'nonce_copy'     => wp_create_nonce('lsttraining_copy_leitstelle'),
            'nonce_delete'   => wp_create_nonce('lsttraining_delete_nebenstelle'),
            'allLeitstellen' => $all_leitstellen,
        ]);

        wp_localize_script('lst-zuordnung-inline', 'lstZuordnungAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('lst_zuordnung'),
        ]);
    }
}

add_action('admin_enqueue_scripts', function ($hook) {

    // admin-ui.php liegt in /includes/
    // Plugin-Root-Datei liegt eine Ebene höher:
    $plugin_file = dirname(__DIR__) . '/lsttraining-plugin.php';

    // Robust: Root-URL/Path immer aus Plugin-Root-Datei ableiten
    $root_url  = plugin_dir_url($plugin_file);   // .../wp-content/plugins/lsttraining-plugin/
    $root_path = plugin_dir_path($plugin_file);  // .../wp-content/plugins/lsttraining-plugin/
    $asset_version = static function (string $relative) use ($root_path): string {
        $path = $root_path . ltrim($relative, '/\\');
        return is_readable($path) ? (string) filemtime($path) : '1.0.0';
    };

    // Gate robuster machen: entweder Hook oder ?page enthält lsttraining
    $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    $is_lst_page = (strpos((string)$hook, 'lsttraining') !== false) || (strpos($page, 'lsttraining') !== false);

    if (!$is_lst_page) {
        return;
    }

    // Common (alle LSTtraining-Seiten)
    wp_enqueue_style('dashicons');

    // OpenLayers (aus Plugin-Ordner)
    wp_enqueue_style('lst-openlayers-css', $root_url . 'openlayers/ol.css', [], null);
    wp_enqueue_script('lst-openlayers', $root_url . 'openlayers/ol.js', [], null, true);

    // Admin UI
    wp_enqueue_style('lst-admin-css', $root_url . 'css/admin-ui.css', [], $asset_version('css/admin-ui.css'));
    wp_enqueue_script('lst-admin-ui', $root_url . 'js/admin-ui.js', ['jquery', 'lst-openlayers'], $asset_version('js/admin-ui.js'), true);

    // ───────────────────────────────────────────
    // Leitstellen (Top-Level)
    // ───────────────────────────────────────────
    if ($page === 'lsttraining_leitstellen' || $hook === 'toplevel_page_lsttraining_leitstellen') {
        wp_enqueue_media();

        $neighbor_leitstellen = [];
        try {
            $pdo = lsttraining_get_connection();
            if ($pdo instanceof PDO) {
                $stmt = $pdo->query('SELECT id, name, gps, geojson FROM nebenleitstellen ORDER BY name');
                if ($stmt) {
                    $neighbor_leitstellen = array_map(
                        static function (array $row): array {
                            return [
                                'id'      => (int)($row['id'] ?? 0),
                                'name'    => (string)($row['name'] ?? ''),
                                'gps'     => (string)($row['gps'] ?? ''),
                                'geojson' => (string)($row['geojson'] ?? ''),
                            ];
                        },
                        $stmt->fetchAll(PDO::FETCH_ASSOC)
                    );
                }
            }
        } catch (Throwable $e) {
            $neighbor_leitstellen = [];
        }

        wp_add_inline_script(
            'lst-admin-ui',
            'window.lstNeighborLeitstellenData = ' . wp_json_encode($neighbor_leitstellen, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';',
            'before'
        );

        wp_enqueue_script(
            'lst-leitstellen-editor',
            $root_url . 'js/leitstellen_editor.js',
            ['jquery', 'wp-util', 'lst-openlayers', 'lst-admin-ui'],
            $asset_version('js/leitstellen_editor.js'),
            true
        );

        wp_enqueue_script(
            'lst-leitstellen-pois',
            $root_url . 'js/pois.js',
            ['jquery', 'wp-util', 'lst-openlayers', 'lst-leitstellen-editor'],
            '1.0.0',
            true
        );

        // Einsatzgebiet-Editor (Popup) – benötigt für „Einsatzgebiet bearbeiten“
        wp_enqueue_script(
            'lsttraining-einsatzgebiet-editor',
            $root_url . 'js/einsatzgebiet-editor.js',
            ['jquery', 'lst-openlayers'],
            '1.0.0',
            true
        );

        // Turf (für simplify + union)
        wp_enqueue_script(
            'lsttraining-turf',
            $root_url . 'js/turf.min.js',
            [],
            '6.5.0',
            true
        );

        // Turf-Upload-Logik
        wp_enqueue_script(
            'lsttraining-einsatzgebiet-upload',
            $root_url . 'js/einsatzgebiet_upload.js',
            ['lsttraining-turf', 'lst-openlayers', 'lsttraining-einsatzgebiet-editor'],
            '1.0.0',
            true
        );

        wp_localize_script('lst-leitstellen-editor', 'lstLeitstellenAjax', [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('lsttraining_leitstellen'),
            // falls du OSM-AJAX nutzt:
            'osm_nonce' => wp_create_nonce('lsttraining_osm_layers'),
            'signal_sprite_urls' => [
                'beacon' => $root_url . 'img/signal/beacon.svg',
                'strobe' => $root_url . 'img/signal/strobe.svg',
                'bar' => $root_url . 'img/signal/lightbar.svg',
                'glow' => $root_url . 'img/signal/glow.svg',
                'editor_point' => $root_url . 'img/signal/editor-point.svg',
            ],
        ]);

        wp_enqueue_script(
            'lst-zuordnung-inline',
            $root_url . 'js/zuordnung_modal.js',
            ['lst-openlayers'],
            '1.0.0',
            true
        );

        wp_localize_script('lst-zuordnung-inline', 'lstZuordnungAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('lst_zuordnung'),
        ]);
    }

    // ───────────────────────────────────────────
    // Nebenstellen
    // ───────────────────────────────────────────
    if ($page === 'lsttraining_nebenstellen' || strpos((string)$hook, 'lsttraining_nebenstellen') !== false) {
        lsttraining_enqueue_nebenstellen_assets();
    }

    // ───────────────────────────────────────────
    // Krankenhäuser
    // ───────────────────────────────────────────
    if (strpos($page, 'lsttraining_krankenhaeuser') !== false || strpos((string)$hook, 'lsttraining_krankenhaeuser') !== false) {

        wp_enqueue_script(
            'lst-departments',
            $root_url . 'js/departments.js',
            ['jquery', 'underscore', 'wp-util', 'lst-openlayers'],
            '1.0.0',
            true
        );

        wp_enqueue_script(
            'lst-hospitals',
            $root_url . 'js/hospitals.js',
            ['jquery', 'lst-openlayers', 'lst-departments'],
            '1.0.0',
            true
        );

        $departments = [];
        $json_path = $root_path . 'data/departments.json';
        if (is_readable($json_path)) {
            $tmp = json_decode(file_get_contents($json_path), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                $departments = $tmp;
            }
        }

        wp_localize_script('lst-hospitals', 'lstHospitalsAjax', [
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('lsttraining_hospitals'),
            'plugin_url'  => $root_url,
            'departments' => $departments,
        ]);
    }

    // ───────────────────────────────────────────
    // Wachen (Subpage unter Leitstellen)
    // ───────────────────────────────────────────
    if ($page === 'lsttraining_leitstellen_wachen' || strpos((string)$hook, 'leitstellen_wachen') !== false) {

        wp_enqueue_style(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            [],
            '4.1.0-rc.0'
        );

        wp_enqueue_script(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            ['jquery'],
            '4.1.0-rc.0',
            true
        );

        wp_enqueue_script(
            'lst-wachen',
            $root_url . 'js/wachen.js',
            ['jquery', 'lst-openlayers', 'select2'],
            $asset_version('js/wachen.js'),
            true
        );

        wp_localize_script('lst-wachen', 'lstWachenAjax', [
            'ajax_url'        => admin_url('admin-ajax.php'),
            'admin_url'       => admin_url('admin.php'),
            'nonce'           => wp_create_nonce('lsttraining_wachen'),
            'fahrzeuge_nonce' => wp_create_nonce('lst_fahrzeuge_nonce'),
        ]);
    }

    // ───────────────────────────────────────────
    // Fahrzeuge (eigene Seite)
    // ───────────────────────────────────────────
    if ($page === 'lsttraining_fahrzeuge' || strpos((string)$hook, 'lsttraining_fahrzeuge') !== false || strpos((string)$hook, 'leitstellen_fahrzeuge') !== false) {

        wp_enqueue_style(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            [],
            '4.1.0-rc.0'
        );

        wp_enqueue_script(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            ['jquery'],
            '4.1.0-rc.0',
            true
        );

        wp_enqueue_script(
            'lst-fahrzeuge',
            $root_url . 'js/fahrzeuge.js',
            ['jquery', 'select2'],
            '1.9.1',
            true
        );

        $bundeslaender = [];
        $json_path = $root_path . 'data/bundeslaender.json';
        if (is_readable($json_path)) {
            $tmp = json_decode(file_get_contents($json_path), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                $bundeslaender = $tmp;
            }
        }

        $fahrzeugtypen = [];
        $ft_path = $root_path . 'data/fahrzeugtypen.json';
        if (is_readable($ft_path)) {
            $tmp = json_decode(file_get_contents($ft_path), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                $fahrzeugtypen = array_values(array_filter(array_map('strval', $tmp)));
            }
        }
        if (empty($fahrzeugtypen)) {
            $fahrzeugtypen = ['RTW','NEF','KTW','HLF 20','LF 20','DLK 23/12','GW-San','ELW 1','MTW'];
        }

        wp_localize_script('lst-fahrzeuge', 'lstFahrzeugeAjax', [
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('lst_fahrzeuge_nonce'),
            'bundeslaender' => $bundeslaender,
            'fahrzeugtypen' => $fahrzeugtypen,
            'signal_sprite_urls' => [
                'beacon' => $root_url . 'img/signal/beacon.svg',
                'strobe' => $root_url . 'img/signal/strobe.svg',
                'bar' => $root_url . 'img/signal/lightbar.svg',
                'glow' => $root_url . 'img/signal/glow.svg',
                'editor_point' => $root_url . 'img/signal/editor-point.svg',
            ],
        ]);
    }
	
	if ($page === 'lsttraining_einsaetze' || strpos((string)$hook, 'lsttraining_einsaetze') !== false) {
        $einsatz_fahrzeugtypen = [];
        $einsatz_departments = [];
        $einsatz_ft_path = $root_path . 'data/fahrzeugtypen.json';
        if (is_readable($einsatz_ft_path)) {
            $tmp = json_decode(file_get_contents($einsatz_ft_path), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                $einsatz_fahrzeugtypen = array_values(array_filter(array_map('strval', $tmp)));
            }
        }
        if (empty($einsatz_fahrzeugtypen)) {
            $einsatz_fahrzeugtypen = ['RTW','NEF','KTW','HLF 20','LF 20','DLK 23/12','GW-San','ELW 1','MTW'];
        }
        $einsatz_departments_path = $root_path . 'data/departments.json';
        if (is_readable($einsatz_departments_path)) {
            $tmp = json_decode(file_get_contents($einsatz_departments_path), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                $einsatz_departments = $tmp;
            }
        }

		wp_enqueue_script(
			'lst-einsaetze',
			$root_url . 'js/einsaetze.js',
			['jquery', 'underscore', 'wp-util', 'lst-openlayers', 'lst-admin-ui'],
			$asset_version('js/einsaetze.js'),
			true
		);

		wp_localize_script('lst-einsaetze', 'lstEinsaetzeAjax', [
			'ajax_url'      => admin_url('admin-ajax.php'),
			'nonce'         => wp_create_nonce('lsttraining_leitstellen'),
            'fahrzeugtypen' => $einsatz_fahrzeugtypen,
            'departments' => $einsatz_departments,
		]);
	}	
		if ($page === 'lsttraining_anruferprofile' || strpos((string)$hook, 'lsttraining_anruferprofile') !== false) {
		wp_enqueue_script(
			'lst-anruferprofile',
			$root_url . 'js/anruferprofile.js',
			['jquery', 'underscore', 'wp-util', 'lst-admin-ui'],
			'1.0.1',
			true
		);

		wp_localize_script('lst-anruferprofile', 'lstAnruferprofileAjax', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('lsttraining_leitstellen'),
		]);
	}
});

/* ---------------- Render-Callbacks (Templates liegen in /includes) ---------------- */

if (!function_exists('lsttraining_render_anruferprofile')) {
    function lsttraining_render_anruferprofile() {
        require_once plugin_dir_path(__FILE__) . 'anruferprofile.php';
    }
}
if (!function_exists('lsttraining_render_leitstellen')) {
    function lsttraining_render_leitstellen() {
        require_once plugin_dir_path(__FILE__) . 'leitstellen_editor.php';
    }
}

if (!function_exists('lsttraining_render_nebenstellen')) {
    function lsttraining_render_nebenstellen() {
        lsttraining_enqueue_nebenstellen_assets();
        require_once plugin_dir_path(__FILE__) . 'nebenstellen_editor.php';
    }
}

if (!function_exists('lsttraining_render_einsaetze')) {
        function lsttraining_render_einsaetze() {
            require_once plugin_dir_path(__FILE__) . 'einsaetze.php';
        }
    }

if (!function_exists('lsttraining_render_leitstellen_fahrzeuge')) {
    function lsttraining_render_leitstellen_fahrzeuge() {
        if (!lsttraining_user_can('fahrzeuge')) {
            wp_die('Keine Berechtigung.');
        }
        $template = plugin_dir_path(__FILE__) . 'fahrzeuge.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="notice notice-error"><p>Die Datei fahrzeuge.php wurde nicht gefunden.</p></div>';
        }
    }
}

if (!function_exists('lsttraining_render_krankenhaeuser')) {
    function lsttraining_render_krankenhaeuser() {
        require_once plugin_dir_path(__FILE__) . 'hospitals.php';
    }
}

if (!function_exists('lsttraining_render_leitstellen_wachen')) {
    function lsttraining_render_leitstellen_wachen() {
        require_once plugin_dir_path(__FILE__) . 'wachen.php';
    }
}

if (!function_exists('lsttraining_render_benutzer_page')) {
    function lsttraining_render_benutzer_page() {
        $template = plugin_dir_path(__FILE__) . 'benutzer.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="notice notice-error"><p>Die Datei benutzer.php wurde nicht gefunden.</p></div>';
        }
    }
}

if (!function_exists('lsttraining_render_verlauf_page')) {
    function lsttraining_render_verlauf_page() {
        $template = plugin_dir_path(__FILE__) . 'verlauf.php';
        if (file_exists($template)) {
            require_once $template;
        } else {
            echo '<div class="notice notice-error"><p>Die Datei verlauf.php wurde nicht gefunden.</p></div>';
        }
    }
}

if (!function_exists('lsttraining_render_help')) {
    function lsttraining_render_help() {
        $template = plugin_dir_path(__FILE__) . 'help.php';
        if (is_readable($template)) {
            include $template;
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__('Die Hilfedatei wurde nicht gefunden.', 'lsttraining') . '</p></div>';
        }
    }
}
