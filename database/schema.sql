/* ------------------------------------------------------------------
 * LST Training – Gesamt-Schema (extern, neutrale Tabellennamen)
 * + Einsatzsystem (Vorlagen + Regeln + Instanz-Einsätze + OSM Tile System)
 *
 * Stand: 2026-03-24
 * ------------------------------------------------------------------ */

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- -------------------------------------------------------------------
-- 1) Stammdaten: Leitstellen / POIs / Nebenleitstellen
-- -------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `leitstellen` (
  `id`                  INT NOT NULL AUTO_INCREMENT,
  `name`                VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL,
  `ort`                 VARCHAR(255) COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `bundesland`          VARCHAR(255) COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `land`                VARCHAR(100) COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `latitude`            DOUBLE NULL DEFAULT NULL,
  `longitude`           DOUBLE NULL DEFAULT NULL,
  `geojson`             LONGTEXT COLLATE utf8mb4_general_ci NULL,
  `available_hospitals` TEXT COLLATE utf8mb4_general_ci NOT NULL
    COMMENT 'JSON-Array mit IDs der freigeschalteten Krankenhäuser',
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `leitstellen_pois` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `leitstelle_id` INT NOT NULL,

  `poi_type`      VARCHAR(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `name`          VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `comment`       TEXT         COLLATE utf8mb4_unicode_ci NULL,
  `genus`         ENUM('der','die','das') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'der',

  `latitude`      DOUBLE NOT NULL,
  `longitude`     DOUBLE NOT NULL,

  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_ls_pois_ls` (`leitstelle_id`),
  KEY `idx_ls_pois_type` (`poi_type`),
  KEY `idx_ls_pois_latlon` (`latitude`, `longitude`),

  CONSTRAINT `fk_ls_pois_leitstelle`
    FOREIGN KEY (`leitstelle_id`) REFERENCES `leitstellen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nebenleitstellen` (
  `id`                INT NOT NULL AUTO_INCREMENT,
  `name`              VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL,
  `aufgaben`          TEXT COLLATE utf8mb4_general_ci NULL,
  `zustandigkeit`     TEXT COLLATE utf8mb4_general_ci NULL,
  `standorte`         TEXT COLLATE utf8mb4_general_ci NULL,
  `einwohner`         INT NULL DEFAULT NULL,
  `flaeche_km2`       FLOAT NULL DEFAULT NULL,
  `gps`               VARCHAR(255) COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `nachbarleitstelle` TINYINT(1) NULL DEFAULT NULL,
  `geojson`           JSON NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------
-- 2) Wachen / Zuordnungen
-- -------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `wachen` (
  `id`                 INT NOT NULL AUTO_INCREMENT,
  `name`               VARCHAR(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `typ`                VARCHAR(50)  COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `bundesland`         VARCHAR(50)  COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL,
  `latitude`           DOUBLE NOT NULL,
  `longitude`          DOUBLE NOT NULL,
  `land`               VARCHAR(64)  COLLATE utf8mb4_0900_ai_ci NULL DEFAULT 'Deutschland',
  `arrival_pos`        VARCHAR(50)  COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL,
  `departure_pos`      VARCHAR(50)  COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL,
  `bild_datei`         VARCHAR(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `exists_in_reality`  TINYINT(1) NOT NULL DEFAULT 1,
  `placed_by_user_id`  BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_at`         DATETIME NULL DEFAULT NULL,
  `source_note`        VARCHAR(255) COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL,
  `verified_by`        BIGINT UNSIGNED NULL DEFAULT NULL,
  `verified_at`        DATETIME NULL DEFAULT NULL,

  PRIMARY KEY (`id`),

  KEY `bundesland` (`bundesland`),
  KEY `land` (`land`),
  KEY `exists_in_reality` (`exists_in_reality`),
  KEY `placed_by_user_id` (`placed_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `wache_leitstellen` (
  `wache_id`      INT NOT NULL,
  `leitstelle_id` INT NOT NULL,
  PRIMARY KEY (`wache_id`, `leitstelle_id`),
  KEY `idx_wl_leitstelle` (`leitstelle_id`),

  CONSTRAINT `fk_wl_wache`
    FOREIGN KEY (`wache_id`) REFERENCES `wachen`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wl_leitstelle`
    FOREIGN KEY (`leitstelle_id`) REFERENCES `leitstellen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `wache_nebenleitstellen` (
  `wache_id`           INT NOT NULL,
  `nebenleitstelle_id` INT NOT NULL,
  PRIMARY KEY (`wache_id`, `nebenleitstelle_id`),
  KEY `idx_wn_nebenleitstelle` (`nebenleitstelle_id`),

  CONSTRAINT `fk_wn_wache`
    FOREIGN KEY (`wache_id`) REFERENCES `wachen`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wn_nebenleitstelle`
    FOREIGN KEY (`nebenleitstelle_id`) REFERENCES `nebenleitstellen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------
-- 3) Fahrzeuge + Live/Instanz-Status
-- -------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `fahrzeuge` (
  `id`                 INT NOT NULL AUTO_INCREMENT,
  `wache_id`           INT NOT NULL,
  `rufname`            VARCHAR(100) COLLATE utf8mb4_general_ci NOT NULL,
  `fahrzeugtyp`        VARCHAR(100) COLLATE utf8mb4_general_ci NOT NULL,
  `source_note`        VARCHAR(255) COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `is_first_responder` TINYINT(1) NOT NULL DEFAULT 0,
  `status`             ENUM('frei','besetzt','einsatzbereit','nicht einsatzbereit')
                       COLLATE utf8mb4_general_ci NULL DEFAULT 'frei',
  `fms_status`         ENUM('1','2','3','4','5','6')
                       COLLATE utf8mb4_general_ci NULL DEFAULT '2',
  `sondersignal`       TINYINT(1) NULL DEFAULT 0,
  `dienstzeiten`       VARCHAR(255) COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `latitude`           DOUBLE NULL DEFAULT NULL,
  `longitude`          DOUBLE NULL DEFAULT NULL,
  `bild_datei`         VARCHAR(255) COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `wache_id` (`wache_id`),
  KEY `rufname` (`rufname`),

  CONSTRAINT `fk_fahrzeuge_wache`
    FOREIGN KEY (`wache_id`) REFERENCES `wachen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `spielinstanzen` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `leitstelle_id` INT NULL DEFAULT NULL,
  `name`          VARCHAR(255) COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `erstellt_am`   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `ist_aktiv`     TINYINT(1) NULL DEFAULT 1,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `leitstelle_id` (`leitstelle_id`),

  CONSTRAINT `fk_spielinstanzen_leitstelle`
    FOREIGN KEY (`leitstelle_id`) REFERENCES `leitstellen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `instanz_user` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `instanz_id` INT NULL DEFAULT NULL,
  `user_id`    INT NULL DEFAULT NULL,
  `rolle`      ENUM('leiter','mitspieler') COLLATE utf8mb4_general_ci NULL DEFAULT 'mitspieler',
  `connected`  TINYINT(1) NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `instanz_id` (`instanz_id`),

  CONSTRAINT `fk_instanz_user_instanz`
    FOREIGN KEY (`instanz_id`) REFERENCES `spielinstanzen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `fahrzeug_status` (
  `id`                    INT NOT NULL AUTO_INCREMENT,
  `instanz_id`            INT NULL DEFAULT NULL,
  `fahrzeug_id`           INT NULL DEFAULT NULL,
  `wache_id`              INT NULL DEFAULT NULL,
  `latitude`              DOUBLE NULL DEFAULT NULL,
  `longitude`             DOUBLE NULL DEFAULT NULL,
  `ziel_latitude`         DOUBLE NULL DEFAULT NULL,
  `ziel_longitude`        DOUBLE NULL DEFAULT NULL,
  `status`                ENUM('frei','besetzt','einsatzbereit','nicht einsatzbereit')
                         COLLATE utf8mb4_general_ci NULL DEFAULT 'frei',
  `fms_status`            ENUM('1','2','3','4','5','6')
                         COLLATE utf8mb4_general_ci NULL DEFAULT '2',
  `sondersignal`          TINYINT(1) NULL DEFAULT 0,
  `bemerkung`             TEXT COLLATE utf8mb4_general_ci NULL,
  `letzte_aktualisierung` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `instanz_id` (`instanz_id`),
  KEY `fahrzeug_id` (`fahrzeug_id`),
  KEY `wache_id` (`wache_id`),

  CONSTRAINT `fk_fahrzeug_status_instanz`
    FOREIGN KEY (`instanz_id`) REFERENCES `spielinstanzen`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fahrzeug_status_fahrzeug`
    FOREIGN KEY (`fahrzeug_id`) REFERENCES `fahrzeuge`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fahrzeug_status_wache`
    FOREIGN KEY (`wache_id`) REFERENCES `wachen`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------
-- 4) Krankenhäuser
-- -------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `krankenhaeuser` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `poi_id`           VARCHAR(50) COLLATE utf8mb4_general_ci NOT NULL UNIQUE COMMENT 'Externe POI-ID',
  `name`             VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Name des Krankenhauses',
  `latitude`         DOUBLE NOT NULL,
  `longitude`        DOUBLE NOT NULL,
  `versorgungsstufe` ENUM('Grundversorgung','Schwerpunktversorger','Maximalversorger')
                    COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Grundversorgung',
  `trauma_level`     TINYINT NOT NULL DEFAULT 0,
  `helipad`          TINYINT(1) NOT NULL DEFAULT 0,
  `departments`      JSON NOT NULL,
  `last_update`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------
-- 5) Rechte + Audit
-- -------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `user_permissions` (
  `user_id`               BIGINT UNSIGNED NOT NULL,
  `can_edit_leitstellen`  TINYINT(1) NOT NULL DEFAULT 0,
  `can_edit_nebenstellen` TINYINT(1) NOT NULL DEFAULT 0,
  `can_edit_hospitals`    TINYINT(1) NOT NULL DEFAULT 0,
  `can_edit_wachen`       TINYINT(1) NOT NULL DEFAULT 0,
  `can_edit_fahrzeuge`    TINYINT(1) NOT NULL DEFAULT 0,
  `leitstellen_ids`       TEXT COLLATE utf8mb4_unicode_ci NOT NULL
    COMMENT 'Kommagetrennte Liste von Leitstellen‐IDs, die der Benutzer bearbeiten darf',
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lst_activity_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ts`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id`     BIGINT UNSIGNED NULL DEFAULT NULL,
  `entity_type` VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id`   BIGINT UNSIGNED NULL DEFAULT NULL,
  `action`      VARCHAR(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip`          VARBINARY(16) NULL DEFAULT NULL,
  `user_agent`  VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `meta_json`   TEXT COLLATE utf8mb4_unicode_ci NULL,

  PRIMARY KEY (`id`),
  KEY `ts` (`ts`),
  KEY `user_id` (`user_id`),
  KEY `entity_type` (`entity_type`),
  KEY `entity_id` (`entity_id`),
  KEY `action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 6) Einsatzsystem
-- -------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `einsaetze` (
  `id` INT NOT NULL AUTO_INCREMENT,

  `title` VARCHAR(255) NULL,

  `einsatzart` ENUM('RD','FW') NOT NULL,
  `einsatztyp` VARCHAR(100) NOT NULL,

  `enabled` TINYINT(1) NOT NULL DEFAULT 1,

  `wetter_mask_json` TEXT NULL,
  `uhrzeit_fenster`  VARCHAR(32) NULL,

  `scope_type` ENUM('anywhere','landscape','poi_type','fixed_point') NOT NULL DEFAULT 'anywhere',

  `landscape_tags_json` TEXT NULL,
  `poi_type` VARCHAR(50) NULL,

  `fixed_latitude`  DOUBLE NULL,
  `fixed_longitude` DOUBLE NULL,
  `fixed_radius_m`  INT NULL,

  `caller_who`   TEXT NOT NULL,
  `caller_where` TEXT NOT NULL,
  `caller_what`  TEXT NOT NULL,

  `anrufertext`  TEXT NULL,
  `lagemeldung`  TEXT NOT NULL,

  `patientenzahl` INT NULL DEFAULT 0,
  `patient_anforderung` VARCHAR(255) NULL,
  `notarzt_benoetigt` TINYINT(1) NULL DEFAULT 0,
  `feuerwehr_benoetigt` TINYINT(1) NULL DEFAULT 0,
  `poi_tag` VARCHAR(50) NULL,

  `weight_base` INT NOT NULL DEFAULT 100,

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_einsaetze_enabled` (`enabled`),
  KEY `idx_einsaetze_art_typ` (`einsatzart`,`einsatztyp`),
  KEY `idx_einsaetze_scope` (`scope_type`),
  KEY `idx_einsaetze_poi_type` (`poi_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `einsatz_excluded_leitstellen` (
  `einsatz_id`    INT NOT NULL,
  `leitstelle_id` INT NOT NULL,

  PRIMARY KEY (`einsatz_id`,`leitstelle_id`),
  KEY `idx_eel_leitstelle` (`leitstelle_id`),

  CONSTRAINT `fk_eel_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `einsaetze`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eel_leitstelle`
    FOREIGN KEY (`leitstelle_id`) REFERENCES `leitstellen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `einsatz_followups` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `einsatz_id` INT NOT NULL,

  `step_no` INT NOT NULL,
  `kind` ENUM('dispatcher_question','caller_answer','update','unit_report') NOT NULL DEFAULT 'update',
  `text` TEXT NOT NULL,

  `min_after_sec` INT NULL,
  `max_after_sec` INT NULL,
  `condition_json` TEXT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_followups_einsatz_step` (`einsatz_id`,`step_no`),
  KEY `idx_followups_einsatz` (`einsatz_id`),

  CONSTRAINT `fk_followups_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `einsaetze`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `einsatz_rules` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,

  `enabled` TINYINT(1) NOT NULL DEFAULT 1,

  `einsatzart` ENUM('RD','FW') NOT NULL,
  `einsatztyp` VARCHAR(100) NOT NULL,

  `wetter_mask_json` TEXT NULL,
  `uhrzeit_fenster`  VARCHAR(32) NULL,

  `scope_type` ENUM('anywhere','landscape','poi_type','fixed_point') NOT NULL DEFAULT 'anywhere',
  `landscape_tags_json` TEXT NULL,
  `poi_type` VARCHAR(50) NULL,

  `fixed_latitude`  DOUBLE NULL,
  `fixed_longitude` DOUBLE NULL,
  `fixed_radius_m`  INT NULL,

  `spawn_radius_min_m` INT NULL,
  `spawn_radius_max_m` INT NULL,

  `caller_template_json` TEXT NOT NULL,
  `followups_template_json` TEXT NULL,

  `weight_base` INT NOT NULL DEFAULT 100,

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_rules_enabled` (`enabled`),
  KEY `idx_rules_art_typ` (`einsatzart`,`einsatztyp`),
  KEY `idx_rules_scope` (`scope_type`),
  KEY `idx_rules_poi_type` (`poi_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `instanz_einsaetze` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  `instanz_id` INT NOT NULL,
  `leitstelle_id` INT NOT NULL,

  `source` ENUM('template','rule') NOT NULL,
  `source_id` INT NOT NULL,

  `einsatzart` ENUM('RD','FW') NOT NULL,
  `einsatztyp` VARCHAR(100) NOT NULL,

  `weather` VARCHAR(20) NULL,
  `uhrzeit_fenster` VARCHAR(32) NULL,

  `latitude` DOUBLE NOT NULL,
  `longitude` DOUBLE NOT NULL,

  `poi_type` VARCHAR(50) NULL,
  `poi_name_snapshot` VARCHAR(255) NULL,

  `caller_text` TEXT NOT NULL,
  `lagemeldung` TEXT NOT NULL,

  `state` ENUM('new','active','closed') NOT NULL DEFAULT 'new',
  `meta_json` TEXT NULL,

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_ie_instanz` (`instanz_id`),
  KEY `idx_ie_leitstelle` (`leitstelle_id`),
  KEY `idx_ie_state` (`state`),
  KEY `idx_ie_source` (`source`,`source_id`),

  CONSTRAINT `fk_ie_instanz`
    FOREIGN KEY (`instanz_id`) REFERENCES `spielinstanzen`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ie_leitstelle`
    FOREIGN KEY (`leitstelle_id`) REFERENCES `leitstellen`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `instanz_einsatz_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instanz_einsatz_id` BIGINT UNSIGNED NOT NULL,

  `ts` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  `kind` ENUM('dispatcher_question','caller_answer','update','unit_report','system') NOT NULL DEFAULT 'system',
  `text` TEXT NOT NULL,

  `meta_json` TEXT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_iee_einsatz` (`instanz_einsatz_id`),
  KEY `idx_iee_ts` (`ts`),

  CONSTRAINT `fk_iee_einsatz`
    FOREIGN KEY (`instanz_einsatz_id`) REFERENCES `instanz_einsaetze`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 7) OSM TILE SYSTEM
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `leitstellen_osm_layers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `layer_key` VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` VARCHAR(64) COLLATE utf8mb4_unicode_ci DEFAULT 'offline_tiles',
  `source_version` VARCHAR(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,

  `tile_z` SMALLINT DEFAULT NULL,
  `tile_x` INT DEFAULT NULL,
  `tile_y` INT DEFAULT NULL,

  `sha1` CHAR(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `feature_count` INT DEFAULT NULL,
  `bytes_gz` INT DEFAULT NULL,
  `file_relpath` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,

  `last_checked_at` DATETIME DEFAULT NULL,
  `last_changed_at` DATETIME DEFAULT NULL,
  `last_check_source` VARCHAR(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `check_status` VARCHAR(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `check_message` TEXT COLLATE utf8mb4_unicode_ci,
  `etag_or_signature` VARCHAR(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_dirty` TINYINT(1) NOT NULL DEFAULT 0,

  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tile` (`layer_key`,`tile_z`,`tile_x`,`tile_y`),
  KEY `idx_tile_xyz` (`tile_z`,`tile_x`,`tile_y`),
  KEY `idx_layer_version` (`layer_key`,`source_version`),
  KEY `idx_last_checked_at` (`last_checked_at`),
  KEY `idx_last_changed_at` (`last_changed_at`),
  KEY `idx_check_status` (`check_status`),
  KEY `idx_dirty` (`is_dirty`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `leitstelle_tile_scope` (
  `leitstelle_id` INT NOT NULL,
  `layer_key` VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tile_z` SMALLINT NOT NULL,
  `tile_x` INT NOT NULL,
  `tile_y` INT NOT NULL,

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`leitstelle_id`,`layer_key`,`tile_z`,`tile_x`,`tile_y`),
  KEY `idx_scope_leitstelle` (`leitstelle_id`),
  KEY `idx_scope_tile` (`layer_key`,`tile_z`,`tile_x`,`tile_y`),

  CONSTRAINT `fk_ltscope_leitstelle`
    FOREIGN KEY (`leitstelle_id`) REFERENCES `leitstellen`(`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leitstelle_osm_update_lock` (
  `leitstelle_id` INT NOT NULL,
  `layer_key` VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lock_token` VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `locked_by` VARCHAR(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lock_until` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`leitstelle_id`,`layer_key`),
  KEY `idx_lock_until` (`lock_until`),

  CONSTRAINT `fk_loul_leitstelle`
    FOREIGN KEY (`leitstelle_id`) REFERENCES `leitstellen`(`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;


CREATE TABLE IF NOT EXISTS `leitstelle_layer_sync_state` (
  `leitstelle_id` INT NOT NULL,
  `layer_key` VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,

  -- aktuelle Phase des Syncs
  `phase` ENUM('idle','scan','download') NOT NULL DEFAULT 'idle',

  -- Zeitfenster für Änderungsprüfung
  `scan_since` DATETIME DEFAULT NULL,
  `scan_osm_base` DATETIME DEFAULT NULL,

  -- Scan-Konfiguration / Fortschritt
  `scan_start_z` SMALLINT DEFAULT NULL,
  `scan_cursor_json` LONGTEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,

  -- Fortschritt Download-Phase
  `dirty_total` INT NOT NULL DEFAULT 0,
  `dirty_done` INT NOT NULL DEFAULT 0,

  -- letzter erfolgreicher Lauf
  `last_success_at` DATETIME DEFAULT NULL,
  `last_success_osm_base` DATETIME DEFAULT NULL,

  -- Fehlerdiagnose
  `last_error` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,

  -- Standard-Timestamps
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`leitstelle_id`, `layer_key`),
  KEY `idx_phase` (`phase`),
  KEY `idx_last_success` (`last_success_at`),

  CONSTRAINT `fk_llss_leitstelle`
    FOREIGN KEY (`leitstelle_id`)
    REFERENCES `leitstellen`(`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;