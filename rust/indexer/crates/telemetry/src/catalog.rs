//! Every metric the indexer exports, one place for names, types and help text.
//!
//! Metrics replace the web UI as the way to see the system, so a stage records through these
//! constants instead of inventing names, and the Grafana dashboard is tested against this list.
//! Help text names each metric's labels. Keep their values low in number.

use indexer_core::Stage;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Kind {
    Counter,
    Gauge,
    Histogram,
}

#[derive(Clone, Copy, Debug)]
pub struct MetricDef {
    pub name: &'static str,
    pub kind: Kind,
    pub help: &'static str,
}

macro_rules! catalog {
    ($($kind:ident $constant:ident = $name:literal, $help:literal;)*) => {
        $(pub const $constant: &str = $name;)*

        pub const ALL: &[MetricDef] = &[
            $(MetricDef { name: $name, kind: Kind::$kind, help: $help },)*
        ];
    };
}

catalog! {
    // Process
    Gauge BUILD_INFO = "indexer_build_info", "Always 1, by version.";
    Gauge START_TIME = "indexer_start_time_seconds", "Unix time the process started.";

    // The headline number: the project's goal.
    Counter NEW_HIGH_QUALITY_RELEASES = "indexer_new_high_quality_releases_total",
        "New high-quality releases that became searchable.";

    // Harvest
    Counter HARVEST_ARTICLES = "indexer_harvest_articles_total", "Headers read, by server and group.";
    Gauge HARVEST_CONNECTIONS = "indexer_harvest_connections_in_use", "NNTP connections in use, by server.";
    Counter HARVEST_ERRORS = "indexer_harvest_errors_total", "Harvest errors, by server and kind.";
    Gauge HARVEST_BACKFILL_DEPTH = "indexer_harvest_backfill_depth_articles", "Articles still to backfill, by group.";
    Gauge HARVEST_RETENTION = "indexer_harvest_retention_reached_seconds", "Age of the oldest header read, by group.";

    // Funnel
    Counter FUNNEL_POSTS = "indexer_funnel_posts_total",
        "Posts leaving each stage, by stage, outcome (passed or dropped) and drop reason.";

    // Identify
    Counter IDENTIFY_ATTEMPTS = "indexer_identify_attempts_total", "Ladder attempts, by step.";
    Counter IDENTIFY_SUCCESSES = "indexer_identify_successes_total", "Ladder successes, by step.";
    Counter IDENTIFY_BYTES = "indexer_identify_downloaded_bytes_total", "Bytes downloaded for identification, by step.";
    Histogram IDENTIFY_DURATION = "indexer_identify_step_duration_seconds", "Time spent per attempt, by step.";

    // Quality
    Counter QUALITY_RELEASES = "indexer_quality_releases_total", "Classified releases, by resolution, source, codec and tier.";
    Counter QUALITY_BITRATE_FAILURES = "indexer_quality_bitrate_failures_total", "Releases failing the bitrate test, by resolution and source.";

    // IDs
    Counter ID_MATCHES = "indexer_id_matches_total", "ID match attempts, by id_type and outcome.";
    Counter ID_API_CALLS = "indexer_id_api_calls_total", "Outside API calls, by service and outcome.";
    Counter ID_API_RATE_LIMITS = "indexer_id_api_rate_limits_total", "Rate-limit responses, by service.";

    // Group yield
    Counter GROUP_RELEASES = "indexer_group_high_quality_releases_total", "High-quality releases, by group.";
    Counter GROUP_HEADER_BYTES = "indexer_group_header_bytes_total", "Header bytes read, by group.";

    // API
    Counter API_REQUESTS = "indexer_api_requests_total", "Newznab requests, by type and status.";
    Histogram API_REQUEST_DURATION = "indexer_api_request_duration_seconds", "Newznab request latency, by type.";
    Counter API_EMPTY_RESULTS = "indexer_api_empty_results_total", "Searches with no results, by type.";
    Counter API_GRABS = "indexer_api_grabs_total", "NZBs served.";

    // System
    Gauge QUEUE_DEPTH = "indexer_queue_depth", "Items waiting, by queue.";
    Histogram SHARD_WRITE_WAIT = "indexer_shard_write_wait_seconds", "Time waiting to write, by shard.";
    Gauge DISK_USAGE = "indexer_disk_usage_bytes", "Disk use, by table.";

    // 0-day
    Histogram ZERODAY_ANNOUNCE_LATENCY = "indexer_zeroday_announce_latency_seconds", "Announce arrival after pre time, by source.";
    Counter ZERODAY_EXPECTED = "indexer_zeroday_expected_releases_total", "Expected releases, by outcome (announced or found).";
    Histogram ZERODAY_TIME_TO_SEARCHABLE = "indexer_zeroday_time_to_searchable_seconds", "Time until searchable, by start (announce or post).";
    Counter ZERODAY_PUBLISHED = "indexer_zeroday_published_total", "Published releases, by level (exact, probable or title).";

    // Phase 1 speed numbers
    Counter IMPORT_ROWS = "indexer_import_rows_total", "Rows imported from MariaDB, by table.";
    Counter IMPORT_NZB_BYTES = "indexer_import_nzb_bytes_total", "Compressed NZB bytes imported from MariaDB.";
    Counter IMPORT_ANOMALIES = "indexer_import_anomalies_total", "Rows or NZBs kept that could not be vouched for, by kind.";
    Histogram SEARCH_QUERY_DURATION = "indexer_search_query_duration_seconds", "Text search latency, by engine.";
}

/// Attaches help text to every metric. Metrics still appear only once first recorded.
pub fn describe_all() {
    for def in ALL {
        match def.kind {
            Kind::Counter => metrics::describe_counter!(def.name, def.help),
            Kind::Gauge => metrics::describe_gauge!(def.name, def.help),
            Kind::Histogram => metrics::describe_histogram!(def.name, def.help),
        }
    }
}

pub(crate) fn record_startup(version: &'static str, start_time: f64) {
    metrics::gauge!(BUILD_INFO, "version" => version).set(1.0);
    metrics::gauge!(START_TIME).set(start_time);
    // Starts at zero, so increase() sees the first release.
    metrics::counter!(NEW_HIGH_QUALITY_RELEASES).absolute(0);
}

pub fn funnel_passed(stage: Stage, posts: u64) {
    metrics::counter!(FUNNEL_POSTS, "stage" => stage.as_str(), "outcome" => "passed", "reason" => "")
        .increment(posts);
}

pub fn funnel_dropped(stage: Stage, reason: &'static str, posts: u64) {
    metrics::counter!(FUNNEL_POSTS, "stage" => stage.as_str(), "outcome" => "dropped", "reason" => reason)
        .increment(posts);
}

#[cfg(test)]
mod tests {
    use std::collections::HashSet;

    use super::*;

    #[test]
    fn names_are_unique_and_follow_prometheus_conventions() {
        let mut seen = HashSet::new();
        for def in ALL {
            assert!(seen.insert(def.name), "duplicate metric {}", def.name);
            assert!(def.name.starts_with("indexer_"), "{}", def.name);
            assert!(
                def.name
                    .chars()
                    .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_'),
                "{}",
                def.name
            );
            assert_eq!(
                def.kind == Kind::Counter,
                def.name.ends_with("_total"),
                "{}: counters and only counters end in _total",
                def.name
            );
            if def.kind == Kind::Histogram {
                assert!(
                    def.name.ends_with("_seconds"),
                    "{}: buckets are in seconds",
                    def.name
                );
            }
        }
    }

    #[test]
    fn dashboard_queries_only_catalog_metrics() {
        let dashboard = concat!(
            env!("CARGO_MANIFEST_DIR"),
            "/../../monitoring/grafana/dashboards/indexer.json"
        );
        let text = std::fs::read_to_string(dashboard).unwrap();
        let known: HashSet<_> = ALL.iter().map(|def| def.name).collect();
        let used: Vec<_> = text
            .split(|c: char| !(c.is_ascii_alphanumeric() || c == '_'))
            .filter(|token| token.starts_with("indexer_"))
            .collect();
        assert!(!used.is_empty());
        for token in used {
            let name = ["_bucket", "_sum", "_count"]
                .iter()
                .find_map(|suffix| token.strip_suffix(suffix))
                .unwrap_or(token);
            assert!(
                known.contains(name),
                "dashboard queries unknown metric {token}"
            );
        }
    }
}
