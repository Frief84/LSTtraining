<?php
/**
 * Integrierte Hilfe- und Dokumentationsseite.
 */

if (!defined('ABSPATH')) { exit; }
if (!current_user_can('read')) {
    wp_die(esc_html__('Du hast keine ausreichenden Rechte, um diese Seite aufzurufen.', 'lsttraining'));
}

$is_admin = current_user_can('manage_options');
$github_docs_url = 'https://github.com/Frief84/LSTtraining/blob/main/docs/sicherheit-migration-multiplayer.md';
?>
<div class="wrap lsttraining-help">
    <h1><?php esc_html_e('LST Training – Hilfe & Dokumentation', 'lsttraining'); ?></h1>
    <p class="description">
        <?php esc_html_e('Kurzanleitung für den sicheren Betrieb, die Benutzerrechte und die Multiplayer-Simulation.', 'lsttraining'); ?>
    </p>

    <div class="notice notice-info inline">
        <p><strong><?php esc_html_e('Grundregel:', 'lsttraining'); ?></strong>
            <?php esc_html_e('Benutzer sehen und bearbeiten nur die Bereiche und Leitstellen, die ihnen ausdrücklich freigegeben wurden.', 'lsttraining'); ?>
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
    </style>

    <div class="lst-help-grid">
        <section class="lst-help-card">
            <h2><?php esc_html_e('Schnellstart', 'lsttraining'); ?></h2>
            <ol>
                <li><?php esc_html_e('Leitstelle und Einsatzgebiet anlegen.', 'lsttraining'); ?></li>
                <li><?php esc_html_e('Wachen zuordnen und Fahrzeuge einrichten.', 'lsttraining'); ?></li>
                <li><?php esc_html_e('Krankenhäuser und Einsatzvorlagen ergänzen.', 'lsttraining'); ?></li>
                <li><?php esc_html_e('Simulation starten oder einen gespeicherten Spielstand fortsetzen.', 'lsttraining'); ?></li>
            </ol>
            <p><?php esc_html_e('Welche Menüpunkte sichtbar sind, hängt von den persönlichen Freigaben ab.', 'lsttraining'); ?></p>
        </section>

        <section class="lst-help-card">
            <h2><?php esc_html_e('Fahrzeuge und Wachen verschieben', 'lsttraining'); ?></h2>
            <p><?php esc_html_e('Das Verschieben in einen anderen Leitstellenbereich ist erlaubt, wenn der Benutzer für den bisherigen und den vollständigen neuen Bereich berechtigt ist.', 'lsttraining'); ?></p>
            <p><?php esc_html_e('Fehlt eine dieser Freigaben, lehnt der Server die Änderung ab – unabhängig davon, was die Oberfläche anzeigt.', 'lsttraining'); ?></p>
        </section>

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

        <section class="lst-help-card">
            <h2><?php esc_html_e('Sicherheitsregeln', 'lsttraining'); ?></h2>
            <ul>
                <li><?php esc_html_e('Änderungen und Löschungen werden ausschließlich als geschützte POST-Anfragen verarbeitet.', 'lsttraining'); ?></li>
                <li><?php esc_html_e('Jede Aktion prüft Anmeldung, Sicherheits-Token und konkrete Objektberechtigung.', 'lsttraining'); ?></li>
                <li><?php esc_html_e('Nicht zugeordnete Objekte sind für Nicht-Administratoren gesperrt.', 'lsttraining'); ?></li>
                <li><?php esc_html_e('Gemeinsam zugeordnete Objekte benötigen die Freigabe für alle betroffenen Leitstellen.', 'lsttraining'); ?></li>
            </ul>
        </section>

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
        <?php endif; ?>

        <section class="lst-help-card lst-help-wide">
            <h2><?php esc_html_e('Ausführliche Entwicklerdokumentation', 'lsttraining'); ?></h2>
            <p><?php esc_html_e('Die vollständige Beschreibung der Endpunkt-Sicherung, Objekt-Scope-Ermittlung, Migrationen, Tick-Serialisierung, Snapshot-Regeln und Testfälle liegt im Repository.', 'lsttraining'); ?></p>
            <p><a class="button button-primary" href="<?php echo esc_url($github_docs_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Technische Dokumentation auf GitHub öffnen', 'lsttraining'); ?></a></p>
            <p><code>docs/sicherheit-migration-multiplayer.md</code></p>
        </section>
    </div>
</div>
