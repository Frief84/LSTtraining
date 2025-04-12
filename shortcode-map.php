<?php
function lsttraining_render_map() {
    $slug = get_option('lsttraining_map_page');
    global $post;

    if (!$post || $post->post_name !== $slug) {
        return ''; // Nicht anzeigen, wenn nicht auf richtiger Seite
    }

    ob_start();
    ?>
    <div id="map" style="height: 600px; width: 100%;"></div>
    <link rel="stylesheet" href="<?php echo plugin_dir_url(__FILE__) . '../openlayers/ol.css'; ?>">
    <script src="<?php echo plugin_dir_url(__FILE__) . '../openlayers/ol.js'; ?>"></script>
    <script src="<?php echo plugin_dir_url(__FILE__) . '../js/app.js'; ?>"></script>
    <?php
    return ob_get_clean();
}
add_shortcode('lsttraining_map', 'lsttraining_render_map');
?>
