//! `nntmux-upload.json`: what a staged upload has been through, kept beside its files.
//!
//! An upload's bytes are captured when they're read, and only that exact generation can later be
//! retired. A source is retired by moving it into a private directory and proving it's still that
//! generation before it's unlinked, so a file replaced at the same path is never removed. Uploads
//! that PHP left part way through its longer journal are held for review: drain them before cutover.

use std::fs::{self, File, Metadata, OpenOptions};
use std::io::Write;
use std::os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt};
use std::path::{Path, PathBuf};
use std::time::Duration;

use anyhow::{Context, Result, anyhow, bail};
use chrono::{SecondsFormat, Utc};
use rand::RngCore;
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use sqlx::MySqlPool;

use nntmux_verify::in_tx;
use nntmux_verify::locks::Admission;

pub const FILENAME: &str = "nntmux-upload.json";
const LOCK_FILENAME: &str = ".nntmux-upload.lock";

pub const STAGED: &str = "staged";
pub const NZB_IMPORTED: &str = "nzb_imported";
pub const NZB_DUPLICATE: &str = "nzb_duplicate";
pub const NZB_FAILED: &str = "nzb_failed";
pub const NZB_PENDING: &str = "nzb_pending";
pub const METADATA_REVIEW: &str = "metadata_review";
pub const METADATA_DEFERRED: &str = "metadata_deferred";
pub const NZB_REJECTED: &str = "nzb_rejected";
pub const NFO_IMPORTED: &str = "nfo_imported";
pub const NFO_FAILED: &str = "nfo_failed";

const IMPORTED_STATES: [&str; 3] = [NZB_IMPORTED, NFO_IMPORTED, NFO_FAILED];
/// Fields of PHP's multi-step custody journal. Rust can't resume those steps.
const PHP_JOURNAL: [&str; 4] = [
    "custody_gc",
    "delete_input_pending",
    "delete_terminal_nfo",
    "custody_collision",
];

/// An import's outcome for one NZB.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum ImportStatus {
    Queued,
    MetadataIgnored,
    MetadataDeferred,
    Duplicate,
    Blacklisted,
    NoGroup,
    Failed,
}

impl ImportStatus {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Queued => "queued",
            Self::MetadataIgnored => "metadata_ignored",
            Self::MetadataDeferred => "metadata_deferred",
            Self::Duplicate => "duplicate",
            Self::Blacklisted => "blacklisted",
            Self::NoGroup => "nogroup",
            Self::Failed => "failed",
        }
    }
}

/// The candidate revision an admission accepted, as the manifest records it.
#[derive(Clone, Debug)]
pub struct AdmissionRecord {
    pub candidate_id: i64,
    pub identity: String,
    pub version: i64,
    pub manifest_hash: String,
}

/// Manifest beside this NZB, if it was staged.
pub fn manifest_path_for(nzb: &Path) -> Option<PathBuf> {
    let path = nzb.parent()?.join(FILENAME);
    path.is_file().then_some(path)
}

fn identity_of(meta: &Metadata) -> String {
    format!(
        "{{\"dev\":{},\"ino\":{},\"mode\":{},\"size\":{},\"mtime\":{},\"ctime\":{}}}",
        meta.dev(),
        meta.ino(),
        meta.mode(),
        meta.size(),
        meta.mtime(),
        meta.ctime()
    )
}

/// `lstat` of a regular file, as the staging side records it.
pub fn source_identity(path: &Path) -> Option<String> {
    let meta = fs::symlink_metadata(path).ok()?;
    meta.file_type().is_file().then(|| identity_of(&meta))
}

fn file_digest(path: &Path) -> Result<String> {
    let mut file = File::open(path)?;
    let mut hasher = Sha256::new();
    std::io::copy(&mut file, &mut hasher)?;
    Ok(hex::encode(hasher.finalize()))
}

fn sync_directory(path: &Path) -> Result<()> {
    File::open(path)?.sync_all()?;
    Ok(())
}

fn random_hex(bytes: usize) -> String {
    let mut buffer = vec![0u8; bytes];
    rand::rng().fill_bytes(&mut buffer);
    hex::encode(buffer)
}

fn now() -> String {
    Utc::now().to_rfc3339_opts(SecondsFormat::Secs, false)
}

fn safe_filename(name: &str, extension: &str) -> bool {
    let path = Path::new(name);
    !name.is_empty()
        && name != "."
        && name != ".."
        && !name.contains(['/', '\0'])
        && path
            .extension()
            .is_some_and(|found| found.eq_ignore_ascii_case(extension))
        && path
            .file_stem()
            .is_some_and(|stem| !stem.to_string_lossy().trim().is_empty())
}

pub fn read(path: &Path) -> Result<Value> {
    let contents = fs::read(path).with_context(|| format!("reading {}", path.display()))?;
    let manifest: Value = serde_json::from_slice(&contents)
        .with_context(|| format!("Invalid upload manifest JSON: {}", path.display()))?;
    let version = manifest["version"].as_i64();
    let valid = matches!(version, Some(1 | 2))
        && manifest["upload_id"].is_string()
        && manifest["state"].is_string()
        && manifest["nzb"]["filename"].is_string();
    if !valid {
        bail!("Invalid upload manifest structure: {}", path.display());
    }
    if !safe_filename(manifest["nzb"]["filename"].as_str().unwrap_or(""), "nzb") {
        bail!("Unsafe nzb filename in upload manifest");
    }
    if !manifest["nfo"].is_null() {
        let name = manifest["nfo"]["filename"]
            .as_str()
            .ok_or_else(|| anyhow!("Invalid NFO manifest entry: {}", path.display()))?;
        if !safe_filename(name, "nfo") {
            bail!("Unsafe nfo filename in upload manifest");
        }
    }
    Ok(manifest)
}

pub fn write(path: &Path, manifest: &Value) -> Result<()> {
    let mut body = serde_json::to_string_pretty(manifest)?;
    body.push('\n');
    let temporary = path.with_file_name(format!("{FILENAME}.tmp.{}", random_hex(16)));
    let mut stream = OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(&temporary)?;
    stream.write_all(body.as_bytes())?;
    stream.sync_all()?;
    drop(stream);
    fs::rename(&temporary, path)?;
    sync_directory(path.parent().unwrap_or(Path::new(".")))
}

/// Serializes everything touching one upload, across processes. Released when dropped.
async fn lock(path: &Path) -> Result<File> {
    let file = OpenOptions::new()
        .create(true)
        .truncate(false)
        .write(true)
        .open(path.with_file_name(LOCK_FILENAME))?;
    loop {
        match file.try_lock() {
            Ok(()) => return Ok(file),
            Err(std::fs::TryLockError::WouldBlock) => {
                tokio::time::sleep(Duration::from_millis(50)).await;
            }
            Err(std::fs::TryLockError::Error(failure)) => return Err(failure.into()),
        }
    }
}

fn state(manifest: &Value) -> &str {
    manifest["state"].as_str().unwrap_or("")
}

fn is_imported(manifest: &Value) -> bool {
    IMPORTED_STATES.contains(&state(manifest))
}

fn carries_php_journal(manifest: &Value) -> bool {
    PHP_JOURNAL.iter().any(|field| {
        let value = &manifest[*field];
        !value.is_null() && value.as_object().is_none_or(|object| !object.is_empty())
    })
}

fn hold_for_review(manifest: &mut Value, reason: &str) {
    manifest["state"] = json!(METADATA_REVIEW);
    manifest["release_id"] = Value::Null;
    manifest["release_guid"] = Value::Null;
    manifest["last_error"] = json!(reason);
    manifest["updated_at"] = json!(now());
}

/// Captures this attempt's source generation before its bytes are read. `Ok(None)` when there's
/// no manifest; a `deferred` reservation means it must wait.
pub async fn reserve_input(nzb: &Path) -> Result<Option<Value>> {
    let Some(path) = manifest_path_for(nzb) else {
        return Ok(None);
    };
    let _guard = lock(&path).await?;
    let mut manifest = read(&path)?;
    let name = nzb
        .file_name()
        .map(|name| name.to_string_lossy().into_owned());
    if manifest["nzb"]["filename"].as_str() != name.as_deref() {
        bail!("Input filename mismatch");
    }
    if carries_php_journal(&manifest) {
        return Ok(Some(json!({"deferred": true})));
    }
    let cleanup_pending = [
        "delete_nzb_after_nfo",
        "delete_nzb_after_publication",
        "delete_nfo_after_import",
    ]
    .iter()
    .any(|field| !manifest[*field].is_null());
    if is_imported(&manifest) && cleanup_pending {
        return Ok(Some(json!({"deferred": true})));
    }
    if manifest["nfo"].is_object() && !manifest["nfo_capture"].is_object() {
        // Old identifiers can't show which companion bytes belong to a fresh admission.
        hold_for_review(
            &mut manifest,
            "Uncaptured paired companion; restaging both inputs is required.",
        );
        write(&path, &manifest)?;
        return Ok(Some(json!({"deferred": true})));
    }
    if manifest["version"] == json!(1) {
        manifest["version"] = json!(2);
    }
    let identity = source_identity(nzb);
    let accepted = &manifest["accepted_capture"];
    if accepted["protocol"] == json!(2)
        && is_imported(&manifest)
        && identity.as_deref() == accepted["source_identity"].as_str()
        && accepted["companion_attempt"] == manifest["nfo_capture"]["attempt"]
    {
        // A replay of the same owned generation mustn't orphan its accepted companion.
        let mut reservation = accepted.clone();
        let capture = accepted.clone();
        if let Some(object) = reservation.as_object_mut() {
            object.remove("digest");
        }
        manifest["input_reservation"] = reservation.clone();
        manifest["input_capture"] = capture;
        write(&path, &manifest)?;
        return Ok(Some(reservation));
    }
    let reservation = json!({
        "protocol": 2,
        "attempt": random_hex(16),
        "upload_id": manifest["upload_id"],
        "filename": name,
        "source_identity": identity,
        "companion_attempt": manifest["nfo_capture"]["attempt"],
    });
    manifest["input_reservation"] = reservation.clone();
    write(&path, &manifest)?;
    Ok(Some(reservation))
}

/// Ties the reservation to the exact bytes read. `None` if the source changed meanwhile.
pub async fn finalize_input(
    nzb: &Path,
    bytes: &[u8],
    reservation: &Value,
) -> Result<Option<Value>> {
    let path = manifest_path_for(nzb).ok_or_else(|| anyhow!("Upload manifest disappeared."))?;
    let _guard = lock(&path).await?;
    let mut manifest = read(&path)?;
    let identity = source_identity(nzb);
    if manifest["input_reservation"] != *reservation
        || reservation["source_identity"].is_null()
        || identity.as_deref() != reservation["source_identity"].as_str()
    {
        return Ok(None);
    }
    let digest = hex::encode(Sha256::digest(bytes));
    if digest != file_digest(nzb)?
        || source_identity(nzb).as_deref() != reservation["source_identity"].as_str()
    {
        return Ok(None);
    }
    let mut capture = reservation.clone();
    capture["digest"] = json!(digest);
    manifest["input_capture"] = capture.clone();
    write(&path, &manifest)?;
    Ok(Some(capture))
}

fn custody_directory(source: &Path, receipt: &Value) -> PathBuf {
    let name = source
        .file_name()
        .map(|name| name.to_string_lossy().into_owned());
    let locator = serde_json::to_string(&json!([name, receipt])).unwrap_or_default();
    source.with_file_name(format!(".custody-{}", hex::encode(Sha256::digest(locator))))
}

/// Whether this path still holds the captured generation. A same-filesystem rename may change the
/// ctime, but not the inode, mode or bytes.
fn capture_matches(path: &Path, receipt: &Value, renamed: bool) -> bool {
    let Some(identity) = source_identity(path) else {
        return false;
    };
    let (Some(expected), Some(digest)) = (
        receipt["source_identity"].as_str(),
        receipt["digest"].as_str(),
    ) else {
        return false;
    };
    let same = if renamed {
        let parse = |text: &str| {
            serde_json::from_str::<Value>(text).ok().map(|mut value| {
                if let Some(object) = value.as_object_mut() {
                    object.remove("ctime");
                }
                value
            })
        };
        parse(expected).is_some() && parse(expected) == parse(&identity)
    } else {
        expected == identity
    };
    same && file_digest(path).is_ok_and(|found| found == digest)
        && source_identity(path).as_deref() == Some(identity.as_str())
}

/// Retires the captured generation. `Ok(false)` when there's no proof this generation can go.
/// Bytes that aren't the capture stay in private custody, and the upload is held for review.
fn retire_source(source: &Path, receipt: &Value) -> Result<bool> {
    let custody = custody_directory(source, receipt);
    let parent = source.parent().unwrap_or(Path::new("."));
    let existed = custody.is_dir();
    let payload = custody.join("payload");
    if !existed {
        if !capture_matches(source, receipt, false) {
            return Ok(false);
        }
        fs::DirBuilder::new().mode(0o700).create(&custody)?;
        sync_directory(parent)?;
    }
    if fs::symlink_metadata(&payload).is_err() && capture_matches(source, receipt, false) {
        fs::rename(source, &payload)?;
        sync_directory(&custody)?;
        sync_directory(parent)?;
    }
    if fs::symlink_metadata(&payload).is_ok() {
        if !capture_matches(&payload, receipt, true) {
            bail!(
                "Custody of {} holds other bytes; explicit restaging is required.",
                custody.display()
            );
        }
        fs::remove_file(&payload)?;
        sync_directory(&custody)?;
    }
    // A generation replaced before it moved is gone too, and the new file is never touched.
    Ok(true)
}

fn forget_custody(source: &Path, receipt: &Value) {
    let custody = custody_directory(source, receipt);
    if fs::remove_dir(&custody).is_ok() {
        let _ = sync_directory(source.parent().unwrap_or(Path::new(".")));
    }
}

fn retirement_fields(capture: &Value) -> Value {
    json!({"source_identity": capture["source_identity"], "digest": capture["digest"]})
}

/// Deletes the captured input, now or once its release is published. `Ok(false)` when it stays.
pub async fn delete_captured_input(
    pool: &MySqlPool,
    nzb: &Path,
    capture: Option<&Value>,
    retain_for_companion: bool,
) -> Result<bool> {
    let Some(path) = manifest_path_for(nzb) else {
        if !retain_for_companion && capture.is_none() {
            fs::remove_file(nzb)?;
            return Ok(true);
        }
        return Ok(false);
    };
    let Some(capture) = capture else {
        return Ok(false);
    };
    {
        let _guard = lock(&path).await?;
        let mut manifest = read(&path)?;
        if carries_php_journal(&manifest)
            || manifest["input_reservation"]["attempt"] != capture["attempt"]
            || nzb
                .file_name()
                .map(|name| name.to_string_lossy().into_owned())
                .as_deref()
                != manifest["nzb"]["filename"].as_str()
        {
            return Ok(false);
        }
        let terminal = ["failed", "blacklisted", "nogroup", "duplicate"]
            .contains(&manifest["outcome_status"].as_str().unwrap_or(""));
        if manifest["accepted_capture"] != *capture
            && (manifest["outcome_capture"] != *capture || !terminal)
        {
            return Ok(false);
        }
        if retain_for_companion {
            // Metadata enrichment may change the admission's revision before publication, so a
            // queued capture retires through the fenced publication or companion path.
            let field = if manifest["nfo"].is_object() || manifest["nfo_capture"].is_object() {
                "delete_nzb_after_nfo"
            } else {
                "delete_nzb_after_publication"
            };
            manifest[field] = retirement_fields(capture);
            write(&path, &manifest)?;
        } else {
            match retire_source(nzb, capture) {
                Ok(true) => {
                    manifest["relinquished_attempt"] = capture["attempt"].clone();
                    write(&path, &manifest)?;
                    forget_custody(nzb, capture);
                    return Ok(true);
                }
                Ok(false) => return Ok(false),
                Err(failure) => {
                    manifest["custody_collision"] = json!({"attempt": capture["attempt"]});
                    hold_for_review(&mut manifest, &format!("{failure:#}"));
                    write(&path, &manifest)?;
                    return Err(failure);
                }
            }
        }
    }
    if retain_for_companion {
        reconcile_pending(pool, &path).await?;
        return Ok(read(&path)?["relinquished_attempt"] == capture["attempt"]);
    }
    Ok(false)
}

fn valid_identity(value: &Value) -> bool {
    value.as_str().is_some_and(|identity| {
        identity.len() == 40
            && identity
                .bytes()
                .all(|byte| matches!(byte, b'0'..=b'9' | b'a'..=b'f'))
    })
}

fn can_reconcile(manifest: &Value) -> bool {
    state(manifest) == NZB_PENDING
        || ([METADATA_REVIEW, METADATA_DEFERRED].contains(&state(manifest))
            && valid_identity(&manifest["candidate_identity"]))
}

/// Records what happened to the NZB. Queued outcomes need the revision their admission accepted.
pub async fn record_nzb_outcome(
    pool: &MySqlPool,
    nzb: &Path,
    status: ImportStatus,
    capture: Option<&Value>,
    admission: Option<&AdmissionRecord>,
) -> Result<()> {
    let Some(path) = manifest_path_for(nzb) else {
        return Ok(());
    };
    let Some(capture) = capture else {
        return Ok(());
    };
    let _guard = lock(&path).await?;
    let mut manifest = read(&path)?;
    if !manifest["custody_collision"].is_null() {
        return Ok(());
    }
    if manifest["version"] != json!(2)
        || capture["protocol"] != json!(2)
        || manifest["input_capture"] != *capture
        || manifest["input_reservation"]["attempt"] != capture["attempt"]
    {
        return Ok(());
    }
    if status == ImportStatus::Queued && admission.is_none() {
        bail!("Queued upload has no valid verification candidate identity.");
    }
    let same_capture = manifest["accepted_capture"] == *capture;
    if same_capture
        && manifest["nfo_capture"].is_object()
        && is_imported(&manifest)
        && nfo_cleanup_committed(pool, &manifest).await?
    {
        // A same-generation notification can't replace a committed receipt's authority.
        return Ok(());
    }
    let stale_retry = manifest["stale_admission_retry"] == json!(true);
    if matches!(
        status,
        ImportStatus::Queued | ImportStatus::MetadataIgnored | ImportStatus::MetadataDeferred
    ) && let Some(object) = manifest.as_object_mut()
    {
        object.remove("stale_admission_retry");
    }
    manifest["outcome_capture"] = capture.clone();
    manifest["outcome_status"] = json!(status.as_str());
    match status {
        ImportStatus::Queued => {
            let admission = admission.expect("checked above");
            if !valid_identity(&json!(admission.identity)) {
                bail!("Queued upload has no valid verification candidate identity.");
            }
            manifest["accepted_capture"] = capture.clone();
            manifest["accepted_admission"] = json!({
                "candidate_id": admission.candidate_id,
                "identity": admission.identity,
                "version": admission.version,
                "manifest_hash": admission.manifest_hash,
            });
            manifest["candidate_identity"] = json!(admission.identity);
            manifest["state"] = json!(NZB_PENDING);
            manifest["release_id"] = Value::Null;
            manifest["release_guid"] = Value::Null;
            manifest["last_error"] = Value::Null;
        }
        ImportStatus::MetadataIgnored | ImportStatus::MetadataDeferred => {
            // A concurrent import may already own this upload, so a late advisory outcome mustn't
            // discard that durable identity.
            let settled =
                is_imported(&manifest) || [NZB_REJECTED, NZB_DUPLICATE].contains(&state(&manifest));
            if (!stale_retry && can_reconcile(&manifest)) || settled {
                return Ok(());
            }
            manifest["state"] = json!(if status == ImportStatus::MetadataIgnored {
                METADATA_REVIEW
            } else {
                METADATA_DEFERRED
            });
            manifest["release_id"] = Value::Null;
            manifest["release_guid"] = Value::Null;
            manifest["candidate_identity"] = Value::Null;
            manifest["last_error"] =
                json!(format!("NZB import ended with status {}", status.as_str()));
        }
        ImportStatus::Duplicate => {
            manifest["state"] = json!(NZB_DUPLICATE);
            manifest["release_id"] = Value::Null;
            manifest["release_guid"] = Value::Null;
            manifest["last_error"] = json!("NZB import detected an existing duplicate release");
        }
        ImportStatus::Blacklisted | ImportStatus::NoGroup | ImportStatus::Failed => {
            manifest["state"] = json!(NZB_FAILED);
            manifest["release_id"] = Value::Null;
            manifest["release_guid"] = Value::Null;
            manifest["last_error"] =
                json!(format!("NZB import ended with status {}", status.as_str()));
        }
    }
    manifest["updated_at"] = json!(now());
    write(&path, &manifest)
}

/// Whether the scan should read this NZB. Reconciles first, so a finished upload isn't read again.
pub async fn should_import(pool: &MySqlPool, nzb: &Path) -> Result<bool> {
    let Some(path) = manifest_path_for(nzb) else {
        return Ok(true);
    };
    let manifest = read(&path)?;
    let name = nzb
        .file_name()
        .map(|name| name.to_string_lossy().into_owned());
    if manifest["nzb"]["filename"].as_str() != name.as_deref() {
        bail!(
            "NZB path does not match its upload manifest: {}",
            nzb.display()
        );
    }
    let manifest = reconcile_pending(pool, &path).await?;
    Ok(
        [STAGED, NZB_FAILED, METADATA_DEFERRED].contains(&state(&manifest))
            || (state(&manifest) == METADATA_REVIEW
                && manifest["stale_admission_retry"] == json!(true)),
    )
}

#[derive(sqlx::FromRow)]
struct CandidateState {
    id: i64,
    version: i64,
    manifest_hash: String,
    status: String,
    release_id: Option<i64>,
    verified: bool,
    guid: Option<String>,
}

fn admission_matches(manifest: &Value, candidate: &CandidateState) -> bool {
    let accepted = &manifest["accepted_admission"];
    accepted["identity"] == manifest["candidate_identity"]
        && accepted["candidate_id"].as_i64() == Some(candidate.id)
        && accepted["version"].as_i64() == Some(candidate.version)
        && accepted["manifest_hash"].as_str() == Some(candidate.manifest_hash.as_str())
}

async fn accepted_input_valid(pool: &MySqlPool, path: &Path, manifest: &Value) -> Result<bool> {
    let accepted = &manifest["accepted_capture"];
    if accepted.is_null() {
        return Ok(false);
    }
    if manifest["candidate_identity"].is_string() {
        let admission = &manifest["accepted_admission"];
        let found: Option<i32> = sqlx::query_scalar(
            "SELECT 1 FROM release_verification_candidates WHERE id = ? AND identity = ? AND version = ? AND manifest_hash = ?",
        )
        .bind(admission["candidate_id"].as_i64())
        .bind(manifest["candidate_identity"].as_str())
        .bind(admission["version"].as_i64())
        .bind(admission["manifest_hash"].as_str())
        .fetch_optional(pool)
        .await?;
        if found.is_none() {
            return Ok(false);
        }
    }
    let source = path.with_file_name(manifest["nzb"]["filename"].as_str().unwrap_or(""));
    Ok(manifest["relinquished_attempt"] == accepted["attempt"]
        || capture_matches(
            &custody_directory(&source, accepted).join("payload"),
            accepted,
            true,
        )
        || capture_matches(&source, accepted, false))
}

/// Resolves a queued upload from its candidate, even after its NZB was deleted.
async fn resolve_candidate(pool: &MySqlPool, path: &Path, manifest: &mut Value) -> Result<()> {
    let identity = manifest["candidate_identity"].clone();
    if !valid_identity(&identity) {
        bail!("Pending upload has no valid verification candidate identity.");
    }
    let candidate: Option<CandidateState> = sqlx::query_as(
        "SELECT CAST(c.id AS SIGNED) AS id, CAST(c.version AS SIGNED) AS version, c.manifest_hash, c.status, \
         CAST(c.release_id AS SIGNED) AS release_id, c.verified_at IS NOT NULL AS verified, r.guid \
         FROM release_verification_candidates c LEFT JOIN releases r ON r.id = c.release_id WHERE c.identity = ?",
    )
    .bind(identity.as_str())
    .fetch_optional(pool)
    .await?;
    let published = |status: &str| ["complete", "repaired", "sampled"].contains(&status);
    if let Some(row) = &candidate {
        if published(&row.status) && !admission_matches(manifest, row) {
            hold_for_review(
                manifest,
                "Accepted candidate admission revision no longer matches publication.",
            );
            // Retry admission against the current revision: only admission can decide compatibility.
            manifest["stale_admission_retry"] = json!(true);
            return Ok(());
        }
        match row.status.as_str() {
            "metadata_deferred" | "metadata_expired" => {
                hold_for_review(
                    manifest,
                    "Download verification candidate awaits metadata capacity; retained source may retry.",
                );
                manifest["state"] = json!(METADATA_DEFERRED);
                return Ok(());
            }
            "metadata_review" => {
                if let Some(object) = manifest.as_object_mut() {
                    object.remove("stale_admission_retry");
                }
                hold_for_review(
                    manifest,
                    "Download verification candidate is held for metadata review.",
                );
                return Ok(());
            }
            status if !published(status) && !["expired", "unrepairable"].contains(&status) => {
                return Ok(());
            }
            _ => {}
        }
    }
    if let Some(row) = &candidate
        && published(&row.status)
        && row.verified
        && row.release_id.is_some_and(|release| release > 0)
        && row.guid.as_deref().is_some_and(|guid| !guid.is_empty())
    {
        if !manifest["accepted_capture"].is_null()
            && !accepted_input_valid(pool, path, manifest).await?
        {
            hold_for_review(manifest, "Admitted NZB source generation changed.");
            return Ok(());
        }
        manifest["state"] = json!(NZB_IMPORTED);
        manifest["release_id"] = json!(row.release_id);
        manifest["release_guid"] = json!(row.guid);
        manifest["last_error"] = Value::Null;
    } else {
        manifest["state"] = json!(NZB_REJECTED);
        manifest["release_id"] = Value::Null;
        manifest["release_guid"] = Value::Null;
        manifest["last_error"] = json!(match candidate.as_ref().map(|row| row.status.as_str()) {
            None => "Download verification candidate no longer exists.",
            Some("expired") => "Download verification expired before publication.",
            Some("unrepairable") => "Download verification found the payload unrepairable.",
            Some(_) => "Verified publication has no current release identity.",
        });
    }
    manifest["updated_at"] = json!(now());
    Ok(())
}

/// Retires the NZB once what it waited for has committed.
async fn finish_input_deletion(
    pool: &MySqlPool,
    path: &Path,
    manifest: &mut Value,
    publication_only: bool,
) -> Result<()> {
    let field = if publication_only {
        "delete_nzb_after_publication"
    } else {
        "delete_nzb_after_nfo"
    };
    let accepted = manifest["accepted_capture"].clone();
    if manifest[field].is_null()
        || !accepted.is_object()
        || manifest[field] != retirement_fields(&accepted)
    {
        return Ok(());
    }
    if publication_only {
        if state(manifest) != NZB_IMPORTED
            || manifest["nfo"].is_object()
            || manifest["nfo_capture"].is_object()
        {
            return Ok(());
        }
        let current: Option<i32> =
            sqlx::query_scalar("SELECT 1 FROM releases WHERE id = ? AND guid = ?")
                .bind(manifest["release_id"].as_i64())
                .bind(manifest["release_guid"].as_str())
                .fetch_optional(pool)
                .await?;
        if current.is_none() {
            return Ok(());
        }
    } else if !nfo_cleanup_committed(pool, manifest).await? {
        return Ok(());
    }
    let source = path.with_file_name(manifest["nzb"]["filename"].as_str().unwrap_or(""));
    match retire_source(&source, &accepted) {
        Ok(true) => {
            manifest["relinquished_attempt"] = accepted["attempt"].clone();
            if let Some(object) = manifest.as_object_mut() {
                object.remove(field);
            }
            write(path, manifest)?;
            forget_custody(&source, &accepted);
            Ok(())
        }
        Ok(false) => Ok(()),
        Err(failure) => {
            manifest["custody_collision"] = json!({"attempt": accepted["attempt"]});
            hold_for_review(manifest, &format!("{failure:#}"));
            write(path, manifest)?;
            Err(failure)
        }
    }
}

/// Moves a queued upload forward from its candidate's current state, under the reset fence.
pub async fn reconcile_pending(pool: &MySqlPool, path: &Path) -> Result<Value> {
    let _guard = lock(path).await?;
    let mut manifest = read(path)?;
    if carries_php_journal(&manifest) {
        if manifest["custody_collision"].is_null() {
            hold_for_review(
                &mut manifest,
                "An interrupted PHP custody journal needs review.",
            );
            manifest["custody_collision"] = json!({"attempt": null});
            write(path, &manifest)?;
        }
        return Ok(manifest);
    }
    let has_accepted = !manifest["accepted_capture"].is_null();
    if !has_accepted
        && (can_reconcile(&manifest) || [NZB_IMPORTED, NFO_FAILED].contains(&state(&manifest)))
    {
        hold_for_review(
            &mut manifest,
            "Unknown legacy admission provenance; authoritative migration required.",
        );
        write(path, &manifest)?;
        return Ok(manifest);
    }
    if !can_reconcile(&manifest) && !has_accepted {
        return Ok(manifest);
    }
    if [NZB_IMPORTED, NFO_FAILED].contains(&state(&manifest))
        && !accepted_input_valid(pool, path, &manifest).await?
    {
        hold_for_review(&mut manifest, "Admitted NZB source generation changed.");
        write(path, &manifest)?;
        return Ok(manifest);
    }
    if !can_reconcile(&manifest) {
        if state(&manifest) == NZB_IMPORTED {
            let admission = Admission::enter(pool).await?;
            let finished = finish_input_deletion(pool, path, &mut manifest, true).await;
            admission.leave().await?;
            finished?;
        }
        return Ok(manifest);
    }
    let admission = Admission::enter(pool).await?;
    let outcome = async {
        let before = manifest.clone();
        resolve_candidate(pool, path, &mut manifest).await?;
        if manifest != before {
            write(path, &manifest)?;
        }
        if state(&manifest) == NZB_IMPORTED {
            finish_input_deletion(pool, path, &mut manifest, true).await?;
        }
        Ok::<(), anyhow::Error>(())
    }
    .await;
    admission.leave().await?;
    outcome?;
    Ok(manifest)
}

fn nfo_commit_binding(manifest: &Value) -> String {
    let body = serde_json::to_string(&json!({
        "protocol": 2,
        "operation": "nfo-store-cleanup",
        "upload_id": manifest["upload_id"],
        "accepted": manifest["accepted_capture"],
        "accepted_admission": manifest["accepted_admission"],
        "companion": manifest["nfo_capture"],
        "candidate_identity": manifest["candidate_identity"],
        "release_id": manifest["release_id"],
        "release_guid": manifest["release_guid"],
    }))
    .unwrap_or_default();
    hex::encode(Sha256::digest(body))
}

/// A committed row, never a filesystem marker, proves this upload's NFO was stored.
async fn nfo_cleanup_committed(pool: &MySqlPool, manifest: &Value) -> Result<bool> {
    if manifest["accepted_capture"]["protocol"] != json!(2)
        || !manifest["nfo_capture"].is_object()
        || !manifest["release_id"].is_i64()
        || !manifest["release_guid"].is_string()
    {
        return Ok(false);
    }
    let found: Option<i32> = sqlx::query_scalar(
        "SELECT 1 FROM nfo_cleanup_commits n JOIN releases r ON r.id = n.release_id AND r.guid = n.release_guid \
         WHERE n.binding = ? AND n.release_id = ? AND n.release_guid = ?",
    )
    .bind(nfo_commit_binding(manifest))
    .bind(manifest["release_id"].as_i64())
    .bind(manifest["release_guid"].as_str())
    .fetch_optional(pool)
    .await?;
    Ok(found.is_some())
}

/// Files under a folder, as the importer scans them: dot-prefixed entries are skipped, which keeps
/// `.staging` and custody directories out.
pub fn scan(folder: &Path) -> Result<Vec<PathBuf>> {
    let mut found = Vec::new();
    let mut pending = vec![folder.to_owned()];
    while let Some(directory) = pending.pop() {
        for entry in fs::read_dir(&directory)? {
            let entry = entry?;
            if entry.file_name().to_string_lossy().starts_with('.') {
                continue;
            }
            let kind = entry.file_type()?;
            if kind.is_dir() {
                pending.push(entry.path());
            } else if kind.is_file() {
                found.push(entry.path());
            }
        }
    }
    found.sort();
    Ok(found)
}

/// The outcome of one companion import.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum NfoOutcome {
    Imported,
    Skipped(String),
}

fn zlib(bytes: &[u8]) -> Result<Vec<u8>> {
    let mut encoder = flate2::write::ZlibEncoder::new(Vec::new(), flate2::Compression::default());
    encoder.write_all(bytes)?;
    let mut stored = vec![0x1f, 0x8b, 0x08, 0x00];
    stored.extend(encoder.finish()?);
    Ok(stored)
}

/// Stores the upload's NFO on its release, and retires both inputs when asked. The NFO write and its
/// receipt commit together, so a restart resumes only that exact operation.
pub async fn import_companion(pool: &MySqlPool, path: &Path, delete: bool) -> Result<NfoOutcome> {
    let manifest = reconcile_pending(pool, path).await?;
    let current = state(&manifest).to_owned();
    if current == NFO_IMPORTED && !delete {
        return Ok(NfoOutcome::Skipped("NFO was already imported".to_owned()));
    }
    if ![NZB_IMPORTED, NFO_FAILED, NFO_IMPORTED].contains(&current.as_str()) {
        return Ok(NfoOutcome::Skipped(format!(
            "NFO is waiting or held ({current})"
        )));
    }
    if !manifest["nfo"].is_object() {
        return Ok(NfoOutcome::Skipped("Upload has no paired NFO".to_owned()));
    }
    let _guard = lock(path).await?;
    let mut manifest = read(path)?;
    if !manifest["custody_collision"].is_null() {
        bail!("Custody collision requires explicit restaging.");
    }
    let receipt = manifest["nfo_capture"].clone();
    let accepted = manifest["accepted_capture"].clone();
    let release = manifest["release_id"].as_i64();
    let guid = manifest["release_guid"].as_str().map(str::to_owned);
    if receipt["protocol"] != json!(1)
        || !accepted.is_object()
        || accepted["companion_attempt"] != receipt["attempt"]
        || release.is_none()
        || guid.is_none()
        || !accepted_input_valid(pool, path, &manifest).await?
    {
        hold_for_review(
            &mut manifest,
            "Unknown companion generation or admitted input",
        );
        write(path, &manifest)?;
        bail!("Unknown companion generation or admitted input");
    }
    let name = receipt["filename"].as_str().unwrap_or("");
    let companion = path.with_file_name(name);
    let admission = Admission::enter(pool).await?;
    let stored = async {
        if nfo_cleanup_committed(pool, &manifest).await? {
            return Ok::<bool, anyhow::Error>(false);
        }
        if !capture_matches(&companion, &receipt, false) {
            bail!("Companion generation changed");
        }
        let body = fs::read(&companion)?;
        if hex::encode(Sha256::digest(&body)) != receipt["digest"].as_str().unwrap_or("") {
            bail!("Companion body does not match staged bytes");
        }
        if body.is_empty() || body.len() > 65_535 {
            bail!("Captured companion is empty or exceeds the NFO size limit");
        }
        let data = zlib(&body)?;
        let binding = nfo_commit_binding(&manifest);
        let digest = hex::encode(Sha256::digest(&data));
        in_tx!(pool, 5, |tx| {
            let locked: Option<u32> = sqlx::query_scalar("SELECT id FROM releases WHERE id = ? AND guid = ? FOR UPDATE")
                .bind(release)
                .bind(guid.as_deref())
                .fetch_optional(&mut *tx)
                .await?;
            if locked.is_none() {
                bail!("Release identity changed before companion store");
            }
            sqlx::query("INSERT INTO release_nfos (releases_id, nfo) VALUES (?, ?) ON DUPLICATE KEY UPDATE nfo = VALUES(nfo)")
                .bind(release)
                .bind(&data)
                .execute(&mut *tx)
                .await?;
            sqlx::query("UPDATE releases SET nfostatus = 1 WHERE id = ?")
                .bind(release)
                .execute(&mut *tx)
                .await?;
            sqlx::query(
                "INSERT INTO nfo_cleanup_commits (binding, release_id, release_guid, candidate_identity, stored_digest) \
                 VALUES (?, ?, ?, ?, ?)",
            )
            .bind(&binding)
            .bind(release)
            .bind(guid.as_deref())
            .bind(manifest["candidate_identity"].as_str())
            .bind(&digest)
            .execute(&mut *tx)
            .await?;
            Ok::<_, anyhow::Error>(())
        })?;
        Ok(true)
    }
    .await;
    let outcome = async {
        let newly = match stored {
            Ok(newly) => newly,
            Err(failure) => {
                manifest["state"] = json!(NFO_FAILED);
                manifest["last_error"] = json!(format!("{failure:#}"));
                manifest["updated_at"] = json!(now());
                write(path, &manifest)?;
                return Err(failure);
            }
        };
        manifest["state"] = json!(NFO_IMPORTED);
        manifest["last_error"] = Value::Null;
        manifest["updated_at"] = json!(now());
        write(path, &manifest)?;
        finish_input_deletion(pool, path, &mut manifest, false).await?;
        if delete
            && manifest["nfo_relinquished_attempt"] != receipt["attempt"]
            && retire_source(&companion, &receipt)?
        {
            manifest["nfo_relinquished_attempt"] = receipt["attempt"].clone();
            write(path, &manifest)?;
            forget_custody(&companion, &receipt);
        }
        Ok(if newly {
            NfoOutcome::Imported
        } else {
            NfoOutcome::Skipped("NFO storage was already committed; cleanup retried".to_owned())
        })
    }
    .await;
    admission.leave().await?;
    outcome
}
