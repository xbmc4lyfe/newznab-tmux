//! `NzbContentsService::checkPar2Result`: tries a release's PAR2 candidates, a few articles at
//! a time, and says whether the release was renamed, can never be renamed this way, or must be
//! asked again.
//!
//! Public and independent of the `fix-names` command, because post-processing asks the same
//! question. The release's `proc_par2` is set only when every candidate has been read to the
//! end (or provably cannot describe the release); a retryable failure, such as an NNTP outage,
//! never marks a release as processed.

use std::sync::Arc;
use std::time::Instant;

use anyhow::{Result, bail};
use redis::aio::ConnectionManager;
use sqlx::MySqlPool;

use crate::par2_apply::Expected;
use crate::par2_candidates::{Candidate, Class, Plan, parse_nzb, plan};
use crate::par2_lock::{LockClaim, RedisLock, Refresh};
use crate::par2_progress;
use crate::par2_recovery::{ArticleSource, Recovery, Request, recover};
use crate::par2_support::{Claim, Guard, LockedRelease, aborted, absorb_abort};
use crate::queries::FixNamesConfig;
use crate::release::Snapshot;
use crate::service::{NameFixer, Par2Outcome};
use crate::update::ReleaseSearch;

/// Environment-derived settings the PHP read through `config()`.
#[derive(Clone, Debug)]
pub struct Par2Settings {
    /// `ADD_PAR2`: record the named files as the release's files.
    pub add_par2: bool,
    /// `NNTP_ALTERNATE_FALLBACK`, falling back to `USE_ALTERNATE_NNTP_SERVER`.
    pub alternate: bool,
    /// The most a check keeps its release lock without renewal.
    pub lease_seconds: u64,
    /// Prepended to every Redis key a check or sweep takes, so tests (and deployments that
    /// share a Redis) don't collide. Empty by default.
    pub key_prefix: String,
}

/// Laravel's `env()` followed by a `(bool)` cast.
pub(crate) fn env_bool(name: &str) -> Option<bool> {
    let value = std::env::var(name).ok()?;
    let value = value.trim();
    Some(match value.to_ascii_lowercase().as_str() {
        "true" | "(true)" => true,
        "false" | "(false)" | "empty" | "(empty)" | "null" | "(null)" | "" | "0" => false,
        _ => true,
    })
}

impl Par2Settings {
    pub fn from_env() -> Self {
        let seconds = |name: &str| {
            std::env::var(name)
                .ok()
                .and_then(|v| v.trim().parse::<u64>().ok())
                .unwrap_or(120)
        };
        let alternate = match std::env::var("NNTP_ALTERNATE_FALLBACK") {
            Ok(value) if !value.trim().is_empty() => env_bool("NNTP_ALTERNATE_FALLBACK"),
            _ => env_bool("USE_ALTERNATE_NNTP_SERVER"),
        };
        Self {
            add_par2: env_bool("ADD_PAR2").unwrap_or(false),
            alternate: alternate.unwrap_or(false),
            lease_seconds: 600
                .max(10 * seconds("NNTP_SOCKET_TIMEOUT").max(seconds("NNTP_SOCKET_TIMEOUT_A"))),
            key_prefix: std::env::var("NNTMUX_REDIS_PREFIX").unwrap_or_default(),
        }
    }
}

/// What a check cost, for the sweep's accounting.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Par2Stats {
    pub candidates_probed: i64,
    pub articles_received: i64,
}

/// Everything a check needs from outside the release. One per worker; checks run one at a time.
pub struct Par2Checker {
    pub fixer: NameFixer,
    pub search: Arc<dyn ReleaseSearch>,
    pub redis: ConnectionManager,
    pub articles: Box<dyn ArticleSource>,
    pub config: FixNamesConfig,
    pub settings: Par2Settings,
}

/// The cached release row the check read, as the progress logic compares it.
fn snapshot_of(release: &LockedRelease) -> Snapshot {
    Snapshot {
        id: release.id,
        guid: release.guid.clone(),
        name: release.name.clone(),
        searchname: release.searchname.clone(),
    }
}

/// `matchingPar2Snapshot`: locks the row and compares its identity. A terminal check wants a
/// completed release, the other an unfinished one.
async fn matching_snapshot(
    conn: &mut sqlx::MySqlConnection,
    snapshot: &Snapshot,
    group_id: i64,
    terminal: bool,
) -> Result<bool> {
    let Some(release) = LockedRelease::lock(conn, snapshot.id).await? else {
        return Ok(false);
    };
    if release.guid != snapshot.guid
        || release.name != snapshot.name
        || release.searchname != snapshot.searchname
        || release.groups_id != group_id
    {
        return Ok(false);
    }
    let completed = release.isrenamed == 1 || release.proc_par2 == 1 || release.predb_id > 0;
    Ok(if terminal {
        completed
    } else {
        release.isrenamed == 0 && release.predb_id == 0 && release.proc_par2 != 1
    })
}

async fn has_pending_candidates(
    conn: &mut sqlx::MySqlConnection,
    release_id: i64,
    signature: &str,
) -> Result<bool> {
    let found: i64 = sqlx::query_scalar(
        "SELECT EXISTS(SELECT 1 FROM par2_rename_candidate_progress c \
         JOIN par2_rename_progress p ON p.releases_id = c.releases_id \
         WHERE c.releases_id = ? AND p.signature = ?)",
    )
    .bind(release_id)
    .bind(signature)
    .fetch_one(conn)
    .await?;
    Ok(found == 1)
}

/// Distinguishes a partial index from a failed probe when the budget runs out: an explicit
/// index with a cursor and no block is partly read.
pub async fn has_incomplete_explicit_progress(
    db: &MySqlPool,
    release_id: i64,
    candidates: &[Candidate],
) -> Result<bool> {
    for candidate in candidates.iter().filter(|c| c.class == Class::Index) {
        let found: i64 = sqlx::query_scalar(
            "SELECT EXISTS(SELECT 1 FROM par2_rename_candidate_progress WHERE releases_id = ? \
             AND candidate_signature = ? AND next_segment > 0 AND blocked_reason IS NULL \
             AND accumulator_state IS NOT NULL)",
        )
        .bind(release_id)
        .bind(candidate.signature())
        .fetch_one(db)
        .await?;
        if found == 1 {
            return Ok(true);
        }
    }
    Ok(false)
}

/// `strictPar2Progress`: the stored cursor for this manifest, or an error when it is malformed.
async fn strict_progress(
    db: &MySqlPool,
    release_id: i64,
    signature: &str,
    candidate_count: i64,
) -> Result<Option<(i64, Vec<i64>)>> {
    let row: Option<(String, i64, String)> = sqlx::query_as(
        "SELECT signature, CAST(`offset` AS SIGNED), retry_indexes FROM par2_rename_progress WHERE releases_id = ?",
    )
    .bind(release_id)
    .fetch_optional(db)
    .await?;
    let Some((stored, offset, retry)) = row else {
        return Ok(None);
    };
    if stored != signature {
        return Ok(None);
    }
    if !(0..=candidate_count).contains(&offset) {
        bail!("Malformed PAR2 parent cursor or retry list");
    }
    let serde_json::Value::Array(items) = serde_json::from_str(&retry)? else {
        bail!("Malformed PAR2 parent cursor or retry list");
    };
    let mut valid: Vec<i64> = Vec::new();
    for item in items {
        let Some(index) = item.as_i64() else {
            bail!("Malformed PAR2 parent retry identity");
        };
        if index < 0 || index >= candidate_count || valid.contains(&index) {
            bail!("Malformed PAR2 parent retry identity");
        }
        valid.push(index);
    }
    Ok(Some((offset, valid)))
}

/// `savePar2Snapshot`: records the outer cursor while the claim and release identity hold.
async fn save_snapshot(
    db: &MySqlPool,
    snapshot: &Snapshot,
    group_id: i64,
    signature: &str,
    offset: i64,
    retry: &[i64],
    guard: &dyn Guard,
) -> Result<bool> {
    let result: Result<bool> = async {
        let mut tx = db.begin().await?;
        let lost = || aborted("PAR2 outer cursor lost its claim or release snapshot");
        if !guard.holds().await || !matching_snapshot(&mut tx, snapshot, group_id, false).await? {
            return Err(lost());
        }
        if !par2_progress::save_in(&mut tx, snapshot.id, signature, offset, retry).await? {
            return Err(lost());
        }
        if !guard.holds().await || !matching_snapshot(&mut tx, snapshot, group_id, false).await? {
            return Err(lost());
        }
        tx.commit().await?;
        Ok(true)
    }
    .await;
    absorb_abort(result)
}

/// `completePar2Snapshot`: marks the release's PAR2 work done and drops its progress, only
/// while the claim holds and no candidate of this manifest still has a buffer.
pub async fn complete_snapshot(
    db: &MySqlPool,
    snapshot: &Snapshot,
    group_id: i64,
    guard: &dyn Guard,
    terminal: bool,
    manifest_signature: Option<&str>,
) -> Result<bool> {
    let result: Result<bool> = async {
        let mut tx = db.begin().await?;
        let lost = || aborted("PAR2 completion lost its claim or release snapshot");
        if !guard.holds().await || !matching_snapshot(&mut tx, snapshot, group_id, terminal).await?
        {
            return Err(lost());
        }
        if let Some(signature) = manifest_signature
            && has_pending_candidates(&mut tx, snapshot.id, signature).await?
        {
            return Err(lost());
        }
        sqlx::query("UPDATE releases SET proc_par2 = 1 WHERE id = ?")
            .bind(snapshot.id)
            .execute(&mut *tx)
            .await?;
        if let Some(signature) = manifest_signature
            && has_pending_candidates(&mut tx, snapshot.id, signature).await?
        {
            return Err(lost());
        }
        sqlx::query("DELETE FROM par2_rename_candidate_progress WHERE releases_id = ?")
            .bind(snapshot.id)
            .execute(&mut *tx)
            .await?;
        sqlx::query("DELETE FROM par2_rename_progress WHERE releases_id = ?")
            .bind(snapshot.id)
            .execute(&mut *tx)
            .await?;
        if !guard.holds().await || !matching_snapshot(&mut tx, snapshot, group_id, true).await? {
            return Err(lost());
        }
        tx.commit().await?;
        Ok(true)
    }
    .await;
    absorb_abort(result)
}

impl Par2Checker {
    /// `checkPar2Result`. `deadline` bounds the whole check; `sweep` is the outer claim a
    /// sweep renews along with this release's.
    pub async fn check(
        &mut self,
        guid: &str,
        release_id: i64,
        group_id: i64,
        name_status: i32,
        deadline: Option<Instant>,
        sweep: Option<Arc<dyn Refresh>>,
    ) -> (Par2Outcome, Par2Stats) {
        let mut stats = Par2Stats::default();
        if name_status != 1 {
            return (Par2Outcome::Deferred, stats);
        }
        let lock = RedisLock::new(
            self.redis.clone(),
            format!("{}rename:par2:{release_id}", self.settings.key_prefix),
            self.settings.lease_seconds,
        );
        match lock.acquire().await {
            Ok(true) => {}
            Ok(false) => return (Par2Outcome::Deferred, stats),
            Err(error) => {
                tracing::warn!(release_id, %error, "PAR2 rename attempt remains pending");
                return (Par2Outcome::Retryable, stats);
            }
        }
        let claim = Arc::new(LockClaim::new(lock.clone(), deadline, sweep));
        let outcome = match self
            .check_claimed(guid, release_id, group_id, claim, &mut stats)
            .await
        {
            Ok(outcome) => outcome,
            Err(error) => {
                tracing::warn!(release_id, %error, "PAR2 rename attempt remains pending");
                Par2Outcome::Retryable
            }
        };
        if let Err(error) = lock.release().await {
            tracing::warn!(release_id, %error, "Could not release PAR2 rename claim; it will expire");
        }
        (outcome, stats)
    }

    async fn check_claimed(
        &mut self,
        guid: &str,
        release_id: i64,
        group_id: i64,
        claim: Arc<LockClaim>,
        stats: &mut Par2Stats,
    ) -> Result<Par2Outcome> {
        let db = self.fixer.updater.db.clone();
        let guard: Arc<dyn Claim> = claim;
        let release = {
            let mut conn = db.acquire().await?;
            LockedRelease::read(&mut conn, release_id).await?
        };
        let Some(release) = release else {
            return Ok(Par2Outcome::Deferred);
        };
        if release.guid != guid || release.groups_id != group_id {
            return Ok(Par2Outcome::Deferred);
        }
        let snapshot = snapshot_of(&release);
        if !guard.holds().await {
            return Ok(Par2Outcome::Deferred);
        }
        if release.proc_par2 == 1 || release.isrenamed == 1 || release.predb_id > 0 {
            return Ok(
                if complete_snapshot(&db, &snapshot, group_id, guard.as_ref(), true, None).await? {
                    Par2Outcome::NoMatch
                } else {
                    Par2Outcome::Deferred
                },
            );
        }

        let xml = nntmux_verify::nzb::read(&db, guid).await?;
        if !guard.holds().await {
            return Ok(Par2Outcome::Deferred);
        }
        let Some(files) = xml.as_deref().filter(|x| !x.is_empty()).and_then(parse_nzb) else {
            return Ok(Par2Outcome::Retryable);
        };
        let budget = self.config.par2_max_probes.max(1) as i64;
        let max_article_bytes = self.config.par2_max_probe_bytes.max(1) as i64;
        let max_state_bytes = self.config.par2_max_state_bytes.max(1) as i64;
        let plan: Plan = plan(&files, max_article_bytes)?;
        let alternate = self.settings.alternate;
        let signature = plan.signature(
            guid,
            group_id,
            max_article_bytes,
            max_state_bytes,
            alternate,
        );
        let count = plan.candidates.len() as i64;
        let progress = strict_progress(&db, release_id, &signature, count).await?;
        let (mut offset, mut retry) = progress.unwrap_or((0, Vec::new()));
        let mut pending: Vec<i64> = (offset..count).collect();
        for index in &retry {
            if !pending.contains(index) {
                pending.push(*index);
            }
        }
        let mut outcome = Par2Outcome::NoMatch;
        for index in pending {
            if stats.candidates_probed >= budget {
                let incomplete =
                    has_incomplete_explicit_progress(&db, release_id, &plan.candidates).await?;
                return Ok(if !retry.is_empty() && !incomplete {
                    Par2Outcome::Retryable
                } else {
                    Par2Outcome::Deferred
                });
            }
            if !guard.holds().await {
                return Ok(Par2Outcome::Deferred);
            }
            let candidate = &plan.candidates[index as usize];
            let max_payload_bytes = (release.size > 0).then(|| {
                let candidate_bytes: i64 = candidate.segments.iter().map(|s| s.bytes).sum();
                let non_par2 = (plan.non_explicit_bytes
                    - if candidate.explicit {
                        0
                    } else {
                        candidate_bytes
                    })
                .max(0);
                release.size.min(non_par2)
            });
            let expected = Expected {
                snapshot: snapshot.clone(),
                max_payload_bytes,
            };
            let recovered = {
                let mut recovery = Recovery {
                    fixer: &mut self.fixer,
                    search: &self.search,
                    add_par2: self.settings.add_par2,
                    articles: self.articles.as_mut(),
                    claim: &guard,
                    alternate,
                };
                recover(
                    &mut recovery,
                    &Request {
                        candidate,
                        snapshot: &expected,
                        manifest_signature: &signature,
                        group_id,
                        article_budget: budget - stats.candidates_probed,
                        max_article_bytes,
                        max_state_bytes,
                    },
                )
                .await?
            };
            stats.candidates_probed += recovered.probes;
            stats.articles_received += recovered.articles;
            outcome = recovered.outcome;
            if outcome == Par2Outcome::Renamed {
                return Ok(outcome);
            }
            if !guard.holds().await {
                return Ok(Par2Outcome::Deferred);
            }
            retry.retain(|known| *known != index);
            if matches!(outcome, Par2Outcome::Retryable | Par2Outcome::Deferred) {
                retry.push(index);
            }
            offset = offset.max(index + 1);
            if !save_snapshot(
                &db,
                &snapshot,
                group_id,
                &signature,
                offset,
                &retry,
                guard.as_ref(),
            )
            .await?
            {
                return Ok(Par2Outcome::Deferred);
            }
        }
        let pending_candidates = {
            let mut conn = db.acquire().await?;
            has_pending_candidates(&mut conn, release_id, &signature).await?
        };
        if !retry.is_empty() || !plan.structure_valid || pending_candidates {
            return Ok(if outcome == Par2Outcome::Deferred {
                outcome
            } else {
                Par2Outcome::Retryable
            });
        }
        Ok(
            if complete_snapshot(
                &db,
                &snapshot,
                group_id,
                guard.as_ref(),
                false,
                Some(&signature),
            )
            .await?
            {
                Par2Outcome::NoMatch
            } else {
                Par2Outcome::Deferred
            },
        )
    }
}
