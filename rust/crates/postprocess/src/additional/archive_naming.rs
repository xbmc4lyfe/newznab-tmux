//! `ReleaseArchiveNamingEvidence::recordLocked`: the durable record of what an archive inventory
//! proves about a release's naming scope. Processing statuses and retained file rows are no such
//! proof; this row is.
//!
//! Callers run it inside the transaction that writes the inventory and statuses, and write them
//! only when it returns true: false rejects the whole stale finalization. PHP took a persist
//! callback and called it on exactly those paths.
//!
//! The NZB source is read as name fixing reads it ([`nntmux_namefix::update`]): the digest
//! `release_nzb_contents` records, under the release row lock.

use anyhow::{Result, bail};
use nntmux_namefix::update::decode_witnesses;
use nntmux_predb::season::has_non_single_episode_evidence;
use sqlx::MySqlConnection;

pub const PROOF_VERSION: i64 = 1;
pub const MAX_WITNESSES: usize = 256;
const MAX_WITNESS_BYTES: usize = 32_768;
const MAX_NAME_BYTES: usize = 4096;

/// How much of the release one inventory accounts for.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Scope {
    Unknown,
    Incomplete,
    CompleteSingle,
    MultiEpisode,
}

impl Scope {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Unknown => "unknown",
            Self::Incomplete => "incomplete",
            Self::CompleteSingle => "complete_single",
            Self::MultiEpisode => "multi_episode",
        }
    }

    fn parse(text: &str) -> Option<Self> {
        Some(match text {
            "unknown" => Self::Unknown,
            "incomplete" => Self::Incomplete,
            "complete_single" => Self::CompleteSingle,
            "multi_episode" => Self::MultiEpisode,
            _ => return None,
        })
    }
}

/// What one finalization observed.
#[derive(Clone, Debug)]
pub struct Observation<'a> {
    pub release_id: i64,
    pub guid: &'a str,
    pub source_digest: &'a str,
    pub scope: Scope,
    pub filenames: &'a [String],
    /// Every archive in the current NZB was fully accounted for. A subset never is.
    pub authoritative_complete: bool,
    /// An observed filename was dropped, not merely that a retry listed less.
    pub evidence_omitted: bool,
}

struct Previous {
    source: String,
    scope: Scope,
    names: Vec<String>,
}

/// Records the observation unless the NZB changed since it was made. True when the caller may
/// write its inventory and statuses.
pub async fn record_locked(conn: &mut MySqlConnection, seen: &Observation<'_>) -> Result<bool> {
    validate_digest(seen.source_digest)?;
    let locked: Option<i64> = sqlx::query_scalar(
        "SELECT CAST(id AS SIGNED) FROM releases WHERE id = ? AND guid = ? FOR UPDATE",
    )
    .bind(seen.release_id)
    .bind(seen.guid)
    .fetch_optional(&mut *conn)
    .await?;
    if locked.is_none() {
        bail!("NZB release identity changed.");
    }
    let source: Option<String> = sqlx::query_scalar(
        "SELECT source_digest FROM release_nzb_contents WHERE guid = ? LOCK IN SHARE MODE",
    )
    .bind(seen.guid)
    .fetch_optional(&mut *conn)
    .await?;
    if source.as_deref() != Some(seen.source_digest) {
        return Ok(false);
    }
    let previous = load_locked(conn, seen.release_id, seen.guid).await?;
    let (mut names, overflow) = bounded_names(seen.filenames);
    let overflow = overflow || seen.evidence_omitted;
    let mut scope = seen.scope;
    if has_non_single_episode_evidence(&names) {
        scope = Scope::MultiEpisode;
    } else if scope != Scope::MultiEpisode
        && (overflow
            || (scope == Scope::CompleteSingle
                && (!seen.authoritative_complete || names.is_empty())))
    {
        scope = Scope::Incomplete;
    }
    if let Some(previous) = previous.filter(|previous| previous.source == seen.source_digest) {
        if previous.scope == Scope::MultiEpisode {
            return Ok(true);
        }
        // Evidence from disjoint attempts must not erase a witnessed pack.
        let mut observed = previous.names;
        for name in &names {
            if !observed.contains(name) {
                observed.push(name.clone());
            }
        }
        if has_non_single_episode_evidence(&observed) {
            scope = Scope::MultiEpisode;
        } else if !overflow
            && previous.scope == Scope::CompleteSingle
            && matches!(scope, Scope::Unknown | Scope::Incomplete)
        {
            return Ok(true);
        }
        if matches!(
            scope,
            Scope::Unknown | Scope::Incomplete | Scope::MultiEpisode
        ) {
            names = bounded_names(&observed).0;
        }
    }
    store(conn, seen, scope, &names).await?;
    Ok(true)
}

async fn load_locked(
    conn: &mut MySqlConnection,
    release_id: i64,
    guid: &str,
) -> Result<Option<Previous>> {
    // A missing table or column is an error, never legacy absence.
    let row: Option<(String, i64, String, String, String)> = sqlx::query_as(
        "SELECT release_guid, CAST(proof_version AS SIGNED), scope, source_sha256, episode_files \
         FROM release_archive_naming_evidence WHERE releases_id = ? FOR UPDATE",
    )
    .bind(release_id)
    .fetch_optional(&mut *conn)
    .await?;
    let Some((release_guid, version, scope, source, files)) = row else {
        return Ok(None);
    };
    let Some(scope) =
        Scope::parse(&scope).filter(|_| release_guid == guid && version == PROOF_VERSION)
    else {
        bail!("Malformed archive naming evidence.");
    };
    validate_digest(&source)?;
    let names = decode_witnesses(&files)?;
    Ok(Some(Previous {
        source,
        scope,
        names,
    }))
}

async fn store(
    conn: &mut MySqlConnection,
    seen: &Observation<'_>,
    scope: Scope,
    names: &[String],
) -> Result<()> {
    sqlx::query(
        "INSERT INTO release_archive_naming_evidence \
         (releases_id, release_guid, source_sha256, proof_version, scope, episode_files, updated_at) \
         VALUES (?, ?, ?, ?, ?, ?, NOW()) \
         ON DUPLICATE KEY UPDATE release_guid = VALUES(release_guid), \
         source_sha256 = VALUES(source_sha256), proof_version = VALUES(proof_version), \
         scope = VALUES(scope), episode_files = VALUES(episode_files), updated_at = VALUES(updated_at)",
    )
    .bind(seen.release_id)
    .bind(seen.guid)
    .bind(seen.source_digest)
    .bind(PROOF_VERSION)
    .bind(scope.as_str())
    .bind(serde_json::to_string(names)?)
    .execute(&mut *conn)
    .await?;
    Ok(())
}

/// `boundedNames`: unique names within the count and byte bounds, and whether any were dropped.
/// A name over the count bound still counts toward the bytes, as in PHP.
pub fn bounded_names(names: &[String]) -> (Vec<String>, bool) {
    let mut bounded: Vec<String> = Vec::new();
    let mut seen = std::collections::HashSet::new();
    let mut overflow = false;
    let mut bytes = 0;
    for name in names {
        if !seen.insert(name.as_str()) {
            continue;
        }
        if name.is_empty() || name.len() > MAX_NAME_BYTES {
            overflow = true;
            continue;
        }
        bytes += name.len();
        if bounded.len() >= MAX_WITNESSES || bytes > MAX_WITNESS_BYTES {
            overflow = true;
            continue;
        }
        bounded.push(name.clone());
    }
    (bounded, overflow)
}

fn validate_digest(digest: &str) -> Result<()> {
    if digest.len() != 64
        || !digest
            .bytes()
            .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
    {
        bail!("Invalid NZB source digest.");
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn bounds_drop_duplicates_and_count_bytes_past_the_cap() {
        let names: Vec<String> = ["a", "a", "", "b"]
            .iter()
            .map(|s| (*s).to_owned())
            .collect();
        assert_eq!(
            bounded_names(&names),
            (vec!["a".to_owned(), "b".to_owned()], true)
        );
        let many: Vec<String> = (0..300).map(|index| format!("f{index}")).collect();
        let (kept, overflow) = bounded_names(&many);
        assert_eq!(kept.len(), MAX_WITNESSES);
        assert!(overflow);
    }

    #[test]
    fn digests_are_lowercase_sha256_hex() {
        assert!(validate_digest(&"a".repeat(64)).is_ok());
        assert!(validate_digest(&"A".repeat(64)).is_err());
        assert!(validate_digest(&"a".repeat(63)).is_err());
    }
}
