//! `nntmux-ctl fingerprint backfill`: queueing releases whose fingerprint is missing or out of date.

use std::time::Duration;

use anyhow::{Context, Result};
use chrono::{TimeDelta, Utc};
use nntmux_verify::nzb::store;
use sqlx::MySqlPool;

/// Marks the record the collector writes; any other format or version is collected again.
pub const FORMAT: &str = "compact-v1";
pub const SCHEMA_VERSION: i64 = 1;
/// How long a collection may run, after which a job's lease has long lapsed.
pub const TIMEOUT_SECONDS: i64 = 1800;
const PAGE: i64 = 1000;

#[derive(Clone, Copy, Debug)]
pub struct Options {
    pub limit: i64,
    pub after_id: i64,
    pub delay_ms: u64,
    pub dry_run: bool,
    pub stale: bool,
}

type Markers = (
    i64,
    Option<String>,
    Option<String>,
    Option<String>,
    Option<String>,
    Option<String>,
);

/// Decided here from each marker's JSON type and value, because the database's coercion would let
/// "1" or 1.0 pass for 1, and hide a stale record.
fn current((_, status, version_type, version, format_type, format): &Markers) -> bool {
    status.as_deref() == Some("complete")
        && version_type
            .as_deref()
            .is_some_and(|kind| kind.eq_ignore_ascii_case("integer"))
        && version
            .as_deref()
            .and_then(|value| value.parse::<i64>().ok())
            == Some(SCHEMA_VERSION)
        && format_type
            .as_deref()
            .is_some_and(|kind| kind.eq_ignore_ascii_case("string"))
        && format.as_deref() == Some(FORMAT)
}

/// Published releases after `after` whose fingerprint is missing or not current.
pub async fn stale_release_ids(pool: &MySqlPool, after: i64, limit: i64) -> Result<Vec<i64>> {
    let mut ids = Vec::new();
    let mut cursor = after;
    loop {
        let rows: Vec<Markers> = sqlx::query_as(
            "SELECT CAST(r.id AS SIGNED), m.status, \
             JSON_TYPE(JSON_EXTRACT(m.metadata, '$.schema_version')), JSON_UNQUOTE(JSON_EXTRACT(m.metadata, '$.schema_version')), \
             JSON_TYPE(JSON_EXTRACT(m.metadata, '$.format')), JSON_UNQUOTE(JSON_EXTRACT(m.metadata, '$.format')) \
             FROM releases r LEFT JOIN release_fingerprint_metadata m ON m.releases_id = r.id \
             WHERE r.nzbstatus = 1 AND r.id > ? ORDER BY r.id LIMIT ?",
        )
        .bind(cursor)
        .bind(PAGE)
        .fetch_all(pool)
        .await?;
        if rows.is_empty() {
            return Ok(ids);
        }
        for row in &rows {
            cursor = row.0;
            if !current(row) {
                ids.push(row.0);
                if ids.len() as i64 >= limit {
                    return Ok(ids);
                }
            }
        }
    }
}

/// Records an upgrade marked stale at least an hour ago, so one that keeps failing is retried
/// hourly, and runs still processing long past the timeout, whose worker died.
pub async fn interrupted_release_ids(pool: &MySqlPool, after: i64, limit: i64) -> Result<Vec<i64>> {
    let now = Utc::now().naive_utc();
    Ok(sqlx::query_scalar(
        "SELECT CAST(releases_id AS SIGNED) FROM release_fingerprint_metadata \
         WHERE ((status = 'stale' AND updated_at < ?) OR (status = 'processing' AND updated_at < ?)) \
         AND releases_id > ? ORDER BY releases_id LIMIT ?",
    )
    .bind(now - TimeDelta::hours(1))
    .bind(now - TimeDelta::seconds(2 * TIMEOUT_SECONDS))
    .bind(after)
    .bind(limit)
    .fetch_all(pool)
    .await?)
}

/// Returns the releases matched, queued unless this is a dry run. A release with no stored NZB stops
/// the run, as it did in PHP, with everything before it already queued.
pub async fn backfill(pool: &MySqlPool, options: &Options) -> Result<Vec<i64>> {
    let limit = options.limit.clamp(1, 10_000);
    let after = options.after_id.max(0);
    let delay = Duration::from_millis(options.delay_ms.min(60_000));
    let ids = if options.stale {
        interrupted_release_ids(pool, after, limit).await?
    } else {
        stale_release_ids(pool, after, limit).await?
    };
    if options.dry_run {
        return Ok(ids);
    }
    for id in &ids {
        let queued = store::enqueue_fingerprint(pool, *id, true).await?;
        if !queued {
            return Err(anyhow::anyhow!(
                "Fingerprint work has no database NZB source."
            ))
            .with_context(|| format!("release {id}"));
        }
        if !delay.is_zero() {
            tokio::time::sleep(delay).await;
        }
    }
    Ok(ids)
}

pub async fn run(pool: &MySqlPool, options: &Options) -> Result<()> {
    let ids = backfill(pool, options).await?;
    if options.dry_run {
        for id in &ids {
            println!("{id}");
        }
        println!(
            "Dry run: {} matching releases; nothing was queued.",
            ids.len()
        );
        return Ok(());
    }
    let resume = ids.last().copied().unwrap_or(options.after_id.max(0));
    println!(
        "Queued {} releases. Resume with --after-id={resume}.",
        ids.len()
    );
    Ok(())
}
