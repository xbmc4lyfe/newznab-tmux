//! `nntmux-ctl verify repairable`: spot checks published releases with bounded article reads.
//!
//! Ported from `releases:verify-repairable`. A release is audited by reading its stored NZB as a
//! manifest and sampling it the way the candidate worker samples a new upload. An inconclusive
//! result is kept and retried later; only a proven verdict ends an audit. With `--delete`, a release
//! proven unrepairable is deleted in the transaction that records the verdict, and the candidate
//! worker's scratch cleanup removes its preview files afterwards ([`cleanup`]).
//!
//! The spot check itself never proves a release unrepairable: like PHP, only a partial release whose
//! NZB lost articles or files recorded at publication is.

use std::io::Write;
use std::os::unix::fs::{DirBuilderExt, PermissionsExt};
use std::path::{Path, PathBuf};
use std::sync::LazyLock;

use anyhow::{Context, Result, anyhow, bail};
use chrono::Duration;
use regex::Regex;
use serde::Serialize;
use serde_json::{Value, json};
use sqlx::{MySqlConnection, MySqlPool};

use crate::candidates::now;
use crate::config::VerificationConfig;
use crate::context::Verify;
use crate::in_tx;
use crate::inventory;
use crate::ledger::{enter_publication, publication_keys};
use crate::locks::WorkerLocks;
use crate::nzb;
use crate::reader::{ReleaseFacts, read_manifest};
use crate::releases::{release_files, request_search_delete};
use crate::spot_check::{self, ArticleSource, SpotCheckOptions};

const STATE_UNAVAILABLE: &str = "Audit state is unavailable.";
const SCRATCH_INACCESSIBLE: &str = "Audit scratch root is inaccessible or linked.";
const REASON_BYTES: usize = 2000;

static GUID: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^[a-zA-Z0-9_-]{1,40}$").expect("valid"));
static GENERATION: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^[a-f0-9]{32}$").expect("valid"));

/// What one run audits.
#[derive(Clone, Copy, Debug)]
pub struct AuditOptions {
    /// Report without deleting, even with `delete`.
    pub dry_run: bool,
    /// Delete releases proven unrepairable.
    pub delete: bool,
    /// Most releases attempted.
    pub limit: i64,
    /// Start after this release ID, unless `resume`.
    pub since_id: i64,
    /// Continue from the persisted cursor, and take due retries in turn with fresh releases.
    pub resume: bool,
}

/// One audited release, as appended to `audit.jsonl`.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct AuditRecord {
    pub id: i64,
    pub verdict: String,
    pub reason: String,
    #[serde(skip_serializing_if = "std::ops::Not::not")]
    pub deleted: bool,
}

/// Why a run stopped without auditing.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Deferred {
    /// A candidate worker or another audit holds the worker gate.
    WorkerPriority,
    /// New candidates are waiting for a worker.
    CandidatesDue,
}

#[derive(Debug, Default)]
pub struct AuditReport {
    pub deferred: Option<Deferred>,
    pub records: Vec<AuditRecord>,
}

#[derive(sqlx::FromRow)]
struct AuditedRelease {
    id: i64,
    guid: String,
    name: String,
    searchname: String,
    fromname: Option<String>,
    postdate: Option<String>,
    groups_id: i64,
    size: i64,
    article_fingerprint: Option<String>,
    predb_id: i64,
    late_article_candidate_id: Option<i64>,
    articles_found: Option<i64>,
    articles_expected: Option<i64>,
    files_found: Option<i64>,
    files_expected: Option<i64>,
}

/// The columns that identify the NZB generation an audit verdict was reached on.
type Generation = (
    Option<i64>,
    Option<i64>,
    Option<i64>,
    Option<i64>,
    Option<i64>,
    i64,
);

impl AuditedRelease {
    fn generation(&self) -> Generation {
        (
            self.late_article_candidate_id,
            self.articles_found,
            self.articles_expected,
            self.files_found,
            self.files_expected,
            self.size,
        )
    }
}

const RELEASE_COLUMNS: &str = "CAST(id AS SIGNED) AS id, guid, name, searchname, fromname, \
     DATE_FORMAT(postdate, '%Y-%m-%d %H:%i:%s') AS postdate, CAST(groups_id AS SIGNED) AS groups_id, \
     CAST(size AS SIGNED) AS size, article_fingerprint, CAST(predb_id AS SIGNED) AS predb_id, \
     CAST(late_article_candidate_id AS SIGNED) AS late_article_candidate_id, \
     CAST(articles_found AS SIGNED) AS articles_found, CAST(articles_expected AS SIGNED) AS articles_expected, \
     CAST(files_found AS SIGNED) AS files_found, CAST(files_expected AS SIGNED) AS files_expected";

#[derive(sqlx::FromRow)]
struct ScratchRow {
    id: i64,
}

fn scratch_root(config: &VerificationConfig) -> PathBuf {
    config.storage_path.join("scratch")
}

/// The scratch root may be absent, but never a link or something unreadable.
fn check_scratch(scratch: &Path) -> Result<()> {
    match std::fs::symlink_metadata(scratch) {
        Err(failure) if failure.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(_) => bail!(SCRATCH_INACCESSIBLE),
        Ok(meta) if meta.file_type().is_symlink() || !meta.is_dir() => bail!(SCRATCH_INACCESSIBLE),
        Ok(meta) if meta.permissions().mode() & 0o500 != 0o500 => bail!(SCRATCH_INACCESSIBLE),
        Ok(_) => Ok(()),
    }
}

fn ensure_directory(path: &Path) -> Result<()> {
    std::fs::DirBuilder::new()
        .recursive(true)
        .mode(0o700)
        .create(path)
        .with_context(|| format!("creating {}", path.display()))
}

/// PHP's `mb_strcut`: at most `bytes` bytes, cut back to a character boundary.
fn cut(value: &str, bytes: usize) -> &str {
    if value.len() <= bytes {
        return value;
    }
    let mut end = bytes;
    while !value.is_char_boundary(end) {
        end -= 1;
    }
    &value[..end]
}

/// A new candidate waiting for a worker takes priority over an audit.
pub async fn new_candidates_due(pool: &MySqlPool) -> Result<bool> {
    let current = now();
    let found: Option<i32> = sqlx::query_scalar(
        "SELECT 1 FROM release_verification_candidates WHERE release_id IS NULL \
         AND status IN ('ready', 'pending', 'unknown', 'verifying') AND next_attempt_at <= ? \
         AND (lease_until IS NULL OR lease_until < ?) LIMIT 1",
    )
    .bind(current)
    .bind(current)
    .fetch_optional(pool)
    .await?;
    Ok(found.is_some())
}

/// Runs one audit pass, holding every host's verification workers out while it does.
pub async fn run(
    verify: &Verify,
    source: &dyn ArticleSource,
    options: AuditOptions,
    mut report_line: impl FnMut(&str),
) -> Result<AuditReport> {
    let config = &verify.config;
    let root = config.storage_path.clone();
    ensure_directory(&root)?;
    let scratch = scratch_root(config);
    check_scratch(&scratch)?;
    let mut locks = WorkerLocks::new(&verify.db, config).await?;
    if !locks.try_exclusive().await? {
        return Ok(AuditReport {
            deferred: Some(Deferred::WorkerPriority),
            records: Vec::new(),
        });
    }
    let outcome = audit_locked(verify, source, options, &scratch, &mut report_line).await;
    locks.close().await;
    outcome
}

async fn audit_locked(
    verify: &Verify,
    source: &dyn ArticleSource,
    options: AuditOptions,
    scratch: &Path,
    report_line: &mut impl FnMut(&str),
) -> Result<AuditReport> {
    let db = &verify.db;
    let config = &verify.config;
    let mut report = AuditReport::default();
    check_scratch(scratch)?;
    if new_candidates_due(db).await? {
        report.deferred = Some(Deferred::CandidatesDue);
        return Ok(report);
    }
    let state: Option<(i64, bool)> = sqlx::query_as(
        "SELECT CAST(audit_cursor AS SIGNED), audit_retry_turn FROM release_verification_state WHERE id = 1",
    )
    .fetch_optional(db)
    .await?;
    let Some((saved_cursor, mut retry_turn)) = state else {
        bail!(STATE_UNAVAILABLE);
    };
    let cursor = if options.resume {
        saved_cursor
    } else {
        options.since_id
    };
    let limit = options.limit.max(1);
    let mut fresh: std::collections::VecDeque<i64> = sqlx::query_scalar(
        "SELECT CAST(id AS SIGNED) FROM releases WHERE id > ? ORDER BY id LIMIT ?",
    )
    .bind(cursor)
    .bind(limit)
    .fetch_all(db)
    .await?
    .into();
    let mut retries: std::collections::VecDeque<i64> = if options.resume {
        sqlx::query_scalar(
            "SELECT CAST(release_id AS SIGNED) FROM release_verification_audit_retries \
             WHERE next_attempt_at <= ? ORDER BY next_attempt_at, release_id LIMIT ?",
        )
        .bind(now())
        .bind(limit)
        .fetch_all(db)
        .await?
        .into()
    } else {
        Default::default()
    };
    let spot_check = SpotCheckOptions {
        articles: config.spot_check_articles,
        bytes: config.spot_check_bytes,
        article_bytes: config.spot_check_article_bytes,
        stat_all_articles: config.stat_all_articles,
    };
    let mut attempted = 0;
    while attempted < limit && (!fresh.is_empty() || !retries.is_empty()) {
        if new_candidates_due(db).await? {
            report.deferred = Some(Deferred::CandidatesDue);
            break;
        }
        let is_retry = !retries.is_empty() && (retry_turn || fresh.is_empty());
        let id = if is_retry {
            retries.pop_front()
        } else {
            fresh.pop_front()
        }
        .expect("a queue is non-empty");
        let release: Option<AuditedRelease> = sqlx::query_as(sqlx::AssertSqlSafe(format!(
            "SELECT {RELEASE_COLUMNS} FROM releases WHERE id = ?"
        )))
        .bind(id)
        .fetch_optional(db)
        .await?;
        let Some(release) = release else {
            forget_missing(db, id).await?;
            continue;
        };
        attempted += 1;
        let entry = register(db, &config.host, id).await?;
        ensure_directory(&scratch.join(format!("audit-{id}-{entry}")))?;
        let (verdict, reason) = examine(db, source, &spot_check, &release).await;
        record(db, entry, &verdict, &reason).await?;
        let delete = !options.dry_run && options.delete && verdict == "unrepairable";
        let retry_at = now() + Duration::seconds(config.retry_seconds.max(1));
        in_tx!(db, 5, |tx| {
            // Deletions serialize with publications that may choose this release as a duplicate
            // (they share a search key); the shared ledger row is locked last.
            let keys = publication_keys(
                release.size,
                release.article_fingerprint.as_deref(),
                &release.name,
                &release.searchname,
                release.predb_id,
            );
            enter_publication(tx, &keys)
                .await
                .map_err(|_| anyhow!(STATE_UNAVAILABLE))?;
            if verdict == "unknown" {
                sqlx::query(
                    "INSERT INTO release_verification_audit_retries (release_id, next_attempt_at, reason) \
                     VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE next_attempt_at = VALUES(next_attempt_at), \
                     reason = VALUES(reason)",
                )
                .bind(id)
                .bind(retry_at)
                .bind(cut(&reason, REASON_BYTES))
                .execute(&mut *tx)
                .await?;
            } else {
                if delete {
                    commit_deletion(tx, &release, entry).await?;
                }
                sqlx::query("DELETE FROM release_verification_audit_retries WHERE release_id = ?")
                    .bind(id)
                    .execute(&mut *tx)
                    .await?;
                sqlx::query(
                    "UPDATE release_verification_scratch SET terminal = 1 \
                     WHERE id = ? AND audit_release_id IS NOT NULL",
                )
                .bind(entry)
                .execute(&mut *tx)
                .await?;
            }
            let current: Option<i64> = sqlx::query_scalar(
                "SELECT CAST(audit_cursor AS SIGNED) FROM release_verification_state WHERE id = 1 FOR UPDATE",
            )
            .fetch_optional(&mut *tx)
            .await?;
            let Some(current) = current else {
                bail!(STATE_UNAVAILABLE);
            };
            sqlx::query(
                "UPDATE release_verification_state SET audit_cursor = ?, audit_retry_turn = ? WHERE id = 1",
            )
            .bind(current.max(id))
            .bind(!is_retry)
            .execute(&mut *tx)
            .await?;
            Ok::<(), anyhow::Error>(())
        })?;
        let record = AuditRecord {
            id,
            verdict,
            reason,
            deleted: delete,
        };
        let line = serde_json::to_string(&record)?;
        append_report(&config.storage_path, &line)?;
        retry_turn = !is_retry;
        report_line(&line);
        report.records.push(record);
    }
    Ok(report)
}

/// A release deleted since it was listed has nothing left to audit.
async fn forget_missing(db: &MySqlPool, id: i64) -> Result<()> {
    in_tx!(db, 5, |tx| {
        sqlx::query("DELETE FROM release_verification_audit_retries WHERE release_id = ?")
            .bind(id)
            .execute(&mut *tx)
            .await?;
        sqlx::query(
            "UPDATE release_verification_scratch SET terminal = 1 WHERE audit_release_id = ?",
        )
        .bind(id)
        .execute(&mut *tx)
        .await?;
        Ok::<(), anyhow::Error>(())
    })
}

/// This host's open audit of a release, or a new one. Registered before its directory exists, so
/// the worker's cleanup only ever removes directories it knows about.
async fn register(db: &MySqlPool, host: &str, release: i64) -> Result<i64> {
    let active: Option<ScratchRow> = sqlx::query_as(
        "SELECT CAST(id AS SIGNED) AS id FROM release_verification_scratch \
         WHERE host = ? AND audit_release_id = ? AND terminal = 0 LIMIT 1",
    )
    .bind(host)
    .bind(release)
    .fetch_optional(db)
    .await?;
    if let Some(row) = active {
        return Ok(row.id);
    }
    let id = sqlx::query(
        "INSERT INTO release_verification_scratch (host, audit_release_id) VALUES (?, ?)",
    )
    .bind(host)
    .bind(release)
    .execute(db)
    .await?
    .last_insert_id();
    Ok(id as i64)
}

async fn record(db: &MySqlPool, entry: i64, verdict: &str, reason: &str) -> Result<()> {
    sqlx::query(
        "UPDATE release_verification_scratch SET audit_verdict = ?, audit_reason = ? \
         WHERE id = ? AND audit_release_id IS NOT NULL",
    )
    .bind(verdict)
    .bind(cut(reason, REASON_BYTES))
    .bind(entry)
    .execute(db)
    .await?;
    Ok(())
}

/// The verdict on one release. Every failure is an inconclusive `unknown`.
async fn examine(
    db: &MySqlPool,
    source: &dyn ArticleSource,
    options: &SpotCheckOptions,
    release: &AuditedRelease,
) -> (String, String) {
    match try_examine(db, source, options, release).await {
        Ok(verdict) => verdict,
        Err(failure) => ("unknown".to_owned(), failure.to_string()),
    }
}

async fn try_examine(
    db: &MySqlPool,
    source: &dyn ArticleSource,
    options: &SpotCheckOptions,
    release: &AuditedRelease,
) -> Result<(String, String)> {
    if release.late_article_candidate_id.is_some() {
        // Late articles may still replace its NZB; the flag only clears, so a later retry is stable.
        bail!("Partial release is still awaiting late articles.");
    }
    let Some(xml) = nzb::read(db, &release.guid).await? else {
        bail!("Release NZB is unavailable.");
    };
    let facts = ReleaseFacts {
        searchname: release.searchname.clone(),
        fromname: release.fromname.clone().unwrap_or_default(),
        postdate: release.postdate.clone(),
        groups_id: release.groups_id,
        ..ReleaseFacts::default()
    };
    // A partial publication's NZB is knowingly incomplete and can never grow; audit what it has,
    // but only while it still holds every article and file recorded at publication.
    let manifest = read_manifest(&xml, &facts)?;
    let partial = release.articles_expected.is_some();
    if partial {
        let found = inventory::completeness(&manifest);
        let recorded_articles = release.articles_found.unwrap_or(0);
        let recorded_files = release.files_found.unwrap_or(0);
        // Fewer than recorded is a truncated NZB. More is an upgrade whose NZB was installed but
        // whose transaction never committed (an interrupted worker): a verified, fuller NZB,
        // sampled as usual.
        if found.articles_found < recorded_articles || found.files_found < recorded_files {
            return Ok((
                "unrepairable".to_owned(),
                format!(
                    "Partial release NZB holds {} of {} recorded articles and {} of {} recorded files.",
                    found.articles_found, recorded_articles, found.files_found, recorded_files
                ),
            ));
        }
    }
    let result = spot_check::verify(source, options, &manifest, partial).await;
    Ok((result.verdict, result.reason))
}

fn append_report(root: &Path, line: &str) -> Result<()> {
    let mut file = std::fs::OpenOptions::new()
        .create(true)
        .append(true)
        .open(root.join("audit.jsonl"))
        .context("Cannot persist audit report.")?;
    file.write_all(format!("{line}\n").as_bytes())
        .context("Cannot persist audit report.")
}

/// The ownership receipt for a database NZB, in the format PHP's audits also wrote.
fn database_receipt(guid: &str, generation: Option<&str>) -> Value {
    json!({
        "version": 1,
        "kind": "database",
        "guid": guid,
        "path": format!("database-nzb:{guid}"),
        "generation": generation,
    })
}

/// Deletes an audited release inside the transaction that marks its audit terminal and advances
/// the cursor. The verdict holds only for the generation audited: a partial upgraded, or fenced
/// for late articles, meanwhile has another NZB.
async fn commit_deletion(
    tx: &mut MySqlConnection,
    release: &AuditedRelease,
    entry: i64,
) -> Result<()> {
    let current: Option<AuditedRelease> = sqlx::query_as(sqlx::AssertSqlSafe(format!(
        "SELECT {RELEASE_COLUMNS} FROM releases WHERE id = ? AND guid = ? FOR UPDATE"
    )))
    .bind(release.id)
    .bind(&release.guid)
    .fetch_optional(&mut *tx)
    .await?;
    if current.is_none_or(|current| current.generation() != release.generation()) {
        bail!("Audited release changed before deletion.");
    }
    let generation: Option<String> =
        sqlx::query_scalar("SELECT generation FROM release_nzb_contents WHERE guid = ? FOR UPDATE")
            .bind(&release.guid)
            .fetch_optional(&mut *tx)
            .await?;
    let Some(generation) = generation else {
        bail!("Unimported database NZB cannot qualify a release for audit deletion.");
    };
    let receipt = database_receipt(&release.guid, Some(&generation));
    let mut paths = vec![receipt["path"].clone()];
    paths.extend(
        release_files(&release.guid)
            .into_iter()
            .map(|path| Value::String(path.to_string_lossy().into_owned())),
    );
    let inventory = json!({"version": 1, "nzb_receipt": receipt, "paths": paths});
    let updated = sqlx::query(
        "UPDATE release_verification_scratch SET delete_guid = ?, delete_paths = ? \
         WHERE id = ? AND audit_release_id = ? AND terminal = 0 AND audit_verdict = 'unrepairable'",
    )
    .bind(&release.guid)
    .bind(inventory.to_string())
    .bind(entry)
    .bind(release.id)
    .execute(&mut *tx)
    .await?
    .rows_affected();
    if updated != 1 {
        bail!("Proven audit deletion state is unavailable.");
    }
    request_search_delete(tx, release.id).await?;
    // Foreign keys take the NZB, fingerprint work and the rest; downloads have none.
    sqlx::query("DELETE FROM user_downloads WHERE releases_id = ?")
        .bind(release.id)
        .execute(&mut *tx)
        .await?;
    sqlx::query("DELETE FROM releases WHERE id = ? AND guid = ?")
        .bind(release.id)
        .bind(&release.guid)
        .execute(&mut *tx)
        .await?;
    Ok(())
}

/// A terminal scratch entry whose audit deleted its release.
#[derive(Clone, Debug)]
pub struct DeletedAudit {
    pub terminal: bool,
    pub audit_release_id: Option<i64>,
    pub audit_verdict: Option<String>,
    pub delete_guid: String,
    pub delete_paths: Option<String>,
}

fn accessible_parent(path: &Path) -> Result<()> {
    let Some(parent) = path.parent() else {
        return Ok(());
    };
    match std::fs::metadata(parent) {
        Err(failure) if failure.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(_) => bail!("Audit artifact directory is inaccessible."),
        Ok(meta) if !meta.is_dir() || meta.permissions().mode() & 0o500 != 0o500 => {
            bail!("Audit artifact directory is inaccessible.")
        }
        Ok(_) => Ok(()),
    }
}

/// Removes what a committed audit deletion left: the preview and sample files, and the search
/// document. Run by the candidate worker under its slot lock; an error keeps the entry to retry.
pub async fn cleanup(pool: &MySqlPool, entry: &DeletedAudit) -> Result<()> {
    let Some(release_id) = entry
        .audit_release_id
        .filter(|_| entry.terminal && entry.audit_verdict.as_deref() == Some("unrepairable"))
    else {
        bail!("Audit deletion is not committed.");
    };
    let current: Option<i32> =
        sqlx::query_scalar("SELECT 1 FROM releases WHERE id = ? OR guid = ? LIMIT 1")
            .bind(release_id)
            .bind(&entry.delete_guid)
            .fetch_optional(pool)
            .await?;
    if current.is_some() {
        bail!("Audit cleanup cannot remove a current release.");
    }
    let inventory: Value = serde_json::from_str(entry.delete_paths.as_deref().unwrap_or("null"))?;
    if inventory.is_array() {
        bail!("Legacy audit artifact ownership is unqualified; retaining it for review.");
    }
    let (Some(receipt), Some(paths)) = (
        inventory
            .get("nzb_receipt")
            .filter(|value| value.is_object()),
        inventory.get("paths").and_then(Value::as_array),
    ) else {
        bail!("Audit artifact inventory is unavailable.");
    };
    if inventory.get("version") != Some(&json!(1)) || paths.len() != 9 {
        bail!("Audit artifact inventory is unavailable.");
    }
    if receipt.get("guid").and_then(Value::as_str) != Some(entry.delete_guid.as_str())
        || receipt.get("path") != paths.first()
    {
        bail!("Audit NZB receipt does not match its retained inventory.");
    }
    if paths[0].as_str().is_none_or(str::is_empty) {
        bail!("Audit NZB artifact path is invalid.");
    }
    if !database_source_gone(pool, receipt).await? {
        bail!("Audit NZB artifact deletion will retry.");
    }
    let mut seen = std::collections::HashSet::new();
    for path in &paths[1..] {
        let Some(path) = path.as_str().filter(|path| !path.is_empty()) else {
            bail!("Audit artifact path is invalid.");
        };
        if !seen.insert(path) {
            continue;
        }
        let path = Path::new(path);
        accessible_parent(path)?;
        match std::fs::remove_file(path) {
            Ok(()) => {}
            Err(failure) if failure.kind() == std::io::ErrorKind::NotFound => {}
            Err(_) => bail!("Audit artifact deletion will retry."),
        }
    }
    let mut conn = pool.acquire().await?;
    request_search_delete(&mut conn, release_id).await
}

/// The audit owned its NZB source, and that source is gone. The release's foreign key deleted it
/// in the audit transaction; a new publication may reuse the GUID, but never the random generation
/// this receipt owns. Receipts for NZB files, from a PHP install that stored them on disk, are kept
/// for an operator: this stack has no NZB files to delete.
async fn database_source_gone(pool: &MySqlPool, receipt: &Value) -> Result<bool> {
    match receipt.get("kind").and_then(Value::as_str) {
        Some("database") => {}
        Some("absent") => return Ok(true),
        _ => bail!("NZB file ownership receipts are retained for review."),
    }
    let guid = receipt.get("guid").and_then(Value::as_str).unwrap_or("");
    let generation = match receipt.get("generation") {
        None | Some(Value::Null) => None,
        Some(Value::String(value)) if GENERATION.is_match(value) => Some(value.as_str()),
        Some(_) => bail!("Malformed database NZB ownership receipt."),
    };
    if receipt.get("version") != Some(&json!(1))
        || !GUID.is_match(guid)
        || receipt.get("path").and_then(Value::as_str)
            != Some(format!("database-nzb:{guid}").as_str())
    {
        bail!("Malformed database NZB ownership receipt.");
    }
    let found: Option<i32> =
        sqlx::query_scalar("SELECT 1 FROM release_nzb_contents WHERE guid = ? AND generation = ?")
            .bind(guid)
            .bind(generation.unwrap_or(""))
            .fetch_optional(pool)
            .await?;
    Ok(found.is_none())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn cut_keeps_whole_characters() {
        assert_eq!(cut("abc", 5), "abc");
        assert_eq!(cut("aé", 2), "a");
        assert_eq!(cut("aé", 3), "aé");
    }

    #[test]
    fn records_name_deletion_only_when_it_happened() {
        let mut record = AuditRecord {
            id: 7,
            verdict: "sampled".to_owned(),
            reason: "ok".to_owned(),
            deleted: false,
        };
        assert_eq!(
            serde_json::to_string(&record).unwrap(),
            r#"{"id":7,"verdict":"sampled","reason":"ok"}"#
        );
        record.deleted = true;
        assert!(
            serde_json::to_string(&record)
                .unwrap()
                .ends_with(r#""deleted":true}"#)
        );
    }
}
