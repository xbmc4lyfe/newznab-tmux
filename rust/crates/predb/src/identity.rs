//! The certified PreDB identity index: `predb_identity_keys` nominates rows for a filename, and
//! triggers on `predb` invalidate it whenever a title or filename changes. A filename lookup
//! only answers when the index is certified for the current epoch; everything else is
//! `Unknown`, which callers must never treat as absence.
//!
//! The PHP fingerprinted its own source files into `policy_fingerprint`. Here it's a constant
//! to bump when the key grammar changes. A PHP certificate never matches it, so the index must
//! be rebuilt once at cutover (`nntmux-ctl predb identity-rebuild`).

use anyhow::{Result, anyhow, bail};
use chrono::NaiveDateTime;
use rand::RngCore;
use sha2::{Digest, Sha256};
use sqlx::{MySql, MySqlConnection, MySqlPool, Row, Transaction};

use crate::filename::{OriginalFilenameEvidence, exact_value, has_control};

/// Bump when key derivation or acceptance changes, which forces a rebuild.
pub const POLICY: &str = "2a1cb8761afa0cc74c1c757735c5798660d77ed4327516a99bf8cea5ee82307c";

const MAX_NOMINATIONS: usize = 1000;
const MAX_OWNER_ID: u64 = 4_294_967_295;

fn sha256_hex(value: &str) -> String {
    hex::encode(Sha256::digest(value.as_bytes()))
}

/// The complete bounded nominations for one row. Keys never establish identity by themselves.
pub fn keys_for_row(id: u64, title: &str, filename: &str) -> Option<Vec<(u8, String)>> {
    if !(1..=MAX_OWNER_ID).contains(&id)
        || title.is_empty()
        || !supported_text(title)
        || title.contains(['\\', '/', '|'])
        || !supported_text(filename)
    {
        return None;
    }
    let canonical = exact_value(title);
    if canonical.is_empty() || canonical.len() > 8192 {
        return None;
    }
    let mut keys = vec![(1, sha256_hex(&canonical))];
    if filename.is_empty() {
        return Some(keys);
    }
    let aliases = OriginalFilenameEvidence::new(filename).canonical_nomination_keys()?;
    for alias in aliases {
        let digest = sha256_hex(&alias);
        if !keys.iter().any(|(kind, key)| *kind == 2 && *key == digest) {
            keys.push((2, digest));
        }
    }
    Some(keys)
}

fn supported_text(value: &str) -> bool {
    value.len() <= 1020 && value.chars().count() <= 255 && !has_control(value)
}

#[derive(Clone, Debug, PartialEq)]
struct State {
    generation: String,
    epoch: u64,
    built_epoch: Option<u64>,
    policy: Option<String>,
    ready: bool,
    rebuild_epoch: Option<u64>,
    rebuild_cursor: u64,
    rebuild_until_id: Option<u64>,
}

impl State {
    fn certified(&self) -> bool {
        self.ready
            && self.built_epoch == Some(self.epoch)
            && self.policy.as_deref() == Some(POLICY)
            && self.rebuild_epoch.is_none()
            && self.rebuild_until_id.is_none()
            && self.rebuild_cursor == 0
    }
}

/// Reads the singleton under a row lock, which serializes writers, lookups and rebuild steps.
async fn lock_state(tx: &mut MySqlConnection) -> Result<State> {
    let rows = sqlx::query(
        "SELECT id, generation, epoch, built_epoch, policy_fingerprint, ready, rebuild_epoch, \
         rebuild_cursor, rebuild_until_id FROM predb_identity_state LIMIT 2 FOR UPDATE",
    )
    .fetch_all(&mut *tx)
    .await?;
    let [row] = rows.as_slice() else {
        bail!("PreDB identity state is unavailable");
    };
    let state = State {
        generation: row.try_get("generation")?,
        epoch: row.try_get("epoch")?,
        built_epoch: row.try_get("built_epoch")?,
        policy: row.try_get("policy_fingerprint")?,
        ready: row.try_get("ready")?,
        rebuild_epoch: row.try_get("rebuild_epoch")?,
        rebuild_cursor: row.try_get("rebuild_cursor")?,
        rebuild_until_id: row.try_get("rebuild_until_id")?,
    };
    let id: u64 = row.try_get("id")?;
    if id != 1 || !is_generation(&state.generation) {
        bail!("Unsupported PreDB identity state");
    }
    Ok(state)
}

fn is_generation(value: &str) -> bool {
    value.len() == 32
        && value
            .bytes()
            .all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f'))
}

/// The triggers are what keep the index honest, so nothing certifies without all three.
async fn assert_schema(tx: &mut MySqlConnection) -> Result<()> {
    let triggers: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() \
         AND EVENT_OBJECT_TABLE = 'predb' AND TRIGGER_NAME IN \
         ('predb_identity_insert', 'predb_identity_update', 'predb_identity_delete')",
    )
    .fetch_one(&mut *tx)
    .await?;
    if triggers != 3 {
        bail!("PreDB identity triggers are missing");
    }
    Ok(())
}

/// Recomputes one owner's keys at `revision`. Validates everything before replacing anything.
async fn replace_owner(
    tx: &mut MySqlConnection,
    generation: &str,
    id: u64,
    revision: u64,
) -> Result<()> {
    let source: Option<(String, String)> =
        sqlx::query_as("SELECT title, filename FROM predb WHERE id = ? LIMIT 1 FOR UPDATE")
            .bind(id)
            .fetch_optional(&mut *tx)
            .await?;
    let owner: Option<(u64, bool)> = sqlx::query_as(
        "SELECT revision, deleted FROM predb_identity_revisions WHERE predb_id = ? LIMIT 1 FOR UPDATE",
    )
    .bind(id)
    .fetch_optional(&mut *tx)
    .await?;
    let Some((owner_revision, deleted)) = owner else {
        bail!("PreDB owner revision or presence changed");
    };
    if owner_revision != revision || source.is_none() != deleted {
        bail!("PreDB owner revision or presence changed");
    }
    let keys = match &source {
        None => Vec::new(),
        Some((title, filename)) => keys_for_row(id, title, filename)
            .ok_or_else(|| anyhow!("PreDB owner identity can't be projected completely"))?,
    };
    sqlx::query("DELETE FROM predb_identity_keys WHERE generation = ? AND predb_id = ?")
        .bind(generation)
        .bind(id)
        .execute(&mut *tx)
        .await?;
    for (kind, digest) in &keys {
        sqlx::query(
            "INSERT INTO predb_identity_keys (generation, key_digest, kind, predb_id, source_revision) \
             VALUES (?, ?, ?, ?, ?)",
        )
        .bind(generation)
        .bind(digest)
        .bind(kind)
        .bind(id)
        .bind(revision)
        .execute(&mut *tx)
        .await?;
    }
    sqlx::query(
        "UPDATE predb_identity_revisions SET projected_revision = ?, projected_generation = ? \
         WHERE predb_id = ? AND revision = ? AND deleted = ?",
    )
    .bind(revision)
    .bind(generation)
    .bind(id)
    .bind(revision)
    .bind(deleted)
    .execute(&mut *tx)
    .await?;
    Ok(())
}

/// A candidate a lookup found.
#[derive(Clone, Debug, PartialEq)]
pub struct Candidate {
    pub id: u64,
    pub title: String,
    pub filename: String,
}

#[derive(Clone, Debug, PartialEq)]
pub enum Lookup {
    /// The index couldn't answer, which never establishes absence.
    Unknown,
    Absent,
    Ambiguous,
    Unique(Candidate),
}

/// One authority envelope for an ordered pass: nothing escapes before its final check. Any
/// failure answers `Unknown` for the whole pass. Pass a transaction to take part in it.
pub async fn lookup_many(pool: &MySqlPool, evidences: &[OriginalFilenameEvidence]) -> Vec<Lookup> {
    if evidences.is_empty() {
        return Vec::new();
    }
    let attempt = async {
        let mut tx = pool.begin().await?;
        let results = observe_many(&mut tx, evidences).await?;
        tx.commit().await?;
        Ok::<_, anyhow::Error>(results)
    };
    attempt.await.unwrap_or_else(|error| {
        tracing::warn!(%error, "PreDB identity lookup is unknown");
        vec![Lookup::Unknown; evidences.len()]
    })
}

/// [`lookup_many`] inside a caller's transaction, so its row locks last until the write.
pub async fn lookup_many_in(
    tx: &mut MySqlConnection,
    evidences: &[OriginalFilenameEvidence],
) -> Vec<Lookup> {
    if evidences.is_empty() {
        return Vec::new();
    }
    observe_many(tx, evidences).await.unwrap_or_else(|error| {
        tracing::warn!(%error, "PreDB identity lookup is unknown");
        vec![Lookup::Unknown; evidences.len()]
    })
}

async fn observe_many(
    tx: &mut MySqlConnection,
    evidences: &[OriginalFilenameEvidence],
) -> Result<Vec<Lookup>> {
    let mut digests = Vec::with_capacity(evidences.len());
    for evidence in evidences {
        let Some(keys) = evidence.canonical_nomination_keys() else {
            return Ok(vec![Lookup::Unknown; evidences.len()]);
        };
        let mut hashed: Vec<String> = keys.iter().map(|key| sha256_hex(key)).collect();
        hashed.dedup();
        digests.push(hashed);
    }
    assert_schema(tx).await?;
    let state = lock_state(tx).await?;
    if !state.certified() {
        bail!("Uncertified identity state");
    }
    let mut results = Vec::with_capacity(evidences.len());
    for (evidence, digests) in evidences.iter().zip(&digests) {
        results.push(resolve(tx, evidence, digests, &state).await?);
    }
    if lock_state(tx).await? != state {
        bail!("Identity authority changed");
    }
    Ok(results)
}

async fn resolve(
    tx: &mut MySqlConnection,
    evidence: &OriginalFilenameEvidence,
    digests: &[String],
    state: &State,
) -> Result<Lookup> {
    for kind in [1u8, 2] {
        let accepted = nominees(tx, evidence, digests, state, kind).await?;
        match accepted.len() {
            0 => {}
            1 => return Ok(Lookup::Unique(accepted.into_iter().next().expect("one"))),
            _ => return Ok(Lookup::Ambiguous),
        }
    }
    Ok(Lookup::Absent)
}

async fn nominees(
    tx: &mut MySqlConnection,
    evidence: &OriginalFilenameEvidence,
    digests: &[String],
    state: &State,
    kind: u8,
) -> Result<Vec<Candidate>> {
    if digests.is_empty() {
        return Ok(Vec::new());
    }
    let marks = vec!["?"; digests.len()].join(", ");
    let sql = format!(
        "SELECT k.predb_id, k.key_digest, k.source_revision, p.id AS source_id, p.title, p.filename, \
         r.revision, r.deleted, r.projected_revision, r.projected_generation \
         FROM predb_identity_keys k LEFT JOIN predb p ON p.id = k.predb_id \
         LEFT JOIN predb_identity_revisions r ON r.predb_id = k.predb_id \
         WHERE k.generation = ? AND k.kind = ? AND k.key_digest IN ({marks}) \
         ORDER BY k.key_digest, k.predb_id LIMIT {MAX_NOMINATIONS}"
    );
    let mut query = sqlx::query(sqlx::AssertSqlSafe(sql))
        .bind(&state.generation)
        .bind(kind);
    for digest in digests {
        query = query.bind(digest);
    }
    let rows = query.fetch_all(&mut *tx).await?;
    if rows.len() >= MAX_NOMINATIONS {
        bail!("Incomplete identity nominations");
    }
    let mut accepted: Vec<Candidate> = Vec::new();
    for row in rows {
        let id: u64 = row.try_get("predb_id")?;
        let digest: String = row.try_get("key_digest")?;
        let source_id: Option<u64> = row.try_get("source_id")?;
        let revision: Option<u64> = row.try_get("revision")?;
        let (Some(revision), Some(title), Some(filename)) = (
            revision,
            row.try_get::<Option<String>, _>("title")?,
            row.try_get::<Option<String>, _>("filename")?,
        ) else {
            bail!("Stale identity nomination");
        };
        if source_id != Some(id)
            || revision > state.epoch
            || row.try_get::<u64, _>("source_revision")? != revision
            || row.try_get::<Option<u64>, _>("projected_revision")? != Some(revision)
            || row.try_get::<Option<bool>, _>("deleted")? != Some(false)
            || row
                .try_get::<Option<String>, _>("projected_generation")?
                .as_deref()
                != Some(state.generation.as_str())
        {
            bail!("Stale identity nomination");
        }
        let keys =
            keys_for_row(id, &title, &filename).ok_or_else(|| anyhow!("Invalid identity key"))?;
        if !keys.iter().any(|(k, d)| *k == kind && *d == digest) {
            bail!("Invalid identity key");
        }
        let alias = if kind == 1 { "" } else { filename.as_str() };
        if evidence.accepts(&title, alias) && !accepted.iter().any(|c| c.id == id) {
            accepted.push(Candidate {
                id,
                title,
                filename,
            });
        }
    }
    Ok(accepted)
}

/// A new PreDB row from a feed.
#[derive(Clone, Debug, Default)]
pub struct NewPredb {
    pub title: String,
    pub source: String,
    pub category: Option<String>,
    pub size: Option<String>,
    pub files: Option<String>,
    pub predate: Option<NaiveDateTime>,
    pub nuked: i8,
    pub nuke_reason: Option<String>,
}

fn cut(value: &str, limit: usize) -> String {
    value.chars().take(limit).collect()
}

#[derive(Debug)]
pub enum InsertOutcome {
    Inserted(u64),
    /// The database refused the row itself: a duplicate title or bad data.
    Rejected(sqlx::Error),
}

/// `true` for duplicate keys and SQLSTATE class 22 data errors, which are about the row rather
/// than the database being unwell.
pub fn is_row_rejection(error: &sqlx::Error) -> bool {
    error.as_database_error().is_some_and(|db| {
        db.is_unique_violation() || db.code().is_some_and(|code| code.starts_with("22"))
    })
}

/// Inserts a row and, while the index is certified, keeps it certified in the same transaction.
pub async fn insert_feed(pool: &MySqlPool, row: &NewPredb) -> Result<InsertOutcome> {
    let mut tx = pool.begin().await?;
    let before = fence(&mut tx).await?;
    let inserted = sqlx::query(
        "INSERT INTO predb (title, source, category, size, files, predate, nuked, nukereason, filename) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, '')",
    )
    .bind(&row.title)
    .bind(cut(&row.source, 50))
    .bind(row.category.as_deref().map(|v| cut(v, 255)))
    .bind(row.size.as_deref().map(|v| cut(v, 50)))
    .bind(row.files.as_deref().map(|v| cut(v, 50)))
    .bind(row.predate)
    .bind(row.nuked)
    .bind(row.nuke_reason.as_deref().map(|v| cut(v, 255)))
    .execute(&mut *tx)
    .await;
    let id = match inserted {
        Ok(done) => done.last_insert_id(),
        Err(error) if is_row_rejection(&error) => return Ok(InsertOutcome::Rejected(error)),
        Err(error) => return Err(error.into()),
    };
    finish(&mut tx, &before, id, true).await?;
    tx.commit().await?;
    Ok(InsertOutcome::Inserted(id))
}

/// What the IRC scraper knows about a release.
#[derive(Clone, Debug, Default)]
pub struct IrcRow {
    pub title: String,
    pub size: Option<String>,
    pub category: Option<String>,
    pub source: Option<String>,
    pub reason: Option<String>,
    pub files: Option<String>,
    pub request_id: Option<i64>,
    pub group_id: Option<i64>,
    pub nuked: Option<i8>,
    pub filename: Option<String>,
    /// A preformatted SQL-safe `FROM_UNIXTIME` value, or now.
    pub predate: Option<NaiveDateTime>,
}

#[derive(Debug, PartialEq)]
pub enum LegacyOutcome {
    Noop,
    Exists,
    Stale,
    Unchanged,
    Inserted(u64),
    Updated,
    Rejected,
}

pub async fn insert_irc(pool: &MySqlPool, row: &IrcRow) -> Result<LegacyOutcome> {
    if row.title.is_empty() || row.title == "0" {
        return Ok(LegacyOutcome::Noop);
    }
    let mut tx = pool.begin().await?;
    let before = fence(&mut tx).await?;
    let exists: Option<u64> = sqlx::query_scalar("SELECT id FROM predb WHERE title = ? LIMIT 1")
        .bind(&row.title)
        .fetch_optional(&mut *tx)
        .await?;
    if exists.is_some() {
        return Ok(LegacyOutcome::Exists);
    }
    let inserted = sqlx::query(
        "INSERT INTO predb (title, size, category, source, nukereason, files, requestid, groups_id, \
         nuked, filename, predate) VALUES (?, COALESCE(?, NULL), ?, COALESCE(?, ''), ?, ?, \
         COALESCE(?, 0), COALESCE(?, 0), COALESCE(?, 0), COALESCE(?, ''), COALESCE(?, NOW()))",
    )
    .bind(&row.title)
    .bind(&row.size)
    .bind(&row.category)
    .bind(&row.source)
    .bind(&row.reason)
    .bind(&row.files)
    .bind(row.request_id)
    .bind(row.group_id)
    .bind(row.nuked)
    .bind(&row.filename)
    .bind(row.predate)
    .execute(&mut *tx)
    .await;
    let id = match inserted {
        Ok(done) => done.last_insert_id(),
        Err(error) if is_row_rejection(&error) => return Ok(LegacyOutcome::Rejected),
        Err(error) => return Err(error.into()),
    };
    finish(&mut tx, &before, id, true).await?;
    tx.commit().await?;
    Ok(LegacyOutcome::Inserted(id))
}

/// Updates a row the scraper observed. `source` and `category` only fill empty columns, and a
/// title change is limited to letter case.
pub async fn update_irc(
    pool: &MySqlPool,
    id: u64,
    observed_title: &str,
    row: &IrcRow,
) -> Result<LegacyOutcome> {
    if !(1..=MAX_OWNER_ID).contains(&id)
        || row.title.is_empty()
        || exact_value(observed_title) != exact_value(&row.title)
    {
        return Ok(LegacyOutcome::Stale);
    }
    let mut tx = pool.begin().await?;
    let before = fence(&mut tx).await?;
    let source: Option<(String, String, Option<String>, String)> = sqlx::query_as(
        "SELECT title, filename, category, source FROM predb WHERE id = ? FOR UPDATE",
    )
    .bind(id)
    .fetch_optional(&mut *tx)
    .await?;
    let Some((title, filename, category, current_source)) = source else {
        return Ok(LegacyOutcome::Stale);
    };
    if title != observed_title {
        return Ok(LegacyOutcome::Stale);
    }
    let non_empty = |v: &Option<String>| v.clone().filter(|v| !v.is_empty() && v != "0");
    let new_source = non_empty(&row.source).filter(|_| current_source.is_empty());
    let new_category =
        non_empty(&row.category).filter(|_| category.as_deref().unwrap_or("").is_empty());
    let new_filename = non_empty(&row.filename);
    let (size, reason, files) = (
        non_empty(&row.size),
        non_empty(&row.reason),
        non_empty(&row.files),
    );
    let (request_id, group_id, nuked) = (
        row.request_id.filter(|v| *v != 0),
        row.group_id.filter(|v| *v != 0),
        row.nuked.filter(|v| *v != 0),
    );
    if size.is_none()
        && reason.is_none()
        && files.is_none()
        && new_source.is_none()
        && new_category.is_none()
        && new_filename.is_none()
        && request_id.is_none()
        && group_id.is_none()
        && nuked.is_none()
        && row.predate.is_none()
    {
        return Ok(LegacyOutcome::Noop);
    }
    let changed = title != row.title || new_filename.as_ref().is_some_and(|name| *name != filename);
    let updated = sqlx::query(
        "UPDATE predb SET title = ?, size = COALESCE(?, size), category = COALESCE(?, category), \
         source = COALESCE(?, source), nukereason = COALESCE(?, nukereason), files = COALESCE(?, files), \
         requestid = COALESCE(?, requestid), groups_id = COALESCE(?, groups_id), \
         nuked = COALESCE(?, nuked), filename = COALESCE(?, filename), predate = COALESCE(?, predate) \
         WHERE id = ? AND HEX(title) = HEX(?) AND HEX(filename) = HEX(?)",
    )
    .bind(&row.title)
    .bind(size)
    .bind(new_category)
    .bind(new_source)
    .bind(reason)
    .bind(files)
    .bind(request_id)
    .bind(group_id)
    .bind(nuked)
    .bind(new_filename)
    .bind(row.predate)
    .bind(id)
    .bind(observed_title)
    .bind(&filename)
    .execute(&mut *tx)
    .await;
    let affected = match updated {
        Ok(done) => done.rows_affected(),
        Err(error) if is_row_rejection(&error) => return Ok(LegacyOutcome::Rejected),
        Err(error) => return Err(error.into()),
    };
    finish(&mut tx, &before, id, changed).await?;
    tx.commit().await?;
    Ok(if affected > 0 {
        LegacyOutcome::Updated
    } else {
        LegacyOutcome::Unchanged
    })
}

/// Takes the singleton lock before any source write. Writers without it would race certification.
async fn fence(tx: &mut Transaction<'_, MySql>) -> Result<State> {
    assert_schema(&mut *tx).await?;
    lock_state(&mut *tx).await
}

/// After a source write: checks the triggers fired as expected and, if the index was certified
/// before this write, projects the owner and restores readiness.
async fn finish(
    tx: &mut Transaction<'_, MySql>,
    before: &State,
    id: u64,
    changed: bool,
) -> Result<()> {
    let after = lock_state(&mut *tx).await?;
    let mut expected = before.clone();
    if changed {
        expected.epoch += 1;
        expected.ready = false;
    }
    if after != expected {
        bail!("PreDB writer epoch or certificate drift");
    }
    if !changed {
        return Ok(());
    }
    let owner: Option<(u64, bool, Option<u64>)> = sqlx::query_as(
        "SELECT revision, deleted, projected_revision FROM predb_identity_revisions WHERE predb_id = ?",
    )
    .bind(id)
    .fetch_optional(&mut **tx)
    .await?;
    if owner != Some((expected.epoch, false, None)) {
        bail!("PreDB writer owner delta changed");
    }
    let (title, filename): (String, String) =
        sqlx::query_as("SELECT title, filename FROM predb WHERE id = ?")
            .bind(id)
            .fetch_one(&mut **tx)
            .await?;
    // A row the grammar can't project leaves the index uncertified until the next rebuild.
    if before.certified() && keys_for_row(id, &title, &filename).is_some() {
        replace_owner(&mut *tx, &before.generation, id, expected.epoch).await?;
        let restored = sqlx::query(
            "UPDATE predb_identity_state SET ready = 1, built_epoch = ? WHERE id = 1 AND epoch = ? AND ready = 0",
        )
        .bind(expected.epoch)
        .bind(expected.epoch)
        .execute(&mut **tx)
        .await?;
        if restored.rows_affected() != 1 {
            bail!("PreDB writer readiness restoration failed");
        }
    }
    Ok(())
}

/// Starts a rebuild: a fresh generation frozen at the current epoch.
pub async fn rebuild_start(pool: &MySqlPool) -> Result<(String, u64, u64)> {
    let mut tx = pool.begin().await?;
    assert_schema(&mut tx).await?;
    let state = lock_state(&mut tx).await?;
    let max_predb: Option<u64> = sqlx::query_scalar("SELECT MAX(id) FROM predb")
        .fetch_one(&mut *tx)
        .await?;
    let max_owner: Option<u64> =
        sqlx::query_scalar("SELECT MAX(predb_id) FROM predb_identity_revisions")
            .fetch_one(&mut *tx)
            .await?;
    let until = max_predb.unwrap_or(0).max(max_owner.unwrap_or(0));
    if until > MAX_OWNER_ID {
        bail!("Unsupported PreDB rebuild owner domain");
    }
    let mut bytes = [0u8; 16];
    rand::rng().fill_bytes(&mut bytes);
    let generation = hex::encode(bytes);
    sqlx::query(
        "UPDATE predb_identity_state SET generation = ?, ready = 0, built_epoch = NULL, \
         policy_fingerprint = ?, rebuild_epoch = ?, rebuild_cursor = 0, rebuild_until_id = ? WHERE id = 1",
    )
    .bind(&generation)
    .bind(POLICY)
    .bind(state.epoch)
    .bind(until)
    .execute(&mut *tx)
    .await?;
    tx.commit().await?;
    Ok((generation, state.epoch, until))
}

fn check_generation(generation: &str) -> Result<()> {
    if !is_generation(generation) {
        bail!("Unsupported PreDB rebuild generation.");
    }
    Ok(())
}

async fn active_build(tx: &mut MySqlConnection, generation: &str) -> Result<(u64, u64, u64)> {
    let state = lock_state(tx).await?;
    if state.generation != generation
        || state.ready
        || state.built_epoch.is_some()
        || state.rebuild_epoch != Some(state.epoch)
        || state.policy.as_deref() != Some(POLICY)
    {
        bail!("PreDB rebuild generation, epoch or loaded policy changed.");
    }
    let until = state
        .rebuild_until_id
        .ok_or_else(|| anyhow!("PreDB rebuild state is unavailable."))?;
    if state.rebuild_cursor > until {
        bail!("PreDB rebuild cursor changed");
    }
    Ok((state.epoch, state.rebuild_cursor, until))
}

async fn next_owners(
    tx: &mut MySqlConnection,
    cursor: u64,
    until: u64,
    limit: u32,
) -> Result<Vec<u64>> {
    let mut ids: Vec<u64> =
        sqlx::query_scalar("SELECT id FROM predb WHERE id > ? AND id <= ? ORDER BY id LIMIT ?")
            .bind(cursor)
            .bind(until)
            .bind(limit)
            .fetch_all(&mut *tx)
            .await?;
    let owners: Vec<u64> = sqlx::query_scalar(
        "SELECT predb_id FROM predb_identity_revisions WHERE predb_id > ? AND predb_id <= ? ORDER BY predb_id LIMIT ?",
    )
    .bind(cursor)
    .bind(until)
    .bind(limit)
    .fetch_all(&mut *tx)
    .await?;
    ids.extend(owners);
    ids.sort_unstable();
    ids.dedup();
    ids.truncate(limit as usize);
    Ok(ids)
}

/// One page of a rebuild: `(cursor, processed, exhausted)`.
pub async fn rebuild_chunk(
    pool: &MySqlPool,
    generation: &str,
    limit: u32,
) -> Result<(u64, usize, bool)> {
    check_generation(generation)?;
    if !(1..=256).contains(&limit) {
        bail!("Unsupported PreDB rebuild page.");
    }
    let mut tx = pool.begin().await?;
    assert_schema(&mut tx).await?;
    let (epoch, mut cursor, until) = active_build(&mut tx, generation).await?;
    let ids = next_owners(&mut tx, cursor, until, limit).await?;
    for &id in &ids {
        let owner: Option<u64> = sqlx::query_scalar(
            "SELECT revision FROM predb_identity_revisions WHERE predb_id = ? FOR UPDATE",
        )
        .bind(id)
        .fetch_optional(&mut *tx)
        .await?;
        let revision = match owner {
            Some(revision) if revision <= epoch => revision,
            Some(_) => bail!("PreDB rebuild generation, epoch or loaded policy changed."),
            None => {
                sqlx::query(
                    "INSERT INTO predb_identity_revisions (predb_id, revision, deleted) VALUES (?, ?, 0)",
                )
                .bind(id)
                .bind(epoch)
                .execute(&mut *tx)
                .await?;
                epoch
            }
        };
        replace_owner(&mut tx, generation, id, revision).await?;
        cursor = id;
    }
    sqlx::query("UPDATE predb_identity_state SET rebuild_cursor = ? WHERE id = 1")
        .bind(cursor)
        .execute(&mut *tx)
        .await?;
    let exhausted = next_owners(&mut tx, cursor, until, 1).await?.is_empty();
    tx.commit().await?;
    Ok((cursor, ids.len(), exhausted))
}

/// Certifies a finished rebuild. Fails if anything was written since it started.
pub async fn rebuild_certify(pool: &MySqlPool, generation: &str) -> Result<()> {
    check_generation(generation)?;
    let mut tx = pool.begin().await?;
    assert_schema(&mut tx).await?;
    let (epoch, cursor, until) = active_build(&mut tx, generation).await?;
    if !next_owners(&mut tx, cursor, until, 1).await?.is_empty() {
        bail!("PreDB rebuild inventory is incomplete.");
    }
    for sql in [
        "SELECT COUNT(*) FROM predb WHERE id > ?",
        "SELECT COUNT(*) FROM predb_identity_revisions WHERE predb_id > ?",
    ] {
        let beyond: i64 = sqlx::query_scalar(sql)
            .bind(until)
            .fetch_one(&mut *tx)
            .await?;
        if beyond != 0 {
            bail!("PreDB rebuild inventory changed.");
        }
    }
    sqlx::query(
        "UPDATE predb_identity_state SET ready = 1, built_epoch = ?, rebuild_epoch = NULL, \
         rebuild_cursor = 0, rebuild_until_id = NULL WHERE id = 1",
    )
    .bind(epoch)
    .execute(&mut *tx)
    .await?;
    tx.commit().await?;
    Ok(())
}
