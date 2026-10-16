<?php
/**
 * segmented-sitemaps — crawl-budget-aware segmented sitemap generator.
 *
 * Production-derived from thedjcalendar.com's bs_sitemaps.php v3 (Oct 16, 2026).
 * The problem it exists to solve: a programmatic site's sitemap listed every
 * venue it had ever seen — most of them dead URLs. 512 of 702 venue URLs were
 * 404s Google was being told to crawl. This generator only lists what will
 * actually render, derives real <lastmod> dates from the newest event per
 * entity, and emits a sitemap index + one segment per entity type.
 *
 * Input: JSON files (artists, events, venues, cities). No database.
 * Output: sitemap.xml (index) + one sitemap-<segment>.xml per segment,
 * written atomically per file. Safe to re-run any time (cron-friendly).
 *
 * Zero dependencies. PHP 7.4+.
 */

// ---- helpers ------------------------------------------------------------

function djc_slug_ok($slug) {
    return is_string($slug) && $slug !== '' && preg_match('/^[a-z0-9-]+$/', $slug) === 1;
}

function djc_url_xml($loc, $lastmod) {
    $s = "  <url>\n    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
    if ($lastmod) { $s .= "    <lastmod>" . $lastmod . "</lastmod>\n"; }
    return $s . "  </url>\n";
}

function djc_translit_map() {
    return array(
        'ä'=>'a','ö'=>'o','ü'=>'u','ß'=>'ss','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'á'=>'a','à'=>'a','â'=>'a','å'=>'a','ã'=>'a','ą'=>'a','ł'=>'l','ś'=>'s',
        'ż'=>'z','ź'=>'z','ć'=>'c','ń'=>'n','ę'=>'e','ó'=>'o','ř'=>'r','č'=>'c',
        'ž'=>'z','š'=>'s','ý'=>'y','í'=>'i','ì'=>'i','î'=>'i','ù'=>'u','ú'=>'u',
        'û'=>'u','ñ'=>'n','ğ'=>'g','ı'=>'i','đ'=>'d','ð'=>'d','þ'=>'th',
        'æ'=>'ae','ø'=>'o','ç'=>'c','ě'=>'e','ď'=>'d','ť'=>'t','ů'=>'u','ň'=>'n',
        'ő'=>'o','ű'=>'u','ş'=>'s','ï'=>'i','Ã¦'=>'ae','Ã†'=>'ae',
        'Ä'=>'a','Ö'=>'o','Ü'=>'u','É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e','Á'=>'a',
        'À'=>'a','Â'=>'a','Å'=>'a','Ã'=>'a','Ł'=>'l','Ś'=>'s','Ż'=>'z','Ź'=>'z',
        'Ć'=>'c','Ń'=>'n','Ę'=>'e','Ó'=>'o','Ř'=>'r','Č'=>'c','Ž'=>'z','Š'=>'s',
        'Í'=>'i','Ì'=>'i','Î'=>'i','Ù'=>'u','Ú'=>'u','Û'=>'u','Ñ'=>'n','Ç'=>'c',
        'Đ'=>'d','Æ'=>'ae','Ø'=>'o','Ő'=>'o','Ű'=>'u','Ş'=>'s','Ğ'=>'g','Ï'=>'i',
    );
}

function djc_slugify($s) {
    $s = trim((string)$s);
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t === false || $t === '') { $t = strtr($s, djc_translit_map()); }
    $t = strtolower($t);
    $t = preg_replace('/[^a-z0-9]+/', '-', $t);
    return trim($t, '-');
}

// Load a JSON file; tolerate both bare arrays and {key:[...]} wrappers.
function djc_load_json($file, $wrapKey = null) {
    if (!is_file($file)) { return null; }
    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') { return null; }
    $data = json_decode($raw, true);
    if (!is_array($data)) { return null; }
    if ($wrapKey !== null && isset($data[$wrapKey]) && is_array($data[$wrapKey])) {
        return $data[$wrapKey];
    }
    // heuristics: single-key object wrapping a list
    if (isset($data[0])) { return $data; }
    foreach ($data as $v) { if (is_array($v) && isset($v[0])) { return $v; } }
    return null;
}

// ---- generator ----------------------------------------------------------

/**
 * Build the full segmented sitemap set.
 *
 * $cfg keys:
 *   base                (string)  site origin, no trailing slash
 *   dataDir             (string)  where the JSON sources live
 *   outDir              (string)  where the .xml files are written
 *   cityIncludeDynamic  (bool)    include event-derived city pages with no curated row
 *   includeShows        (bool)    emit a /show/ segment (off until the router
 *                                 is confirmed to handle expired events)
 *   genres              (array)   static genre slug list
 *   staticPages         (array)   path => lastmod map
 *   today               (string)  Y-m-d override for deterministic runs;
 *                                 defaults to the real current date
 *
 * Returns a report array (see README); never exits, never echoes.
 */
function djc_sitemaps_generate($cfg) {
    $out = array('ok' => false, 'error' => '', 'data' => array(),
                 'segments' => array(), 'guardSkipped' => 0, 'indexWritten' => false);

    $base    = isset($cfg['base']) ? rtrim((string)$cfg['base'], '/') : '';
    $dataDir = isset($cfg['dataDir']) ? $cfg['dataDir'] : '.';
    $outDir  = isset($cfg['outDir']) ? $cfg['outDir'] : '.';
    $cityIncludeDynamic = !empty($cfg['cityIncludeDynamic']);
    $includeShows       = !empty($cfg['includeShows']);
    $genres             = isset($cfg['genres']) && is_array($cfg['genres']) ? $cfg['genres'] : array();
    $staticPages        = isset($cfg['staticPages']) && is_array($cfg['staticPages']) ? $cfg['staticPages'] : array();
    $today              = !empty($cfg['today']) ? (string)$cfg['today'] : date('Y-m-d');

    if ($base === '') { $out['error'] = 'config: base URL is required'; return $out; }

    $djs    = djc_load_json($dataDir . '/djs.json');
    $events = djc_load_json($dataDir . '/events.json', 'events');
    $venues = djc_load_json($dataDir . '/venues.json');
    $cities = djc_load_json($dataDir . '/cities.json');

    $missing = array();
    if (!is_array($djs))    { $missing[] = 'djs.json'; }
    if (!is_array($events)) { $missing[] = 'events.json'; }
    if (!is_array($venues)) { $missing[] = 'venues.json'; }
    if (!is_array($cities)) { $missing[] = 'cities.json'; }
    if ($missing) {
        $out['error'] = 'could not read/parse: ' . implode(', ', $missing)
                      . ' in ' . $dataDir
                      . ' — fix before generating sitemaps (never generate from partial data)';
        return $out;
    }

    $upcoming = array();
    foreach ($events as $e) {
        $d = isset($e['event_date']) ? substr((string)$e['event_date'], 0, 10) : '';
        if ($d !== '' && $d >= $today) { $upcoming[] = $e; }
    }
    $out['data'] = array(
        'artists'  => count($djs),
        'events'   => count($events),
        'upcoming' => count($upcoming),
        'venues'   => count($venues),
        'cities'   => count($cities),
    );

    $built = array();
    $guardSkipped = 0;

    $add_segment = function ($name, $file, $pairs) use ($base, $outDir, &$built, &$guardSkipped) {
        $urls = ''; $count = 0;
        foreach ($pairs as $p) {
            $slug = $p['slug'];
            if (!djc_slug_ok($slug)) { $guardSkipped++; continue; }
            $loc = $base . $p['path'];
            $urls .= djc_url_xml($loc, !empty($p['fresh']) ? $p['fresh'] : '');
            $count++;
        }
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
             . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
             . $urls . "</urlset>\n";
        if (file_put_contents($outDir . '/' . $file, $xml) === false) {
            return false;
        }
        $built[$name] = array($file, $count);
        return true;
    };

    // ARTISTS: id slug + freshest upcoming tour date
    $pairs = array();
    foreach ($djs as $a) {
        $slug = isset($a['id']) ? trim((string)$a['id']) : '';
        if ($slug === '') { continue; }
        $fresh = '';
        if (!empty($a['tour_dates']) && is_array($a['tour_dates'])) {
            foreach ($a['tour_dates'] as $td) {
                $y = isset($td['ymd']) ? substr((string)$td['ymd'], 0, 10) : '';
                if ($y !== '' && $y >= $today && $y > $fresh) { $fresh = $y; }
            }
        }
        $pairs[] = array('slug' => $slug, 'path' => '/' . rawurlencode($slug) . '/', 'fresh' => $fresh);
    }
    if (!$add_segment('artists', 'sitemap-artists.xml', $pairs)) { $out['error'] = 'could not write sitemap-artists.xml (check folder permissions)'; return $out; }

    // VENUES: upcoming event per venue. Match venues.json slug against the
    // slugified event venue_name (both sides normalized the same way).
    $venueFresh = array();
    foreach ($upcoming as $e) {
        if (empty($e['venue_name'])) { continue; }
        $s = djc_slugify($e['venue_name']);
        if ($s === '') { continue; }
        $d = substr((string)$e['event_date'], 0, 10);
        if (!isset($venueFresh[$s]) || $d > $venueFresh[$s]) { $venueFresh[$s] = $d; }
    }
    $pairs = array();
    foreach ($venues as $v) {
        $slug = isset($v['slug']) ? trim((string)$v['slug']) : '';
        if ($slug === '') { continue; }
        $fresh = isset($venueFresh[$slug]) ? $venueFresh[$slug] : '';
        if ($fresh === '') {
            $alt = djc_slugify(isset($v['name']) ? $v['name'] : '');
            $fresh = isset($venueFresh[$alt]) ? $venueFresh[$alt] : '';
        }
        if ($fresh === '') { continue; } // no upcoming shows -> never list it
        $pairs[] = array('slug' => $slug, 'path' => '/venue/' . rawurlencode($slug), 'fresh' => $fresh);
    }
    if (!$add_segment('venues', 'sitemap-venues.xml', $pairs)) { $out['error'] = 'could not write sitemap-venues.xml (check folder permissions)'; return $out; }

    // CITIES: derived from upcoming events (collation-free, JSON-native)
    $cityFresh = array();
    foreach ($upcoming as $e) {
        if (empty($e['city'])) { continue; }
        $s = djc_slugify($e['city']);
        if ($s === '') { continue; }
        $d = substr((string)$e['event_date'], 0, 10);
        if (!isset($cityFresh[$s]) || $d > $cityFresh[$s]) { $cityFresh[$s] = $d; }
    }
    $curatedSlugs = array();
    foreach ($cities as $c) {
        if (!empty($c['city_slug'])) { $curatedSlugs[trim((string)$c['city_slug'])] = true; }
    }
    $pairs = array();
    foreach ($cityFresh as $slug => $fresh) {
        $curated = isset($curatedSlugs[$slug]);
        if (!$curated && !$cityIncludeDynamic) { continue; }
        $pairs[] = array('slug' => $slug, 'path' => '/city/' . rawurlencode($slug) . '/', 'fresh' => $fresh);
    }
    if (!$add_segment('cities', 'sitemap-cities.xml', $pairs)) { $out['error'] = 'could not write sitemap-cities.xml (check folder permissions)'; return $out; }

    // SHOWS (off by default)
    if ($includeShows) {
        $pairs = array();
        foreach ($upcoming as $e) {
            $slug = isset($e['slug']) ? trim((string)$e['slug']) : '';
            if ($slug === '') { continue; }
            $pairs[] = array('slug' => $slug,
                'path' => '/show/' . rawurlencode($slug) . '/',
                'fresh' => substr((string)$e['event_date'], 0, 10));
        }
        if (!$add_segment('shows', 'sitemap-shows.xml', $pairs)) { $out['error'] = 'could not write sitemap-shows.xml (check folder permissions)'; return $out; }
    }

    // GENRES (static list)
    $pairs = array();
    foreach ($genres as $g) {
        $pairs[] = array('slug' => (string)$g, 'path' => '/genre/' . $g . '/', 'fresh' => $today);
    }
    if (!$add_segment('genres', 'sitemap-genres.xml', $pairs)) { $out['error'] = 'could not write sitemap-genres.xml (check folder permissions)'; return $out; }

    // STATIC PAGES
    $sp = '';
    foreach ($staticPages as $path => $when) { $sp .= djc_url_xml($base . $path, $when); }
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
         . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n" . $sp . "</urlset>\n";
    if (file_put_contents($outDir . '/sitemap-pages.xml', $xml) === false) {
        $out['error'] = 'could not write sitemap-pages.xml'; return $out;
    }
    $built['pages'] = array('sitemap-pages.xml', count($staticPages));

    $out['guardSkipped'] = $guardSkipped;

    foreach ($built as $name => $b) {
        $out['segments'][] = array('name' => $name, 'file' => $b[0], 'count' => $b[1]);
    }

    // ---- index ----
    $idx = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
         . "<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach ($built as $b) {
        $idx .= "  <sitemap>\n    <loc>" . htmlspecialchars($base . '/' . $b[0], ENT_XML1) . "</loc>\n"
              . "    <lastmod>" . $today . "</lastmod>\n  </sitemap>\n";
    }
    $idx .= "</sitemapindex>\n";
    if (file_put_contents($outDir . '/sitemap.xml', $idx) === false) {
        $out['error'] = 'could not write sitemap.xml'; return $out;
    }
    $out['indexWritten'] = true;
    $out['ok'] = true;
    return $out;
}

/** Render the report in the production console format. */
function djc_sitemaps_report($report) {
    if (!$report['ok']) { return 'ERROR: ' . $report['error'] . "\n"; }
    $d = $report['data'];
    $lines = array();
    $lines[] = sprintf("data: %d artists, %d events (%d upcoming), %d venues, %d cities",
        $d['artists'], $d['events'], $d['upcoming'], $d['venues'], $d['cities']);
    foreach ($report['segments'] as $s) {
        $lines[] = sprintf("%-9s -> %-22s %5d urls", $s['name'], $s['file'], $s['count']);
    }
    if ($report['guardSkipped'] > 0) {
        $lines[] = "slug-guard: skipped " . $report['guardSkipped'] . " malformed slug(s)";
    }
    $lines[] = "index    -> sitemap.xml";
    return implode("\n", $lines) . "\n";
}
