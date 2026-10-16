<?php
/**
 * Contract self-tests for segmented-sitemaps. Zero dependencies, plain PHP.
 * Fixtures are evergreen: built at runtime with dates relative to today.
 * Run: php tests/run-tests.php
 */
require __DIR__ . '/fixtures.php';
require __DIR__ . '/../src/segmented-sitemaps.php';

$PASS = 0; $FAIL = 0;
function check($cond, $label) {
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "  ok  $label\n"; }
    else { $FAIL++; echo "FAIL  $label\n"; }
}
function xml_urls($file) {
    $x = simplexml_load_file($file);
    if ($x === false) { return null; }
    $out = array();
    foreach ($x->url as $u) {
        $out[(string)$u->loc] = isset($u->lastmod) ? (string)$u->lastmod : '';
    }
    return $out;
}

$today = date('Y-m-d');
$d = function ($n) use ($today) { return date('Y-m-d', strtotime("$today +$n days")); };

// ---- unit: slug guard ----
check(djc_slug_ok('tiesto') === true, 'slug guard accepts clean slug');
check(djc_slug_ok('') === false, 'slug guard rejects empty');
check(djc_slug_ok('Bad Slug!') === false, 'slug guard rejects spaces/symbols');
check(djc_slug_ok(null) === false, 'slug guard rejects non-string');

// ---- unit: slugify ----
check(djc_slugify('Mörk Börk Klubb') === 'mork-bork-klubb', 'slugify: diacritics transliterate');
check(djc_slugify('  Tiësto  ') === 'tiesto', 'slugify: trims and folds');
check(preg_match('/^[a-z0-9-]+$/', djc_slugify('BakÃ Ä± Lounge')) === 1, 'slugify: mojibake still yields a guard-safe slug');
check(djc_slugify('!!!') === '', 'slugify: symbol-only -> empty');
check(array_search('ae', djc_translit_map()) !== false, 'translit map covers the double-encoded ae pair');

// ---- unit: json loader ----
$tmp = tempnam(sys_get_temp_dir(), 'djc');
file_put_contents($tmp, '[{"a":1}]');
check(is_array(djc_load_json($tmp)), 'load_json: bare array');
file_put_contents($tmp, '{"events":[{"a":1}]}');
check(count(djc_load_json($tmp, 'events')) === 1, 'load_json: wrapped key');
check(count(djc_load_json($tmp)) === 1, 'load_json: single-key wrapper heuristic');
file_put_contents($tmp, 'not json');
check(djc_load_json($tmp) === null, 'load_json: garbage -> null');
check(djc_load_json('/nope/nope.json') === null, 'load_json: missing file -> null');
unlink($tmp);

// ---- integration: full generation ----
$dataDir = sys_get_temp_dir() . '/djc-fixtures-' . getmypid();
$outDir  = sys_get_temp_dir() . '/djc-out-' . getmypid();
mkdir($dataDir, 0777, true); mkdir($outDir, 0777, true);
build_fixtures($dataDir);

$cfg = array(
    'base' => 'https://www.example.com',
    'dataDir' => $dataDir,
    'outDir' => $outDir,
    'cityIncludeDynamic' => true,
    'includeShows' => false,
    'genres' => array('techno', 'house', 'tech-house', 'electro-house', 'trance', 'edm'),
    'staticPages' => array(
        '/' => '2026-10-16', '/artists/' => '2026-10-16', '/venues/' => '2026-10-16',
        '/genres/' => '2026-10-16', '/shows/' => '2026-10-16', '/calendar/' => '2026-10-16',
        '/contact.php' => '2026-10-16', '/privacy/' => '2026-10-16',
    ),
);
$report = djc_sitemaps_generate($cfg);
check($report['ok'] === true, 'generation succeeds on the sample dataset');
check($report['data']['events'] === 7 && $report['data']['upcoming'] === 5, 'upcoming filter: 7 events -> 5 upcoming (past excluded)');
check($report['guardSkipped'] === 1, 'slug guard: exactly 1 malformed artist id skipped');

$artists = xml_urls($outDir . '/sitemap-artists.xml');
check($artists !== null && count($artists) === 4, 'artists: 5 entries -> 4 listed (1 guard skip)');
check(isset($artists['https://www.example.com/ameli/']), 'artists: clean ids become /{slug}/ urls');
check(isset($artists['https://www.example.com/charlotte-de-witte/']), 'artists: multi-word id kept');
check($artists['https://www.example.com/ameli/'] === $d(40), 'artists: lastmod = LATEST upcoming tour date (max, per production)');
check($artists['https://www.example.com/tiesto/'] === '', 'artists: past-only artist listed with empty lastmod');
check(!isset($artists['https://www.example.com/Bad%20Slug!/']), 'artists: malformed id never enters XML');

$venues = xml_urls($outDir . '/sitemap-venues.xml');
check($venues !== null && isset($venues['https://www.example.com/venue/kraken-klubb']), 'venues: upcoming venue listed');
check($venues['https://www.example.com/venue/kraken-klubb'] === $d(3), 'venues: lastmod from freshest upcoming event');
check(isset($venues['https://www.example.com/venue/mork-bork-klubb']), 'venues: diacritic venue_name slugifies to the venues.json slug');
check(!isset($venues['https://www.example.com/venue/academy-la']), 'venues: never-had-shows venue excluded');
check(!isset($venues['https://www.example.com/venue/output']), 'venues: past-only venue excluded');
check(!isset($venues['https://www.example.com/venue/congress-center-astana']), 'venues: pseudo-venue event string never lists the real venue (undercount, never 404)');

$cities = xml_urls($outDir . '/sitemap-cities.xml');
check(isset($cities['https://www.example.com/city/lyon/']), 'cities: event-derived city listed');
check($cities['https://www.example.com/city/lyon/'] === $d(3), 'cities: lastmod from freshest upcoming event');

// curated-only mode
$cfg2 = $cfg; $cfg2['cityIncludeDynamic'] = false; $cfg2['outDir'] = $outDir . '2'; mkdir($cfg2['outDir']);
$r2 = djc_sitemaps_generate($cfg2);
$cities2 = xml_urls($cfg2['outDir'] . '/sitemap-cities.xml');
check(count($cities2) === 2 && isset($cities2['https://www.example.com/city/lyon/'], $cities2['https://www.example.com/city/brussels/']), 'cities: curated-only mode lists exactly the cities.json rows');

// shows segment toggle
check(!is_file($outDir . '/sitemap-shows.xml'), 'shows: OFF by default -> no segment file');
$cfg3 = $cfg; $cfg3['includeShows'] = true; $cfg3['outDir'] = $outDir . '3'; mkdir($cfg3['outDir']);
$r3 = djc_sitemaps_generate($cfg3);
$shows = xml_urls($cfg3['outDir'] . '/sitemap-shows.xml');
check($shows !== null && count($shows) === 5, 'shows: ON -> exactly the 5 upcoming events');
check($shows['https://www.example.com/show/kraken-klubb-ameli/'] === $d(3), 'shows: lastmod = event date');

// genres + pages
$genres = xml_urls($outDir . '/sitemap-genres.xml');
check($genres !== null && count($genres) === 6, 'genres: static list emitted');
$pages = xml_urls($outDir . '/sitemap-pages.xml');
check($pages !== null && count($pages) === 8, 'pages: static map emitted');

// index
$idx = simplexml_load_file($outDir . '/sitemap.xml');
check($idx !== false && count($idx->sitemap) === 5, 'index: one entry per built segment (shows OFF)');
$idxLocs = array();
foreach ($idx->sitemap as $s) { $idxLocs[] = (string)$s->loc; }
check(in_array('https://www.example.com/sitemap-artists.xml', $idxLocs, true), 'index: lists segment files');
check(!in_array('https://www.example.com/sitemap-shows.xml', $idxLocs, true), 'index: disabled segment absent');

// well-formedness of every emitted file
$allFiles = array('sitemap.xml', 'sitemap-artists.xml', 'sitemap-venues.xml', 'sitemap-cities.xml', 'sitemap-genres.xml', 'sitemap-pages.xml');
$wf = true;
foreach ($allFiles as $f) { if (simplexml_load_file($outDir . '/' . $f) === false) { $wf = false; } }
check($wf, 'XML: every emitted file parses clean');

// ---- report format (production console format) ----
$txt = djc_sitemaps_report($report);
check(preg_match('/^data: \d+ artists, \d+ events \(\d+ upcoming\), \d+ venues, \d+ cities\n/', $txt) === 1, 'report: production data line');
check(preg_match('/^artists\s+-> sitemap-artists\.xml\s+\d+ urls$/m', $txt) === 1, 'report: segment line format');
check(strpos($txt, 'slug-guard: skipped 1 malformed slug(s)') !== false, 'report: guard skip surfaced');
check(preg_match('/^index\s+-> sitemap\.xml$/m', $txt) === 1, 'report: index line');

// ---- failure path ----
$cfg4 = $cfg; $cfg4['dataDir'] = $dataDir . '/nowhere'; $cfg4['outDir'] = $outDir . '4'; mkdir($cfg4['outDir']);
$r4 = djc_sitemaps_generate($cfg4);
check($r4['ok'] === false && strpos($r4['error'], 'djs.json') !== false, 'missing data -> error naming the files, never partial output');
check(!is_file($cfg4['outDir'] . '/sitemap.xml'), 'missing data -> no files written');

echo "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
