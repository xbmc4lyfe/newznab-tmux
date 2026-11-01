//! Retires candidates and retained inventories no new article has reached within the expiry window.
//! Each walk pages by a stored cursor, so an expired or owed prefix never makes maintenance a full
//! table scan.

use std::time::{Duration, Instant};

use anyhow::Result;
use chrono::{NaiveDateTime, TimeDelta};
use sqlx::{MySql, QueryBuilder};

use super::{
    Candidate, PARTIAL_DEFERRED, PARTIAL_QUEUED, now, owed_partial_cutoff,
    release_late_article_fence,
};
use crate::context::Verify;
use crate::in_tx;
use crate::ledger;
use crate::manifest;

const EXPIRY_CURSOR: &str = "release_verification:expiry_cursor";
const SETTLE_CURSOR: &str = "release_verification:settle_cursor";
const RETAINED_CURSOR: &str = "release_verification:retained_expiry_cursor";
const DAY: Duration = Duration::from_secs(86_400);

const EXPIRABLE: &str = "'ready', 'waiting_upload', 'pending', 'unknown', 'header_rejected', 'unrepairable', \
    'verifying', 'dirty', 'metadata_deferred', 'metadata_review'";

fn expirable(status: &str) -> bool {
    matches!(
        status,
        "ready"
            | "waiting_upload"
            | "pending"
            | "unknown"
            | "header_rejected"
            | "unrepairable"
            | "verifying"
            | "dirty"
            | "metadata_deferred"
            | "metadata_review"
    )
}

/// Runs one bounded maintenance pass, starting no new step after `deadline`.
pub async fn expire(verify: &Verify, deadline: Option<Instant>) -> Result<()> {
    let may_start = || deadline.is_none_or(|at| Instant::now() < at);
    if !may_start() {
        return Ok(());
    }
    expire_candidates(verify, deadline).await?;
    expire_retained_partials(verify, deadline).await?;
    settle_late_article_holders(verify, deadline).await
}

async fn expire_candidates(verify: &Verify, deadline: Option<Instant>) -> Result<()> {
    let may_start = || deadline.is_none_or(|at| Instant::now() < at);
    let mut after: i64 = verify
        .cache
        .get::<i64>(EXPIRY_CURSOR)
        .await
        .unwrap_or(0)
        .max(0);
    let start_after = after;
    let mut wrapped = after == 0;
    let partials = verify.config.partial_after_hours > 0;
    let mut expired = 0;
    let result: Result<()> = async {
        for page in 0..10 {
            if expired >= 100 {
                return Ok(());
            }
            if page > 0 && !may_start() {
                return Ok(());
            }
            let through = (wrapped && start_after > 0).then_some(start_after);
            let bounds = page_bounds(verify, after, through).await?;
            let page_end = bounds.first().copied().or(through);
            let has_more = bounds.len() > 1;
            for id in expirable_ids(verify, after, page_end, 100 - expired, partials).await? {
                if !may_start() {
                    return Ok(());
                }
                if expire_candidate(verify, id, partials).await? {
                    expired += 1;
                }
                after = id;
                if expired >= 100 {
                    return Ok(());
                }
            }
            if has_more && let Some(end) = page_end {
                after = end;
                continue;
            }
            after = 0;
            if wrapped {
                return Ok(());
            }
            wrapped = true;
        }
        Ok(())
    }
    .await;
    // The next pass starts over when this can't be stored.
    let _ = verify.cache.put(EXPIRY_CURSOR, &after, DAY).await;
    result
}

/// The ids at offsets 999 and 1000 after `after`: the end of this page, and whether more follow.
async fn page_bounds(verify: &Verify, after: i64, through: Option<i64>) -> Result<Vec<i64>> {
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT id FROM release_verification_candidates FORCE INDEX (PRIMARY) WHERE id > ",
    );
    query.push_bind(after);
    if let Some(through) = through {
        query.push(" AND id <= ").push_bind(through);
    }
    query.push(" ORDER BY id LIMIT 2 OFFSET 999");
    let rows: Vec<(u64,)> = query.build_query_as().fetch_all(&verify.db).await?;
    Ok(rows.into_iter().map(|(id,)| id as i64).collect())
}

async fn expirable_ids(
    verify: &Verify,
    after: i64,
    through: Option<i64>,
    limit: i64,
    partials: bool,
) -> Result<Vec<i64>> {
    let now = now();
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT id FROM release_verification_candidates FORCE INDEX (PRIMARY) WHERE id > ",
    );
    query.push_bind(after);
    if let Some(through) = through {
        query.push(" AND id <= ").push_bind(through);
    }
    query.push(" AND release_id IS NULL AND status IN (");
    query.push(EXPIRABLE);
    query.push(") AND last_unique_at < ");
    query.push_bind(now - TimeDelta::hours(verify.config.expiry_hours));
    query
        .push(" AND (lease_until IS NULL OR lease_until < ")
        .push_bind(now)
        .push(")");
    if partials {
        // A stalled upload still owed its partial attempt is published, not expired. It would otherwise
        // lose the race with promotion, and always lose it when the hold reaches the expiry window.
        query
            .push(" AND (last_unique_at < ")
            .push_bind(owed_partial_cutoff(&verify.config));
        query.push(" OR status NOT IN ('waiting_upload', 'ready')");
        query.push(" OR (status = 'waiting_upload' AND reason LIKE ");
        query.push_bind(format!("{PARTIAL_DEFERRED}%")).push(")");
        query.push(" OR (status = 'ready' AND (reason IS NULL OR reason != ");
        query.push_bind(PARTIAL_QUEUED).push(")))");
    }
    query.push(" ORDER BY id LIMIT ").push_bind(limit.max(1));
    let rows: Vec<(u64,)> = query.build_query_as().fetch_all(&verify.db).await?;
    Ok(rows.into_iter().map(|(id,)| id as i64).collect())
}

/// A stalled upload not yet tried as a partial, or queued for that try, which expiry must leave: for
/// one expiry window past its hold, so a promotion backlog can't keep inventory indefinitely.
fn owed_partial_attempt(verify: &Verify, row: &Candidate) -> bool {
    row.last_unique_at >= owed_partial_cutoff(&verify.config)
        && ((row.status == "waiting_upload"
            && !row
                .reason
                .as_deref()
                .unwrap_or("")
                .starts_with(PARTIAL_DEFERRED))
            || (row.status == "ready" && row.reason.as_deref() == Some(PARTIAL_QUEUED)))
}

async fn expire_candidate(verify: &Verify, id: i64, partials: bool) -> Result<bool> {
    let config = &verify.config;
    let done = in_tx!(verify.db, 5, |tx| {
        let row: Option<Candidate> =
            sqlx::query_as("SELECT * FROM release_verification_candidates WHERE id = ? FOR UPDATE")
                .bind(id)
                .fetch_optional(&mut *tx)
                .await?;
        let Some(row) = row else {
            return Ok::<Option<String>, anyhow::Error>(None);
        };
        let now = now();
        if row.release_id.is_some()
            || !expirable(&row.status)
            || row.last_unique_at >= now - TimeDelta::hours(config.expiry_hours)
            || row.lease_until.is_some_and(|until| until >= now)
            || (partials && owed_partial_attempt(verify, &row))
        {
            return Ok(None);
        }
        let status = if matches!(row.status.as_str(), "metadata_deferred" | "metadata_review") {
            "metadata_expired"
        } else {
            "expired"
        };
        sqlx::query(
            "UPDATE release_verification_candidates SET status = ?, version = ?, manifest_path = '', \
             manifest_bytes = 0, lease_token = NULL, lease_until = NULL, upgrades_release_id = NULL WHERE id = ?",
        )
        .bind(status)
        .bind(row.version + 1)
        .bind(id)
        .execute(&mut *tx)
        .await?;
        if let Some(release) = row.upgrades_release_id {
            // An expired generation can no longer upgrade its partial; workers may now process it.
            release_late_article_fence(tx, &[release as i64], Some(id)).await?;
        }
        ledger::adjust(tx, config, -row.manifest_bytes).await?;
        Ok(Some(row.manifest_path))
    })?;
    match done {
        Some(path) => {
            manifest::delete_if_unreferenced(&verify.db, config, &path).await?;
            Ok(true)
        }
        None => Ok(false),
    }
}

/// Releases the inventory a partial publication kept for late articles once none arrive within the
/// expiry window. Pages in expiry order, resuming where the last pass stopped: rows held back by late
/// headers awaiting archive must not starve the ones behind them.
async fn expire_retained_partials(verify: &Verify, deadline: Option<Instant>) -> Result<()> {
    let may_start = || deadline.is_none_or(|at| Instant::now() < at);
    let cutoff = now() - TimeDelta::hours(verify.config.expiry_hours);
    let mut after: Option<(NaiveDateTime, i64)> = verify.cache.get(RETAINED_CURSOR).await;
    let mut released = 0;
    let result: Result<()> = async {
        'pages: for _ in 0..10 {
            if released >= 100 {
                break;
            }
            let rows = retained_partials(verify, cutoff, after).await?;
            if rows.is_empty() {
                // The end: the next pass starts over from the oldest.
                after = None;
                break;
            }
            for (id, source, retained_at) in rows {
                if !may_start() {
                    break 'pages;
                }
                if expire_retained_partial(verify, id, source.as_deref(), cutoff).await? {
                    released += 1;
                }
                after = Some((retained_at, id));
            }
        }
        Ok(())
    }
    .await;
    let _ = verify.cache.put(RETAINED_CURSOR, &after, DAY).await;
    result
}

/// Published candidates still retaining a partial's inventory with no new article since the cutoff.
/// `retained_last_unique_at` is set only on retaining rows, so its index reads just the expirable
/// ones, including those whose release was deleted meanwhile.
async fn retained_partials(
    verify: &Verify,
    cutoff: NaiveDateTime,
    after: Option<(NaiveDateTime, i64)>,
) -> Result<Vec<(i64, Option<String>, NaiveDateTime)>> {
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT id, source_identity, retained_last_unique_at FROM release_verification_candidates \
         FORCE INDEX (verification_retained_expiry) WHERE retained_last_unique_at < ",
    );
    query.push_bind(cutoff);
    if let Some((at, id)) = after {
        query.push(" AND (retained_last_unique_at > ").push_bind(at);
        query.push(" OR (retained_last_unique_at = ").push_bind(at);
        query.push(" AND id > ").push_bind(id).push("))");
    }
    query.push(" AND release_id IS NOT NULL AND manifest_path <> '' ORDER BY retained_last_unique_at, id LIMIT 100");
    let rows: Vec<(u64, Option<String>, NaiveDateTime)> =
        query.build_query_as().fetch_all(&verify.db).await?;
    Ok(rows
        .into_iter()
        .map(|(id, source, at)| (id as i64, source, at))
        .collect())
}

/// Releases one retained inventory unless its upload has late headers still waiting to be archived.
async fn expire_retained_partial(
    verify: &Verify,
    id: i64,
    source: Option<&str>,
    cutoff: NaiveDateTime,
) -> Result<bool> {
    let config = &verify.config;
    let done = in_tx!(verify.db, 5, |tx| {
        // Serialize with header storage and archive, in their order: late headers still waiting to be
        // archived keep the inventory. A collection added a whole expiry window ago and still
        // unarchived never will be, as when it exceeds the metadata budget, so it holds nothing back.
        if let Some(hash) = source.and_then(|source| hex::decode(source).ok()) {
            let waiting: Option<i32> = sqlx::query_scalar(
                "SELECT 1 FROM collections WHERE collectionhash = ? AND dateadded >= ? LIMIT 1 FOR UPDATE",
            )
            .bind(hash)
            .bind(cutoff)
            .fetch_optional(&mut *tx)
            .await?;
            if waiting.is_some() {
                return Ok::<Option<String>, anyhow::Error>(None);
            }
        }
        let row: Option<Candidate> =
            sqlx::query_as("SELECT * FROM release_verification_candidates WHERE id = ? FOR UPDATE")
                .bind(id)
                .fetch_optional(&mut *tx)
                .await?;
        let Some(row) = row else {
            return Ok(None);
        };
        let Some(release) = row.release_id else {
            return Ok(None);
        };
        if row.manifest_path.is_empty() || row.last_unique_at >= cutoff {
            return Ok(None);
        }
        sqlx::query(
            "UPDATE release_verification_candidates SET manifest_path = '', manifest_bytes = 0, \
             retained_for_release_id = NULL, retained_last_unique_at = NULL WHERE id = ?",
        )
        .bind(id)
        .execute(&mut *tx)
        .await?;
        // No late articles can merge any more: workers that read NZBs may now process it.
        release_late_article_fence(tx, &[release as i64], Some(id)).await?;
        ledger::adjust(tx, config, -row.manifest_bytes).await?;
        Ok(Some(row.manifest_path))
    })?;
    match done {
        Some(path) => {
            manifest::delete_if_unreferenced(&verify.db, config, &path).await?;
            Ok(true)
        }
        None => Ok(false),
    }
}

/// Clears a release's late-article candidate once that candidate can no longer upgrade it: it ended
/// unpublished (expired, filtered) or published elsewhere. The pointer only ever clears. Reads a
/// bounded page of the pointer index per pass, resuming where the last stopped, so live partials
/// never make it a table scan.
async fn settle_late_article_holders(verify: &Verify, deadline: Option<Instant>) -> Result<()> {
    let may_start = || deadline.is_none_or(|at| Instant::now() < at);
    let after: i64 = verify.cache.get(SETTLE_CURSOR).await.unwrap_or(0);
    let held: Vec<(u32, u64)> = sqlx::query_as(
        "SELECT id, late_article_candidate_id FROM releases FORCE INDEX (releases_late_article_candidate_id_index) \
         WHERE late_article_candidate_id > ? ORDER BY late_article_candidate_id LIMIT 1000",
    )
    .bind(after)
    .fetch_all(&verify.db)
    .await?;
    if held.is_empty() {
        let _ = verify.cache.put(SETTLE_CURSOR, &0i64, DAY).await;
        return Ok(());
    }
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT id, release_id, manifest_path, status FROM release_verification_candidates WHERE id IN (",
    );
    let mut separated = query.separated(",");
    for (_, candidate) in &held {
        separated.push_bind(*candidate);
    }
    query.push(")");
    let rows: Vec<(u64, Option<u64>, String, String)> =
        query.build_query_as().fetch_all(&verify.db).await?;
    let candidates: std::collections::HashMap<u64, (Option<u64>, String, String)> = rows
        .into_iter()
        .map(|(id, release, path, status)| (id, (release, path, status)))
        .collect();
    let mut checkpoint = after;
    let count = held.len();
    for (release, pointer) in held {
        if !may_start() {
            // Past the maintenance deadline: the next pass resumes after the last row settled here.
            let _ = verify.cache.put(SETTLE_CURSOR, &checkpoint, DAY).await;
            return Ok(());
        }
        // Settled once its holder ended unpublished, was published elsewhere, or released its inventory.
        let settled = match candidates.get(&pointer) {
            None => true,
            Some((Some(published), path, _)) => *published != u64::from(release) || path.is_empty(),
            Some((None, _, status)) => {
                matches!(status.as_str(), "expired" | "filtered" | "metadata_expired")
            }
        };
        if settled {
            sqlx::query(
                "UPDATE releases SET late_article_candidate_id = NULL WHERE id = ? AND late_article_candidate_id = ?",
            )
            .bind(release)
            .bind(pointer)
            .execute(&verify.db)
            .await?;
        }
        checkpoint = pointer as i64;
    }
    let next = if count < 1000 { 0 } else { checkpoint };
    let _ = verify.cache.put(SETTLE_CURSOR, &next, DAY).await;
    Ok(())
}
