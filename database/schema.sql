/* ------------------------------------------------------------------
 * LST Training – Gesamt‑Schema mit created_at
 * Stand: 2025‑05‑14
 * ------------------------------------------------------------------ */

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

/* ------------------------------------------------------------------ */
/* 1. Leitstellen                                                     */
/* ------------------------------------------------------------------ */
CREATE TABLE leitstellen (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    ort        VARCHAR(255),
    bundesland VARCHAR(255),
    land       VARCHAR(100),
    latitude   DOUBLE,
    longitude  DOUBLE,
    geojson    LONGTEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

/* ------------------------------------------------------------------ */
/* 2. Wachen                                                           */
/* ------------------------------------------------------------------ */
CREATE TABLE `wachen` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                 VARCHAR(255) NOT NULL,
  `typ`                  VARCHAR(50)  NOT NULL DEFAULT '',
  `land`                 VARCHAR(64)  NULL     DEFAULT 'Deutschland',
  `bundesland`           VARCHAR(50)  NULL,
  `latitude`             DOUBLE       NOT NULL,
  `longitude`            DOUBLE       NOT NULL,
  `arrival_pos`          VARCHAR(50)  NULL,
  `departure_pos`        VARCHAR(50)  NULL,
  `bild_datei`           VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `exists_in_reality`    TINYINT(1)   NOT NULL DEFAULT 1,
  `placed_by_user_id`    BIGINT UNSIGNED NULL,
  `updated_by_user_id`   BIGINT UNSIGNED NULL,     -- NEU
  `updated_at`           DATETIME     NULL,         -- NEU
  `source_note`          VARCHAR(255) NULL,
  `verified_by`          BIGINT UNSIGNED NULL,
  `verified_at`          DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wachen_exists`      (`exists_in_reality`),
  KEY `idx_wachen_user`        (`placed_by_user_id`),
  KEY `idx_wachen_updated_by`  (`updated_by_user_id`),  -- NEU
  KEY `idx_wachen_updated_at`  (`updated_at`),          -- NEU
  KEY `idx_wachen_bundesland`  (`bundesland`),
  KEY `idx_wachen_land`        (`land`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ------------------------------------------------------------------ */
/* 3. Fahrzeuge                                                       */
/* ------------------------------------------------------------------ */
CREATE TABLE fahrzeuge (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    wache_id           INT NOT NULL,
    rufname            VARCHAR(100) NOT NULL,

    -- frei definierbarer Fahrzeugtyp (statt ENUM)
    fahrzeugtyp        VARCHAR(100) NOT NULL,

    -- Quelle/Herkunft der Information (z. B. BOS-Detail-/Suchseite)
    source_note        VARCHAR(255) NULL,

    -- First-Responder-Flag direkt in der Tabelle
    is_first_responder BOOLEAN NOT NULL DEFAULT FALSE,

    status             ENUM('frei','besetzt','einsatzbereit','nicht einsatzbereit') DEFAULT 'frei',
    fms_status         ENUM('1','2','3','4','5','6') DEFAULT '2',
    sondersignal       BOOLEAN DEFAULT FALSE,
    dienstzeiten       VARCHAR(255),
    latitude           DOUBLE,
    longitude          DOUBLE,
    bild_datei         VARCHAR(255),
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (wache_id) REFERENCES wachen(id) ON DELETE CASCADE,

    -- sinnvolle Indizes
    UNIQUE KEY uk_fahrzeuge_wache_rufname (wache_id, rufname),
    KEY idx_fahrzeuge_wache (wache_id),
    KEY idx_fahrzeuge_typ (fahrzeugtyp),
    KEY idx_fahrzeuge_fr (is_first_responder)
);

-- Trigger erzwingen: Rettungsmittel dürfen NICHT First Responder sein
DELIMITER //
CREATE TRIGGER bi_fahrzeuge_no_fr_for_rd
BEFORE INSERT ON fahrzeuge
FOR EACH ROW
BEGIN
  IF NEW.fahrzeugtyp IN ('RTW','NAW','NEF','KTW','ITW','RTH','Rettungsbus')
     AND NEW.is_first_responder = 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Rettungsmittel dürfen nicht First Responder sein.';
  END IF;
END//
DELIMITER ;

DELIMITER //
CREATE TRIGGER bu_fahrzeuge_no_fr_for_rd
BEFORE UPDATE ON fahrzeuge
FOR EACH ROW
BEGIN
  IF NEW.fahrzeugtyp IN ('RTW','NAW','NEF','KTW','ITW','RTH','Rettungsbus')
     AND NEW.is_first_responder = 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Rettungsmittel dürfen nicht First Responder sein.';
  END IF;
END//
DELIMITER ;

/* ------------------------------------------------------------------ */
/* 4. Einsatzvorlagen                                                  */
/* ------------------------------------------------------------------ */
CREATE TABLE einsaetze (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    leitstelle_id INT NOT NULL,
    einsatzart    ENUM('RD','FW') NOT NULL,
    einsatztyp    ENUM(
        'Notfalleinsatz','Krankentransport',
        'Brand mit Menschenrettung','Brand ohne Menschenrettung',
        'THL mit Person','THL ohne Person'
    ) NOT NULL,
    uhrzeit_fenster VARCHAR(20),
    wetter        ENUM('klar','heiß','windig','regnerisch','starkregen','schneefall','glatt','gewitter','beliebig') DEFAULT 'beliebig',
    anrufertext   TEXT NOT NULL,
    lagemeldung   TEXT NOT NULL,
    patientenzahl INT DEFAULT 0,
    patient_anforderung VARCHAR(255),
    notarzt_benoetigt   BOOLEAN DEFAULT FALSE,
    feuerwehr_benoetigt BOOLEAN DEFAULT FALSE,
    poi_tag       VARCHAR(50),
    folgeanrufe   VARCHAR(255),
    latitude      DOUBLE,
    longitude     DOUBLE,
    erstellt_am   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (leitstelle_id) REFERENCES leitstellen(id) ON DELETE CASCADE
);

/* ------------------------------------------------------------------ */
/* 5. Nebenleitstellen                                                 */
/* ------------------------------------------------------------------ */
CREATE TABLE nebenleitstellen (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(255) NOT NULL,
    aufgaben          TEXT,
    zustandigkeit     TEXT,
    standorte         TEXT,
    einwohner         INT,
    flaeche_km2       FLOAT,
    gps               VARCHAR(255),
    nachbarleitstelle BOOLEAN,
    geojson           JSON,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

/* ------------------------------------------------------------------ */
/* 6. Spielinstanzen & Zuordnungen                                     */
/* ------------------------------------------------------------------ */
CREATE TABLE spielinstanzen (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    leitstelle_id INT,
    name         VARCHAR(255),
    erstellt_am  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ist_aktiv    BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (leitstelle_id) REFERENCES leitstellen(id) ON DELETE CASCADE
);

CREATE TABLE instanz_user (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    instanz_id INT,
    user_id   INT,
    rolle     ENUM('leiter','mitspieler') DEFAULT 'mitspieler',
    connected BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (instanz_id) REFERENCES spielinstanzen(id)
);

CREATE TABLE instanz_wachen (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    instanz_id INT,
    wache_id  INT,
    ist_aktiv BOOLEAN DEFAULT TRUE,
    bemerkung TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (instanz_id) REFERENCES spielinstanzen(id),
    FOREIGN KEY (wache_id)   REFERENCES wachen(id)
);

CREATE TABLE fahrzeug_status (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    instanz_id         INT,
    fahrzeug_id        INT,
    wache_id           INT NULL,
    latitude           DOUBLE,
    longitude          DOUBLE,
    ziel_latitude      DOUBLE NULL,
    ziel_longitude     DOUBLE NULL,
    status             ENUM('frei','besetzt','einsatzbereit','nicht einsatzbereit') DEFAULT 'frei',
    fms_status         ENUM('1','2','3','4','5','6') DEFAULT '2',
    sondersignal       BOOLEAN DEFAULT FALSE,
    bemerkung          TEXT,
    letzte_aktualisierung TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (instanz_id)  REFERENCES spielinstanzen(id),
    FOREIGN KEY (fahrzeug_id) REFERENCES fahrzeuge(id),
    FOREIGN KEY (wache_id)    REFERENCES wachen(id)
);

/* ------------------------------------------------------------------ */
/* 7. Krankenhäuser                                                    */
/* ------------------------------------------------------------------ */
CREATE TABLE krankenhaeuser (
  id INT AUTO_INCREMENT PRIMARY KEY,
  poi_id VARCHAR(50) NOT NULL UNIQUE COMMENT 'Externe POI-ID',
  name VARCHAR(255) NOT NULL COMMENT 'Name des Krankenhauses',
  latitude DOUBLE NOT NULL,
  longitude DOUBLE NOT NULL,
  versorgungsstufe ENUM(
    'Grundversorgung','Schwerpunktversorger','Maximalversorger'
  ) NOT NULL DEFAULT 'Grundversorgung',
  trauma_level TINYINT NOT NULL DEFAULT 0,
  helipad BOOLEAN NOT NULL DEFAULT FALSE,
  departments JSON NOT NULL,
  last_update TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/* ------------------------------------------------------------------ */
SET foreign_key_checks = 1;



-- Tabelle für LST-Benutzer-Berechtigungen (inkl. Nebenstellen)
CREATE TABLE IF NOT EXISTS `wp_lst_user_permissions` (
  `user_id`               BIGINT(20) UNSIGNED NOT NULL,
  `can_edit_leitstellen`  TINYINT(1)      NOT NULL DEFAULT 0,
  `can_edit_nebenstellen` TINYINT(1)      NOT NULL DEFAULT 0,
  `can_edit_hospitals`    TINYINT(1)      NOT NULL DEFAULT 0,
  `can_edit_wachen`       TINYINT(1)      NOT NULL DEFAULT 0,
  `can_edit_fahrzeuge`    TINYINT(1)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_lst_user_permissions_user`
    FOREIGN KEY (`user_id`)
    REFERENCES `wp_users` (`ID`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Beziehung Wache ⇆ Leitstelle
CREATE TABLE `wache_leitstellen` (
  `wache_id`      INT NOT NULL,
  `leitstelle_id` INT NOT NULL,
  PRIMARY KEY (`wache_id`, `leitstelle_id`),
  INDEX (`leitstelle_id`),
  FOREIGN KEY (`wache_id`)      REFERENCES `wachen`(`id`)       ON DELETE CASCADE,
  FOREIGN KEY (`leitstelle_id`) REFERENCES `leitstellen`(`id`) ON DELETE CASCADE
);

-- Beziehung Wache ⇆ Nebenleitstelle
CREATE TABLE `wache_nebenleitstellen` (
  `wache_id`           INT NOT NULL,
  `nebenleitstelle_id` INT NOT NULL,
  PRIMARY KEY (`wache_id`, `nebenleitstelle_id`),
  INDEX (`nebenleitstelle_id`),
  FOREIGN KEY (`wache_id`)           REFERENCES `wachen`(`id`)            ON DELETE CASCADE,
  FOREIGN KEY (`nebenleitstelle_id`) REFERENCES `nebenleitstellen`(`id`) ON DELETE CASCADE
);


-- Audit-Log für LST-Training
CREATE TABLE IF NOT EXISTS `lst_activity_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ts`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id`       BIGINT UNSIGNED NULL,
  `entity_type`   VARCHAR(64) NOT NULL,               -- z. B. 'leitstelle','nebenstelle','hospital','wache','fahrzeug','permission'
  `entity_id`     BIGINT UNSIGNED NULL,               -- betroffene ID, falls vorhanden
  `action`        VARCHAR(16) NOT NULL,               -- 'create','update','delete','permission_change','login', ...
  `ip`            VARBINARY(16) NULL,                 -- IPv4/IPv6 binär (optional)
  `user_agent`    VARCHAR(255) NULL,
  `meta_json`     TEXT NULL,                          -- Details als JSON (klein halten)
  PRIMARY KEY (`id`),
  KEY `idx_ts` (`ts`),
  KEY `idx_user` (`user_id`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;