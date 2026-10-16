<?php
// Sample config for the bundled example dataset (examples/data).
// Replace base, dataDir and outDir for a real site.
return array(
    'base'               => 'https://www.example.com',
    'dataDir'            => __DIR__ . '/data',
    'outDir'             => getenv('SITEMAP_OUTDIR') ?: (__DIR__ . '/out'),
    'cityIncludeDynamic' => true,
    'includeShows'       => false,
    'jsonReport'         => true,
    'today'              => '2026-10-16', // pinned so the committed demo output is reproducible
    'genres'             => array('techno', 'house', 'tech-house', 'electro-house', 'trance', 'edm'),
    'staticPages'        => array(
        '/'             => '2026-10-16',
        '/artists/'     => '2026-10-16',
        '/venues/'      => '2026-10-16',
        '/genres/'      => '2026-10-16',
        '/shows/'       => '2026-10-16',
        '/calendar/'    => '2026-10-16',
        '/contact.php'  => '2026-10-16',
        '/privacy/'     => '2026-10-16',
    ),
);
