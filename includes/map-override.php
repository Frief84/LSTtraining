<?php
add_action('template_redirect', function () {
    if (!is_singular('page')) return;

    $slug = get_option('lsttraining_map_page');
    global $post;

    if (!$post || $post->post_name !== $slug) return;

    $plugin_url = plugin_dir_url(__FILE__) . '../';

    // Kartenanzeige starten
    echo '<!DOCTYPE html><html><head>';
    echo '<meta charset="UTF-8">';
    echo '<title>LSTtraining Map</title>';
    echo '<link rel="stylesheet" href="' . $plugin_url . 'openlayers/ol.css">';
    echo '</head><body>';
    echo '<div id="map" style="height:100vh; width:100vw;"></div>';

    // Übergib Pfad an JS
    echo '<script>const LST_PLUGIN = "' . esc_url($plugin_url) . '";</script>';

    echo '<script src="' . $plugin_url . 'openlayers/ol.js"></script>';
    echo '<script src="' . $plugin_url . 'js/app.js"></script>';
    echo '</body></html>';

    exit;
});
?>
