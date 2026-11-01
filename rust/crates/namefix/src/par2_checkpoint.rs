//! `Par2CandidateProgress` and `Par2CandidateCheckpoint`: durable article cursors and metadata
//! for each candidate of a release.
//!
//! Manifest signatures cover every ordered candidate's segment number, Message-ID and byte
//! estimate, plus the configuration limits that can block recovery. A blocked checkpoint is
//! never evidence of completeness; only a new manifest or an explicit reset clears its block,
//! and a missing accumulator is allowed only for a zero-byte blocked tombstone.

use anyhow::{Context, Result, bail};
use base64::Engine as _;
use base64::engine::general_purpose::STANDARD;
use serde_json::Value;
use sqlx::{MySqlConnection, MySqlPool, Row};

use crate::par2_accumulator::PacketAccumulator;
use crate::par2_json::Json;
use crate::par2_support::{Guard, LockedRelease, aborted, absorb_abort};
use crate::release::Snapshot;

const MAX_SEGMENTS: i64 = 1_000_000;
const MAX_STORED_BYTES: i64 = 4_294_967_295;

/// The yEnc file an article stream belongs to, so a resumed stream is checked for continuity.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ArticleIdentity {
    pub name_base64: String,
    pub size: i64,
    pub part: Option<i64>,
}

impl ArticleIdentity {
    fn json(&self) -> String {
        Json::Obj(vec![
            ("name_base64", Json::str(self.name_base64.clone())),
            ("size", Json::Int(self.size)),
            ("part", self.part.map_or(Json::Null, Json::Int)),
        ])
        .encode()
    }
}

/// A stored cursor, validated.
#[derive(Clone, Debug)]
pub struct Checkpoint {
    pub next_segment: i64,
    pub retry_segments: Vec<i64>,
    pub accumulator: Option<PacketAccumulator>,
    pub stored_bytes: i64,
    pub blocked_reason: Option<String>,
    pub decoded_offset: i64,
    pub identity: Option<ArticleIdentity>,
}

impl Checkpoint {
    pub fn is_blocked(&self) -> bool {
        self.blocked_reason.is_some()
    }
}

/// A stored row failed validation. Distinguished so a corrupt candidate can be quarantined.
#[derive(Debug)]
pub struct Corrupt;

impl std::fmt::Display for Corrupt {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("Invalid persisted PAR2 candidate checkpoint.")
    }
}

impl std::error::Error for Corrupt {}

fn invalid(message: &'static str) -> anyhow::Error {
    anyhow::anyhow!(message)
}

fn is_signature(value: &str) -> bool {
    value.len() == 64 && value.bytes().all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f'))
}

fn validate_arguments(
    release_id: i64,
    manifest: &str,
    candidate: &str,
    segment_count: i64,
    max_state_bytes: i64,
) -> Result<()> {
    if release_id < 1
        || !(1..=MAX_SEGMENTS).contains(&segment_count)
        || !(1..=MAX_STORED_BYTES).contains(&max_state_bytes)
        || !is_signature(manifest)
        || !is_signature(candidate)
    {
        bail!("Invalid PAR2 checkpoint identity, segment count or byte ceiling.");
    }
    Ok(())
}

fn validate_cursor(next_segment: i64, retries: &[i64], segment_count: i64) -> Result<()> {
    if next_segment < 0 || next_segment > segment_count || retries.len() as i64 > next_segment {
        bail!("Invalid PAR2 article cursor or retry list.");
    }
    let mut seen: Vec<i64> = Vec::new();
    for retry in retries {
        if *retry < 0 || *retry >= next_segment || seen.contains(retry) {
            bail!("Invalid or duplicate PAR2 retry segment.");
        }
        seen.push(*retry);
    }
    Ok(())
}

fn validate_block(reason: Option<&str>) -> Result<()> {
    if let Some(reason) = reason {
        let mut chars = reason.chars();
        let valid = reason.len() <= 64
            && chars.next().is_some_and(|c| c.is_ascii_lowercase())
            && chars.all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_');
        if !valid {
            bail!("Invalid PAR2 checkpoint blocked reason.");
        }
    }
    Ok(())
}

fn canonical_identity(
    identity: Option<&ArticleIdentity>,
    decoded_offset: i64,
    tombstone: bool,
) -> Result<()> {
    if decoded_offset < 0 {
        bail!("Invalid decoded article offset.");
    }
    let Some(identity) = identity else {
        return Ok(());
    };
    if tombstone
        || identity.name_base64.len() > 87_384
        || identity.size < 1
        || decoded_offset > identity.size
        || identity
            .part
            .is_some_and(|part| !(1..=MAX_SEGMENTS).contains(&part))
    {
        bail!("Invalid article continuity identity.");
    }
    let name = STANDARD
        .decode(&identity.name_base64)
        .map_err(|_| invalid("Invalid article filename encoding."))?;
    if name.is_empty()
        || name.len() > 65_536
        || STANDARD.encode(&name) != identity.name_base64
        || name.iter().any(|b| matches!(b, b'\r' | b'\n' | 0))
    {
        bail!("Invalid article filename encoding.");
    }
    Ok(())
}

fn stored_integer(value: i64, maximum: i64) -> Result<i64> {
    if value < 0 || value > maximum {
        bail!("Invalid stored PAR2 checkpoint integer.");
    }
    Ok(value)
}

/// The columns a checkpoint is read from.
const ROW_COLUMNS: &str = "candidate_signature, CAST(next_segment AS SIGNED) AS next_segment, \
     retry_segments, accumulator_state, CAST(decoded_offset AS SIGNED) AS decoded_offset, \
     article_identity, CAST(stored_bytes AS SIGNED) AS stored_bytes, blocked_reason";

struct StoredRow {
    candidate_signature: String,
    next_segment: i64,
    retry_segments: String,
    accumulator_state: Option<String>,
    decoded_offset: i64,
    article_identity: Option<String>,
    stored_bytes: i64,
    blocked_reason: Option<String>,
}

impl StoredRow {
    fn from_row(row: &sqlx::mysql::MySqlRow) -> Result<Self> {
        Ok(Self {
            candidate_signature: row.try_get("candidate_signature")?,
            next_segment: row.try_get("next_segment")?,
            retry_segments: row.try_get("retry_segments")?,
            accumulator_state: row.try_get("accumulator_state")?,
            decoded_offset: row.try_get("decoded_offset")?,
            article_identity: row.try_get("article_identity")?,
            stored_bytes: row.try_get("stored_bytes")?,
            blocked_reason: row.try_get("blocked_reason")?,
        })
    }

    /// `decodeRow`.
    fn decode(&self, segment_count: i64) -> Result<Checkpoint> {
        self.decode_inner(segment_count)
            .map_err(|_| anyhow::Error::new(Corrupt))
    }

    fn decode_inner(&self, segment_count: i64) -> Result<Checkpoint> {
        if !is_signature(&self.candidate_signature) {
            bail!("Invalid candidate signature.");
        }
        let next_segment = stored_integer(self.next_segment, MAX_SEGMENTS)?;
        let stored_bytes = stored_integer(self.stored_bytes, MAX_STORED_BYTES)?;
        let retries: Value = serde_json::from_str(&self.retry_segments)?;
        let Value::Array(retries) = retries else {
            bail!("Invalid stored retry list.");
        };
        let retries: Vec<i64> = retries
            .iter()
            .map(|retry| retry.as_i64().context("non-integer retry"))
            .collect::<Result<_>>()?;
        validate_cursor(next_segment, &retries, segment_count)?;
        validate_block(self.blocked_reason.as_deref())?;
        let accumulator = match &self.accumulator_state {
            Some(text) => {
                let document: Value = serde_json::from_str(text)?;
                if !document.get("packets").is_some_and(Value::is_array) {
                    bail!("Invalid stored accumulator object or packet list.");
                }
                Some(PacketAccumulator::from_state(&document, usize::MAX)?)
            }
            None => {
                if self.blocked_reason.is_none() {
                    bail!("A checkpoint without accumulator state must be blocked.");
                }
                None
            }
        };
        let decoded_offset = stored_integer(self.decoded_offset, i64::MAX)?;
        let identity = match &self.article_identity {
            Some(text) => {
                let document: Value = serde_json::from_str(text)?;
                Some(read_identity(&document)?)
            }
            None => None,
        };
        canonical_identity(identity.as_ref(), decoded_offset, accumulator.is_none())?;
        let bytes = accumulator
            .as_ref()
            .map_or(0, |accumulator| accumulator.state_bytes() as i64)
            + identity.as_ref().map_or(0, |i| i.json().len() as i64);
        if bytes != stored_bytes {
            bail!("PAR2 checkpoint byte accounting does not match canonical state.");
        }
        Ok(Checkpoint {
            next_segment,
            retry_segments: retries,
            accumulator,
            stored_bytes: bytes,
            blocked_reason: self.blocked_reason.clone(),
            decoded_offset,
            identity,
        })
    }
}

fn read_identity(document: &Value) -> Result<ArticleIdentity> {
    let object = document
        .as_object()
        .context("Invalid stored article identity.")?;
    let mut keys: Vec<&str> = object.keys().map(String::as_str).collect();
    keys.sort_unstable();
    if keys != ["name_base64", "part", "size"] {
        bail!("Invalid article continuity identity.");
    }
    let part = match &object["part"] {
        Value::Null => None,
        other => Some(other.as_i64().context("part")?),
    };
    Ok(ArticleIdentity {
        name_base64: object["name_base64"]
            .as_str()
            .context("name")?
            .to_owned(),
        size: object["size"].as_i64().context("size")?,
        part,
    })
}

/// Whether the release still has the snapshot's identity and is still unfinished, and the
/// caller still holds its claim. Locks the row.
async fn assert_writable(
    conn: &mut MySqlConnection,
    snapshot: &Snapshot,
    guard: &dyn Guard,
) -> Result<()> {
    if !guard.holds().await || !matches_snapshot(conn, snapshot).await? {
        return Err(aborted("PAR2 checkpoint ownership or release identity changed."));
    }
    Ok(())
}

pub async fn matches_snapshot(conn: &mut MySqlConnection, snapshot: &Snapshot) -> Result<bool> {
    let release = LockedRelease::lock(conn, snapshot.id).await?;
    Ok(release.is_some_and(|release| {
        release.id == snapshot.id
            && release.guid == snapshot.guid
            && release.name == snapshot.name
            && release.searchname == snapshot.searchname
            && release.predb_id == 0
            && release.isrenamed == 0
            && release.proc_par2 != 1
    }))
}

/// `Par2CandidateProgress::load`. `None` when there is no checkpoint for this manifest.
/// A stored checkpoint that fails validation is an error (`Corrupt` where it's the row).
pub async fn load(
    db: &MySqlPool,
    release_id: i64,
    manifest_signature: &str,
    candidate_signature: &str,
    segment_count: i64,
    max_state_bytes: i64,
) -> Result<Option<Checkpoint>> {
    validate_arguments(
        release_id,
        manifest_signature,
        candidate_signature,
        segment_count,
        max_state_bytes,
    )?;
    let mut tx = db.begin().await?;
    sqlx::query("SELECT id FROM releases WHERE id = ? FOR UPDATE")
        .bind(release_id)
        .fetch_optional(&mut *tx)
        .await?;
    let parent: Option<String> =
        sqlx::query_scalar("SELECT signature FROM par2_rename_progress WHERE releases_id = ?")
            .bind(release_id)
            .fetch_optional(&mut *tx)
            .await?;
    if parent.as_deref() != Some(manifest_signature) {
        tx.commit().await?;
        return Ok(None);
    }
    let rows = sqlx::query(sqlx::AssertSqlSafe(format!(
        "SELECT {ROW_COLUMNS} FROM par2_rename_candidate_progress WHERE releases_id = ?"
    )))
    .bind(release_id)
    .fetch_all(&mut *tx)
    .await?;
    let mut found = None;
    let mut total = 0i64;
    for row in &rows {
        let row = StoredRow::from_row(row)?;
        let ours = row.candidate_signature == candidate_signature;
        let checkpoint = row.decode(if ours { segment_count } else { MAX_SEGMENTS })?;
        if checkpoint.stored_bytes > max_state_bytes - total {
            bail!("The release checkpoint state exceeds its aggregate byte ceiling.");
        }
        total += checkpoint.stored_bytes;
        if ours {
            found = Some(checkpoint);
        }
    }
    tx.commit().await?;
    Ok(found)
}

/// What a save records.
pub struct Save<'a> {
    pub snapshot: &'a Snapshot,
    pub manifest_signature: &'a str,
    pub candidate_signature: &'a str,
    pub next_segment: i64,
    pub retry_segments: &'a [i64],
    pub accumulator: Option<&'a PacketAccumulator>,
    pub segment_count: i64,
    pub max_state_bytes: i64,
    pub blocked_reason: Option<&'a str>,
    pub decoded_offset: i64,
    pub identity: Option<&'a ArticleIdentity>,
}

/// `Par2CandidateProgress::save`. True means the checkpoint (possibly a `state_limit`
/// tombstone) is durable and should be reloaded before continuing. False means the release
/// snapshot or the ownership claim no longer matches.
pub async fn save(db: &MySqlPool, request: &Save<'_>, guard: &dyn Guard) -> Result<bool> {
    validate_arguments(
        request.snapshot.id,
        request.manifest_signature,
        request.candidate_signature,
        request.segment_count,
        request.max_state_bytes,
    )?;
    validate_cursor(
        request.next_segment,
        request.retry_segments,
        request.segment_count,
    )?;
    validate_block(request.blocked_reason)?;
    if request.accumulator.is_none() && request.blocked_reason.is_none() {
        bail!("A checkpoint without accumulator state must be blocked.");
    }
    canonical_identity(
        request.identity,
        request.decoded_offset,
        request.accumulator.is_none(),
    )?;
    let mut bytes = request
        .accumulator
        .map_or(0, |accumulator| accumulator.state_bytes() as i64)
        + request.identity.map_or(0, |i| i.json().len() as i64);
    let mut state = request.accumulator.map(PacketAccumulator::state_json);
    let mut identity = request.identity.map(ArticleIdentity::json);
    let mut blocked = request.blocked_reason.map(str::to_owned);
    let snapshot = request.snapshot;
    let result: Result<bool> = async {
        let mut tx = db.begin().await?;
        assert_writable(&mut tx, snapshot, guard).await?;
        let parent: Option<String> = sqlx::query_scalar(
            "SELECT signature FROM par2_rename_progress WHERE releases_id = ?",
        )
        .bind(snapshot.id)
        .fetch_optional(&mut *tx)
        .await?;
        let mut has_parent = parent.is_some();
        if parent.as_deref().is_some_and(|p| p != request.manifest_signature) {
            sqlx::query("DELETE FROM par2_rename_progress WHERE releases_id = ?")
                .bind(snapshot.id)
                .execute(&mut *tx)
                .await?;
            has_parent = false;
        }
        if !has_parent {
            sqlx::query(
                "INSERT INTO par2_rename_progress (releases_id, signature, `offset`, retry_indexes, updated_at) \
                 VALUES (?, ?, 0, '[]', NOW())",
            )
            .bind(snapshot.id)
            .bind(request.manifest_signature)
            .execute(&mut *tx)
            .await?;
        }
        let rows = sqlx::query(sqlx::AssertSqlSafe(format!(
            "SELECT {ROW_COLUMNS} FROM par2_rename_candidate_progress WHERE releases_id = ?"
        )))
        .bind(snapshot.id)
        .fetch_all(&mut *tx)
        .await?;
        let (mut other_bytes, mut existing_block, mut existing_bytes) = (0i64, false, 0i64);
        for row in &rows {
            let row = StoredRow::from_row(row)?;
            let ours = row.candidate_signature == request.candidate_signature;
            let checkpoint =
                row.decode(if ours { request.segment_count } else { MAX_SEGMENTS })?;
            if ours {
                existing_block = checkpoint.is_blocked();
                existing_bytes = checkpoint.stored_bytes;
            } else {
                if checkpoint.stored_bytes > request.max_state_bytes - other_bytes {
                    bail!("Existing release checkpoint state exceeds its byte ceiling.");
                }
                other_bytes += checkpoint.stored_bytes;
            }
        }
        if existing_block && existing_bytes > request.max_state_bytes - other_bytes {
            bail!("Existing blocked checkpoint exceeds the release byte ceiling.");
        }
        if !existing_block {
            if bytes > request.max_state_bytes - other_bytes {
                state = None;
                bytes = 0;
                blocked = Some("state_limit".to_owned());
                identity = None;
            }
            assert_writable(&mut tx, snapshot, guard).await?;
            let retry = Json::Arr(request.retry_segments.iter().map(|r| Json::Int(*r)).collect());
            sqlx::query(
                "INSERT INTO par2_rename_candidate_progress (releases_id, candidate_signature, next_segment, \
                 retry_segments, accumulator_state, decoded_offset, article_identity, stored_bytes, \
                 blocked_reason, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) \
                 ON DUPLICATE KEY UPDATE next_segment = VALUES(next_segment), \
                 retry_segments = VALUES(retry_segments), accumulator_state = VALUES(accumulator_state), \
                 decoded_offset = VALUES(decoded_offset), article_identity = VALUES(article_identity), \
                 stored_bytes = VALUES(stored_bytes), blocked_reason = VALUES(blocked_reason), \
                 updated_at = VALUES(updated_at)",
            )
            .bind(snapshot.id)
            .bind(request.candidate_signature)
            .bind(request.next_segment)
            .bind(retry.encode())
            .bind(&state)
            .bind(request.decoded_offset)
            .bind(&identity)
            .bind(bytes)
            .bind(&blocked)
            .execute(&mut *tx)
            .await?;
        }
        assert_writable(&mut tx, snapshot, guard).await?;
        tx.commit().await?;
        Ok(true)
    }
    .await;
    absorb_abort(result)
}

/// `blockCorruptCandidate`: quarantines an existing malformed candidate without trusting its
/// cursor or byte accounting. A valid or absent candidate returns false. Sibling corruption
/// or excess stays an error. The tombstone cannot establish completeness and stays blocked
/// until a manifest reset.
pub async fn block_corrupt_candidate(
    db: &MySqlPool,
    snapshot: &Snapshot,
    manifest_signature: &str,
    candidate_signature: &str,
    segment_count: i64,
    max_state_bytes: i64,
    guard: &dyn Guard,
) -> Result<bool> {
    validate_arguments(
        snapshot.id,
        manifest_signature,
        candidate_signature,
        segment_count,
        max_state_bytes,
    )?;
    let result: Result<bool> = async {
        let mut tx = db.begin().await?;
        assert_writable(&mut tx, snapshot, guard).await?;
        let parent: Option<String> = sqlx::query_scalar(
            "SELECT signature FROM par2_rename_progress WHERE releases_id = ? FOR UPDATE",
        )
        .bind(snapshot.id)
        .fetch_optional(&mut *tx)
        .await?;
        if parent.as_deref() != Some(manifest_signature) {
            return Err(aborted("PAR2 checkpoint ownership, release identity or manifest changed."));
        }
        let rows = sqlx::query(sqlx::AssertSqlSafe(format!(
            "SELECT {ROW_COLUMNS} FROM par2_rename_candidate_progress WHERE releases_id = ? FOR UPDATE"
        )))
        .bind(snapshot.id)
        .fetch_all(&mut *tx)
        .await?;
        let (mut corrupt_target, mut other_bytes) = (false, 0i64);
        for row in &rows {
            let row = StoredRow::from_row(row)?;
            if row.candidate_signature == candidate_signature {
                if row.decode(segment_count).is_err() {
                    corrupt_target = true;
                }
            } else {
                let checkpoint = row.decode(MAX_SEGMENTS)?;
                if checkpoint.stored_bytes > max_state_bytes - other_bytes {
                    bail!("Existing release checkpoint state exceeds its byte ceiling.");
                }
                other_bytes += checkpoint.stored_bytes;
            }
        }
        if !corrupt_target {
            return Ok(false);
        }
        assert_writable(&mut tx, snapshot, guard).await?;
        sqlx::query(
            "UPDATE par2_rename_candidate_progress SET next_segment = 0, retry_segments = '[]', \
             accumulator_state = NULL, decoded_offset = 0, article_identity = NULL, stored_bytes = 0, \
             blocked_reason = 'malformed_state', updated_at = NOW() \
             WHERE releases_id = ? AND candidate_signature = ?",
        )
        .bind(snapshot.id)
        .bind(candidate_signature)
        .execute(&mut *tx)
        .await?;
        assert_writable(&mut tx, snapshot, guard).await?;
        let parent: Option<String> =
            sqlx::query_scalar("SELECT signature FROM par2_rename_progress WHERE releases_id = ?")
                .bind(snapshot.id)
                .fetch_optional(&mut *tx)
                .await?;
        if parent.as_deref() != Some(manifest_signature) {
            return Err(aborted("PAR2 checkpoint ownership, release identity or manifest changed."));
        }
        tx.commit().await?;
        Ok(true)
    }
    .await;
    absorb_abort(result)
}

/// `deleteCandidate`: drops one candidate's checkpoint under the same ownership rules.
pub async fn delete_candidate(
    db: &MySqlPool,
    snapshot: &Snapshot,
    manifest_signature: &str,
    candidate_signature: &str,
    guard: &dyn Guard,
) -> Result<bool> {
    validate_arguments(snapshot.id, manifest_signature, candidate_signature, 1, 1)?;
    let result: Result<bool> = async {
        let mut tx = db.begin().await?;
        assert_writable(&mut tx, snapshot, guard).await?;
        let parent: Option<String> =
            sqlx::query_scalar("SELECT signature FROM par2_rename_progress WHERE releases_id = ?")
                .bind(snapshot.id)
                .fetch_optional(&mut *tx)
                .await?;
        if parent.as_deref() != Some(manifest_signature) {
            return Err(aborted("PAR2 checkpoint ownership or release identity changed."));
        }
        sqlx::query(
            "DELETE FROM par2_rename_candidate_progress WHERE releases_id = ? AND candidate_signature = ?",
        )
        .bind(snapshot.id)
        .bind(candidate_signature)
        .execute(&mut *tx)
        .await?;
        assert_writable(&mut tx, snapshot, guard).await?;
        tx.commit().await?;
        Ok(true)
    }
    .await;
    absorb_abort(result)
}
