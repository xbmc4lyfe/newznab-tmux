//! Deleting collections, their binaries and their parts, and the retention sweeps.
//!
//! Every release child runs the sweeps, which are global, so a non-blocking cache lock keeps each to
//! one worker at a time and the others skip it. Each walks bounded pages from a stored cursor, so a
//! large backlog can't stall a cycle.

use std::collections::HashSet;
use std::time::{Duration, Instant};

use anyhow::Result;
use chrono::{NaiveDateTime, TimeDelta, Utc};
use serde::{Deserialize, Serialize};
use sqlx::{MySql, MySqlConnection, MySqlPool, QueryBuilder};
use tracing::warn;

use crate::candidates;
use crate::context::Verify;
use crate::in_tx;
use crate::txn::{is_lock_error, lock_backoff};

pub const STATUS_DEFAULT: i8 = 0;
pub const STATUS_COMPLETE_COLLECTION: i8 = 1;
pub const STATUS_COMPLETE_PARTS: i8 = 2;
pub const STATUS_SIZED: i8 = 3;
pub const STATUS_INSERTED: i8 = 4;
pub const STATUS_TEMP_COMPLETE: i8 = 15;
pub const STATUS_ZERO_PART: i8 = 16;
/// A legacy status the reconciliation walk still revisits.
pub const STATUS_LEGACY: i8 = 10;

/// Statuses verification still owes an attempt.
const VERIFICATION_STATUSES: [i8; 7] = [
    STATUS_DEFAULT,
    STATUS_COMPLETE_COLLECTION,
    STATUS_COMPLETE_PARTS,
    STATUS_TEMP_COMPLETE,
    STATUS_ZERO_PART,
    STATUS_SIZED,
    STATUS_LEGACY,
];

/// Retrievable payload is preserved even when header counts or aggregate sizes are incomplete.
/// Terminal rows, and rows with no usable articles, don't need a download attempt.
pub const ELIGIBLE: &str = "(collections.filecheck IN (0, 1, 2, 15, 16, 3, 10) OR (collections.filecheck = 4 AND EXISTS \
    (SELECT 1 FROM releases AS legacy_release WHERE legacy_release.id = collections.releases_id \
    AND legacy_release.nzbstatus = 0))) AND EXISTS (SELECT 1 FROM binaries AS b \
    INNER JOIN parts AS p ON p.binaries_id = b.id WHERE b.collections_id = collections.id \
    AND p.size > 0 AND p.messageid <> '')";

/// Eligible collections whose size is known, or that a legacy release row already holds.
pub const READY: &str = "collections.filecheck IN (3, 4)";

const ORPHAN_SWEEP_LOCK: &str = "cbp:orphan-sweep:lock";
const ORPHAN_SWEEP_CURSOR: &str = "cbp:orphan-sweep:cursor";
const OLD_SWEEP_LOCK: &str = "cbp:verification-old-sweep:lock";
const OLD_SWEEP_CURSOR: &str = "cbp:verification-old-sweep:cursor";
const OLD_SWEEP_MAX_CANDIDATES: usize = 25;
/// A crashed sweeper releases the sweep after this long.
const SWEEP_LOCK: Duration = Duration::from_secs(600);
/// Hard cap on delete batches per sweep, so a dense window stays bounded.
const ORPHAN_SWEEP_MAX_BATCHES: usize = 20;
const LOCK_RETRY_MAX: u32 = 5;

/// Cleanup limits from `config/nntmux.php`'s `cbp` section.
#[derive(Clone, Copy, Debug)]
pub struct Cleanup {
    pub sql_chunk_size: usize,
    pub orphan_scan_window: i64,
    pub verification_sweep_seconds: u64,
    pub part_retention_hours: i64,
}

impl Cleanup {
    pub fn from_env(part_retention_hours: i64) -> Result<Self> {
        use nntmux_core::parsed_or;
        Ok(Self {
            sql_chunk_size: parsed_or::<i64>("CBP_SQL_CHUNK_SIZE", 500)?.clamp(50, 1000) as usize,
            orphan_scan_window: parsed_or::<i64>("CBP_ORPHAN_SCAN_WINDOW", 250_000)?.max(1000),
            verification_sweep_seconds: parsed_or::<i64>("CBP_VERIFICATION_SWEEP_SECONDS", 60)?
                .clamp(0, 120) as u64,
            part_retention_hours,
        })
    }
}

/// Deletes the given collections; binaries, parts and cross-post rows follow through their cascades.
/// The caller holds the transaction.
pub async fn delete_rows(conn: &mut MySqlConnection, ids: &[i64]) -> Result<u64> {
    if ids.is_empty() {
        return Ok(0);
    }
    let mut query = QueryBuilder::<MySql>::new("DELETE FROM collections WHERE id IN (");
    let mut separated = query.separated(",");
    for id in ids {
        separated.push_bind(*id);
    }
    query.push(")");
    Ok(query.build().execute(&mut *conn).await?.rows_affected())
}

/// Locks parent rows before checking descendants, matching header storage's lock order. Explicit
/// locking reads see newly committed parts even within an older read view.
async fn deletable_verification_ids(conn: &mut MySqlConnection, ids: &[i64]) -> Result<Vec<i64>> {
    if ids.is_empty() {
        return Ok(Vec::new());
    }
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT id, filecheck, releases_id FROM collections FORCE INDEX (PRIMARY) WHERE id IN (",
    );
    let mut separated = query.separated(",");
    for id in ids {
        separated.push_bind(*id);
    }
    query.push(") ORDER BY id FOR UPDATE");
    let parents: Vec<(u32, i8, Option<i32>)> = query.build_query_as().fetch_all(&mut *conn).await?;
    let mut deletable = Vec::with_capacity(parents.len());
    for (id, filecheck, release_id) in parents {
        let mut needs_verification = VERIFICATION_STATUSES.contains(&filecheck);
        if !needs_verification && filecheck == STATUS_INSERTED {
            let unpublished: Option<u32> = sqlx::query_scalar(
                "SELECT id FROM releases WHERE id = ? AND nzbstatus = 0 FOR UPDATE",
            )
            .bind(release_id)
            .fetch_optional(&mut *conn)
            .await?;
            needs_verification = unpublished.is_some();
        }
        if needs_verification {
            let usable: Option<u64> = sqlx::query_scalar(
                "SELECT b.id FROM binaries AS b INNER JOIN parts AS p ON p.binaries_id = b.id \
                 WHERE b.collections_id = ? AND p.size > 0 AND p.messageid <> '' ORDER BY b.id LIMIT 1 FOR UPDATE",
            )
            .bind(id)
            .fetch_optional(&mut *conn)
            .await?;
            if usable.is_some() {
                continue;
            }
        }
        deletable.push(i64::from(id));
    }
    Ok(deletable)
}

/// Deletes collections no verification attempt is owed, one chunk per transaction. Lock contention
/// is retried; when retries run out this chunk is left for a later pass.
pub async fn delete_collections_and_descendants(
    pool: &MySqlPool,
    cleanup: &Cleanup,
    ids: &[i64],
    label: &str,
) -> Result<u64> {
    let mut deleted = 0;
    for chunk in ids.chunks(cleanup.sql_chunk_size) {
        deleted += retry_on_lock_error(label, async || {
            in_tx!(pool, 1, |tx| {
                let deletable = deletable_verification_ids(tx, chunk).await?;
                delete_rows(tx, &deletable).await
            })
        })
        .await?;
    }
    Ok(deleted)
}

/// Runs a write in a bounded retry loop that only swallows transient InnoDB lock errors. Anything
/// else is returned so real failures aren't silently retried. Exhausted retries report 0 rows.
async fn retry_on_lock_error<F>(label: &str, mut op: F) -> Result<u64>
where
    F: AsyncFnMut() -> Result<u64>,
{
    let mut attempt = 0;
    loop {
        match op().await {
            Ok(rows) => return Ok(rows),
            Err(error) if is_lock_error(&error) => {
                attempt += 1;
                if attempt >= LOCK_RETRY_MAX {
                    warn!(
                        label,
                        error = format!("{error:#}"),
                        "delete failed after retries"
                    );
                    return Ok(0);
                }
                tokio::time::sleep(lock_backoff(attempt)).await;
            }
            Err(error) => return Err(error),
        }
    }
}

#[derive(Clone, Copy, Debug, Serialize, Deserialize)]
struct IdWindow {
    last_id: i64,
    max_id: i64,
}

/// Deletes old collections, orphans, and collections missed after their NZB. Returns rows deleted.
pub async fn delete_finished_and_orphans(verify: &Verify, cleanup: &Cleanup) -> Result<u64> {
    let cutoff = Utc::now().naive_utc() - TimeDelta::hours(cleanup.part_retention_hours);
    let mut deleted = delete_old_verification_window(verify, cleanup, cutoff).await?;
    deleted += delete_orphan_collections(verify, cleanup).await?;
    deleted += delete_collections_missed_after_nzb(verify, cleanup).await?;
    Ok(deleted)
}

/// Archives stale windows, keeping failed inputs until the snapshot wraps.
///
/// Each pass hands at most a small number of collections to archival, which reads every header.
/// Stale collections whose archival would only record an already expired candidate are deleted
/// directly instead, so a pass keeps paging through such windows until its time budget runs out.
async fn delete_old_verification_window(
    verify: &Verify,
    cleanup: &Cleanup,
    cutoff: NaiveDateTime,
) -> Result<u64> {
    let Some(lock) = verify.cache.lock(OLD_SWEEP_LOCK, SWEEP_LOCK).await? else {
        return Ok(0);
    };
    let result = sweep_old(verify, cleanup, cutoff).await;
    verify.cache.release(lock).await?;
    result
}

async fn sweep_old(verify: &Verify, cleanup: &Cleanup, cutoff: NaiveDateTime) -> Result<u64> {
    let saved: Option<IdWindow> = verify.cache.get(OLD_SWEEP_CURSOR).await;
    let (mut last_id, max_id) = match saved {
        Some(window) if window.last_id >= 0 && window.max_id > window.last_id => {
            (window.last_id, window.max_id)
        }
        _ => {
            let max: Option<u32> = sqlx::query_scalar("SELECT MAX(id) FROM collections")
                .fetch_one(&verify.db)
                .await?;
            (0, i64::from(max.unwrap_or(0)))
        }
    };
    let mut archive_budget = OLD_SWEEP_MAX_CANDIDATES.min(cleanup.sql_chunk_size);
    let deadline = Instant::now() + Duration::from_secs(cleanup.verification_sweep_seconds);
    let mut deleted = 0;
    loop {
        let Some(page) =
            sweep_old_page(verify, cleanup, cutoff, last_id, max_id, archive_budget).await?
        else {
            verify.cache.forget(OLD_SWEEP_CURSOR).await?;
            return Ok(deleted);
        };
        last_id = page.last_id;
        deleted += page.deleted;
        archive_budget -= page.archived;
        if page.exhausted || last_id >= max_id {
            verify.cache.forget(OLD_SWEEP_CURSOR).await?;
            return Ok(deleted);
        }
        verify
            .cache
            .forever(OLD_SWEEP_CURSOR, &IdWindow { last_id, max_id })
            .await?;
        if archive_budget == 0 || Instant::now() >= deadline {
            return Ok(deleted);
        }
    }
}

struct SweepPage {
    last_id: i64,
    archived: usize,
    deleted: u64,
    exhausted: bool,
}

/// Processes one page after `last_id`: archives at most `budget` collections that still need
/// verification, deletes those whose archival could only expire, and plain-deletes the rest. The
/// page ends at the last collection archival was attempted for, so none owed archival is skipped.
async fn sweep_old_page(
    verify: &Verify,
    cleanup: &Cleanup,
    cutoff: NaiveDateTime,
    last_id: i64,
    max_id: i64,
    budget: usize,
) -> Result<Option<SweepPage>> {
    let limit = cleanup.sql_chunk_size;
    let mut ids: Vec<i64> = sqlx::query_scalar::<_, u32>(
        "SELECT id FROM collections WHERE dateadded < ? AND id > ? AND id <= ? ORDER BY id LIMIT ?",
    )
    .bind(cutoff)
    .bind(last_id)
    .bind(max_id)
    .bind(limit as i64)
    .fetch_all(&verify.db)
    .await?
    .into_iter()
    .map(i64::from)
    .collect();
    if ids.is_empty() {
        return Ok(None);
    }
    let mut exhausted = ids.len() < limit;
    let eligible = eligible_among(&verify.db, &ids).await?;
    let expired: HashSet<i64> = expired_on_archival(
        &verify.db,
        verify,
        &eligible.iter().copied().collect::<Vec<_>>(),
    )
    .await?
    .into_iter()
    .collect();
    let mut archive = Vec::new();
    let mut cut = None;
    for (index, id) in ids.iter().enumerate() {
        if !eligible.contains(id) || expired.contains(id) {
            continue;
        }
        if archive.len() == budget {
            cut = Some(index);
            exhausted = false;
            break;
        }
        archive.push(*id);
    }
    if let Some(index) = cut {
        ids.truncate(index);
    }
    // Deferred over-budget uploads keep their headers, like failed ones, without being read again.
    let kept = candidates::archive_each(verify, &archive).await;
    for (id, outcome) in &kept {
        if let Some(error) = &outcome.error {
            warn!(
                collection_id = id,
                error = format!("{error:#}"),
                retry_after = outcome.retry_after.as_deref(),
                "verification archival failed during stale cleanup"
            );
        }
    }
    let expired_ids: Vec<i64> = ids
        .iter()
        .copied()
        .filter(|id| expired.contains(id))
        .collect();
    let plain: Vec<i64> = ids
        .iter()
        .copied()
        .filter(|id| !expired.contains(id) && !kept.contains_key(id))
        .collect();
    let mut deleted = delete_expired_on_archival(verify, cleanup, &expired_ids).await?;
    deleted += delete_collections_and_descendants(&verify.db, cleanup, &plain, "Cleanup").await?;
    // The deferral table has no foreign key, and these deletions bypass archive_each's own cleanup.
    let mut forgotten = expired_ids;
    forgotten.extend(plain);
    candidates::forget_deferrals(verify, &forgotten).await;
    let last = ids.last().copied().unwrap_or(last_id);
    Ok(Some(SweepPage {
        last_id: last,
        archived: archive.len(),
        deleted,
        exhausted,
    }))
}

async fn eligible_among(pool: &MySqlPool, ids: &[i64]) -> Result<HashSet<i64>> {
    if ids.is_empty() {
        return Ok(HashSet::new());
    }
    let mut query = QueryBuilder::<MySql>::new("SELECT id FROM collections WHERE id IN (");
    let mut separated = query.separated(",");
    for id in ids {
        separated.push_bind(*id);
    }
    query.push(") AND ").push(ELIGIBLE);
    let rows: Vec<(u32,)> = query.build_query_as().fetch_all(pool).await?;
    Ok(rows.into_iter().map(|(id,)| i64::from(id)).collect())
}

#[derive(sqlx::FromRow)]
struct ExpiryRow {
    #[sqlx(try_from = "u32")]
    id: i64,
    filecheck: i8,
    collectionhash: Vec<u8>,
    last_seen_at: Option<NaiveDateTime>,
    dateadded: Option<NaiveDateTime>,
    added: Option<NaiveDateTime>,
}

const EXPIRY_COLUMNS: &str =
    "id, filecheck, collectionhash, last_seen_at, dateadded, CAST(added AS DATETIME) AS added";

/// Archival records a collection whose newest article is older than the candidate expiry, and that
/// has no candidate to join, as an expired candidate holding no headers and then deletes it.
/// Collection hashes are unique, so only this collection's own archival could create that candidate.
/// Legacy inserted rows keep the full archival path for their release handoff.
async fn expired_on_archival(pool: &MySqlPool, verify: &Verify, ids: &[i64]) -> Result<Vec<i64>> {
    if ids.is_empty() {
        return Ok(Vec::new());
    }
    let mut query = QueryBuilder::<MySql>::new(format!(
        "SELECT {EXPIRY_COLUMNS} FROM collections WHERE id IN ("
    ));
    let mut separated = query.separated(",");
    for id in ids {
        separated.push_bind(*id);
    }
    query.push(") ORDER BY id");
    let rows: Vec<ExpiryRow> = query.build_query_as().fetch_all(pool).await?;
    let unlocked = candidates::unlocked_ids(verify, ids).await;
    let sources = expiry_sources(verify, rows, &unlocked);
    let known = known_sources(pool, &sources).await?;
    let mut expired: Vec<i64> = sources
        .into_iter()
        .filter(|(source, _)| !known.contains(source))
        .map(|(_, id)| id)
        .collect();
    expired.sort_unstable();
    Ok(expired)
}

fn expiry_sources(
    verify: &Verify,
    rows: Vec<ExpiryRow>,
    unlocked: &HashSet<i64>,
) -> Vec<(String, i64)> {
    let expires_before = Utc::now().naive_utc() - TimeDelta::hours(verify.config.expiry_hours);
    rows.into_iter()
        .filter(|row| {
            let arrival = row.last_seen_at.or(row.dateadded).or(row.added);
            !unlocked.contains(&row.id)
                && row.filecheck != STATUS_INSERTED
                && arrival.is_some_and(|at| at < expires_before)
        })
        .map(|row| (hex::encode(&row.collectionhash), row.id))
        .collect()
}

async fn known_sources(
    executor: impl sqlx::MySqlExecutor<'_>,
    sources: &[(String, i64)],
) -> Result<HashSet<String>> {
    if sources.is_empty() {
        return Ok(HashSet::new());
    }
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT DISTINCT source_identity FROM release_verification_candidates WHERE source_identity IN (",
    );
    let mut separated = query.separated(",");
    for (source, _) in sources {
        separated.push_bind(source);
    }
    query.push(")");
    let rows: Vec<(String,)> = query.build_query_as().fetch_all(executor).await?;
    Ok(rows.into_iter().map(|(source,)| source).collect())
}

/// Deletes collections whose archival would only expire, rechecking each under its row lock: header
/// storage locks the parent first, so a new article either landed before the recheck or waits for it.
async fn delete_expired_on_archival(
    verify: &Verify,
    cleanup: &Cleanup,
    ids: &[i64],
) -> Result<u64> {
    let mut deleted = 0;
    for chunk in ids.chunks(cleanup.sql_chunk_size) {
        let mut sorted = chunk.to_vec();
        sorted.sort_unstable();
        let unlocked = candidates::unlocked_ids(verify, &sorted).await;
        deleted += retry_on_lock_error("Expired stale cleanup", async || {
            in_tx!(verify.db, 1, |tx| {
                let mut query = QueryBuilder::<MySql>::new(format!(
                    "SELECT {EXPIRY_COLUMNS} FROM collections FORCE INDEX (PRIMARY) WHERE id IN ("
                ));
                let mut separated = query.separated(",");
                for id in &sorted {
                    separated.push_bind(*id);
                }
                query.push(") ORDER BY id FOR UPDATE");
                let rows: Vec<ExpiryRow> = query.build_query_as().fetch_all(&mut *tx).await?;
                let sources = expiry_sources(verify, rows, &unlocked);
                let known = known_sources(&mut *tx, &sources).await?;
                let expired: Vec<i64> = sources
                    .into_iter()
                    .filter(|(source, _)| !known.contains(source))
                    .map(|(_, id)| id)
                    .collect();
                delete_rows(tx, &expired).await
            })
        })
        .await?;
    }
    Ok(deleted)
}

/// Deletes collections with no binaries, a bounded window per sweep. A `NOT EXISTS` scan from the
/// lowest id would have to probe `binaries` for every collection before returning when there are few
/// orphans. A plain `NOT EXISTS` read followed by a single-table delete takes no cross-table locks,
/// which could deadlock against concurrent header writes.
async fn delete_orphan_collections(verify: &Verify, cleanup: &Cleanup) -> Result<u64> {
    let Some(lock) = verify.cache.lock(ORPHAN_SWEEP_LOCK, SWEEP_LOCK).await? else {
        return Ok(0);
    };
    let result = sweep_orphan_window(verify, cleanup).await;
    verify.cache.release(lock).await?;
    result
}

async fn sweep_orphan_window(verify: &Verify, cleanup: &Cleanup) -> Result<u64> {
    let max: Option<u32> = sqlx::query_scalar("SELECT MAX(id) FROM collections")
        .fetch_one(&verify.db)
        .await?;
    let max_id = i64::from(max.unwrap_or(0));
    if max_id == 0 {
        verify.cache.forget(ORPHAN_SWEEP_CURSOR).await?;
        return Ok(0);
    }
    let mut cursor: i64 = verify.cache.get(ORPHAN_SWEEP_CURSOR).await.unwrap_or(0);
    if cursor >= max_id {
        cursor = 0;
    }
    let window_end = (cursor + cleanup.orphan_scan_window).min(max_id);
    let mut deleted = 0;
    for _ in 0..ORPHAN_SWEEP_MAX_BATCHES {
        let ids: Vec<i64> = sqlx::query_scalar::<_, u32>(
            "SELECT c.id FROM collections AS c WHERE c.id > ? AND c.id <= ? AND NOT EXISTS \
             (SELECT 1 FROM binaries AS b WHERE b.collections_id = c.id) ORDER BY c.id LIMIT ?",
        )
        .bind(cursor)
        .bind(window_end)
        .bind(cleanup.sql_chunk_size as i64)
        .fetch_all(&verify.db)
        .await?
        .into_iter()
        .map(i64::from)
        .collect();
        if ids.is_empty() {
            cursor = window_end;
            break;
        }
        deleted +=
            delete_collections_and_descendants(&verify.db, cleanup, &ids, "Orphan cleanup").await?;
        if ids.len() < cleanup.sql_chunk_size {
            cursor = window_end;
            break;
        }
        cursor = ids[ids.len() - 1];
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
    let stored = if cursor >= max_id { 0 } else { cursor };
    verify.cache.forever(ORPHAN_SWEEP_CURSOR, &stored).await?;
    Ok(deleted)
}

/// Deletes collections whose release already has its NZB, in two phases per batch: a non-locking
/// read of a few ids, then a single-table delete. The delete never references `releases`, so
/// concurrent NZB installs can't form a cross-table lock cycle with it. Driving from the few
/// collections linked to a release keeps the optimizer from starting at every published release.
async fn delete_collections_missed_after_nzb(verify: &Verify, cleanup: &Cleanup) -> Result<u64> {
    let sql = format!(
        "SELECT c.id FROM collections AS c WHERE c.releases_id IS NOT NULL \
         AND NOT EXISTS (SELECT 1 FROM collections WHERE collections.id = c.id AND {ELIGIBLE}) \
         AND EXISTS (SELECT 1 FROM releases AS r WHERE r.id = c.releases_id AND r.nzbstatus = 1) \
         ORDER BY c.id LIMIT ?"
    );
    let mut deleted = 0;
    for _ in 0..20 {
        let ids: Vec<i64> = sqlx::query_scalar::<_, u32>(sqlx::AssertSqlSafe(sql.clone()))
            .bind(cleanup.sql_chunk_size as i64)
            .fetch_all(&verify.db)
            .await?
            .into_iter()
            .map(i64::from)
            .collect();
        if ids.is_empty() {
            break;
        }
        let affected =
            delete_collections_and_descendants(&verify.db, cleanup, &ids, "Missed-NZB cleanup")
                .await?;
        deleted += affected;
        if affected < cleanup.sql_chunk_size as u64 {
            break;
        }
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
    Ok(deleted)
}
