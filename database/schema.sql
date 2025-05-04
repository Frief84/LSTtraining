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
    geojson    LONGTEXT              -- neues Feld: Polygon-GeoJSON
);

/* ------------------------------------------------------------------ */
/* 2. Wachen                                                           */
/* ------------------------------------------------------------------ */
CREATE TABLE wachen (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    leitstelle_id INT  NOT NULL,
    name         VARCHAR(255) NOT NULL,
    typ          ENUM('RW','RD','FW','FFW','FW_RD') NOT NULL,
    latitude     DOUBLE NOT NULL,
    longitude    DOUBLE NOT NULL,
    FOREIGN KEY (leitstelle_id) REFERENCES leitstellen(id) ON DELETE CASCADE
);

/* ------------------------------------------------------------------ */
/* 3. Fahrzeuge                                                        */
/* ------------------------------------------------------------------ */
CREATE TABLE fahrzeuge (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    wache_id    INT  NOT NULL,
    rufname     VARCHAR(100) NOT NULL,
    fahrzeugtyp ENUM(
        'RTW','NAW','NEF','KTW','ITW','RTH','Rettungsbus',
        'HLF','LF','DLK','TLF','ELW','ELW1','ELW2',
        'MTW','GW','GW-G','GW-Mess','GW-W','GW-L',
        'GW-San','GW-Höhenrettung','GW-Taucher','GW-Sonder',
        'Dekon-P','WSW','WLF','WLF-K','KdoW','PKW','FwK','FlKfz'
    ) NOT NULL,
    status      ENUM('frei','besetzt','einsatzbereit','nicht einsatzbereit') DEFAULT 'frei',
    fms_status  ENUM('1','2','3','4','5','6') DEFAULT '2',
    sondersignal BOOLEAN DEFAULT FALSE,
    dienstzeiten VARCHAR(255),
    latitude    DOUBLE,
    longitude   DOUBLE,
    bild_datei  VARCHAR(255),
    FOREIGN KEY (wache_id) REFERENCES wachen(id) ON DELETE CASCADE
);

/* ------------------------------------------------------------------ */
/* 4. Einsatzvorlagen (unverändert)                                   */
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
    FOREIGN KEY (leitstelle_id) REFERENCES leitstellen(id) ON DELETE CASCADE
);

/* ------------------------------------------------------------------ */
/* 5. Nebenleitstellen (unverändert)                                  */
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
    geojson           JSON
);

/* ------------------------------------------------------------------ */
/* 6. Spielinstanzen & Zuordnungen (unverändert)                       */
/* ------------------------------------------------------------------ */
CREATE TABLE spielinstanzen (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    leitstelle_id INT,
    name         VARCHAR(255),
    erstellt_am  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ist_aktiv    BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (leitstelle_id) REFERENCES leitstellen(id) ON DELETE CASCADE
);

CREATE TABLE instanz_user (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    instanz_id INT,
    user_id   INT,
    rolle     ENUM('leiter','mitspieler') DEFAULT 'mitspieler',
    connected BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (instanz_id) REFERENCES spielinstanzen(id)
);

CREATE TABLE instanz_wachen (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    instanz_id INT,
    wache_id  INT,
    ist_aktiv BOOLEAN DEFAULT TRUE,
    bemerkung TEXT,
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
    FOREIGN KEY (instanz_id)  REFERENCES spielinstanzen(id),
    FOREIGN KEY (fahrzeug_id) REFERENCES fahrzeuge(id),
    FOREIGN KEY (wache_id)    REFERENCES wachen(id)
);
