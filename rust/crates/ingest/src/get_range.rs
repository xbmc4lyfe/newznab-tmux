//! `articles:get-range`: one range, as a worker of the safe update and safe backfill runs.

use anyhow::{Result, bail};
use chrono::{DateTime, Utc};
use sqlx::MySqlPool;
use tracing::{error, warn};

use crate::config::capacity_available;
use crate::groups::{self, Group};
use crate::missed::MissedParts;
use crate::scan::{Ingest, ScanKind, summary_time};

#[derive(Clone, Copy, Debug, PartialEq, Eq, clap::ValueEnum)]
pub enum RangeMode {
    Binaries,
    Backfill,
}

#[derive(Clone, Debug)]
pub struct RangeRequest {
    pub mode: RangeMode,
    pub group: String,
    pub first: u64,
    pub last: u64,
    /// Refuse a forward range beyond the persisted group cursor.
    pub contiguous: bool,
    /// Repair missing headers before this group visit.
    pub repair: bool,
}

/// Runs one range. Before the scan starts, a parallel range needs durable deferral because a
/// sibling can advance the cursor. Once called, the scan owns admission and storage failures.
pub async fn get_range(ingest: &mut Ingest<'_>, request: &RangeRequest) -> Result<()> {
    if request.first < 1 || request.last < request.first {
        bail!("Article range must contain positive integers with first no greater than last.");
    }
    let pool = ingest.pool;
    if request.contiguous {
        let cursor = groups::by_name(pool, &request.group).await?;
        let beyond = cursor.is_none_or(|group| request.first > group.last_record + 1);
        if request.mode != RangeMode::Binaries || beyond {
            bail!("A preceding header range is unfinished; leaving this range for the next pass.");
        }
    }
    let missed = ingest.missed();
    let outcome = attempt(ingest, request).await;
    if let Err(failure) = &outcome
        && !failure.scanned
        && !request.contiguous
    {
        defer(pool, missed, request).await;
    }
    outcome.map_err(|failure| failure.error)
}

struct RangeFailure {
    scanned: bool,
    error: anyhow::Error,
}

fn before_scan(error: impl Into<anyhow::Error>) -> RangeFailure {
    RangeFailure {
        scanned: false,
        error: error.into(),
    }
}

async fn attempt(ingest: &mut Ingest<'_>, request: &RangeRequest) -> Result<(), RangeFailure> {
    let pool = ingest.pool;
    let config = ingest.config;
    let missed = ingest.missed();
    if !capacity_available(pool, config)
        .await
        .map_err(before_scan)?
    {
        if request.contiguous {
            warn!("Verification backlog is full; leaving this range at the persisted cursor.");
            return Ok(());
        }
        let group = groups::by_name(pool, &request.group)
            .await
            .map_err(before_scan)?;
        let Some(group) = group else {
            return Err(before_scan(anyhow::anyhow!(
                "Verification backlog is full and this range could not be queued for repair; retry this worker."
            )));
        };
        missed
            .defer_range(pool, request.first, request.last, group.id)
            .await
            .map_err(before_scan)?;
        warn!("Verification backlog is full; this range was deferred to part repair.");
        return Ok(());
    }
    let Some(group) = groups::by_name(pool, &request.group)
        .await
        .map_err(before_scan)?
    else {
        return Err(before_scan(anyhow::anyhow!(
            "Group not found: {}",
            request.group
        )));
    };
    if ingest.select_group(&group.name).await.is_none() {
        return Err(before_scan(anyhow::anyhow!(
            "Couldn't select {}",
            group.name
        )));
    }
    if request.repair && config.part_repair {
        ingest.part_repair(&group).await.map_err(before_scan)?;
    }
    if ingest.stopping().await.map_err(before_scan)? {
        if !request.contiguous {
            missed
                .defer_range(pool, request.first, request.last, group.id)
                .await
                .map_err(before_scan)?;
        }
        return Ok(());
    }

    // A binaries range moves the cursor past whatever it doesn't get, so it always tracks what the
    // server didn't return. PHP only did that with `safe_part_repair` on, and lost those articles.
    let kind = if request.mode == RangeMode::Binaries || config.safe_part_repair {
        ScanKind::Update
    } else {
        ScanKind::Backfill
    };
    let scanned = |error: anyhow::Error| RangeFailure {
        scanned: true,
        error,
    };
    let outcome = ingest
        .scan(&group, request.first, request.last, kind, None)
        .await
        .map_err(scanned)?;

    if request.contiguous {
        let Some(end) = outcome.completed_end else {
            return Err(scanned(anyhow::anyhow!(
                "Header download did not complete; leaving this range for the next pass."
            )));
        };
        // An empty response or sparse tail still completes the range. Keep the previous date when
        // no header supplied one, and never advance after a failure.
        let date = outcome
            .summary
            .as_ref()
            .and_then(|summary| summary_time(&summary.last_date));
        advance_cursor(pool, &group, end, date)
            .await
            .map_err(scanned)?;
        return Ok(());
    }
    // A sibling range can move the cursor past this one, so a failed download has to be queued
    // now or it's never fetched. PHP returned here with nothing queued.
    if outcome.completed_end.is_none() {
        missed
            .defer_range(pool, request.first, request.last, group.id)
            .await
            .map_err(scanned)?;
        return Err(scanned(anyhow::anyhow!(
            "Header download failed; the range was deferred to part repair."
        )));
    }
    let Some(summary) = outcome.summary else {
        return Ok(());
    };
    let update = match request.mode {
        RangeMode::Binaries if summary.last_number > group.last_record => Some((
            "UPDATE usenet_groups SET last_record_postdate = FROM_UNIXTIME(?), last_record = ?, \
             last_updated = NOW() WHERE id = ? AND last_record < ?",
            summary_time(&summary.last_date),
            summary.last_number,
        )),
        RangeMode::Backfill if summary.first_number < group.first_record => Some((
            "UPDATE usenet_groups SET first_record_postdate = FROM_UNIXTIME(?), first_record = ?, \
             last_updated = NOW() WHERE id = ? AND first_record > ?",
            summary_time(&summary.first_date),
            summary.first_number,
        )),
        _ => None,
    };
    if let Some((sql, date, number)) = update {
        sqlx::query(sql)
            .bind(date)
            .bind(number)
            .bind(group.id)
            .bind(number)
            .execute(pool)
            .await
            .map_err(|failure| scanned(failure.into()))?;
    }
    Ok(())
}

async fn advance_cursor(
    pool: &MySqlPool,
    group: &Group,
    end: u64,
    date: Option<i64>,
) -> Result<()> {
    let now = Utc::now().naive_utc();
    let date = date.and_then(|time| DateTime::from_timestamp(time, 0));
    match date {
        Some(date) => {
            sqlx::query(
                "UPDATE usenet_groups SET last_record = ?, last_updated = ?, last_record_postdate = ? \
                 WHERE id = ? AND last_record < ?",
            )
            .bind(end)
            .bind(now)
            .bind(date.naive_utc())
            .bind(group.id)
            .bind(end)
            .execute(pool)
            .await?;
        }
        None => {
            sqlx::query(
                "UPDATE usenet_groups SET last_record = ?, last_updated = ? WHERE id = ? AND last_record < ?",
            )
            .bind(end)
            .bind(now)
            .bind(group.id)
            .bind(end)
            .execute(pool)
            .await?;
        }
    }
    Ok(())
}

/// Defers an untried range without using up repair retries.
async fn defer(pool: &MySqlPool, missed: MissedParts, request: &RangeRequest) {
    let group = groups::by_name(pool, &request.group).await;
    let outcome = match group {
        Ok(Some(group)) => {
            missed
                .defer_range(pool, request.first, request.last, group.id)
                .await
        }
        Ok(None) => return,
        Err(failure) => Err(failure),
    };
    match outcome {
        Ok(()) => warn!(
            first = request.first,
            last = request.last,
            group = request.group,
            "queued articles for part repair"
        ),
        Err(failure) => error!(
            error = format!("{failure:#}"),
            "unable to queue a failed article range for part repair"
        ),
    }
}
