//! Claiming candidates, their leases, and how an attempt ends.

use anyhow::{Result, bail};
use chrono::{NaiveDateTime, TimeDelta};
use rand::seq::SliceRandom;
use sqlx::{Connection, MySql, MySqlConnection, QueryBuilder};
use tracing::debug;

use super::{
    Candidate, PARTIAL_DEFERRED, PARTIAL_QUEUED, cut, now, owed_partial_cutoff,
    release_late_article_fence,
};
use crate::context::Verify;
use crate::in_tx;
use crate::ledger;
use crate::locks::WorkerLocks;
use crate::manifest;

/// Window reads per claim while concurrent claimers keep winning the rows this worker tried.
const CLAIM_WINDOW_PASSES: usize = 10;
const WINDOW: i64 = 100;
const LEASE_LOST: &str = "Verification lease was lost.";
const STALE_FILTER: &str = "Stale verification result cannot filter a candidate.";

/// A candidate this worker holds the lease on. `candidate.status` is the status the row had before
/// claiming, which a queued lease returns to.
#[derive(Clone, Debug)]
pub struct Claim {
    pub candidate: Candidate,
    pub token: String,
}

impl Claim {
    pub fn id(&self) -> i64 {
        self.candidate.id
    }
}

/// Claims the next due candidate. `prefer_ready` keeps legacy and stale leases progressing even
/// while ready candidates keep arriving: callers pass false one turn in five.
///
/// Each attempt also takes the stable per-candidate lock on `locks`, so a candidate whose stale
/// lease expired under a still-running worker isn't processed twice.
pub async fn claim(
    verify: &Verify,
    locks: &mut WorkerLocks,
    prefer_ready: bool,
) -> Result<Option<Claim>> {
    let queues: [&[&str]; 2] = if prefer_ready {
        [&["ready"], &["pending", "unknown", "verifying"]]
    } else {
        [&["pending", "unknown", "verifying"], &["ready"]]
    };
    // Every worker reads the same oldest window. When concurrent claimers drained the rows this
    // worker tried, read it again rather than stop while eligible work remains due.
    for _ in 0..CLAIM_WINDOW_PASSES {
        let mut contended = false;
        for statuses in queues {
            let mut ids = claim_queue_ids(verify, statuses).await?;
            let mut spread = false;
            while !ids.is_empty() {
                let id = ids.remove(0);
                match try_claim(verify, locks, id).await? {
                    TryClaim::Claimed(claim) => return Ok(Some(*claim)),
                    TryClaim::Busy => {}
                    TryClaim::Gone => {
                        contended = true;
                        // Losers would otherwise advance through the window in lock-step behind the
                        // winner; spread them over the rest of it once they've lost a row.
                        if !spread {
                            ids.shuffle(&mut rand::rng());
                            spread = true;
                        }
                    }
                }
            }
        }
        if !contended {
            break;
        }
    }
    Ok(None)
}

enum TryClaim {
    Claimed(Box<Claim>),
    /// Eligible, but another worker holds its stable lock.
    Busy,
    /// No longer eligible, or locked by a concurrent claimer.
    Gone,
}

/// Locks the row, then takes the stable candidate lock, as an eligible row is the only one worth
/// locking. A lock this attempt newly took is given back unless the lease is written: one the worker
/// already held belongs to a claim still in flight.
async fn try_claim(verify: &Verify, locks: &mut WorkerLocks, id: i64) -> Result<TryClaim> {
    let lease_seconds = verify.config.lease_seconds;
    let mut newly_taken = false;
    let outcome = in_tx!(verify.db, 5, |tx| {
        let moment = now();
        let row: Option<Candidate> = sqlx::query_as(
            "SELECT * FROM release_verification_candidates FORCE INDEX (PRIMARY) WHERE id = ? \
             AND release_id IS NULL AND status IN ('ready', 'pending', 'unknown', 'verifying') \
             AND next_attempt_at <= ? AND (lease_until IS NULL OR lease_until < ?) FOR UPDATE SKIP LOCKED",
        )
        .bind(id)
        .bind(moment)
        .bind(moment)
        .fetch_optional(&mut *tx)
        .await?;
        let Some(candidate) = row else {
            return Ok::<TryClaim, anyhow::Error>(TryClaim::Gone);
        };
        let held = locks.holds_candidate(id);
        if !locks.try_candidate(id).await? {
            return Ok(TryClaim::Busy);
        }
        newly_taken = newly_taken || !held;
        let token = new_token();
        sqlx::query(
            "UPDATE release_verification_candidates SET status = 'verifying', lease_token = ?, lease_until = ? WHERE id = ?",
        )
        .bind(&token)
        .bind(moment + TimeDelta::seconds(lease_seconds))
        .bind(id)
        .execute(&mut *tx)
        .await?;
        Ok(TryClaim::Claimed(Box::new(Claim { candidate, token })))
    });
    if newly_taken && !matches!(outcome, Ok(TryClaim::Claimed(_))) {
        locks.release_candidate(Some(id)).await;
    }
    outcome
}

fn new_token() -> String {
    use rand::RngCore;
    let mut bytes = [0u8; 16];
    rand::rng().fill_bytes(&mut bytes);
    hex::encode(bytes)
}

/// Fixing the leading status key lets the `verification_due` index serve each order and limit.
async fn claim_queue_ids(verify: &Verify, statuses: &[&str]) -> Result<Vec<i64>> {
    let cutoff = now();
    let mut rows: Vec<(NaiveDateTime, i64)> = Vec::new();
    for status in statuses {
        let page: Vec<(u64, NaiveDateTime)> = sqlx::query_as(
            "SELECT id, next_attempt_at FROM release_verification_candidates WHERE release_id IS NULL AND status = ? \
             AND next_attempt_at <= ? AND (lease_until IS NULL OR lease_until < ?) \
             ORDER BY next_attempt_at, id LIMIT ?",
        )
        .bind(status)
        .bind(cutoff)
        .bind(cutoff)
        .bind(WINDOW)
        .fetch_all(&verify.db)
        .await?;
        for (id, at) in page {
            let id = id as i64;
            // A status change between reads must not take two places in the window.
            if !rows.iter().any(|(_, seen)| *seen == id) {
                rows.push((at, id));
            }
        }
    }
    rows.sort_unstable();
    rows.truncate(WINDOW as usize);
    Ok(rows.into_iter().map(|(_, id)| id).collect())
}

/// Returns an admitted candidate that never began verification, without changing a newer generation.
pub async fn return_queued_claim(verify: &Verify, claim: &Claim) -> Result<bool> {
    let status = if claim.candidate.status == "verifying" {
        "pending"
    } else {
        claim.candidate.status.as_str()
    };
    let changed = sqlx::query(
        "UPDATE release_verification_candidates SET status = ?, lease_token = NULL, lease_until = NULL \
         WHERE id = ? AND status = 'verifying' AND lease_token = ? AND version = ? AND release_id IS NULL",
    )
    .bind(status)
    .bind(claim.id())
    .bind(&claim.token)
    .bind(claim.candidate.version)
    .execute(&verify.db)
    .await?
    .rows_affected();
    Ok(changed == 1)
}

/// Renews a lease, failing when it was lost.
pub async fn heartbeat(
    executor: &mut MySqlConnection,
    lease_seconds: i64,
    id: i64,
    token: &str,
) -> Result<()> {
    let moment = now();
    let changed = sqlx::query(
        "UPDATE release_verification_candidates SET lease_until = ? WHERE id = ? AND lease_token = ? AND lease_until >= ?",
    )
    .bind(moment + TimeDelta::seconds(lease_seconds))
    .bind(id)
    .bind(token)
    .bind(moment)
    .execute(&mut *executor)
    .await?
    .rows_affected();
    if changed == 1 {
        return Ok(());
    }
    // An update within the same second changes no row.
    let held: Option<i32> = sqlx::query_scalar(
        "SELECT 1 FROM release_verification_candidates WHERE id = ? AND lease_token = ? AND lease_until >= ?",
    )
    .bind(id)
    .bind(token)
    .bind(moment)
    .fetch_optional(&mut *executor)
    .await?;
    if held.is_none() {
        bail!(LEASE_LOST);
    }
    Ok(())
}

/// How a verification attempt that didn't publish ended.
#[derive(Clone, Debug, Default)]
pub struct FinishedAttempt<'a> {
    pub reason: &'a str,
    pub waiting_for_headers: bool,
    pub header_rejected: bool,
    pub unrepairable: bool,
    /// Decided by the manifest and its fixed sample, not by transport: only new headers change it.
    pub deterministic: bool,
}

pub async fn finish(verify: &Verify, claim: &Claim, attempt: &FinishedAttempt<'_>) -> Result<()> {
    let status = if attempt.waiting_for_headers {
        "waiting_upload"
    } else if attempt.header_rejected {
        "header_rejected"
    } else if attempt.unrepairable {
        "unrepairable"
    } else {
        "unknown"
    };
    let retry = if attempt.deterministic {
        verify.config.deterministic_retry_seconds
    } else {
        verify.config.retry_seconds
    };
    sqlx::query(
        "UPDATE release_verification_candidates SET status = ?, reason = ?, lease_token = NULL, lease_until = NULL, \
         header_check_pending = 0, next_attempt_at = ? WHERE id = ? AND lease_token = ? AND version = ?",
    )
    .bind(status)
    .bind(cut(attempt.reason, 2000))
    .bind(now() + TimeDelta::seconds(retry))
    .bind(claim.id())
    .bind(&claim.token)
    .bind(claim.candidate.version)
    .execute(&verify.db)
    .await?;
    Ok(())
}

pub async fn reject_by_policy(verify: &Verify, claim: &Claim, reason: &str) -> Result<()> {
    retire(verify, claim, reason, "filtered").await
}

/// Ends an upgrade generation whose partial was deleted or no longer awaits it. It isn't filtered: a
/// filtered newest candidate would make archive discard later uploads under the same hash as replays.
pub async fn abandon_upgrade(verify: &Verify, claim: &Claim, reason: &str) -> Result<()> {
    retire(verify, claim, reason, "expired").await
}

async fn retire(verify: &Verify, claim: &Claim, reason: &str, status: &str) -> Result<()> {
    let config = &verify.config;
    let path = in_tx!(verify.db, 5, |tx| {
        retire_locked(tx, config, claim, reason, status).await
    })?;
    manifest::delete_if_unreferenced(&verify.db, config, &path).await
}

/// Retires a candidate inside the caller's transaction. Returns the manifest to delete after commit.
pub(crate) async fn retire_locked(
    tx: &mut MySqlConnection,
    config: &crate::config::VerificationConfig,
    claim: &Claim,
    reason: &str,
    status: &str,
) -> Result<String> {
    let candidate: Option<Candidate> = sqlx::query_as(
        "SELECT * FROM release_verification_candidates WHERE id = ? AND lease_token = ? AND version = ? \
         AND release_id IS NULL FOR UPDATE",
    )
    .bind(claim.id())
    .bind(&claim.token)
    .bind(claim.candidate.version)
    .fetch_optional(&mut *tx)
    .await?;
    let Some(candidate) = candidate else {
        bail!(STALE_FILTER);
    };
    sqlx::query(
        "UPDATE release_verification_candidates SET status = ?, reason = ?, lease_token = NULL, lease_until = NULL, \
         manifest_path = '', manifest_bytes = 0, upgrades_release_id = NULL WHERE id = ?",
    )
    .bind(status)
    .bind(cut(reason, 2000))
    .bind(claim.id())
    .execute(&mut *tx)
    .await?;
    if let Some(release) = candidate.upgrades_release_id {
        // A retired generation can no longer upgrade its partial: workers may process it now.
        release_late_article_fence(tx, &[release as i64], Some(candidate.id)).await?;
    }
    ledger::adjust(tx, config, -candidate.manifest_bytes).await?;
    Ok(candidate.manifest_path)
}

/// An upload whose missing files or articles have stopped arriving may publish as partial.
pub fn partial_allowed(verify: &Verify, candidate: &Candidate) -> bool {
    let hours = verify.config.partial_after_hours;
    hours > 0
        && candidate.release_id.is_none()
        && candidate.last_unique_at >= owed_partial_cutoff(&verify.config)
        && candidate.last_unique_at < now() - TimeDelta::hours(hours)
}

/// Queues uploads whose missing articles stopped arriving for partial publication. Uploads still
/// inside the hold window are deferred to its end, so each is read about once per window.
pub async fn promote_partial(verify: &Verify, limit: i64) -> Result<u64> {
    let config = &verify.config;
    let hours = config.partial_after_hours;
    if hours < 1 {
        return Ok(0);
    }
    // Only into idle capacity: a ready backlog is worked first. The due index bounds this count.
    let cap = config.partial_ready_limit.max(0);
    let ready: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM (SELECT id FROM release_verification_candidates WHERE status = 'ready' LIMIT ?) AS ready",
    )
    .bind(cap)
    .fetch_one(&verify.db)
    .await?;
    let limit = limit.min(cap - ready);
    if limit < 1 {
        return Ok(0);
    }
    let moment = now();
    let cutoff = moment - TimeDelta::hours(hours);
    let owed = owed_partial_cutoff(config);
    let later = moment + TimeDelta::hours(config.expiry_hours.max(1));
    let mut conn = verify.db.acquire().await?;
    // READ COMMITTED for this transaction only: the locking scan of the due range then takes no gap
    // locks, so admissions into the queue never wait behind promotion either.
    sqlx::query("SET TRANSACTION ISOLATION LEVEL READ COMMITTED")
        .execute(&mut *conn)
        .await?;
    let conn: &mut MySqlConnection = &mut conn;
    let mut tx = conn.begin().await?;
    // Skip rows ingestion holds rather than wait on them, and take the next due ones instead, so a
    // busy prefix never hides later stalled uploads. Each row's predicates hold under its lock.
    let rows: Vec<(u64, NaiveDateTime, Option<String>)> = sqlx::query_as(
        "SELECT id, last_unique_at, reason FROM release_verification_candidates WHERE status = 'waiting_upload' \
         AND next_attempt_at >= ? AND next_attempt_at <= ? AND last_unique_at >= ? AND release_id IS NULL \
         AND lease_token IS NULL ORDER BY next_attempt_at, id LIMIT ? FOR UPDATE SKIP LOCKED",
    )
    .bind(owed)
    .bind(moment)
    .bind(owed)
    .bind(limit.max(1))
    .fetch_all(&mut *tx)
    .await?;
    let mut eligible = Vec::new();
    for (id, last_unique_at, reason) in rows {
        if reason
            .as_deref()
            .unwrap_or("")
            .starts_with(PARTIAL_DEFERRED)
            || last_unique_at < owed
        {
            // Already tried and refused by policy, or past the partial-attempt window: only new
            // headers change that.
            set_next_attempt(&mut tx, id, later).await?;
        } else if last_unique_at < cutoff {
            eligible.push(id);
        } else {
            // Still inside the hold window: due again at its end.
            set_next_attempt(&mut tx, id, last_unique_at + TimeDelta::hours(hours)).await?;
        }
    }
    let mut promoted = 0;
    if !eligible.is_empty() {
        let mut query = QueryBuilder::<MySql>::new(
            "UPDATE release_verification_candidates SET status = 'ready', next_attempt_at = ",
        );
        query
            .push_bind(moment)
            .push(", reason = ")
            .push_bind(PARTIAL_QUEUED);
        query.push(" WHERE id IN (");
        let mut separated = query.separated(",");
        for id in &eligible {
            separated.push_bind(*id);
        }
        query.push(")");
        promoted = query.build().execute(&mut *tx).await?.rows_affected();
    }
    tx.commit().await?;
    debug!(promoted, "queued stalled uploads for partial publication");
    Ok(promoted)
}

async fn set_next_attempt(tx: &mut MySqlConnection, id: u64, at: NaiveDateTime) -> Result<()> {
    sqlx::query("UPDATE release_verification_candidates SET next_attempt_at = ? WHERE id = ?")
        .bind(at)
        .bind(id)
        .execute(&mut *tx)
        .await?;
    Ok(())
}

/// Returns a claimed partial upload that fails publication policy to waiting. Its policy outcome
/// depends only on its headers and configuration: retrying it each hold window would spot check it
/// again for the same result. New headers requeue it; expiry ends it.
pub async fn defer_partial(verify: &Verify, claim: &Claim, reason: &str) -> Result<()> {
    let full = format!("{PARTIAL_DEFERRED}{reason}");
    sqlx::query(
        "UPDATE release_verification_candidates SET status = 'waiting_upload', reason = ?, lease_token = NULL, \
         lease_until = NULL, next_attempt_at = ? WHERE id = ? AND lease_token = ? AND version = ? AND release_id IS NULL",
    )
    .bind(cut(&full, 2000))
    .bind(now() + TimeDelta::hours(verify.config.expiry_hours.max(1)))
    .bind(claim.id())
    .bind(&claim.token)
    .bind(claim.candidate.version)
    .execute(&verify.db)
    .await?;
    Ok(())
}
