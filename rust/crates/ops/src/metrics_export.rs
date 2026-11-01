//! `monitoring:export-metrics`: application metrics for the Prometheus Pushgateway. Metric names
//! match the PHP exporter, so dashboards and alert rules keep working.

use std::path::Path;
use std::time::{Duration, Instant};

use anyhow::{Context, Result};
use chrono::{NaiveDateTime, Utc};
use nntmux_core::var_or;
use nntmux_runtime::Ctx;
use redis::AsyncCommands;
use serde_json::Value;
use sqlx::mysql::MySqlDatabaseError;
use sqlx::{AssertSqlSafe, MySqlPool};
use tracing::warn;

use crate::processing_stats::snapshot_key;
use crate::prometheus::{self, Family};

const PUSH_TIMEOUT: Duration = Duration::from_secs(5);
const JOB: &str = "nntmux";
const TABLES: [&str; 4] = ["collections", "binaries", "parts", "missed_parts"];
const CATEGORIES: [&str; 8] = [
    "tv", "movies", "audio", "books", "console", "pc", "xxx", "misc",
];
const UNVERIFIED: &str = "'ready', 'waiting_upload', 'pending', 'unknown', 'header_rejected', 'verifying', 'dirty', 'unrepairable'";
/// MariaDB's and MySQL's error numbers for a statement over its time limit.
const STATEMENT_TIMEOUTS: [u16; 2] = [1969, 3024];

fn application() -> Vec<Family> {
    let mut build = Family::gauge("nntmux_build_info", "NNTmux runtime versions.");
    build.add(1, &[("version", env!("CARGO_PKG_VERSION"))]);
    vec![
        Family::gauge("nntmux_up", "Whether the NNTmux metrics exporter ran.").with(1),
        build,
    ]
}

/// Stops the server's work too, so a slow measurement can't stall the export.
fn bounded(sql: &str) -> AssertSqlSafe<String> {
    AssertSqlSafe(format!("SET STATEMENT max_statement_time=2 FOR {sql}"))
}

async fn fingerprint(db: &MySqlPool) -> Result<Vec<Family>> {
    let mut jobs = Family::gauge(
        "nntmux_fingerprint_jobs",
        "Database fingerprint jobs by state.",
    );
    let rows: Vec<(String, i64)> = sqlx::query_as(bounded(
        "SELECT status, COUNT(*) FROM release_fingerprint_jobs GROUP BY status",
    ))
    .fetch_all(db)
    .await?;
    for status in ["pending", "processing", "complete", "failed"] {
        let count = rows
            .iter()
            .find(|(name, _)| name == status)
            .map_or(0, |(_, count)| *count);
        jobs.add(count, &[("status", status)]);
    }
    let (expired, live): (i64, i64) = sqlx::query_as(
        "SELECT CAST(COALESCE(SUM(lease_until <= UTC_TIMESTAMP()), 0) AS SIGNED), \
         CAST(COALESCE(SUM(lease_until > UTC_TIMESTAMP()), 0) AS SIGNED) \
         FROM release_fingerprint_jobs WHERE status = 'processing'",
    )
    .fetch_one(db)
    .await?;
    let recent: i64 = sqlx::query_scalar(bounded(
        "SELECT COUNT(*) FROM release_fingerprint_jobs WHERE status = 'complete' \
         AND updated_at >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE",
    ))
    .fetch_one(db)
    .await?;
    let mut slots = Family::gauge(
        "nntmux_fingerprint_connection_slots",
        "Shared NNTP connection budget for Rust clients.",
    );
    let mut used = Family::gauge(
        "nntmux_fingerprint_connections_active",
        "Currently leased Rust NNTP connection slots.",
    );
    for provider in ["primary", "alternate"] {
        let (total, active): (i64, i64) = sqlx::query_as(
            "SELECT COUNT(*), CAST(COALESCE(SUM(lease_until > UTC_TIMESTAMP()), 0) AS SIGNED) \
             FROM release_fingerprint_connections WHERE provider = ?",
        )
        .bind(provider)
        .fetch_one(db)
        .await?;
        slots.add(total, &[("provider", provider)]);
        used.add(active, &[("provider", provider)]);
    }
    let mut queue = Family::gauge("nntmux_queue_jobs", "Jobs waiting in each queue.");
    let pending = rows
        .iter()
        .find(|(name, _)| name == "pending")
        .map_or(0, |(_, count)| *count);
    queue.add(pending, &[("queue", "release-fingerprint")]);
    Ok(vec![
        jobs,
        Family::gauge(
            "nntmux_fingerprint_live_claims",
            "Rust fingerprint jobs with unexpired leases.",
        )
        .with(live),
        Family::gauge(
            "nntmux_fingerprint_completions_last_five_minutes",
            "Releases whose Rust fingerprint job completed in the last five minutes.",
        )
        .with(recent),
        Family::gauge(
            "nntmux_fingerprint_expired_claims",
            "Rust fingerprint claims awaiting takeover.",
        )
        .with(expired),
        slots,
        used,
        queue,
    ])
}

async fn failed_jobs(db: &MySqlPool) -> Result<Vec<Family>> {
    let count: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM failed_jobs")
        .fetch_one(db)
        .await?;
    Ok(vec![
        Family::gauge("nntmux_failed_jobs", "Jobs in the failed jobs store.").with(count),
    ])
}

pub fn timed_out(failure: &sqlx::Error) -> bool {
    failure
        .as_database_error()
        .and_then(|error| error.try_downcast_ref::<MySqlDatabaseError>())
        .is_some_and(|error| STATEMENT_TIMEOUTS.contains(&error.number()))
}

/// The status file the verifier writes, and when it was written. Oversized or unreadable counts as
/// no measurement.
fn read_status(path: &Path) -> (Value, Option<i64>) {
    const LIMIT: u64 = 16_384;
    let missing = (Value::Null, None);
    let Ok(metadata) = std::fs::metadata(path) else {
        return missing;
    };
    if metadata.len() > LIMIT {
        return missing;
    }
    let Ok(body) = std::fs::read(path) else {
        return missing;
    };
    let modified = metadata
        .modified()
        .ok()
        .and_then(|time| time.duration_since(std::time::UNIX_EPOCH).ok())
        .and_then(|age| i64::try_from(age.as_secs()).ok());
    (
        serde_json::from_slice(&body).unwrap_or(Value::Null),
        modified,
    )
}

async fn release_verification(db: &MySqlPool, status_path: &Path) -> Result<Vec<Family>> {
    let now = Utc::now().timestamp();
    let mut families = Vec::new();
    let measured: Result<Vec<(String, i64)>, _> = sqlx::query_as(bounded(
        "SELECT status, COUNT(*) FROM release_verification_candidates GROUP BY status",
    ))
    .fetch_all(db)
    .await;
    let available = match &measured {
        Ok(_) => 1,
        Err(failure) if timed_out(failure) => 0,
        Err(failure) => anyhow::bail!("measuring verification outcomes: {failure}"),
    };
    families.push(
        Family::gauge(
            "nntmux_release_verification_candidates_measurement_available",
            "Whether verification outcome counts were measured within their query deadline.",
        )
        .with(i64::from(available)),
    );
    if let Ok(rows) = measured {
        let mut candidates = Family::gauge(
            "nntmux_release_verification_candidates",
            "Candidates by verification outcome.",
        );
        for (status, total) in &rows {
            candidates.add(*total, &[("status", status.as_str())]);
        }
        families.push(candidates);
    }
    let pending: Option<i64> = sqlx::query_scalar(
        "SELECT CAST(pending_bytes AS SIGNED) FROM release_verification_state WHERE id = 1",
    )
    .fetch_optional(db)
    .await?;
    families.push(
        Family::gauge(
            "nntmux_release_verification_pending_bytes",
            "Compressed pending manifest bytes.",
        )
        .with(pending.unwrap_or(0)),
    );

    let (status, measured_at) = read_status(status_path);
    let scratch = status["scratch_bytes"].as_f64().filter(|bytes| {
        *bytes >= 0.0 && status["scratch_bytes_status"].as_str() != Some("unknown")
    });
    let known = measured_at.is_some() && scratch.is_some();
    families.push(
        Family::gauge(
            "nntmux_release_verification_scratch_bytes_measurement_available",
            "Whether a cached scratch measurement is available; not a live disk-usage reading.",
        )
        .with(i64::from(known)),
    );
    if let (true, Some(bytes), Some(at)) = (known, scratch, measured_at) {
        families.push(
            Family::gauge(
                "nntmux_release_verification_scratch_bytes",
                "Last cached scratch measurement; consult its measurement age.",
            )
            .with(bytes),
        );
        families.push(
            Family::gauge(
                "nntmux_release_verification_scratch_bytes_measurement_age_seconds",
                "Age of the cached scratch measurement.",
            )
            .with((now - at).max(0)),
        );
    }
    families.push(
        Family::gauge(
            "nntmux_release_verification_verified_bytes_per_second",
            "Latest bounded verifier measurement.",
        )
        .with(status["verified_bytes_per_second"].as_f64().unwrap_or(0.0)),
    );

    let oldest: Result<Option<NaiveDateTime>, _> = sqlx::query_scalar(bounded(&format!(
        "SELECT MIN(last_unique_at) FROM release_verification_candidates \
         WHERE release_id IS NULL AND status IN ({UNVERIFIED})"
    )))
    .fetch_one(db)
    .await;
    let age_available = Family::gauge(
        "nntmux_release_verification_backlog_age_measurement_available",
        "Whether the oldest unverified candidate age was measured within its query deadline.",
    );
    match oldest {
        Ok(oldest) => {
            families.push(age_available.with(1));
            let age = oldest.map_or(0, |at| (now - at.and_utc().timestamp()).max(0));
            families.push(
                Family::gauge(
                    "nntmux_release_verification_backlog_age_seconds",
                    "Age of the oldest unverified candidate.",
                )
                .with(age),
            );
        }
        // Omit an unavailable age rather than publishing a fake zero.
        Err(failure) if timed_out(&failure) => families.push(age_available.with(0)),
        Err(failure) => anyhow::bail!("measuring the verification backlog: {failure}"),
    }
    Ok(families)
}

fn severity(status: &str) -> i64 {
    match status {
        "operational" => 0,
        "degraded" => 1,
        "maintenance" => 2,
        "partial_outage" => 3,
        _ => 4,
    }
}

/// Reads the stored results of the health checks rather than probing live, so a slow NNTP probe can
/// never stall the export.
async fn service_status(db: &MySqlPool) -> Result<Vec<Family>> {
    let rows: Vec<(String, String, Option<u32>)> = sqlx::query_as(
        "SELECT slug, status, response_time_ms FROM service_statuses WHERE is_enabled = 1 ORDER BY sort_order",
    )
    .fetch_all(db)
    .await?;
    let mut up = Family::gauge(
        "nntmux_service_up",
        "Whether a monitored service is operational or degraded (1) or down (0).",
    );
    let mut levels = Family::gauge(
        "nntmux_service_severity",
        "Service status severity: 0 operational, 1 degraded, 2 maintenance, 3 partial outage, 4 major outage.",
    );
    let mut response = Family::gauge(
        "nntmux_service_response_time_seconds",
        "Response time of the last service health check.",
    );
    for (slug, status, milliseconds) in &rows {
        let level = severity(status);
        let labels = [("service", slug.as_str())];
        up.add(i64::from(level <= 1), &labels);
        levels.add(level, &labels);
        if let Some(milliseconds) = milliseconds {
            response.add(f64::from(*milliseconds) / 1000.0, &labels);
        }
    }
    Ok(vec![up, levels, response])
}

fn number(value: &Value) -> Option<prometheus::Value> {
    value
        .as_i64()
        .map(prometheus::Value::Int)
        .or_else(|| value.as_f64().map(prometheus::Value::Float))
}

/// The families the snapshot publishes. `None` means there's no recent snapshot.
pub fn snapshot_families(snapshot: Option<&Value>, now: i64) -> Vec<Family> {
    let available = Family::gauge(
        "nntmux_tmux_snapshot_available",
        "Whether a recent tmux monitor snapshot exists.",
    );
    let Some(snapshot) = snapshot else {
        return vec![available.with(0)];
    };
    let counts = &snapshot["counts"];
    let count = |key: &str| number(&counts[key]).unwrap_or(prometheus::Value::Int(0));

    let mut backlog = Family::gauge(
        "nntmux_processing_backlog",
        "Releases waiting for post-processing, by type.",
    );
    if let Some(all) = counts.as_object() {
        for (key, value) in all {
            if let (Some(kind), Some(value)) = (key.strip_prefix("process"), number(value)) {
                backlog.add(value, &[("type", kind)]);
            }
        }
    }
    let mut tables = Family::gauge(
        "nntmux_table_rows",
        "Estimated rows in the binary processing tables.",
    );
    for table in TABLES {
        if let Some(value) = number(&counts[format!("{table}_table")]) {
            tables.add(value, &[("table", table)]);
        }
    }
    let mut categories = Family::gauge(
        "nntmux_releases_by_category",
        "Releases per parent category.",
    );
    for category in CATEGORIES {
        if let Some(value) = number(&counts[category]) {
            categories.add(value, &[("category", category)]);
        }
    }
    let mut groups = Family::gauge("nntmux_groups", "Usenet groups by state.");
    groups
        .add(count("active_groups"), &[("state", "active")])
        .add(count("all_groups"), &[("state", "all")]);
    let mut timers = Family::gauge(
        "nntmux_tmux_query_duration_seconds",
        "Duration of the tmux monitor statistics queries.",
    );
    if let Some(all) = snapshot["query_timers"].as_object() {
        for (query, seconds) in all {
            if let Some(seconds) = number(seconds) {
                timers.add(seconds, &[("query", query.as_str())]);
            }
        }
    }
    let collected = snapshot["collected_at"].as_i64().unwrap_or(now);
    vec![
        available.with(1),
        Family::gauge(
            "nntmux_tmux_snapshot_age_seconds",
            "Seconds since the tmux monitor last published statistics.",
        )
        .with((now - collected).max(0)),
        Family::gauge(
            "nntmux_tmux_running",
            "Whether tmux processing is running (1) or paused (0).",
        )
        .with(snapshot["is_running"].as_i64().unwrap_or(0)),
        backlog,
        Family::gauge(
            "nntmux_processing_total_work",
            "Sum of all post-processing backlogs.",
        )
        .with(count("total_work")),
        Family::gauge(
            "nntmux_additional_backlog",
            "Releases waiting for additional processing.",
        )
        .with(count("work")),
        Family::gauge(
            "nntmux_additional_backlog_available",
            "Additional-processing backlog that is currently claimable.",
        )
        .with(count("work_available")),
        tables,
        Family::gauge("nntmux_releases", "Total releases.").with(count("releases")),
        categories,
        Family::gauge("nntmux_predb_entries", "PreDB entries.").with(count("predb")),
        Family::gauge(
            "nntmux_predb_matched_releases",
            "Releases matched to a PreDB entry.",
        )
        .with(count("predb_matched")),
        Family::gauge("nntmux_releases_with_nfo", "Releases with an NFO.").with(count("nfo")),
        Family::gauge(
            "nntmux_releases_renamed",
            "Releases renamed by the name fixer.",
        )
        .with(count("renamed")),
        groups,
        timers,
    ]
}

async fn read_snapshot(ctx: &Ctx) -> Result<Option<Value>> {
    let mut redis = ctx.redis.clone();
    let body: Option<String> = redis.get(snapshot_key(ctx)).await?;
    Ok(body.and_then(|body| {
        serde_json::from_str::<Value>(&body)
            .ok()
            .filter(|value| value["collected_at"].is_i64() && value["counts"].is_object())
    }))
}

/// Runs every collector and renders the result. A failing collector is reported through
/// `nntmux_metrics_collector_errors` instead of aborting the rest.
pub async fn render(ctx: &Ctx) -> String {
    let started = Instant::now();
    let db = &ctx.db;
    let now = Utc::now().timestamp();
    let status_path = Path::new(&var_or(
        "RELEASE_VERIFICATION_STORAGE_PATH",
        "/app/storage/app/release-verification",
    ))
    .join("status.json");
    let results: Vec<(&'static str, Result<Vec<Family>>)> = vec![
        ("ApplicationCollector", Ok(application())),
        (
            "TmuxSnapshotCollector",
            read_snapshot(ctx)
                .await
                .map(|snapshot| snapshot_families(snapshot.as_ref(), now)),
        ),
        ("DatabaseFingerprintCollector", fingerprint(db).await),
        ("FailedJobsCollector", failed_jobs(db).await),
        (
            "ReleaseVerificationCollector",
            release_verification(db, &status_path).await,
        ),
        ("ServiceStatusCollector", service_status(db).await),
    ];
    let mut families = Vec::new();
    let mut errors = Family::gauge(
        "nntmux_metrics_collector_errors",
        "Whether a metrics collector failed during the last export.",
    );
    for (name, result) in results {
        match result {
            Ok(collected) => {
                families.extend(collected);
                errors.add(0, &[("collector", name)]);
            }
            Err(failure) => {
                warn!(
                    collector = name,
                    error = format!("{failure:#}"),
                    "monitoring collector failed"
                );
                errors.add(1, &[("collector", name)]);
            }
        }
    }
    families.push(errors);
    families.push(
        Family::gauge(
            "nntmux_metrics_export_duration_seconds",
            "Time spent collecting NNTmux metrics.",
        )
        .with(started.elapsed().as_secs_f64()),
    );
    prometheus::format(&families)
}

pub fn enabled() -> bool {
    matches!(
        var_or("MONITORING_ENABLED", "false")
            .to_ascii_lowercase()
            .as_str(),
        "1" | "true" | "yes" | "on"
    )
}

fn pushgateway_url() -> String {
    var_or("MONITORING_PUSHGATEWAY_URL", "http://127.0.0.1:9091")
        .trim_end_matches('/')
        .to_owned()
}

/// Replaces the metric group on the Pushgateway. PUT, not POST, so series no longer reported drop.
pub async fn push(ctx: &Ctx) -> Result<()> {
    push_to(ctx, &pushgateway_url()).await
}

pub async fn push_to(ctx: &Ctx, base: &str) -> Result<()> {
    let url = format!("{base}/metrics/job/{JOB}");
    reqwest::Client::builder()
        .timeout(PUSH_TIMEOUT)
        .build()?
        .put(&url)
        .header(reqwest::header::CONTENT_TYPE, prometheus::CONTENT_TYPE)
        .body(render(ctx).await)
        .send()
        .await
        .with_context(|| format!("pushing metrics to {url}"))?
        .error_for_status()
        .with_context(|| format!("pushing metrics to {url}"))?;
    Ok(())
}
