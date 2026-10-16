<?php
/**
 * Builds evergreen sample JSON fixtures (dates relative to today) into a
 * temp dir, so the tests never go stale as the calendar rolls forward.
 *
 * Dataset shape mirrors the production site: artists with tour_dates,
 * events wrapped in {"events":[...]}, venues with slugs, curated cities.
 * Deliberate traps inside:
 *  - Venue with NO upcoming event (dead URL -> must be excluded)
 *  - Venue whose events only carry venue_name (slug comes from name match)
 *  - Artist with only PAST tour dates (still listed, empty lastmod)
 *  - Event with diacritic venue name ("Mörk Börk Klubb" -> mork-bork-klubb)
 *  - Malformed artist id ("Bad Slug!") -> slug guard skips it
 *  - Mojibake venue name ("BakÃ Ä±" case) to pin the double-encode fix
 */
function build_fixtures($dir) {
    $today = date('Y-m-d');
    $ts = function ($offsetDays) use ($today) {
        return date('Y-m-d H:i:s', strtotime($today . " +{$offsetDays} days"));
    };
    $ymd = function ($offsetDays) use ($today) {
        return date('Y-m-d', strtotime($today . " +{$offsetDays} days"));
    };

    $djs = array(
        array('id' => 'ameli', 'name' => 'Amelie Lens', 'tour_dates' => array(
            array('ymd' => $ymd(3), 'venue' => 'Kraken Klubb'),
            array('ymd' => $ymd(40), 'venue' => 'Awakenings'),
        )),
        array('id' => 'tiesto', 'name' => 'Tiësto', 'tour_dates' => array(
            array('ymd' => $ymd(-5), 'venue' => 'LIV'), // past only
            array('ymd' => $ymd(-1), 'venue' => 'XS'),
        )),
        array('id' => 'charlotte-de-witte', 'name' => 'Charlotte de Witte', 'tour_dates' => array(
            array('ymd' => $ymd(1), 'venue' => 'Fuse'),
        )),
        array('id' => 'marmy-summermatter', 'name' => 'Marmy Summermatter', 'tour_dates' => array()), // profile only
        array('id' => 'Bad Slug!', 'name' => 'Slug Guard Bait', 'tour_dates' => array()), // malformed
    );
    file_put_contents($dir . '/djs.json', json_encode($djs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $events = array('events' => array(
        array('slug' => 'kraken-klubb-ameli', 'event_date' => $ts(3), 'venue_name' => 'Kraken Klubb', 'city' => 'Lyon'),
        array('slug' => 'fuse-cdw', 'event_date' => $ts(1), 'venue_name' => 'Fuse', 'city' => 'Brussels'),
        array('slug' => 'mork-night', 'event_date' => $ts(12), 'venue_name' => 'Mörk Börk Klubb', 'city' => 'Tromsø'),
        array('slug' => 'astana-boris', 'event_date' => $ts(20), 'venue_name' => 'Boris Brejcha @ Congress Center Astana', 'city' => 'Astana'),
        array('slug' => 'old-liv-gig', 'event_date' => $ts(-7), 'venue_name' => 'LIV', 'city' => 'Miami Beach'),   // past
        array('slug' => 'old-womb-gig', 'event_date' => $ts(-2), 'venue_name' => 'Output', 'city' => 'Brooklyn'), // past
        array('slug' => 'baki-night', 'event_date' => $ts(8), 'venue_name' => 'BakÃ Ä± Lounge', 'city' => 'BakÃÄ±'), // mojibake
    ));
    file_put_contents($dir . '/events.json', json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $venues = array(
        array('id' => 1, 'name' => 'Kraken Klubb', 'slug' => 'kraken-klubb', 'city' => 'Lyon', 'state' => null),
        array('id' => 2, 'name' => 'Fuse', 'slug' => 'fuse', 'city' => 'Brussels', 'state' => null),
        array('id' => 3, 'name' => 'Mörk Börk Klubb', 'slug' => 'mork-bork-klubb', 'city' => 'Tromsø', 'state' => null),
        array('id' => 4, 'name' => 'Academy LA', 'slug' => 'academy-la', 'city' => 'Los Angeles', 'state' => 'CA'),   // dead: no upcoming
        array('id' => 5, 'name' => 'Output', 'slug' => 'output', 'city' => 'Brooklyn', 'state' => 'NY'),             // dead: past only
        array('id' => 6, 'name' => 'BakÃ Ä± Lounge', 'slug' => 'baki-lounge', 'city' => 'BakÃÄ±', 'state' => null),  // mojibake name, clean slug
        array('id' => 7, 'name' => 'Congress Center Astana', 'slug' => 'congress-center-astana', 'city' => 'Astana', 'state' => null),
    );
    file_put_contents($dir . '/venues.json', json_encode($venues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $cities = array(
        array('id' => 1, 'city_slug' => 'lyon', 'city_name' => 'Lyon', 'city_intro' => 'Curated intro.', 'updated_at' => $ts(0)),
        array('id' => 2, 'city_slug' => 'brussels', 'city_name' => 'Brussels', 'city_intro' => null, 'updated_at' => $ts(0)),
    );
    file_put_contents($dir . '/cities.json', json_encode($cities, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return $today;
}
