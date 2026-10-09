<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PreDB Feed Importer
    |--------------------------------------------------------------------------
    |
    | Polls public PreDB JSON APIs / RSS feeds and upserts entries into the
    | predb table (same semantics as the IRC scraper). Scheduled every five
    | minutes when enabled; run manually with `php artisan predb:import-feed`.
    |
    */

    'enabled' => (bool) env('PREDB_FEEDS_ENABLED', false),

    'sources' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PREDB_FEED_SOURCES', 'predb_club,predb_net,predb_me'))
    ))),

    'page_size' => (int) env('PREDB_FEED_PAGE_SIZE', 100),

    'timeout' => (int) env('PREDB_FEED_TIMEOUT', 15),

    'user_agent' => (string) env('PREDB_FEED_USER_AGENT', 'NNTmux-PreDB-Importer/1.0'),

    'endpoints' => [
        'predb_club' => 'https://predb.club/api/v1/',
        'predb_net' => 'https://api.predb.net/',
        'predb_me' => (string) env('PREDB_FEED_RSS_URL', 'https://predb.me/?rss=1'),
    ],
];
