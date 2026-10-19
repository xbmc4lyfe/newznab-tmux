<?php

declare(strict_types=1);

return [
    'required' => env('RELEASE_VERIFICATION_REQUIRED', true),
    'publication_timing_sample_every' => (int) env('RELEASE_VERIFICATION_PUBLICATION_TIMING_SAMPLE_EVERY', 0),
    'spot_check_articles' => (int) env('RELEASE_VERIFICATION_SPOT_CHECK_ARTICLES', 1),
    'spot_check_bytes' => (int) env('RELEASE_VERIFICATION_SPOT_CHECK_BYTES', 1048576),
    'spot_check_article_bytes' => (int) env('RELEASE_VERIFICATION_SPOT_CHECK_ARTICLE_BYTES', 1048576),
    'scheduled' => env('RELEASE_VERIFICATION_SCHEDULED', true),
    'worker_limit' => (int) env('RELEASE_VERIFICATION_WORKER_LIMIT', 1000),
    'worker_seconds' => (int) env('RELEASE_VERIFICATION_WORKER_SECONDS', 300),
    'candidate_workers' => (int) env('RELEASE_VERIFICATION_CANDIDATE_WORKERS', 2),
    // Publish an upload still missing files or articles once no new article has arrived for this many
    // hours, recording how many of its declared articles were found; 0 waits for complete headers.
    'partial_after_hours' => (int) env('RELEASE_VERIFICATION_PARTIAL_AFTER_HOURS', 4),
    // Promote stalled uploads only while fewer than this many candidates are ready, so partial attempts
    // use idle verification capacity and never queue ahead of a large backlog of new uploads.
    'partial_ready_limit' => (int) env('RELEASE_VERIFICATION_PARTIAL_READY_LIMIT', 500),
    // Hosts sharing one database schedule disjoint slots: this host runs slot_offset + 1 through
    // slot_offset + candidate_workers (at most slot 256).
    'slot_offset' => (int) env('RELEASE_VERIFICATION_SLOT_OFFSET', 0),
    // Names this host's scratch inventory and budget. The primary host keeps ''.
    'host' => (string) env('RELEASE_VERIFICATION_HOST', ''),
    // A worker-only host schedules nothing but its verification slots.
    'worker_only' => (bool) env('RELEASE_VERIFICATION_WORKER_ONLY', false),
    // auto: database locks on MariaDB/MySQL (they cover every worker host), file locks otherwise.
    'locks' => env('RELEASE_VERIFICATION_LOCKS', 'auto'),
    // Remote workers publish through the primary host's internal endpoint at this URL.
    'publish_url' => env('RELEASE_VERIFICATION_PUBLISH_URL'),
    // Remote workers may publish several candidates per request through this batch endpoint URL
    // (e.g. http://primary:8090/internal/release-verification/publish-batch); empty keeps one request each.
    'publish_batch_url' => env('RELEASE_VERIFICATION_PUBLISH_BATCH_URL'),
    // Primary: most items one batch request may carry (hard cap 64); more is refused with HTTP 422.
    'publish_batch_max_items' => (int) env('RELEASE_VERIFICATION_PUBLISH_BATCH_MAX_ITEMS', 16),
    // Primary: largest batch request body in bytes; more is refused with HTTP 413.
    'publish_batch_max_bytes' => (int) env('RELEASE_VERIFICATION_PUBLISH_BATCH_MAX_BYTES', 1048576),
    // Primary: seconds after which a batch starts no further item; the rest answer "retry".
    'publish_batch_seconds' => (float) env('RELEASE_VERIFICATION_PUBLISH_BATCH_SECONDS', 15),
    'publish_timeout' => (int) env('RELEASE_VERIFICATION_PUBLISH_TIMEOUT', 120),
    // Remote workers hand verified results to a per-host outbox and move on; forwarder processes send
    // them to the primary in batches. Off keeps every worker publishing synchronously.
    'publish_async' => (bool) env('RELEASE_VERIFICATION_PUBLISH_ASYNC', false),
    // Forwarder processes per host (1-64); more forwarders overlap more batches.
    'publish_forwarders' => (int) env('RELEASE_VERIFICATION_PUBLISH_FORWARDERS', 4),
    // Entries (waiting or being sent) at which a worker stops handing off and waits for room.
    'publish_outbox_max' => (int) env('RELEASE_VERIFICATION_PUBLISH_OUTBOX_MAX', 256),
    // Seconds a worker waits for room in a full outbox before publishing synchronously instead.
    'publish_outbox_wait_seconds' => (int) env('RELEASE_VERIFICATION_PUBLISH_OUTBOX_WAIT_SECONDS', 30),
    // Items a forwarder sends per request; the primary refuses more than its publish_batch_max_items.
    'publish_forward_items' => (int) env('RELEASE_VERIFICATION_PUBLISH_FORWARD_ITEMS', 16),
    // Milliseconds (0-30000) a forwarder waits, once work is due, for a batch to fill to its item limit before
    // sending a partial one. 0 sends as soon as anything is due. Backlogs and in-flight work never wait.
    'publish_forward_linger_ms' => (int) env('RELEASE_VERIFICATION_PUBLISH_FORWARD_LINGER_MS', 0),
    // Failed sends after which a forwarder finishes an entry unknown, to be verified again.
    'publish_forward_max_attempts' => (int) env('RELEASE_VERIFICATION_PUBLISH_FORWARD_MAX_ATTEMPTS', 5),
    // Period in seconds after which the scheduler restarts a forwarder. No run lasts longer (forwarder N runs
    // 10 s short of it, less (N-1) whole minutes, wrapping inside it), so a container stop does not wait long;
    // keep it well under the container's stop grace period. The scheduler starts forwarders once a minute, so
    // anything under 120 is treated as 120 (a shorter run would leave minutes with no forwarder running).
    'publish_forwarder_seconds' => (int) env('RELEASE_VERIFICATION_PUBLISH_FORWARDER_SECONDS', 300),
    // A forwarder counts as alive to workers for this many seconds after it last touched its marker. It touches
    // it every few seconds, idle or while a request waits for the primary (up to publish_timeout, through cURL's
    // progress callback), so this only has to cover a stall; a hung or crashed forwarder is noticed after this long.
    // Values under 20 count as 20: it must bridge the seconds between a forwarder's scheduled exit and its replacement.
    'publish_forwarder_alive_seconds' => (int) env('RELEASE_VERIFICATION_PUBLISH_FORWARDER_ALIVE_SECONDS', 30),
    // The primary host serves that endpoint, without authentication, to these networks only.
    'publish_endpoint' => (bool) env('RELEASE_VERIFICATION_PUBLISH_ENDPOINT', false),
    'publish_allowed_networks' => env('RELEASE_VERIFICATION_PUBLISH_ALLOWED_NETWORKS', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.0/8'),
    'storage_path' => storage_path('app/release-verification'),
    // database: manifests live in release_verification_manifests (shared by every worker host and
    // written in the candidate's transaction), falling back to legacy files until they are imported.
    // files: the previous per-host manifest files under storage_path/manifests.
    'manifest_storage' => env('RELEASE_VERIFICATION_MANIFEST_STORAGE', 'database'),
    'scratch_bytes' => (int) env('RELEASE_VERIFICATION_SCRATCH_BYTES', 214748364800),
    'free_bytes' => (int) env('RELEASE_VERIFICATION_FREE_BYTES', 10737418240),
    'article_bytes' => (int) env('RELEASE_VERIFICATION_ARTICLE_BYTES', 16777216),
    'download_connections' => (int) env('RELEASE_VERIFICATION_DOWNLOAD_CONNECTIONS', 2),
    'pipeline_window' => (int) env('RELEASE_VERIFICATION_PIPELINE_WINDOW', 4),
    'download_buffer_bytes' => (int) env('RELEASE_VERIFICATION_DOWNLOAD_BUFFER_BYTES', 67108864),
    'bytes_per_second' => (int) env('RELEASE_VERIFICATION_BYTES_PER_SECOND', 0),
    'pending_bytes' => (int) env('RELEASE_VERIFICATION_PENDING_BYTES', 10737418240),
    'lease_seconds' => 300,
    'retry_seconds' => 900,
    // Spot-check failures decided by the manifest and its fixed sample, not by transport.
    'deterministic_retry_seconds' => (int) env('RELEASE_VERIFICATION_DETERMINISTIC_RETRY_SECONDS', 86400),
    'expiry_hours' => 48,
    'par2_binary' => env('RELEASE_VERIFICATION_PAR2_BINARY', 'par2'),
];
