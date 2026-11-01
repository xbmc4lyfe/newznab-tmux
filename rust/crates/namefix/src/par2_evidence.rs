//! `Par2FileHashEvidence` and `Par2DescriptorEvidence`: the content hashes and descriptor
//! packets a PAR2 inventory proves about a release, kept only while the release still has the
//! identity they were collected for.
//!
//! Every function takes the caller's connection and expects it to be inside the transaction
//! that holds the release row lock; a `false` or an `Aborted` error means that transaction
//! must roll back. The `*_in_tx` wrappers open one for callers without their own.

use anyhow::Result;
use base64::Engine as _;
use base64::engine::general_purpose::STANDARD;
use sqlx::{MySqlConnection, MySqlPool, Row};

use crate::par2_accumulator::Descriptor;
use crate::par2_budget::size_decimal;
use crate::par2_support::{Guard, LockedRelease, aborted, absorb_abort};

pub const RETRY_DELAY_SECONDS: i64 = 60;
pub const INVENTORY_PROOF_VERSION: i64 = 1;
/// Validated descriptor provenance only; this version makes no eligibility claim.
pub const DESCRIPTOR_PROOF_VERSION: i64 = 1;

/// The identity evidence is collected under. `proc_hash16k` is checked only when set.
#[derive(Clone, Debug, PartialEq)]
pub struct EvidenceSnapshot {
    pub id: i64,
    pub guid: String,
    pub name: Option<String>,
    pub searchname: Option<String>,
    pub predb_id: i64,
    pub isrenamed: i64,
    pub proc_hash16k: Option<i64>,
}

impl EvidenceSnapshot {
    /// The snapshot of an unlinked, unrenamed release, which is what PAR2 evidence needs.
    pub fn unlinked(release: &LockedRelease) -> Self {
        Self {
            id: release.id,
            guid: release.guid.clone(),
            name: release.name.clone(),
            searchname: release.searchname.clone(),
            predb_id: 0,
            isrenamed: 0,
            proc_hash16k: None,
        }
    }

    /// `Par2FileHashEvidence::snapshotMatches`.
    pub fn matches(&self, current: Option<&LockedRelease>) -> bool {
        let Some(current) = current else {
            return false;
        };
        !self.guid.is_empty()
            && current.id == self.id
            && current.guid == self.guid
            && current.name == self.name
            && current.searchname == self.searchname
            && current.predb_id == self.predb_id
            && current.isrenamed == self.isrenamed
            && self.proc_hash16k.is_none_or(|value| current.proc_hash16k == value)
    }
}

/// `littleEndianSize` for the eight raw bytes of a FileDesc, as the decimal string.
pub fn little_endian_size(bytes: &[u8]) -> Result<String> {
    if bytes.len() != 8 {
        anyhow::bail!("A PAR2 file size requires eight bytes.");
    }
    Ok(u64::from_le_bytes(bytes.try_into().expect("eight")).to_string())
}

fn is_hex32(value: &str) -> bool {
    value.len() == 32 && value.bytes().all(|b| b.is_ascii_hexdigit())
}

/// `Par2FileHashEvidence::remember`: one file's full MD5 and 16 KiB hash, with its exact size
/// when known. An empty size marks a digest whose size can't be represented exactly.
pub async fn remember_hash(
    conn: &mut MySqlConnection,
    release_id: i64,
    full_md5: &str,
    hash16k: &str,
    size: Option<&str>,
    expected: &EvidenceSnapshot,
    guard: Option<&dyn Guard>,
) -> Result<bool> {
    if expected.id != release_id
        || expected.guid.is_empty()
        || !is_hex32(full_md5)
        || !is_hex32(hash16k)
    {
        return Ok(false);
    }
    absorb_abort(
        remember_hash_locked(conn, release_id, full_md5, hash16k, size, expected, guard).await,
    )
}

async fn remember_hash_locked(
    conn: &mut MySqlConnection,
    release_id: i64,
    full_md5: &str,
    hash16k: &str,
    size: Option<&str>,
    expected: &EvidenceSnapshot,
    guard: Option<&dyn Guard>,
) -> Result<bool> {
    let current = LockedRelease::lock(conn, release_id).await?;
    let Some(current) = current else {
        return Err(aborted("PAR2 file evidence ownership changed."));
    };
    if current.guid.is_empty() || !guard_holds(guard).await {
        return Err(aborted("PAR2 file evidence ownership changed."));
    }
    if !expected.matches(Some(&current)) {
        return Err(aborted("PAR2 file evidence ownership changed."));
    }
    let (hash16k, full_md5) = (hash16k.to_ascii_lowercase(), full_md5.to_ascii_lowercase());
    let mut proc_hash16k = expected.proc_hash16k;
    let stale = sqlx::query(
        "DELETE FROM par_file_hashes WHERE releases_id = ? AND HEX(release_guid) <> HEX(?)",
    )
    .bind(release_id)
    .bind(&current.guid)
    .execute(&mut *conn)
    .await?
    .rows_affected();
    let exact = size.and_then(size_decimal).unwrap_or_default();
    let mut repaired = 0;
    if !exact.is_empty() {
        repaired = sqlx::query(
            "DELETE FROM par_file_hashes WHERE releases_id = ? AND release_guid = ? \
             AND hash16k = ? AND full_md5 = ? AND file_size = ''",
        )
        .bind(release_id)
        .bind(&current.guid)
        .bind(&hash16k)
        .bind(&full_md5)
        .execute(&mut *conn)
        .await?
        .rows_affected();
    }
    let has_exact = exact.is_empty()
        && sqlx::query_scalar::<_, i64>(
            "SELECT EXISTS(SELECT 1 FROM par_file_hashes WHERE releases_id = ? \
             AND HEX(release_guid) = HEX(?) AND hash16k = ? AND full_md5 = ? AND file_size <> '')",
        )
        .bind(release_id)
        .bind(&current.guid)
        .bind(&hash16k)
        .bind(&full_md5)
        .fetch_one(&mut *conn)
        .await?
            == 1;
    let inserted = !has_exact
        && sqlx::query(
            "INSERT IGNORE INTO par_file_hashes (releases_id, release_guid, hash16k, full_md5, file_size) \
             VALUES (?, ?, ?, ?, ?)",
        )
        .bind(release_id)
        .bind(&current.guid)
        .bind(&hash16k)
        .bind(&full_md5)
        .bind(&exact)
        .execute(&mut *conn)
        .await?
        .rows_affected()
            > 0;
    if stale > 0 || repaired > 0 || inserted {
        sqlx::query("DELETE FROM par_file_hash_inventories WHERE releases_id = ?")
            .bind(release_id)
            .execute(&mut *conn)
            .await?;
    }
    let known: i64 = sqlx::query_scalar(
        "SELECT EXISTS(SELECT 1 FROM par_hashes WHERE releases_id = ? AND hash = ?)",
    )
    .bind(release_id)
    .bind(&hash16k)
    .fetch_one(&mut *conn)
    .await?;
    if known != 1 {
        sqlx::query("INSERT IGNORE INTO par_hashes (releases_id, hash) VALUES (?, ?)")
            .bind(release_id)
            .bind(&hash16k)
            .execute(&mut *conn)
            .await?;
    }
    if (inserted || repaired > 0) && current.predb_id == 0 && matches!(current.isrenamed, 0 | 1) {
        sqlx::query(
            "UPDATE releases SET proc_hash16k = 0 WHERE id = ? AND predb_id = 0 AND isrenamed = ? \
             AND HEX(guid) = HEX(?)",
        )
        .bind(release_id)
        .bind(current.isrenamed)
        .bind(&current.guid)
        .execute(&mut *conn)
        .await?;
        sqlx::query("DELETE FROM par2_hash_retry WHERE releases_id = ?")
            .bind(release_id)
            .execute(&mut *conn)
            .await?;
        if proc_hash16k.is_some() {
            proc_hash16k = Some(0);
        }
    }
    if !guard_holds(guard).await {
        return Err(aborted("PAR2 file evidence ownership changed."));
    }
    let Some(after) = LockedRelease::lock(conn, release_id).await? else {
        return Err(aborted("PAR2 file evidence ownership changed."));
    };
    let unchanged = after.guid == current.guid
        && after.name == current.name
        && after.searchname == current.searchname
        && after.predb_id == current.predb_id
        && after.isrenamed == current.isrenamed
        && proc_hash16k.is_none_or(|value| after.proc_hash16k == value);
    if !unchanged {
        return Err(aborted("PAR2 file evidence ownership changed."));
    }
    Ok(true)
}

async fn guard_holds(guard: Option<&dyn Guard>) -> bool {
    match guard {
        Some(guard) => guard.holds().await,
        None => true,
    }
}

/// `inventoryEvidenceMatches`: the stored hashes are exactly the inventory's files. A complete
/// current set must not certify descriptors retained from a different, still partial set.
pub async fn inventory_evidence_matches(
    conn: &mut MySqlConnection,
    release_id: i64,
    guid: &str,
    files: &[InventoryFile],
) -> Result<bool> {
    let mut expected: Vec<String> = Vec::new();
    for file in files {
        let (Some(hash), Some(hash16k)) = (&file.hash, &file.hash_16k) else {
            return Ok(false);
        };
        let Some(size) = file.size_decimal.as_deref().and_then(size_decimal) else {
            return Ok(false);
        };
        let key = format!("{}:{}:{size}", hash16k.to_lowercase(), hash.to_lowercase());
        if !expected.contains(&key) {
            expected.push(key);
        }
    }
    if expected.is_empty() {
        return Ok(false);
    }
    let rows = sqlx::query(
        "SELECT hash16k, full_md5, file_size FROM par_file_hashes WHERE releases_id = ? AND HEX(release_guid) = HEX(?)",
    )
    .bind(release_id)
    .bind(guid)
    .fetch_all(conn)
    .await?;
    if rows.len() != expected.len() {
        return Ok(false);
    }
    for row in rows {
        let key = format!(
            "{}:{}:{}",
            row.try_get::<String, _>("hash16k")?,
            row.try_get::<String, _>("full_md5")?,
            row.try_get::<String, _>("file_size")?
        );
        match expected.iter().position(|known| *known == key) {
            Some(at) => {
                expected.swap_remove(at);
            }
            None => return Ok(false),
        }
    }
    Ok(expected.is_empty())
}

/// A file as the inventory comparison needs it.
#[derive(Clone, Debug, Default)]
pub struct InventoryFile {
    pub hash: Option<String>,
    pub hash_16k: Option<String>,
    pub size_decimal: Option<String>,
}

/// `markInventoryComplete`: records that the stored hashes are the release's whole inventory.
pub async fn mark_inventory_complete(
    conn: &mut MySqlConnection,
    release_id: i64,
    expected: &EvidenceSnapshot,
    guard: Option<&dyn Guard>,
) -> Result<bool> {
    absorb_abort(mark_inventory_locked(conn, release_id, expected, guard).await)
}

async fn mark_inventory_locked(
    conn: &mut MySqlConnection,
    release_id: i64,
    expected: &EvidenceSnapshot,
    guard: Option<&dyn Guard>,
) -> Result<bool> {
    let current = LockedRelease::lock(conn, release_id).await?;
    if !expected.matches(current.as_ref()) || !guard_holds(guard).await {
        return Err(aborted("PAR2 inventory completeness ownership changed."));
    }
    let current = current.expect("matched");
    let (count, last): (i64, i64) = sqlx::query_as(
        "SELECT COUNT(*), CAST(COALESCE(MAX(id), 0) AS SIGNED) FROM par_file_hashes \
         WHERE releases_id = ? AND HEX(release_guid) = HEX(?)",
    )
    .bind(release_id)
    .bind(&current.guid)
    .fetch_one(&mut *conn)
    .await?;
    if count == 0 {
        return Ok(false);
    }
    sqlx::query(
        "INSERT INTO par_file_hash_inventories (releases_id, release_guid, evidence_last_id, evidence_count, proof_version) \
         VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE release_guid = VALUES(release_guid), \
         evidence_last_id = VALUES(evidence_last_id), evidence_count = VALUES(evidence_count), \
         proof_version = VALUES(proof_version)",
    )
    .bind(release_id)
    .bind(&current.guid)
    .bind(last)
    .bind(count)
    .bind(INVENTORY_PROOF_VERSION)
    .execute(&mut *conn)
    .await?;
    let after = LockedRelease::lock(conn, release_id).await?;
    if !guard_holds(guard).await || !expected.matches(after.as_ref()) {
        return Err(aborted("PAR2 inventory completeness ownership changed."));
    }
    Ok(true)
}

/// `Par2DescriptorEvidence::remember`: every accepted FileDesc packet's identity and literal
/// name, independent of file collection. The release lock serializes append, replay and GUID
/// replacement, and a checksum identity never overwrites different descriptor bytes.
pub async fn remember_descriptors(
    conn: &mut MySqlConnection,
    descriptors: &[Descriptor],
    snapshot: &EvidenceSnapshot,
    guard: Option<&dyn Guard>,
) -> Result<bool> {
    absorb_abort(remember_descriptors_locked(conn, descriptors, snapshot, guard).await)
}

type DescriptorRow = (String, String, String, String, String, String);

fn descriptor_row(descriptor: &Descriptor) -> DescriptorRow {
    (
        descriptor.packet_checksum.clone(),
        descriptor.recovery_set_id.clone(),
        descriptor.file_id.clone(),
        descriptor.hash.clone(),
        descriptor.hash_16k.clone(),
        format!(
            "{}|{}",
            descriptor.size_decimal,
            STANDARD.encode(&descriptor.filename_bytes)
        ),
    )
}

async fn remember_descriptors_locked(
    conn: &mut MySqlConnection,
    descriptors: &[Descriptor],
    snapshot: &EvidenceSnapshot,
    guard: Option<&dyn Guard>,
) -> Result<bool> {
    let current = LockedRelease::lock(conn, snapshot.id).await?;
    if !snapshot.matches(current.as_ref()) || snapshot.guid.is_empty() || !guard_holds(guard).await
    {
        return Err(aborted("PAR2 descriptor evidence ownership changed."));
    }
    sqlx::query(
        "DELETE FROM par2_descriptor_evidence WHERE releases_id = ? AND HEX(release_guid) <> HEX(?)",
    )
    .bind(snapshot.id)
    .bind(&snapshot.guid)
    .execute(&mut *conn)
    .await?;
    for chunk in descriptors.chunks(25) {
        let mut rows: Vec<(String, &Descriptor)> = Vec::new();
        for descriptor in chunk {
            if !guard_holds(guard).await {
                return Err(aborted("PAR2 descriptor evidence ownership changed."));
            }
            let checksum = descriptor.packet_checksum.clone();
            match rows.iter_mut().find(|(known, _)| *known == checksum) {
                Some((_, kept)) => {
                    if descriptor_row(kept) != descriptor_row(descriptor) {
                        anyhow::bail!("PAR2 descriptor checksum identity contains different evidence.");
                    }
                    *kept = descriptor;
                }
                None => rows.push((checksum, descriptor)),
            }
        }
        let mut lookup = sqlx::QueryBuilder::<sqlx::MySql>::new(
            "SELECT packet_checksum, recovery_set_id, file_id, full_md5, hash16k, file_size, \
             raw_filename_base64, CAST(descriptor_proof_version AS SIGNED) AS descriptor_proof_version, \
             classification, CAST(classification_version AS SIGNED) AS classification_version, \
             classification_provenance FROM par2_descriptor_evidence WHERE releases_id = ",
        );
        lookup
            .push_bind(snapshot.id)
            .push(" AND release_guid = ")
            .push_bind(snapshot.guid.clone())
            .push(" AND HEX(release_guid) = HEX(")
            .push_bind(snapshot.guid.clone())
            .push(") AND packet_checksum IN (");
        let mut separated = lookup.separated(", ");
        for (checksum, _) in &rows {
            separated.push_bind(checksum.clone());
        }
        separated.push_unseparated(")");
        let stored = lookup.build().fetch_all(&mut *conn).await?;
        for row in stored {
            let checksum: String = row.try_get("packet_checksum")?;
            let Some(at) = rows
                .iter()
                .position(|(known, _)| *known == checksum.to_lowercase())
            else {
                anyhow::bail!("PAR2 descriptor checksum identity contains different evidence.");
            };
            let descriptor = rows[at].1;
            let same = checksum == descriptor.packet_checksum
                && row.try_get::<String, _>("recovery_set_id")? == descriptor.recovery_set_id
                && row.try_get::<String, _>("file_id")? == descriptor.file_id
                && row.try_get::<String, _>("full_md5")? == descriptor.hash
                && row.try_get::<String, _>("hash16k")? == descriptor.hash_16k
                && row.try_get::<String, _>("file_size")? == descriptor.size_decimal
                && row.try_get::<String, _>("raw_filename_base64")?
                    == STANDARD.encode(&descriptor.filename_bytes)
                && row.try_get::<i64, _>("descriptor_proof_version")? == DESCRIPTOR_PROOF_VERSION
                && row.try_get::<String, _>("classification")? == "unknown"
                && row.try_get::<i64, _>("classification_version")? == 0
                && row.try_get::<String, _>("classification_provenance")? == "unclassified";
            if !same {
                anyhow::bail!("PAR2 descriptor checksum identity contains different evidence.");
            }
            rows.swap_remove(at);
        }
        for (_, descriptor) in &rows {
            sqlx::query(
                "INSERT INTO par2_descriptor_evidence (releases_id, release_guid, packet_checksum, \
                 recovery_set_id, file_id, full_md5, hash16k, file_size, raw_filename_base64, \
                 descriptor_proof_version, classification, classification_version, classification_provenance) \
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unknown', 0, 'unclassified')",
            )
            .bind(snapshot.id)
            .bind(&snapshot.guid)
            .bind(&descriptor.packet_checksum)
            .bind(&descriptor.recovery_set_id)
            .bind(&descriptor.file_id)
            .bind(&descriptor.hash)
            .bind(&descriptor.hash_16k)
            .bind(&descriptor.size_decimal)
            .bind(STANDARD.encode(&descriptor.filename_bytes))
            .bind(DESCRIPTOR_PROOF_VERSION)
            .execute(&mut *conn)
            .await?;
        }
        let again = LockedRelease::lock(conn, snapshot.id).await?;
        if !guard_holds(guard).await || !snapshot.matches(again.as_ref()) {
            return Err(aborted("PAR2 descriptor evidence ownership changed."));
        }
    }
    let last = LockedRelease::lock(conn, snapshot.id).await?;
    if !guard_holds(guard).await || !snapshot.matches(last.as_ref()) {
        return Err(aborted("PAR2 descriptor evidence ownership changed."));
    }
    Ok(true)
}

/// `remember_hash` in its own transaction, for callers outside one.
pub async fn remember_hash_in_tx(
    db: &MySqlPool,
    release_id: i64,
    full_md5: &str,
    hash16k: &str,
    size: Option<&str>,
    expected: &EvidenceSnapshot,
    guard: Option<&dyn Guard>,
) -> Result<bool> {
    let mut tx = db.begin().await?;
    let done = remember_hash(&mut tx, release_id, full_md5, hash16k, size, expected, guard).await?;
    if done {
        tx.commit().await?;
    }
    Ok(done)
}

/// `remember_descriptors` in its own transaction, for callers outside one.
pub async fn remember_descriptors_in_tx(
    db: &MySqlPool,
    descriptors: &[Descriptor],
    snapshot: &EvidenceSnapshot,
    guard: Option<&dyn Guard>,
) -> Result<bool> {
    let mut tx = db.begin().await?;
    let done = remember_descriptors(&mut tx, descriptors, snapshot, guard).await?;
    if done {
        tx.commit().await?;
    }
    Ok(done)
}
