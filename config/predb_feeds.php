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

    // Default keys: predb_club, predb_net, predb_me, predatabase, xrel, xrel_p2p. These OPT-IN sources are never
    // in the default list; add them to PREDB_FEED_SOURCES explicitly:
    //  - predb_org: predb.org history import (newest entries trail by about a day).
    //  - srrdb: first page only (terms: "Use but don't scrape").
    //  - scnsrc_me: scnsrc.me RSS; titles use spaces, so names are lossy (dots are guessed).
    //  - eztv: EZTV RSS; P2P TV names, lossy spaced titles; robots.txt unverified (Cloudflare challenge).
    //  - predb_ovh: predb.ovh JSON API; documented v1 API; not yet verified against live responses.
    //  - apibay: The Pirate Bay top-100 recent dump (movies/TV only); P2P names, not scene PREs.
    //  - nfodb: music catalogue RSS with exact titles; mixed scene/P2P listings, not verified PRE times.
    //  - one_techno: music catalogue RSS; exact titles, with publication dates never used as PRE times.
    //  - one_gabba: music catalogue RSS with exact titles; publication dates are listing times only.
    //  - ddlvalley: exact movie/TV catalogue titles; publication dates are listing times only.
    //  - hdencode: movie catalogue titles with terminal display sizes removed; listing dates only.
    //  - hdencode_tv: TV catalogue titles with terminal display sizes removed; listing dates only.
    //  - mp3scene: historical music catalogue names; current listing dates are not historical PRE times.
    //  - nima4k: bounded latest homepage catalogue names; listing dates and PRE times unknown.
    //  - gload: literal game catalogue RSS titles; publication dates are listing times only.
    //  - scnlog: literal release RSS titles and categories; publication dates are listing times only.
    //  - knaben: latest movie catalogue identifiers with uploader suffixes preserved; dates and PRE times unknown.
    //  - seriousaboutsound: latest literal music forum headings; topic dates and PRE metadata unknown.
    //  - crowdnfo: latest canonical catalogue headings, including contributed names; dates and PRE times unknown.
    //  - movieblog: literal RSS release titles, latest page only; publication dates are listing times only.
    //  - hdworld: literal German movie/TV catalogue RSS titles; publication dates are listing times only.
    //  - gabber: latest printed-day music directory names, one request with no retries or redirects; dates unknown.
    //  - g4u: bounded latest-day homepage game titles; listing dates and PRE times unknown.
    //  - 3dsdb: manual complete XML catalogue (--source=3dsdb --pages=100), ordered by catalogue ID, not PRE time.
    //  - scene_archive_sql: manual local nine-column 2007 SQL dictionary; parsed, never executed.
    //  - dats_pciso_1998: manual independent literal MIA names only; decorated titles/file records are excluded.
    //  - nzedb_archive: manual retained May 2024 gzip title catalogue; dates/PRE metadata unknown.
    //  - nswdb: manual complete XML catalogue (--source=nswdb --pages=100), ordered by catalogue ID, not PRE time.
    'sources' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PREDB_FEED_SOURCES', 'predb_club,predb_net,predb_me,predatabase,xrel,xrel_p2p'))
    ))),

    'page_size' => (int) env('PREDB_FEED_PAGE_SIZE', 100),

    // Maximum wire and decoded response size for RSS feeds.
    'max_body_bytes' => (int) env('PREDB_FEED_MAX_BODY_BYTES', 1048576),

    'timeout' => (int) env('PREDB_FEED_TIMEOUT', 15),

    'user_agent' => (string) env('PREDB_FEED_USER_AGENT', 'NNTmux-PreDB-Importer/1.0'),

    'endpoints' => [
        'predb_club' => 'https://predb.club/api/v1/',
        'predb_net' => 'https://api.predb.net/',
        'predb_me' => (string) env('PREDB_FEED_RSS_URL', 'https://predb.me/?rss=1'),
        'predb_org' => 'https://predb.org/api/search',
        'predatabase' => 'https://predataba.se/api/search',
        'srrdb' => 'https://api.srrdb.com/v1/search/order:date-desc',
        'xrel' => 'https://api.xrel.to/v2/release/latest.json',
        'xrel_p2p' => 'https://api.xrel.to/v2/p2p/releases.json',
        'scnsrc_me' => 'https://www.scnsrc.me/feed',
        'eztv' => 'https://eztvx.to/ezrss.xml',
        'predb_ovh' => 'https://predb.ovh/api/v1/',
        'apibay' => 'https://apibay.org/precompiled/data_top100_recent.json',
        'nfodb' => 'https://nfodb.net.ru/rss.xml',
        'one_techno' => 'https://1techno.org/rss.xml',
        'one_gabba' => 'https://1gabba.pw/rss.xml',
        'ddlvalley' => 'https://www.ddlvalley.me/feed/',
        'hdencode' => 'https://hdencode.org/tag/movies/feed/',
        'hdencode_tv' => 'https://hdencode.org/tag/tv-shows/feed/',
        'mp3scene' => 'https://www.mp3scene.info/feeds/releases/',
        'nima4k' => 'https://nima4k.org/',
        'gload' => 'https://gload.to/feed/',
        'hdworld' => 'https://hd-world.cc/feed/',
        'scnlog' => 'https://scnlog.me/feed/',
        'knaben' => 'https://api.knaben.org/v2/browse',
        'crowdnfo' => 'https://crowdnfo.net/',
        'seriousaboutsound' => 'https://seriousaboutsound.net/forum/48-releases/?sortby=start_date&sortdirection=desc',
        'movieblog' => 'https://movieblog.to/feed/',
        'g4u' => 'https://g4u.to/',
        'gabber' => 'https://gabber.od.ua/0day',
        'nzedb_archive' => 'https://raw.githubusercontent.com/nZEDb/nZEDbPre_Dumps/ae51410b04bb3c59749b68582fbc4cc1e1f02d68/dumps/1689156393/1714642832_predb_dump.csv.gz',
        'dats_pciso_1998' => 'https://dats.site/exports/dat-sets/267',
        'nswdb' => 'https://nswdb.com/xml.php',
        '3dsdb' => 'https://3dsdb.com/xml.php',
    ],

    // Never page beyond the latest listing: srrDB's terms say "Use but don't scrape", and the
    // single-page catalogues expose only their latest listing. All these sources remain opt-in.
    'single_page_sources' => ['srrdb', 'nfodb', 'one_techno', 'one_gabba', 'ddlvalley', 'hdencode', 'hdencode_tv', 'mp3scene', 'nima4k', 'gload', 'hdworld', 'g4u', 'gabber', 'movieblog', 'scnlog', 'knaben', 'crowdnfo', 'seriousaboutsound'],

    // Complete metadata exports require explicit CLI selection; automatic polling skips them even if configured.
    'manual_only_sources' => ['nswdb', '3dsdb', 'nzedb_archive', 'dats_pciso_1998', 'scene_archive_sql'],

    // Operator-supplied extracted SQL metadata; empty disables access until explicitly configured.
    'scene_archive_sql_file' => (string) env('PREDB_FEED_SCENE_ARCHIVE_SQL_FILE', ''),

    // predataba.se: anonymous clients read 50 entries (5 requests) per run; a key lifts the depth limit.
    'predatabase_api_key' => (string) env('PREDB_FEED_PREDATABASE_API_KEY', ''),

    // srrDB reports release dates in its own local time.
    'srrdb_timezone' => (string) env('PREDB_FEED_SRRDB_TIMEZONE', 'Europe/Brussels'),

    // Pause between consecutive pages of one source (history imports page deeply).
    'request_delay_ms' => (int) env('PREDB_FEED_REQUEST_DELAY_MS', 1000),

    // On HTTP 429: wait (Retry-After when sent, else this many seconds) and retry the page.
    'rate_limit_wait_seconds' => (int) env('PREDB_FEED_RATE_LIMIT_WAIT', 60),
    'rate_limit_retries' => (int) env('PREDB_FEED_RATE_LIMIT_RETRIES', 5),
    // If the server asks to wait longer than this, the source fails for this run instead of blocking.
    'rate_limit_max_wait_seconds' => (int) env('PREDB_FEED_RATE_LIMIT_MAX_WAIT', 900),

    // Safety cap on pages per source for --since history imports.
    'max_pages' => (int) env('PREDB_FEED_MAX_PAGES', 2000),
];
