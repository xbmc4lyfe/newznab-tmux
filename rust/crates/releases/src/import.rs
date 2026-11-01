//! `nntmux-ctl releases import-nzbs`: uploaded and dropped NZBs become verification candidates.
//!
//! An NZB is admitted, never published directly: the verify role decides. A blacklisted, duplicate
//! or groupless NZB ends here. Anything that may succeed later keeps its source.

use std::path::{Path, PathBuf};
use std::sync::LazyLock;

use anyhow::{Result, bail};
use chrono::{DateTime, Utc};
use nntmux_core::pcre::Pcre;
use nntmux_ingest::blacklist::{Blacklist, record_usage};
use nntmux_ingest::header::Overview;
use nntmux_runtime::Shutdown;
use nntmux_verify::candidates::{AdmissionDeferred, enqueue_with_admission};
use nntmux_verify::groups;
use nntmux_verify::nzb::coverage::document_fingerprint;
use nntmux_verify::nzb::parse::{ParsedNzb, parse};
use nntmux_verify::reader::{PasswordMetadataRejected, ReleaseFacts, read_parsed};
use serde_json::Value;
use sha1::{Digest, Sha1};
use std::collections::{BTreeSet, HashMap};
use tracing::{info, warn};

use crate::Releases;
use crate::upload_manifest::{self as uploads, AdmissionRecord, ImportStatus};

static COUNTER_TAIL: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/(\(\d+\/\d+\))*$/").expect("valid"));
static YENC_TAIL: LazyLock<Pcre> = LazyLock::new(|| Pcre::new(r"/yEnc.*$/i").expect("valid"));
static WRAPPER: LazyLock<Pcre> = LazyLock::new(|| Pcre::new(r"/\.nzb(\.gz)?$/i").expect("valid"));
static MEDIA: LazyLock<Pcre> = LazyLock::new(|| {
    Pcre::new(r"/\.(mkv|mp4|avi|mov|wmv|flv|m4v|ts|webm|mpg|mpeg|iso|m2ts|ogm|divx|vob)$/i")
        .expect("valid")
});

#[derive(Clone)]
pub struct Options {
    pub folder: PathBuf,
    pub use_filename: bool,
    pub delete: bool,
    pub delete_failed: bool,
    /// Only staged uploads, on a cooldown after a failure. A dropped file has no capture to retire,
    /// so an unattended pass would admit it again every round and bring back deleted releases.
    pub unattended: bool,
    /// Stops the scan between files.
    pub stop: Option<Shutdown>,
}

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Report {
    pub imported: u64,
    pub skipped: u64,
    pub duplicates: u64,
}

/// `Show - S01E07 [1080p].mkv.nzb.gz` is `Show - S01E07 [1080p]`.
pub fn name_from_path(path: &Path) -> String {
    let name = path
        .file_name()
        .map(|name| name.to_string_lossy().into_owned())
        .unwrap_or_default();
    let name = WRAPPER.replace_all(name.as_bytes(), b"");
    let name = MEDIA.replace_all(&name, b"");
    String::from_utf8_lossy(&name)
        .trim_end_matches(['.', ' ', '\t', '\n', '\r', '\0', '\x0B'])
        .to_owned()
}

/// Drops the part counter and whatever follows the yEnc marker.
pub fn normalize_subject(subject: &str) -> String {
    let marked = COUNTER_TAIL.replace_all(subject.as_bytes(), b"yEnc");
    let cut = YENC_TAIL.replace_all(&marked, b"yEnc");
    nntmux_verify::nzb::fingerprint::php_trim(&String::from_utf8_lossy(&cut)).to_owned()
}

/// The one active category an NZB's metadata names. `None` when any value is unknown or ambiguous.
async fn metadata_category(pool: &sqlx::MySqlPool, document: &ParsedNzb) -> Result<Option<i64>> {
    let values: Vec<&str> = document
        .metadata
        .iter()
        .filter(|meta| meta.kind.trim().to_lowercase() == "category")
        .map(|meta| meta.text.trim())
        .filter(|value| !value.is_empty())
        .collect();
    if values.is_empty() {
        return Ok(None);
    }
    let active: Vec<(i64, String)> =
        sqlx::query_as("SELECT CAST(id AS SIGNED), title FROM categories WHERE status = 1")
            .fetch_all(pool)
            .await?;
    let mut resolved = BTreeSet::new();
    for value in values {
        let digits = value.bytes().all(|byte| byte.is_ascii_digit());
        let lowered = value.to_lowercase();
        let matching: Vec<i64> = active
            .iter()
            .filter(|(id, title)| {
                if digits {
                    value.parse::<i64>().ok() == Some(*id)
                } else {
                    title.to_lowercase() == lowered
                }
            })
            .map(|(id, _)| *id)
            .collect();
        let [only] = matching[..] else {
            return Ok(None);
        };
        resolved.insert(only);
    }
    Ok(if resolved.len() == 1 {
        resolved.into_iter().next()
    } else {
        None
    })
}

struct Scanned {
    group: i64,
    subject: String,
    poster: String,
    date: Option<String>,
}

/// Resolves groups and checks the blacklist. Unknown valid groups are added inactive.
async fn scan_document(
    pool: &sqlx::MySqlPool,
    blacklist: &mut Blacklist,
    document: &ParsedNzb,
) -> Result<Result<Scanned, ImportStatus>> {
    let mut known: HashMap<String, i64> = HashMap::new();
    let mut group = -1;
    let mut matched = BTreeSet::new();
    let mut conn = pool.acquire().await?;
    let mut outcome = None;
    'files: for file in &document.files {
        group = -1;
        let header = Overview {
            number: 0,
            subject: file.subject.clone().into_bytes(),
            from: file.poster.clone().into_bytes(),
            date: Vec::new(),
            message_id: Vec::new(),
            bytes: 0,
            xref: Vec::new(),
        };
        for name in &file.groups {
            let name = nntmux_verify::nzb::fingerprint::php_trim(name).to_owned();
            // The name the blacklist is read for. A name already in the table needs no validating,
            // and once a group is found the rest are checked as written.
            let mut checked = Some(name.clone());
            if group == -1 {
                let existing = match known.get(&name) {
                    Some(id) => Some(*id),
                    None => {
                        sqlx::query_scalar::<_, i64>(
                            "SELECT CAST(id AS SIGNED) FROM usenet_groups WHERE name = ?",
                        )
                        .bind(&name)
                        .fetch_optional(&mut *conn)
                        .await?
                    }
                };
                if let Some(id) = existing {
                    known.insert(name.clone(), id);
                    group = id;
                } else if let Some(valid) = groups::valid_group(&name) {
                    let ids = groups::ids_by_name(&mut conn, std::slice::from_ref(&name)).await?;
                    if let Some(id) = ids.get(&name) {
                        group = *id;
                    }
                    checked = Some(valid);
                } else {
                    checked = None;
                }
            }
            if let Some(checked) = checked
                && blacklist
                    .lists(pool, &checked)
                    .await?
                    .is_blacklisted(&header, &mut matched)
            {
                outcome = Some(ImportStatus::Blacklisted);
                break 'files;
            }
        }
        if group == -1 {
            outcome = Some(ImportStatus::NoGroup);
            break;
        }
    }
    drop(conn);
    record_usage(pool, &matched).await?;
    if let Some(status) = outcome {
        return Ok(Err(status));
    }
    let first = document.files.first();
    let date = first
        .and_then(|file| file.date.trim().parse::<i64>().ok())
        .and_then(|seconds| {
            DateTime::<Utc>::from_timestamp(seconds, 0)
                .map(|at| at.format("%Y-%m-%d %H:%M:%S").to_string())
        });
    Ok(Ok(Scanned {
        group,
        subject: first.map(|file| file.subject.clone()).unwrap_or_default(),
        poster: first.map(|file| file.poster.clone()).unwrap_or_default(),
        date,
    }))
}

fn identity_for(document: &ParsedNzb, xml: &[u8]) -> String {
    let mut hash = Sha1::new();
    hash.update(b"import:");
    match document_fingerprint(document) {
        Some(fingerprint) => hash.update(fingerprint.as_bytes()),
        None => hash.update(xml),
    }
    hex::encode(hash.finalize())
}

/// Decides what happens to one document's bytes. Returns the accepted revision when queued.
async fn admit_document(
    releases: &Releases,
    blacklist: &mut Blacklist,
    xml: &[u8],
    name_override: &str,
) -> Result<(ImportStatus, Option<AdmissionRecord>)> {
    let pool = &releases.verify.db;
    let Ok(document) = parse(xml) else {
        return Ok((ImportStatus::Failed, None));
    };
    // A password that can't be trusted is held for review before anything else reads the document.
    let gate = ReleaseFacts {
        import: true,
        ..ReleaseFacts::default()
    };
    if let Err(failure) = read_parsed(&document, &gate)
        && failure.is::<PasswordMetadataRejected>()
    {
        return Ok((ImportStatus::MetadataIgnored, None));
    }
    let scanned = match scan_document(pool, blacklist, &document).await? {
        Ok(scanned) => scanned,
        Err(status) => return Ok((status, None)),
    };
    if document.files.is_empty() {
        return Ok((ImportStatus::Failed, None));
    }
    let subject = normalize_subject(&scanned.subject);
    let date = scanned
        .date
        .unwrap_or_else(|| Utc::now().format("%Y-%m-%d %H:%M:%S").to_string());
    let facts = ReleaseFacts {
        searchname: if name_override.is_empty() {
            subject.clone()
        } else {
            name_override.to_owned()
        },
        fromname: scanned.poster,
        postdate: Some(date),
        groups_id: scanned.group,
        import: true,
        categories_id: metadata_category(pool, &document).await?,
        import_name_override: !name_override.is_empty(),
        original_subject: Some(subject),
    };
    let mut manifest = match read_parsed(&document, &facts) {
        Ok(manifest) => manifest,
        Err(failure) if failure.is::<PasswordMetadataRejected>() => {
            return Ok((ImportStatus::MetadataIgnored, None));
        }
        Err(failure) => return Err(failure),
    };
    manifest.identity = identity_for(&document, xml);
    match enqueue_with_admission(&releases.verify, &manifest).await {
        Ok(Some(admission)) => Ok((
            ImportStatus::Queued,
            Some(AdmissionRecord {
                candidate_id: admission.candidate_id,
                identity: admission.identity,
                version: admission.version,
                manifest_hash: admission.manifest_hash,
            }),
        )),
        Ok(None) => Ok((ImportStatus::MetadataIgnored, None)),
        Err(failure) => {
            if failure.downcast_ref::<AdmissionDeferred>().is_none() {
                warn!(
                    error = format!("{failure:#}"),
                    "NZB verification admission deferred"
                );
            }
            Ok((ImportStatus::MetadataDeferred, None))
        }
    }
}

fn read_source(path: &Path) -> Option<Vec<u8>> {
    let bytes = std::fs::read(path).ok()?;
    let lower = path.to_string_lossy().to_lowercase();
    if !lower.ends_with(".nzb.gz") {
        return Some(bytes);
    }
    nntmux_verify::nzb::decode(&bytes, nntmux_verify::nzb::MAX_DECODED_BYTES)
}

fn is_nzb(path: &Path) -> bool {
    let lower = path.to_string_lossy().to_lowercase();
    lower.ends_with(".nzb") || lower.ends_with(".nzb.gz")
}

async fn import_one(
    releases: &Releases,
    blacklist: &mut Blacklist,
    path: &Path,
    options: &Options,
    report: &mut Report,
) -> Result<()> {
    let pool = &releases.verify.db;
    let reservation = uploads::reserve_input(path).await?;
    if reservation
        .as_ref()
        .is_some_and(|value| value["deferred"] == Value::Bool(true))
    {
        report.skipped += 1;
        return Ok(());
    }
    let Some(bytes) = read_source(path) else {
        warn!(path = %path.display(), "unable to read an NZB");
        report.skipped += 1;
        if options.delete_failed {
            uploads::delete_captured_input(pool, path, None, false).await?;
        }
        return Ok(());
    };
    let capture = match &reservation {
        Some(reservation) => match uploads::finalize_input(path, &bytes, reservation).await? {
            Some(capture) => Some(capture),
            None => {
                uploads::record_nzb_outcome(pool, path, ImportStatus::MetadataDeferred, None, None)
                    .await?;
                report.skipped += 1;
                return Ok(());
            }
        },
        None => None,
    };
    let name = if options.use_filename {
        name_from_path(path)
    } else {
        String::new()
    };
    let (status, admission) = match admit_document(releases, blacklist, &bytes, &name).await {
        Ok(outcome) => outcome,
        Err(failure) => {
            warn!(path = %path.display(), error = format!("{failure:#}"), "problem importing an NZB");
            (ImportStatus::Failed, None)
        }
    };
    uploads::record_nzb_outcome(pool, path, status, capture.as_ref(), admission.as_ref()).await?;
    match status {
        ImportStatus::Queued => {
            report.imported += 1;
            // Without a durable capture there's no authority to retire a pathname.
            if options.delete
                && let Some(capture) = &capture
                && read_source(path).as_deref() == Some(bytes.as_slice())
            {
                uploads::delete_captured_input(pool, path, Some(capture), true).await?;
            }
        }
        ImportStatus::MetadataIgnored | ImportStatus::MetadataDeferred => {
            report.skipped += 1;
        }
        ImportStatus::Duplicate => {
            report.duplicates += 1;
            if options.delete || options.delete_failed {
                uploads::delete_captured_input(pool, path, capture.as_ref(), false).await?;
            }
        }
        ImportStatus::Blacklisted | ImportStatus::NoGroup => {
            report.skipped += 1;
            if options.delete || options.delete_failed {
                uploads::delete_captured_input(pool, path, capture.as_ref(), false).await?;
            }
        }
        ImportStatus::Failed => {
            report.skipped += 1;
            if options.delete_failed {
                uploads::delete_captured_input(pool, path, capture.as_ref(), false).await?;
            }
        }
    }
    Ok(())
}

/// Scans a folder, reconciling queued uploads and importing the NZBs that are due.
pub async fn import_folder(releases: &Releases, options: &Options) -> Result<Report> {
    if !options.folder.is_dir() {
        bail!("Folder path does not exist: {}", options.folder.display());
    }
    let pool = &releases.verify.db;
    let mut report = Report::default();
    let mut blacklist = Blacklist::default();
    for path in uploads::scan(&options.folder)? {
        let name = path
            .file_name()
            .map(|name| name.to_string_lossy().into_owned());
        if name.as_deref() == Some(uploads::FILENAME) {
            if let Err(failure) = uploads::reconcile_pending(pool, &path).await {
                warn!(path = %path.display(), error = format!("{failure:#}"), "upload reconciliation failed");
            }
            continue;
        }
        if !is_nzb(&path) {
            continue;
        }
        if options.unattended
            && (uploads::manifest_path_for(&path).is_none() || uploads::cooling_down(&path))
        {
            continue;
        }
        let outcome = async {
            if uploads::should_import(pool, &path).await? {
                import_one(releases, &mut blacklist, &path, options, &mut report).await
            } else {
                Ok(())
            }
        }
        .await;
        if let Err(failure) = outcome {
            warn!(path = %path.display(), error = format!("{failure:#}"), "could not import an NZB");
            report.skipped += 1;
        }
    }
    info!(?report, "NZB import finished");
    Ok(report)
}

/// `nntmux:import-nfos`: stores the paired NFOs of uploads whose NZB has a release.
pub async fn import_companions(
    releases: &Releases,
    folder: &Path,
    delete: bool,
    delete_failed: bool,
    stop: Option<&Shutdown>,
) -> Result<(u64, u64, u64)> {
    if !folder.is_dir() {
        bail!("Folder path does not exist: {}", folder.display());
    }
    let (mut imported, mut skipped, mut failed) = (0, 0, 0);
    for path in uploads::scan(folder)? {
        if stop.is_some_and(Shutdown::requested) {
            break;
        }
        if path
            .file_name()
            .is_none_or(|name| name != uploads::FILENAME)
        {
            continue;
        }
        match uploads::import_companion(&releases.verify.db, &path, delete, delete_failed).await {
            Ok(uploads::NfoOutcome::Imported) => imported += 1,
            Ok(uploads::NfoOutcome::Skipped(_)) => skipped += 1,
            Err(failure) => {
                warn!(path = %path.display(), error = format!("{failure:#}"), "paired NFO import failed");
                failed += 1;
            }
        }
    }
    Ok((imported, skipped, failed))
}
