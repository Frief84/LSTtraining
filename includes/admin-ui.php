<?php
/**
 * Admin UI Dispatcher für LSTtraining Plugin
 */

function lsttraining_render_leitstellen() {
    require_once plugin_dir_path(__FILE__) . '/leitstellen_editor.php';
}

function lsttraining_render_leitstellen_wachen() {
    echo '<div class="wrap"><h1>Leitstellen – Wachen</h1><p>Hier könnten die Wachen der spielbaren Leitstellen verwaltet werden.</p></div>';
}

function lsttraining_render_leitstellen_fahrzeuge() {
    echo '<div class="wrap"><h1>Leitstellen – Fahrzeuge</h1><p>Hier könnten die Fahrzeuge der spielbaren Leitstellen verwaltet werden.</p></div>';
}

function lsttraining_render_nebenleitstellen() {
    require_once plugin_dir_path(__FILE__) . '/nebenstellen_editor.php';
}

function lsttraining_render_neben_wachen() {
    echo '<div class="wrap"><h1>Neben-Wachen</h1><p>Bearbeite realistische Wachen aus Nebenleitstellen.</p></div>';
}

function lsttraining_render_neben_fahrzeuge() {
    echo '<div class="wrap"><h1>Neben-Fahrzeuge</h1><p>Verwalte Fahrzeuge, die zu Neben-Wachen gehören.</p></div>';
}
