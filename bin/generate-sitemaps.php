<?php
/**
 * CLI runner for segmented-sitemaps.
 *
 * Usage:
 *   php bin/generate-sitemaps.php <config.php>
 *
 * The config file returns the $cfg array documented in
 * src/segmented-sitemaps.php (see examples/sample-config.php).
 */
if ($argc < 2) {
    fwrite(STDERR, "usage: php bin/generate-sitemaps.php <config.php>\n");
    exit(1);
}
$configFile = $argv[1];
if (!is_file($configFile)) {
    fwrite(STDERR, "ERROR: config file not found: {$configFile}\n");
    exit(1);
}
require __DIR__ . '/../src/segmented-sitemaps.php';
$cfg = require $configFile;
if (!is_array($cfg)) {
    fwrite(STDERR, "ERROR: config file must return an array\n");
    exit(1);
}
$report = djc_sitemaps_generate($cfg);
echo djc_sitemaps_report($report);
if (!$report['ok']) { exit(1); }
if (!empty($cfg['jsonReport'])) {
    file_put_contents($cfg['outDir'] . '/report.json', json_encode($report, JSON_PRETTY_PRINT));
}
