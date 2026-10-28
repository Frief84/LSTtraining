<?php
define('ABSPATH', __DIR__ . '/');
define('LSTTRAINING_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require_once LSTTRAINING_PATH . 'includes/regions.php';

$cases = [
    [['ILS Sachsen-Anhalt', ''], ['Deutschland', 'Sachsen-Anhalt']],
    [['Bereich Niederösterreich', ''], ['Österreich', 'Niederösterreich']],
    [['Leitstelle Zürich', 'Kanton Zuerich'], ['Schweiz', 'Zürich']],
    [['Nachbarleitstelle Austria', ''], ['Österreich', '']],
    [['Leitstelle Dresden', 'Stadt Dresden, Landkreise Meißen, Sächsische Schweiz-Osterzgebirge'], ['Deutschland', 'Sachsen']],
    [['Feuerwehr-Zug Nord', ''], ['', '']],
    [['Leitstelle Freiburg Deutschland', ''], ['Deutschland', '']],
    [['Unbekannte Region', 'ohne verwertbare Ortsangabe'], ['', '']],
];

foreach ($cases as [$input, $expected]) {
    $actual = lsttraining_region_detect($input[0], $input[1]);
    if (($actual['land'] ?? '') !== $expected[0] || ($actual['bundesland'] ?? '') !== $expected[1]) {
        fwrite(STDERR, 'Regionserkennung fehlgeschlagen: ' . json_encode([$input, $expected, $actual], JSON_UNESCAPED_UNICODE) . PHP_EOL);
        exit(1);
    }
}

$dresden = lsttraining_region_detect_bundesland_for_land(
    'Deutschland',
    'Leitstelle Dresden',
    'Stadt Dresden, Landkreise Meißen, Sächsische Schweiz-Osterzgebirge'
);
if ($dresden !== 'Sachsen') {
    fwrite(STDERR, "Deutschland-spezifische Bundeslanderkennung fehlgeschlagen.\n");
    exit(1);
}

$valid = lsttraining_region_validate_selection('Deutschland', 'Bayern');
$invalid = lsttraining_region_validate_selection('Österreich', 'Bayern');
$missing_country = lsttraining_region_validate_selection('', 'Zürich');
if (!$valid['valid'] || $invalid['valid'] || $missing_country['valid']) {
    fwrite(STDERR, "Regionsvalidierung fehlgeschlagen.\n");
    exit(1);
}

$preserved = lsttraining_region_backfill_values('Österreich', 'Wien', 'Nebenstelle Bayern', 'Deutschland');
$detected = lsttraining_region_backfill_values('', '', 'Nebenstelle Sachsen-Anhalt', '');
if ($preserved !== ['land' => 'Österreich', 'bundesland' => 'Wien'] || $detected !== ['land' => 'Deutschland', 'bundesland' => 'Sachsen-Anhalt']) {
    fwrite(STDERR, "Bestandsdaten-Backfill fehlgeschlagen.\n");
    exit(1);
}

echo "Regionserkennung erfolgreich getestet.\n";
