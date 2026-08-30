import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, extname, join, relative, resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const read = (path) => readFileSync(join(root, path), 'utf8');
const walk = (dir) => readdirSync(join(root, dir)).flatMap((name) => {
  const path = join(dir, name);
  return statSync(join(root, path)).isDirectory() ? walk(path) : [path];
});
const checks = [];
const check = (name, fn) => {
  fn();
  checks.push(name);
};

check('JavaScript-Syntax', () => {
  for (const file of walk('js').filter((path) => extname(path) === '.js')) {
    execFileSync(process.execPath, ['--check', join(root, file)], { stdio: 'pipe' });
  }
});

check('JSON-Daten', () => {
  for (const file of walk('data').filter((path) => extname(path) === '.json')) {
    JSON.parse(read(file).replace(/^\uFEFF/, ''));
  }
});

check('PHP-Struktur und Bootstrap-Abhängigkeiten', () => {
  const pairs = { '(': ')', '[': ']', '{': '}' };
  const changedPhp = [
    'lsttraining-plugin.php', 'includes/admin-menu.php', 'includes/admin-ui.php', 'includes/benutzer.php', 'includes/documentation.php',
    'includes/fahrzeuge.php', 'includes/help.php',
    'includes/instance-lifecycle.php', 'includes/leitstellen_editor.php', 'includes/migrations.php',
    'includes/nebenstellen_editor.php', 'includes/permissions.php', 'includes/schema_import.php',
    'includes/rest-api.php', 'includes/rest-management-api.php', 'includes/rest-validation.php', 'includes/settings.php', 'includes/simulation-workspace.php', 'includes/wachen.php',
    'includes/ajax/ajax_einsaetze.php', 'includes/ajax/ajax_fahrzeuge.php', 'includes/ajax/ajax_frontend.php',
    'includes/ajax/ajax_hospitals.php', 'includes/ajax/ajax_nebenstellen.php', 'includes/ajax/ajax_simulation.php',
    'includes/ajax/ajax_users.php', 'includes/ajax/ajax_wachen.php', 'includes/simulation/vehicle-state.php'
  ];
  for (const file of changedPhp) {
    const phpBlocks = [...read(file).matchAll(/<\?php([\s\S]*?)(?:\?>|$)/g)].map((match) => match[1]);
    for (const block of [phpBlocks.join('\n')]) {
      const stack = [];
      let quote = '';
      let lineComment = false;
      let blockComment = false;
      for (let i = 0; i < block.length; i += 1) {
        const char = block[i];
        const next = block[i + 1] || '';
        if (lineComment) {
          if (char === '\n') lineComment = false;
          continue;
        }
        if (blockComment) {
          if (char === '*' && next === '/') { blockComment = false; i += 1; }
          continue;
        }
        if (quote) {
          if (char === '\\') { i += 1; continue; }
          if (char === quote) quote = '';
          continue;
        }
        if (char === '/' && next === '/') { lineComment = true; i += 1; continue; }
        if (char === '#') { lineComment = true; continue; }
        if (char === '/' && next === '*') { blockComment = true; i += 1; continue; }
        if (char === "'" || char === '"' || char === '`') { quote = char; continue; }
        if (pairs[char]) stack.push(pairs[char]);
        if (Object.values(pairs).includes(char)) {
          assert.equal(stack.pop(), char, `Klammerfehler in ${file}`);
        }
      }
      assert.equal(stack.length, 0, `Offene Klammer in ${file}`);
    }
  }

  const plugin = read('lsttraining-plugin.php');
  for (const match of plugin.matchAll(/LSTTRAINING_PATH\s*\.\s*'([^']+\.php)'/g)) {
    assert.ok(existsSync(join(root, match[1])), `Bootstrap-Datei fehlt: ${match[1]}`);
  }
});

check('Lokale Admin-Assets', () => {
  const adminUi = read('includes/admin-ui.php');
  const assetPattern = /\$(?:root_url|root_path)\s*\.\s*'([^']+\.(?:js|css|json|png|svg))'/g;
  for (const match of adminUi.matchAll(assetPattern)) {
    assert.ok(existsSync(join(root, match[1])), `Asset fehlt: ${match[1]}`);
  }
  const sourceFiles = [
    ...walk('includes').filter((path) => extname(path) === '.php'),
    ...walk('js').filter((path) => extname(path) === '.js')
  ];
  const localReference = /['"]((?:img|js|css|data|openlayers|vendor)\/[A-Za-z0-9_.\/-]+\.(?:js|css|json|png|svg|jpg|woff2|ttf))['"]/g;
  for (const file of sourceFiles) {
    for (const match of read(file).matchAll(localReference)) {
      assert.ok(existsSync(join(root, match[1])), `Referenz in ${file} fehlt: ${match[1]}`);
    }
  }
});

check('Idempotentes Basisschema', () => {
  const schema = read('database/schema.sql');
  assert.doesNotMatch(schema, /CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i);
  const constraints = [...schema.matchAll(/CONSTRAINT\s+`([^`]+)`/gi)].map((match) => match[1]);
  assert.equal(new Set(constraints).size, constraints.length, 'Constraint-Name doppelt');
  assert.match(schema, /UNIQUE KEY `uk_anp_name`/);
  assert.match(schema, /INSERT IGNORE INTO anrufer_name_pool/);
  assert.match(schema, /`signal_lights_json` LONGTEXT/);
  assert.match(schema, /`patient_profile_json` LONGTEXT/);
});

check('DDL nur in Schema/Migration', () => {
  for (const file of walk('includes').filter((path) => extname(path) === '.php' && path.replaceAll('\\', '/') !== 'includes/migrations.php')) {
    const source = read(file);
    assert.doesNotMatch(source, /(?:CREATE\s+TABLE|ALTER\s+TABLE)/i, `Laufzeit-DDL in ${file}`);
  }
});

check('Keine GET-Löschaktionen', () => {
  for (const file of walk('includes').filter((path) => extname(path) === '.php')) {
    const source = read(file);
    assert.doesNotMatch(source, /\$_GET\s*\[\s*['"]delete_id['"]\s*\]/, `GET-Löschung in ${file}`);
    assert.doesNotMatch(source, /[?&]delete_id=/, `GET-Löschlink in ${file}`);
  }
});

check('Fahrzeug-Endpunkte mit Objekt-Scope', () => {
  const vehicles = read('includes/ajax/ajax_fahrzeuge.php');
  for (const action of ['get_fahrzeug', 'list_fahrzeuge_by_wache', 'save_fahrzeug', 'delete_fahrzeug']) {
    assert.match(vehicles, new RegExp(`wp_ajax_lsttraining_${action}`));
  }
  assert.ok((vehicles.match(/lsttraining_user_can_object\(/g) || []).length >= 5);
  assert.match(vehicles, /require_method\('POST'\)/);
  assert.match(vehicles, /bestehende Fahrzeug/);
  assert.match(vehicles, /Zielwache/);
});

check('Nonce und Methoden für Kern-Schreibwege', () => {
  for (const file of ['includes/ajax/ajax_wachen.php', 'includes/ajax/ajax_hospitals.php', 'includes/ajax/ajax_users.php']) {
    const source = read(file);
    assert.match(source, /nonce_action/);
    assert.match(source, /'method'\s*=>\s*'POST'/);
  }
  assert.match(read('includes/leitstellen_editor.php'), /wp_verify_nonce/);
  assert.match(read('includes/schema_import.php'), /check_admin_referer/);
});

check('Versionierte Migration', () => {
  const migrations = read('includes/migrations.php');
  assert.match(migrations, /LSTTRAINING_SCHEMA_VERSION\s*=\s*2026082601/);
  assert.match(migrations, /lsttraining_schema_store_version\(LSTTRAINING_SCHEMA_VERSION\)/);
  assert.match(read('lsttraining-plugin.php'), /register_activation_hook\(LSTTRAINING_PLUGIN_FILE, 'lsttraining_run_migrations'\)/);
});

check('Serialisierter Tick und lesender Snapshot', () => {
  const simulation = read('includes/ajax/ajax_simulation.php');
  assert.match(simulation, /SELECT GET_LOCK\(\?, 0\)/);
  assert.match(simulation, /SELECT RELEASE_LOCK\(\?\)/);
  assert.match(simulation, /fetch_snapshot\(PDO \$pdo, int \$instanz_id, int \$user_id, bool \$advance_state = false\)/);
  assert.match(simulation, /\$advance_state && !\$simulation_paused/);
  assert.match(simulation, /\$position_changed \|\| \$outside_wache/);
  assert.match(simulation, /unset\([\s\S]*?\$vehicle\['ziel_latitude'\][\s\S]*?\$vehicle\['ziel_longitude'\]/);
  assert.match(simulation, /lsttraining_sim_fetch_snapshot\(\$pdo, \$instanz_id, \(int\) get_current_user_id\(\), true\)/);
});

check('Geschuetzte REST-Status-API', () => {
  const api = read('includes/rest-api.php');
  assert.match(api, /instanzen\/\(\?P<instanz_id>\\d\+\)\/status/);
  assert.match(api, /instanzen\/\(\?P<instanz_id>\\d\+\)\/fahrzeuge/);
  assert.ok((api.match(/permission_callback' => 'lst_rest_can_read_instance_status'/g) || []).length === 2);
  assert.match(api, /instanz_user[\s\S]*connected = 1/);
  assert.match(api, /CASE WHEN ifs\.id IS NULL THEN fs\.fms_status ELSE ifs\.fms_status END/);
  assert.match(api, /Cache-Control', 'no-store, private'/);
  assert.match(api, /lst_rest_update_instance_status/);
  assert.match(api, /lst_rest_update_instance_vehicle/);
  assert.match(api, /rolle = \?/);
  assert.match(api, /lsttraining_sim_update_vehicle_state/);
  assert.ok(existsSync(join(root, 'docs/rest-status-api.md')));
});

check('Geschuetzte REST-Verwaltungs-API', () => {
  const api = read('includes/rest-management-api.php');
  const help = read('includes/help.php');
  const managementDocs = read('docs/rest-management-api.md');
  const statusDocs = read('docs/rest-status-api.md');
  const apiGuide = read('docs/rest-api-praxis.md');
  for (const resource of ['leitstellen', 'nebenleitstellen', 'wachen', 'fahrzeuge', 'krankenhaeuser']) {
    assert.match(api, new RegExp(`'${resource}'`));
  }
  for (const method of ['READABLE', 'CREATABLE', 'EDITABLE', 'DELETABLE']) {
    assert.match(api, new RegExp(`WP_REST_Server::${method}`));
  }
  assert.match(api, /lsttraining_user_can_object/);
  assert.match(api, /lsttraining_user_can_all_leitstellen/);
  assert.match(api, /beginTransaction\(\)/);
  assert.match(api, /rollBack\(\)/);
  assert.match(api, /'confirm'.*'required' => true/);
  assert.match(api, /lsttraining_log_activity/);
  assert.match(read('lsttraining-plugin.php'), /includes\/rest-management-api\.php/);
  assert.match(help, /REST-API – vollständige Referenz/);
  assert.match(help, /\/verwaltung\/\{ressource\}\/\{id\}/);
  assert.match(help, /\/instanzen\/\{instanz_id\}\/fahrzeuge\/\{status_id\}/);
  assert.match(managementDocs, /Vollstaendige Feldreferenz/);
  assert.match(managementDocs, /HTTP-Statuscodes und Fehlerwerte/);
  assert.match(statusDocs, /Live-Zustand schreiben/);
  assert.match(apiGuide, /Daten hochladen/);
  assert.match(apiGuide, /Objekte verschieben/);
  assert.match(apiGuide, /data_base64/);
  assert.match(apiGuide, /wache_id/);
});

check('Strikte REST-Eingabe- und Bildvalidierung', () => {
  const validation = read('includes/rest-validation.php');
  const management = read('includes/rest-management-api.php');
  const statusApi = read('includes/rest-api.php');
  const docs = read('docs/rest-management-api.md');
  const help = read('includes/help.php');

  for (const helper of [
    'lsttraining_rest_json_object',
    'lsttraining_rest_assert_safe_string',
    'lsttraining_rest_strict_integer',
    'lsttraining_rest_strict_number',
    'lsttraining_rest_strict_boolean',
    'lsttraining_rest_id_list',
    'lsttraining_rest_geojson',
    'lsttraining_rest_departments',
    'lsttraining_rest_signal_lights',
    'lsttraining_rest_image_input',
    'lsttraining_rest_trusted_image_reference',
    'lsttraining_rest_sanitize_svg',
    'lsttraining_rest_store_sanitized_image'
  ]) {
    assert.match(validation, new RegExp(`function ${helper}\\b`));
  }
  assert.match(validation, /imagecreatefromstring/);
  assert.match(validation, /getimagesizefromstring/);
  assert.match(validation, /base64_decode\(\$base64, true\)/);
  assert.match(validation, /imagepng/);
  assert.match(validation, /imagejpeg/);
  assert.match(validation, /DOMDocument/);
  assert.match(validation, /LIBXML_NONET/);
  assert.match(validation, /foreignObject/);
  assert.match(validation, /wp_unique_filename/);
  assert.match(validation, /wp_delete_file/);
  assert.match(validation, /muss Bilddaten mitsenden/);
  assert.match(validation, /Unbekannte Felder/);
  assert.match(management, /lst_manage_materialize_images/);
  assert.match(management, /lst_manage_cleanup_created_images/);
  assert.ok((statusApi.match(/lsttraining_rest_json_object\(/g) || []).length >= 3);
  assert.match(read('lsttraining-plugin.php'), /includes\/rest-validation\.php/);
  assert.match(docs, /vollstaendig decodiert/);
  assert.match(docs, /data_base64/);
  assert.match(help, /vollständig neu codiert/);
  assert.match(help, /Daten hochladen und ändern/);
  assert.match(help, /Verschieben und Zuordnungen ändern/);
  assert.match(help, /base64/);
  assert.match(help, /wache_id/);
});

check('Benutzerrechte pro Bereich und Leitstelle', () => {
  const permissions = read('includes/permissions.php');
  for (const area of ['leitstellen', 'nebenstellen', 'hospitals', 'wachen', 'fahrzeuge']) {
    assert.match(permissions, new RegExp(`'${area}'`));
  }
  assert.match(permissions, /lsttraining_user_can_all_leitstellen/);
  assert.match(permissions, /fahrzeuge f JOIN wache_leitstellen/);
  const users = read('includes/benutzer.php');
  assert.match(users, /user_leitstelle_permissions/);
  assert.match(users, /name="ulp\[/);
});

check('Integrierte Hilfe und technische Dokumentation', () => {
  const menu = read('includes/admin-menu.php');
  const adminUi = read('includes/admin-ui.php');
  const help = read('includes/help.php');
  const docs = read('docs/sicherheit-migration-multiplayer.md');
  assert.match(menu, /lsttraining_hilfe/);
  assert.match(menu, /lsttraining_render_help/);
  assert.match(adminUi, /function lsttraining_render_help/);
  assert.match(help, /current_user_can\('read'\)/);
  assert.match(help, /\$can_manage_content/);
  assert.match(help, /Spielerhandbuch/);
  assert.match(help, /Administrations- und Entwickler-Wiki/);
  assert.match(help, /lsttraining_schema_installed_version/);
  for (const heading of ['Berechtigungsmodell', 'CSRF-', 'Datenbankmigrationen', 'Multiplayer-Ticks', 'lesender Snapshot', 'Signallicht-Grafiken', 'Automatisierte Prüfungen']) {
    assert.ok(docs.includes(heading), `Dokumentationsabschnitt fehlt: ${heading}`);
  }
  assert.match(read('README.md'), /Hilfe & Dokumentation/);
});

check('Vollstaendige Wiki-Dokumentation', () => {
  const requiredPages = [
    'docs/README.md', 'docs/_Sidebar.md', 'docs/erste-schritte.md', 'docs/spielerhandbuch.md',
    'docs/administration.md', 'docs/simulation-und-multiplayer.md',
    'docs/betrieb-und-fehlerbehebung.md', 'docs/entwickleruebersicht.md',
    'docs/leitstellen-editor.md', 'docs/nebenleitstellen-editor.md',
    'docs/krankenhaeuser-editor.md', 'docs/wachen-editor.md',
    'docs/fahrzeuge-editor.md', 'docs/polizei-und-unterstuetzungsfahrzeuge.md',
    'docs/rest-api-praxis.md'
  ];
  for (const page of requiredPages) {
    assert.ok(existsSync(join(root, page)), `Wiki-Seite fehlt: ${page}`);
  }

  const administration = read('docs/administration.md');
  for (const topic of ['Leitstellen', 'Nebenleitstellen', 'Krankenhäuser', 'Wachen', 'Fahrzeuge', 'Einsatzvorlagen', 'Anrufe und Anruferprofile']) {
    assert.ok(administration.includes(topic), `Administrationskapitel fehlt: ${topic}`);
  }

  const markdownFiles = ['README.md', ...walk('docs').filter((path) => extname(path) === '.md')];
  for (const file of markdownFiles) {
    for (const match of read(file).matchAll(/\[[^\]]+\]\(([^)]+)\)/g)) {
      const rawTarget = match[1].trim().replace(/^<|>$/g, '');
      if (/^(?:https?:|mailto:|#)/i.test(rawTarget)) continue;
      const target = decodeURIComponent(rawTarget.split('#')[0]);
      if (!target) continue;
      assert.ok(existsSync(resolve(root, dirname(file), target)), `Toter Doku-Link in ${file}: ${rawTarget}`);
    }
  }
});

check('Rollenbasierter WordPress-Dokumentationsviewer', () => {
  const viewer = read('includes/documentation.php');
  const settings = read('includes/settings.php');
  const help = read('includes/help.php');
  const plugin = read('lsttraining-plugin.php');
  assert.match(plugin, /includes\/documentation\.php/);
  assert.match(viewer, /add_shortcode\('lsttraining_docs'/);
  assert.match(viewer, /add_filter\('the_content'/);
  assert.match(viewer, /}, 9\);/);
  assert.match(viewer, /lsttraining_documentation_markdown/);
  assert.match(viewer, /wp_kses_post\(lsttraining_documentation_markdown/);
  assert.match(viewer, /'audience' => 'player'/);
  assert.match(viewer, /'audience' => 'admin'/);
  assert.match(viewer, /lsttraining_documentation_can_view/);
  assert.match(viewer, /user_can\(\$user_id, 'manage_options'\)/);
  const editorAreas = {
    'leitstellen-editor': 'leitstellen',
    'nebenleitstellen-editor': 'nebenstellen',
    'krankenhaeuser-editor': 'hospitals',
    'wachen-editor': 'wachen',
    'fahrzeuge-editor': 'fahrzeuge'
  };
  for (const [slug, area] of Object.entries(editorAreas)) {
    assert.match(viewer, new RegExp(`'${slug}'[\\s\\S]*?'areas' => \\['${area}'\\]`));
  }
  assert.match(viewer, /'polizei-und-unterstuetzungsfahrzeuge'[\s\S]*?'areas' => \['leitstellen', 'fahrzeuge'\]/);
  for (const page of walk('docs').map((path) => path.replaceAll('\\', '/')).filter((path) => extname(path) === '.md' && !['docs/README.md', 'docs/_Sidebar.md'].includes(path))) {
    assert.ok(viewer.includes(`'${page}'`), `Markdown-Seite fehlt im WordPress-Viewer: ${page}`);
  }
  assert.match(settings, /lsttraining_docs_page_id/);
  assert.match(settings, /\[lsttraining_docs\]/);
  assert.match(viewer, /has_shortcode\(\(string\) \$post->post_content, 'lsttraining_docs'\)/);
  assert.match(help, /lsttraining_documentation_page_url/);
  assert.match(help, /Backend-Editoren/);
  assert.match(help, /lsttraining_documentation_can_view\(\$documentation_catalog\[\$editor_slug\]\)/);
  assert.ok(existsSync(join(root, 'css/documentation.css')), 'Stylesheet des Dokumentationsviewers fehlt');
});

console.log(`OK: ${checks.length} Prüfgruppen`);
for (const name of checks) {
  console.log(`  ✓ ${name}`);
}
