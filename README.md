# segmented-sitemaps

**Your sitemap lists 702 venues. 512 of them are dead. Stop telling Google.**

That sentence came from a real diagnostic on a real 1,000-page event directory
([The DJ Calendar](https://thedjcalendar.com)). The old sitemap listed every
venue the site had ever heard of. Most had no upcoming shows - the URLs 404ed,
and the site was actively telling Google to crawl hundreds of pages that don't
exist. Crawl budget went to corpses while live pages waited in line.

This library is the fix, extracted verbatim from production. It builds a
**segmented sitemap set** where every listed URL is proven live by construction:
an entity only enters the sitemap if it has an upcoming event, and every
`<lastmod>` is derived from real data instead of stamped with today's date.

[![CI](https://github.com/bryanhamiltondev/segmented-sitemaps/actions/workflows/ci.yml/badge.svg)](https://github.com/bryanhamiltondev/segmented-sitemaps/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb3) ![dependencies](https://img.shields.io/badge/dependencies-0-brightgreen) [![Live demo](https://img.shields.io/badge/demo-live-00d2ff)](https://bryanhamiltondev.github.io/segmented-sitemaps/)

## The production numbers

One run on the real site:

| segment | before | after | why |
|---|---|---|---|
| venues | 702 | 190 | 512 venues had no upcoming show - dead URLs, gone |
| cities | 5 | 169 | cities are derived from upcoming events, not a stale curated list |
| artists | 99 | 99 | profiles always render - all stay |
| **total** | ~900 | **473** | every remaining URL provably renders |

`Crawled - not indexed` on venue/city URLs is the metric that should collapse
after a switch like this. That's the point.

## How it decides

The rules, in order of how hard they bite:

1. **Only upcoming events count.** Past shows 404 on the production router
   (confirmed against the real `error_404_log`). An event is upcoming if its
   date is `>= today`.
2. **A venue without an upcoming event is never listed.** This is the whole
   ballgame: the sitemap *undercounts* rather than ever pointing Google at a
   404. A wrong URL can never get in; a right one can only get left out.
3. **Venue matching is slug-normalized on both sides.** `venues.json` slugs are
   matched against the slugified event `venue_name` (with a fallback attempt on
   the venue's own name). Pseudo-venues like `"Boris Brejcha @ Congress Center
   Astana"` sitting in event venue names simply match nothing and drop out -
   that's correct: those pages were noise anyway.
4. **Cities are derived, curated-only is a flag.** By default any city with an
   upcoming event gets listed (the production router renders city pages
   dynamically). Flip `cityIncludeDynamic` off to list only cities that have a
   curated row.
5. **Every slug passes `^[a-z0-9-]+$`** before it enters XML. Bad slugs are
   counted and reported, never emitted.
6. **`<lastmod>` means something.** Artists get the *latest* upcoming tour
   date; venues and cities the freshest upcoming event date. The sitemap index
   itself gets today.
7. **Never generate from partial data.** If any source file is missing or
   unparseable, the run fails loudly and writes nothing. Half a sitemap is
   worse than a stale one.

## Quick start

```bash
# run the self-tests (zero dependencies, no PHPUnit)
php tests/run-tests.php

# generate sitemaps from the bundled sample data
SITEMAP_OUTDIR=/tmp/out php bin/generate-sitemaps.php examples/sample-config.php

# wire it to your site: copy examples/sample-config.php, adjust, run via cron
# 0 5 * * * php /path/to/bin/generate-sitemaps.php /path/to/config.php
```

### Config

```php
return array(
    'base'               => 'https://www.example.com', // no trailing slash
    'dataDir'            => __DIR__ . '/data',         // where the JSON lives
    'outDir'             => __DIR__,                   // where XML is written
    'cityIncludeDynamic' => true,   // list event-derived cities with no curated row
    'includeShows'       => false,  // /show/ segment: off until your router
                                    // provably handles expired event URLs
    'genres'             => array('techno', 'house'),
    'staticPages'        => array('/' => '2026-10-16'),
    'jsonReport'         => true,   // also write report.json next to the XML
    // 'today' => '2026-10-16',        // optional override for deterministic runs
);
```

### Input shapes (tolerant)

- `djs.json` - bare array of `{id, name, tour_dates:[{ymd, ...}]}`
- `events.json` - `{"events":[{slug, event_date, venue_name, city, ...}]}` (bare arrays also work)
- `venues.json` - bare array of `{id, name, slug, city, ...}`
- `cities.json` - bare array of `{city_slug, city_name, ...}`

The loader handles bare arrays and single-key wrapper objects alike, so you
don't have to re-export your data to fit the tool.

### Output

`sitemap.xml` (index) plus one `sitemap-<segment>.xml` per segment, and a
console/JSON report:

```
data: 5 artists, 7 events (5 upcoming), 7 venues, 2 cities
artists   -> sitemap-artists.xml        4 urls
venues    -> sitemap-venues.xml         4 urls
cities    -> sitemap-cities.xml         5 urls
genres    -> sitemap-genres.xml         6 urls
pages     -> sitemap-pages.xml          8 urls
slug-guard: skipped 1 malformed slug(s)
index    -> sitemap.xml
```

Submit `/sitemap.xml` once in Search Console. Re-run on cron after every data
sync - each run rewrites the full set atomically per file, so a partially
written run can never publish a torn file.

## Design decisions

- **JSON-native, no database.** The production predecessor started as a PDO
  version against MySQL and was retired: reading the site's own export files
  meant zero collation fights, zero credentials in the script, and the data
  source was guaranteed identical to what the site itself renders from. If
  your site renders from MySQL, export or query into these four shapes and
  keep the same property: the sitemap is built from exactly what the site
  serves.
- **Web runner and CLI share one library.** The production file is a
  token-gated web endpoint (so a cron one-liner of `curl` can refresh the
  set); the CLI here is the same library behind a config file. No secrets ship
  in this repo - your token stays on your server.
- **Mojibake-proof slugification.** Real-world venue data arrives
  double-encoded (`BakÃ Ä±` for Bakı). Slugification tries `iconv` translit
  first and falls back to an explicit transliteration map that handles the
  double-encoded pairs explicitly. Either way, output is guard-clean ASCII.
- **Zero dependencies.** Plain PHP, plain `simplexml` in tests only. No
  Composer, no framework, no config DSL.

## Tests

`php tests/run-tests.php` - 47 contract checks covering both directions:
slug guard accepts/rejects, diacritics and mojibake slugification, JSON
wrapper tolerance, the upcoming filter, venue undercount-never-404 behavior,
curated-only mode, the shows toggle, index composition, XML well-formedness,
the production report format, and the fail-loudly path. Fixtures are built at
runtime with dates relative to today, so the suite never goes stale.

## Demo

[Live demo](https://bryanhamiltondev.github.io/segmented-sitemaps/) - the real
generator run against the bundled sample dataset, output embedded verbatim.
No mocks.

## Sibling repos

Extracted from the same production site: [instant-search-index](https://github.com/bryanhamiltondev/instant-search-index) (client-side search), [dj-card-preview](https://github.com/bryanhamiltondev/dj-card-preview) (audio hover previews), [tour-feed-pipeline](https://github.com/bryanhamiltondev/tour-feed-pipeline) (tour API normalization), [next-show-radar](https://github.com/bryanhamiltondev/next-show-radar) (geolocation-aware maps).

## License

MIT
