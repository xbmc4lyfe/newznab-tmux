//! `nntmux-ctl postprocess additional-diagnose`: backlog, claims, capacity, the queue index and
//! the temp path. Port of `AdditionalProcessingDiagnostics`.

use anyhow::Result;
use chrono::{SecondsFormat, Utc};
use serde::Serialize;
use sqlx::MySqlPool;

use super::candidates::{self, BucketBacklog, Scope};
use super::queue::WORKER_MAX_BATCHES;
use crate::config::Config;
use crate::workspace;

const OVERSIZED_IN_FLIGHT_THRESHOLD: i64 = 500;
const OVERSIZED_THREAD_THRESHOLD: i64 = 32;
const REQUIRED_CLAIM_INDEX_COLUMNS: [&str; 7] = [
    "passwordstatus",
    "haspreview",
    "nzbstatus",
    "leftguid",
    "postdate",
    "id",
    "additional_pp_claimed_at",
];

#[derive(Clone, Debug, Serialize)]
pub struct Report {
    pub generated_at: String,
    pub database_driver: String,
    pub backlog: Backlog,
    pub settings: ReportSettings,
    pub claims: Claims,
    pub capacity: Capacity,
    pub indexes: Indexes,
    pub temp_path: TempPath,
    pub warnings: Vec<Warning>,
}

#[derive(Clone, Debug, Serialize)]
pub struct Backlog {
    pub total: i64,
    pub available: i64,
    pub active_claims: i64,
    pub stale_claims: i64,
    pub buckets: Vec<BucketBacklog>,
}

#[derive(Clone, Debug, Serialize)]
pub struct ReportSettings {
    pub pipeline: &'static str,
    pub postthreads: i64,
    pub maxaddprocessed: i64,
    pub releaseprocessingtimeout: i64,
    pub maxpptimeoutcount: i64,
    pub worker_max_batches: i64,
    pub multiprocessing_child_timeout: i64,
}

#[derive(Clone, Debug, Serialize)]
pub struct Claims {
    pub ttl_seconds: i64,
    pub stale_before: String,
    pub estimated_claimed_batch_lifetime_seconds: i64,
}

#[derive(Clone, Debug, Serialize)]
pub struct Capacity {
    pub threads: i64,
    pub releases_per_batch: i64,
    pub max_in_flight: i64,
    pub max_per_worker_lifetime: i64,
}

#[derive(Clone, Debug, Serialize)]
pub struct Indexes {
    pub required_columns: [&'static str; 7],
    pub present: Vec<String>,
    pub claim_queue_index: Option<String>,
    pub missing: bool,
}

#[derive(Clone, Debug, Serialize)]
pub struct TempPath {
    pub path: String,
    pub exists: bool,
    pub writable: bool,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct Warning {
    pub code: &'static str,
    pub message: String,
}

fn warning(code: &'static str, message: String) -> Warning {
    Warning { code, message }
}

pub async fn inspect(pool: &MySqlPool, config: &Config) -> Result<Report> {
    let settings = &config.settings;
    let scope = Scope::new(config);
    let threads = settings.post_threads.max(1);
    let batch_size = settings.max_additional_processed.max(1);
    let child_timeout = config.child_timeout_seconds;
    let claim_ttl = scope.claim_ttl_seconds;
    let mut warnings = Vec::new();

    let counts = candidates::backlog_counts(pool, &scope).await?;
    let (active_claims, stale_claims) = candidates::claim_counts(pool, &scope).await?;
    let buckets = candidates::bucket_backlog(pool, &scope).await?;
    let backlog = Backlog {
        total: counts.total,
        available: counts.available,
        active_claims,
        stale_claims,
        buckets,
    };
    let indexes = indexes(pool, &mut warnings).await?;
    let temp_path = temp_path(config, &mut warnings);
    let maximum_claim_lifetime = child_timeout
        .max(1)
        .min(settings.release_timeout_seconds.max(1) * batch_size);

    if backlog.stale_claims > 0 {
        warnings.push(warning(
            "stale-claims",
            format!(
                "{} additional-processing claim(s) are stale and eligible for recovery.",
                backlog.stale_claims
            ),
        ));
    }
    if claim_ttl <= maximum_claim_lifetime {
        warnings.push(warning(
            "claim-ttl",
            format!(
                "Claim TTL ({claim_ttl}s) does not exceed the estimated claimed-batch lifetime \
                 ({maximum_claim_lifetime}s); another worker may reclaim active work."
            ),
        ));
    }
    let max_in_flight = threads * batch_size;
    if threads > OVERSIZED_THREAD_THRESHOLD || max_in_flight > OVERSIZED_IN_FLIGHT_THRESHOLD {
        warnings.push(warning(
            "oversized-capacity",
            format!(
                "postthreads={threads} and maxaddprocessed={batch_size} allow up to {max_in_flight} \
                 claimed releases at once; reduce threads or batch size and benchmark end-to-end throughput."
            ),
        ));
    }

    Ok(Report {
        generated_at: iso8601(Utc::now()),
        database_driver: "mariadb".to_owned(),
        backlog,
        settings: ReportSettings {
            pipeline: "v2",
            postthreads: threads,
            maxaddprocessed: batch_size,
            releaseprocessingtimeout: settings.release_timeout_seconds,
            maxpptimeoutcount: settings.max_timeout_count,
            worker_max_batches: WORKER_MAX_BATCHES,
            multiprocessing_child_timeout: child_timeout,
        },
        claims: Claims {
            ttl_seconds: claim_ttl,
            stale_before: iso8601(candidates::claim_stale_before(&scope).and_utc()),
            estimated_claimed_batch_lifetime_seconds: maximum_claim_lifetime,
        },
        capacity: Capacity {
            threads,
            releases_per_batch: batch_size,
            max_in_flight,
            max_per_worker_lifetime: batch_size * WORKER_MAX_BATCHES,
        },
        indexes,
        temp_path,
        warnings,
    })
}

/// Carbon's `toIso8601String`, in UTC as the app's timezone is.
fn iso8601(when: chrono::DateTime<Utc>) -> String {
    when.to_rfc3339_opts(SecondsFormat::Secs, false)
}

async fn indexes(pool: &MySqlPool, warnings: &mut Vec<Warning>) -> Result<Indexes> {
    let rows: Vec<(String, String)> = sqlx::query_as(
        "SELECT INDEX_NAME, LOWER(GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')) \
         FROM information_schema.STATISTICS \
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'releases' GROUP BY INDEX_NAME",
    )
    .fetch_all(pool)
    .await?;
    let mut present = Vec::with_capacity(rows.len());
    let mut claim_queue_index = None;
    for (name, columns) in rows {
        if claim_queue_covers(&columns) {
            claim_queue_index = Some(name.clone());
        }
        present.push(name);
    }
    present.sort();
    let missing = claim_queue_index.is_none();
    if missing {
        warnings.push(warning(
            "missing-index",
            "The releases claim-queue index is missing or has the wrong column order; run pending migrations before processing."
                .to_owned(),
        ));
    }
    Ok(Indexes {
        required_columns: REQUIRED_CLAIM_INDEX_COLUMNS,
        present,
        claim_queue_index,
        missing,
    })
}

/// The required columns must lead the index. The PHP demanded an exact match, so it reported the
/// shipped index, which also ends in `size`, as missing.
fn claim_queue_covers(columns: &str) -> bool {
    let mut actual = columns.split(',');
    REQUIRED_CLAIM_INDEX_COLUMNS
        .iter()
        .all(|required| actual.next() == Some(*required))
}

fn temp_path(config: &Config, warnings: &mut Vec<Warning>) -> TempPath {
    let path = config.tmp_unrar_path.as_path();
    let exists = path.is_dir();
    let writable = if exists {
        workspace::directory_is_writable(path)
    } else {
        workspace::nearest_existing_directory_is_writable(path)
    };
    let shown = path.to_string_lossy().trim_end_matches('/').to_owned();
    if !writable {
        warnings.push(warning(
            "unwritable-temp-path",
            format!(
                "Additional-processing temp path {shown} is not writable and cannot be created by the current user."
            ),
        ));
    }
    TempPath {
        path: shown,
        exists,
        writable,
    }
}

/// The report as tables, then its warnings.
pub fn render(report: &Report) -> String {
    let mut out = table(
        &["Backlog", "Count"],
        &[
            ["Total".to_owned(), report.backlog.total.to_string()],
            ["Available".to_owned(), report.backlog.available.to_string()],
            [
                "Active claims".to_owned(),
                report.backlog.active_claims.to_string(),
            ],
            [
                "Stale claims".to_owned(),
                report.backlog.stale_claims.to_string(),
            ],
        ],
    );
    out.push_str(&table(
        &["Capacity", "Value"],
        &[
            ["Pipeline".to_owned(), report.settings.pipeline.to_owned()],
            ["Threads".to_owned(), report.capacity.threads.to_string()],
            [
                "Releases per batch".to_owned(),
                report.capacity.releases_per_batch.to_string(),
            ],
            [
                "Maximum in flight".to_owned(),
                report.capacity.max_in_flight.to_string(),
            ],
            [
                "Claim TTL".to_owned(),
                format!("{} seconds", report.claims.ttl_seconds),
            ],
            [
                "Child timeout".to_owned(),
                format!("{} seconds", report.settings.multiprocessing_child_timeout),
            ],
        ],
    ));
    out.push_str(&table(
        &["Preflight", "Value"],
        &[
            ["Database driver".to_owned(), report.database_driver.clone()],
            [
                "Claim queue index".to_owned(),
                report
                    .indexes
                    .claim_queue_index
                    .clone()
                    .unwrap_or_else(|| "missing".to_owned()),
            ],
            ["Temp path".to_owned(), report.temp_path.path.clone()],
            [
                "Temp path writable".to_owned(),
                if report.temp_path.writable {
                    "yes"
                } else {
                    "no"
                }
                .to_owned(),
            ],
        ],
    ));
    if report.warnings.is_empty() {
        out.push_str("No additional-processing diagnostic warnings were found.\n");
    } else {
        out.push('\n');
        for warning in &report.warnings {
            out.push_str(&format!("[{}] {}\n", warning.code, warning.message));
        }
    }
    out
}

fn table(headers: &[&str; 2], rows: &[[String; 2]]) -> String {
    let width = |column: usize| {
        rows.iter()
            .map(|row| row[column].chars().count())
            .chain([headers[column].chars().count()])
            .max()
            .unwrap_or(0)
    };
    let (left, right) = (width(0), width(1));
    let rule = format!("+{}+{}+\n", "-".repeat(left + 2), "-".repeat(right + 2));
    let mut out = rule.clone();
    out.push_str(&format!(
        "| {:left$} | {:right$} |\n",
        headers[0], headers[1]
    ));
    out.push_str(&rule);
    for row in rows {
        out.push_str(&format!("| {:left$} | {:right$} |\n", row[0], row[1]));
    }
    out.push_str(&rule);
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_shipped_index_counts_as_the_claim_queue() {
        assert!(claim_queue_covers(
            "passwordstatus,haspreview,nzbstatus,leftguid,postdate,id,additional_pp_claimed_at,size"
        ));
        assert!(claim_queue_covers(
            "passwordstatus,haspreview,nzbstatus,leftguid,postdate,id,additional_pp_claimed_at"
        ));
        assert!(!claim_queue_covers(
            "haspreview,passwordstatus,nzbstatus,leftguid,postdate,id,additional_pp_claimed_at"
        ));
        assert!(!claim_queue_covers("passwordstatus,haspreview"));
    }

    #[test]
    fn tables_line_up() {
        let text = table(
            &["Backlog", "Count"],
            &[["Total".to_owned(), "12".to_owned()]],
        );
        assert_eq!(
            text,
            "+---------+-------+\n| Backlog | Count |\n+---------+-------+\n| Total   | 12    |\n+---------+-------+\n"
        );
    }
}
