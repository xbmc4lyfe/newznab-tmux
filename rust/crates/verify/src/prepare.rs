//! Classifies stored header inventories without opening an NNTP connection, and archives the late
//! headers of dirty candidates. This is the `releases:prepare-candidates` work.

use std::time::{Duration, Instant};

use anyhow::Result;
use chrono::{NaiveDateTime, TimeDelta};
use serde::{Deserialize, Serialize};
use sqlx::{Connection, MySql, QueryBuilder};
use tracing::{info, warn};

use crate::candidates::{self, cut};
use crate::collections::{ELIGIBLE, READY};
use crate::context::Verify;
use crate::inventory::{self, HeaderStatus};
use crate::locks::WorkerLocks;
use crate::manifest;

const WAITING_BATCH_SIZE: usize = 25;
const DIRTY_CURSOR: &str = "release-verification:dirty-settle:cursor";

#[derive(Clone, Debug, sqlx::FromRow)]
struct PendingRow {
    id: u64,
    version: u32,
    manifest_path: String,
    status: String,
    next_attempt_at: NaiveDateTime,
}

/// Classifies up to `limit` manifests, stopping new reads after `seconds`. `prefer_pending` is asked
/// whether this turn prefers `pending` over `unknown`; `true` spends the turn. Returns rows changed.
pub async fn prepare(
    verify: &Verify,
    limit: i64,
    seconds: u64,
    mut prefer_pending: impl FnMut(bool) -> bool,
) -> Result<u64> {
    let limit = limit.max(1);
    let deadline = Instant::now() + Duration::from_secs(seconds.max(1));
    let cutoff = candidates::now();
    let mut inspected = 0;
    let mut changed = 0;
    let mut cursors: [Option<(NaiveDateTime, u64)>; 2] = [None, None];
    let mut exhausted = [false, false];
    const STATUSES: [&str; 2] = ["pending", "unknown"];
    let mut waiting: Vec<PendingRow> = Vec::new();
    let mut waiting_reason = String::new();
    'run: while inspected < limit && !(exhausted[0] && exhausted[1]) && Instant::now() < deadline {
        // Peek before selection; spend a turn only when its first metadata read is admitted.
        let order = if prefer_pending(false) {
            [0, 1]
        } else {
            [1, 0]
        };
        for index in order {
            if exhausted[index] || Instant::now() >= deadline || inspected >= limit {
                continue;
            }
            let page_size = (limit - inspected).min(100);
            let rows =
                select_page(verify, STATUSES[index], cutoff, cursors[index], page_size).await?;
            if (rows.len() as i64) < page_size {
                exhausted[index] = true;
            }
            let found = !rows.is_empty();
            let mut phase_committed = false;
            for row in rows {
                if Instant::now() >= deadline {
                    changed += flush_waiting(verify, &mut waiting, &waiting_reason, cutoff).await?;
                    break 'run;
                }
                if !phase_committed {
                    prefer_pending(true);
                    phase_committed = true;
                }
                inspected += 1;
                cursors[index] = Some((row.next_attempt_at, row.id));
                let (status, reason) =
                    match manifest::read(&verify.db, &verify.config, &row.manifest_path).await {
                        Ok(manifest) => {
                            let assessment = inventory::assess(&manifest);
                            (
                                assessment.status.as_str(),
                                assessment.reason.unwrap_or("").to_owned(),
                            )
                        }
                        Err(error) => ("unknown", error.to_string()),
                    };
                if status == HeaderStatus::WaitingUpload.as_str() {
                    let reason = cut(&reason, 2000).to_owned();
                    if waiting_reason != reason {
                        changed +=
                            flush_waiting(verify, &mut waiting, &waiting_reason, cutoff).await?;
                        waiting_reason = reason;
                    }
                    waiting.push(row);
                    if waiting.len() == WAITING_BATCH_SIZE {
                        changed +=
                            flush_waiting(verify, &mut waiting, &waiting_reason, cutoff).await?;
                    }
                    continue;
                }
                // Keep ready promotions and retry timestamps immediate and ordered.
                changed += flush_waiting(verify, &mut waiting, &waiting_reason, cutoff).await?;
                changed += update_row(verify, &row, status, &reason, cutoff).await?;
            }
            changed += flush_waiting(verify, &mut waiting, &waiting_reason, cutoff).await?;
            if found {
                break;
            }
        }
    }
    Ok(changed)
}

async fn select_page(
    verify: &Verify,
    status: &str,
    cutoff: NaiveDateTime,
    cursor: Option<(NaiveDateTime, u64)>,
    limit: i64,
) -> Result<Vec<PendingRow>> {
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT id, version, manifest_path, status, next_attempt_at FROM release_verification_candidates WHERE status = ",
    );
    query.push_bind(status);
    query.push(" AND next_attempt_at <= ").push_bind(cutoff);
    query.push(" AND release_id IS NULL AND lease_token IS NULL AND (lease_until IS NULL OR lease_until < ");
    query.push_bind(cutoff).push(")");
    if let Some((at, id)) = cursor {
        query.push(" AND (next_attempt_at > ").push_bind(at);
        query.push(" OR (next_attempt_at = ").push_bind(at);
        query.push(" AND id > ").push_bind(id).push("))");
    }
    query
        .push(" ORDER BY next_attempt_at, id LIMIT ")
        .push_bind(limit);
    Ok(query.build_query_as().fetch_all(&verify.db).await?)
}

/// Writes a verdict only if the row still holds what was read. A valid candidate keeps its NNTP
/// backoff and diagnostic reason.
async fn update_row(
    verify: &Verify,
    row: &PendingRow,
    status: &str,
    reason: &str,
    cutoff: NaiveDateTime,
) -> Result<u64> {
    let mut query =
        QueryBuilder::<MySql>::new("UPDATE release_verification_candidates SET status = ");
    query.push_bind(status).push(", header_check_pending = 0");
    if status != "ready" {
        query.push(", reason = ").push_bind(cut(reason, 2000));
        if status == "unknown" {
            query.push(", next_attempt_at = ");
            query.push_bind(candidates::now() + TimeDelta::seconds(verify.config.retry_seconds));
        }
    }
    query.push(" WHERE id = ").push_bind(row.id);
    query.push(" AND version = ").push_bind(row.version);
    query
        .push(" AND manifest_path = ")
        .push_bind(&row.manifest_path);
    query.push(" AND status = ").push_bind(&row.status);
    query
        .push(" AND next_attempt_at = ")
        .push_bind(row.next_attempt_at);
    query.push(" AND release_id IS NULL AND lease_token IS NULL AND (lease_until IS NULL OR lease_until < ");
    query.push_bind(cutoff).push(")");
    Ok(query.build().execute(&verify.db).await?.rows_affected())
}

async fn flush_waiting(
    verify: &Verify,
    rows: &mut Vec<PendingRow>,
    reason: &str,
    cutoff: NaiveDateTime,
) -> Result<u64> {
    if rows.is_empty() {
        return Ok(0);
    }
    let affected = update_waiting(verify, rows, reason, cutoff).await?;
    rows.clear();
    Ok(affected)
}

/// Ingestion can own candidates in collection order, so only available candidate locks are taken: a
/// multirow update can't wait while holding its prefix. The physical scan stays on the primary-key
/// list even when other status indexes look cheaper.
async fn update_waiting(
    verify: &Verify,
    rows: &[PendingRow],
    reason: &str,
    cutoff: NaiveDateTime,
) -> Result<u64> {
    let mut conn = verify.db.acquire().await?;
    let mut tx = conn.begin().await?;
    let mut select = QueryBuilder::<MySql>::new(
        "SELECT id FROM release_verification_candidates FORCE INDEX (PRIMARY) WHERE id IN (",
    );
    let mut separated = select.separated(",");
    for row in rows {
        separated.push_bind(row.id);
    }
    select.push(") AND release_id IS NULL AND lease_token IS NULL AND (lease_until IS NULL OR lease_until < ");
    select.push_bind(cutoff).push(") AND (");
    for (index, row) in rows.iter().enumerate() {
        if index > 0 {
            select.push(" OR ");
        }
        select.push("(id = ").push_bind(row.id);
        select.push(" AND version = ").push_bind(row.version);
        select
            .push(" AND manifest_path = ")
            .push_bind(&row.manifest_path);
        select.push(" AND status = ").push_bind(&row.status);
        select
            .push(" AND next_attempt_at = ")
            .push_bind(row.next_attempt_at);
        select.push(")");
    }
    select.push(") FOR UPDATE SKIP LOCKED");
    let ids: Vec<(u64,)> = select.build_query_as().fetch_all(&mut *tx).await?;
    if ids.is_empty() {
        return Ok(0);
    }
    let mut update = QueryBuilder::<MySql>::new(
        "UPDATE release_verification_candidates SET status = 'waiting_upload', header_check_pending = 0, reason = ",
    );
    update.push_bind(reason).push(" WHERE id IN (");
    let mut separated = update.separated(",");
    for (id,) in &ids {
        separated.push_bind(*id);
    }
    update.push(")");
    let affected = update.build().execute(&mut *tx).await?.rows_affected();
    tx.commit().await?;
    Ok(affected)
}

#[derive(Debug, Serialize, Deserialize)]
struct DirtyCursor {
    at: Option<NaiveDateTime>,
    id: u64,
    upper_at: NaiveDateTime,
    upper_id: u64,
}

/// What one dirty-settle pass did.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct DirtyTotals {
    pub inspected: u64,
    pub settled: u64,
    pub kept: u64,
}

/// Archives the late headers of dirty candidates. Ingestion marks a pending candidate dirty when more
/// of its articles arrive, and only archiving that collection makes it claimable again. Release
/// creation reaches a collection only when its group's cursor does, which on a large group can take
/// weeks, so this walks the dirty candidates themselves, oldest first, resuming after the last one it
/// looked at. Each sweep ends at the time it began, so it always comes round again to those it passed
/// over, however many become dirty while it runs.
pub async fn settle_dirty(verify: &Verify, limit: i64, seconds: u64) -> Result<DirtyTotals> {
    let mut totals = DirtyTotals::default();
    let limit = limit.max(1);
    let deadline = Instant::now() + Duration::from_secs(seconds);
    let cursor: Option<DirtyCursor> = verify.cache.get(DIRTY_CURSOR).await;
    let (after, upper_at, upper_id) = match cursor {
        Some(cursor) => (
            cursor.at.map(|at| (at, cursor.id)),
            cursor.upper_at,
            cursor.upper_id,
        ),
        None => (None, candidates::now(), u64::MAX),
    };
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT id, source_identity, next_attempt_at FROM release_verification_candidates WHERE status = 'dirty'",
    );
    if let Some((at, id)) = after {
        query.push(" AND (next_attempt_at > ").push_bind(at);
        query.push(" OR (next_attempt_at = ").push_bind(at);
        query.push(" AND id > ").push_bind(id).push("))");
    }
    query.push(" AND (next_attempt_at < ").push_bind(upper_at);
    query.push(" OR (next_attempt_at = ").push_bind(upper_at);
    query.push(" AND id <= ").push_bind(upper_id).push("))");
    query
        .push(" ORDER BY next_attempt_at, id LIMIT ")
        .push_bind(limit);
    let rows: Vec<(u64, Option<String>, NaiveDateTime)> =
        query.build_query_as().fetch_all(&verify.db).await?;
    if rows.is_empty() {
        // This sweep is done: the next run starts a new one from the oldest.
        verify.cache.forget(DIRTY_CURSOR).await?;
        return Ok(totals);
    }
    let count = rows.len() as i64;
    for (id, source, at) in rows {
        if totals.inspected > 0 && Instant::now() >= deadline {
            return Ok(totals);
        }
        // Moved past first: a process that dies inside archive skips this candidate until the walk
        // comes round again, instead of retrying it on every run ahead of the rest.
        verify
            .cache
            .forever(
                DIRTY_CURSOR,
                &DirtyCursor {
                    at: Some(at),
                    id,
                    upper_at,
                    upper_id,
                },
            )
            .await?;
        totals.inspected += 1;
        let hash = source
            .as_deref()
            .filter(|source| source.len() == 40)
            .and_then(|source| hex::decode(source).ok());
        let ids: Vec<i64> = match hash {
            None => Vec::new(),
            Some(hash) => {
                let sql = format!(
                    "SELECT collections.id FROM collections WHERE {ELIGIBLE} AND {READY} AND collectionhash = ? ORDER BY collections.id"
                );
                sqlx::query_scalar::<_, u32>(sqlx::AssertSqlSafe(sql))
                    .bind(hash)
                    .fetch_all(&verify.db)
                    .await?
                    .into_iter()
                    .map(i64::from)
                    .collect()
            }
        };
        let kept = candidates::archive_each(verify, &ids).await;
        for (collection, outcome) in &kept {
            if let Some(error) = &outcome.error {
                warn!(
                    collection_id = collection,
                    error = format!("{error:#}"),
                    retry_after = outcome.retry_after.as_deref(),
                    "verification archival failed while settling dirty candidates; original headers retained"
                );
            }
        }
        totals.kept += kept.len() as u64;
        let status: Option<String> =
            sqlx::query_scalar("SELECT status FROM release_verification_candidates WHERE id = ?")
                .bind(id)
                .fetch_optional(&verify.db)
                .await?;
        if status.as_deref() != Some("dirty") {
            totals.settled += 1;
        }
    }
    if count < limit {
        // The sweep reached its end: the next run starts a new one from the oldest.
        verify.cache.forget(DIRTY_CURSOR).await?;
    }
    Ok(totals)
}

/// Options for one preparation pass, the flags of `releases:prepare-candidates`.
#[derive(Clone, Copy, Debug)]
pub struct PrepareOptions {
    pub limit: i64,
    pub max_seconds: u64,
    pub dirty_limit: i64,
    pub dirty_seconds: u64,
}

impl Default for PrepareOptions {
    fn default() -> Self {
        Self {
            limit: 5000,
            max_seconds: 30,
            dirty_limit: 200,
            dirty_seconds: 20,
        }
    }
}

/// One preparation pass. False when another preparer or exclusive maintenance is running.
pub async fn run_pass(
    verify: &Verify,
    locks: &mut WorkerLocks,
    options: PrepareOptions,
) -> Result<bool> {
    if !locks.start_preparation().await? {
        return Ok(false);
    }
    let result = run_locked(verify, locks, options).await;
    locks.close().await;
    result?;
    Ok(true)
}

async fn run_locked(
    verify: &Verify,
    locks: &mut WorkerLocks,
    options: PrepareOptions,
) -> Result<()> {
    let changed = prepare(verify, options.limit, options.max_seconds, |advance| {
        locks.prefer_primary_queue(advance)
    })
    .await?;
    let promoted = candidates::promote_partial(verify, options.limit).await?;
    // Late headers make a candidate dirty until archive merges them; release creation may not reach
    // their collection for a long time on a large group.
    let dirty = settle_dirty(verify, options.dirty_limit, options.dirty_seconds).await?;
    info!(
        classified = changed,
        promoted,
        dirty_inspected = dirty.inspected,
        dirty_settled = dirty.settled,
        dirty_kept = dirty.kept,
        "candidate preparation pass"
    );
    Ok(())
}
