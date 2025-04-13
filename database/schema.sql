-- Datenbank auswählen oder erstellen
-- CREATE DATABASE lsttraining CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE lsttraining;

-- 1. Leitstellen
CREATE TABLE leitstellen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    ort VARCHAR(255),
    bundesland VARCHAR(255),
    land VARCHAR(100),
    latitude DOUBLE,
    longitude DOUBLE
);

-- 2. Wachen
CREATE TABLE wachen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    leitstelle_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    typ ENUM('RW', 'RD', 'FW', 'FFW', 'FW_RD') NOT NULL,
    latitude DOUBLE NOT NULL,
    longitude DOUBLE NOT NULL,
    FOREIGN KEY (leitstelle_id) REFERENCES leitstellen(id) ON DELETE CASCADE
);

-- 3. Fahrzeuge
CREATE TABLE fahrzeuge (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wache_id INT NOT NULL,
    rufname VARCHAR(100) NOT NULL,
    fahrzeugtyp ENUM(
        'RTW', 'NAW', 'NEF', 'KTW', 'ITW', 'RTH', 'Rettungsbus',
        'HLF', 'LF', 'DLK', 'TLF', 'ELW', 'ELW1', 'ELW2',
        'MTW', 'GW', 'GW-G', 'GW-Mess', 'GW-W', 'GW-L',
        'GW-San', 'GW-Höhenrettung', 'GW-Taucher', 'GW-Sonder',
        'Dekon-P', 'WSW', 'WLF', 'WLF-K', 'KdoW', 'PKW', 'FwK', 'FlKfz'
    ) NOT NULL,
    status ENUM('frei', 'besetzt', 'einsatzbereit', 'nicht einsatzbereit') DEFAULT 'frei',
    fms_status ENUM('1','2','3','4','5','6') DEFAULT '2',
    sondersignal BOOLEAN DEFAULT FALSE,
    dienstzeiten VARCHAR(255),
    latitude DOUBLE,
    longitude DOUBLE,
    bild_datei VARCHAR(255),
    FOREIGN KEY (wache_id) REFERENCES wachen(id) ON DELETE CASCADE
);

-- 4. Einsatzgebiete (Polygon)
CREATE TABLE einsatzgebiete (
    id INT AUTO_INCREMENT PRIMARY KEY,
    leitstelle_id INT NOT NULL,
    bezeichnung VARCHAR(255),
    polygon GEOMETRY NOT NULL,
    FOREIGN KEY (leitstelle_id) REFERENCES leitstellen(id) ON DELETE CASCADE
);

-- 5. Einsatzvorlagen
CREATE TABLE einsaetze (
    id INT AUTO_INCREMENT PRIMARY KEY,
    leitstelle_id INT NOT NULL,

    einsatzart ENUM('RD', 'FW') NOT NULL,
    einsatztyp ENUM(
        'Notfalleinsatz',
        'Krankentransport',
        'Brand mit Menschenrettung',
        'Brand ohne Menschenrettung',
        'THL mit Person',
        'THL ohne Person'
    ) NOT NULL,

    uhrzeit_fenster VARCHAR(20),
    wetter ENUM('klar', 'heiß', 'windig', 'regnerisch', 'schneefall', 'glatt', 'gewitter', 'beliebig') DEFAULT 'beliebig',

    anrufertext TEXT NOT NULL,
    lagemeldung TEXT NOT NULL,
    patientenzahl INT DEFAULT 0,
    patient_anforderung VARCHAR(255),
    notarzt_benoetigt BOOLEAN DEFAULT FALSE,
    feuerwehr_benoetigt BOOLEAN DEFAULT FALSE,

    poi_tag VARCHAR(50),
    folgeanrufe VARCHAR(255),
    latitude DOUBLE,
    longitude DOUBLE,

    erstellt_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (leitstelle_id) REFERENCES leitstellen(id) ON DELETE CASCADE
);
