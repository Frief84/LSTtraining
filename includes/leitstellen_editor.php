<?php
/**
 * Editor for playable dispatch centres (“Leitstellen”)
 * – GeoJSON is loaded via JavaScript and saved in leitstellen.geojson
 *   v2025-05-04  • admin notice instead of late redirect (no header warnings)
 */


$leitstelle_id = isset( $_GET['ls_id'] )
    ? intval( $_GET['ls_id'] )
    : 0;

require_once plugin_dir_path( __FILE__ ) . '/db.php';
require_once plugin_dir_path( __FILE__ ) . '/einsatzgebiet-editor.php';

$pdo         = lsttraining_get_connection();
$leitstellen = [];
$neighbor_ids_by_leitstelle = [];
$suchbegriff = isset( $_GET['suchbegriff'] ) ? $_GET['suchbegriff'] : '';

if ( ! function_exists( 'lsttraining_leitstellen_column_exists' ) ) {
    function lsttraining_leitstellen_column_exists( PDO $pdo, string $table, string $column ): bool {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                   FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?'
            );
            $stmt->execute( [ $table, $column ] );
            return (int) $stmt->fetchColumn() > 0;
        } catch ( Throwable $e ) {
            return false;
        }
    }
}

if ( ! function_exists( 'lsttraining_leitstellen_table_exists' ) ) {
    function lsttraining_leitstellen_table_exists( PDO $pdo, string $table ): bool {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                   FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?'
            );
            $stmt->execute( [ $table ] );
            return (int) $stmt->fetchColumn() > 0;
        } catch ( Throwable $e ) {
            return false;
        }
    }
}

if ( ! function_exists( 'lsttraining_leitstellen_ensure_neighbor_table' ) ) {
    function lsttraining_leitstellen_ensure_neighbor_table( PDO $pdo ): void {
        if ( lsttraining_leitstellen_table_exists( $pdo, 'leitstelle_nebenleitstellen' ) ) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `leitstelle_nebenleitstellen` (
              `leitstelle_id` INT NOT NULL,
              `nebenleitstelle_id` INT NOT NULL,
              PRIMARY KEY (`leitstelle_id`, `nebenleitstelle_id`),
              KEY `idx_ln_nebenleitstelle` (`nebenleitstelle_id`),
              CONSTRAINT `fk_ln_leitstelle`
                FOREIGN KEY (`leitstelle_id`) REFERENCES `leitstellen`(`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_ln_nebenleitstelle`
                FOREIGN KEY (`nebenleitstelle_id`) REFERENCES `nebenleitstellen`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}

if ( ! function_exists( 'lsttraining_leitstellen_save_neighbors' ) ) {
    function lsttraining_leitstellen_save_neighbors( PDO $pdo, int $leitstelle_id, array $neben_ids ): void {
        if ( $leitstelle_id <= 0 ) {
            return;
        }
        lsttraining_leitstellen_ensure_neighbor_table( $pdo );

        $clean_ids = array_values( array_filter(
            array_unique( array_filter( array_map( 'intval', $neben_ids ) ) ),
            static function ( int $neben_id ) use ( $leitstelle_id ): bool {
                return $neben_id !== $leitstelle_id;
            }
        ) );
        if ( $clean_ids ) {
            try {
                $own_stmt = $pdo->prepare( 'SELECT name FROM leitstellen WHERE id = ? LIMIT 1' );
                $own_stmt->execute( [ $leitstelle_id ] );
                $own_name = trim( (string) $own_stmt->fetchColumn() );
                if ( $own_name !== '' ) {
                    $placeholders = implode( ',', array_fill( 0, count( $clean_ids ), '?' ) );
                    $self_stmt = $pdo->prepare(
                        'SELECT id FROM nebenleitstellen WHERE id IN (' . $placeholders . ') AND LOWER(TRIM(name)) = LOWER(TRIM(?))'
                    );
                    $self_stmt->execute( array_merge( $clean_ids, [ $own_name ] ) );
                    $self_ids = array_map( 'intval', $self_stmt->fetchAll( PDO::FETCH_COLUMN ) ?: [] );
                    if ( $self_ids ) {
                        $clean_ids = array_values( array_diff( $clean_ids, $self_ids ) );
                    }
                }
            } catch ( Throwable $e ) {
                error_log( '[LSTtraining][leitstellen_editor] self_neighbor_filter: ' . $e->getMessage() );
            }
        }
        $pdo->prepare( 'DELETE FROM leitstelle_nebenleitstellen WHERE leitstelle_id = ?' )->execute( [ $leitstelle_id ] );
        if ( ! $clean_ids ) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT IGNORE INTO leitstelle_nebenleitstellen (leitstelle_id, nebenleitstelle_id) VALUES (?, ?)'
        );
        foreach ( $clean_ids as $neben_id ) {
            $insert->execute( [ $leitstelle_id, $neben_id ] );
        }
    }
}

if ( ! function_exists( 'lsttraining_leitstellen_normalize_signal_lights_json' ) ) {
    function lsttraining_leitstellen_normalize_signal_lights_json( $raw ): string {
        $raw = is_string( $raw ) ? wp_unslash( $raw ) : '';
        if ( trim( $raw ) === '' ) {
            return '';
        }
        $decoded = json_decode( $raw, true );
        $lights = is_array( $decoded['lights'] ?? null ) ? $decoded['lights'] : ( is_array( $decoded ) ? $decoded : [] );
        $normalized = [];
        foreach ( $lights as $light ) {
            if ( ! is_array( $light ) ) {
                continue;
            }
            $x = isset( $light['x'] ) ? (float) $light['x'] : null;
            $y = isset( $light['y'] ) ? (float) $light['y'] : null;
            if ( $x === null || $y === null || ! is_finite( $x ) || ! is_finite( $y ) ) {
                continue;
            }
            $type = sanitize_key( (string) ( $light['type'] ?? 'beacon' ) );
            if ( ! in_array( $type, [ 'beacon', 'strobe', 'bar', 'glow' ], true ) ) {
                $type = 'beacon';
            }
            $normalized[] = [
                'x'        => max( 0.0, min( 1.0, $x ) ),
                'y'        => max( 0.0, min( 1.0, $y ) ),
                'type'     => $type,
                'interval' => max( 120, min( 2000, (int) ( $light['interval'] ?? 420 ) ) ),
                'phase'    => max( 0, min( 5000, (int) ( $light['phase'] ?? 0 ) ) ),
                'size'     => max( 0.4, min( 2.5, (float) ( $light['size'] ?? 1 ) ) ),
            ];
        }
        return $normalized ? wp_json_encode( [ 'version' => 1, 'lights' => $normalized ] ) : '';
    }
}

if ( ! lsttraining_user_can( 'leitstellen', $leitstelle_id ) ) {
    wp_die( 'Keine Berechtigung.' );
}

if ( $pdo && ! lsttraining_leitstellen_column_exists( $pdo, 'leitstellen', 'police_vehicle_image' ) ) {
    try {
        $pdo->exec( "ALTER TABLE leitstellen ADD COLUMN police_vehicle_image VARCHAR(255) NULL DEFAULT 'img/fahrzeug/default_pol.png' AFTER geojson" );
    } catch ( Throwable $e ) {
        error_log( '[LSTtraining][leitstellen_editor] police_vehicle_image: ' . $e->getMessage() );
    }
}
$leitstellen_has_police_image_column = $pdo ? lsttraining_leitstellen_column_exists( $pdo, 'leitstellen', 'police_vehicle_image' ) : false;

$lsttraining_leitstellen_default_columns = [
    'police_signal_lights_json' => "ALTER TABLE leitstellen ADD COLUMN police_signal_lights_json LONGTEXT NULL AFTER police_vehicle_image",
    'rescue_vehicle_image' => "ALTER TABLE leitstellen ADD COLUMN rescue_vehicle_image VARCHAR(255) NULL DEFAULT 'img/fahrzeug/default.png' AFTER police_signal_lights_json",
    'rescue_signal_lights_json' => "ALTER TABLE leitstellen ADD COLUMN rescue_signal_lights_json LONGTEXT NULL AFTER rescue_vehicle_image",
];
if ( $pdo ) {
    foreach ( $lsttraining_leitstellen_default_columns as $lst_col => $lst_sql ) {
        if ( ! lsttraining_leitstellen_column_exists( $pdo, 'leitstellen', $lst_col ) ) {
            try {
                $pdo->exec( $lst_sql );
            } catch ( Throwable $e ) {
                error_log( '[LSTtraining][leitstellen_editor] ' . $lst_col . ': ' . $e->getMessage() );
            }
        }
    }
}
$leitstellen_has_police_signal_column = $pdo ? lsttraining_leitstellen_column_exists( $pdo, 'leitstellen', 'police_signal_lights_json' ) : false;
$leitstellen_has_rescue_image_column = $pdo ? lsttraining_leitstellen_column_exists( $pdo, 'leitstellen', 'rescue_vehicle_image' ) : false;
$leitstellen_has_rescue_signal_column = $pdo ? lsttraining_leitstellen_column_exists( $pdo, 'leitstellen', 'rescue_signal_lights_json' ) : false;
$nebenleitstellen_options = [];
if ( $pdo ) {
    try {
        lsttraining_leitstellen_ensure_neighbor_table( $pdo );
        $nls_stmt = $pdo->query( 'SELECT id, name, gps, geojson FROM nebenleitstellen ORDER BY name ASC, id ASC' );
        $nebenleitstellen_options = $nls_stmt ? $nls_stmt->fetchAll( PDO::FETCH_ASSOC ) : [];
    } catch ( Throwable $e ) {
        error_log( '[LSTtraining][leitstellen_editor] neighbor_table: ' . $e->getMessage() );
    }
}


/* -------------------------------------------------------------------------
 * DELETE
 * ---------------------------------------------------------------------- */
if ( isset( $_GET['delete_id'] ) && $pdo ) {
    $pdo->prepare( 'DELETE FROM leitstellen WHERE id = ?' )
        ->execute( [ intval( $_GET['delete_id'] ) ] );
	    // Log: Leitstelle gelöscht
    lsttraining_log_activity([
        'entity_type' => 'leitstelle',
        'action'      => 'delete',
        'entity_id'   => (int)$_GET['delete_id'],
        'meta'        => ['page' => 'leitstellen_editor.php']
    ]);
    add_settings_error( 'lsttraining_msg', 'deleted',
        'Leitstelle gelöscht.', 'updated' );
}

/* -------------------------------------------------------------------------
 * CREATE
 * ---------------------------------------------------------------------- */
if ( $_SERVER['REQUEST_METHOD'] === 'POST'
     && isset( $_POST['lst_form_mode'] )
     && $_POST['lst_form_mode'] === 'create'
     && $pdo ) {

    // Neue Leitstelle schreiben
    $create_columns = 'name, ort, bundesland, land, latitude, longitude, geojson';
    $create_values = '?,?,?,?,?,?,?';
    $create_params = [
        sanitize_text_field( $_POST['lst_update_name'] ),
        sanitize_text_field( $_POST['lst_update_ort'] ),
        sanitize_text_field( $_POST['lst_update_bl'] ),
        sanitize_text_field( $_POST['lst_update_land'] ),
        floatval( $_POST['lst_update_lat'] ),
        floatval( $_POST['lst_update_lon'] ),
        wp_unslash( $_POST['geojson_edit'] ?? '' ),
    ];
    if ( $leitstellen_has_police_image_column ) {
        $create_columns .= ', police_vehicle_image';
        $create_values .= ',?';
        $create_params[] = sanitize_text_field( wp_unslash( $_POST['lst_update_police_vehicle_image'] ?? 'img/fahrzeug/default_pol.png' ) );
    }
    if ( $leitstellen_has_police_signal_column ) {
        $create_columns .= ', police_signal_lights_json';
        $create_values .= ',?';
        $create_params[] = lsttraining_leitstellen_normalize_signal_lights_json( $_POST['lst_update_police_signal_lights_json'] ?? '' );
    }
    if ( $leitstellen_has_rescue_image_column ) {
        $create_columns .= ', rescue_vehicle_image';
        $create_values .= ',?';
        $create_params[] = sanitize_text_field( wp_unslash( $_POST['lst_update_rescue_vehicle_image'] ?? 'img/fahrzeug/default.png' ) );
    }
    if ( $leitstellen_has_rescue_signal_column ) {
        $create_columns .= ', rescue_signal_lights_json';
        $create_values .= ',?';
        $create_params[] = lsttraining_leitstellen_normalize_signal_lights_json( $_POST['lst_update_rescue_signal_lights_json'] ?? '' );
    }
    $stmt = $pdo->prepare( 'INSERT INTO leitstellen (' . $create_columns . ') VALUES (' . $create_values . ')' );
    $stmt->execute( $create_params );
	
   $new_id = (int)$pdo->lastInsertId();
    lsttraining_leitstellen_save_neighbors(
        $pdo,
        $new_id,
        array_map( 'intval', (array) ( $_POST['lst_neighbor_nebenleitstellen'] ?? [] ) )
    );
    lsttraining_log_activity([
        'entity_type' => 'leitstelle',
        'action'      => 'create',
        'entity_id'   => $new_id,
        'meta'        => ['page' => 'leitstellen_editor.php']
    ]);

    add_settings_error(
        'lsttraining_msg',
        'lst_ok',
        'Leitstelle angelegt.',
        'updated'
    );
}


/* -------------------------------------------------------------------------
 * UPDATE
 * ---------------------------------------------------------------------- */
if ( $_SERVER['REQUEST_METHOD'] === 'POST'
     && isset( $_POST['lst_update_id'] )
     && ( $_POST['lst_form_mode'] ?? '' ) !== 'create'
     && $pdo ) {

    /* basic data */
    $update_sql = 'UPDATE leitstellen
            SET name = ?,
                ort = ?,
                bundesland = ?,
                land = ?,
                latitude = ?,
                longitude = ?';
    $update_params = [
        sanitize_text_field( $_POST['lst_update_name'] ),
        sanitize_text_field( $_POST['lst_update_ort'] ),
        sanitize_text_field( $_POST['lst_update_bl'] ),
        sanitize_text_field( $_POST['lst_update_land'] ),
        floatval( $_POST['lst_update_lat'] ),
        floatval( $_POST['lst_update_lon'] ),
    ];
    if ( $leitstellen_has_police_image_column ) {
        $update_sql .= ', police_vehicle_image = ?';
        $update_params[] = sanitize_text_field( wp_unslash( $_POST['lst_update_police_vehicle_image'] ?? 'img/fahrzeug/default_pol.png' ) );
    }
    if ( $leitstellen_has_police_signal_column ) {
        $update_sql .= ', police_signal_lights_json = ?';
        $update_params[] = lsttraining_leitstellen_normalize_signal_lights_json( $_POST['lst_update_police_signal_lights_json'] ?? '' );
    }
    if ( $leitstellen_has_rescue_image_column ) {
        $update_sql .= ', rescue_vehicle_image = ?';
        $update_params[] = sanitize_text_field( wp_unslash( $_POST['lst_update_rescue_vehicle_image'] ?? 'img/fahrzeug/default.png' ) );
    }
    if ( $leitstellen_has_rescue_signal_column ) {
        $update_sql .= ', rescue_signal_lights_json = ?';
        $update_params[] = lsttraining_leitstellen_normalize_signal_lights_json( $_POST['lst_update_rescue_signal_lights_json'] ?? '' );
    }
    $update_sql .= ' WHERE id = ?';
    $update_params[] = intval( $_POST['lst_update_id'] );
    $pdo->prepare( $update_sql )->execute( $update_params );
    lsttraining_leitstellen_save_neighbors(
        $pdo,
        intval( $_POST['lst_update_id'] ),
        array_map( 'intval', (array) ( $_POST['lst_neighbor_nebenleitstellen'] ?? [] ) )
    );
	
   lsttraining_log_activity([
        'entity_type' => 'leitstelle',
        'action'      => 'update',
        'entity_id'   => (int)$leitstelle_id,
        'meta'        => ['page' => 'leitstellen_editor.php']
    ]);

    /* GeoJSON (accept both field names) */
    $geojson = '';
    if ( isset( $_POST['geojson_edit'] ) ) {
        $geojson = stripslashes( $_POST['geojson_edit'] );
    } elseif ( isset( $_POST['geojson_einsatzgebiet_edit'] ) ) {
        $geojson = stripslashes( $_POST['geojson_einsatzgebiet_edit'] );
    }
    if ( $geojson !== '' ) {
        $pdo->prepare(
            'UPDATE leitstellen SET geojson = ? WHERE id = ?'
        )->execute( [ $geojson, intval( $_POST['lst_update_id'] ) ] );
    }

    /* success notice */
    add_settings_error( 'lsttraining_msg', 'saved',
        'Leitstelle gespeichert.', 'updated' );
}

/* -------------------------------------------------------------------------
 * LIST
 * ---------------------------------------------------------------------- */
if ( $pdo ) {
    $police_image_select = $leitstellen_has_police_image_column
        ? 'police_vehicle_image'
        : "'img/fahrzeug/default_pol.png' AS police_vehicle_image";
    $police_signal_select = $leitstellen_has_police_signal_column
        ? 'police_signal_lights_json'
        : "'' AS police_signal_lights_json";
    $rescue_image_select = $leitstellen_has_rescue_image_column
        ? 'rescue_vehicle_image'
        : "'img/fahrzeug/default.png' AS rescue_vehicle_image";
    $rescue_signal_select = $leitstellen_has_rescue_signal_column
        ? 'rescue_signal_lights_json'
        : "'' AS rescue_signal_lights_json";
    $default_selects = implode( ',', [ $police_image_select, $police_signal_select, $rescue_image_select, $rescue_signal_select ] );
    if ( $suchbegriff !== '' ) {
        $stmt = $pdo->prepare(
            'SELECT id,name,ort,bundesland,land,latitude,longitude,' . $default_selects . '
               FROM leitstellen
              WHERE name LIKE ?
                 OR id = ?
           ORDER BY name ASC'
        );
        $stmt->execute( [ '%' . $suchbegriff . '%', $suchbegriff ] );
    } else {
        $stmt = $pdo->query(
            'SELECT id,name,ort,bundesland,land,latitude,longitude,' . $default_selects . '
               FROM leitstellen
           ORDER BY name ASC'
        );
    }
    $leitstellen = $stmt->fetchAll( PDO::FETCH_OBJ );
    $neighbor_ids_by_leitstelle = [];
    if ( $leitstellen && lsttraining_leitstellen_table_exists( $pdo, 'leitstelle_nebenleitstellen' ) ) {
        $ids = array_map( static function ( $item ) {
            return (int) $item->id;
        }, $leitstellen );
        if ( $ids ) {
            $rel_stmt = $pdo->prepare(
                'SELECT leitstelle_id, nebenleitstelle_id
                   FROM leitstelle_nebenleitstellen
                  WHERE leitstelle_id IN (' . implode( ',', array_fill( 0, count( $ids ), '?' ) ) . ')'
            );
            if ( $rel_stmt ) {
                $rel_stmt->execute( $ids );
                foreach ( $rel_stmt->fetchAll( PDO::FETCH_ASSOC ) ?: [] as $rel ) {
                    $ls_id = (int) ( $rel['leitstelle_id'] ?? 0 );
                    $neighbor_ids_by_leitstelle[ $ls_id ] = $neighbor_ids_by_leitstelle[ $ls_id ] ?? [];
                    $neighbor_ids_by_leitstelle[ $ls_id ][] = (int) ( $rel['nebenleitstelle_id'] ?? 0 );
                }
            }
        }
    }
}
?>

<div class="wrap">
    <h1>Leitstellen verwalten</h1>

    <?php settings_errors( 'lsttraining_msg' ); ?>

    <form method="get" style="margin-bottom:20px;">
        <input type="hidden" name="page" value="lsttraining_leitstellen">
        <input type="text" name="suchbegriff" placeholder="Suchen nach Name oder ID …"
               value="<?php echo esc_attr( $suchbegriff ); ?>" style="width:300px;">
        <button class="button">Suchen</button>
    </form>
	
	<button id="btn-new-leitstelle" class="button button-primary">
    + Neue Leitstelle
</button>

    <table class="widefat">
        <thead>
            <tr>
                <th>ID</th><th>Name</th><th>Ort</th>
                <th>Bundesland</th><th>Land</th><th>Koordinaten</th><th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $leitstellen as $l ) : ?>
            <tr>
                <td><?php echo esc_html( $l->id ); ?></td>
                <td><?php echo esc_html( $l->name ); ?></td>
                <td><?php echo esc_html( $l->ort ); ?></td>
                <td><?php echo esc_html( $l->bundesland ); ?></td>
                <td><?php echo esc_html( $l->land ); ?></td>
                <td><?php echo esc_html( $l->latitude ); ?>,&nbsp;<?php echo esc_html( $l->longitude ); ?></td>
                <td>
                    <a href="#" class="button edit-leitstelle"
                       data-id="<?php echo esc_attr( $l->id ); ?>"
                       data-name="<?php echo esc_attr( $l->name ); ?>"
                       data-ort="<?php echo esc_attr( $l->ort ); ?>"
                       data-bl="<?php echo esc_attr( $l->bundesland ); ?>"
                       data-land="<?php echo esc_attr( $l->land ); ?>"
                       data-lat="<?php echo esc_attr( $l->latitude ); ?>"
                       data-lon="<?php echo esc_attr( $l->longitude ); ?>"
                       data-police-image="<?php echo esc_attr( $l->police_vehicle_image ?: 'img/fahrzeug/default_pol.png' ); ?>"
                       data-police-signal-lights="<?php echo esc_attr( $l->police_signal_lights_json ?? '' ); ?>"
                       data-rescue-image="<?php echo esc_attr( $l->rescue_vehicle_image ?: 'img/fahrzeug/default.png' ); ?>"
                       data-rescue-signal-lights="<?php echo esc_attr( $l->rescue_signal_lights_json ?? '' ); ?>"
                       data-neighbor-ids="<?php echo esc_attr( wp_json_encode( $neighbor_ids_by_leitstelle[ (int) $l->id ] ?? [] ) ); ?>"
                    >Bearbeiten</a>
                    <a href="<?php echo admin_url(
                        'admin.php?page=lsttraining_leitstellen&delete_id=' . $l->id ); ?>"
                       class="button button-link-delete"
                       onclick="return confirm('Wirklich löschen?');"
                    >Löschen</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Popup overlay -->
<div id="popup-overlay" class="lst-leitstelle-overlay" style="display:none;"></div>

<!-- Edit popup -->
<div id="edit-leitstelle-formular" class="lst-leitstelle-modal" style="display:none;">
    <div class="lst-leitstelle-modal__head">
        <div>
            <span class="lst-leitstelle-kicker">Leitstelle</span>
            <h2>Leitstelle bearbeiten</h2>
        </div>
        <button type="button" class="button" onclick="document.getElementById('popup-overlay').style.display='none'; document.getElementById('edit-leitstelle-formular').style.display='none';">Schließen</button>
    </div>

    <form method="post" class="lst-leitstelle-form">
            <input type="hidden" name="lst_form_mode" id="lst_form_mode" value="update">
            <input type="hidden" name="lst_update_id" id="lst_update_id">
        <div class="lst-leitstelle-grid">
            <section class="lst-leitstelle-card">
                <h3>Stammdaten</h3>
                <div class="lst-leitstelle-fields">
                    <label>Name<input type="text" name="lst_update_name" id="lst_update_name" required></label>
                    <label>Ort<input type="text" name="lst_update_ort" id="lst_update_ort"></label>
                    <label>Bundesland<input type="text" name="lst_update_bl" id="lst_update_bl"></label>
                    <label>Land<input type="text" name="lst_update_land" id="lst_update_land"></label>
                    <div class="lst-leitstelle-field-pair">
                        <label>Latitude<input type="number" step="0.000001" name="lst_update_lat" id="lst_update_lat"></label>
                        <label>Longitude<input type="number" step="0.000001" name="lst_update_lon" id="lst_update_lon"></label>
                    </div>
                </div>
            </section>

            <section class="lst-leitstelle-card lst-leitstelle-card--map">
                <h3>Karte</h3>
                <div id="map_edit" class="lst-leitstelle-map"></div>
            </section>
        </div>

        <section class="lst-leitstelle-card">
            <h3>Verwaltung</h3>
<?php
/* hidden polygon field + invisible map container (filled via JS) */
lsttraining_einsatzgebiet_editor(
    'einsatzgebiet_edit',   // placeholder map ID – JS will overwrite
    'geojson_edit',         // fixed hidden field
    '', 0, 'leitstelle', ''
);
?>
            <div class="lst-leitstelle-actions">
<button type="button" class="button open-einsatzgebiet-editor"
        data-map-id="einsatzgebiet_edit"
        data-leitstelle-id="0"
        data-center=""
        data-context="leitstelle">
    Einsatzgebiet bearbeiten
</button>
			
<button type="button" class="button open-wachen-editor"
        onclick="window.location.href='<?php 
          echo admin_url( 'admin.php?page=lsttraining_leitstellen_wachen' );
        ?>&ls_id='+document.getElementById('lst_update_id').value;">
    Wachen bearbeiten
</button>
<input type="hidden" id="current_leitstelle_id" name="ls_id" 
       value="<?php echo esc_attr( $leitstelle_id ); ?>">

<button
   type="button"
   class="button open-leitstelle-hospitals-editor"
>
   Krankenhäuser bearbeiten
</button>

<button
   type="button"
   class="button open-leitstelle-pois-editor"
>
   POIs bearbeiten
</button>

<button
   type="button"
   class="button"
   id="btn-osm-refresh"
   title="Geänderte OSM-Tiles für diese Leitstelle anwenden"
>OSM Tiles sync</button>
<?php
$zuo_url = admin_url( 'admin.php?page=lsttraining_zuordnung_modal'
    . '&entity_type=leitstelle&entity_id=' . intval($leitstelle_id)
    . '&TB_iframe=true&width=1100&height=760' );
?>
<button type="button"
        class="button"
        id="w_zuord_button_l"
        disabled
        title="Bitte zuerst speichern">
  Zuordnung der Wachen bearbeiten
</button>
            </div>
            <span id="lst-osm-refresh-spinner" class="spinner" style="float:none; margin-left:6px; visibility:hidden;"></span>
            <div id="lst-osm-refresh-status" class="notice inline" style="display:none; margin-top:10px; padding:8px;"></div>
        </section>

        <section class="lst-leitstelle-card">
            <h3>Nachbarleitstellen</h3>
            <div class="lst-neighbor-picker">
                <div class="lst-neighbor-picker__list">
                    <label for="lst_neighbor_nebenleitstellen">Angrenzende Leitstellen</label>
                    <select id="lst_neighbor_nebenleitstellen" name="lst_neighbor_nebenleitstellen[]" multiple size="8" style="min-width:320px;width:100%;">
                        <?php foreach ( $nebenleitstellen_options as $nls ) : ?>
                            <option value="<?php echo esc_attr( (string) (int) $nls['id'] ); ?>">
                                <?php echo esc_html( (string) $nls['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lst-neighbor-picker__map-wrap">
                    <div id="lst_neighbor_map" class="lst-neighbor-map" aria-label="Nachbarleitstellen visuell auswählen"></div>
                    <div class="lst-neighbor-map__empty" data-lst-neighbor-map-empty hidden>Keine Nebenleitstellen mit gültigen Koordinaten vorhanden.</div>
                    <div class="lst-neighbor-legend" aria-hidden="true">
                        <span><i class="is-home"></i> Leitstelle</span>
                        <span><i class="is-selected"></i> ausgewählt</span>
                        <span><i class="is-available"></i> weitere</span>
                    </div>
                </div>
            </div>
            <p class="description">Diese Nebenleitstellen können in laufenden Einsätzen per Unterstützungsanfrage kontaktiert werden.</p>
        </section>

        <section class="lst-leitstelle-card">
            <h3>Default-Fahrzeuge</h3>
            <div class="lst-default-vehicle-grid">
                <article class="lst-default-vehicle-card">
                    <div class="lst-default-vehicle-card__preview">
                        <img alt="Polizei-Fahrzeugbild" data-lst-default-image-preview-for="lst_update_police_vehicle_image">
                        <span data-lst-default-image-empty-for="lst_update_police_vehicle_image">Kein Bild geladen</span>
                    </div>
                    <div class="lst-default-vehicle-card__body">
                        <strong>Polizei-Fahrzeugbild</strong>
                        <label>Bildpfad oder URL
                            <input type="text" name="lst_update_police_vehicle_image" id="lst_update_police_vehicle_image" value="img/fahrzeug/default_pol.png" list="lst-vehicle-image-options" data-lst-default-image-input>
                        </label>
                        <div class="lst-default-vehicle-actions">
                            <button type="button" class="button lst-default-image-upload" data-target="lst_update_police_vehicle_image">Bild auswählen</button>
                            <button type="button" class="button lst-default-signal-editor-open" data-image-field="lst_update_police_vehicle_image" data-json-field="lst_update_police_signal_lights_json" data-preset="pol" data-title="Blaulichter Polizei">Blaulichter bearbeiten</button>
                        </div>
                        <input type="hidden" name="lst_update_police_signal_lights_json" id="lst_update_police_signal_lights_json" value="">
                        <p class="description">Standard: img/fahrzeug/default_pol.png</p>
                        <small class="lst-default-vehicle-path" data-lst-default-image-status-for="lst_update_police_vehicle_image"></small>
                    </div>
                </article>

                <article class="lst-default-vehicle-card">
                    <div class="lst-default-vehicle-card__preview">
                        <img alt="Default-Rettungsfahrzeug" data-lst-default-image-preview-for="lst_update_rescue_vehicle_image">
                        <span data-lst-default-image-empty-for="lst_update_rescue_vehicle_image">Kein Bild geladen</span>
                    </div>
                    <div class="lst-default-vehicle-card__body">
                        <strong>Default-Rettungsfahrzeug</strong>
                        <label>Bildpfad oder URL
                            <input type="text" name="lst_update_rescue_vehicle_image" id="lst_update_rescue_vehicle_image" value="img/fahrzeug/default.png" list="lst-vehicle-image-options" data-lst-default-image-input>
                        </label>
                        <div class="lst-default-vehicle-actions">
                            <button type="button" class="button lst-default-image-upload" data-target="lst_update_rescue_vehicle_image">Bild auswählen</button>
                            <button type="button" class="button lst-default-signal-editor-open" data-image-field="lst_update_rescue_vehicle_image" data-json-field="lst_update_rescue_signal_lights_json" data-preset="rd" data-title="Blaulichter Rettungsfahrzeug">Blaulichter bearbeiten</button>
                        </div>
                        <input type="hidden" name="lst_update_rescue_signal_lights_json" id="lst_update_rescue_signal_lights_json" value="">
                        <p class="description">Standard: img/fahrzeug/default.png</p>
                        <small class="lst-default-vehicle-path" data-lst-default-image-status-for="lst_update_rescue_vehicle_image"></small>
                    </div>
                </article>
            </div>
            <datalist id="lst-vehicle-image-options">
                <option value="img/fahrzeug/default_pol.png">
                <option value="img/fahrzeug/default.png">
                <?php
                $lst_police_vehicle_images = [];
                foreach ( [ 'png', 'jpg', 'jpeg', 'webp', 'gif' ] as $lst_police_vehicle_ext ) {
                    $lst_police_vehicle_images = array_merge(
                        $lst_police_vehicle_images,
                        glob( dirname( __DIR__ ) . '/img/fahrzeug/*.' . $lst_police_vehicle_ext ) ?: []
                    );
                }
                foreach ( array_unique( $lst_police_vehicle_images ) as $vehicle_image ) :
                ?>
                    <option value="<?php echo esc_attr( 'img/fahrzeug/' . basename( $vehicle_image ) ); ?>">
                <?php endforeach; ?>
            </datalist>
        </section>

        <div class="lst-leitstelle-footer">
            <button class="button button-primary">Speichern</button>
            <button type="button" class="button"
                    onclick="document.getElementById('popup-overlay').style.display='none';
                             document.getElementById('edit-leitstelle-formular').style.display='none';">
                Abbrechen
            </button>
        </div>
    </form>
</div>
<script>
window.lstNeighborLeitstellenData = <?php echo wp_json_encode( array_map(
    static function ( array $nls ): array {
        return [
            'id'      => (int) ( $nls['id'] ?? 0 ),
            'name'    => (string) ( $nls['name'] ?? '' ),
            'gps'     => (string) ( $nls['gps'] ?? '' ),
            'geojson' => (string) ( $nls['geojson'] ?? '' ),
        ];
    },
    $nebenleitstellen_options
), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
</script>
<!-- Krankenhäuser-Modal -->
<div id="leitstellen-hospitals-modal" class="hidden">
  <div class="modal-overlay"></div>
  <div class="modal-wrapper">
    <div class="modal-header">
      <h2>Krankenhäuser bearbeiten</h2>
      <button type="button" class="modal-close" aria-label="Schließen">&times;</button>
    </div>
    <div class="modal-body"></div>
  </div>
</div>

<script type="text/html" id="tmpl-leitstellen-hospitals-editor">
  <form id="leitstellen-hospitals-form">
    <p class="description">
      Hier können Krankenhäuser dieser Leitstelle zugeordnet werden.
    </p>

    <p>
      <label for="leitstellen-hospitals-filter"><strong>Krankenhäuser filtern</strong></label><br>
      <input
        type="text"
        id="leitstellen-hospitals-filter"
        placeholder="Nach Name oder ID filtern …"
        style="width:100%;"
        autocomplete="off"
      >
    </p>

    <div id="leitstellen-hospitals-map" style="width:100%; height:400px; margin-bottom:15px;"></div>

    <div
      id="leitstellen-hospitals-selector"
      style="max-height:300px; overflow:auto; border:1px solid #ddd; padding:10px; margin-bottom:15px;"
    >
      <# _.each(data.hospitals || [], function(h){ #>
        <label
          style="display:block; margin:0 0 8px 0;"
          class="hospital-row"
          data-id="{{ h.id }}"
          data-name="{{ (h.name || '').toLowerCase() }}"
        >
          <input
            type="checkbox"
            class="hos-toggle"
            value="{{ h.id }}"
            <# if (_.contains(data.selected_ids || [], String(h.id)) || _.contains(data.selected_ids || [], h.id)) { #>checked<# } #>
          >
          <strong>{{ h.name || ('Krankenhaus #' + h.id) }}</strong>
          <span class="description">
            (#{{ h.id }})
            <# if (h.versorgungsstufe) { #> · {{ h.versorgungsstufe }}<# } #>
            <# if (h.trauma_level) { #> · Trauma {{ h.trauma_level }}<# } #>
          </span>
        </label>
      <# }); #>
    </div>

    <p>
      <button type="submit" class="button button-primary">Speichern</button>
      <button type="button" class="button" id="leitstellen-hospitals-cancel">Abbrechen</button>
    </p>
  </form>
</script>
<!-- POI-Modal -->
<div id="leitstellen-pois-modal" class="hidden">
  <div class="modal-overlay"></div>
  <div class="modal-wrapper">
    <div class="modal-header">
      <h2>POIs bearbeiten</h2>
      <button type="button" class="modal-close" aria-label="Schließen">&times;</button>
    </div>
    <div class="modal-body"></div>
  </div>
</div>

<script type="text/html" id="tmpl-leitstellen-pois-editor">
  <div class="lst-poi-fullscreen">
    <div class="lst-poi-map-shell">
      <div id="leitstellen-pois-map"></div>

      <div class="lst-poi-overlay-top-right">
        <button type="button" class="button" id="lst-poi-toggle-legend">Legende</button>
        <button type="button" class="button" id="lst-poi-close">Schließen</button>
      </div>

      <div id="lst-poi-legend-overlay" class="lst-poi-legend-overlay hidden"></div>

      <div id="lst-poi-edit-popup" class="lst-poi-popup hidden">
        <div class="lst-poi-popup-head">
          <strong>POI bearbeiten</strong>
          <button type="button" class="button-link" id="lst-poi-edit-popup-close">×</button>
        </div>
        <div class="lst-poi-popup-body">
          <form id="lst-poi-edit-form">
            <input type="hidden" id="lst-poi-edit-id" value="">

            <div class="lst-poi-field">
              <label for="lst-poi-edit-type">Typ</label>
              <select id="lst-poi-edit-type" required>
                <option value="">Bitte wählen ...</option>
                <# _.each(data.poi_types || [], function(t){ #>
                  <option value="{{ t.tag }}">{{ t.tag }}</option>
                <# }); #>
              </select>
              <p id="lst-poi-edit-type-desc" class="description"></p>
            </div>

            <div class="lst-poi-field">
              <label for="lst-poi-edit-name">Name</label>
              <input type="text" id="lst-poi-edit-name" value="">
            </div>

            <div class="lst-poi-field">
              <label for="lst-poi-edit-comment">Kommentar</label>
              <textarea id="lst-poi-edit-comment" rows="3"></textarea>
            </div>

            <div class="lst-poi-field">
              <label for="lst-poi-edit-genus">Genus</label>
              <select id="lst-poi-edit-genus">
                <option value="der">der</option>
                <option value="die">die</option>
                <option value="das">das</option>
              </select>
            </div>

            <div class="lst-poi-coords">
              <div class="lst-poi-field">
                <label for="lst-poi-edit-lat">Breitengrad</label>
                <input type="number" step="0.000001" id="lst-poi-edit-lat" required>
              </div>
              <div class="lst-poi-field">
                <label for="lst-poi-edit-lon">Längengrad</label>
                <input type="number" step="0.000001" id="lst-poi-edit-lon" required>
              </div>
            </div>

            <div class="lst-poi-popup-actions">
              <button type="submit" class="button button-primary">Speichern</button>
              <button type="button" class="button button-secondary" id="lst-poi-delete-btn">Löschen</button>
            </div>
          </form>
        </div>
      </div>

      <div id="lst-poi-create-popup" class="lst-poi-popup hidden">
        <div class="lst-poi-popup-head">
          <strong>Neuen POI anlegen</strong>
          <button type="button" class="button-link" id="lst-poi-create-popup-close">×</button>
        </div>
        <div class="lst-poi-popup-body">
          <form id="lst-poi-create-form">
            <div class="lst-poi-field">
              <label for="lst-poi-create-type">Typ</label>
              <select id="lst-poi-create-type" required>
                <option value="">Bitte wählen ...</option>
                <# _.each(data.poi_types || [], function(t){ #>
                  <option value="{{ t.tag }}">{{ t.tag }}</option>
                <# }); #>
              </select>
              <p id="lst-poi-create-type-desc" class="description"></p>
            </div>

            <div class="lst-poi-field">
              <label for="lst-poi-create-name">Name</label>
              <input type="text" id="lst-poi-create-name" value="">
            </div>

            <div class="lst-poi-field">
              <label for="lst-poi-create-comment">Kommentar</label>
              <textarea id="lst-poi-create-comment" rows="3"></textarea>
            </div>

            <div class="lst-poi-field">
              <label for="lst-poi-create-genus">Genus</label>
              <select id="lst-poi-create-genus">
                <option value="der">der</option>
                <option value="die">die</option>
                <option value="das">das</option>
              </select>
            </div>

            <div class="lst-poi-coords">
              <div class="lst-poi-field">
                <label for="lst-poi-create-lat">Breitengrad</label>
                <input type="number" step="0.000001" id="lst-poi-create-lat" required>
              </div>
              <div class="lst-poi-field">
                <label for="lst-poi-create-lon">Längengrad</label>
                <input type="number" step="0.000001" id="lst-poi-create-lon" required>
              </div>
            </div>

            <div class="lst-poi-popup-actions">
              <button type="submit" class="button button-primary">Anlegen</button>
            </div>
          </form>
        </div>
      </div>

      <div class="lst-poi-overlay-bottom-right">
        <button type="button" class="button button-primary" id="lst-poi-import-open">Import</button>
      </div>
    </div>

    <div id="lst-poi-import-modal" class="lst-poi-import-modal hidden">
      <div class="lst-poi-import-card">
        <div class="lst-poi-import-head">
          <strong>POIs importieren</strong>
          <button type="button" class="button" id="lst-poi-import-close">Schließen</button>
        </div>

        <textarea id="lst-poi-import-text" rows="10"></textarea>

        <div class="lst-poi-import-actions">
          <button type="button" class="button" id="lst-poi-import-parse">Vorschau erzeugen</button>
          <button type="button" class="button button-primary" id="lst-poi-import-run" disabled>Importieren</button>
        </div>

        <div id="lst-poi-import-preview"></div>
      </div>
    </div>
  </div>
</script>
