<?php
/**
 * Rollenabhaengiger Markdown-Dokumentationsviewer im aktiven WordPress-Theme.
 */

if (!defined('ABSPATH')) { exit; }

function lsttraining_documentation_catalog(): array {
    return [
        'spielerhandbuch' => [
            'title' => 'Spielerhandbuch',
            'path' => 'docs/spielerhandbuch.md',
            'audience' => 'player',
            'description' => 'Profil, Spielstart, Workspace, Anrufe, Einsätze, Fahrzeuge und gespeicherte Spiele.',
        ],
        'erste-schritte' => [
            'title' => 'Erste Schritte',
            'path' => 'docs/erste-schritte.md',
            'audience' => 'admin',
            'description' => 'Installation, Einstellungen, Shortcodes und Erstprüfung.',
        ],
        'administration' => [
            'title' => 'Administration und Stammdaten',
            'path' => 'docs/administration.md',
            'audience' => 'admin',
            'description' => 'Leitstellen, Nebenleitstellen, Krankenhäuser, Wachen, Fahrzeuge, Einsätze und Anruferprofile.',
        ],
        'leitstellen-editor' => [
            'title' => 'Leitstellen-Editor',
            'path' => 'docs/leitstellen-editor.md',
            'audience' => 'area',
            'areas' => ['leitstellen'],
            'description' => 'Leitstellen, Einsatzgebiete, Nachbarn, Kliniken, POIs und Default-Fahrzeuge verwalten.',
        ],
        'eigene-leitstelle-anlegen' => [
            'title' => 'Eigene Leitstelle anlegen',
            'path' => 'docs/eigene-leitstelle-anlegen.md',
            'audience' => 'area',
            'areas' => ['leitstellen'],
            'description' => 'Schritt-für-Schritt-Anleitung zum Anlegen einer Leitstelle im WordPress-Adminbereich.',
        ],
        'nebenleitstellen-editor' => [
            'title' => 'Nebenleitstellen-Editor',
            'path' => 'docs/nebenleitstellen-editor.md',
            'audience' => 'area',
            'areas' => ['nebenstellen'],
            'description' => 'Nebenleitstellen, Gebiete, Hauptleitstellen- und Wachenzuordnungen bearbeiten.',
        ],
        'krankenhaeuser-editor' => [
            'title' => 'Krankenhäuser-Editor',
            'path' => 'docs/krankenhaeuser-editor.md',
            'audience' => 'area',
            'areas' => ['hospitals'],
            'description' => 'Klinikstandorte, Fachbereiche, Versorgungsstufen und Leitstellenfreigaben pflegen.',
        ],
        'wachen-editor' => [
            'title' => 'Wachen-Editor',
            'path' => 'docs/wachen-editor.md',
            'audience' => 'area',
            'areas' => ['wachen'],
            'description' => 'Wachen, Standorte, Typen und Leitstellenzuordnungen sicher verwalten.',
        ],
        'fahrzeuge-editor' => [
            'title' => 'Fahrzeuge-Editor',
            'path' => 'docs/fahrzeuge-editor.md',
            'audience' => 'area',
            'areas' => ['fahrzeuge'],
            'description' => 'Fahrzeugstammdaten, Wachenwechsel, Dienstzeiten, Bilder und Signallichter bearbeiten.',
        ],
        'polizei-und-unterstuetzungsfahrzeuge' => [
            'title' => 'Polizei und Unterstützungsfahrzeuge',
            'path' => 'docs/polizei-und-unterstuetzungsfahrzeuge.md',
            'audience' => 'area',
            'areas' => ['leitstellen', 'fahrzeuge'],
            'description' => 'Polizei-Defaults und dynamische Unterstützungsfahrzeuge im Leitstellen-Editor konfigurieren.',
        ],
        'simulation-und-multiplayer' => [
            'title' => 'Simulation und Multiplayer',
            'path' => 'docs/simulation-und-multiplayer.md',
            'audience' => 'admin',
            'description' => 'Instanzen, Tick, Snapshot, Multiplayer und Aufbewahrung.',
        ],
        'einsatz-ortsbindung' => [
            'title' => 'Einsatz-Ortsbindung',
            'path' => 'docs/einsatz-ortsbindung.md',
            'audience' => 'area',
            'areas' => ['leitstellen'],
            'description' => 'Einsatzgebiete, Landschafts-, Straßen-, Autobahn- und POI-Orte.',
        ],
        'simulation-workspace-hospitals' => [
            'title' => 'Krankenhäuser im Workspace',
            'path' => 'docs/simulation-workspace-hospitals.md',
            'audience' => 'area',
            'areas' => ['hospitals', 'leitstellen'],
            'description' => 'Fachbereiche, Patienten und Krankenhauszuweisung.',
        ],
        'wetter-und-nachbarleitstellen-auslastung' => [
            'title' => 'Wetter und Nachbarleitstellen',
            'path' => 'docs/wetter-und-nachbarleitstellen-auslastung.md',
            'audience' => 'area',
            'areas' => ['leitstellen'],
            'description' => 'Wetterwirkung, Auslastung und Unterstützungsangebote.',
        ],
        'sicherheit-migration-multiplayer' => [
            'title' => 'Sicherheit, Migration und Berechtigungen',
            'path' => 'docs/sicherheit-migration-multiplayer.md',
            'audience' => 'admin',
            'description' => 'Objekt-Scope, CSRF, Schema, Tick und Abnahme.',
        ],
        'betrieb-und-fehlerbehebung' => [
            'title' => 'Betrieb und Fehlerbehebung',
            'path' => 'docs/betrieb-und-fehlerbehebung.md',
            'audience' => 'admin',
            'description' => 'Backups, Updates, Diagnose und Betriebschecklisten.',
        ],
        'entwickleruebersicht' => [
            'title' => 'Entwicklerübersicht',
            'path' => 'docs/entwickleruebersicht.md',
            'audience' => 'admin',
            'description' => 'Architektur, Module, Datenfluss und Tests.',
        ],
        'rest-management-api' => [
            'title' => 'REST-Verwaltungs-API',
            'path' => 'docs/rest-management-api.md',
            'audience' => 'admin',
            'description' => 'Stammdaten-Routen, Felder, Beziehungen und Fehler.',
        ],
        'rest-api-praxis' => [
            'title' => 'REST-API Praxisanleitung',
            'path' => 'docs/rest-api-praxis.md',
            'audience' => 'admin',
            'description' => 'Praktische Beispiele fuer JSON-Aufrufe, Uploads, Bilddaten und Zuordnungen.',
        ],
        'rest-status-api' => [
            'title' => 'REST-Status-API',
            'path' => 'docs/rest-status-api.md',
            'audience' => 'admin',
            'description' => 'Instanzstatus und effektive Fahrzeugzustände.',
        ],
        'osm-tile-architecture' => [
            'title' => 'OSM-Tile-Architektur',
            'path' => 'docs/osm_tile_architecture.md',
            'audience' => 'admin',
            'description' => 'Lokale Kartendaten und OSM-Verarbeitung.',
        ],
        'projekt-readme' => [
            'title' => 'Projekt-README',
            'path' => 'README.md',
            'audience' => 'admin',
            'description' => 'Projektübersicht und Repository-Struktur.',
        ],
    ];
}

function lsttraining_documentation_can_view(array $document, ?int $user_id = null): bool {
    $user_id = $user_id ?: get_current_user_id();
    if ($user_id <= 0 || !user_can($user_id, 'read')) {
        return false;
    }
    if (user_can($user_id, 'manage_options')) {
        return true;
    }

    $audience = (string) ($document['audience'] ?? 'admin');
    if ($audience === 'player') {
        return true;
    }
    if ($audience !== 'area') {
        return false;
    }
    foreach ((array) ($document['areas'] ?? []) as $area) {
        if (lsttraining_user_can((string) $area, null, $user_id)) {
            return true;
        }
    }
    return false;
}

function lsttraining_documentation_page_url(string $slug = ''): string {
    $page_id = absint(get_option('lsttraining_docs_page_id', 0));
    $base = $page_id > 0 ? get_permalink($page_id) : '';
    if (!$base) {
        $base = home_url('/');
    }
    return $slug === '' || $slug === 'home'
        ? $base
        : add_query_arg('lst_doc', sanitize_key($slug), $base);
}

function lsttraining_documentation_slug_for_path(string $current_slug, string $target): ?string {
    $catalog = lsttraining_documentation_catalog();
    $fragmentless = preg_replace('/#.*$/', '', trim($target));
    if ($fragmentless === null || $fragmentless === '') {
        return $current_slug;
    }

    $current_path = (string) ($catalog[$current_slug]['path'] ?? 'docs/README.md');
    $base_dir = dirname($current_path);
    $combined = $base_dir . '/' . $fragmentless;
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', $combined)) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    $normalized = implode('/', $parts);
    if ($normalized === 'docs/README.md') {
        return 'home';
    }
    foreach ($catalog as $slug => $document) {
        if ((string) ($document['path'] ?? '') === $normalized) {
            return $slug;
        }
    }
    return null;
}

function lsttraining_documentation_inline(string $text, string $current_slug): string {
    $code_tokens = [];
    $text = preg_replace_callback('/`([^`]+)`/', static function (array $match) use (&$code_tokens): string {
        $token = '@@LSTCODE' . count($code_tokens) . '@@';
        $code_tokens[$token] = '<code>' . esc_html($match[1]) . '</code>';
        return $token;
    }, $text) ?? $text;

    $html = esc_html($text);
    $html = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', static function (array $match) use ($current_slug): string {
        $label = $match[1];
        $target = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('#^https?://#i', $target)) {
            return '<a href="' . esc_url($target) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
        }
        if (str_starts_with($target, '#')) {
            return '<a href="' . esc_attr($target) . '">' . $label . '</a>';
        }

        $slug = lsttraining_documentation_slug_for_path($current_slug, $target);
        $catalog = lsttraining_documentation_catalog();
        if ($slug === 'home') {
            return '<a href="' . esc_url(lsttraining_documentation_page_url()) . '">' . $label . '</a>';
        }
        if ($slug === null || !isset($catalog[$slug]) || !lsttraining_documentation_can_view($catalog[$slug])) {
            return $label;
        }
        $fragment = '';
        if (str_contains($target, '#')) {
            $fragment = '#' . sanitize_title(substr($target, strpos($target, '#') + 1));
        }
        return '<a href="' . esc_url(lsttraining_documentation_page_url($slug) . $fragment) . '">' . $label . '</a>';
    }, $html) ?? $html;
    $html = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html) ?? $html;
    $html = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $html) ?? $html;

    foreach ($code_tokens as $token => $replacement) {
        $html = str_replace($token, $replacement, $html);
    }
    return $html;
}

function lsttraining_documentation_table_cells(string $line): array {
    $line = trim($line);
    $line = trim($line, '|');
    return array_map('trim', explode('|', $line));
}

function lsttraining_documentation_markdown(string $markdown, string $current_slug): string {
    $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
    $html = [];
    $paragraph = [];
    $list_type = '';
    $in_code = false;
    $code_language = '';
    $code_lines = [];

    $close_paragraph = static function () use (&$html, &$paragraph, $current_slug): void {
        if (!$paragraph) return;
        $html[] = '<p>' . lsttraining_documentation_inline(implode(' ', $paragraph), $current_slug) . '</p>';
        $paragraph = [];
    };
    $close_list = static function () use (&$html, &$list_type): void {
        if ($list_type === '') return;
        $html[] = '</' . $list_type . '>';
        $list_type = '';
    };

    for ($i = 0, $count = count($lines); $i < $count; $i++) {
        $line = rtrim((string) $lines[$i]);

        if (preg_match('/^```\s*([A-Za-z0-9_-]*)\s*$/', $line, $fence)) {
            if (!$in_code) {
                $close_paragraph();
                $close_list();
                $in_code = true;
                $code_language = sanitize_html_class((string) ($fence[1] ?? ''));
                $code_lines = [];
            } else {
                $class = $code_language !== '' ? ' class="language-' . esc_attr($code_language) . '"' : '';
                $html[] = '<pre><code' . $class . '>' . esc_html(implode("\n", $code_lines)) . '</code></pre>';
                $in_code = false;
                $code_language = '';
                $code_lines = [];
            }
            continue;
        }
        if ($in_code) {
            $code_lines[] = $line;
            continue;
        }

        if (trim($line) === '') {
            $close_paragraph();
            $close_list();
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $heading)) {
            $close_paragraph();
            $close_list();
            $level = strlen($heading[1]);
            $plain = trim(wp_strip_all_tags(preg_replace('/[`*_]/', '', $heading[2]) ?? $heading[2]));
            $html[] = '<h' . $level . ' id="' . esc_attr(sanitize_title($plain)) . '">' . lsttraining_documentation_inline($heading[2], $current_slug) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^\s*(?:---+|\*\*\*+)\s*$/', $line)) {
            $close_paragraph();
            $close_list();
            $html[] = '<hr>';
            continue;
        }

        $next = $lines[$i + 1] ?? '';
        if (str_contains($line, '|') && preg_match('/^\s*\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)+\|?\s*$/', (string) $next)) {
            $close_paragraph();
            $close_list();
            $headers = lsttraining_documentation_table_cells($line);
            $html[] = '<div class="lst-doc-table-wrap"><table><thead><tr>';
            foreach ($headers as $cell) {
                $html[] = '<th>' . lsttraining_documentation_inline($cell, $current_slug) . '</th>';
            }
            $html[] = '</tr></thead><tbody>';
            $i += 2;
            while ($i < $count && str_contains((string) $lines[$i], '|') && trim((string) $lines[$i]) !== '') {
                $html[] = '<tr>';
                foreach (lsttraining_documentation_table_cells((string) $lines[$i]) as $cell) {
                    $html[] = '<td>' . lsttraining_documentation_inline($cell, $current_slug) . '</td>';
                }
                $html[] = '</tr>';
                $i++;
            }
            $html[] = '</tbody></table></div>';
            $i--;
            continue;
        }

        if (preg_match('/^>\s?(.*)$/', $line, $quote)) {
            $close_paragraph();
            $close_list();
            $html[] = '<blockquote><p>' . lsttraining_documentation_inline($quote[1], $current_slug) . '</p></blockquote>';
            continue;
        }

        if (preg_match('/^\s*[-*+]\s+(.+)$/', $line, $item)) {
            $close_paragraph();
            if ($list_type !== 'ul') {
                $close_list();
                $list_type = 'ul';
                $html[] = '<ul>';
            }
            $html[] = '<li>' . lsttraining_documentation_inline($item[1], $current_slug) . '</li>';
            continue;
        }
        if (preg_match('/^\s*\d+[.)]\s+(.+)$/', $line, $item)) {
            $close_paragraph();
            if ($list_type !== 'ol') {
                $close_list();
                $list_type = 'ol';
                $html[] = '<ol>';
            }
            $html[] = '<li>' . lsttraining_documentation_inline($item[1], $current_slug) . '</li>';
            continue;
        }

        $close_list();
        $paragraph[] = trim($line);
    }

    if ($in_code) {
        $html[] = '<pre><code>' . esc_html(implode("\n", $code_lines)) . '</code></pre>';
    }
    $close_paragraph();
    $close_list();

    return implode("\n", $html);
}

function lsttraining_documentation_enqueue_style(): void {
    $relative = 'css/documentation.css';
    $path = LSTTRAINING_PATH . $relative;
    wp_enqueue_style(
        'lsttraining-documentation',
        LSTTRAINING_URL . $relative,
        [],
        is_readable($path) ? (string) filemtime($path) : '1.0.0'
    );
}

function lsttraining_documentation_render_home(): string {
    $catalog = lsttraining_documentation_catalog();
    $visible = array_filter($catalog, 'lsttraining_documentation_can_view');
    $is_admin = current_user_can('manage_options');

    ob_start();
    ?>
    <article class="lst-doc-article">
        <header class="lst-doc-hero">
            <p class="lst-doc-kicker">LSTtraining-Wiki</p>
            <h1>Hilfe &amp; Dokumentation</h1>
            <p><?php echo $is_admin
                ? esc_html__('Vollständige Betriebs-, Administrations- und Entwicklerdokumentation.', 'lsttraining')
                : esc_html__('Hier werden ausschließlich die für dein Konto freigegebenen Hilfeseiten angezeigt.', 'lsttraining'); ?></p>
        </header>
        <div class="lst-doc-cards">
            <?php foreach ($visible as $slug => $document) : ?>
                <a class="lst-doc-card" href="<?php echo esc_url(lsttraining_documentation_page_url((string) $slug)); ?>">
                    <strong><?php echo esc_html((string) $document['title']); ?></strong>
                    <span><?php echo esc_html((string) $document['description']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </article>
    <?php
    return (string) ob_get_clean();
}

function lsttraining_documentation_render(array $atts = []): string {
    lsttraining_documentation_enqueue_style();
    if (!is_user_logged_in()) {
        return '<div class="lst-doc-message"><p>' . esc_html__('Bitte melde dich an, um die Dokumentation zu öffnen.', 'lsttraining') . '</p><p><a href="' . esc_url(wp_login_url(lsttraining_documentation_page_url())) . '">' . esc_html__('Anmelden', 'lsttraining') . '</a></p></div>';
    }

    $atts = shortcode_atts(['document' => ''], $atts, 'lsttraining_docs');
    $request_slug = isset($_GET['lst_doc']) ? sanitize_key(wp_unslash($_GET['lst_doc'])) : '';
    $slug = $request_slug !== '' ? $request_slug : sanitize_key((string) $atts['document']);
    if ($slug === '' || $slug === 'home') {
        return '<div class="lst-doc-shell">' . lsttraining_documentation_render_home() . '</div>';
    }

    $catalog = lsttraining_documentation_catalog();
    if (!isset($catalog[$slug]) || !lsttraining_documentation_can_view($catalog[$slug])) {
        return '<div class="lst-doc-message lst-doc-message--error"><h2>' . esc_html__('Keine Berechtigung', 'lsttraining') . '</h2><p>' . esc_html__('Diese Dokumentationsseite ist für dein Konto nicht freigegeben.', 'lsttraining') . '</p><p><a href="' . esc_url(lsttraining_documentation_page_url()) . '">' . esc_html__('Zur Hilfe-Startseite', 'lsttraining') . '</a></p></div>';
    }

    $path = LSTTRAINING_PATH . ltrim((string) $catalog[$slug]['path'], '/\\');
    if (!is_readable($path)) {
        return '<div class="lst-doc-message lst-doc-message--error"><p>' . esc_html__('Die angeforderte Dokumentationsdatei wurde nicht gefunden.', 'lsttraining') . '</p></div>';
    }
    $markdown = file_get_contents($path);
    if ($markdown === false) {
        return '<div class="lst-doc-message lst-doc-message--error"><p>' . esc_html__('Die Dokumentationsdatei konnte nicht gelesen werden.', 'lsttraining') . '</p></div>';
    }

    $visible = array_filter($catalog, 'lsttraining_documentation_can_view');
    ob_start();
    ?>
    <div class="lst-doc-shell">
        <nav class="lst-doc-sidebar" aria-label="Dokumentationsnavigation">
            <a class="lst-doc-home" href="<?php echo esc_url(lsttraining_documentation_page_url()); ?>">LSTtraining-Wiki</a>
            <?php foreach ($visible as $nav_slug => $document) : ?>
                <a href="<?php echo esc_url(lsttraining_documentation_page_url((string) $nav_slug)); ?>" <?php echo $nav_slug === $slug ? 'aria-current="page"' : ''; ?>>
                    <?php echo esc_html((string) $document['title']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <article class="lst-doc-article">
            <nav class="lst-doc-breadcrumb" aria-label="Brotkrumen">
                <a href="<?php echo esc_url(lsttraining_documentation_page_url()); ?>">Wiki</a>
                <span aria-hidden="true">›</span>
                <span><?php echo esc_html((string) $catalog[$slug]['title']); ?></span>
            </nav>
            <div class="lst-doc-markdown">
                <?php echo wp_kses_post(lsttraining_documentation_markdown($markdown, $slug)); ?>
            </div>
        </article>
    </div>
    <?php
    return (string) ob_get_clean();
}

add_shortcode('lsttraining_docs', 'lsttraining_documentation_render');

add_filter('the_content', static function (string $content): string {
    if (is_admin() || !is_singular('page') || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    $page_id = absint(get_option('lsttraining_docs_page_id', 0));
    if ($page_id <= 0 || get_queried_object_id() !== $page_id || has_shortcode($content, 'lsttraining_docs')) {
        return $content;
    }
    return lsttraining_documentation_render();
}, 9);

add_action('wp_enqueue_scripts', static function (): void {
    $page_id = absint(get_option('lsttraining_docs_page_id', 0));
    global $post;
    $has_shortcode = $post instanceof WP_Post && has_shortcode((string) $post->post_content, 'lsttraining_docs');
    if (($page_id > 0 && is_page($page_id)) || $has_shortcode) {
        lsttraining_documentation_enqueue_style();
    }
});
