import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { extname, join, relative, resolve } from 'node:path';

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
    'lsttraining-plugin.php', 'includes/admin-menu.php', 'includes/admin-ui.php', 'includes/benutzer.php', 'includes/fahrzeuge.php',
    'includes/help.php',
    'includes/instance-lifecycle.php', 'includes/leitstellen_editor.php', 'includes/migrations.php',
    'includes/nebenstellen_editor.php', 'includes/permissions.php', 'includes/schema_import.php',
    'includes/settings.php', 'includes/simulation-workspace.php', 'includes/wachen.php',
    'includes/ajax/ajax_einsaetze.php', 'includes/ajax/ajax_fahrzeuge.php', 'includes/ajax/ajax_frontend.php',
    'includes/ajax/ajax_hospitals.php', 'includes/ajax/ajax_nebenstellen.php', 'includes/ajax/ajax_simulation.php',
    'includes/ajax/ajax_users.php', 'includes/ajax/ajax_wachen.php'
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
  for (const file of walk('includes').filter((path) => extname(path) === '.php' && path !== 'includes/migrations.php')) {
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

check('Benutzerrechte pro Bereich und Leitstelle', () => {
  const permissions = read('includes/permissions.php');
  for (const area of ['leitstellen', 'nebenstellen', 'hospitals', 'wachen', 'fahrzeuge']) {
    assert.match(permissions, new RegExp(`'${area}'`));
  }
  assert.match(permissions, /lsttraining_user_can_all_leitstellen/);
  assert.match(permissions, /fahrzeuge f JOIN wache_leitstellen/);
  assert.match(read('includes/benutzer.php'), /select name="leitstellen_ids_/);
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
  assert.match(help, /lsttraining_schema_installed_version/);
  for (const heading of ['Berechtigungsmodell', 'CSRF-', 'Datenbankmigrationen', 'Multiplayer-Ticks', 'lesender Snapshot', 'Signallicht-Grafiken', 'Automatisierte Prüfungen']) {
    assert.ok(docs.includes(heading), `Dokumentationsabschnitt fehlt: ${heading}`);
  }
  assert.match(read('README.md'), /Hilfe & Dokumentation/);
});

console.log(`OK: ${checks.length} Prüfgruppen`);
for (const name of checks) {
  console.log(`  ✓ ${name}`);
}
