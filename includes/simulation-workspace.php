<?php
if (!defined('ABSPATH')) { exit; }

function lsttraining_workspace_enqueue_assets(): void {
    wp_enqueue_style(
        'lst-openlayers-css',
        LSTTRAINING_URL . 'openlayers/ol.css',
        [],
        null
    );

    wp_enqueue_style(
        'lst-fontawesome-core',
        LSTTRAINING_URL . 'vendor/fontawesome/css/fontawesome.min.css',
        [],
        '6.7.2'
    );

    wp_enqueue_style(
        'lst-fontawesome-solid',
        LSTTRAINING_URL . 'vendor/fontawesome/css/solid.min.css',
        ['lst-fontawesome-core'],
        '6.7.2'
    );

    wp_enqueue_style(
        'lsttraining-simulation-workspace',
        LSTTRAINING_URL . 'css/simulation-workspace.css',
        ['lst-openlayers-css', 'lst-fontawesome-solid'],
        '1.0.21'
    );

    wp_enqueue_script(
        'lst-openlayers',
        LSTTRAINING_URL . 'openlayers/ol.js',
        [],
        null,
        true
    );

    wp_enqueue_script(
        'lsttraining-simulation-workspace',
        LSTTRAINING_URL . 'js/simulation-workspace.js',
        ['jquery', 'lst-openlayers'],
        '1.0.23',
        true
    );

    wp_localize_script('lsttraining-simulation-workspace', 'lsttrainingWorkspace', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('lsttraining_frontend_start'),
        'rest_url' => esc_url_raw(rest_url('lst/v1/')),
        'rest_nonce' => wp_create_nonce('wp_rest'),
        'legacy_url_param' => 'legacy',
        'department_config_url' => LSTTRAINING_URL . 'data/departments.json',
        'signal_sprite_urls' => [
            'beacon' => LSTTRAINING_URL . 'img/signal/beacon.png',
            'strobe' => LSTTRAINING_URL . 'img/signal/strobe.png',
            'bar' => LSTTRAINING_URL . 'img/signal/lightbar.png',
            'glow' => LSTTRAINING_URL . 'img/signal/glow.png',
            'editor_point' => LSTTRAINING_URL . 'img/signal/editor-point.png',
        ],
        'texts' => [
            'bootstrapError' => 'Simulationsbasis konnte nicht geladen werden.',
            'snapshotError' => 'Simulationsdaten konnten nicht geladen werden.',
            'emptyVehicles' => 'Keine Fahrzeuge in dieser Instanz.',
            'emptyIncidents' => 'Keine Einsätze in dieser Ansicht.',
            'emptyTimeline' => 'Noch keine Funkmeldungen.',
            'routeError' => 'Route konnte nicht berechnet werden.',
            'alarmError' => 'Fahrzeug konnte nicht alarmiert werden.',
            'saveError' => 'Einsatzdaten konnten nicht gespeichert werden.',
        ],
    ]);
}

function lsttraining_workspace_icon(string $label): string {
    return '<span class="lstw-icon" aria-hidden="true">' . esc_html($label) . '</span>';
}

function lsttraining_workspace_panel(string $id, string $title, string $count_attr, string $body_attr, string $extra_class = '', string $body = ''): void {
    ?>
    <section class="lstw-panel <?php echo esc_attr($extra_class); ?>" data-lstw-panel="<?php echo esc_attr($id); ?>" data-lstw-area="<?php echo esc_attr($id); ?>">
        <header class="lstw-panel__head" data-lstw-drag-handle>
            <div class="lstw-panel__title">
                <span><?php echo esc_html($title); ?></span>
                <?php if ($count_attr !== ''): ?>
                    <b <?php echo $count_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>0</b>
                <?php endif; ?>
            </div>
            <?php if ($id === 'radio'): ?>
                <div class="lstw-head-tasks" data-lstw-pending-communications aria-label="Offene Kommunikation" hidden></div>
            <?php endif; ?>
            <nav class="lstw-panel__actions" aria-label="<?php echo esc_attr($title); ?> Fensteraktionen">
                <?php if ($id === 'details'): ?>
                    <button type="button" data-lstw-close-details title="Schließen">Schließen</button>
                <?php endif; ?>
                <button type="button" data-lstw-action="minimize" title="Minimieren">_</button>
                <button type="button" data-lstw-action="maximize" title="Maximieren">[]</button>
                <button type="button" data-lstw-action="float" title="Entkoppeln">Float</button>
                <button type="button" data-lstw-action="popout" title="Als Fenster ausgliedern">Fenster</button>
            </nav>
        </header>
        <div class="lstw-panel__tabs" data-lstw-tabs hidden></div>
        <div class="lstw-panel__body" <?php echo $body_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
            <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
    </section>
    <?php
}

function lsttraining_workspace_render_instance_view(int $instanz_id): string {
    $instance = function_exists('lsttraining_frontend_fetch_instance_summary')
        ? lsttraining_frontend_fetch_instance_summary($instanz_id, (int) get_current_user_id())
        : null;
    $back_url = remove_query_arg(['lst_instance', 'lst_sim_view']);

    ob_start();
    ?>
    <div class="lstw-shell" data-lsttraining-workspace data-instance-id="<?php echo esc_attr((string) $instanz_id); ?>">
        <?php if (!$instance): ?>
            <main class="lstw-error">
                <h1>Simulation nicht gefunden</h1>
                <p>Die angeforderte Simulation konnte nicht geladen werden oder du bist nicht Teilnehmer dieser Instanz.</p>
                <a href="<?php echo esc_url($back_url); ?>">Zurück zum Start</a>
            </main>
        <?php else:
            $settings = is_array($instance['settings'] ?? null) ? $instance['settings'] : [];
            $role = (string) ($instance['user_rolle'] ?? '');
            $leitstelle_parts = array_filter([
                $instance['leitstelle_name'] ?? '',
                $instance['leitstelle_ort'] ?? '',
                $instance['leitstelle_bundesland'] ?? '',
            ]);
            ?>
            <header class="lstw-topbar">
                <div class="lstw-brand">
                    <button type="button" class="lstw-square-btn" data-lstw-action="reset-view" title="Ansicht zentrieren">Menu</button>
                    <div>
                        <strong>LST Training</strong>
                        <span><?php echo esc_html(implode(' - ', $leitstelle_parts)); ?></span>
                    </div>
                </div>

                <div class="lstw-dispatch-center" data-lstw-leitstelle="<?php echo esc_attr((string) ($instance['leitstelle_id'] ?? '')); ?>">
                    <span>Leitstelle</span>
                    <strong><?php echo esc_html($instance['leitstelle_name'] ?? 'Leitstelle'); ?></strong>
                </div>

                <div class="lstw-clock" aria-label="Simulationszeit">
                    <span>Simulationszeit</span>
                    <strong data-lstw-sim-time><?php echo esc_html(wp_date('H:i:s')); ?></strong>
                </div>

                <div class="lstw-weather" data-lstw-weather aria-label="Wetterlage">
                    <span>Wetter</span>
                    <strong data-lstw-weather-label>--</strong>
                    <small data-lstw-weather-next></small>
                </div>

                <div class="lstw-controls">
                    <button type="button" data-lstw-toggle-pause>Pause</button>
                    <button type="button" class="is-active" data-lstw-speed="1">x1</button>
                    <button type="button" data-lstw-speed="2">x2</button>
                    <button type="button" data-lstw-speed="5">x5</button>
                </div>

                <div class="lstw-actions">
                    <button type="button" data-lstw-save-layout>Layout speichern</button>
                    <button type="button" data-lstw-load-layout>Layout laden</button>
                    <button type="button" data-lstw-settings>Settings</button>
                    <span class="lstw-user"><?php echo esc_html(wp_get_current_user()->display_name ?: wp_get_current_user()->user_login); ?></span>
                </div>
            </header>

            <div class="lsttraining-message lstw-message" data-lstw-message hidden></div>

            <main class="lstw-board" data-lstw-board>
                <div class="lstw-dock-zone lstw-dock-zone--left" data-lstw-dock-zone="left">Links andocken</div>
                <div class="lstw-dock-zone lstw-dock-zone--center" data-lstw-dock-zone="center">Mitte andocken</div>
                <div class="lstw-dock-zone lstw-dock-zone--right" data-lstw-dock-zone="right">Rechts andocken</div>
                <div class="lstw-dock-zone lstw-dock-zone--bottom" data-lstw-dock-zone="bottom">Unten andocken</div>

                <section class="lstw-panel lstw-panel--map" data-lstw-panel="map" data-lstw-area="map">
                    <header class="lstw-panel__head" data-lstw-drag-handle>
                        <div class="lstw-panel__title"><span>Karte</span></div>
                        <nav class="lstw-panel__actions" aria-label="Kartenfenster Aktionen">
                            <button type="button" data-lstw-action="minimize" title="Minimieren">_</button>
                            <button type="button" data-lstw-action="maximize" title="Maximieren">[]</button>
                            <button type="button" data-lstw-action="float" title="Entkoppeln">Float</button>
                            <button type="button" data-lstw-action="popout" title="Als Fenster ausgliedern">Fenster</button>
                        </nav>
                    </header>
                    <div class="lstw-panel__tabs" data-lstw-tabs hidden></div>
                    <div class="lstw-map-panel">
                        <div class="lstw-map-toggles" data-lstw-layer-toggles>
                            <button type="button" class="is-active" data-lstw-layer="incidents">Einsätze</button>
                            <button type="button" class="is-active" data-lstw-layer="vehicles">Fahrzeuge</button>
                            <button type="button" class="is-active" data-lstw-layer="hospitals">Krankenhäuser</button>
                            <button type="button" class="is-active" data-lstw-layer="stations">Wachen</button>
                        </div>
                        <div class="lstw-map-tools">
                            <button type="button" data-lstw-map-tool="center" title="Zentrieren">Center</button>
                        </div>
                        <div class="lstw-map" data-lstw-map></div>
                        <div class="lstw-map-pause" data-lstw-pause-overlay hidden>Pause</div>
                        <div class="lstw-map-status" data-lstw-map-status>Livekarte wird geladen...</div>
                    </div>
                </section>

                <?php
                lsttraining_workspace_panel(
                    'vehicles',
                    'Fahrzeuge',
                    '',
                    'data-lstw-vehicles',
                    'lstw-panel--vehicles',
                    '<div class="lstw-panel-tools"><input type="search" data-lstw-vehicle-search placeholder="Fahrzeuge suchen..."><div class="lstw-segments" data-lstw-vehicle-filters><button class="is-active" type="button" data-filter="all">Alle</button><button type="button" data-filter="rd">RD</button><button type="button" data-filter="fw">FW</button><button type="button" data-filter="thw">THW</button><button type="button" data-filter="rth">RTH</button></div></div><div class="lstw-scroll" data-lstw-vehicle-list></div>'
                );
                ?>

                <section class="lstw-panel lstw-panel--incidents" data-lstw-panel="incidents" data-lstw-area="incidents">
                    <header class="lstw-panel__head" data-lstw-drag-handle>
                        <div class="lstw-panel__title"><span>Einsätze</span><b data-lstw-incident-count>0</b></div>
                        <nav class="lstw-panel__actions" aria-label="Einsatzfenster Aktionen">
                            <button type="button" data-lstw-new-incident>Neuer Einsatz</button>
                            <button type="button" data-lstw-action="minimize" title="Minimieren">_</button>
                            <button type="button" data-lstw-action="maximize" title="Maximieren">[]</button>
                            <button type="button" data-lstw-action="float" title="Entkoppeln">Float</button>
                        </nav>
                    </header>
                    <div class="lstw-panel__tabs" data-lstw-tabs hidden></div>
                    <div class="lstw-panel__body">
                        <div class="lstw-scroll" data-lstw-incident-list></div>
                    </div>
                </section>

                <?php
                lsttraining_workspace_panel('details', 'Einsatzdetails', '', '', 'lstw-panel--details', '<div class="lstw-detail" data-lstw-detail><p class="lstw-empty">Kein Einsatz ausgewählt.</p></div>');
                lsttraining_workspace_panel('radio', 'Funk', 'data-lstw-radio-count', '', 'lstw-panel--radio', '<div class="lstw-panel-tools"><div class="lstw-segments" data-lstw-radio-filters><button class="is-active" type="button" data-filter="all">Alle</button><button type="button" data-filter="dispatch">Leitstelle</button><button type="button" data-filter="vehicles">Fahrzeuge</button><button type="button" data-filter="caller">Notruf</button><button type="button" data-filter="system">System</button></div></div><div class="lstw-timeline" data-lstw-radio-list></div>');
                ?>

                <div class="lstw-grid-resizer lstw-grid-resizer--vertical lstw-grid-resizer--left" data-lstw-grid-resize="left" aria-hidden="true"></div>
                <div class="lstw-grid-resizer lstw-grid-resizer--vertical lstw-grid-resizer--right" data-lstw-grid-resize="right" aria-hidden="true"></div>
                <div class="lstw-grid-resizer lstw-grid-resizer--horizontal" data-lstw-grid-resize="rows" aria-hidden="true"></div>
            </main>

            <div class="lstw-minimized" data-lstw-minimized></div>
            <div class="lstw-modal" data-lstw-modal hidden></div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
