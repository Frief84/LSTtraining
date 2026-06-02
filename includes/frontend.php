<?php
if (!defined('ABSPATH')) { exit; }

function lsttraining_frontend_enqueue_style(): void {
    wp_enqueue_style(
        'lsttraining-frontend',
        LSTTRAINING_URL . 'css/frontend.css',
        [],
        '1.0.14'
    );
}

function lsttraining_frontend_enqueue_script(): void {
    lsttraining_frontend_enqueue_style();

    wp_enqueue_style(
        'lst-openlayers-css',
        LSTTRAINING_URL . 'openlayers/ol.css',
        [],
        null
    );

    wp_enqueue_script(
        'lst-openlayers',
        LSTTRAINING_URL . 'openlayers/ol.js',
        [],
        null,
        true
    );

    wp_enqueue_script(
        'lsttraining-frontend-start',
        LSTTRAINING_URL . 'js/frontend-start.js',
        ['jquery', 'lst-openlayers'],
        '1.0.19',
        true
    );

    wp_localize_script('lsttraining-frontend-start', 'lsttrainingFrontend', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('lsttraining_frontend_start'),
        'rest_url' => esc_url_raw(rest_url('lst/v1/')),
        'rest_nonce' => wp_create_nonce('wp_rest'),
        'texts'    => [
            'loadingLeitstellen' => 'Leitstellen werden geladen...',
            'noLeitstellen'      => 'Keine Leitstellen gefunden.',
            'startSuccess'       => 'Simulation wurde gestartet. Du wirst weitergeleitet...',
            'startError'         => 'Die Simulation konnte nicht gestartet werden.',
            'loadingInstances'    => 'Offene Spiele werden geladen...',
            'noInstances'         => 'Aktuell sind keine offenen Multiplayer- oder Einsatzleiter-Spiele vorhanden.',
            'loadingSavedInstances' => 'Gespeicherte Spiele werden geladen...',
            'noSavedInstances'      => 'Du hast aktuell keine fortsetzbaren Spiele.',
            'deleteInstanceSuccess' => 'Gespeicherte Simulation wurde gelöscht.',
            'deleteInstanceError'   => 'Simulation konnte nicht gelöscht werden.',
            'leaveInstanceSuccess'  => 'Du hast das gemeinsame Spiel verlassen.',
            'leaveInstanceError'    => 'Spiel konnte nicht verlassen werden.',
            'joinSuccess'         => 'Du bist dem Spiel beigetreten. Du wirst weitergeleitet...',
            'joinError'           => 'Beitritt konnte nicht abgeschlossen werden.',
            'loadingEinsaetze'    => 'Einsätze werden geladen...',
            'noEinsaetze'         => 'Noch keine Einsätze in dieser Simulation.',
            'tickError'           => 'Einsatzgenerator konnte nicht ausgeführt werden.',
            'bootstrapError'      => 'Simulationsbasis konnte nicht geladen werden.',
            'snapshotError'        => 'Simulationsdaten konnten nicht geladen werden.',
            'routingError'         => 'Route konnte nicht berechnet werden.',
            'acceptError'          => 'Anruf konnte nicht angenommen werden.',
            'alarmError'           => 'Fahrzeug konnte nicht alarmiert werden.',
            'missingAjaxAction'    => 'AJAX-Action lsttraining_sim_get_snapshot ist nicht registriert. Bitte Plugin-Dateien und Cache prüfen.',
        ],
    ]);
}

function lsttraining_frontend_simulation_url(int $instanz_id, ?string $base_url = null): string {
    $base = $base_url ?: home_url('/');
    $base = remove_query_arg(['lst_instance', 'lst_sim_view'], $base);

    return add_query_arg([
        'lst_sim_view' => '1',
        'lst_instance' => $instanz_id,
    ], $base);
}

function lsttraining_frontend_page_has_shortcode(): bool {
    global $post;

    if (!$post instanceof WP_Post) {
        return false;
    }

    $content = (string) $post->post_content;
    return has_shortcode($content, 'lsttraining_start') || has_shortcode($content, 'lsttraining_profile');
}

add_action('wp_enqueue_scripts', function () {
    if (!lsttraining_frontend_page_has_shortcode()) {
        return;
    }

    global $post;
    $content = $post instanceof WP_Post ? (string) $post->post_content : '';
    if (is_user_logged_in() && has_shortcode($content, 'lsttraining_start')) {
        lsttraining_frontend_enqueue_script();
        return;
    }

    lsttraining_frontend_enqueue_style();
});

function lsttraining_frontend_label(string $type, ?string $value): string {
    $maps = [
        'mode' => [
            'singleplayer'       => 'Einzelspieler',
            'multiplayer'        => 'Multiplayer',
            'einsatzleiter'      => 'Einsatzleiter',
            'leiter'             => 'Einsatzleiter',
            'leiter_multiplayer' => 'Einsatzleiter',
        ],
        'role' => [
            'leiter'     => 'Einsatzleiter',
            'mitspieler' => 'Leitstellendisponent',
        ],
        'season' => [
            'spring' => 'Frühling',
            'summer' => 'Sommer',
            'autumn' => 'Herbst',
            'winter' => 'Winter',
        ],
        'weather' => [
            'auto'   => 'Automatisch',
            'clear'  => 'Klar',
            'cloudy' => 'Bewölkt',
            'rain'   => 'Regen',
            'snow'   => 'Schnee',
            'storm'  => 'Sturm',
            'windy'  => 'Windig',
            'fog'    => 'Nebel',
            'cold'   => 'Kalt',
            'hot'    => 'Heiß',
        ],
    ];

    return $maps[$type][$value ?? ''] ?? (string) $value;
}

function lsttraining_frontend_fetch_instance_summary(int $instanz_id, int $user_id): ?array {
    if ($instanz_id <= 0 || $user_id <= 0) {
        return null;
    }

    $pdo = lsttraining_get_connection();
    if (!$pdo) {
        return null;
    }

    if (function_exists('lsttraining_frontend_ensure_instance_columns')) {
        lsttraining_frontend_ensure_instance_columns($pdo);
    }

    $stmt = $pdo->prepare('
        SELECT
            si.id,
            si.name,
            si.started_at,
            si.settings_json,
            si.sim_state,
            l.name AS leitstelle_name,
            l.ort AS leitstelle_ort,
            l.bundesland AS leitstelle_bundesland,
            iu.rolle AS user_rolle,
            (
                SELECT COUNT(*)
                FROM fahrzeug_status fs
                WHERE fs.instanz_id = si.id
            ) AS fahrzeuge_count
        FROM spielinstanzen si
        INNER JOIN leitstellen l ON l.id = si.leitstelle_id
        LEFT JOIN instanz_user iu ON iu.instanz_id = si.id AND iu.user_id = ?
        WHERE si.id = ?
        LIMIT 1
    ');
    $stmt->execute([$user_id, $instanz_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (!$row['user_rolle'] && !current_user_can('manage_options'))) {
        return null;
    }

    $settings = json_decode((string) ($row['settings_json'] ?? ''), true);
    $row['settings'] = is_array($settings) ? $settings : [];

    return $row;
}

function lsttraining_frontend_render_login_notice(): string {
    $login_url = wp_login_url(get_permalink());

    ob_start();
    ?>
    <div class="lsttraining-start lsttraining-start--plain">
        <section class="lsttraining-start__panel lsttraining-start__login">
            <h2>Simulation starten</h2>
            <p>Bitte melde dich an, um eine Simulation zu starten.</p>
            <a class="lsttraining-btn lsttraining-btn--primary" href="<?php echo esc_url($login_url); ?>">Zum WordPress-Login</a>
        </section>
    </div>
    <?php
    return ob_get_clean();
}

function lsttraining_frontend_render_instance_view(int $instanz_id, bool $force_fullscreen = false): string {
    $instance = lsttraining_frontend_fetch_instance_summary($instanz_id, (int) get_current_user_id());
    $back_url = remove_query_arg('lst_instance');
    $is_fullscreen = $force_fullscreen || (isset($_GET['lst_sim_view']) && in_array((string) wp_unslash($_GET['lst_sim_view']), ['1', 'legacy'], true));

    ob_start();
    ?>
    <div class="<?php echo esc_attr($is_fullscreen ? 'lsttraining-sim-shell' : 'lsttraining-start lsttraining-start--plain lsttraining-instance'); ?>" data-lsttraining-instance data-instance-id="<?php echo esc_attr((string) $instanz_id); ?>">
        <?php if (!$instance): ?>
            <section class="lsttraining-start__panel">
                <h2>Simulation nicht gefunden</h2>
                <p>Die angeforderte Simulation konnte nicht geladen werden oder du bist nicht Teilnehmer dieser Instanz.</p>
                <a class="lsttraining-btn" href="<?php echo esc_url($back_url); ?>">Zurück zum Start</a>
            </section>
        <?php else:
            $settings = $instance['settings'];
            $mode = (string) ($settings['mode'] ?? '');
            $role = (string) ($instance['user_rolle'] ?? '');
            $can_force_spawn = current_user_can('manage_options') || $role === 'leiter';
            $leitstelle_parts = array_filter([
                $instance['leitstelle_name'] ?? '',
                $instance['leitstelle_ort'] ?? '',
                $instance['leitstelle_bundesland'] ?? '',
            ]);
            ?>
            <div class="lsttraining-message" data-lst-message hidden></div>

            <section class="lsttraining-dispatch">
                <div class="lsttraining-dispatch__workspace">
                    <aside class="lsttraining-dispatch-panel lsttraining-dispatch-panel--vehicles">
                        <div class="lsttraining-panel-head">
                            <div>
                                <p class="lsttraining-kicker">FMS</p>
                                <h3>Fahrzeuge</h3>
                            </div>
                            <span class="lsttraining-count" data-lst-vehicle-count>0</span>
                        </div>
                        <div class="lsttraining-dispatch-list" data-lst-vehicles aria-live="polite">
                            <p class="lsttraining-muted">Fahrzeuge werden geladen...</p>
                        </div>
                    </aside>

                    <main class="lsttraining-dispatch-mapwrap" aria-label="Livekarte">
                        <div class="lsttraining-dispatch-map" data-lst-dispatch-map></div>
                        <div class="lsttraining-map-status" data-lst-map-status>Livekarte wird geladen...</div>
                    </main>

                    <aside class="lsttraining-dispatch-panel lsttraining-dispatch-panel--incidents">
                        <div class="lsttraining-panel-head">
                            <div>
                                <p class="lsttraining-kicker"><?php echo esc_html(lsttraining_frontend_label('role', $role)); ?> · <?php echo esc_html(lsttraining_frontend_label('mode', $mode)); ?></p>
                                <h3><?php echo esc_html($instance['name']); ?></h3>
                            </div>
                            <span class="lsttraining-count" data-lst-incident-count>0</span>
                        </div>
                        <div class="lsttraining-sim-context">
                            <strong><?php echo esc_html(implode(' - ', $leitstelle_parts)); ?></strong>
                            <span><?php echo esc_html($instance['started_at'] ?: (($settings['start_date'] ?? '') . ' ' . ($settings['start_time'] ?? ''))); ?></span>
                            <div class="lsttraining-sim-clock" aria-label="Datum und Uhrzeit">
                                <time data-lst-sim-date><?php echo esc_html(wp_date('d.m.Y')); ?></time>
                                <strong data-lst-sim-time><?php echo esc_html(wp_date('H:i:s')); ?></strong>
                            </div>
                            <div class="lsttraining-card-actions">
                                <button type="button" class="lsttraining-mini-btn" data-lst-run-tick>Generator prüfen</button>
                                <button type="button" class="lsttraining-mini-btn" data-lst-layout-reset>Layout zurücksetzen</button>
                            </div>
                        </div>
                        <div class="lsttraining-dispatch-list" data-lst-einsaetze aria-live="polite">
                            <p class="lsttraining-muted">Einsätze werden geladen...</p>
                        </div>
                    </aside>

                    <section class="lsttraining-dispatch-panel lsttraining-dispatch-panel--fms">
                        <div class="lsttraining-panel-head">
                            <div>
                                <p class="lsttraining-kicker">Funk</p>
                                <h3>FMS-Meldungen</h3>
                            </div>
                        </div>
                        <div class="lsttraining-log" data-lst-fms-log aria-live="polite"></div>
                    </section>

                    <section class="lsttraining-dispatch-panel lsttraining-dispatch-panel--calls">
                        <div class="lsttraining-panel-head">
                            <div>
                                <p class="lsttraining-kicker">Notruf</p>
                                <h3>Anruferverlauf</h3>
                            </div>
                            <?php if ($can_force_spawn): ?>
                                <button type="button" class="lsttraining-mini-btn" data-lst-force-spawn>Neuer Anruf</button>
                            <?php endif; ?>
                        </div>
                        <div class="lsttraining-log lsttraining-log--calls" data-lst-call-log aria-live="polite"></div>
                    </section>

                    <button type="button" class="lsttraining-resize-handle lsttraining-resize-handle--vertical lsttraining-resize-handle--map" data-lst-resize="map" aria-label="Kartengröße anpassen"></button>
                    <button type="button" class="lsttraining-resize-handle lsttraining-resize-handle--vertical lsttraining-resize-handle--incidents" data-lst-resize="incidents" aria-label="Einsatzfenstergröße anpassen"></button>
                    <button type="button" class="lsttraining-resize-handle lsttraining-resize-handle--horizontal" data-lst-resize="rows" aria-label="Obere und untere Fenstergröße anpassen"></button>
                </div>
            </section>
            <div class="lsttraining-dispatch-modal" data-lst-dispatch-modal hidden></div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function lsttraining_frontend_render_fullscreen_document(int $instanz_id, string $view = 'workspace'): void {
    status_header(200);
    nocache_headers();
    if ($view === 'legacy') {
        lsttraining_frontend_enqueue_script();
    } elseif (function_exists('lsttraining_workspace_enqueue_assets')) {
        lsttraining_workspace_enqueue_assets();
    } else {
        lsttraining_frontend_enqueue_script();
        $view = 'legacy';
    }
    ?>
    <!doctype html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo esc_html(sprintf('LSTtraining Simulation %d', $instanz_id)); ?></title>
        <?php wp_head(); ?>
    </head>
    <body <?php body_class('lsttraining-sim-body'); ?>>
        <?php
        if ($view === 'legacy' || !function_exists('lsttraining_workspace_render_instance_view')) {
            echo lsttraining_frontend_render_instance_view($instanz_id, true);
        } else {
            echo lsttraining_workspace_render_instance_view($instanz_id);
        }
        ?>
        <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}

add_action('template_redirect', function () {
    $sim_view = isset($_GET['lst_sim_view']) ? (string) wp_unslash($_GET['lst_sim_view']) : '';
    $is_sim_view = in_array($sim_view, ['1', 'legacy'], true);
    $instanz_id = isset($_GET['lst_instance']) ? absint($_GET['lst_instance']) : 0;
    if (!$is_sim_view || $instanz_id <= 0) {
        return;
    }

    lsttraining_frontend_render_fullscreen_document($instanz_id, $sim_view === 'legacy' ? 'legacy' : 'workspace');
    exit;
});

function lsttraining_frontend_profile_message(): ?array {
    if (
        !isset($_POST['lsttraining_profile_action'])
        || (string) wp_unslash($_POST['lsttraining_profile_action']) !== 'save'
    ) {
        return null;
    }

    if (!is_user_logged_in()) {
        return ['type' => 'error', 'text' => 'Bitte melde dich an, um dein Profil zu bearbeiten.'];
    }

    $nonce = isset($_POST['lsttraining_profile_nonce']) ? (string) wp_unslash($_POST['lsttraining_profile_nonce']) : '';
    if (!wp_verify_nonce($nonce, 'lsttraining_profile_update')) {
        return ['type' => 'error', 'text' => 'Der Sicherheits-Token ist ungültig. Bitte lade die Seite neu.'];
    }

    $user = wp_get_current_user();
    $user_id = (int) $user->ID;
    $email = isset($_POST['user_email']) ? sanitize_email(wp_unslash($_POST['user_email'])) : '';
    if (!$email || !is_email($email)) {
        return ['type' => 'error', 'text' => 'Bitte gib eine gültige E-Mail-Adresse ein.'];
    }

    $existing_email_user = email_exists($email);
    if ($existing_email_user && (int) $existing_email_user !== $user_id) {
        return ['type' => 'error', 'text' => 'Diese E-Mail-Adresse wird bereits von einem anderen Konto verwendet.'];
    }

    $profiles = function_exists('lsttraining_frontend_difficulty_profiles')
        ? lsttraining_frontend_difficulty_profiles()
        : [];
    $difficulty = isset($_POST['lsttraining_difficulty'])
        ? sanitize_key(wp_unslash($_POST['lsttraining_difficulty']))
        : 'normal';
    if (!isset($profiles[$difficulty])) {
        $difficulty = 'normal';
    }

    $marker_mode = isset($_POST['lsttraining_vehicle_marker_mode'])
        ? sanitize_key(wp_unslash($_POST['lsttraining_vehicle_marker_mode']))
        : 'marker';
    if (!in_array($marker_mode, ['marker', 'image', 'tactical'], true)) {
        $marker_mode = 'marker';
    }

    $userdata = [
        'ID' => $user_id,
        'display_name' => sanitize_text_field(wp_unslash($_POST['display_name'] ?? $user->display_name)),
        'first_name' => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
        'last_name' => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
        'user_email' => $email,
    ];

    $pass1 = isset($_POST['pass1']) ? (string) wp_unslash($_POST['pass1']) : '';
    $pass2 = isset($_POST['pass2']) ? (string) wp_unslash($_POST['pass2']) : '';
    if ($pass1 !== '' || $pass2 !== '') {
        if ($pass1 !== $pass2) {
            return ['type' => 'error', 'text' => 'Die neuen Passwörter stimmen nicht überein.'];
        }
        if (strlen($pass1) < 8) {
            return ['type' => 'error', 'text' => 'Das neue Passwort muss mindestens 8 Zeichen lang sein.'];
        }
        $userdata['user_pass'] = $pass1;
    }

    $updated = wp_update_user($userdata);
    if (is_wp_error($updated)) {
        return ['type' => 'error', 'text' => $updated->get_error_message()];
    }

    update_user_meta($user_id, 'lsttraining_sim_difficulty', $difficulty);
    update_user_meta($user_id, 'lsttraining_vehicle_marker_mode', $marker_mode);

    return ['type' => 'success', 'text' => 'Profil wurde gespeichert.'];
}

function lsttraining_render_profile_shortcode(): string {
    lsttraining_frontend_enqueue_style();

    if (!is_user_logged_in()) {
        return lsttraining_frontend_render_login_notice();
    }

    $message = lsttraining_frontend_profile_message();
    $user = wp_get_current_user();
    $profiles = function_exists('lsttraining_frontend_difficulty_profiles')
        ? lsttraining_frontend_difficulty_profiles()
        : [];
    $current_difficulty = get_user_meta((int) $user->ID, 'lsttraining_sim_difficulty', true);
    if (!isset($profiles[$current_difficulty])) {
        $current_difficulty = 'normal';
    }
    $marker_mode = get_user_meta((int) $user->ID, 'lsttraining_vehicle_marker_mode', true);
    if (!in_array($marker_mode, ['marker', 'image', 'tactical'], true)) {
        $marker_mode = 'marker';
    }
    $marker_modes = [
        'marker' => [
            'label' => 'Marker',
            'description' => 'Kompakte FMS-Kreise mit Rufnamen auf der Livekarte.',
        ],
        'image' => [
            'label' => 'Fahrzeugbilder',
            'description' => 'Hinterlegte Fahrzeugbilder, sonst das Plugin-Standardbild.',
        ],
        'tactical' => [
            'label' => 'Taktische Zeichen',
            'description' => 'Reduzierte taktische Symbole nach Fahrzeug- und Organisationstyp.',
        ],
    ];

    ob_start();
    ?>
    <div class="lsttraining-start lsttraining-profile">
        <aside class="lsttraining-rail" aria-label="Fachdienste">
            <span class="lsttraining-rail__item lsttraining-rail__item--rd" title="Rettungsdienst">RD</span>
            <span class="lsttraining-rail__item lsttraining-rail__item--fw" title="Feuerwehr">FW</span>
            <span class="lsttraining-rail__item lsttraining-rail__item--thw" title="Technisches Hilfswerk">THW</span>
        </aside>

        <header class="lsttraining-start__hero">
            <p class="lsttraining-kicker">LSTtraining</p>
            <h2>Profil</h2>
            <p>Verwalte deine Kontodaten und lege fest, wie fordernd neue Simulationen starten.</p>
        </header>

        <?php if ($message): ?>
            <div class="lsttraining-message lsttraining-message--<?php echo esc_attr($message['type']); ?>">
                <?php echo esc_html($message['text']); ?>
            </div>
        <?php endif; ?>

        <form class="lsttraining-profile__form" method="post">
            <input type="hidden" name="lsttraining_profile_action" value="save">
            <?php wp_nonce_field('lsttraining_profile_update', 'lsttraining_profile_nonce'); ?>

            <section class="lsttraining-start__panel">
                <h3>Benutzerdaten</h3>
                <div class="lsttraining-field-grid lsttraining-profile__grid">
                    <label class="lsttraining-field">
                        <span>Benutzername</span>
                        <input type="text" value="<?php echo esc_attr($user->user_login); ?>" disabled>
                    </label>
                    <label class="lsttraining-field">
                        <span>Anzeigename</span>
                        <input type="text" name="display_name" value="<?php echo esc_attr($user->display_name); ?>" required>
                    </label>
                    <label class="lsttraining-field">
                        <span>E-Mail</span>
                        <input type="email" name="user_email" value="<?php echo esc_attr($user->user_email); ?>" required>
                    </label>
                    <label class="lsttraining-field">
                        <span>Vorname</span>
                        <input type="text" name="first_name" value="<?php echo esc_attr($user->first_name); ?>">
                    </label>
                    <label class="lsttraining-field">
                        <span>Nachname</span>
                        <input type="text" name="last_name" value="<?php echo esc_attr($user->last_name); ?>">
                    </label>
                </div>

                <div class="lsttraining-field-grid lsttraining-profile__grid">
                    <label class="lsttraining-field">
                        <span>Neues Passwort</span>
                        <input type="password" name="pass1" autocomplete="new-password">
                    </label>
                    <label class="lsttraining-field">
                        <span>Passwort wiederholen</span>
                        <input type="password" name="pass2" autocomplete="new-password">
                    </label>
                </div>
            </section>

            <section class="lsttraining-start__panel">
                <h3>Schwierigkeitsgrad</h3>
                <div class="lsttraining-difficulty-grid">
                    <?php foreach ($profiles as $key => $profile): ?>
                        <label class="lsttraining-difficulty-card">
                            <input type="radio" name="lsttraining_difficulty" value="<?php echo esc_attr($key); ?>" <?php checked($current_difficulty, $key); ?>>
                            <span><?php echo esc_html($profile['label']); ?></span>
                            <small><?php echo esc_html($profile['description']); ?></small>
                            <em>
                                <?php echo esc_html((string) $profile['max_active_einsaetze']); ?> aktive Einsätze,
                                Last <?php echo esc_html((string) $profile['leitstelle_load_factor']); ?>
                            </em>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="lsttraining-start__panel">
                <h3>Kartendarstellung Fahrzeuge</h3>
                <div class="lsttraining-marker-mode-grid">
                    <?php foreach ($marker_modes as $key => $mode): ?>
                        <label class="lsttraining-marker-mode-card">
                            <input type="radio" name="lsttraining_vehicle_marker_mode" value="<?php echo esc_attr($key); ?>" <?php checked($marker_mode, $key); ?>>
                            <span><?php echo esc_html($mode['label']); ?></span>
                            <small><?php echo esc_html($mode['description']); ?></small>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="lsttraining-start__actions">
                <button type="submit" class="lsttraining-btn lsttraining-btn--primary">Profil speichern</button>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

function lsttraining_render_start_shortcode(): string {
    lsttraining_frontend_enqueue_style();

    if (!is_user_logged_in()) {
        return lsttraining_frontend_render_login_notice();
    }

    $instanz_id = isset($_GET['lst_instance']) ? absint($_GET['lst_instance']) : 0;
    if ($instanz_id > 0) {
        $sim_url = lsttraining_frontend_simulation_url($instanz_id, get_permalink());
        return '<div class="lsttraining-start lsttraining-start--plain"><section class="lsttraining-start__panel"><h2>Simulation öffnen</h2><p>Die Simulation läuft in einem eigenen Vollfenster ohne WordPress-Menü.</p><a class="lsttraining-btn lsttraining-btn--primary" href="' . esc_url($sim_url) . '" target="_blank" rel="noopener">Simulation öffnen</a></section></div>';
    }

    lsttraining_frontend_enqueue_script();

    $today = wp_date('Y-m-d');
    $time = wp_date('H:i');

    ob_start();
    ?>
    <div class="lsttraining-start" data-lsttraining-start>
        <aside class="lsttraining-rail" aria-label="Fachdienste">
            <span class="lsttraining-rail__item lsttraining-rail__item--rd" title="Rettungsdienst">RD</span>
            <span class="lsttraining-rail__item lsttraining-rail__item--fw" title="Feuerwehr">FW</span>
            <span class="lsttraining-rail__item lsttraining-rail__item--thw" title="Technisches Hilfswerk">THW</span>
        </aside>

        <header class="lsttraining-start__hero">
            <p class="lsttraining-kicker">LSTtraining</p>
            <h2>Simulation starten</h2>
            <p>Erstelle eine neue Leitstellenlage oder tritt einer offenen Simulation als Disponent bei.</p>
        </header>

        <div class="lsttraining-message" data-lst-message hidden></div>

        <div class="lsttraining-start__workspace">
            <form class="lsttraining-start__form" id="lsttraining-start-form" novalidate>
                <section class="lsttraining-start__panel">
                    <h3>Leitstelle</h3>
                    <label class="lsttraining-field">
                        <span>Leitstelle auswählen</span>
                        <select name="leitstelle_id" data-lst-leitstellen required>
                            <option value="">Leitstellen werden geladen...</option>
                        </select>
                    </label>
                </section>

                <section class="lsttraining-start__panel">
                    <h3>Zeit &amp; Umgebung</h3>
                    <div class="lsttraining-field-grid">
                        <label class="lsttraining-field">
                            <span>Datum</span>
                            <input type="date" name="start_date" value="<?php echo esc_attr($today); ?>" required>
                        </label>
                        <label class="lsttraining-field">
                            <span>Uhrzeit</span>
                            <input type="time" name="start_time" value="<?php echo esc_attr($time); ?>" required>
                        </label>
                        <button type="button" class="lsttraining-btn lsttraining-btn--inline" data-lst-now>Jetzt verwenden</button>
                    </div>

                    <div class="lsttraining-field-grid">
                        <label class="lsttraining-field">
                            <span>Jahreszeit</span>
                            <select name="season_override" data-lst-season-select>
                                <option value="auto">Automatisch</option>
                                <option value="spring">Frühling</option>
                                <option value="summer">Sommer</option>
                                <option value="autumn">Herbst</option>
                                <option value="winter">Winter</option>
                            </select>
                        </label>
                        <div class="lsttraining-season-preview" aria-live="polite">
                            Berechnet: <strong data-lst-season-label>Automatisch</strong>
                        </div>
                    </div>
                </section>

                <section class="lsttraining-start__panel">
                    <h3>Spielmodus</h3>
                    <div class="lsttraining-mode-grid" data-lst-mode-grid>
                        <label class="lsttraining-mode-card">
                            <input type="radio" name="mode" value="singleplayer" checked>
                            <span>Einzelspieler</span>
                            <small>Alleine simulieren und die Leitstelle selbst steuern.</small>
                        </label>
                        <label class="lsttraining-mode-card">
                            <input type="radio" name="mode" value="multiplayer">
                            <span>Multiplayer</span>
                            <small>Gemeinsam als Leitstellendisponenten in einer Instanz arbeiten.</small>
                        </label>
                        <label class="lsttraining-mode-card lsttraining-mode-card--leader">
                            <input type="radio" name="mode" value="einsatzleiter">
                            <span>Einsatzleiter</span>
                            <small>Lagen und Einsätze vorgeben, während Disponenten reagieren.</small>
                        </label>
                    </div>
                </section>

                <div class="lsttraining-start__actions">
                    <button type="submit" class="lsttraining-btn lsttraining-btn--primary" data-lst-submit>Simulation starten</button>
                </div>
            </form>

            <div class="lsttraining-start__side">
                <section class="lsttraining-start__panel lsttraining-area-panel">
                    <div class="lsttraining-panel-head">
                        <div>
                            <p class="lsttraining-kicker">GIS</p>
                            <h3>Einsatzgebiet</h3>
                        </div>
                    </div>
                    <div class="lsttraining-area-preview" aria-label="Einsatzgebiet Vorschau">
                        <div class="lsttraining-area-map" data-lst-area-map></div>
                        <p class="lsttraining-area-status" data-lst-area-status>Wähle eine Leitstelle aus, um das Einsatzgebiet anzuzeigen.</p>
                    </div>
                </section>

                <aside class="lsttraining-join lsttraining-start__panel">
                    <div class="lsttraining-panel-head">
                        <div>
                            <p class="lsttraining-kicker">Fortsetzen</p>
                            <h3>Meine gespeicherten Spiele</h3>
                        </div>
                        <button type="button" class="lsttraining-btn lsttraining-btn--small" data-lst-refresh-saved-instances>Aktualisieren</button>
                    </div>
                    <div class="lsttraining-open-list" data-lst-saved-instances aria-live="polite">
                        <p class="lsttraining-muted">Gespeicherte Spiele werden geladen...</p>
                    </div>
                </aside>

                <aside class="lsttraining-join lsttraining-start__panel">
                    <div class="lsttraining-panel-head">
                        <div>
                            <p class="lsttraining-kicker">Teilnahme</p>
                            <h3>Offene Spiele beitreten</h3>
                        </div>
                        <button type="button" class="lsttraining-btn lsttraining-btn--small" data-lst-refresh-instances>Aktualisieren</button>
                    </div>
                    <div class="lsttraining-open-list" data-lst-open-instances aria-live="polite">
                        <p class="lsttraining-muted">Offene Spiele werden geladen...</p>
                    </div>
                </aside>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('lsttraining_start', 'lsttraining_render_start_shortcode');
add_shortcode('lsttraining_profile', 'lsttraining_render_profile_shortcode');
