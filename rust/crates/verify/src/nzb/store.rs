//! `release_nzb_contents`: compressed bodies with a digest, written under the release's row lock.
//! Lock order is release, content, job.

use std::io::{Read, Write};

use anyhow::{Result, bail};
use chrono::Utc;
use flate2::Compression;
use flate2::bufread::GzDecoder;
use flate2::write::GzEncoder;
use rand::RngCore;
use sha2::{Digest, Sha256};
use sqlx::{MySqlConnection, MySqlExecutor, MySqlPool};

use crate::in_tx;

pub const MAX_COMPRESSED_BYTES: usize = 33_554_432;
pub const MAX_DECODED_BYTES: usize = 268_435_456;

const PROOF_VERSION: u8 = 1;

/// The identity of a release row a caller has locked.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ReleaseRef {
    pub id: i64,
    pub guid: String,
}

/// The source digest stored with an NZB, shared with naming evidence.
pub fn digest(decoded: &[u8]) -> String {
    let mut hasher = Sha256::new();
    hasher.update(b"nntmux:nzb-archive-naming:1\0");
    hasher.update(decoded);
    hex::encode(hasher.finalize())
}

/// Rejects truncated streams, concatenated members and decompression bombs.
pub fn decode(body: &[u8], max_decoded: usize) -> Option<Vec<u8>> {
    if max_decoded < 1 || !body.starts_with(&[0x1f, 0x8b]) {
        return None;
    }
    let mut decoder = GzDecoder::new(body);
    let mut xml = Vec::new();
    (&mut decoder)
        .take(max_decoded as u64 + 1)
        .read_to_end(&mut xml)
        .ok()?;
    if xml.is_empty() || xml.len() > max_decoded || !decoder.into_inner().is_empty() {
        return None;
    }
    Some(xml)
}

/// The stored form of a rendered document.
pub fn compress(document: &[u8]) -> Result<Vec<u8>> {
    let mut encoder = GzEncoder::new(Vec::new(), Compression::default());
    encoder.write_all(document)?;
    Ok(encoder.finish()?)
}

pub async fn exists(executor: impl MySqlExecutor<'_>, guid: &str) -> Result<bool> {
    let found: Option<i32> =
        sqlx::query_scalar("SELECT 1 FROM release_nzb_contents WHERE guid = ?")
            .bind(guid)
            .fetch_optional(executor)
            .await?;
    Ok(found.is_some())
}

/// Reads and verifies a stored NZB within the default limits.
pub async fn read(executor: impl MySqlExecutor<'_>, guid: &str) -> Result<Option<Vec<u8>>> {
    read_bounded(executor, guid, MAX_COMPRESSED_BYTES, MAX_DECODED_BYTES).await
}

/// Reads a stored NZB within limits. `None` when it's missing or over a limit; an error when the
/// bytes don't match their recorded digest.
pub async fn read_bounded(
    executor: impl MySqlExecutor<'_>,
    guid: &str,
    max_compressed: usize,
    max_decoded: usize,
) -> Result<Option<Vec<u8>>> {
    let row: Option<(Vec<u8>, String)> = sqlx::query_as(
        "SELECT body, source_digest FROM release_nzb_contents WHERE guid = ? AND decoded_bytes <= ?",
    )
    .bind(guid)
    .bind(max_decoded as u64)
    .fetch_optional(executor)
    .await?;
    let Some((body, source_digest)) = row else {
        return Ok(None);
    };
    if body.len() > max_compressed {
        return Ok(None);
    }
    match decode(&body, max_decoded) {
        Some(xml) if digest(&xml) == source_digest => Ok(Some(xml)),
        _ => bail!("Database NZB integrity check failed."),
    }
}

/// Installs a compressed NZB. The caller holds its transaction and the release row lock.
///
/// `expected_digest` is a compare-and-swap for repairs. Returns the new source digest.
pub async fn put_locked(
    conn: &mut MySqlConnection,
    release: &ReleaseRef,
    compressed: &[u8],
    expected_digest: Option<&str>,
    enqueue_work: bool,
) -> Result<String> {
    let current: Option<i64> =
        sqlx::query_scalar("SELECT id FROM releases WHERE id = ? AND guid = ? FOR UPDATE")
            .bind(release.id)
            .bind(&release.guid)
            .fetch_optional(&mut *conn)
            .await?
            .map(|id: u32| i64::from(id));
    if current.is_none() {
        bail!("Release changed before database NZB installation.");
    }
    if compressed.len() > MAX_COMPRESSED_BYTES {
        bail!("Invalid or oversized compressed NZB.");
    }
    let Some(xml) = decode(compressed, MAX_DECODED_BYTES) else {
        bail!("Invalid or oversized compressed NZB.");
    };
    let previous: Option<(String, String)> = sqlx::query_as(
        "SELECT source_digest, generation FROM release_nzb_contents WHERE guid = ? FOR UPDATE",
    )
    .bind(&release.guid)
    .fetch_optional(&mut *conn)
    .await?;
    if let Some(expected) = expected_digest
        && previous.as_ref().is_none_or(|(old, _)| old != expected)
    {
        bail!("NZB source changed before replacement.");
    }
    let digest = digest(&xml);
    let now = Utc::now().naive_utc();
    let generation = match &previous {
        Some((old, generation)) if *old == digest => generation.clone(),
        _ => {
            let mut bytes = [0u8; 16];
            rand::rng().fill_bytes(&mut bytes);
            hex::encode(bytes)
        }
    };
    if previous.is_none() {
        sqlx::query(
            "INSERT INTO release_nzb_contents \
             (guid, created_at, releases_id, body, source_digest, generation, decoded_bytes, updated_at) \
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        )
        .bind(&release.guid)
        .bind(now)
        .bind(release.id)
        .bind(compressed)
        .bind(&digest)
        .bind(&generation)
        .bind(xml.len() as u64)
        .bind(now)
        .execute(&mut *conn)
        .await?;
    } else {
        sqlx::query(
            "UPDATE release_nzb_contents SET releases_id = ?, body = ?, source_digest = ?, \
             generation = ?, decoded_bytes = ?, updated_at = ? WHERE guid = ?",
        )
        .bind(release.id)
        .bind(compressed)
        .bind(&digest)
        .bind(&generation)
        .bind(xml.len() as u64)
        .bind(now)
        .bind(&release.guid)
        .execute(&mut *conn)
        .await?;
    }
    source_replaced_locked(conn, release, &digest).await?;
    if enqueue_work {
        enqueue_fingerprint_locked(conn, release.id, &digest, false).await?;
    }
    Ok(digest)
}

/// A new source generation starts its archive naming evidence over.
pub async fn source_replaced_locked(
    conn: &mut MySqlConnection,
    release: &ReleaseRef,
    digest: &str,
) -> Result<()> {
    let row: Option<(String, u8, String)> = sqlx::query_as(
        "SELECT release_guid, proof_version, source_sha256 FROM release_archive_naming_evidence \
         WHERE releases_id = ? FOR UPDATE",
    )
    .bind(release.id)
    .fetch_optional(&mut *conn)
    .await?;
    if let Some((guid, version, source)) = &row {
        if *guid != release.guid || *version != PROOF_VERSION {
            bail!("Malformed archive naming evidence.");
        }
        if source == digest {
            return Ok(());
        }
    }
    sqlx::query(
        "INSERT INTO release_archive_naming_evidence \
         (releases_id, release_guid, source_sha256, proof_version, scope, episode_files, updated_at) \
         VALUES (?, ?, ?, ?, 'unknown', '[]', ?) \
         ON DUPLICATE KEY UPDATE release_guid = VALUES(release_guid), source_sha256 = VALUES(source_sha256), \
         proof_version = VALUES(proof_version), scope = VALUES(scope), episode_files = VALUES(episode_files), \
         updated_at = VALUES(updated_at)",
    )
    .bind(release.id)
    .bind(&release.guid)
    .bind(digest)
    .bind(PROOF_VERSION)
    .bind(Utc::now().naive_utc())
    .execute(&mut *conn)
    .await?;
    Ok(())
}

/// Queues fingerprint work for a source generation. Replacing the source fences even an in-flight
/// claim immediately, in the same transaction.
pub async fn enqueue_fingerprint_locked(
    conn: &mut MySqlConnection,
    release_id: i64,
    digest: &str,
    force: bool,
) -> Result<()> {
    let job: Option<(String, String)> = sqlx::query_as(
        "SELECT source_digest, status FROM release_fingerprint_jobs WHERE release_id = ? FOR UPDATE",
    )
    .bind(release_id)
    .fetch_optional(&mut *conn)
    .await?;
    if let Some((source, status)) = &job
        && source == digest
        && (!force || matches!(status.as_str(), "pending" | "processing"))
    {
        return Ok(());
    }
    let now = Utc::now().naive_utc();
    if job.is_none() {
        sqlx::query(
            "INSERT INTO release_fingerprint_jobs (release_id, created_at, source_digest, status, attempts, \
             available_at, updated_at) VALUES (?, ?, ?, 'pending', 0, UTC_TIMESTAMP(6), ?)",
        )
        .bind(release_id)
        .bind(now)
        .bind(digest)
        .bind(now)
        .execute(&mut *conn)
        .await?;
    } else {
        sqlx::query(
            "UPDATE release_fingerprint_jobs SET source_digest = ?, status = 'pending', attempts = 0, \
             available_at = UTC_TIMESTAMP(6), lease_token = NULL, lease_until = NULL, worker_id = NULL, \
             last_error = NULL, updated_at = ? WHERE release_id = ?",
        )
        .bind(digest)
        .bind(now)
        .bind(release_id)
        .execute(&mut *conn)
        .await?;
    }
    sqlx::query(
        "UPDATE release_fingerprint_metadata SET status = 'stale', run_token = NULL, updated_at = ? \
         WHERE releases_id = ?",
    )
    .bind(now)
    .bind(release_id)
    .execute(&mut *conn)
    .await?;
    Ok(())
}

/// Queues fingerprint work for a release's stored NZB. False when it has none.
pub async fn enqueue_fingerprint(pool: &MySqlPool, release_id: i64, force: bool) -> Result<bool> {
    in_tx!(pool, 1, |tx| {
        let guid: Option<String> =
            sqlx::query_scalar("SELECT guid FROM releases WHERE id = ? FOR UPDATE")
                .bind(release_id)
                .fetch_optional(&mut *tx)
                .await?;
        let Some(guid) = guid else {
            return Ok::<bool, anyhow::Error>(false);
        };
        let digest: Option<String> = sqlx::query_scalar(
            "SELECT source_digest FROM release_nzb_contents WHERE guid = ? FOR UPDATE",
        )
        .bind(&guid)
        .fetch_optional(&mut *tx)
        .await?;
        let Some(digest) = digest else {
            return Ok(false);
        };
        enqueue_fingerprint_locked(tx, release_id, &digest, force).await?;
        Ok(true)
    })
}

/// Final source deletion uses the same release-to-source order as installation. False when the
/// release is gone.
pub async fn delete(pool: &MySqlPool, guid: &str) -> Result<bool> {
    in_tx!(pool, 1, |tx| {
        let id: Option<u32> =
            sqlx::query_scalar("SELECT id FROM releases WHERE guid = ? FOR UPDATE")
                .bind(guid)
                .fetch_optional(&mut *tx)
                .await?;
        let Some(id) = id else {
            return Ok::<bool, anyhow::Error>(false);
        };
        sqlx::query("DELETE FROM release_nzb_contents WHERE guid = ?")
            .bind(guid)
            .execute(&mut *tx)
            .await?;
        sqlx::query("DELETE FROM release_fingerprint_jobs WHERE release_id = ?")
            .bind(id)
            .execute(&mut *tx)
            .await?;
        Ok(true)
    })
}
