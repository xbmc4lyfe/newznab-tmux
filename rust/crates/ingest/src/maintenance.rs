//! One-off repair commands that need no news server.

use anyhow::Result;
use chrono::{Duration, DurationRound, NaiveDate, NaiveDateTime, Utc};
use sqlx::{AssertSqlSafe, MySqlPool};
use tracing::{info, warn};

/// Reasons `CandidateHeaderInventory` gives for headers that can never pass verification.
const REJECTION_REASONS: [&str; 5] = [
    "Conflicting header identities require resolution.",
    "Candidate has no file inventory to spot check.",
    "Invalid or duplicate file identity in candidate headers.",
    "Invalid declared article count in candidate headers.",
    "Invalid or duplicate article reference in candidate headers.",
];
const REJECTED: &str = "header_rejected";

/// How far a reclassify run got.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Reclassified {
    pub changed: u64,
    /// Set when `max_seconds` ran out: resume after this id.
    pub resume_after: Option<u64>,
}

/// Candidates recorded before `header_rejected` existed keep retrying as unknown. Moves those whose
/// reason is a header defect out of the claim queue, in short primary-key ranges, skipping any a
/// worker holds and any newly admitted version still awaiting its first check.
pub async fn reclassify_header_rejections(
    pool: &MySqlPool,
    chunk: u64,
    after: u64,
    max_seconds: u64,
) -> Result<Reclassified> {
    let chunk = chunk.max(1);
    let started = std::time::Instant::now();
    let ceiling: Option<u64> =
        sqlx::query_scalar("SELECT MAX(id) FROM release_verification_candidates")
            .fetch_one(pool)
            .await?;
    let ceiling = ceiling.unwrap_or(0);
    let mut changed = 0;
    let mut position = after;
    while position < ceiling {
        if max_seconds > 0 && started.elapsed().as_secs() >= max_seconds {
            warn!(position, "stopped at the time limit");
            return Ok(Reclassified {
                changed,
                resume_after: Some(position),
            });
        }
        changed += reclassify_range(pool, position, position + chunk).await?;
        position += chunk;
    }
    info!(changed, "reclassified candidates with header defects");
    Ok(Reclassified {
        changed,
        resume_after: None,
    })
}

async fn reclassify_range(pool: &MySqlPool, after: u64, through: u64) -> Result<u64> {
    let reasons = vec!["?"; REJECTION_REASONS.len()].join(",");
    let mut transaction = pool.begin().await?;
    // Ingestion and workers may hold candidates in another order, so take only available rows.
    // Both the locking read and the update stay on the primary key, inside this id range.
    let sql = format!(
        "SELECT id FROM release_verification_candidates FORCE INDEX (PRIMARY)
         WHERE id > ? AND id <= ? AND status = 'unknown' AND release_id IS NULL
         AND lease_token IS NULL AND header_check_pending = 0 AND reason IN ({reasons})
         FOR UPDATE SKIP LOCKED"
    );
    let mut select = sqlx::query_scalar::<_, u64>(AssertSqlSafe(sql))
        .bind(after)
        .bind(through);
    for reason in REJECTION_REASONS {
        select = select.bind(reason);
    }
    let ids = select.fetch_all(&mut *transaction).await?;
    if ids.is_empty() {
        return Ok(0);
    }
    // UPDATE ignores SELECT index hints, so bound its scan to the rows just locked.
    let marks = vec!["?"; ids.len()].join(",");
    let sql = format!(
        "UPDATE release_verification_candidates FORCE INDEX (PRIMARY) SET status = ?
         WHERE id IN ({marks}) AND status = 'unknown' AND release_id IS NULL
         AND lease_token IS NULL AND header_check_pending = 0"
    );
    let mut update = sqlx::query(AssertSqlSafe(sql)).bind(REJECTED);
    for id in &ids {
        update = update.bind(id);
    }
    let changed = update.execute(&mut *transaction).await?.rows_affected();
    transaction.commit().await?;
    Ok(changed)
}

/// How many periods were collected and skipped.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Collected {
    pub collected: u64,
    pub skipped: u64,
}

/// Rebuilds daily activity from retained hourly aggregates, for the last `days` days.
pub async fn backfill_daily_stats(pool: &MySqlPool, days: i64, force: bool) -> Result<Collected> {
    let today = Utc::now().date_naive();
    let mut totals = Collected::default();
    for back in (0..days).rev() {
        let date = today - Duration::days(back);
        let exists: bool = sqlx::query_scalar(
            "SELECT EXISTS(SELECT 1 FROM user_activity_stats WHERE stat_date = ?)",
        )
        .bind(date)
        .fetch_one(pool)
        .await?;
        if exists && !force {
            totals.skipped += 1;
            continue;
        }
        collect_day(pool, date).await?;
        totals.collected += 1;
    }
    Ok(totals)
}

async fn collect_day(pool: &MySqlPool, date: NaiveDate) -> Result<()> {
    let start = date.and_hms_opt(0, 0, 0).unwrap_or_default();
    let end = start + Duration::days(1);
    let (downloads, api_hits): (i64, i64) = sqlx::query_as(
        "SELECT CAST(COALESCE(SUM(downloads_count), 0) AS SIGNED),
                CAST(COALESCE(SUM(api_hits_count), 0) AS SIGNED)
         FROM user_activity_stats_hourly WHERE stat_hour >= ? AND stat_hour < ?",
    )
    .bind(start)
    .bind(end)
    .fetch_one(pool)
    .await?;
    let now = Utc::now().naive_utc();
    let updated = sqlx::query(
        "UPDATE user_activity_stats SET downloads_count = ?, api_hits_count = ?, updated_at = ?
         WHERE stat_date = ?",
    )
    .bind(downloads)
    .bind(api_hits)
    .bind(now)
    .bind(date)
    .execute(pool)
    .await?;
    if updated.rows_affected() == 0 {
        sqlx::query(
            "INSERT IGNORE INTO user_activity_stats
             (stat_date, downloads_count, api_hits_count, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)",
        )
        .bind(date)
        .bind(downloads)
        .bind(api_hits)
        .bind(now)
        .bind(now)
        .execute(pool)
        .await?;
    }
    Ok(())
}

/// Rebuilds hourly activity from the raw logs, for the last `hours` hours.
pub async fn backfill_hourly_stats(pool: &MySqlPool, hours: i64, force: bool) -> Result<Collected> {
    let now = Utc::now();
    let current = now.duration_trunc(Duration::hours(1))?.naive_utc();
    let mut totals = Collected::default();
    for back in (0..hours).rev() {
        let hour = current - Duration::hours(back);
        let exists: bool = sqlx::query_scalar(
            "SELECT EXISTS(SELECT 1 FROM user_activity_stats_hourly WHERE stat_hour = ?)",
        )
        .bind(hour)
        .fetch_one(pool)
        .await?;
        if exists && !force {
            totals.skipped += 1;
            continue;
        }
        collect_hour(pool, hour).await?;
        totals.collected += 1;
    }
    Ok(totals)
}

async fn collect_hour(pool: &MySqlPool, hour: NaiveDateTime) -> Result<()> {
    let next = hour + Duration::hours(1);
    let count = |table: &'static str| async move {
        let sql = format!("SELECT COUNT(*) FROM {table} WHERE timestamp >= ? AND timestamp < ?");
        sqlx::query_scalar::<_, i64>(AssertSqlSafe(sql))
            .bind(hour)
            .bind(next)
            .fetch_one(pool)
            .await
    };
    let downloads = count("user_downloads").await?;
    let api_hits = count("user_requests").await?;
    let now = Utc::now().naive_utc();
    let updated = sqlx::query(
        "UPDATE user_activity_stats_hourly SET downloads_count = ?, api_hits_count = ?, updated_at = ?,
         created_at = COALESCE(created_at, NOW()) WHERE stat_hour = ?",
    )
    .bind(downloads)
    .bind(api_hits)
    .bind(now)
    .bind(hour)
    .execute(pool)
    .await?;
    if updated.rows_affected() == 0 {
        sqlx::query(
            "INSERT IGNORE INTO user_activity_stats_hourly
             (stat_hour, downloads_count, api_hits_count, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), ?)",
        )
        .bind(hour)
        .bind(downloads)
        .bind(api_hits)
        .bind(now)
        .execute(pool)
        .await?;
    }
    Ok(())
}
