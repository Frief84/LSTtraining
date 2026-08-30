<?php
/**
 * Integrierte Hilfe- und Dokumentationsseite.
 */

if (!defined('ABSPATH')) { exit; }
if (!current_user_can('read')) {
    wp_die(esc_html__('Du hast keine ausreichenden Rechte, um diese Seite aufzurufen.', 'lsttraining'));
}

$is_admin = current_user_can('manage_options');
$can_manage_content = $is_admin
    || lsttraining_user_can('leitstellen')
    || lsttraining_user_can('nebenstellen')
    || lsttraining_user_can('hospitals')
    || lsttraining_user_can('wachen')
    || lsttraining_user_can('fahrzeuge');
$docs_page_configured = absint(get_option('lsttraining_docs_page_id', 0)) > 0;
$player_docs_url = $docs_page_configured
    ? lsttraining_documentation_page_url('spielerhandbuch')
    : 'https://github.com/Frief84/LSTtraining/blob/main/docs/spielerhandbuch.md';
$wiki_url = $docs_page_configured
    ? lsttraining_documentation_page_url()
    : 'https://github.com/Frief84/LSTtraining/blob/main/docs/README.md';
$security_docs_url = $docs_page_configured
    ? lsttraining_documentation_page_url('sicherheit-migration-multiplayer')
    : 'https://github.com/Frief84/LSTtraining/blob/main/docs/sicherheit-migration-multiplayer.md';
$management_api_url = $docs_page_configured
    ? lsttraining_documentation_page_url('rest-management-api')
    : 'https://github.com/Frief84/LSTtraining/blob/main/docs/rest-management-api.md';
$rest_api_guide_url = $docs_page_configured
    ? lsttraining_documentation_page_url('rest-api-praxis')
    : 'https://github.com/Frief84/LSTtraining/blob/main/docs/rest-api-praxis.md';
$status_api_url = $docs_page_configured
    ? lsttraining_documentation_page_url('rest-status-api')
    : 'https://github.com/Frief84/LSTtraining/blob/main/docs/rest-status-api.md';
$editor_documents = [];
$documentation_catalog = lsttraining_documentation_catalog();
foreach ([
    'leitstellen-editor',
    'nebenleitstellen-editor',
    'krankenhaeuser-editor',
    'wachen-editor',
    'fahrzeuge-editor',
    'polizei-und-unterstuetzungsfahrzeuge',
] as $editor_slug) {
    if (!isset($documentation_catalog[$editor_slug]) || !lsttraining_documentation_can_view($documentation_catalog[$editor_slug])) {
        continue;
    }
    $editor_document = $documentation_catalog[$editor_slug];
    $editor_document['url'] = $docs_page_configured
        ? lsttraining_documentation_page_url($editor_slug)
        : 'https://github.com/Frief84/LSTtraining/blob/main/' . ltrim((string) $editor_document['path'], '/');
    $editor_documents[] = $editor_document;
}
?>
<div class="wrap lsttraining-help">
    <h1><?php esc_html_e('LST Training – Hilfe & Dokumentation', 'lsttraining'); ?></h1>
    <p class="description">
        <?php esc_html_e('Kurzanleitung für den sicheren Betrieb, die Benutzerrechte und die Multiplayer-Simulation.', 'lsttraining'); ?>
    </p>

    <div class="notice notice-info inline">
        <p><strong><?php esc_html_e('Grundregel:', 'lsttraining'); ?></strong>
            <?php if ($can_manage_content) : ?>
                <?php esc_html_e('Benutzer sehen und bearbeiten nur die Bereiche und Leitstellen, die ihnen ausdrücklich freigegeben wurden.', 'lsttraining'); ?>
            <?php else : ?>
                <?php esc_html_e('Diese Ansicht enthält nur die Spielerhilfe. Verwaltungs-, Datenbank-, Rechte- und API-Dokumentation ist Administratoren beziehungsweise berechtigten Bearbeitern vorbehalten.', 'lsttraining'); ?>
            <?php endif; ?>
        </p>
    </div>

    <style>
        .lsttraining-help .lst-help-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(290px,1fr)); gap:16px; margin:20px 0; }
        .lsttraining-help .lst-help-card { background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:18px; box-shadow:0 1px 1px rgba(0,0,0,.04); }
        .lsttraining-help .lst-help-card h2 { margin-top:0; font-size:18px; }
        .lsttraining-help .lst-help-card h3 { font-size:14px; margin-bottom:4px; }
        .lsttraining-help .lst-help-card ul, .lsttraining-help .lst-help-card ol { margin-left:20px; }
        .lsttraining-help .lst-help-wide { grid-column:1 / -1; }
        .lsttraining-help table { margin-top:12px; }
        .lsttraining-help code { white-space:normal; }
        .lsttraining-help pre { overflow:auto; padding:14px; color:#f0f0f1; background:#1d2327; border-radius:4px; }
        .lsttraining-help pre code { white-space:pre; color:inherit; }
        .lsttraining-help details { margin:12px 0; padding:10px 12px; border:1px solid #dcdcde; border-radius:4px; background:#f6f7f7; }
        .lsttraining-help details summary { cursor:pointer; font-weight:600; }
        .lsttraining-help .lst-help-api-table th { white-space:nowrap; }
        .lsttraining-help .lst-help-api-table td code { overflow-wrap:anywhere; }
    </style>

    <div class="lst-help-grid">
        <section class="lst-help-card">
            <h2><?php esc_html_e('Schnellstart', 'lsttraining'); ?></h2>
            <?php if ($can_manage_content) : ?>
                <ol>
                    <li><?php esc_html_e('Leitstelle und Einsatzgebiet anlegen.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Wachen zuordnen und Fahrzeuge einrichten.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Krankenhäuser, Einsatzvorlagen und Anruferprofile ergänzen.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Simulation starten oder einen gespeicherten Spielstand fortsetzen.', 'lsttraining'); ?></li>
                </ol>
                <p><?php esc_html_e('Welche Menüpunkte sichtbar sind, hängt von den persönlichen Freigaben ab.', 'lsttraining'); ?></p>
            <?php else : ?>
                <ol>
                    <li><?php esc_html_e('Auf der Simulationsseite eine freigegebene Leitstelle auswählen.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Datum, Uhrzeit, Jahreszeit und Spielmodus festlegen.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Eine neue Simulation starten, ein Spiel fortsetzen oder einem offenen Multiplayer-Spiel beitreten.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Im Workspace Anrufe annehmen, Einsätze disponieren und Funkmeldungen bearbeiten.', 'lsttraining'); ?></li>
                </ol>
            <?php endif; ?>
        </section>

        <?php if ($can_manage_content) : ?>
            <section class="lst-help-card">
                <h2><?php esc_html_e('Fahrzeuge und Wachen verschieben', 'lsttraining'); ?></h2>
                <p><?php esc_html_e('Das Verschieben in einen anderen Leitstellenbereich ist erlaubt, wenn der Benutzer für den bisherigen und den vollständigen neuen Bereich berechtigt ist.', 'lsttraining'); ?></p>
                <p><?php esc_html_e('Fehlt eine dieser Freigaben, lehnt der Server die Änderung ab – unabhängig davon, was die Oberfläche anzeigt.', 'lsttraining'); ?></p>
            </section>
        <?php endif; ?>

        <?php if ($editor_documents) : ?>
            <section class="lst-help-card lst-help-wide">
                <h2><?php esc_html_e('Backend-Editoren', 'lsttraining'); ?></h2>
                <p><?php esc_html_e('Hier erscheinen nur die Editor-Anleitungen, für deren Verwaltungsbereich dein Konto freigeschaltet ist.', 'lsttraining'); ?></p>
                <div class="lst-help-grid">
                    <?php foreach ($editor_documents as $editor_document) : ?>
                        <article class="lst-help-card">
                            <h3><?php echo esc_html((string) $editor_document['title']); ?></h3>
                            <p><?php echo esc_html((string) $editor_document['description']); ?></p>
                            <p><a class="button button-secondary" href="<?php echo esc_url((string) $editor_document['url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Anleitung öffnen', 'lsttraining'); ?></a></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="lst-help-card">
            <h2><?php esc_html_e('Multiplayer', 'lsttraining'); ?></h2>
            <p><?php esc_html_e('Pro Spielinstanz schreibt immer nur ein Tick gleichzeitig den Simulationsfortschritt fort. Dadurch entstehen bei mehreren geöffneten Browsern keine doppelten automatischen Einsätze.', 'lsttraining'); ?></p>
            <p><?php esc_html_e('Pausieren, Geschwindigkeit ändern und Einsätze erzwingen dürfen nur Administratoren oder Einsatzleiter.', 'lsttraining'); ?></p>
        </section>

        <section class="lst-help-card">
            <h2><?php esc_html_e('Live-Snapshot', 'lsttraining'); ?></h2>
            <p><?php esc_html_e('Das regelmäßige Abrufen des Snapshots verändert den Spielstand nicht.', 'lsttraining'); ?></p>
            <p><?php esc_html_e('Positionen werden nur übertragen, wenn ein Fahrzeug von seiner Startposition oder deutlich von seiner Wache abweicht. Frühere Bewegungen und Ziele gehören nicht zum Positions-Snapshot.', 'lsttraining'); ?></p>
        </section>

        <section class="lst-help-card">
            <h2><?php esc_html_e('Gespeicherte Spiele', 'lsttraining'); ?></h2>
            <ul>
                <li><?php esc_html_e('„Simulation starten“ erzeugt einen neuen Spielstand.', 'lsttraining'); ?></li>
                <li><?php esc_html_e('„Fortsetzen“ öffnet einen vorhandenen Spielstand.', 'lsttraining'); ?></li>
                <li><?php esc_html_e('Der Verantwortliche oder ein Administrator darf gemeinsam genutzte Spiele löschen.', 'lsttraining'); ?></li>
                <li><?php esc_html_e('Teilnehmer können ein gemeinsames Spiel verlassen, ohne es für andere zu löschen.', 'lsttraining'); ?></li>
            </ul>
        </section>

        <?php if ($can_manage_content) : ?>
            <section class="lst-help-card">
                <h2><?php esc_html_e('Sicherheitsregeln', 'lsttraining'); ?></h2>
                <ul>
                    <li><?php esc_html_e('Änderungen und Löschungen werden ausschließlich als geschützte POST-Anfragen verarbeitet.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Jede Aktion prüft Anmeldung, Sicherheits-Token und konkrete Objektberechtigung.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Nicht zugeordnete Objekte sind für Nicht-Administratoren gesperrt.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Gemeinsam zugeordnete Objekte benötigen die Freigabe für alle betroffenen Leitstellen.', 'lsttraining'); ?></li>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ($is_admin) : ?>
            <section class="lst-help-card lst-help-wide">
                <h2><?php esc_html_e('Administration: Benutzerrechte', 'lsttraining'); ?></h2>
                <p><?php esc_html_e('Unter „Benutzer“ werden pro Person zuerst die erlaubten Bereiche und anschließend die zulässigen Leitstellen ausgewählt. Beide Freigaben müssen zusammenpassen.', 'lsttraining'); ?></p>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Prüffall', 'lsttraining'); ?></th><th><?php esc_html_e('Erwartetes Ergebnis', 'lsttraining'); ?></th></tr></thead>
                    <tbody>
                        <tr><td><?php esc_html_e('Bereich nicht freigegeben', 'lsttraining'); ?></td><td><?php esc_html_e('Ansicht und Änderung verweigert', 'lsttraining'); ?></td></tr>
                        <tr><td><?php esc_html_e('Bereich erlaubt, Leitstelle nicht erlaubt', 'lsttraining'); ?></td><td><?php esc_html_e('Objekt nicht sichtbar; Änderung verweigert', 'lsttraining'); ?></td></tr>
                        <tr><td><?php esc_html_e('Bereich und Leitstelle erlaubt', 'lsttraining'); ?></td><td><?php esc_html_e('Lesen, Speichern und Löschen erlaubt', 'lsttraining'); ?></td></tr>
                        <tr><td><?php esc_html_e('Zielbereich beim Verschieben nicht erlaubt', 'lsttraining'); ?></td><td><?php esc_html_e('Änderung verweigert', 'lsttraining'); ?></td></tr>
                    </tbody>
                </table>
                <p><a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=lsttraining_benutzer')); ?>"><?php esc_html_e('Benutzerrechte öffnen', 'lsttraining'); ?></a></p>
            </section>

            <section class="lst-help-card">
                <h2><?php esc_html_e('Datenbankschema', 'lsttraining'); ?></h2>
                <p>
                    <?php esc_html_e('Installierter Stand:', 'lsttraining'); ?>
                    <strong><?php echo esc_html((string) lsttraining_schema_installed_version()); ?></strong><br>
                    <?php esc_html_e('Erforderlicher Stand:', 'lsttraining'); ?>
                    <strong><?php echo esc_html((string) LSTTRAINING_SCHEMA_VERSION); ?></strong>
                </p>
                <p><?php esc_html_e('Migrationen laufen bei Aktivierung beziehungsweise Upgrade. Im normalen Seiten- und Simulationsbetrieb wird das Schema nicht verändert.', 'lsttraining'); ?></p>
                <p><a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=lsttraining')); ?>"><?php esc_html_e('Einstellungen und Schema-Prüfung', 'lsttraining'); ?></a></p>
            </section>

            <section class="lst-help-card">
                <h2><?php esc_html_e('Abnahme nach einem Update', 'lsttraining'); ?></h2>
                <ol>
                    <li><?php esc_html_e('Datenbank sichern.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Schema-Prüfung ausführen.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Rechte mit einem eingeschränkten Testbenutzer prüfen.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Dieselbe Multiplayer-Instanz in zwei Browsern testen.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Kontrollieren, dass reines Snapshot-Polling keine Zustände verändert.', 'lsttraining'); ?></li>
                </ol>
            </section>

            <section class="lst-help-card lst-help-wide">
                <h2><?php esc_html_e('REST-API – vollständige Referenz', 'lsttraining'); ?></h2>
                <p><?php esc_html_e('Alle Routen beginnen mit /wp-json/lst/v1. Die Verwaltungs-API bearbeitet Stammdaten; die Instanz-Routen lesen und ändern ausschließlich den Zustand einer laufenden Simulation.', 'lsttraining'); ?></p>

                <h3><?php esc_html_e('Authentifizierung und Rechte', 'lsttraining'); ?></h3>
                <ul>
                    <li><?php esc_html_e('Browser: angemeldete WordPress-Sitzung, credentials: same-origin und der Header X-WP-Nonce.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Externe Clients: WordPress Application Password über HTTPS.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Verwaltungsrouten prüfen Bereichsrecht, Leitstellen-Scope und die konkrete Objektzuordnung.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Live-Schreibzugriffe sind nur für Einsatzleiter der Instanz und Administratoren erlaubt.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Datenbank-Zugangsdaten werden niemals an den Client übertragen.', 'lsttraining'); ?></li>
                </ul>

                <h3><?php esc_html_e('Strikte Eingabevalidierung', 'lsttraining'); ?></h3>
                <p><?php esc_html_e('Alle schreibenden REST-Routen prüfen den JSON-Body vor dem ersten Datenbankschreibzugriff. Unbekannte Felder, falsche Datentypen, ungültige Wertebereiche, Steuerzeichen sowie HTML-, Skript- und andere ausführbare Codebestandteile werden mit HTTP 400 und validation_failed abgelehnt.', 'lsttraining'); ?></p>
                <ul>
                    <li><?php esc_html_e('Texte sind reiner UTF-8-Text mit feldabhängiger Maximallänge; HTML und ausführbare URI-Schemata sind nicht erlaubt.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('GeoJSON erlaubt ausschließlich Polygon- und MultiPolygon-Einsatzgebiete mit gültigen, geschlossenen Koordinatenringen.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Fachbereiche, Signallichter, ID-Listen, Koordinatenpaare und Bildreferenzen werden jeweils gegen ein festes Format geprüft.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Beliebige externe Bild-URLs werden nicht akzeptiert, da ihr Inhalt nicht sicher geprüft werden kann. Referenzen sind nur für vorhandene lokale Plugin-Bilder und zuvor von der API bereinigte Uploads erlaubt.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Bilddaten können als Base64-Objekt übertragen werden. Rasterbilder werden vollständig neu codiert; bei SVG werden aktive Inhalte, externe Referenzen, Stylesheets und unbekanntes Markup entfernt.', 'lsttraining'); ?></li>
                    <li><?php esc_html_e('Datenbankwerte werden als gebundene Parameter geschrieben; Tabellen und Spalten stammen aus festen serverseitigen Listen.', 'lsttraining'); ?></li>
                </ul>

                <h3><?php esc_html_e('Verwaltungsrouten', 'lsttraining'); ?></h3>
                <p><?php esc_html_e('Die Verwaltungs-API arbeitet ressourcenbasiert. Eine Ressource entspricht einem Stammdatenbereich. Hochladen bedeutet dabei immer: JSON an die passende Route senden. Verschieben bedeutet: Beziehungsfelder oder die Wache eines Fahrzeugs ändern, nicht Dateien auf dem Server per FTP bewegen.', 'lsttraining'); ?></p>
                <table class="widefat striped lst-help-api-table">
                    <thead><tr><th><?php esc_html_e('Methode', 'lsttraining'); ?></th><th><?php esc_html_e('Pfad', 'lsttraining'); ?></th><th><?php esc_html_e('Funktion', 'lsttraining'); ?></th></tr></thead>
                    <tbody>
                        <tr><td><code>GET</code></td><td><code>/verwaltung/{ressource}</code></td><td><?php esc_html_e('Liste; Filter search, page und per_page bis 200', 'lsttraining'); ?></td></tr>
                        <tr><td><code>POST</code></td><td><code>/verwaltung/{ressource}</code></td><td><?php esc_html_e('Datensatz anlegen', 'lsttraining'); ?></td></tr>
                        <tr><td><code>GET</code></td><td><code>/verwaltung/{ressource}/{id}</code></td><td><?php esc_html_e('Datensatz mit Beziehungen lesen', 'lsttraining'); ?></td></tr>
                        <tr><td><code>PATCH</code></td><td><code>/verwaltung/{ressource}/{id}</code></td><td><?php esc_html_e('Übermittelte Felder und Beziehungen ändern', 'lsttraining'); ?></td></tr>
                        <tr><td><code>DELETE</code></td><td><code>/verwaltung/{ressource}/{id}?confirm=true</code></td><td><?php esc_html_e('Datensatz und schemaabhängige Kinddaten löschen', 'lsttraining'); ?></td></tr>
                    </tbody>
                </table>

                <h3><?php esc_html_e('Daten hochladen und ändern', 'lsttraining'); ?></h3>
                <p><?php esc_html_e('Neue Datensätze werden mit POST angelegt, vorhandene Datensätze mit PATCH geändert. Der Body ist immer ein JSON-Objekt. Nur die Felder der jeweiligen Ressource sind erlaubt; unbekannte Felder führen zu HTTP 400.', 'lsttraining'); ?></p>
                <table class="widefat striped lst-help-api-table">
                    <thead><tr><th><?php esc_html_e('Datenart', 'lsttraining'); ?></th><th><?php esc_html_e('So wird sie übertragen', 'lsttraining'); ?></th></tr></thead>
                    <tbody>
                        <tr><td><?php esc_html_e('Texte und Stammdaten', 'lsttraining'); ?></td><td><code>{"name":"RW Mitte","typ":"Rettungswache"}</code></td></tr>
                        <tr><td><?php esc_html_e('Koordinaten', 'lsttraining'); ?></td><td><code>{"latitude":52.52,"longitude":13.405}</code></td></tr>
                        <tr><td><?php esc_html_e('Einsatzgebiet', 'lsttraining'); ?></td><td><code>{"geojson":{...}}</code> <?php esc_html_e('oder ein GeoJSON-String mit Polygon/MultiPolygon.', 'lsttraining'); ?></td></tr>
                        <tr><td><?php esc_html_e('ID-Listen', 'lsttraining'); ?></td><td><code>{"leitstellen":[3,7],"nebenleitstellen":[12]}</code></td></tr>
                        <tr><td><?php esc_html_e('Fachbereiche', 'lsttraining'); ?></td><td><code>{"departments":["CARD","NEURO"]}</code> <?php esc_html_e('oder Fachbereiche mit eigenen Koordinaten.', 'lsttraining'); ?></td></tr>
                        <tr><td><?php esc_html_e('Signallichter', 'lsttraining'); ?></td><td><code>{"signal_lights_json":{"version":1,"lights":[{"x":0.5,"y":0.2,"type":"beacon"}]}}</code></td></tr>
                        <tr><td><?php esc_html_e('Vorhandenes lokales Bild', 'lsttraining'); ?></td><td><code>{"bild_datei":"img/fahrzeug/default.png"}</code></td></tr>
                        <tr><td><?php esc_html_e('Neues Bild als Upload', 'lsttraining'); ?></td><td><code>{"bild_datei":{"filename":"rtw.png","mime_type":"image/png","data_base64":"..."}}</code></td></tr>
                    </tbody>
                </table>
                <p><?php esc_html_e('Bei PATCH muss nicht der komplette Datensatz gesendet werden. Es reichen die Felder, die geändert werden sollen. Beziehungen werden aber als vollständige neue Liste verstanden: Wer leitstellen oder nebenleitstellen sendet, ersetzt damit die bisherige Zuordnung dieser Beziehung.', 'lsttraining'); ?></p>

                <h3><?php esc_html_e('Verschieben und Zuordnungen ändern', 'lsttraining'); ?></h3>
                <p><?php esc_html_e('Die API verschiebt keine Ordner oder Dateien, sondern fachliche Objekte zwischen Leitstellen, Nebenleitstellen und Wachen. Der Server prüft dabei immer den alten und den neuen Leitstellen-Scope.', 'lsttraining'); ?></p>
                <table class="widefat striped lst-help-api-table">
                    <thead><tr><th><?php esc_html_e('Aktion', 'lsttraining'); ?></th><th><?php esc_html_e('Route und Felder', 'lsttraining'); ?></th><th><?php esc_html_e('Wirkung', 'lsttraining'); ?></th></tr></thead>
                    <tbody>
                        <tr><td><?php esc_html_e('Wache anderer Leitstelle zuordnen', 'lsttraining'); ?></td><td><code>PATCH /verwaltung/wachen/{id}</code><br><code>{"leitstellen":[5]}</code></td><td><?php esc_html_e('Die Wache gehört danach zur übergebenen Leitstellenliste.', 'lsttraining'); ?></td></tr>
                        <tr><td><?php esc_html_e('Wache zusätzlich einer Nebenleitstelle zuordnen', 'lsttraining'); ?></td><td><code>PATCH /verwaltung/wachen/{id}</code><br><code>{"nebenleitstellen":[8,9]}</code></td><td><?php esc_html_e('Die Nebenleitstellen-Zuordnung wird ersetzt.', 'lsttraining'); ?></td></tr>
                        <tr><td><?php esc_html_e('Fahrzeug in eine andere Wache verschieben', 'lsttraining'); ?></td><td><code>PATCH /verwaltung/fahrzeuge/{id}</code><br><code>{"wache_id":22}</code></td><td><?php esc_html_e('Das Fahrzeug gehört danach zur Zielwache; der Rufname muss dort eindeutig sein.', 'lsttraining'); ?></td></tr>
                        <tr><td><?php esc_html_e('Krankenhaus für Leitstellen freigeben', 'lsttraining'); ?></td><td><code>PATCH /verwaltung/leitstellen/{id}</code><br><code>{"available_hospitals":[4,11]}</code></td><td><?php esc_html_e('Die Leitstelle nutzt danach genau diese Krankenhausliste.', 'lsttraining'); ?></td></tr>
                        <tr><td><?php esc_html_e('Nebenleitstelle mit Leitstellen verknüpfen', 'lsttraining'); ?></td><td><code>PATCH /verwaltung/nebenleitstellen/{id}</code><br><code>{"leitstellen":[3]}</code></td><td><?php esc_html_e('Die Nebenleitstelle wird den übergebenen Hauptleitstellen zugeordnet.', 'lsttraining'); ?></td></tr>
                    </tbody>
                </table>
                <p><?php esc_html_e('Nicht-Administratoren dürfen nur in Bereiche verschieben, für die sie berechtigt sind. Gehört ein Objekt mehreren Leitstellen, muss der Benutzer für alle betroffenen Leitstellen die passende Bereichsfreigabe besitzen.', 'lsttraining'); ?></p>

                <details open>
                    <summary><?php esc_html_e('Leitstellen', 'lsttraining'); ?></summary>
                    <p><code>ressource = leitstellen</code></p>
                    <p><strong><?php esc_html_e('Felder:', 'lsttraining'); ?></strong> <code>name</code>, <code>ort</code>, <code>bundesland</code>, <code>land</code>, <code>latitude</code>, <code>longitude</code>, <code>geojson</code>, <code>available_hospitals</code>, <code>police_vehicle_image</code>, <code>police_signal_lights_json</code>, <code>rescue_vehicle_image</code>, <code>rescue_signal_lights_json</code>.</p>
                    <p><strong><?php esc_html_e('Beziehungen:', 'lsttraining'); ?></strong> <code>nebenleitstellen</code> und <code>wachen</code> als ID-Listen; <code>available_hospitals</code> ist die ID-Liste der freigegebenen Krankenhäuser.</p>
                    <p><?php esc_html_e('Neue Leitstellen dürfen über die API nur Administratoren anlegen.', 'lsttraining'); ?></p>
                </details>

                <details>
                    <summary><?php esc_html_e('Nebenleitstellen', 'lsttraining'); ?></summary>
                    <p><code>ressource = nebenleitstellen</code></p>
                    <p><strong><?php esc_html_e('Felder:', 'lsttraining'); ?></strong> <code>name</code>, <code>aufgaben</code>, <code>zustandigkeit</code>, <code>standorte</code>, <code>einwohner</code>, <code>flaeche_km2</code>, <code>gps</code>, <code>nachbarleitstelle</code>, <code>geojson</code>.</p>
                    <p><strong><?php esc_html_e('Beziehungen:', 'lsttraining'); ?></strong> <code>leitstellen</code> und <code>wachen</code> als ID-Listen.</p>
                </details>

                <details>
                    <summary><?php esc_html_e('Wachen', 'lsttraining'); ?></summary>
                    <p><code>ressource = wachen</code></p>
                    <p><strong><?php esc_html_e('Felder:', 'lsttraining'); ?></strong> <code>name</code>, <code>typ</code>, <code>bundesland</code>, <code>land</code>, <code>latitude</code>, <code>longitude</code>, <code>arrival_pos</code>, <code>departure_pos</code>, <code>bild_datei</code>, <code>exists_in_reality</code>, <code>source_note</code>.</p>
                    <p><strong><?php esc_html_e('Beziehungen:', 'lsttraining'); ?></strong> <code>leitstellen</code> und <code>nebenleitstellen</code> als ID-Listen. Beim Lesen enthält <code>relations.fahrzeuge</code> zusätzlich die Fahrzeug-IDs.</p>
                </details>

                <details>
                    <summary><?php esc_html_e('Fahrzeuge', 'lsttraining'); ?></summary>
                    <p><code>ressource = fahrzeuge</code></p>
                    <p><strong><?php esc_html_e('Felder:', 'lsttraining'); ?></strong> <code>wache_id</code>, <code>rufname</code>, <code>fahrzeugtyp</code>, <code>source_note</code>, <code>is_first_responder</code>, <code>status</code>, <code>fms_status</code>, <code>sondersignal</code>, <code>dienstzeiten</code>, <code>latitude</code>, <code>longitude</code>, <code>bild_datei</code>, <code>signal_lights_json</code>.</p>
                    <p><?php esc_html_e('Rufnamen müssen innerhalb einer Wache eindeutig sein. Änderungen hier betreffen Stammdaten, nicht automatisch bereits laufende Instanzen.', 'lsttraining'); ?></p>
                </details>

                <details>
                    <summary><?php esc_html_e('Krankenhäuser', 'lsttraining'); ?></summary>
                    <p><code>ressource = krankenhaeuser</code></p>
                    <p><strong><?php esc_html_e('Felder:', 'lsttraining'); ?></strong> <code>poi_id</code>, <code>name</code>, <code>latitude</code>, <code>longitude</code>, <code>versorgungsstufe</code>, <code>trauma_level</code>, <code>helipad</code>, <code>departments</code>.</p>
                    <p><?php esc_html_e('Fehlt poi_id beim Anlegen, erzeugt der Server eine manual-UUID. Beim Löschen wird die Krankenhaus-ID aus allen Leitstellenfreigaben entfernt.', 'lsttraining'); ?></p>
                </details>

                <h3><?php esc_html_e('Live- und Statusrouten', 'lsttraining'); ?></h3>
                <table class="widefat striped lst-help-api-table">
                    <thead><tr><th><?php esc_html_e('Methode', 'lsttraining'); ?></th><th><?php esc_html_e('Pfad', 'lsttraining'); ?></th><th><?php esc_html_e('Funktion', 'lsttraining'); ?></th></tr></thead>
                    <tbody>
                        <tr><td><code>GET</code></td><td><code>/instanzen/{instanz_id}/status</code></td><td><?php esc_html_e('Leitstelle, Simulationszustand, Fahrzeuggruppen, offene Einsätze und Teilnehmer', 'lsttraining'); ?></td></tr>
                        <tr><td><code>PATCH</code></td><td><code>/instanzen/{instanz_id}/status</code></td><td><?php esc_html_e('state, paused und Geschwindigkeit 1, 2 oder 5 schreiben', 'lsttraining'); ?></td></tr>
                        <tr><td><code>GET</code></td><td><code>/instanzen/{instanz_id}/fahrzeuge</code></td><td><?php esc_html_e('Effektive Fahrzeugzustände; Filter wache_id, fahrzeug_id und fms_status', 'lsttraining'); ?></td></tr>
                        <tr><td><code>PATCH</code></td><td><code>/instanzen/{instanz_id}/fahrzeuge/{status_id}</code></td><td><?php esc_html_e('Status, FMS, Sondersignal, Bemerkung, Position und Ziel instanzbezogen schreiben', 'lsttraining'); ?></td></tr>
                    </tbody>
                </table>
                <p><?php esc_html_e('Live-Fahrzeugänderungen werden als Delta zur unveränderlichen Instanz-Baseline gespeichert. Eine pausierte Simulation lehnt Fahrzeugstatusänderungen mit HTTP 409 ab.', 'lsttraining'); ?></p>

                <h3><?php esc_html_e('Weitere REST-Routen', 'lsttraining'); ?></h3>
                <table class="widefat striped lst-help-api-table">
                    <tbody>
                        <tr><td><code>GET /wachen</code></td><td><?php esc_html_e('Kartendaten der Wachen; optional leitstelle_id oder nebenleitstelle_id', 'lsttraining'); ?></td></tr>
                        <tr><td><code>POST /route</code></td><td><?php esc_html_e('Route über OpenRouteService berechnen; Body mit coordinates und optional preference', 'lsttraining'); ?></td></tr>
                    </tbody>
                </table>

                <h3><?php esc_html_e('Anfragebeispiele', 'lsttraining'); ?></h3>
                <pre><code><?php echo esc_html("// Wache anlegen\nfetch('/wp-json/lst/v1/verwaltung/wachen', {\n  method: 'POST',\n  credentials: 'same-origin',\n  headers: {\n    'Content-Type': 'application/json',\n    'X-WP-Nonce': restNonce\n  },\n  body: JSON.stringify({\n    name: 'Feuer- und Rettungswache Mitte',\n    typ: 'FRRD',\n    latitude: 52.52,\n    longitude: 13.405,\n    leitstellen: [3]\n  })\n});\n\n// Fahrzeugstatus in Instanz 42 aendern\nfetch('/wp-json/lst/v1/instanzen/42/fahrzeuge/91', {\n  method: 'PATCH',\n  credentials: 'same-origin',\n  headers: {\n    'Content-Type': 'application/json',\n    'X-WP-Nonce': restNonce\n  },\n  body: JSON.stringify({ fms_status: '3', sondersignal: true })\n});"); ?></code></pre>

                <p><strong><?php esc_html_e('Bilddaten:', 'lsttraining'); ?></strong> <code>{"filename":"rtw.png","mime_type":"image/png","data_base64":"..."}</code>. <?php esc_html_e('PNG, JPEG, GIF und WebP werden aus den decodierten Pixeln ohne Originalmetadaten neu erzeugt. SVG wird auf eine Positivliste statischer Elemente und Attribute reduziert. Das Rasterbild darf höchstens 10 MiB und 4096 × 4096 Pixel groß sein.', 'lsttraining'); ?></p>

                <h3><?php esc_html_e('Antworten und Fehler', 'lsttraining'); ?></h3>
                <p><?php esc_html_e('Erfolgreiche Antworten enthalten ok: true und data. Fehler enthalten ok: false, error und – bei Verwaltungsrouten – message. Übliche Statuscodes sind 400 für ungültige Daten, 401 für fehlende Anmeldung, 403 für fehlende Rechte, 404 für unbekannte Datensätze, 409 für Konflikte oder pausierte Simulationen und 500 für Datenbankfehler.', 'lsttraining'); ?></p>
                <p><?php esc_html_e('Unbekannte JSON-Felder werden vollständig abgelehnt. Mehrtabellenänderungen laufen in einer Transaktion, und alle Schreibvorgänge werden im Aktivitätsprotokoll erfasst.', 'lsttraining'); ?></p>

                <p>
                    <a class="button button-secondary" href="<?php echo esc_url($management_api_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Verwaltungs-API öffnen', 'lsttraining'); ?></a>
                    <a class="button button-secondary" href="<?php echo esc_url($status_api_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Status-API öffnen', 'lsttraining'); ?></a>
                </p>
            </section>
        <?php endif; ?>

        <section class="lst-help-card lst-help-wide">
            <h2><?php esc_html_e('Spielerhandbuch', 'lsttraining'); ?></h2>
            <p><?php esc_html_e('Das Spielerhandbuch beschreibt Profil, Spielstart, Spielmodi, Workspace, Anrufe, Einsätze, Fahrzeuge, Funk und gespeicherte Spiele.', 'lsttraining'); ?></p>
            <p><a class="button button-primary" href="<?php echo esc_url($player_docs_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Spielerhandbuch öffnen', 'lsttraining'); ?></a></p>
            <p><code>docs/spielerhandbuch.md</code></p>
        </section>

        <?php if ($is_admin) : ?>
            <section class="lst-help-card lst-help-wide">
                <h2><?php esc_html_e('Administrations- und Entwickler-Wiki', 'lsttraining'); ?></h2>
                <p><?php esc_html_e('Die Wiki-Startseite führt zu den vollständigen Kapiteln für Leitstellen, Nebenleitstellen, Krankenhäuser, Wachen, Fahrzeuge, Einsätze, Anrufe, Betrieb, Sicherheit und Entwicklung.', 'lsttraining'); ?></p>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url($wiki_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Wiki-Startseite öffnen', 'lsttraining'); ?></a>
                    <a class="button button-secondary" href="<?php echo esc_url($security_docs_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Sicherheitsdokumentation öffnen', 'lsttraining'); ?></a>
                    <a class="button button-secondary" href="<?php echo esc_url($rest_api_guide_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('REST-API Praxisanleitung öffnen', 'lsttraining'); ?></a>
                </p>
                <p><code>docs/README.md</code></p>
            </section>
        <?php endif; ?>
    </div>
</div>
