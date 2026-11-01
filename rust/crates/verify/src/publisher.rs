//! Publishing a verified candidate: as a new release, an upgrade of its partial, or reuse of a
//! release that already holds its articles.
//!
//! Everything derived from the manifest alone is computed before any lock. The transaction takes
//! the publication stripes first, so duplicate detection sees every committed release sharing a
//! search key, then the candidate. Its NZB, search request and fingerprint work commit with the row,
//! so nothing has to be settled afterwards.

use std::collections::HashSet;
use std::time::Instant;

use anyhow::{Result, anyhow, bail};
use rand::RngCore;
use sqlx::MySqlConnection;
use thiserror::Error;
use tracing::warn;

use crate::candidates::queue::{self, Claim};
use crate::candidates::{self, Candidate, release_late_article_fence};
use crate::context::Verify;
use crate::metadata::{Metadata, Resolver};
use crate::nzb::coverage::{
    CoverageBudget, coverage_ids, covered_snapshot, document_fingerprint, parse_document,
};
use crate::nzb::parse::ParsedNzb;
use crate::nzb::{ReleaseRef, compress, document, put_locked};
use crate::reader::{ReleaseFacts, read_parsed};
use crate::releases::request_search_update;
use crate::spot_check::VerificationResult;
use crate::{groups, in_tx, inventory, ledger, manifest, policy};

const NZB_NONE: i32 = 0;
const NZB_ADDED: i32 = 1;
const COVERAGE_CANDIDATES: usize = 128;
const CACHE_VERSION_KEY: &str = "releases:cache_version";

#[derive(Debug, Error)]
pub enum PublishError {
    #[error("Stale verification result cannot publish.")]
    Stale,
    /// Missing files or articles may still satisfy the policy, so this isn't a final rejection.
    #[error("{0}")]
    PartialDeferred(String),
}

/// What publishing did. `release` is the release the candidate now belongs to, if any.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Outcome {
    Published(i64),
    Upgraded(i64),
    Duplicate(i64),
    Filtered,
    Abandoned,
    MetadataReview,
    NotPassed,
}

impl Outcome {
    pub fn release(self) -> Option<i64> {
        match self {
            Self::Published(id) | Self::Upgraded(id) | Self::Duplicate(id) => Some(id),
            _ => None,
        }
    }
}

/// What the transaction leaves for after its commit.
struct Done {
    outcome: Outcome,
    unreferenced: Option<String>,
}

fn done(outcome: Outcome) -> Done {
    Done {
        outcome,
        unreferenced: None,
    }
}

/// Computed from the manifest before any lock.
struct Prepared {
    metadata: Metadata,
    size: i64,
    keys: Vec<String>,
    rejection: Option<String>,
    body: Vec<u8>,
    fingerprint: Option<String>,
    required: Option<HashSet<String>>,
    archived_path: String,
    archived: Option<manifest::Manifest>,
}

fn guid() -> String {
    let mut bytes = [0u8; 16];
    rand::rng().fill_bytes(&mut bytes);
    bytes[6] = (bytes[6] & 0x0F) | 0x40;
    bytes[8] = (bytes[8] & 0x3F) | 0x80;
    let hex = hex::encode(bytes);
    format!(
        "{}-{}-{}-{}-{}",
        &hex[..8],
        &hex[8..12],
        &hex[12..16],
        &hex[16..20],
        &hex[20..]
    )
}

fn check_passworded_rars() -> bool {
    // Only these spellings make a config value true in Laravel, and the setting compares to true.
    matches!(
        nntmux_core::var_or("CHECK_PASSWORDED_RARS", "false")
            .to_ascii_lowercase()
            .as_str(),
        "true" | "(true)"
    )
}

/// Only Usenet inventories can grow. An imported NZB's candidate never receives more articles.
fn awaits_late_articles(manifest: &manifest::Manifest) -> bool {
    manifest.metadata_source.as_deref() != Some("import")
}

async fn release_keys(
    conn: &mut MySqlConnection,
    release: i64,
    lock: bool,
) -> Result<Option<Vec<String>>> {
    let lock = if lock { " FOR UPDATE" } else { "" };
    let row: Option<(i64, Option<String>, String, String, i64)> =
        sqlx::query_as(sqlx::AssertSqlSafe(format!(
            "SELECT CAST(size AS SIGNED), article_fingerprint, name, searchname, CAST(predb_id AS SIGNED) \
             FROM releases WHERE id = ?{lock}"
        )))
        .bind(release)
        .fetch_optional(&mut *conn)
        .await?;
    Ok(row.map(|(size, fingerprint, name, searchname, predb)| {
        ledger::publication_keys(size, fingerprint.as_deref(), &name, &searchname, predb)
    }))
}

async fn prepare(
    verify: &Verify,
    resolver: &Resolver<'_>,
    claim: &Claim,
    manifest: &manifest::Manifest,
) -> Result<Prepared> {
    let path: Option<String> = sqlx::query_scalar(
        "SELECT manifest_path FROM release_verification_candidates WHERE id = ? AND version = ? \
         AND manifest_hash = ? AND lease_token = ? AND release_id IS NULL AND lease_until >= ?",
    )
    .bind(claim.id())
    .bind(claim.candidate.version)
    .bind(&claim.candidate.manifest_hash)
    .bind(&claim.token)
    .bind(candidates::now())
    .fetch_optional(&verify.db)
    .await?;
    let Some(archived_path) = path else {
        return Err(PublishError::Stale.into());
    };
    let metadata = resolver.resolve(manifest).await?;
    // A filtered candidate never writes or compares an NZB, so one it can't render is still filtered.
    let rejection = policy::verified_rejection(&verify.db, &verify.config, manifest).await?;
    let rendered = if rejection.is_none() {
        document(manifest, manifest.verified_file_count.unwrap_or(0))?
    } else {
        String::new()
    };
    let parsed = parse_document(rendered.as_bytes());
    let fingerprint = parsed.as_ref().and_then(document_fingerprint);
    let size = inventory::bytes(manifest);
    let mut keys = ledger::publication_keys(
        size,
        fingerprint.as_deref(),
        &metadata.name,
        &metadata.searchname,
        metadata.predb_id,
    );
    // Upgrading a partial must serialize with anything that can reach it under its current keys.
    if let Some(previous) = manifest.upgrades_release_id {
        let mut conn = verify.db.acquire().await?;
        keys.extend(
            release_keys(&mut conn, previous, false)
                .await?
                .unwrap_or_default(),
        );
    }
    keys.sort();
    keys.dedup();
    let archived = manifest::read(&verify.db, &verify.config, &archived_path)
        .await
        .ok();
    let required = parsed.as_ref().and_then(coverage_ids);
    let body = compress(rendered.as_bytes())?;
    Ok(Prepared {
        metadata,
        size,
        keys,
        rejection,
        body,
        fingerprint,
        required,
        archived_path,
        archived,
    })
}

/// A covered release must keep any password the candidate supplies.
fn preserves_passwords(snapshot: &ParsedNzb, manifest: &manifest::Manifest) -> bool {
    let passwords = manifest.passwords();
    if passwords.is_empty() {
        return true;
    }
    if !manifest::valid_passwords(passwords) {
        return false;
    }
    let facts = ReleaseFacts {
        searchname: manifest.subject.clone(),
        fromname: manifest.fromname.clone(),
        groups_id: manifest.groups_id,
        ..ReleaseFacts::default()
    };
    read_parsed(snapshot, &facts).is_ok_and(|retained| retained.passwords() == passwords)
}

struct Search<'a> {
    verify: &'a Verify,
    claim: &'a Claim,
    manifest: &'a manifest::Manifest,
    required: &'a HashSet<String>,
    budget: CoverageBudget,
    seen: HashSet<i64>,
    last_heartbeat: Option<Instant>,
    metadata_conflict: bool,
}

impl Search<'_> {
    fn exhausted(&self) -> bool {
        self.seen.len() >= COVERAGE_CANDIDATES || self.budget.exhausted()
    }

    async fn current(tx: &mut MySqlConnection, id: i64, guid: &str) -> Result<bool> {
        let found: Option<String> =
            sqlx::query_scalar("SELECT guid FROM releases WHERE id = ? AND nzbstatus = ?")
                .bind(id)
                .bind(NZB_ADDED)
                .fetch_optional(&mut *tx)
                .await?;
        Ok(found.as_deref() == Some(guid))
    }

    /// Whether this candidate release holds every required article.
    async fn verify_candidate(
        &mut self,
        tx: &mut MySqlConnection,
        id: i64,
        guid: &str,
    ) -> Result<bool> {
        if guid.is_empty() || self.seen.contains(&id) || self.exhausted() {
            return Ok(false);
        }
        self.seen.insert(id);
        if self
            .last_heartbeat
            .is_none_or(|last| last.elapsed().as_secs() >= 1)
        {
            queue::heartbeat(
                tx,
                self.verify.config.lease_seconds,
                self.claim.id(),
                &self.claim.token,
            )
            .await?;
            self.last_heartbeat = Some(Instant::now());
        }
        // Hold the nominated row stable while reading its stored document.
        let row: Option<u32> =
            sqlx::query_scalar("SELECT id FROM releases WHERE id = ? AND nzbstatus = ? FOR UPDATE")
                .bind(id)
                .bind(NZB_ADDED)
                .fetch_optional(&mut *tx)
                .await?;
        if row.is_none() || !Self::current(tx, id, guid).await? {
            return Ok(false);
        }
        let Some(snapshot) = covered_snapshot(tx, self.required, guid, &mut self.budget).await?
        else {
            return Ok(false);
        };
        if !Self::current(tx, id, guid).await? {
            return Ok(false);
        }
        if !preserves_passwords(&snapshot, self.manifest) {
            self.metadata_conflict = true;
            return Ok(false);
        }
        Ok(true)
    }

    /// `findCoveredUpload`: fingerprint matches, then metadata nominations, then exact size. Metadata
    /// only nominates: a release counts only when its stored document proves the articles.
    async fn find(&mut self, tx: &mut MySqlConnection, prepared: &Prepared) -> Result<Option<i64>> {
        let mut rounds: Vec<(String, Vec<(i64, String)>)> = Vec::new();
        if let Some(fingerprint) = &prepared.fingerprint {
            rounds.push((
                "fingerprint".to_owned(),
                sqlx::query_as(
                    "SELECT CAST(id AS SIGNED), guid FROM releases WHERE article_fingerprint = ? ORDER BY id LIMIT 128",
                )
                .bind(fingerprint)
                .fetch_all(&mut *tx)
                .await?,
            ));
        }
        let metadata = &prepared.metadata;
        if metadata.predb_id > 0 {
            rounds.push((
                "predb".to_owned(),
                sqlx::query_as(
                    "SELECT CAST(id AS SIGNED), guid FROM releases WHERE predb_id = ? AND nzbstatus = ? ORDER BY id LIMIT 128",
                )
                .bind(metadata.predb_id)
                .bind(NZB_ADDED)
                .fetch_all(&mut *tx)
                .await?,
            ));
        }
        for (column, value) in [
            ("searchname", &metadata.searchname),
            ("name", &metadata.name),
        ] {
            if !value.is_empty() {
                rounds.push((
                    column.to_owned(),
                    sqlx::query_as(sqlx::AssertSqlSafe(format!(
                        "SELECT CAST(id AS SIGNED), guid FROM releases WHERE {column} = ? AND nzbstatus = ? ORDER BY id LIMIT 128"
                    )))
                    .bind(value)
                    .bind(NZB_ADDED)
                    .fetch_all(&mut *tx)
                    .await?,
                ));
            }
        }
        if prepared.size > 0 {
            rounds.push((
                "size".to_owned(),
                sqlx::query_as(
                    "SELECT CAST(id AS SIGNED), guid FROM releases WHERE size = ? AND nzbstatus = ? ORDER BY id LIMIT 128",
                )
                .bind(prepared.size)
                .bind(NZB_ADDED)
                .fetch_all(&mut *tx)
                .await?,
            ));
        }
        for (_, candidates) in rounds {
            for (id, guid) in candidates {
                if self.exhausted() {
                    return Ok(None);
                }
                if self.verify_candidate(tx, id, &guid).await? {
                    return Ok(Some(id));
                }
            }
        }
        Ok(None)
    }
}

/// `(release, metadata conflict)` for the release covering this manifest.
async fn covered_duplicate(
    tx: &mut MySqlConnection,
    verify: &Verify,
    claim: &Claim,
    manifest: &manifest::Manifest,
    prepared: &Prepared,
) -> Result<(Option<i64>, bool)> {
    // No usable inventory, which is what the search would conclude too.
    let Some(required) = prepared.required.as_ref() else {
        return Ok((None, false));
    };
    let mut search = Search {
        verify,
        claim,
        manifest,
        required,
        budget: CoverageBudget::default(),
        seen: HashSet::new(),
        last_heartbeat: None,
        metadata_conflict: false,
    };
    let found = search.find(tx, prepared).await?;
    queue::heartbeat(tx, verify.config.lease_seconds, claim.id(), &claim.token).await?;
    Ok((found, search.metadata_conflict))
}

async fn record_groups(tx: &mut MySqlConnection, release: i64, names: &[String]) -> Result<()> {
    let mut ids: Vec<i64> = groups::ids_by_name(&mut *tx, names)
        .await?
        .into_values()
        .collect();
    ids.sort_unstable();
    ids.dedup();
    for group in ids {
        sqlx::query("INSERT IGNORE INTO releases_groups (releases_id, groups_id) VALUES (?, ?)")
            .bind(release)
            .bind(group)
            .execute(&mut *tx)
            .await?;
    }
    Ok(())
}

/// Returns the manifest to delete after commit, unless it's retained for late articles.
async fn mark_published(
    tx: &mut MySqlConnection,
    verify: &Verify,
    candidate: &Candidate,
    release: i64,
    result: &VerificationResult,
    retain: bool,
) -> Result<Option<String>> {
    let reason = candidates::cut(&result.reason, 2000);
    if retain {
        sqlx::query(
            "UPDATE release_verification_candidates SET release_id = ?, status = ?, reason = ?, verified_at = ?, \
             lease_token = NULL, lease_until = NULL, retained_for_release_id = ?, retained_last_unique_at = ? WHERE id = ?",
        )
        .bind(release)
        .bind(&result.verdict)
        .bind(reason)
        .bind(candidates::now())
        .bind(release)
        .bind(candidate.last_unique_at)
        .bind(candidate.id)
        .execute(&mut *tx)
        .await?;
        return Ok(None);
    }
    sqlx::query(
        "UPDATE release_verification_candidates SET release_id = ?, status = ?, reason = ?, verified_at = ?, \
         lease_token = NULL, lease_until = NULL, manifest_bytes = 0, manifest_path = '' WHERE id = ?",
    )
    .bind(release)
    .bind(&result.verdict)
    .bind(reason)
    .bind(candidates::now())
    .bind(candidate.id)
    .execute(&mut *tx)
    .await?;
    // Release capacity last, so the shared ledger row is held only until commit.
    ledger::adjust(&mut *tx, &verify.config, -candidate.manifest_bytes).await?;
    Ok(Some(candidate.manifest_path.clone()))
}

#[derive(sqlx::FromRow)]
struct Upgradable {
    #[sqlx(try_from = "u32")]
    id: i64,
    guid: String,
    #[sqlx(try_from = "u32")]
    predb_id: i64,
    searchname: String,
}

/// The published partial this generation upgrades, or `None` when it no longer can. Only while this
/// generation holds the inventory: once the pointer clears, a worker may be reading its NZB.
async fn lock_upgradable(
    tx: &mut MySqlConnection,
    manifest: &manifest::Manifest,
    keys: &[String],
    candidate: i64,
) -> Result<Option<Upgradable>> {
    let Some(release) = manifest.upgrades_release_id else {
        return Ok(None);
    };
    let row: Option<Upgradable> = sqlx::query_as(
        "SELECT id, guid, predb_id, searchname FROM releases WHERE id = ? AND articles_expected IS NOT NULL \
         AND nzbstatus = ? AND late_article_candidate_id = ? FOR UPDATE",
    )
    .bind(release)
    .bind(NZB_ADDED)
    .bind(candidate)
    .fetch_optional(&mut *tx)
    .await?;
    let Some(row) = row else {
        return Ok(None);
    };
    let current = release_keys(tx, release, true).await?;
    if !keys.is_empty()
        && current
            .as_ref()
            .is_none_or(|current| current.iter().any(|key| !keys.contains(key)))
    {
        bail!("Partial release changed before its upgrade; retry.");
    }
    Ok(Some(row))
}

/// Rewrites a published partial in place: the same row and GUID, a replaced NZB, and counts cleared
/// once nothing is missing. Everything derived from the smaller NZB is redone.
#[allow(clippy::too_many_arguments)]
async fn upgrade_partial(
    tx: &mut MySqlConnection,
    release: &Upgradable,
    manifest: &manifest::Manifest,
    prepared: &Prepared,
    total_parts: i64,
    candidate: i64,
    retain: bool,
) -> Result<()> {
    let partial = manifest.partial.as_ref();
    let metadata = &prepared.metadata;
    // No NZB-reading worker has touched a fenced partial, so its name came from publication: take
    // the fuller inventory's, unless PreDB has since confirmed one.
    let mut naming = String::new();
    if release.predb_id == 0 {
        naming.push_str(
            ", name = ?, searchname = ?, fromname = ?, categories_id = ?, isrenamed = ?, predb_id = ?",
        );
        if metadata.searchname != release.searchname {
            // Title-derived lookups ran on the old name, so redo them as any rename does.
            naming.push_str(
                ", videos_id = 0, tv_episodes_id = 0, tv_lookup_attempts = 0, tv_lookup_retry_at = NULL, \
                 imdbid = NULL, movieinfo_id = NULL, musicinfo_id = NULL, consoleinfo_id = NULL, gamesinfo_id = 0, \
                 bookinfo_id = NULL, book_lookup_attempts = 0, book_lookup_retry_at = NULL, \
                 book_name_normalized_at = NULL, anidbid = NULL, iscategorized = 1",
            );
        }
    }
    let sql = format!(
        "UPDATE releases SET size = ?, totalpart = ?, articles_found = ?, articles_expected = ?, files_found = ?, \
         files_expected = ?, late_article_candidate_id = ?, article_fingerprint = COALESCE(?, article_fingerprint), \
         completion = 0, passwordstatus = ?, haspreview = -1, nfostatus = -1, jpgstatus = 0, videostatus = 0, \
         proc_pp = 0, proc_par2 = 0, proc_nfo = 0, proc_files = 0, proc_uid = 0, proc_srr = 0, proc_hash16k = 0, \
         proc_crc32 = 0, pp_timeout_count = 0, archive_retry_at = NULL{naming} WHERE id = ?"
    );
    let mut query = sqlx::query(sqlx::AssertSqlSafe(sql))
        .bind(prepared.size)
        .bind(total_parts)
        .bind(partial.map(|partial| partial.articles_found))
        .bind(partial.map(|partial| partial.articles_expected))
        .bind(partial.map(|partial| partial.files_found))
        .bind(partial.map(|partial| partial.files_expected))
        // With no staging, an upgrade with nothing left to wait for needs no fence after commit.
        .bind(retain.then_some(candidate))
        .bind(prepared.fingerprint.as_deref())
        .bind(-i32::from(check_passworded_rars()));
    if release.predb_id == 0 {
        query = query
            .bind(&metadata.name)
            .bind(&metadata.searchname)
            .bind(&metadata.fromname)
            .bind(metadata.categories_id)
            .bind(metadata.isrenamed)
            .bind(metadata.predb_id);
    }
    query.bind(release.id).execute(&mut *tx).await?;
    let source = ReleaseRef {
        id: release.id,
        guid: release.guid.clone(),
    };
    put_locked(&mut *tx, &source, &prepared.body, None, true).await?;
    // Even if no collection has started: a run that read the smaller NZB can then only save while
    // its record still says processing.
    let now = candidates::now();
    sqlx::query(
        "INSERT INTO release_fingerprint_metadata (releases_id, status, created_at, updated_at) VALUES (?, 'stale', ?, ?) \
         ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at)",
    )
    .bind(release.id)
    .bind(now)
    .bind(now)
    .execute(&mut *tx)
    .await?;
    request_search_update(&mut *tx, release.id).await
}

async fn insert_release(
    tx: &mut MySqlConnection,
    manifest: &manifest::Manifest,
    metadata: &Metadata,
    guid: &str,
    size: i64,
    total_parts: i64,
) -> Result<i64> {
    let id = sqlx::query(
        "INSERT INTO releases (name, searchname, totalpart, groups_id, adddate, guid, leftguid, postdate, fromname, \
         size, passwordstatus, haspreview, categories_id, nfostatus, nzbstatus, isrenamed, iscategorized, predb_id) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, -1, ?, -1, ?, ?, 1, ?)",
    )
    .bind(&metadata.name)
    .bind(&metadata.searchname)
    .bind(total_parts)
    .bind(manifest.groups_id)
    .bind(candidates::now())
    .bind(guid)
    .bind(&guid[..1])
    .bind(manifest.date.as_deref())
    .bind(&metadata.fromname)
    .bind(size)
    .bind(-i32::from(check_passworded_rars()))
    .bind(metadata.categories_id)
    .bind(NZB_ADDED)
    .bind(metadata.isrenamed)
    .bind(metadata.predb_id)
    .execute(&mut *tx)
    .await?
    .last_insert_id();
    Ok(id as i64)
}

async fn update_legacy(
    tx: &mut MySqlConnection,
    id: i64,
    manifest: &manifest::Manifest,
    metadata: &Metadata,
    size: i64,
    total_parts: i64,
) -> Result<()> {
    sqlx::query(
        "UPDATE releases SET name = ?, searchname = ?, fromname = ?, predb_id = ?, isrenamed = ?, categories_id = ?, \
         totalpart = ?, groups_id = ?, postdate = ?, size = ?, nzbstatus = ?, iscategorized = 1 WHERE id = ?",
    )
    .bind(&metadata.name)
    .bind(&metadata.searchname)
    .bind(&metadata.fromname)
    .bind(metadata.predb_id)
    .bind(metadata.isrenamed)
    .bind(metadata.categories_id)
    .bind(total_parts)
    .bind(manifest.groups_id)
    .bind(manifest.date.as_deref())
    .bind(size)
    .bind(NZB_ADDED)
    .bind(id)
    .execute(&mut *tx)
    .await?;
    Ok(())
}

/// The locked section. The caller's transaction retries it on lock errors.
async fn publish_locked(
    tx: &mut MySqlConnection,
    verify: &Verify,
    claim: &Claim,
    result: &VerificationResult,
    manifest: &manifest::Manifest,
    prepared: &Prepared,
) -> Result<Done> {
    ledger::enter_publication(&mut *tx, &prepared.keys).await?;
    let candidate: Option<Candidate> =
        sqlx::query_as("SELECT * FROM release_verification_candidates WHERE id = ? FOR UPDATE")
            .bind(claim.id())
            .fetch_optional(&mut *tx)
            .await?;
    let candidate = candidate
        .filter(|row| {
            row.version == claim.candidate.version
                && row.manifest_hash == claim.candidate.manifest_hash
                && row.lease_token.as_deref() == Some(claim.token.as_str())
                && row
                    .lease_until
                    .is_some_and(|until| until >= candidates::now())
        })
        .ok_or(PublishError::Stale)?;
    if let Some(release) = candidate.release_id {
        return Ok(done(Outcome::Duplicate(release as i64)));
    }
    if result.verdict == "sampled" {
        if manifest.verification_mode.as_deref() != Some("spot_check") {
            bail!("Sampled verdict has no spot-check evidence.");
        }
        let rejection = if let Some(partial) = manifest.partial {
            // Recomputed rather than trusted: the stored inventory decides the upload is incomplete.
            if inventory::assess(manifest).status != inventory::HeaderStatus::WaitingUpload
                || partial != inventory::completeness(manifest)
                || !queue::partial_allowed(verify, &candidate)
            {
                bail!("Partial publication is no longer eligible.");
            }
            inventory::partial_rejection(manifest)
        } else {
            inventory::assess(manifest).reason
        };
        if let Some(rejection) = rejection {
            bail!("{rejection}");
        }
    }
    if let Some(reason) = &prepared.rejection {
        if manifest.partial.is_some() && !policy::irrecoverable(reason) {
            return Err(PublishError::PartialDeferred(reason.clone()).into());
        }
        let path = queue::retire_locked(tx, &verify.config, claim, reason, "filtered").await?;
        return Ok(Done {
            outcome: Outcome::Filtered,
            unreferenced: Some(path),
        });
    }
    let metadata = &prepared.metadata;
    if manifest.verification_mode.as_deref() != Some("spot_check")
        && manifest.metadata_source.as_deref() != Some("import")
    {
        bail!("Authoritative verified size and inventory are required for collection publication.");
    }
    if prepared.size < 1 && manifest.verification_mode.as_deref() == Some("spot_check") {
        bail!("Spot-checked release has no positive declared size.");
    }
    // Stored by content hash: the pre-lock copy counts only if the row still names its path.
    let archived = match &prepared.archived {
        Some(archived) if prepared.archived_path == candidate.manifest_path => archived.clone(),
        _ => manifest::read(&verify.db, &verify.config, &candidate.manifest_path).await?,
    };
    if archived.legacy_release_id != manifest.legacy_release_id
        || (archived.legacy_release_id.is_some()
            && archived.metadata_source.as_deref() == Some("import"))
    {
        bail!("Legacy release identity is not trusted.");
    }
    let mut legacy: Option<(i64, String)> = None;
    if let Some(id) = archived.legacy_release_id {
        let row: Option<(i64, String, i32, i64)> = sqlx::query_as(
            "SELECT CAST(id AS SIGNED), guid, CAST(nzbstatus AS SIGNED), CAST(groups_id AS SIGNED) \
             FROM releases WHERE id = ? FOR UPDATE",
        )
        .bind(id)
        .fetch_optional(&mut *tx)
        .await?;
        match row {
            Some((id, guid, status, group))
                if status == NZB_NONE && group == manifest.groups_id =>
            {
                legacy = Some((id, guid));
            }
            _ => bail!("Legacy release identity changed before publication."),
        }
    }
    let total_parts = if manifest.verification_mode.as_deref() == Some("spot_check") {
        manifest
            .publication_file_count
            .unwrap_or(manifest.expected_files)
            .max(0)
    } else {
        manifest.expected_files.max(manifest.files.len() as i64)
    };
    let mut upgrade = if legacy.is_none() {
        lock_upgradable(tx, manifest, &prepared.keys, candidate.id).await?
    } else {
        None
    };
    if upgrade.is_none()
        && let Some(partial) = manifest.upgrades_release_id
    {
        // Publishing its retained articles anew would undo the deletion or duplicate the partial.
        let exists: Option<i32> = sqlx::query_scalar("SELECT 1 FROM releases WHERE id = ?")
            .bind(partial)
            .fetch_optional(&mut *tx)
            .await?;
        let reason = if exists.is_some() {
            "The partial release these articles would upgrade no longer awaits them."
        } else {
            "The partial release these articles would upgrade was deleted."
        };
        let path = queue::retire_locked(tx, &verify.config, claim, reason, "expired").await?;
        return Ok(Done {
            outcome: Outcome::Abandoned,
            unreferenced: Some(path),
        });
    }
    let mut duplicate = None;
    if let Some(partial) = &upgrade {
        // A complete copy published meanwhile is reused, and the partial settled rather than
        // upgraded into a second release of the same articles.
        let found = covered_duplicate(tx, verify, claim, manifest, prepared).await?;
        match found {
            (Some(id), _) if id != partial.id => {
                release_late_article_fence(&mut *tx, &[partial.id], None).await?;
                upgrade = None;
            }
            // Conflicting metadata: review it as a fresh candidate would be. The partial stays held.
            (None, true) => upgrade = None,
            _ => {}
        }
        duplicate = Some(found);
    }
    if let Some(partial) = &upgrade {
        let retain = manifest.partial.is_some() && awaits_late_articles(manifest);
        upgrade_partial(
            tx,
            partial,
            manifest,
            prepared,
            total_parts,
            candidate.id,
            retain,
        )
        .await?;
        record_groups(tx, partial.id, &manifest.groups).await?;
        queue::heartbeat(tx, verify.config.lease_seconds, claim.id(), &claim.token).await?;
        let unreferenced =
            mark_published(tx, verify, &candidate, partial.id, result, retain).await?;
        return Ok(Done {
            outcome: Outcome::Upgraded(partial.id),
            unreferenced,
        });
    }
    let (duplicate, metadata_conflict) = match duplicate {
        Some(found) => found,
        None if legacy.is_none() => {
            covered_duplicate(tx, verify, claim, manifest, prepared).await?
        }
        None => (None, false),
    };
    if let Some(release) = duplicate {
        record_groups(tx, release, &manifest.groups).await?;
        queue::heartbeat(tx, verify.config.lease_seconds, claim.id(), &claim.token).await?;
        // A covered candidate keeps no inventory: its articles are a subset of the reused release,
        // and merging late articles into that subset could replace a more complete partial.
        let unreferenced = mark_published(tx, verify, &candidate, release, result, false).await?;
        return Ok(Done {
            outcome: Outcome::Duplicate(release),
            unreferenced,
        });
    }
    if metadata_conflict {
        // Article reuse mustn't discard uploader assertions or create the same articles again.
        sqlx::query(
            "UPDATE release_verification_candidates SET status = 'metadata_review', \
             reason = 'Covered release does not preserve supplied metadata; review retained candidate.', \
             lease_token = NULL, lease_until = NULL, verified_at = NULL WHERE id = ?",
        )
        .bind(candidate.id)
        .execute(&mut *tx)
        .await?;
        return Ok(done(Outcome::MetadataReview));
    }
    let (id, release_guid) = match legacy {
        Some((id, existing)) => {
            update_legacy(tx, id, manifest, metadata, prepared.size, total_parts).await?;
            request_search_update(&mut *tx, id).await?;
            (id, existing)
        }
        None => {
            let fresh = guid();
            let id =
                insert_release(tx, manifest, metadata, &fresh, prepared.size, total_parts).await?;
            request_search_update(&mut *tx, id).await?;
            (id, fresh)
        }
    };
    let retain = manifest.partial.is_some() && awaits_late_articles(manifest);
    if let Some(partial) = manifest.partial {
        // Until its inventory is released, workers that read NZBs leave it alone.
        sqlx::query(
            "UPDATE releases SET articles_found = ?, articles_expected = ?, files_found = ?, files_expected = ?, \
             late_article_candidate_id = ? WHERE id = ?",
        )
        .bind(partial.articles_found)
        .bind(partial.articles_expected)
        .bind(partial.files_found)
        .bind(partial.files_expected)
        .bind(retain.then_some(candidate.id))
        .bind(id)
        .execute(&mut *tx)
        .await?;
    }
    let source = ReleaseRef {
        id,
        guid: release_guid,
    };
    put_locked(&mut *tx, &source, &prepared.body, None, true).await?;
    sqlx::query(
        "INSERT IGNORE INTO release_regexes (releases_id, collection_regex_id, naming_regex_id) VALUES (?, ?, ?)",
    )
    .bind(id)
    .bind(manifest.regex_id)
    .bind(metadata.naming_regex_id)
    .execute(&mut *tx)
    .await?;
    record_groups(tx, id, &manifest.groups).await?;
    queue::heartbeat(tx, verify.config.lease_seconds, claim.id(), &claim.token).await?;
    let unreferenced = mark_published(tx, verify, &candidate, id, result, retain).await?;
    Ok(Done {
        outcome: Outcome::Published(id),
        unreferenced,
    })
}

/// Publishes a passed verification result under the claim's lease.
pub async fn publish(
    verify: &Verify,
    resolver: &Resolver<'_>,
    claim: &Claim,
    result: &VerificationResult,
) -> Result<Outcome> {
    if !result.passed() {
        return Ok(Outcome::NotPassed);
    }
    let manifest = result
        .manifest
        .as_ref()
        .ok_or_else(|| anyhow!("A passed verification result carries its manifest."))?;
    let prepared = prepare(verify, resolver, claim, manifest).await?;
    let done = in_tx!(verify.db, 5, |tx| {
        publish_locked(tx, verify, claim, result, manifest, &prepared).await
    })?;
    if let Some(path) = &done.unreferenced
        && let Err(failure) =
            manifest::delete_if_unreferenced(&verify.db, &verify.config, path).await
    {
        warn!(
            error = format!("{failure:#}"),
            "could not delete a consumed manifest"
        );
    }
    if matches!(done.outcome, Outcome::Upgraded(_)) {
        // Cached API rows still describe the smaller inventory.
        let version: i64 = verify.cache.get(CACHE_VERSION_KEY).await.unwrap_or(1);
        if let Err(failure) = verify
            .cache
            .forever(CACHE_VERSION_KEY, &(version + 1))
            .await
        {
            warn!(
                error = format!("{failure:#}"),
                "could not invalidate cached release rows"
            );
        }
    }
    Ok(done.outcome)
}
