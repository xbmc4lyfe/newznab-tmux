-- Runtime processing settings for the docker/ stack (typed single-row configuration tables).
-- Sized to stay under the primary provider's connection limit: binaries (12) + backfill (30) +
-- additional (16) + NFO (4) post-processing hold about 60 of its 73 NNTP connections. Those workers
-- mostly wait on NNTP rather than CPU, so they run more threads than the 10 cores.
-- Settings are cached for 300s; `make -C docker seed` clears the cache afterwards.
UPDATE ingestion_configurations SET
    binary_threads = 12,
    backfill_threads = 30,
    release_threads = 4,
    -- 0 keeps every same-name/same-poster upload (no cross-post deletion).
    cross_post_hours = 0,
    -- Collections still receiving parts this long after their first article are released as they are.
    -- 0 released (and re-reconciled) every collection immediately, racing header storage (BUGS #37).
    collection_delay_hours = 2,
    max_messages = 20000,
    -- Drop collections under 2 MB before they become releases. Article-obfuscated posts in
    -- boneless/cores/comp otherwise yield one ~740 KB release per article.
    min_size_to_form_release = 2097152,
    backfill_days_mode = 1,
    backfill_quantity = 200000,
    updated_at = NOW();

UPDATE post_processing_configurations SET
    post_threads = 16,
    nfo_threads = 4,
    post_threads_non = 3,
    post_threads_amazon = 2,
    fix_name_threads = 6,
    -- PreDB full-text matching reads this many entries per worker per cycle (16 workers).
    fix_names_per_run = 100,
    -- 0 = extract the first RAR/ZIP volume with unrar/unzip so ffmpeg and mediainfo get a video file;
    -- 1 only lists archive contents (no samples, previews or mediainfo).
    extract_using_rar_info = 0,
    updated_at = NOW();

UPDATE tmux_configurations SET
    sequential_mode = 0,
    binaries_enabled = 1,
    backfill_mode = 1,
    releases_enabled = 1,
    post_mode = 3,
    post_non_mode = 1,
    post_amazon_mode = 1,
    fix_names_enabled = 1,
    run_irc_scraper = 1,
    htop_enabled = 1,
    updated_at = NOW();
