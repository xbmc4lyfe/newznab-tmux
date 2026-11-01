//! Admitting a manifest from outside ingestion: imported and uploaded NZBs.

use anyhow::Result;
use sqlx::{Connection, MySqlConnection};
use thiserror::Error;

use super::{Candidate, archive::admitted_status, now};
use crate::context::Verify;
use crate::inventory::{self, Assessment, HeaderStatus};
use crate::locks::Admission;
use crate::manifest::{self, Manifest, PreparedManifest};
use crate::nzb::coverage::{CoverageBudget, coverage_ids, covered_snapshot, parse_document};
use crate::nzb::document;
use crate::reader::{ReleaseFacts, read_parsed};
use crate::txn::is_unique_violation;
use crate::{in_tx, ledger};

const PUBLISHED: [&str; 3] = ["complete", "repaired", "sampled"];
const NZB_ADDED: i32 = 1;

/// The admission couldn't be decided now. The source is kept and retried.
#[derive(Debug, Error)]
pub enum AdmissionDeferred {
    #[error("Candidate capacity is full; import retained for retry.")]
    Capacity,
    #[error("Metadata capacity is full; import retained for retry.")]
    MetadataCapacity,
    #[error("Publication changed during admission; import retained for retry.")]
    Publication,
}

/// The exact revision an admission accepted.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct CandidateAdmission {
    pub candidate_id: i64,
    pub identity: String,
    pub version: i64,
    pub manifest_hash: String,
}

/// What the transaction decided. Deferrals commit their fence before they're reported.
enum Decision {
    Accepted,
    Refused,
    MetadataCapacity,
    Publication,
}

fn without_passwords(manifest: &Manifest) -> Manifest {
    let mut copy = manifest.clone();
    copy.passwords = None;
    copy
}

/// Whether the published release already holds these articles and these passwords.
async fn identical_published_replay(
    tx: &mut MySqlConnection,
    existing: &Candidate,
    manifest: &Manifest,
    prepared: Option<&PreparedManifest>,
    replay: Option<&str>,
) -> Result<Decision> {
    let release: Option<(String, i32)> = sqlx::query_as(
        "SELECT guid, CAST(nzbstatus AS SIGNED) FROM releases WHERE id = ? FOR UPDATE",
    )
    .bind(existing.release_id)
    .fetch_optional(&mut *tx)
    .await?;
    let Some((guid, status)) = release else {
        return Ok(Decision::Refused);
    };
    let (Some(prepared), Some(replay)) = (prepared, replay) else {
        return Ok(Decision::Refused);
    };
    if guid.is_empty()
        || status != NZB_ADDED
        || !PUBLISHED.contains(&existing.status.as_str())
        || existing.verified_at.is_none()
        || !existing.manifest_path.is_empty()
        || existing.manifest_bytes != 0
        || existing.manifest_hash != prepared.name[..64]
        || manifest.metadata_source.as_deref() != Some("import")
        || manifest.conflict
    {
        return Ok(Decision::Refused);
    }
    let required = parse_document(replay.as_bytes()).and_then(|document| coverage_ids(&document));
    let Some(required) = required else {
        return Ok(Decision::Publication);
    };
    let mut budget = CoverageBudget::default();
    let Some(snapshot) = covered_snapshot(tx, &required, &guid, &mut budget).await? else {
        return Ok(Decision::Publication);
    };
    let facts = ReleaseFacts {
        searchname: manifest.subject.clone(),
        fromname: manifest.fromname.clone(),
        groups_id: manifest.groups_id,
        ..ReleaseFacts::default()
    };
    Ok(match read_parsed(&snapshot, &facts) {
        Ok(retained) if retained.passwords() == manifest.passwords() => Decision::Accepted,
        Ok(_) => Decision::Refused,
        Err(_) => Decision::Publication,
    })
}

/// Fills explicit uploader metadata only for an otherwise identical unpublished import. The caller
/// holds the admission and candidate locks.
async fn enrich_passwords(
    tx: &mut MySqlConnection,
    verify: &Verify,
    existing: &Candidate,
    manifest: &Manifest,
    prepared: Option<&PreparedManifest>,
    unreferenced: &mut Option<String>,
) -> Result<Decision> {
    if manifest.passwords().is_empty() {
        return Ok(match existing.status.as_str() {
            "metadata_deferred" => Decision::MetadataCapacity,
            "metadata_review" | "metadata_expired" => Decision::Refused,
            _ => Decision::Accepted,
        });
    }
    let open = [
        "ready",
        "waiting_upload",
        "pending",
        "unknown",
        "header_rejected",
        "verifying",
        "metadata_deferred",
    ];
    if existing.release_id.is_some()
        || existing.manifest_path.is_empty()
        || !open.contains(&existing.status.as_str())
        || manifest.metadata_source.as_deref() != Some("import")
        || manifest.conflict
    {
        return Ok(Decision::Refused);
    }
    let original =
        manifest::read_current(&mut *tx, &verify.config, &existing.manifest_path).await?;
    if original.metadata_source.as_deref() != Some("import") || original.conflict {
        return Ok(Decision::Refused);
    }
    if manifest::php_json(&without_passwords(manifest))?
        != manifest::php_json(&without_passwords(&original))?
    {
        return Ok(Decision::Refused);
    }
    if !original.passwords().is_empty() {
        return Ok(if original.passwords() == manifest.passwords() {
            Decision::Accepted
        } else {
            Decision::Refused
        });
    }
    let Some(prepared) = prepared else {
        anyhow::bail!("Explicit metadata must be prepared before admission.");
    };
    let path = manifest::write_prepared(&mut *tx, prepared).await?;
    let bytes = manifest::size(&mut *tx, &verify.config, &path).await?;
    let delta = bytes - existing.manifest_bytes;
    let assessment = inventory::assess(manifest);
    // The reservation is this transaction's last lock request.
    let reserved = ledger::fits(&mut *tx, &verify.config, delta).await?
        && ledger::adjust(&mut *tx, &verify.config, delta).await?;
    if !reserved {
        // Keep the bounded manifest and capacity, but prevent its passwordless publication.
        sqlx::query(
            "UPDATE release_verification_candidates SET status = 'metadata_deferred', \
             reason = 'Explicit metadata awaits capacity.', version = ?, lease_token = NULL, lease_until = NULL \
             WHERE id = ?",
        )
        .bind(existing.version + 1)
        .bind(existing.id)
        .execute(&mut *tx)
        .await?;
        *unreferenced = Some(path);
        return Ok(Decision::MetadataCapacity);
    }
    sqlx::query(
        "UPDATE release_verification_candidates SET manifest_path = ?, manifest_hash = ?, manifest_bytes = ?, \
         version = ?, status = ?, header_check_pending = ?, reason = ?, next_attempt_at = ?, lease_token = NULL, \
         lease_until = NULL WHERE id = ?",
    )
    .bind(&path)
    .bind(&path[..64])
    .bind(bytes)
    .bind(existing.version + 1)
    .bind(admitted_status(assessment.status))
    .bind(assessment.status == HeaderStatus::Rejected)
    .bind(assessment.reason)
    .bind(now())
    .bind(existing.id)
    .execute(&mut *tx)
    .await?;
    *unreferenced = Some(existing.manifest_path.clone());
    Ok(Decision::Accepted)
}

/// A plain lookup first: locking an absent key would gap-lock the identity index, and concurrent
/// first admissions inserting into one gap deadlock.
async fn lock_identity(tx: &mut MySqlConnection, identity: &str) -> Result<Option<Candidate>> {
    let id: Option<u64> =
        sqlx::query_scalar("SELECT id FROM release_verification_candidates WHERE identity = ?")
            .bind(identity)
            .fetch_optional(&mut *tx)
            .await?;
    let Some(id) = id else {
        return Ok(None);
    };
    Ok(
        sqlx::query_as("SELECT * FROM release_verification_candidates WHERE id = ? FOR UPDATE")
            .bind(id)
            .fetch_optional(&mut *tx)
            .await?,
    )
}

/// The candidate row for a new or readmitted manifest. `false` means a first admission lost the race
/// for this identity, so the caller decides again against the row that won.
async fn write_row(
    tx: &mut MySqlConnection,
    manifest: &Manifest,
    existing: Option<&Candidate>,
    path: &str,
    bytes: i64,
    assessment: &Assessment,
) -> Result<bool> {
    let moment = now();
    let version = existing.map_or(0, |row| row.version) + 1;
    if let Some(row) = existing {
        sqlx::query(
            "UPDATE release_verification_candidates SET identity = ?, source_identity = ?, groups_id = ?, \
             manifest_path = ?, manifest_bytes = ?, manifest_hash = ?, last_unique_at = ?, next_attempt_at = ?, \
             version = ?, status = ?, header_check_pending = ?, reason = ?, release_id = NULL, verified_at = NULL, \
             lease_token = NULL, lease_until = NULL WHERE id = ?",
        )
        .bind(&manifest.identity)
        .bind(&manifest.identity)
        .bind(manifest.groups_id)
        .bind(path)
        .bind(bytes)
        .bind(&path[..64])
        .bind(moment)
        .bind(moment)
        .bind(version)
        .bind(admitted_status(assessment.status))
        .bind(assessment.status == HeaderStatus::Rejected)
        .bind(assessment.reason)
        .bind(row.id)
        .execute(&mut *tx)
        .await?;
        return Ok(true);
    }
    let inserted = sqlx::query(
        "INSERT INTO release_verification_candidates (identity, source_identity, groups_id, manifest_path, \
         manifest_bytes, manifest_hash, last_unique_at, next_attempt_at, version, status, header_check_pending, reason) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(&manifest.identity)
    .bind(&manifest.identity)
    .bind(manifest.groups_id)
    .bind(path)
    .bind(bytes)
    .bind(&path[..64])
    .bind(moment)
    .bind(moment)
    .bind(version)
    .bind(admitted_status(assessment.status))
    .bind(assessment.status == HeaderStatus::Rejected)
    .bind(assessment.reason)
    .execute(&mut *tx)
    .await;
    match inserted {
        Ok(_) => Ok(true),
        Err(failure) if is_unique_violation(&failure) => Ok(false),
        Err(failure) => Err(failure.into()),
    }
}

async fn admit_locked(
    tx: &mut MySqlConnection,
    verify: &Verify,
    manifest: &Manifest,
    prepared: Option<&PreparedManifest>,
    replay: Option<&str>,
    unreferenced: &mut Option<String>,
) -> Result<Decision> {
    for _ in 0..2 {
        let existing = lock_identity(tx, &manifest.identity).await?;
        let release_gone = match existing.as_ref().and_then(|row| row.release_id) {
            Some(release) => {
                let found: Option<i32> =
                    sqlx::query_scalar("SELECT 1 FROM releases WHERE id = ? FOR UPDATE")
                        .bind(release)
                        .fetch_optional(&mut *tx)
                        .await?;
                found.is_none()
            }
            None => false,
        };
        let metadata_readmission = existing.as_ref().is_some_and(|row| {
            row.status == "metadata_expired"
                && row.release_id.is_none()
                && row.manifest_path.is_empty()
                && row.manifest_bytes == 0
        });
        if metadata_readmission
            && (manifest.passwords().is_empty()
                || manifest.metadata_source.as_deref() != Some("import")
                || manifest.conflict)
        {
            return Ok(Decision::Refused);
        }
        if let Some(row) = &existing
            && !metadata_readmission
            && !release_gone
        {
            if row.release_id.is_some() && !manifest.passwords().is_empty() {
                if replay.is_none() {
                    return Ok(Decision::Publication);
                }
                return identical_published_replay(tx, row, manifest, prepared, replay).await;
            }
            return enrich_passwords(tx, verify, row, manifest, prepared, unreferenced).await;
        }
        let owned;
        let prepared_now = match prepared {
            Some(prepared) => prepared,
            None => {
                owned = PreparedManifest::new(manifest)?;
                &owned
            }
        };
        let path = manifest::write_prepared(&mut *tx, prepared_now).await?;
        let bytes = manifest::size(&mut *tx, &verify.config, &path).await?;
        let delta = bytes - existing.as_ref().map_or(0, |row| row.manifest_bytes);
        if !ledger::fits(&mut *tx, &verify.config, delta).await? {
            return Err(AdmissionDeferred::Capacity.into());
        }
        let assessment = inventory::assess(manifest);
        if !write_row(tx, manifest, existing.as_ref(), &path, bytes, &assessment).await? {
            // A concurrent first admission committed this identity after the plain lookup.
            continue;
        }
        if let Some(row) = &existing
            && !row.manifest_path.is_empty()
            && row.manifest_path != path
        {
            *unreferenced = Some(row.manifest_path.clone());
        }
        // The ledger row is the last lock.
        if !ledger::adjust(&mut *tx, &verify.config, delta).await? {
            return Err(AdmissionDeferred::Capacity.into());
        }
        return Ok(Decision::Accepted);
    }
    anyhow::bail!("Candidate identity kept changing during admission; retry.");
}

/// Admits a manifest as a candidate. `None` when it's refused: the source's metadata needs review.
/// A [`AdmissionDeferred`] error means the source must be kept and retried.
pub async fn enqueue_with_admission(
    verify: &Verify,
    manifest: &Manifest,
) -> Result<Option<CandidateAdmission>> {
    if manifest.passwords.is_some() && !manifest::valid_passwords(manifest.passwords()) {
        return Ok(None);
    }
    let mut admission = Admission::enter(&verify.db).await?;
    let outcome = admit(verify, admission.conn(), manifest).await;
    let left = admission.leave().await;
    let (decision, accepted, unreferenced) = outcome?;
    left?;
    if let Some(path) = unreferenced {
        manifest::delete_if_unreferenced(&verify.db, &verify.config, &path).await?;
    }
    match decision {
        Decision::Accepted => Ok(accepted),
        Decision::Refused => Ok(None),
        Decision::MetadataCapacity => Err(AdmissionDeferred::MetadataCapacity.into()),
        Decision::Publication => Err(AdmissionDeferred::Publication.into()),
    }
}

async fn admit(
    verify: &Verify,
    conn: &mut MySqlConnection,
    manifest: &Manifest,
) -> Result<(Decision, Option<CandidateAdmission>, Option<String>)> {
    // Prepared before capacity ownership. A passwordless known replay keeps its cheap path.
    let observed: Option<Option<u64>> = sqlx::query_scalar(
        "SELECT release_id FROM release_verification_candidates WHERE identity = ?",
    )
    .bind(&manifest.identity)
    .fetch_optional(&mut *conn)
    .await?;
    let has_passwords = !manifest.passwords().is_empty();
    let prepared = if has_passwords || observed.is_none() {
        Some(PreparedManifest::new(manifest)?)
    } else {
        None
    };
    let replay = if observed.flatten().is_some() && has_passwords {
        Some(document(
            manifest,
            manifest.verified_file_count.unwrap_or(0),
        )?)
    } else {
        None
    };
    in_tx!(conn, 5, |tx| {
        let mut unreferenced = None;
        let decision = admit_locked(
            tx,
            verify,
            manifest,
            prepared.as_ref(),
            replay.as_deref(),
            &mut unreferenced,
        )
        .await?;
        let mut accepted = None;
        if matches!(decision, Decision::Accepted) {
            let row: Option<(i64, String, i64, String)> = sqlx::query_as(
                "SELECT CAST(id AS SIGNED), identity, CAST(version AS SIGNED), manifest_hash \
                 FROM release_verification_candidates WHERE identity = ? FOR UPDATE",
            )
            .bind(&manifest.identity)
            .fetch_optional(&mut *tx)
            .await?;
            let Some((candidate_id, identity, version, manifest_hash)) = row else {
                anyhow::bail!("Accepted candidate disappeared under admission lock");
            };
            accepted = Some(CandidateAdmission {
                candidate_id,
                identity,
                version,
                manifest_hash,
            });
        }
        Ok::<_, anyhow::Error>((decision, accepted, unreferenced))
    })
}
