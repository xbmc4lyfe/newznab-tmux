//! The candidate queue: uploads whose headers are archived as manifests until a worker verifies them.
//!
//! Lock order everywhere: an upload's collection, then its candidates in primary-key order, then
//! manifest rows, then the capacity ledger as the last lock request before commit.

mod admit;
mod archive;
mod deferrals;
mod expiry;
mod hot;
mod queue;

use anyhow::Result;
use chrono::{NaiveDateTime, TimeDelta, Utc};
use sqlx::{MySql, MySqlConnection, QueryBuilder};

use crate::config::VerificationConfig;

pub use admit::{AdmissionDeferred, CandidateAdmission, enqueue_with_admission};
pub use archive::{MetadataBudgetExceeded, archive};
pub use deferrals::{KeptCollection, archive_each, forget_deferrals, unlocked_ids};
pub use expiry::expire;
pub use hot::invalidate_hot_collections;
pub use queue::{
    Claim, FinishedAttempt, abandon_upgrade, claim, defer_partial, finish, heartbeat,
    partial_allowed, promote_partial, reject_by_policy, return_queued_claim,
};

pub(crate) const PARTIAL_QUEUED: &str =
    "Upload stopped growing before it was complete; queued for partial publication.";
pub(crate) const PARTIAL_DEFERRED: &str = "Partial publication deferred: ";

/// A `release_verification_candidates` row.
#[derive(Clone, Debug, sqlx::FromRow)]
pub struct Candidate {
    #[sqlx(try_from = "u64")]
    pub id: i64,
    pub identity: String,
    pub source_identity: Option<String>,
    #[sqlx(try_from = "u32")]
    pub groups_id: i64,
    #[sqlx(try_from = "u32")]
    pub version: i64,
    pub manifest_path: String,
    #[sqlx(try_from = "u64")]
    pub manifest_bytes: i64,
    pub manifest_hash: String,
    pub status: String,
    pub reason: Option<String>,
    pub last_unique_at: NaiveDateTime,
    pub next_attempt_at: NaiveDateTime,
    pub lease_token: Option<String>,
    pub lease_until: Option<NaiveDateTime>,
    pub release_id: Option<u64>,
    pub verified_at: Option<NaiveDateTime>,
    pub header_check_pending: bool,
    pub upgrades_release_id: Option<u64>,
    pub retained_for_release_id: Option<u64>,
    pub retained_last_unique_at: Option<NaiveDateTime>,
}

pub fn now() -> NaiveDateTime {
    Utc::now().naive_utc()
}

/// Uploads whose last new article is older than this are no longer owed a partial attempt.
pub(crate) fn owed_partial_cutoff(config: &VerificationConfig) -> NaiveDateTime {
    now() - TimeDelta::hours(config.expiry_hours + config.partial_after_hours.max(0))
}

/// `mb_strcut($value, 0, $bytes, 'UTF-8')`: the longest prefix within `bytes` that ends on a
/// character boundary.
pub fn cut(value: &str, bytes: usize) -> &str {
    if value.len() <= bytes {
        return value;
    }
    let mut end = bytes;
    while !value.is_char_boundary(end) {
        end -= 1;
    }
    &value[..end]
}

/// Releases these partials' fence held by `candidate`. Null releases whichever candidate holds it.
/// By primary key: an update through the pointer index would gap-lock it like a locking read.
pub async fn release_late_article_fence(
    conn: &mut MySqlConnection,
    release_ids: &[i64],
    candidate: Option<i64>,
) -> Result<()> {
    if release_ids.is_empty() {
        return Ok(());
    }
    let mut query = QueryBuilder::<MySql>::new(
        "UPDATE releases SET late_article_candidate_id = NULL WHERE id IN (",
    );
    let mut separated = query.separated(",");
    for id in release_ids {
        separated.push_bind(*id);
    }
    query.push(")");
    if let Some(candidate) = candidate {
        query
            .push(" AND late_article_candidate_id = ")
            .push_bind(candidate);
    }
    query.build().execute(&mut *conn).await?;
    Ok(())
}
