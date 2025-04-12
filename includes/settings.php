<?php
function lsttraining_register_settings() {
    register_setting('lsttraining_options', 'lsttraining_map_page');
    register_setting('lsttraining_options', 'lsttraining_db_mode');
    register_setting('lsttraining_options', 'lsttraining_ext_host');
    register_setting('lsttraining_options', 'lsttraining_ext_user');
    register_setting('lsttraining_options', 'lsttraining_ext_pass');
    register_setting('lsttraining_options', 'lsttraining_ext_name');
    register_setting('lsttraining_options', 'lsttraining_ors_key'); // NEU: ORS-API-Key
}

function lsttraining_settings_page() {
    $map_page = get_option('lsttraining_map_page', '');
    $db_mode = get_option('lsttraining_db_mode', 'wordpress');
    $host = get_option('lsttraining_ext_host', '');
    $user = get_option('lsttraining_ext_user', '');
    $pass = get_option('lsttraining_ext_pass', '');
    $name = get_option('lsttraining_ext_name', '');
    $api_key = get_option('lsttraining_ors_key', '');

    $pages = get_pages();
    ?>
    <div class="wrap">
        <h1>LSTtraining Einstellungen</h1>
        <form method="post" action="options.php">
            <?php
                settings_fields('lsttraining_options');
                do_settings_sections('lsttraining_options');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Kartenanzeige auf Seite</th>
                    <td>
                        <select name="lsttraining_map_page">
                            <option value="">-- Seite wählen --</option>
                            <?php foreach ($pages as $page): ?>
                                <option value="<?php echo esc_attr($page->post_name); ?>" <?php selected($map_page, $page->post_name); ?>>
                                    <?php echo esc_html($page->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Wähle eine bestehende WordPress-Seite, auf der die Karte erscheinen soll.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Datenbank-Modus</th>
                    <td>
                        <select name="lsttraining_db_mode" id="lsttraining_db_mode">
                            <option value="wordpress" <?php selected($db_mode, 'wordpress'); ?>>WordPress-Datenbank verwenden</option>
                            <option value="extern" <?php selected($db_mode, 'extern'); ?>>Externe Datenbank verwenden</option>
                        </select>
                    </td>
                </tr>
                <tr class="external-db">
                    <th scope="row">Externer DB-Host</th>
                    <td><input type="text" name="lsttraining_ext_host" value="<?php echo esc_attr($host); ?>" /></td>
                </tr>
                <tr class="external-db">
                    <th scope="row">Externer DB-Benutzer</th>
                    <td><input type="text" name="lsttraining_ext_user" value="<?php echo esc_attr($user); ?>" /></td>
                </tr>
                <tr class="external-db">
                    <th scope="row">Externer DB-Passwort</th>
                    <td><input type="password" name="lsttraining_ext_pass" value="<?php echo esc_attr($pass); ?>" /></td>
                </tr>
                <tr class="external-db">
                    <th scope="row">Externer DB-Name</th>
                    <td><input type="text" name="lsttraining_ext_name" value="<?php echo esc_attr($name); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">OpenRouteService API-Key</th>
                    <td>
                        <input type="text" name="lsttraining_ors_key" value="<?php echo esc_attr($api_key); ?>" style="width: 400px;" />
                        <p class="description">Trage hier deinen API-Key von <a href='https://openrouteservice.org/dev/#/signup' target='_blank'>openrouteservice.org</a> ein.</p>
                    </td>
                </tr>
            </table>
			
			
            <?php submit_button(); ?>
        </form>

        <hr />
        <h2>Tabellen initialisieren</h2>
        <form method="post">
            <input type="submit" name="lsttraining_install_schema" class="button button-secondary" value="Datenbankstruktur aus schema.sql installieren" />
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        function toggleDBFields() {
            const mode = document.getElementById('lsttraining_db_mode').value;
            document.querySelectorAll('.external-db').forEach(el => {
                el.style.display = (mode === 'extern') ? 'table-row' : 'none';
            });
        }
        document.getElementById('lsttraining_db_mode').addEventListener('change', toggleDBFields);
        toggleDBFields();
    });
    </script>
    <?php
}

add_action('admin_init', 'lsttraining_register_settings');

add_action('admin_menu', function () {
    add_menu_page('LSTtraining', 'LSTtraining', 'manage_options', 'lsttraining', 'lsttraining_settings_page');
});
?>
