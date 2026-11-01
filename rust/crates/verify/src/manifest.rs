//! Content-addressed candidate manifests: gzipped JSON named by the SHA-256 of the JSON.
//!
//! Manifests are rows in `release_verification_manifests`, so every worker host can read any
//! candidate's manifest and one written inside an admission transaction commits or rolls back with
//! it. Manifests written by the PHP app before it moved to rows stay readable from their legacy
//! files until `verification:import-manifests` copies them in.

use std::io::{Read, Write};
use std::path::PathBuf;

use anyhow::{Context, Result, anyhow, bail};
use flate2::Compression;
use flate2::bufread::GzDecoder;
use flate2::write::GzEncoder;
use serde::ser::Serialize;
use serde::{Deserialize, Deserializer};
use serde_json::ser::{CharEscape, Formatter, Serializer};
use sha2::{Digest, Sha256};
use sqlx::{MySqlConnection, MySqlPool};

use crate::config::VerificationConfig;
use crate::in_tx;
use crate::txn::is_unique_violation;

const INTEGRITY: &str = "Manifest integrity check failed.";

#[derive(Clone, Debug, PartialEq, Eq, serde::Serialize, Deserialize)]
pub struct Segment {
    pub number: i64,
    pub messageid: String,
    pub bytes: i64,
}

#[derive(Clone, Debug, PartialEq, Eq, serde::Serialize, Deserialize)]
pub struct ManifestFile {
    pub key: String,
    pub subject: String,
    pub totalparts: i64,
    #[serde(default, skip_serializing_if = "std::ops::Not::not")]
    pub totalparts_inferred: bool,
    pub segments: Vec<Segment>,
}

/// One upload's header inventory. Field order is the JSON order, so a manifest built from a
/// collection hashes to the name the PHP app gave the same headers.
#[derive(Clone, Debug, Default, PartialEq, Eq, serde::Serialize, Deserialize)]
pub struct Manifest {
    #[serde(default)]
    pub legacy_release_id: Option<i64>,
    #[serde(default)]
    pub identity: String,
    #[serde(default, deserialize_with = "null_as_empty")]
    pub subject: String,
    #[serde(default, deserialize_with = "null_as_empty")]
    pub fromname: String,
    #[serde(default)]
    pub date: Option<String>,
    #[serde(default)]
    pub groups_id: i64,
    #[serde(default)]
    pub regex_id: i64,
    #[serde(default)]
    pub expected_files: i64,
    #[serde(default)]
    pub groups: Vec<String>,
    #[serde(default)]
    pub files: Vec<ManifestFile>,
    #[serde(default)]
    pub conflict: bool,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub metadata_source: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub passwords: Option<Vec<String>>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub verification_mode: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub categories_id: Option<i64>,
    #[serde(default, skip_serializing_if = "std::ops::Not::not")]
    pub import_name_override: bool,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub original_subject: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub upgrades_release_id: Option<i64>,
    /// What a spot check adds on top of the archived inventory. None of this is stored with it.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub spot_check: Option<SpotCheck>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub declared_size: Option<i64>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub publication_size: Option<i64>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub publication_file_count: Option<i64>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub verified_file_names: Option<Vec<String>>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub verified_file_count: Option<i64>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub identity_hints: Option<Vec<IdentityHint>>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub partial: Option<crate::inventory::Completeness>,
}

/// The spot check a sampled result records.
#[derive(Clone, Debug, Default, PartialEq, Eq, serde::Serialize, Deserialize)]
pub struct SpotCheck {
    pub articles: i64,
    pub bytes: i64,
    pub sampled_files: i64,
    pub method: String,
    #[serde(default, skip_serializing_if = "Vec::is_empty")]
    pub parity_file_keys: Vec<String>,
}

/// A filename read from a sampled article.
#[derive(Clone, Debug, PartialEq, Eq, serde::Serialize, Deserialize)]
pub struct IdentityHint {
    pub name: String,
    #[serde(default)]
    pub source: String,
}

/// The sampled evidence a remote worker sends instead of a whole manifest.
#[derive(Clone, Debug, Default, PartialEq, Eq, serde::Serialize, Deserialize)]
pub struct Evidence {
    pub articles: i64,
    pub bytes: i64,
    pub sampled_files: i64,
    pub verified_file_names: Vec<String>,
    pub identity_hints: Vec<String>,
    #[serde(default)]
    pub partial: bool,
    #[serde(default, skip_serializing_if = "Vec::is_empty")]
    pub parity_file_keys: Vec<String>,
}

fn null_as_empty<'de, D: Deserializer<'de>>(deserializer: D) -> Result<String, D::Error> {
    Ok(Option::<String>::deserialize(deserializer)?.unwrap_or_default())
}

impl Manifest {
    pub fn passwords(&self) -> &[String] {
        self.passwords.as_deref().unwrap_or_default()
    }

    /// Header metadata this manifest holds, as the budget counts it.
    pub fn metadata_bytes(&self) -> i64 {
        self.files
            .iter()
            .flat_map(|file| &file.segments)
            .map(|segment| 128 + segment.messageid.len() as i64)
            .sum()
    }

    /// A stable identity for the articles this manifest names.
    pub fn article_identity(&self) -> String {
        let mut ids: Vec<String> = self
            .files
            .iter()
            .flat_map(|file| {
                file.segments.iter().map(|part| {
                    format!(
                        "{}:{}:{}:{}",
                        file.key, part.number, part.messageid, part.bytes
                    )
                })
            })
            .collect();
        ids.sort();
        hex::encode(Sha256::digest(ids.join("\n")))
    }
}

/// `App\Support\NzbPasswordMetadata::isValid`.
pub fn valid_passwords(values: &[String]) -> bool {
    values.len() <= 64
        && values.iter().all(|value| {
            value.len() <= 4096
                && value.chars().all(|c| {
                    matches!(c, '\u{9}' | '\u{A}' | '\u{D}' | '\u{20}'..='\u{D7FF}'
                        | '\u{E000}'..='\u{FFFD}' | '\u{10000}'..='\u{10FFFF}')
                })
        })
}

/// Writes JSON the way PHP's `json_encode` does by default: `/` and non-ASCII are escaped.
struct PhpFormatter;

impl Formatter for PhpFormatter {
    fn write_string_fragment<W: ?Sized + Write>(
        &mut self,
        writer: &mut W,
        fragment: &str,
    ) -> std::io::Result<()> {
        let mut units = [0u16; 2];
        for c in fragment.chars() {
            match c {
                '/' => writer.write_all(b"\\/")?,
                c if c.is_ascii() => writer.write_all(&[c as u8])?,
                c => {
                    for unit in c.encode_utf16(&mut units) {
                        write!(writer, "\\u{unit:04x}")?;
                    }
                }
            }
        }
        Ok(())
    }

    fn write_char_escape<W: ?Sized + Write>(
        &mut self,
        writer: &mut W,
        escape: CharEscape,
    ) -> std::io::Result<()> {
        match escape {
            CharEscape::Quote => writer.write_all(b"\\\""),
            CharEscape::ReverseSolidus => writer.write_all(b"\\\\"),
            CharEscape::Solidus => writer.write_all(b"\\/"),
            CharEscape::Backspace => writer.write_all(b"\\b"),
            CharEscape::FormFeed => writer.write_all(b"\\f"),
            CharEscape::LineFeed => writer.write_all(b"\\n"),
            CharEscape::CarriageReturn => writer.write_all(b"\\r"),
            CharEscape::Tab => writer.write_all(b"\\t"),
            CharEscape::AsciiControl(byte) => write!(writer, "\\u{byte:04x}"),
        }
    }
}

/// `json_encode` of a manifest.
pub fn php_json(manifest: &Manifest) -> Result<Vec<u8>> {
    let mut out = Vec::new();
    let mut serializer = Serializer::with_formatter(&mut out, PhpFormatter);
    manifest
        .serialize(&mut serializer)
        .context("encoding a candidate manifest")?;
    Ok(out)
}

/// Bytes prepared without storing anything or reserving shared capacity.
#[derive(Clone, Debug)]
pub struct PreparedManifest {
    pub name: String,
    pub compressed: Vec<u8>,
    pub json_bytes: usize,
}

impl PreparedManifest {
    pub fn new(manifest: &Manifest) -> Result<Self> {
        if !valid_passwords(manifest.passwords()) {
            bail!("Invalid candidate password metadata.");
        }
        let body = php_json(manifest)?;
        Ok(Self {
            name: format!("{}.json.gz", hex::encode(Sha256::digest(&body))),
            compressed: gzip(&body)?,
            json_bytes: body.len(),
        })
    }
}

fn gzip(body: &[u8]) -> Result<Vec<u8>> {
    let mut encoder = GzEncoder::new(Vec::new(), Compression::default());
    encoder.write_all(body)?;
    Ok(encoder.finish()?)
}

/// Decodes exactly one gzip member, accepting no trailing bytes and at most `limit` bytes of output.
fn gunzip_exact(body: &[u8], limit: usize) -> Option<Vec<u8>> {
    let mut decoder = GzDecoder::new(body);
    let mut out = Vec::new();
    (&mut decoder)
        .take(limit as u64 + 1)
        .read_to_end(&mut out)
        .ok()?;
    if out.len() > limit || !decoder.into_inner().is_empty() {
        return None;
    }
    Some(out)
}

fn assert_name(name: &str) -> Result<()> {
    let valid = name.strip_suffix(".json.gz").is_some_and(|hash| {
        hash.len() == 64 && hash.bytes().all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f'))
    });
    if !valid {
        bail!("Invalid manifest reference.");
    }
    Ok(())
}

fn valid_body(name: &str, body: &[u8]) -> bool {
    gunzip_exact(body, usize::MAX - 1)
        .is_some_and(|json| format!("{}.json.gz", hex::encode(Sha256::digest(&json))) == name)
}

/// The legacy file a manifest was written to before manifests moved to rows.
pub fn legacy_path(config: &VerificationConfig, name: &str) -> Result<PathBuf> {
    assert_name(name)?;
    Ok(config.storage_path.join("manifests").join(name))
}

/// Inserts the manifest row, or validates the row already stored under its name. Inside a
/// transaction the row is part of it, so a rollback leaves nothing behind.
pub async fn write_prepared(
    conn: &mut MySqlConnection,
    prepared: &PreparedManifest,
) -> Result<String> {
    let name = &prepared.name;
    // Insert a name this transaction's view lacks without a locking read first: locking an absent
    // key gap-locks the primary index, and concurrent admissions inserting into one gap deadlock.
    let exists: Option<i32> =
        sqlx::query_scalar("SELECT 1 FROM release_verification_manifests WHERE name = ?")
            .bind(name)
            .fetch_optional(&mut *conn)
            .await?;
    if exists.is_none() && insert_row(conn, prepared).await? {
        return Ok(name.clone());
    }
    // A current read: a plain read could still see a row that cleanup deleted after this
    // transaction's snapshot, and admission would then reference nothing.
    let mut existing = lock_body(conn, name).await?;
    if existing.is_none() {
        if insert_row(conn, prepared).await? {
            return Ok(name.clone());
        }
        existing = lock_body(conn, name).await?;
    }
    let Some(existing) = existing else {
        bail!(INTEGRITY);
    };
    validate_occupied(&existing, prepared)?;
    Ok(name.clone())
}

async fn insert_row(conn: &mut MySqlConnection, prepared: &PreparedManifest) -> Result<bool> {
    let inserted = sqlx::query(
        "INSERT INTO release_verification_manifests (name, body, bytes) VALUES (?, ?, ?)",
    )
    .bind(&prepared.name)
    .bind(&prepared.compressed)
    .bind(prepared.compressed.len() as u64)
    .execute(&mut *conn)
    .await;
    match inserted {
        Ok(_) => Ok(true),
        Err(error) if is_unique_violation(&error) => Ok(false),
        Err(error) => Err(error.into()),
    }
}

async fn lock_body(conn: &mut MySqlConnection, name: &str) -> Result<Option<Vec<u8>>> {
    Ok(sqlx::query_scalar(
        "SELECT body FROM release_verification_manifests WHERE name = ? FOR UPDATE",
    )
    .bind(name)
    .fetch_optional(&mut *conn)
    .await?)
}

/// Allows alternate compression and bounded optional gzip headers.
fn validate_occupied(body: &[u8], prepared: &PreparedManifest) -> Result<()> {
    if body.len() > prepared.json_bytes * 2 + 65_536 {
        bail!(INTEGRITY);
    }
    if body == prepared.compressed {
        return Ok(());
    }
    let valid = gunzip_exact(body, prepared.json_bytes).is_some_and(|json| {
        json.len() == prepared.json_bytes
            && format!("{}.json.gz", hex::encode(Sha256::digest(&json))) == prepared.name
    });
    if !valid {
        bail!(INTEGRITY);
    }
    Ok(())
}

/// Reads a manifest outside any transaction.
pub async fn read(pool: &MySqlPool, config: &VerificationConfig, name: &str) -> Result<Manifest> {
    assert_name(name)?;
    let mut body: Option<Vec<u8>> =
        sqlx::query_scalar("SELECT body FROM release_verification_manifests WHERE name = ?")
            .bind(name)
            .fetch_optional(pool)
            .await?;
    if body.is_none() {
        body = read_legacy_file(config, name)?;
    }
    if body.is_none() {
        // The importer commits a row before deleting its file, so a file that vanished between
        // the two lookups is now readable from the database.
        body = sqlx::query_scalar("SELECT body FROM release_verification_manifests WHERE name = ?")
            .bind(name)
            .fetch_optional(pool)
            .await?;
    }
    decode(name, body)
}

/// Reads a manifest inside a transaction: the committed row rather than the snapshot, because the
/// importer may have moved it from its legacy file after the snapshot was taken.
pub async fn read_current(
    conn: &mut MySqlConnection,
    config: &VerificationConfig,
    name: &str,
) -> Result<Manifest> {
    assert_name(name)?;
    let sql = "SELECT body FROM release_verification_manifests WHERE name = ? LOCK IN SHARE MODE";
    let mut body: Option<Vec<u8>> = sqlx::query_scalar(sql)
        .bind(name)
        .fetch_optional(&mut *conn)
        .await?;
    if body.is_none() {
        body = read_legacy_file(config, name)?;
    }
    if body.is_none() {
        body = sqlx::query_scalar(sql)
            .bind(name)
            .fetch_optional(&mut *conn)
            .await?;
    }
    decode(name, body)
}

fn read_legacy_file(config: &VerificationConfig, name: &str) -> Result<Option<Vec<u8>>> {
    match std::fs::read(legacy_path(config, name)?) {
        Ok(body) => Ok(Some(body)),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(None),
        Err(error) => Err(error.into()),
    }
}

fn decode(name: &str, body: Option<Vec<u8>>) -> Result<Manifest> {
    let body = body.ok_or_else(|| anyhow!(INTEGRITY))?;
    // No limit beyond memory: the bytes were validated against the budget when admitted.
    let mut json = Vec::new();
    let mut decoder = GzDecoder::new(body.as_slice());
    decoder
        .read_to_end(&mut json)
        .map_err(|_| anyhow!(INTEGRITY))?;
    if format!("{}.json.gz", hex::encode(Sha256::digest(&json))) != name {
        bail!(INTEGRITY);
    }
    serde_json::from_slice(&json).context("Invalid manifest contents.")
}

/// Stored (compressed) size of a manifest, as counted against pending capacity.
pub async fn size(
    conn: &mut MySqlConnection,
    config: &VerificationConfig,
    name: &str,
) -> Result<i64> {
    assert_name(name)?;
    let bytes: Option<u32> = sqlx::query_scalar(
        "SELECT bytes FROM release_verification_manifests WHERE name = ? FOR UPDATE",
    )
    .bind(name)
    .fetch_optional(&mut *conn)
    .await?;
    if let Some(bytes) = bytes {
        return Ok(i64::from(bytes));
    }
    let metadata = std::fs::metadata(legacy_path(config, name)?)
        .map_err(|_| anyhow!("Cannot reserve manifest storage."))?;
    Ok(metadata.len() as i64)
}

/// Serializes deletion with admissions and keeps every manifest a candidate still references.
///
/// Locks the manifest row (or its gap) first. Every admission that adds a reference writes that
/// row under the same lock, so none can add one until this commits, and the reference check takes
/// its read view only after the lock is granted, so it sees every committed reference without
/// locking candidates out of admission's candidate-then-manifest order.
///
/// Call this after the transaction that dropped the reference has finished.
pub async fn delete_if_unreferenced(
    pool: &MySqlPool,
    config: &VerificationConfig,
    name: &str,
) -> Result<()> {
    if name.is_empty() {
        return Ok(());
    }
    assert_name(name)?;
    in_tx!(pool, 5, |tx| {
        sqlx::query_scalar::<_, String>(
            "SELECT name FROM release_verification_manifests WHERE name = ? FOR UPDATE",
        )
        .bind(name)
        .fetch_optional(&mut *tx)
        .await?;
        let referenced: Option<i32> = sqlx::query_scalar(
            "SELECT 1 FROM release_verification_candidates WHERE manifest_path = ? LIMIT 1",
        )
        .bind(name)
        .fetch_optional(&mut *tx)
        .await?;
        if referenced.is_some() {
            return Ok::<(), anyhow::Error>(());
        }
        sqlx::query("DELETE FROM release_verification_manifests WHERE name = ?")
            .bind(name)
            .execute(&mut *tx)
            .await?;
        match std::fs::remove_file(legacy_path(config, name)?) {
            Ok(()) => Ok(()),
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
            Err(error) => Err(error.into()),
        }
    })
}

/// What `verification:import-manifests` reports.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct ImportCounts {
    pub imported: u64,
    pub already: u64,
    pub missing: u64,
    pub invalid: u64,
    pub conflict: u64,
    pub unreferenced: u64,
}

impl ImportCounts {
    pub fn add(&mut self, other: Self) {
        self.imported += other.imported;
        self.already += other.already;
        self.missing += other.missing;
        self.invalid += other.invalid;
        self.conflict += other.conflict;
        self.unreferenced += other.unreferenced;
    }
}

/// Import chunk bounds: names per transaction, and file bytes held in memory at once.
const IMPORT_CHUNK_NAMES: usize = 50;
const IMPORT_CHUNK_BYTES: usize = 33_554_432;

enum LegacyFile {
    Missing,
    Invalid,
    Valid(Vec<u8>),
}

/// Copies legacy manifest files into the database. Each file must decode to its name; rows are
/// inserted only while a candidate still references the name, so a concurrent cleanup can't be
/// undone. A file is removed only after a committed row for its name has itself been validated.
pub async fn import_legacy_files(
    pool: &MySqlPool,
    config: &VerificationConfig,
    names: &[String],
    delete_files: bool,
) -> Result<ImportCounts> {
    let mut counts = ImportCounts::default();
    let mut unique: Vec<&String> = Vec::with_capacity(names.len());
    for name in names {
        if !unique.contains(&name) {
            unique.push(name);
        }
    }
    let mut chunk: Vec<(String, LegacyFile)> = Vec::new();
    let mut chunk_bytes = 0;
    for name in unique {
        if assert_name(name).is_err() {
            // A malformed reference can't name a file or row; count it and keep importing.
            counts.invalid += 1;
            continue;
        }
        let file = match std::fs::read(legacy_path(config, name)?) {
            Ok(body) if valid_body(name, &body) => {
                chunk_bytes += body.len();
                LegacyFile::Valid(body)
            }
            Ok(_) => LegacyFile::Invalid,
            Err(_) => LegacyFile::Missing,
        };
        chunk.push((name.clone(), file));
        if chunk.len() >= IMPORT_CHUNK_NAMES || chunk_bytes >= IMPORT_CHUNK_BYTES {
            counts.add(import_chunk(pool, config, &chunk, delete_files).await?);
            chunk.clear();
            chunk_bytes = 0;
        }
    }
    if !chunk.is_empty() {
        counts.add(import_chunk(pool, config, &chunk, delete_files).await?);
    }
    Ok(counts)
}

/// Imports one bounded chunk in one transaction, one row per statement to stay under the packet
/// limit. Locking the referencing candidates and then each manifest row follows admission's order.
async fn import_chunk(
    pool: &MySqlPool,
    config: &VerificationConfig,
    files: &[(String, LegacyFile)],
    delete_files: bool,
) -> Result<ImportCounts> {
    let placeholders = vec!["?"; files.len()].join(",");
    let sql = format!(
        "SELECT manifest_path FROM release_verification_candidates \
         WHERE manifest_path IN ({placeholders}) ORDER BY id FOR UPDATE"
    );
    let (counts, verified) = in_tx!(pool, 5, |tx| {
        let mut counts = ImportCounts::default();
        let mut verified: Vec<String> = Vec::new();
        let mut query = sqlx::query_scalar::<_, String>(sqlx::AssertSqlSafe(sql.clone()));
        for (name, _) in files {
            query = query.bind(name);
        }
        let referenced = query.fetch_all(&mut *tx).await?;
        for (name, file) in files {
            let stored = lock_body(tx, name).await?;
            match (stored, file) {
                (Some(stored), _) => {
                    if valid_body(name, &stored) {
                        counts.already += 1;
                        verified.push(name.clone());
                    } else {
                        counts.conflict += 1;
                    }
                }
                (None, LegacyFile::Missing) => counts.missing += 1,
                (None, LegacyFile::Invalid) => counts.invalid += 1,
                (None, LegacyFile::Valid(_)) if !referenced.contains(name) => {
                    counts.unreferenced += 1;
                }
                (None, LegacyFile::Valid(body)) => {
                    sqlx::query(
                        "INSERT INTO release_verification_manifests (name, body, bytes) VALUES (?, ?, ?)",
                    )
                    .bind(name)
                    .bind(body)
                    .bind(body.len() as u64)
                    .execute(&mut *tx)
                    .await?;
                    counts.imported += 1;
                    verified.push(name.clone());
                }
            }
        }
        Ok::<_, anyhow::Error>((counts, verified))
    })?;
    if delete_files {
        for name in &verified {
            match std::fs::remove_file(legacy_path(config, name)?) {
                Ok(()) => {}
                Err(error) if error.kind() == std::io::ErrorKind::NotFound => {}
                Err(error) => return Err(error.into()),
            }
        }
    }
    Ok(counts)
}

/// The outcome of `verification:import-manifests`.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct ImportReport {
    pub counts: ImportCounts,
    pub last_candidate_id: i64,
}

/// Copies the legacy files candidates still reference into the database, a page of candidates at a time.
pub async fn import_referenced(
    pool: &MySqlPool,
    config: &VerificationConfig,
    batch: i64,
    limit: i64,
    since_id: i64,
    delete_files: bool,
) -> Result<ImportReport> {
    let mut report = ImportReport {
        last_candidate_id: since_id,
        ..ImportReport::default()
    };
    let mut seen = 0;
    loop {
        let mut rows: Vec<(u64, String)> = sqlx::query_as(
            "SELECT id, manifest_path FROM release_verification_candidates WHERE id > ? \
             AND manifest_path <> '' ORDER BY id LIMIT ?",
        )
        .bind(report.last_candidate_id)
        .bind(batch)
        .fetch_all(pool)
        .await?;
        let full_page = rows.len() as i64 == batch;
        if limit > 0 && seen + rows.len() as i64 > limit {
            rows.truncate((limit - seen) as usize);
        }
        let Some(last) = rows.last() else {
            return Ok(report);
        };
        report.last_candidate_id = last.0 as i64;
        seen += rows.len() as i64;
        let names: Vec<String> = rows.into_iter().map(|(_, name)| name).collect();
        report
            .counts
            .add(import_legacy_files(pool, config, &names, delete_files).await?);
        if !full_page || (limit > 0 && seen >= limit) {
            return Ok(report);
        }
    }
}
