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
    'publish_timeout' => (int) env('RELEASE_VERIFICATION_PUBLISH_TIMEOUT', 120),
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
