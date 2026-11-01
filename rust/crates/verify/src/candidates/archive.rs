//! Moves a collection's headers into a candidate manifest and deletes them from the ingestion tables.

use std::sync::LazyLock;

use anyhow::{Result, bail};
use chrono::{NaiveDateTime, TimeDelta};
use futures_util::TryStreamExt;
use regex::Regex;
use sqlx::{Connection, MySql, MySqlConnection, QueryBuilder};

use super::deferrals::unlocked_by_raised_budget;
use super::expiry::owed_partial_attempt;
use super::{Candidate, now, release_late_article_fence};
use crate::collections::{self, ELIGIBLE, STATUS_INSERTED};
use crate::context::Verify;
use crate::groups::ids_by_name;
use crate::in_tx;
use crate::inventory::{self, HeaderStatus};
use crate::ledger;
use crate::locks::Admission;
use crate::manifest::{self, Manifest, ManifestFile, PreparedManifest, Segment};
use crate::reader::declared_file_count;

/// A collection's headers would exceed the metadata budget. They stay in the ingestion tables, and
/// the deferrals keep archival from reading them again until the budget or backoff changes.
#[derive(Debug, thiserror::Error)]
#[error("{0}")]
pub struct MetadataBudgetExceeded(&'static str);

/// The columns archival reads from a collection row.
#[derive(Clone, Debug, sqlx::FromRow)]
pub(super) struct CollectionRow {
    #[sqlx(try_from = "u32")]
    pub id: i64,
    pub subject: String,
    pub fromname: String,
    pub date: Option<NaiveDateTime>,
    #[sqlx(try_from = "u32")]
    pub totalfiles: i64,
    #[sqlx(try_from = "u32")]
    pub groups_id: i64,
    pub collectionhash: Vec<u8>,
    pub collection_regexes_id: i32,
    pub dateadded: Option<NaiveDateTime>,
    pub last_seen_at: Option<NaiveDateTime>,
    pub added: Option<NaiveDateTime>,
    pub filecheck: i8,
    pub releases_id: Option<i32>,
}

pub(super) const COLLECTION_COLUMNS: &str = "id, subject, fromname, date, totalfiles, groups_id, collectionhash, \
    collection_regexes_id, dateadded, last_seen_at, CAST(added AS DATETIME) AS added, filecheck, releases_id";

const ALWAYS: fn(&Candidate) -> bool = |_| true;

/// Archives one collection. Manifests its transaction stopped referencing are deleted after commit.
pub async fn archive(verify: &Verify, collection_id: i64) -> Result<()> {
    let mut admission = Admission::enter(&verify.db).await?;
    let outcome = archive_admitted(verify, admission.conn(), collection_id).await;
    let left = admission.leave().await;
    let unreferenced = outcome?;
    left?;
    for name in unreferenced {
        manifest::delete_if_unreferenced(&verify.db, &verify.config, &name).await?;
    }
    Ok(())
}

async fn archive_admitted(
    verify: &Verify,
    conn: &mut MySqlConnection,
    collection_id: i64,
) -> Result<Vec<String>> {
    let hash: Option<Vec<u8>> =
        sqlx::query_scalar("SELECT collectionhash FROM collections WHERE id = ?")
            .bind(collection_id)
            .fetch_optional(&mut *conn)
            .await?;
    let Some(hash) = hash else {
        return Ok(Vec::new());
    };
    let unlocked = unlocked_by_raised_budget(verify, &mut *conn, collection_id, &hash).await;
    in_tx!(conn, 5, |tx| {
        archive_locked(verify, tx, collection_id, &hash, unlocked).await
    })
}

/// Locks through ingestion's unique hash index before anything else. A collection another archiver
/// or header writer holds is skipped, and picked up by a later pass.
async fn lock_collection(
    conn: &mut MySqlConnection,
    collection_id: i64,
    hash: &[u8],
) -> Result<Option<CollectionRow>> {
    let sql = format!(
        "SELECT {COLLECTION_COLUMNS} FROM collections FORCE INDEX (ix_collection_collectionhash) \
         WHERE collectionhash = ? AND id = ? FOR UPDATE SKIP LOCKED"
    );
    Ok(sqlx::query_as(sqlx::AssertSqlSafe(sql))
        .bind(hash)
        .bind(collection_id)
        .fetch_optional(&mut *conn)
        .await?)
}

/// The upload's candidates matching `keep`, newest first, locked. Rows for a collection hash are
/// only inserted under that collection's lock, which `archive` holds, so outside a caller's snapshot
/// a plain read finds them all and they're locked by primary key: a locking read of the source index
/// would also lock its neighbours, blocking or deadlocking other uploads' admissions.
pub(super) async fn lock_source_candidates(
    conn: &mut MySqlConnection,
    source: &str,
    current_reads: bool,
    keep: impl Fn(&Candidate) -> bool,
) -> Result<Vec<Candidate>> {
    let mut rows: Vec<Candidate> = if current_reads {
        sqlx::query_as(
            "SELECT * FROM release_verification_candidates WHERE source_identity = ? ORDER BY id FOR UPDATE",
        )
        .bind(source)
        .fetch_all(&mut *conn)
        .await?
    } else {
        let ids: Vec<u64> = sqlx::query_scalar(
            "SELECT id FROM release_verification_candidates WHERE source_identity = ?",
        )
        .bind(source)
        .fetch_all(&mut *conn)
        .await?;
        if ids.is_empty() {
            Vec::new()
        } else {
            let mut query = QueryBuilder::<MySql>::new(
                "SELECT * FROM release_verification_candidates FORCE INDEX (PRIMARY) WHERE id IN (",
            );
            let mut separated = query.separated(",");
            for id in &ids {
                separated.push_bind(*id);
            }
            query.push(") ORDER BY id FOR UPDATE");
            query.build_query_as().fetch_all(&mut *conn).await?
        }
    };
    rows.retain(|row| row.source_identity.as_deref() == Some(source) && keep(row));
    rows.reverse();
    Ok(rows)
}

/// Candidates of this upload holding a partial's inventory, those a release's
/// `late_article_candidate_id` names, newest first, locked.
pub(super) async fn lock_partial_holders(
    conn: &mut MySqlConnection,
    source: &str,
    current_reads: bool,
) -> Result<Vec<Candidate>> {
    // Lock the upload's candidates, then read the pointers with a current (locking) read: a plain
    // read could use an older snapshot and miss a just-published partial.
    let candidates = lock_source_candidates(conn, source, current_reads, |row| {
        !row.manifest_path.is_empty()
    })
    .await?;
    if candidates.is_empty() {
        return Ok(Vec::new());
    }
    // A holder is the published partial or the generation upgrading it: lock those release rows by
    // primary key. Locking by the pointer index would gap-lock its top range and block, or deadlock
    // with, every concurrent pointer move and new partial.
    let mut release_ids: Vec<u64> = candidates
        .iter()
        .flat_map(|row| [row.release_id, row.upgrades_release_id])
        .flatten()
        .collect();
    release_ids.sort_unstable();
    release_ids.dedup();
    if release_ids.is_empty() {
        return Ok(Vec::new());
    }
    let mut query =
        QueryBuilder::<MySql>::new("SELECT late_article_candidate_id FROM releases WHERE id IN (");
    let mut separated = query.separated(",");
    for id in &release_ids {
        separated.push_bind(*id);
    }
    query.push(") ORDER BY id LOCK IN SHARE MODE");
    let pointers: Vec<(Option<u64>,)> = query.build_query_as().fetch_all(&mut *conn).await?;
    let held: Vec<i64> = pointers
        .into_iter()
        .filter_map(|(pointer,)| pointer.map(|value| value as i64))
        .collect();
    Ok(candidates
        .into_iter()
        .filter(|candidate| held.contains(&candidate.id))
        .collect())
}

/// Whether merging made a clean retained inventory unusable: conflicting identities, or header
/// defects that later articles can never repair because merging only adds.
pub(super) fn spoils(retained: &Manifest, merged: &Manifest) -> bool {
    if retained.conflict {
        return false;
    }
    merged.conflict
        || (inventory::assess(merged).status == HeaderStatus::Rejected
            && inventory::assess(retained).status != HeaderStatus::Rejected)
}

/// Adds `new`'s headers to `old`. Different data at a part already held marks a conflict, and the
/// part already held stays.
pub(super) fn merge(old: &Manifest, new: &Manifest) -> Manifest {
    let mut merged = old.clone();
    let mut files: std::collections::BTreeMap<String, ManifestFile> = old
        .files
        .iter()
        .map(|file| (file.key.clone(), file.clone()))
        .collect();
    for file in &new.files {
        let Some(previous) = files.get_mut(&file.key) else {
            files.insert(file.key.clone(), file.clone());
            continue;
        };
        if previous.subject != file.subject || previous.totalparts != file.totalparts {
            merged.conflict = true;
        }
        let mut parts: std::collections::BTreeMap<i64, Segment> = previous
            .segments
            .iter()
            .map(|segment| (segment.number, segment.clone()))
            .collect();
        for segment in &file.segments {
            match parts.get(&segment.number) {
                Some(held) if held != segment => merged.conflict = true,
                _ => {
                    parts.insert(segment.number, segment.clone());
                }
            }
        }
        previous.segments = parts.into_values().collect();
    }
    merged.files = files.into_values().collect();
    merged.groups.extend(new.groups.iter().cloned());
    merged.groups.sort();
    merged.groups.dedup();
    if old.fromname != new.fromname
        || (old.expected_files > 0
            && new.expected_files > 0
            && old.expected_files != new.expected_files)
    {
        merged.conflict = true;
    }
    merged.expected_files = old.expected_files.max(new.expected_files).max(0);
    if new.legacy_release_id.is_some() {
        if old.legacy_release_id.is_some() && old.legacy_release_id != new.legacy_release_id {
            merged.conflict = true;
        } else {
            merged.legacy_release_id = new.legacy_release_id;
        }
    }
    merged
}

/// The holder these headers are a partial's late articles for, as `archive` routes them; hot
/// collection invalidation must pick the same one, or it marks a candidate archive never merges into.
pub(super) async fn late_article_owner(
    verify: &Verify,
    conn: &mut MySqlConnection,
    collection: &CollectionRow,
    source: &str,
    holders: &[Candidate],
    headers: &Manifest,
    current_reads: bool,
) -> Result<Option<Candidate>> {
    let Some(holder) = partial_owner(verify, conn, holders, headers).await? else {
        return Ok(None);
    };
    if collection.filecheck == STATUS_INSERTED && collection.releases_id.is_some() {
        // A legacy release's headers publish that release; merged into a partial, the generation
        // could upgrade neither, so they're kept apart.
        return Ok(None);
    }
    // Any established repost under the same hash (pending, holding no partial) that also takes these
    // headers makes them ambiguous: never upgrade the partial with possibly mixed articles. They then
    // follow the newest repost, as ordinary pending headers do.
    let held: Vec<i64> = holders.iter().map(|row| row.id).collect();
    let reposts = lock_source_candidates(conn, source, current_reads, |row| {
        row.release_id.is_none()
            && !row.manifest_path.is_empty()
            && !matches!(
                row.status.as_str(),
                "expired" | "filtered" | "metadata_expired"
            )
            && !held.contains(&row.id)
            && row.upgrades_release_id.is_none()
    })
    .await?;
    for repost in &reposts {
        let old = manifest::read_current(conn, &verify.config, &repost.manifest_path).await?;
        if !spoils(&old, &merge(&old, headers)) {
            return Ok(None);
        }
    }
    // Another post under the hash published since the partial: its inventory is gone, so these
    // headers' compatibility with it can no longer be checked. Keep them apart.
    let partial = holder
        .release_id
        .or(holder.upgrades_release_id)
        .unwrap_or(0);
    let published_at: Option<NaiveDateTime> =
        sqlx::query_scalar("SELECT adddate FROM releases WHERE id = ?")
            .bind(partial)
            .fetch_optional(&mut *conn)
            .await?
            .flatten();
    let published = lock_source_candidates(conn, source, current_reads, |row| {
        row.release_id.is_some_and(|release| {
            release != partial
                && !held.contains(&row.id)
                && (release > partial
                    || published_at
                        .is_some_and(|at| row.verified_at.is_some_and(|verified| verified >= at)))
        })
    })
    .await?;
    Ok(published.is_empty().then_some(holder))
}

/// The one holder whose inventory these headers extend without conflict, if exactly one does. A
/// repost by the same poster under the same subject and file count shares the hash, and a segment
/// at an unseen part number fits either inventory. Mixing them would publish an unusable NZB, so
/// headers more than one holder could take are kept apart.
async fn partial_owner(
    verify: &Verify,
    conn: &mut MySqlConnection,
    partials: &[Candidate],
    headers: &Manifest,
) -> Result<Option<Candidate>> {
    let mut owners = Vec::new();
    for partial in partials {
        let retained = manifest::read_current(conn, &verify.config, &partial.manifest_path).await?;
        if retained.conflict || !spoils(&retained, &merge(&retained, headers)) {
            owners.push(partial.clone());
        }
    }
    Ok(if owners.len() == 1 {
        owners.pop()
    } else {
        None
    })
}

/// The newest pending candidate of this upload that holds no partial's inventory, locked. An unheld
/// upgrade generation is orphaned, its partial deleted: never admit new uploads into it, even while
/// a worker's lease keeps it from being retired.
async fn lock_latest_unheld(
    conn: &mut MySqlConnection,
    source: &str,
    held: &[i64],
    current_reads: bool,
) -> Result<Option<Candidate>> {
    Ok(lock_source_candidates(conn, source, current_reads, |row| {
        row.release_id.is_none()
            && !matches!(
                row.status.as_str(),
                "expired" | "filtered" | "metadata_expired"
            )
            && !held.contains(&row.id)
            && row.upgrades_release_id.is_none()
    })
    .await?
    .into_iter()
    .next())
}

/// Reads a collection's headers. Without `current_reads` these are the transaction's plain reads,
/// taken after the collection lock; with it they're locking reads, so a caller's older snapshot
/// can't omit committed headers. Reading stops once the metadata budget is passed.
pub(super) async fn snapshot_headers(
    verify: &Verify,
    conn: &mut MySqlConnection,
    collection: &CollectionRow,
    current_reads: bool,
) -> Result<Manifest> {
    let share = if current_reads {
        " LOCK IN SHARE MODE"
    } else {
        ""
    };
    let sql = format!("SELECT group_name FROM collection_groups WHERE collections_id = ?{share}");
    let mut groups: Vec<String> = sqlx::query_scalar(sqlx::AssertSqlSafe(sql))
        .bind(collection.id)
        .fetch_all(&mut *conn)
        .await?;
    if groups.is_empty() {
        let sql = format!("SELECT name FROM usenet_groups WHERE id = ?{share}");
        let name: Option<String> = sqlx::query_scalar(sqlx::AssertSqlSafe(sql))
            .bind(collection.groups_id)
            .fetch_optional(&mut *conn)
            .await?;
        groups.push(name.unwrap_or_default());
    }
    groups.sort();
    let budget = verify.config.metadata_budget();
    let mut metadata_bytes = 0;
    let sql = format!(
        "SELECT id, binaryhash, name, totalparts FROM binaries WHERE collections_id = ? ORDER BY binaryhash{share}"
    );
    let binaries: Vec<(u64, Vec<u8>, String, u32)> = sqlx::query_as(sqlx::AssertSqlSafe(sql))
        .bind(collection.id)
        .fetch_all(&mut *conn)
        .await?;
    let mut files = Vec::with_capacity(binaries.len());
    for batch in binaries.chunks(100) {
        let mut query = QueryBuilder::<MySql>::new(
            "SELECT binaries_id, partnumber, messageid, size FROM parts WHERE binaries_id IN (",
        );
        let mut separated = query.separated(",");
        for (id, ..) in batch {
            separated.push_bind(*id);
        }
        query.push(") ORDER BY binaries_id, partnumber");
        query.push(share);
        let mut by_binary: std::collections::HashMap<u64, Vec<Segment>> =
            std::collections::HashMap::new();
        {
            // Streamed, so an upload far over the budget stops at the budget.
            let mut parts = query
                .build_query_as::<(u64, u32, String, u32)>()
                .fetch(&mut *conn);
            while let Some((binary, number, messageid, size)) = parts.try_next().await? {
                metadata_bytes += 128 + messageid.len() as i64;
                if metadata_bytes > budget {
                    return Err(MetadataBudgetExceeded(
                        "Candidate exceeds metadata budget; headers retained.",
                    )
                    .into());
                }
                by_binary.entry(binary).or_default().push(Segment {
                    number: i64::from(number),
                    messageid,
                    bytes: i64::from(size),
                });
            }
        }
        for (id, hash, name, totalparts) in batch {
            files.push(ManifestFile {
                key: hex::encode(hash),
                subject: name.clone(),
                totalparts: i64::from(*totalparts),
                totalparts_inferred: false,
                segments: by_binary.remove(id).unwrap_or_default(),
            });
        }
    }
    let mut expected = collection.totalfiles;
    if collection.filecheck == STATUS_INSERTED {
        // Legacy insertion could overwrite the declared count with observed files. Recover explicit
        // counters outside quoted filenames only.
        expected = 0;
        for file in &files {
            if has_quoted_name(&file.subject) {
                expected = expected.max(declared_file_count(&file.subject));
            }
        }
    }
    Ok(Manifest {
        legacy_release_id: None,
        identity: hex::encode(&collection.collectionhash),
        subject: collection.subject.clone(),
        fromname: collection.fromname.clone(),
        date: collection
            .date
            .map(|date| date.format("%Y-%m-%d %H:%M:%S").to_string()),
        groups_id: collection.groups_id,
        regex_id: i64::from(collection.collection_regexes_id),
        expected_files: expected,
        groups,
        files,
        conflict: false,
        ..Manifest::default()
    })
}

fn has_quoted_name(subject: &str) -> bool {
    static QUOTED: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r#""[^"\r\n]+"|'[^'\r\n]+'"#).expect("valid"));
    QUOTED.is_match(subject)
}

/// Legacy publication takes capacity before its release row; keeps that lock order.
pub(super) async fn legacy_release_id(
    conn: &mut MySqlConnection,
    collection: &CollectionRow,
) -> Result<Option<i64>> {
    if collection.filecheck != STATUS_INSERTED || collection.releases_id.is_none() {
        return Ok(None);
    }
    let id: Option<u32> =
        sqlx::query_scalar("SELECT id FROM releases WHERE id = ? AND nzbstatus = 0 FOR UPDATE")
            .bind(collection.releases_id)
            .fetch_optional(&mut *conn)
            .await?;
    Ok(id.map(i64::from))
}

/// A new version with header defects is claimed once; its verifier or the preparer then moves it out
/// of the claim queue instead of retrying it every retry interval.
pub(super) fn admitted_status(status: HeaderStatus) -> &'static str {
    match status {
        HeaderStatus::Rejected => "unknown",
        other => other.as_str(),
    }
}

/// A published candidate's retained inventory still belongs to a live partial release.
async fn retains_live_partial(conn: &mut MySqlConnection, candidate: &Candidate) -> Result<bool> {
    let Some(release) = candidate.release_id else {
        return Ok(false);
    };
    if candidate.manifest_path.is_empty() || candidate.status != "sampled" {
        return Ok(false);
    }
    let found: Option<i32> = sqlx::query_scalar(
        "SELECT 1 FROM releases WHERE id = ? AND articles_expected IS NOT NULL LOCK IN SHARE MODE",
    )
    .bind(release)
    .fetch_optional(&mut *conn)
    .await?;
    Ok(found.is_some())
}

async fn archive_locked(
    verify: &Verify,
    tx: &mut MySqlConnection,
    collection_id: i64,
    hash: &[u8],
    unlocked: bool,
) -> Result<Vec<String>> {
    let config = &verify.config;
    let mut unreferenced: Vec<String> = Vec::new();
    let Some(collection) = lock_collection(tx, collection_id, hash).await? else {
        return Ok(unreferenced);
    };
    if collection.filecheck == STATUS_INSERTED {
        let sql = format!("SELECT 1 FROM collections WHERE collections.id = ? AND {ELIGIBLE}");
        let eligible: Option<i32> = sqlx::query_scalar(sqlx::AssertSqlSafe(sql))
            .bind(collection_id)
            .fetch_optional(&mut *tx)
            .await?;
        if eligible.is_none() {
            return Ok(unreferenced);
        }
    }
    let source = hex::encode(&collection.collectionhash);
    // The parent lock protects the header snapshot; unrelated admissions need no lock yet. Terminal
    // replays keep their existing cheap, locked validation path.
    let observed: Option<(String, String, Option<u64>)> = sqlx::query_as(
        "SELECT status, manifest_path, release_id FROM release_verification_candidates \
         WHERE source_identity = ? ORDER BY id DESC LIMIT 1",
    )
    .bind(&source)
    .fetch_optional(&mut *tx)
    .await?;
    let filtered = observed
        .as_ref()
        .is_some_and(|(status, ..)| status == "filtered");
    let mut headers: Option<Manifest> = None;
    let mut prepared: Option<PreparedManifest> = None;
    let mut header_assessment: Option<inventory::Assessment> = None;
    let mut snapshot_failure: Option<anyhow::Error> = None;
    if !filtered {
        match snapshot_headers(verify, tx, &collection, false).await {
            Ok(snapshot) => {
                // Prepare new, non-legacy admissions while only the parent is held. Existing
                // manifests and legacy rows still need locked reconciliation.
                let fresh = observed
                    .as_ref()
                    .is_none_or(|(_, path, release)| path.is_empty() || release.is_some());
                if collection.filecheck != STATUS_INSERTED && fresh {
                    prepared = Some(PreparedManifest::new(&snapshot)?);
                    header_assessment = Some(inventory::assess(&snapshot));
                }
                headers = Some(snapshot);
            }
            // A stale read view can hide a committed filtered replay; validate it first.
            Err(error) if error.is::<MetadataBudgetExceeded>() => snapshot_failure = Some(error),
            Err(error) => return Err(error),
        }
    }
    // Lock all of the upload's candidates first, in primary-key order like every batched candidate
    // update; the lookups below then only re-read rows this transaction already holds.
    let all = lock_source_candidates(tx, &source, false, ALWAYS).await?;
    let mut existing: Option<Candidate> = all.into_iter().next();
    // A partial's inventory is held by the candidate its release points at: the published partial
    // itself, or the pending generation late articles moved it to. Late articles join the newest
    // holder they extend without conflict, past any newer post under the same hash; headers that
    // conflict with a holder never merge into it.
    let mut holder: Option<Candidate> = None;
    let mut apart = false;
    let mut holders: Vec<Candidate> = Vec::new();
    if existing.is_some() {
        holders = lock_partial_holders(tx, &source, false).await?;
        if !holders.is_empty() && snapshot_failure.is_none() {
            // A filtered newer post skipped the snapshot; these headers may still be a partial's.
            let snapshot = match headers.take() {
                Some(snapshot) => Ok(snapshot),
                None => snapshot_headers(verify, tx, &collection, false).await,
            };
            match snapshot {
                Ok(snapshot) => {
                    holder = late_article_owner(
                        verify,
                        tx,
                        &collection,
                        &source,
                        &holders,
                        &snapshot,
                        false,
                    )
                    .await?;
                    headers = Some(snapshot);
                }
                Err(error) if error.is::<MetadataBudgetExceeded>() => {
                    snapshot_failure = Some(error)
                }
                Err(error) => return Err(error),
            }
            let existing_id = existing.as_ref().map_or(0, |row| row.id);
            apart = holder.is_none() && holders.iter().any(|held| held.id == existing_id);
        }
    }
    // A pending generation whose partial was deleted holds no partial any more: retire it, since its
    // retained articles must not publish anew, so it neither absorbs nor diverts later uploads.
    let mut retired_bytes = 0;
    let mut identity_after: Option<String> = None;
    let held_ids: Vec<i64> = holders.iter().map(|held| held.id).collect();
    if let Some(current) = existing.clone() {
        let moment = now();
        let orphans = lock_source_candidates(tx, &source, false, |row| {
            row.release_id.is_none()
                && row.upgrades_release_id.is_some()
                && !row.manifest_path.is_empty()
                && !held_ids.contains(&row.id)
                // One a worker is verifying is left to it: the publisher abandons it.
                && row.lease_until.is_none_or(|until| until < moment)
        })
        .await?;
        for orphan in &orphans {
            // The version stays, as when a worker retires a candidate, so an outcome is still recognized.
            sqlx::query(
                "UPDATE release_verification_candidates SET status = 'expired', \
                 reason = 'The partial release it would upgrade was deleted.', manifest_path = '', manifest_bytes = 0, \
                 lease_token = NULL, lease_until = NULL, upgrades_release_id = NULL WHERE id = ?",
            )
            .bind(orphan.id)
            .execute(&mut *tx)
            .await?;
            retired_bytes += orphan.manifest_bytes;
            unreferenced.push(orphan.manifest_path.clone());
        }
        if orphans.iter().any(|orphan| orphan.id == current.id) {
            // Never revive the retired row: its identity and timing belonged to the deleted partial.
            identity_after = Some(generation_identity(&source, current.id));
            existing = None;
        }
    }
    if holder.is_none() && (existing.is_some() || identity_after.is_some()) {
        // Not a partial's articles: they join the newest pending candidate that holds no partial's
        // inventory. Where partials share the hash, headers that would spoil it are kept apart too.
        let mut own = lock_latest_unheld(tx, &source, &held_ids, false).await?;
        let existing_id = existing.as_ref().map_or(0, |row| row.id);
        let shared = !holders.is_empty()
            || identity_after.is_some()
            || own.as_ref().is_some_and(|row| row.id != existing_id);
        if let Some(row) = own.clone()
            && shared
            && snapshot_failure.is_none()
        {
            let snapshot = match headers.take() {
                Some(snapshot) => snapshot,
                None => snapshot_headers(verify, tx, &collection, false).await?,
            };
            let old = manifest::read_current(tx, config, &row.manifest_path).await?;
            if spoils(&old, &merge(&old, &snapshot)) {
                own = None;
                apart = existing.is_some();
            }
            headers = Some(snapshot);
        }
        match own {
            Some(row) if row.id != existing_id => {
                existing = Some(row);
                apart = false;
                identity_after = None;
            }
            // The newest row is an orphaned generation still leased to a worker: start a new one.
            None if existing.as_ref().is_some_and(|row| {
                row.release_id.is_none() && row.upgrades_release_id.is_some()
            }) =>
            {
                apart = true;
            }
            _ => {}
        }
    }
    if let Some(row) = holder.clone()
        && (row.release_id.is_none() || row.id == existing.as_ref().map_or(0, |current| current.id))
    {
        // A pending generation is merged like any candidate; the published partial itself as the
        // newest candidate takes the usual path below.
        existing = Some(row);
        holder = None;
    }
    let existing_filtered = existing
        .as_ref()
        .is_some_and(|row| row.status == "filtered");
    if existing_filtered
        && holder.is_none()
        && !holders.is_empty()
        && let Some(failure) = snapshot_failure.take()
    {
        // Unreadable headers might be a live partial's late articles, not the filtered post's
        // replay; keep them rather than delete them as one.
        return Err(failure);
    }
    if existing_filtered && holder.is_none() {
        let row = existing.as_ref().expect("checked above");
        if row.release_id.is_none()
            && row.groups_id == collection.groups_id
            && row.manifest_path.is_empty()
            && row.manifest_bytes == 0
        {
            let usable: Option<u64> = sqlx::query_scalar(
                "SELECT p.binaries_id FROM binaries AS b INNER JOIN parts AS p ON p.binaries_id = b.id \
                 WHERE b.collections_id = ? AND p.size > 0 AND p.messageid <> '' LIMIT 1 FOR UPDATE",
            )
            .bind(collection_id)
            .fetch_optional(&mut *tx)
            .await?;
            if usable.is_some() {
                collections::delete_rows(tx, &[collection_id]).await?;
            }
        }
        // Orphans retired above still give back their capacity.
        if retired_bytes > 0 {
            ledger::adjust(tx, config, -retired_bytes).await?;
        }
        return Ok(unreferenced);
    }
    if let Some(failure) = snapshot_failure {
        return Err(failure);
    }
    let mut identity = existing
        .as_ref()
        .map(|row| row.identity.clone())
        .or_else(|| identity_after.clone())
        .unwrap_or_else(|| source.clone());
    // A partial publication retains its inventory: late articles start a generation that merges it
    // and upgrades that release in place, instead of publishing only the late fragment.
    let mut previous: Option<Candidate> = None;
    let mut merges = false;
    if let Some(row) = holder.take() {
        identity = match &existing {
            None => identity_after.clone().unwrap_or_else(|| source.clone()),
            Some(current) => generation_identity(&source, current.id),
        };
        previous = Some(row);
        merges = true;
        existing = None;
    } else if apart && existing.is_some() {
        // Another post under a holder's hash: its own generation, leaving the holder untouched.
        identity = generation_identity(&source, existing.as_ref().expect("checked").id);
        existing = None;
    } else if existing
        .as_ref()
        .is_some_and(|row| row.release_id.is_some())
    {
        let row = existing.take().expect("checked");
        identity = generation_identity(&source, row.id);
        // A retained inventory whose partial release is gone is released, not merged.
        if !row.manifest_path.is_empty() {
            merges = retains_live_partial(tx, &row).await?;
            previous = Some(row);
        }
    }
    let mut built = match headers.take() {
        Some(snapshot) => snapshot,
        None => snapshot_headers(verify, tx, &collection, false).await?,
    };
    built.legacy_release_id = legacy_release_id(tx, &collection).await?;
    if merges {
        let row = previous.as_ref().expect("merges has a previous");
        let mut retained = manifest::read_current(tx, config, &row.manifest_path).await?;
        // The publication consumed any legacy release; the next generation publishes anew.
        retained.legacy_release_id = None;
        let merged = merge(&retained, &built);
        if spoils(&retained, &merged) {
            // Conflicting headers (another post under the same hash) would poison the retained
            // inventory; leave the partial and its inventory as they are and archive these apart.
            previous = None;
            merges = false;
        } else {
            built = merged;
            if retained.article_identity() == built.article_identity() && !built.conflict {
                // A replay of headers the partial already holds adds no articles, but a crosspost
                // replay can add groups: record those on the partial release before discarding them.
                let release = row
                    .release_id
                    .expect("a retained inventory has its release");
                let added: Vec<String> = built
                    .groups
                    .iter()
                    .filter(|group| !retained.groups.contains(group))
                    .cloned()
                    .collect();
                let mut group_ids: Vec<i64> =
                    ids_by_name(tx, &added).await?.into_values().collect();
                group_ids.sort_unstable();
                group_ids.dedup();
                for group in group_ids {
                    sqlx::query(
                        "INSERT IGNORE INTO releases_groups (releases_id, groups_id) VALUES (?, ?)",
                    )
                    .bind(release)
                    .bind(group)
                    .execute(&mut *tx)
                    .await?;
                }
                collections::delete_rows(tx, &[collection_id]).await?;
                if retired_bytes > 0 {
                    ledger::adjust(tx, config, -retired_bytes).await?;
                }
                return Ok(unreferenced);
            }
            built.upgrades_release_id = row.release_id.map(|id| id as i64);
            prepared = None;
            header_assessment = None;
        }
    }
    let mut new_articles = true;
    if let Some(row) = existing
        .as_ref()
        .filter(|row| !row.manifest_path.is_empty())
    {
        let old = manifest::read_current(tx, config, &row.manifest_path).await?;
        built = merge(&old, &built);
        new_articles = old.article_identity() != built.article_identity();
        // A candidate may have appeared since the advisory read; persist its merge.
        prepared = None;
        header_assessment = None;
    }
    if (merges || existing.is_some()) && built.metadata_bytes() > config.metadata_budget() {
        // Each snapshot fits, but the merged inventory wouldn't: keep these headers for a later pass
        // or a raised budget, before anything is written or charged to the ledger.
        return Err(MetadataBudgetExceeded(
            "Merged candidate exceeds metadata budget; headers retained.",
        )
        .into());
    }
    let prepared = match prepared {
        Some(prepared) => prepared,
        None => PreparedManifest::new(&built)?,
    };
    let path = manifest::write_prepared(tx, &prepared).await?;
    let size = manifest::size(tx, config, &path).await?;
    let mut arrival = collection
        .last_seen_at
        .or(collection.dateadded)
        .or(collection.added)
        .unwrap_or_else(now);
    if unlocked {
        arrival = arrival.max(now());
    }
    let last_unique_at = match (&existing, &previous, merges) {
        (None, Some(row), true) => arrival.max(row.last_unique_at),
        (None, ..) => arrival,
        (Some(row), ..) if new_articles => arrival.max(row.last_unique_at),
        (Some(row), ..) => row.last_unique_at,
    };
    // A replay must not discard an upload still owed its partial attempt, which expiry itself leaves.
    let owed = config.partial_after_hours > 0
        && existing
            .as_ref()
            .is_some_and(|row| owed_partial_attempt(verify, row));
    let expired = last_unique_at < now() - TimeDelta::hours(config.expiry_hours) && !owed;
    let delta = (if expired { 0 } else { size })
        - existing.as_ref().map_or(0, |row| row.manifest_bytes)
        - previous.as_ref().map_or(0, |row| row.manifest_bytes)
        - retired_bytes;
    if !ledger::fits(&mut *tx, config, delta).await? {
        // The rollback takes the new manifest row with it.
        bail!("Pending verification capacity is full; original headers retained.");
    }
    let changed = existing
        .as_ref()
        .is_none_or(|row| row.manifest_path != path);
    let dirty = existing.as_ref().is_some_and(|row| row.status == "dirty");
    let manifest_hash = &path[..64];
    let upgrades = if merges {
        previous.as_ref().and_then(|row| row.release_id)
    } else {
        None
    };
    let assessment =
        (changed || dirty).then(|| header_assessment.unwrap_or_else(|| inventory::assess(&built)));
    let holder_id = write_candidate(
        tx,
        &CandidateWrite {
            identity: &identity,
            source: &source,
            groups_id: collection.groups_id,
            path: &path,
            size,
            manifest_hash,
            upgrades,
            assessment,
            last_unique_at,
            expired,
        },
        existing.as_ref(),
    )
    .await?;
    if expired && let Some(row) = &existing {
        // An expired pending generation can no longer upgrade its partial.
        let pointed: Vec<i64> = [row.release_id, row.upgrades_release_id]
            .into_iter()
            .flatten()
            .filter(|id| *id > 0)
            .map(|id| id as i64)
            .collect();
        release_late_article_fence(tx, &pointed, Some(row.id)).await?;
    }
    if let Some(row) = &previous {
        // The new generation now holds the retained inventory and its capacity, and becomes the only
        // candidate that may upgrade the partial; an already expired one settles it.
        sqlx::query(
            "UPDATE release_verification_candidates SET manifest_path = '', manifest_bytes = 0, \
             retained_for_release_id = NULL, retained_last_unique_at = NULL WHERE id = ?",
        )
        .bind(row.id)
        .execute(&mut *tx)
        .await?;
        if merges {
            let release = row.release_id.expect("a merge has its release") as i64;
            if expired {
                release_late_article_fence(tx, &[release], Some(row.id)).await?;
            } else {
                sqlx::query(
                    "UPDATE releases SET late_article_candidate_id = ? WHERE id = ? AND late_article_candidate_id = ?",
                )
                .bind(holder_id)
                .bind(release)
                .bind(row.id)
                .execute(&mut *tx)
                .await?;
            }
        }
        if row.manifest_path != path {
            unreferenced.push(row.manifest_path.clone());
        }
    }
    collections::delete_rows(tx, &[collection_id]).await?;
    // Last lock request: a full ledger rolls back the deletion, keeping the original headers.
    if !ledger::adjust(tx, config, delta).await? {
        bail!("Pending verification capacity is full; original headers retained.");
    }
    if expired {
        unreferenced.push(path.clone());
    }
    if let Some(row) = &existing
        && row.manifest_path != path
    {
        unreferenced.push(row.manifest_path.clone());
    }
    Ok(unreferenced)
}

pub(super) fn generation_identity(source: &str, id: i64) -> String {
    use sha1::{Digest, Sha1};
    hex::encode(Sha1::digest(format!("{source}:generation:{id}")))
}

struct CandidateWrite<'a> {
    identity: &'a str,
    source: &'a str,
    groups_id: i64,
    path: &'a str,
    size: i64,
    manifest_hash: &'a str,
    upgrades: Option<u64>,
    /// Set when the row gets a new version.
    assessment: Option<inventory::Assessment>,
    last_unique_at: NaiveDateTime,
    expired: bool,
}

/// Inserts or updates the candidate row. Returns its id.
async fn write_candidate(
    tx: &mut MySqlConnection,
    write: &CandidateWrite<'_>,
    existing: Option<&Candidate>,
) -> Result<i64> {
    let now = now();
    let (path, size) = if write.expired {
        ("", 0)
    } else {
        (write.path, write.size)
    };
    let Some(row) = existing else {
        let assessment = write.assessment.expect("a new row has an assessment");
        let (status, last_unique_at) = if write.expired {
            ("expired", write.last_unique_at)
        } else {
            (admitted_status(assessment.status), write.last_unique_at)
        };
        let id = sqlx::query(
            "INSERT INTO release_verification_candidates (identity, source_identity, groups_id, manifest_path, \
             manifest_bytes, manifest_hash, upgrades_release_id, version, last_unique_at, next_attempt_at, status, \
             header_check_pending, reason) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)",
        )
        .bind(write.identity)
        .bind(write.source)
        .bind(write.groups_id)
        .bind(path)
        .bind(size)
        .bind(write.manifest_hash)
        .bind(if write.expired { None } else { write.upgrades })
        .bind(last_unique_at)
        .bind(now)
        .bind(status)
        .bind(assessment.status == HeaderStatus::Rejected)
        .bind(assessment.reason)
        .execute(&mut *tx)
        .await?
        .last_insert_id();
        return Ok(id as i64);
    };
    let mut query =
        QueryBuilder::<MySql>::new("UPDATE release_verification_candidates SET identity = ");
    query.push_bind(write.identity);
    query.push(", source_identity = ").push_bind(write.source);
    query.push(", groups_id = ").push_bind(write.groups_id);
    query.push(", manifest_path = ").push_bind(path);
    query.push(", manifest_bytes = ").push_bind(size);
    query
        .push(", manifest_hash = ")
        .push_bind(write.manifest_hash);
    if let Some(upgrades) = write.upgrades {
        query.push(", upgrades_release_id = ").push_bind(upgrades);
    }
    if let Some(assessment) = write.assessment {
        query.push(", version = ").push_bind(row.version + 1);
        query.push(", next_attempt_at = ").push_bind(now);
        query
            .push(", status = ")
            .push_bind(admitted_status(assessment.status));
        query
            .push(", header_check_pending = ")
            .push_bind(assessment.status == HeaderStatus::Rejected);
        query.push(", lease_token = NULL, lease_until = NULL, reason = ");
        query.push_bind(assessment.reason);
    }
    let new_stamp = write.assessment.is_some() || write.expired;
    if new_stamp {
        query
            .push(", last_unique_at = ")
            .push_bind(write.last_unique_at);
    }
    if write.expired {
        query.push(", status = 'expired', lease_token = NULL, lease_until = NULL, upgrades_release_id = NULL");
    }
    if row.retained_for_release_id.is_some() {
        // A retaining row keeps its expiry stamp in step with last_unique_at.
        if write.expired {
            query.push(", retained_for_release_id = NULL, retained_last_unique_at = NULL");
        } else if new_stamp {
            query
                .push(", retained_last_unique_at = ")
                .push_bind(write.last_unique_at);
        }
    }
    query.push(" WHERE id = ").push_bind(row.id);
    query.build().execute(&mut *tx).await?;
    Ok(row.id)
}
