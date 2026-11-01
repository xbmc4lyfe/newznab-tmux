//! Remembers collections whose headers exceeded the metadata budget, so archival stops reading every
//! part of them again on each pass. More articles only make an upload larger, so a retry can only
//! succeed once the budget is raised, which ends the deferral at once, or after the backoff, which
//! covers anything else that changed. Their headers stay in the ingestion tables meanwhile.
//! Bookkeeping failures never stop archival: a collection whose deferral can't be read or written is
//! simply tried again.

use std::collections::{HashMap, HashSet};
use std::time::Duration;

use anyhow::Result;
use chrono::{NaiveDateTime, TimeDelta};
use sqlx::{MySql, QueryBuilder};
use tracing::warn;

use super::archive::{MetadataBudgetExceeded, archive};
use super::now;
use crate::context::Verify;

const FIRST_BACKOFF_SECONDS: i64 = 21_600;
const MAX_BACKOFF_SECONDS: i64 = 604_800;
const PRUNE_LOCK: &str = "release-verification:oversized-prune";
const PRUNE_EVERY: Duration = Duration::from_secs(3600);
const PRUNE_ROWS: i64 = 1000;

/// A collection archival left in place.
#[derive(Debug)]
pub struct KeptCollection {
    /// Why archival failed; `None` when it's waiting out a deferral.
    pub error: Option<anyhow::Error>,
    pub retry_after: Option<String>,
}

fn logged<T>(result: Result<T>, fallback: T) -> T {
    match result {
        Ok(value) => value,
        Err(error) => {
            warn!(
                error = format!("{error:#}"),
                "over-budget collection bookkeeping failed; archival continues without it"
            );
            fallback
        }
    }
}

async fn current_hashes(verify: &Verify, ids: &[i64]) -> Result<HashMap<i64, Vec<u8>>> {
    let mut query =
        QueryBuilder::<MySql>::new("SELECT id, collectionhash FROM collections WHERE id IN (");
    let mut separated = query.separated(",");
    for id in ids {
        separated.push_bind(*id);
    }
    query.push(")");
    let rows: Vec<(u32, Vec<u8>)> = query.build_query_as().fetch_all(&verify.db).await?;
    Ok(rows
        .into_iter()
        .map(|(id, hash)| (i64::from(id), hash))
        .collect())
}

/// Whether a deferred collection is now eligible for archival because the budget was raised.
pub(super) async fn unlocked_by_raised_budget(verify: &Verify, id: i64, hash: &[u8]) -> bool {
    let row: Result<Option<(Vec<u8>, u64)>> = sqlx::query_as(
        "SELECT collectionhash, budget_bytes FROM release_verification_oversized_collections WHERE collection_id = ?",
    )
    .bind(id)
    .fetch_optional(&verify.db)
    .await
    .map_err(Into::into);
    logged(row, None).is_some_and(|(stored, budget)| {
        stored == hash && (budget as i64) < verify.config.metadata_budget()
    })
}

/// Ids whose deferral has ended because the budget was raised.
pub async fn unlocked_ids(verify: &Verify, ids: &[i64]) -> HashSet<i64> {
    logged(unlocked(verify, ids).await, HashSet::new())
}

async fn unlocked(verify: &Verify, ids: &[i64]) -> Result<HashSet<i64>> {
    let budget = verify.config.metadata_budget();
    let mut out = HashSet::new();
    for chunk in ids.chunks(500) {
        let current = current_hashes(verify, chunk).await?;
        for (id, hash, stored_budget) in records(verify, chunk).await? {
            if current.get(&id) == Some(&hash) && stored_budget < budget {
                out.insert(id);
            }
        }
    }
    Ok(out)
}

async fn records(verify: &Verify, ids: &[i64]) -> Result<Vec<(i64, Vec<u8>, i64)>> {
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT collection_id, collectionhash, budget_bytes FROM release_verification_oversized_collections WHERE collection_id IN (",
    );
    let mut separated = query.separated(",");
    for id in ids {
        separated.push_bind(*id);
    }
    query.push(")");
    let rows: Vec<(u64, Vec<u8>, u64)> = query.build_query_as().fetch_all(&verify.db).await?;
    Ok(rows
        .into_iter()
        .map(|(id, hash, budget)| (id as i64, hash, budget as i64))
        .collect())
}

/// Archives each collection unless it's waiting out an over-budget backoff at the current budget.
/// Returns the ids not archived: deferred (no error) or failed.
pub async fn archive_each(verify: &Verify, ids: &[i64]) -> HashMap<i64, KeptCollection> {
    let recorded = logged(lookup(verify, ids).await, HashMap::new());
    let mut kept = HashMap::new();
    for id in ids {
        if recorded.get(id).copied().unwrap_or(false) {
            kept.insert(
                *id,
                KeptCollection {
                    error: None,
                    retry_after: None,
                },
            );
            continue;
        }
        if let Err(error) = archive(verify, *id).await {
            let retry_after = if error.is::<MetadataBudgetExceeded>() {
                logged(record(verify, *id).await, None)
            } else {
                None
            };
            kept.insert(
                *id,
                KeptCollection {
                    error: Some(error),
                    retry_after,
                },
            );
        }
    }
    // A collection archive() left in place (another archiver held it, or it wasn't ready) keeps its record.
    let settled: Vec<i64> = recorded
        .keys()
        .copied()
        .filter(|id| !kept.contains_key(id))
        .collect();
    logged(drop_stale(verify, &settled).await, ());
    kept
}

/// Drops the records of collections deleted without archival.
pub async fn forget_deferrals(verify: &Verify, ids: &[i64]) {
    logged(drop_stale(verify, ids).await, ());
}

/// Whether each recorded collection is still deferred; ids without a record are absent.
async fn lookup(verify: &Verify, ids: &[i64]) -> Result<HashMap<i64, bool>> {
    if ids.is_empty() {
        return Ok(HashMap::new());
    }
    if verify.cache.add(PRUNE_LOCK, PRUNE_EVERY).await? {
        let oldest: Vec<u64> = sqlx::query_scalar(
            "SELECT collection_id FROM release_verification_oversized_collections ORDER BY collection_id LIMIT ?",
        )
        .bind(PRUNE_ROWS)
        .fetch_all(&verify.db)
        .await?;
        let oldest: Vec<i64> = oldest.into_iter().map(|id| id as i64).collect();
        drop_stale(verify, &oldest).await?;
    }
    let now = now();
    let budget = verify.config.metadata_budget();
    let mut found = HashMap::new();
    for chunk in ids.chunks(500) {
        let current = current_hashes(verify, chunk).await?;
        let mut query = QueryBuilder::<MySql>::new(
            "SELECT collection_id, collectionhash, budget_bytes, retry_after FROM release_verification_oversized_collections WHERE collection_id IN (",
        );
        let mut separated = query.separated(",");
        for id in chunk {
            separated.push_bind(*id);
        }
        query.push(")");
        let rows: Vec<(u64, Vec<u8>, u64, NaiveDateTime)> =
            query.build_query_as().fetch_all(&verify.db).await?;
        for (id, hash, stored_budget, retry_after) in rows {
            let id = id as i64;
            // A deferral left by a replaced collection under a reused id never holds back the new one.
            found.insert(
                id,
                current.get(&id) == Some(&hash)
                    && stored_budget as i64 >= budget
                    && retry_after > now,
            );
        }
    }
    Ok(found)
}

/// Records an over-budget failure, doubling the backoff on each repeat at the same budget.
async fn record(verify: &Verify, id: i64) -> Result<Option<String>> {
    let hash: Option<Vec<u8>> =
        sqlx::query_scalar("SELECT collectionhash FROM collections WHERE id = ?")
            .bind(id)
            .fetch_optional(&verify.db)
            .await?;
    let Some(hash) = hash else {
        return Ok(None);
    };
    let budget = verify.config.metadata_budget();
    let previous: Option<(Vec<u8>, u64, u32)> = sqlx::query_as(
        "SELECT collectionhash, budget_bytes, attempts FROM release_verification_oversized_collections WHERE collection_id = ?",
    )
    .bind(id)
    .fetch_optional(&verify.db)
    .await?;
    let attempts: u32 = match previous {
        Some((stored, stored_budget, attempts))
            if stored == hash && stored_budget as i64 == budget =>
        {
            attempts + 1
        }
        _ => 1,
    };
    let now = now();
    let backoff = FIRST_BACKOFF_SECONDS
        .saturating_mul(1 << (attempts - 1).min(10))
        .min(MAX_BACKOFF_SECONDS);
    let retry_after = now + TimeDelta::seconds(backoff);
    sqlx::query(
        "INSERT INTO release_verification_oversized_collections \
         (collection_id, collectionhash, budget_bytes, attempts, failed_at, retry_after) VALUES (?, ?, ?, ?, ?, ?) \
         ON DUPLICATE KEY UPDATE collectionhash = VALUES(collectionhash), budget_bytes = VALUES(budget_bytes), \
         attempts = VALUES(attempts), failed_at = VALUES(failed_at), retry_after = VALUES(retry_after)",
    )
    .bind(id)
    .bind(&hash)
    .bind(budget)
    .bind(attempts)
    .bind(now)
    .bind(retry_after)
    .execute(&verify.db)
    .await?;
    Ok(Some(retry_after.and_utc().to_rfc3339()))
}

/// Drops these ids' records whose collection is gone or was replaced: archived, deleted or reused.
/// A record for a collection that still holds its headers is kept, whoever last wrote it.
async fn drop_stale(verify: &Verify, ids: &[i64]) -> Result<()> {
    for chunk in ids.chunks(500) {
        let current = current_hashes(verify, chunk).await?;
        for (id, hash, _) in records(verify, chunk).await? {
            if current.get(&id) != Some(&hash) {
                sqlx::query(
                    "DELETE FROM release_verification_oversized_collections WHERE collection_id = ? AND collectionhash = ?",
                )
                .bind(id)
                .bind(&hash)
                .execute(&verify.db)
                .await?;
            }
        }
    }
    Ok(())
}
