//! Pending-capacity accounting and the locks that order verification writers.
//!
//! Manifests are rows protected by candidate and manifest row locks, so writers run concurrently:
//! each applies its capacity change as one conditional delta, its last lock request before commit,
//! and publications serialize only with each other on their own rows.

use anyhow::{Result, bail};
use deunicode::deunicode;
use sqlx::{MySqlConnection, MySqlExecutor};

use crate::config::VerificationConfig;

pub const PUBLICATION_LOCK: &str = "publication";
pub const PUBLICATION_STRIPES: u32 = 1024;

const UNINITIALIZED: &str = "Verification migration has not initialized capacity state.";

/// Keys a release's duplicate search looks up committed releases by: its article fingerprint,
/// predb id, searchname, name and exact size. Two publications that could find each other as
/// duplicates share at least one key. Names compare under a case- and accent-insensitive
/// collation, so their keys keep only lowercase ASCII letters and digits: a coarser key only
/// serializes more.
pub fn publication_keys(
    size: i64,
    fingerprint: Option<&str>,
    name: &str,
    search_name: &str,
    predb_id: i64,
) -> Vec<String> {
    let mut keys = Vec::with_capacity(5);
    if let Some(fingerprint) = fingerprint.filter(|value| !value.is_empty()) {
        keys.push(format!("fingerprint:{}", fingerprint.to_lowercase()));
    }
    if predb_id > 0 {
        keys.push(format!("predb:{predb_id}"));
    }
    if !search_name.is_empty() {
        keys.push(format!("name:{}", fold_name(search_name)));
    }
    if !name.is_empty() {
        keys.push(format!("name:{}", fold_name(name)));
    }
    if size > 0 {
        keys.push(format!("size:{size}"));
    }
    keys
}

fn fold_name(value: &str) -> String {
    deunicode(value)
        .to_lowercase()
        .chars()
        .filter(char::is_ascii_alphanumeric)
        .collect()
}

pub fn publication_stripe(key: &str) -> String {
    let hash = crc32fast::hash(key.as_bytes());
    format!("{PUBLICATION_LOCK}:{}", hash % PUBLICATION_STRIPES)
}

/// Serializes publications, and their duplicate detection, that share a duplicate-search key, but
/// not with admission or unrelated releases.
///
/// One statement locks all of a publication's stripes in primary-key order, so overlapping
/// publications can't deadlock. It then locks the shared `publication` row: in share mode, which
/// striped publications hold together, or exclusively when it has no keys or some stripes don't
/// exist yet, so such a publication waits for, and excludes, every other.
///
/// Only locking reads run here: the caller's first plain read, its duplicate search, must take its
/// read view after these locks are granted to see the releases committed while it waited.
pub async fn enter_publication(conn: &mut MySqlConnection, keys: &[String]) -> Result<()> {
    let mut stripes: Vec<String> = keys.iter().map(|key| publication_stripe(key)).collect();
    stripes.sort();
    stripes.dedup();
    let mut striped = false;
    if !stripes.is_empty() {
        let placeholders = vec!["?"; stripes.len()].join(",");
        let sql = format!(
            "SELECT name FROM release_verification_locks WHERE name IN ({placeholders}) ORDER BY name FOR UPDATE"
        );
        let mut query = sqlx::query_scalar::<_, String>(sqlx::AssertSqlSafe(sql));
        for stripe in &stripes {
            query = query.bind(stripe);
        }
        striped = query.fetch_all(&mut *conn).await?.len() == stripes.len();
    }
    let mode = if striped {
        "LOCK IN SHARE MODE"
    } else {
        "FOR UPDATE"
    };
    let sql = format!("SELECT name FROM release_verification_locks WHERE name = ? {mode}");
    let row: Option<String> = sqlx::query_scalar(sqlx::AssertSqlSafe(sql))
        .bind(PUBLICATION_LOCK)
        .fetch_optional(&mut *conn)
        .await?;
    if row.is_none() {
        bail!("Verification publication lock has not been initialized.");
    }
    Ok(())
}

/// Advisory, non-locking capacity check that lets a full ledger fail before expensive work.
pub async fn fits(
    executor: impl MySqlExecutor<'_>,
    config: &VerificationConfig,
    delta: i64,
) -> Result<bool> {
    if delta <= 0 {
        return Ok(true);
    }
    let pending: Option<u64> =
        sqlx::query_scalar("SELECT pending_bytes FROM release_verification_state WHERE id = 1")
            .fetch_optional(executor)
            .await?;
    let Some(pending) = pending else {
        bail!(UNINITIALIZED);
    };
    Ok(pending as i64 + delta <= config.pending_bytes)
}

/// Applies a pending-bytes delta, refusing growth that would exceed capacity.
///
/// The capacity row stays locked until commit, so callers issue this as their last lock request:
/// afterwards they may write only rows their transaction already holds.
pub async fn adjust(
    conn: &mut MySqlConnection,
    config: &VerificationConfig,
    delta: i64,
) -> Result<bool> {
    if delta == 0 {
        return Ok(true);
    }
    let changed = if delta > 0 {
        sqlx::query(
            "UPDATE release_verification_state SET pending_bytes = pending_bytes + ? \
             WHERE id = 1 AND pending_bytes + ? <= ?",
        )
        .bind(delta)
        .bind(delta)
        .bind(config.pending_bytes)
        .execute(&mut *conn)
        .await?
        .rows_affected()
    } else {
        // A release never drives the unsigned column below zero.
        sqlx::query(
            "UPDATE release_verification_state \
             SET pending_bytes = IF(pending_bytes < ?, 0, pending_bytes - ?) WHERE id = 1",
        )
        .bind(-delta)
        .bind(-delta)
        .execute(&mut *conn)
        .await?
        .rows_affected()
    };
    if changed == 1 {
        return Ok(true);
    }
    let exists: Option<i32> =
        sqlx::query_scalar("SELECT 1 FROM release_verification_state WHERE id = 1")
            .fetch_optional(&mut *conn)
            .await?;
    if exists.is_none() {
        bail!(UNINITIALIZED);
    }
    Ok(false)
}

/// Whether admissions should still take new work: full capacity less a small reserve.
pub async fn capacity_available(
    executor: impl MySqlExecutor<'_>,
    config: &VerificationConfig,
) -> Result<bool> {
    let pending: Option<u64> =
        sqlx::query_scalar("SELECT pending_bytes FROM release_verification_state WHERE id = 1")
            .fetch_optional(executor)
            .await?;
    let capacity = config.pending_bytes;
    let reserve = 33_554_432.min(capacity / 10);
    Ok((pending.unwrap_or(0) as i64) < capacity - reserve)
}
