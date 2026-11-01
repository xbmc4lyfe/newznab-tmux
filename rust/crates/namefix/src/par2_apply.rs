//! `Par2Processor::applyInventoryForRename`: applies a validated, recovery-set isolated PAR2
//! inventory to a release without fetching another article.
//!
//! The release row is locked for the evidence and file writes, which commit together. The
//! rename that may follow runs in the updater's own transaction, which asks the caller's claim
//! again before it commits, so a lost claim never leaves a rename behind. PHP held the evidence
//! and the rename in one transaction; here a claim lost after the evidence committed leaves the
//! evidence, which is idempotent and was written while the claim held.

use std::sync::Arc;

use anyhow::{Result, bail};
use nntmux_categorize::cat;
use nntmux_predb::filename::exact_value;
use sqlx::{MySql, MySqlConnection, QueryBuilder, Row};

use crate::extractors;
use crate::guard::FilenameGuard;
use crate::par2_accumulator::{PacketAccumulator, SetFile};
use crate::par2_budget::{exceeds_payload_budget, set_fits_payload_budget};
use crate::par2_evidence::{
    EvidenceSnapshot, InventoryFile, inventory_evidence_matches, mark_inventory_complete,
    remember_descriptors, remember_hash,
};
use crate::par2_info::{Body, Par2Info};
use crate::par2_support::{Guard, LockedRelease, aborted, absorb_abort};
use crate::release::{Release, Snapshot};
use crate::service::{NameFixer, Par2Outcome};
use crate::update::ReleaseSearch;

/// The identity a caller probed under, and the payload bound it computed for the candidate.
#[derive(Clone, Debug)]
pub struct Expected {
    pub snapshot: Snapshot,
    pub max_payload_bytes: Option<i64>,
}

/// What applying an inventory concluded.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Applied {
    pub outcome: Par2Outcome,
    /// The release was not in an "other" category, and the inventory named files, so its
    /// existing name stands.
    pub existing_name_confirmed: bool,
}

impl Applied {
    fn of(outcome: Par2Outcome) -> Self {
        Self {
            outcome,
            existing_name_confirmed: false,
        }
    }
}

/// `resolveMaxPayloadBytes`.
pub fn resolve_max_payload_bytes(release_size: i64, expected: Option<&Expected>) -> Option<i64> {
    let bound = expected.and_then(|e| e.max_payload_bytes);
    if release_size <= 0 {
        return bound.map(|value| value.max(0));
    }
    Some(match bound {
        Some(value) => release_size.min(value.max(0)),
        None => release_size,
    })
}

/// `setHasCompleteDescriptors`.
fn set_has_complete_descriptors(parser: Option<&Par2Info>, stream_complete: bool) -> bool {
    let Some(parser) = parser else {
        return false;
    };
    if parser.has_unresolved_descriptors() {
        return false;
    }
    stream_complete
        || parser
            .packets
            .iter()
            .any(|packet| matches!(packet.body, Body::Main { .. }))
}

fn inventory_file(file: &SetFile) -> InventoryFile {
    InventoryFile {
        hash: file.hash.clone(),
        hash_16k: file.hash_16k.clone(),
        size_decimal: file
            .size_decimal
            .clone()
            .or_else(|| file.size.map(|size| size.to_string())),
    }
}

/// The identity check under the release lock: the same bytes, the same group, unlinked,
/// unrenamed and unfinished, and the same payload bound as when the inventory was judged.
async fn assert_inventory_snapshot(
    conn: &mut MySqlConnection,
    expected: &LockedRelease,
    guard: &dyn Guard,
    snapshot: Option<&Expected>,
) -> Result<()> {
    if !guard.holds().await {
        return Err(aborted(
            "PAR2 inventory ownership or release snapshot changed.",
        ));
    }
    let current = LockedRelease::lock(conn, expected.id).await?;
    let unchanged = current.is_some_and(|current| {
        current.guid == expected.guid
            && current.name == expected.name
            && current.searchname == expected.searchname
            && current.groups_id == expected.groups_id
            && current.predb_id == 0
            && current.isrenamed == 0
            && current.proc_par2 != 1
            && resolve_max_payload_bytes(current.size, snapshot)
                == resolve_max_payload_bytes(expected.size, snapshot)
    });
    if !unchanged {
        return Err(aborted(
            "PAR2 inventory ownership or release snapshot changed.",
        ));
    }
    Ok(())
}

/// `ReleaseFile::addReleaseFiles` for a name with a 16 KiB hash: the row, its hash, and a flag
/// for whether a search refresh is owed.
async fn add_release_file(
    conn: &mut MySqlConnection,
    release_id: i64,
    name: &[u8],
    size: &str,
    created_at: &str,
    hash16k: &str,
) -> Result<(u64, bool)> {
    let Ok(name) = std::str::from_utf8(name) else {
        bail!("Incorrect string value in a PAR2 file name");
    };
    let duplicate: Option<i64> = sqlx::query_scalar(
        "SELECT 1 FROM release_files WHERE releases_id = ? AND name = ? LIMIT 1",
    )
    .bind(release_id)
    .bind(name)
    .fetch_optional(&mut *conn)
    .await?;
    let mut inserted = 0;
    if duplicate.is_none() {
        inserted = sqlx::query(
            "INSERT IGNORE INTO release_files (releases_id, name, size, created_at, updated_at, passworded, crc32) \
             VALUES (?, ?, ?, ?, ?, 0, '')",
        )
        .bind(release_id)
        .bind(name)
        .bind(size)
        .bind(created_at)
        .bind(chrono::Utc::now().timestamp())
        .execute(&mut *conn)
        .await?
        .rows_affected();
    }
    let valid_hash = hash16k.len() == 32 && hash16k.bytes().all(|b| b.is_ascii_hexdigit());
    if valid_hash {
        let present = duplicate.is_some()
            || inserted > 0
            || sqlx::query_scalar::<_, i64>(
                "SELECT EXISTS(SELECT 1 FROM release_files WHERE releases_id = ? AND name = ?)",
            )
            .bind(release_id)
            .bind(name)
            .fetch_one(&mut *conn)
            .await?
                == 1;
        if present {
            sqlx::query("INSERT IGNORE INTO par_hashes (releases_id, hash) VALUES (?, ?)")
                .bind(release_id)
                .bind(hash16k.to_ascii_lowercase())
                .execute(&mut *conn)
                .await?;
        }
    }
    Ok((inserted, duplicate.is_none()))
}

/// The release as the rename needs it, plus the row's postdate.
async fn load_release(
    conn: &mut MySqlConnection,
    release_id: i64,
    group_id: i64,
    expected: Option<&Expected>,
) -> Result<Option<(Release, Option<String>)>> {
    let mut query = QueryBuilder::<MySql>::new("SELECT ");
    query
        .push(Release::CANDIDATE_COLUMNS)
        .push(", DATE_FORMAT(r.postdate, '%Y-%m-%d %H:%i:%s') AS postdate_text FROM releases r WHERE r.isrenamed = 0 AND r.predb_id = 0 AND r.groups_id = ")
        .push_bind(group_id)
        .push(" AND r.proc_par2 <> 1 AND");
    match expected {
        Some(expected) => {
            let probe = Release {
                id: release_id,
                guid: Some(expected.snapshot.guid.clone()),
                name: expected.snapshot.name.clone(),
                searchname: expected.snapshot.searchname.clone(),
                ..Release::default()
            };
            probe.push_snapshot(&mut query);
        }
        None => {
            query.push(" r.id = ").push_bind(release_id);
        }
    }
    let row = query.build().fetch_optional(conn).await?;
    let Some(row) = row else {
        return Ok(None);
    };
    Ok(Some((
        Release::from_row(&row)?,
        row.try_get("postdate_text")?,
    )))
}

/// `checkName` for a PAR2 file name, with the caller's claim asked inside the rename's own
/// transaction.
async fn check_name(
    fixer: &mut NameFixer,
    release: &Release,
    text: &str,
    claim: &mut FilenameGuard,
) -> Result<bool> {
    const KIND: &str = "PAR2, ";
    if let Some((title, id)) = fixer.updater.check_predb_match(text).await? {
        if exact_value(&title) == exact_value(release.searchname.as_deref().unwrap_or("")) {
            fixer
                .updater
                .attach_predb_snapshot(release, id, None, None, Some(claim))
                .await?;
        } else {
            fixer
                .updater
                .update_release(
                    release,
                    &title,
                    "preDB: Match",
                    true,
                    KIND,
                    true,
                    id,
                    None,
                    Some(claim),
                )
                .await?;
        }
        return Ok(fixer.updater.matched);
    }
    if let Some(result) = extractors::extract_from_file(text) {
        let method = format!("fileCheck: {}", result.method);
        fixer
            .updater
            .update_release(
                release,
                &result.new_name,
                &method,
                true,
                KIND,
                true,
                0,
                None,
                Some(claim),
            )
            .await?;
    }
    Ok(fixer.updater.matched)
}

/// What applying needs from outside the inventory.
pub struct Context<'a> {
    pub fixer: &'a mut NameFixer,
    pub search: &'a Arc<dyn ReleaseSearch>,
    /// `ADD_PAR2`: record the named files as the release's files.
    pub add_par2: bool,
    pub guard: &'a Arc<dyn Guard>,
}

/// `applyInventoryForRename`.
pub async fn apply_inventory(
    cx: Context<'_>,
    inventory: &PacketAccumulator,
    release_id: i64,
    group_id: i64,
    allow_terminal_metadata: bool,
    expected: Option<&Expected>,
) -> Result<Applied> {
    let Context {
        fixer,
        search,
        add_par2,
        guard,
    } = cx;
    let db = fixer.updater.db.clone();
    fixer.updater.reset();
    if !guard.holds().await {
        return Ok(Applied::of(Par2Outcome::Deferred));
    }
    if let Some(expected) = expected
        && expected.snapshot.id != release_id
    {
        return Ok(Applied::of(Par2Outcome::Deferred));
    }
    let mut conn = db.acquire().await?;
    let Some((release, postdate)) = load_release(&mut conn, release_id, group_id, expected).await?
    else {
        return Ok(Applied::of(if expected.is_none() {
            Par2Outcome::NoMatch
        } else {
            Par2Outcome::Deferred
        }));
    };
    drop(conn);
    let max_payload = resolve_max_payload_bytes(release.relsize, expected);
    let stream_complete = allow_terminal_metadata && inventory.is_complete()?;
    let complete = allow_terminal_metadata
        && (stream_complete
            || inventory.has_complete_main_inventory()?
            || max_payload.is_some_and(|max| max <= 0)
            || exceeds_payload_budget(inventory, max_payload)?);

    let mut files: Vec<(Vec<u8>, SetFile)> = Vec::new();
    let mut evidence: Vec<SetFile> = Vec::new();
    let parsers = if max_payload.is_some() {
        inventory.parsers_by_set()?
    } else {
        Vec::new()
    };
    for (set, set_files) in inventory.files_by_set()? {
        if !set_fits_payload_budget(&set_files, max_payload) {
            continue;
        }
        if max_payload.is_some() {
            let parser = parsers
                .iter()
                .find(|(known, _)| *known == set)
                .map(|(_, p)| p);
            if !set_has_complete_descriptors(parser, stream_complete) {
                continue;
            }
        }
        for file in set_files {
            evidence.push(file.clone());
            if let Some(name) = &file.name
                && !files.iter().any(|(known, _)| known == name)
            {
                files.push((name.clone(), file));
            }
        }
    }

    let locked_expected = LockedRelease {
        id: release.id,
        guid: release.guid.clone().unwrap_or_default(),
        name: release.name.clone(),
        searchname: release.searchname.clone(),
        groups_id: release.groups_id,
        predb_id: 0,
        isrenamed: 0,
        proc_par2: 0,
        proc_hash16k: 0,
        size: release.relsize,
        categories_id: i64::from(release.categories_id),
        postdate: postdate.clone(),
    };
    let snapshot = EvidenceSnapshot::unlinked(&locked_expected);
    let guard_ref: &dyn Guard = guard.as_ref();

    let result: Result<bool> = async {
        let mut tx = db.begin().await?;
        assert_inventory_snapshot(&mut tx, &locked_expected, guard_ref, expected).await?;
        if !remember_descriptors(&mut tx, &inventory.descriptors(), &snapshot, Some(guard_ref))
            .await?
        {
            return Err(aborted("PAR2 inventory ownership or release snapshot changed."));
        }
        assert_inventory_snapshot(&mut tx, &locked_expected, guard_ref, expected).await?;
        for file in &evidence {
            let (Some(hash), Some(hash16k)) = (&file.hash, &file.hash_16k) else {
                continue;
            };
            assert_inventory_snapshot(&mut tx, &locked_expected, guard_ref, expected).await?;
            let size = inventory_file(file).size_decimal;
            if !remember_hash(
                &mut tx,
                release.id,
                hash,
                hash16k,
                size.as_deref(),
                &snapshot,
                Some(guard_ref),
            )
            .await?
            {
                return Err(aborted("PAR2 inventory ownership or release snapshot changed."));
            }
            assert_inventory_snapshot(&mut tx, &locked_expected, guard_ref, expected).await?;
        }
        let mut hash_evidence_complete =
            complete && inventory.has_complete_main_inventory()? && !evidence.is_empty();
        if evidence
            .iter()
            .any(|file| file.hash.is_none() || file.hash_16k.is_none())
        {
            hash_evidence_complete = false;
        }
        if hash_evidence_complete {
            let inventory_files: Vec<InventoryFile> = evidence.iter().map(inventory_file).collect();
            hash_evidence_complete = inventory_evidence_matches(
                &mut tx,
                release.id,
                &locked_expected.guid,
                &inventory_files,
            )
            .await?;
        }
        if hash_evidence_complete
            && !mark_inventory_complete(&mut tx, release.id, &snapshot, Some(guard_ref)).await?
        {
            return Err(aborted("PAR2 inventory ownership or release snapshot changed."));
        }
        let mut search_owed = false;
        if add_par2 {
            let created = postdate
                .clone()
                .unwrap_or_else(|| chrono::Utc::now().format("%Y-%m-%d %H:%M:%S").to_string());
            for (name, file) in files.iter().take(21) {
                assert_inventory_snapshot(&mut tx, &locked_expected, guard_ref, expected).await?;
                let size = inventory_file(file).size_decimal.unwrap_or_else(|| "0".into());
                let hash = file.hash_16k.clone().unwrap_or_default();
                let (added, attempted) =
                    add_release_file(&mut tx, release.id, name, &size, &created, &hash).await?;
                search_owed |= attempted;
                assert_inventory_snapshot(&mut tx, &locked_expected, guard_ref, expected).await?;
                if added > 0 {
                    sqlx::query(
                        "UPDATE releases SET rarinnerfilecount = rarinnerfilecount + ? WHERE id = ?",
                    )
                    .bind(added as i64)
                    .bind(release.id)
                    .execute(&mut *tx)
                    .await?;
                }
                assert_inventory_snapshot(&mut tx, &locked_expected, guard_ref, expected).await?;
            }
        } else if complete && !files.is_empty() {
            assert_inventory_snapshot(&mut tx, &locked_expected, guard_ref, expected).await?;
            sqlx::query("UPDATE releases SET rarinnerfilecount = ? WHERE id = ? AND rarinnerfilecount < ?")
                .bind(files.len() as i64)
                .bind(release.id)
                .bind(files.len() as i64)
                .execute(&mut *tx)
                .await?;
        }
        assert_inventory_snapshot(&mut tx, &locked_expected, guard_ref, expected).await?;
        tx.commit().await?;
        if search_owed {
            search.update_release(release.id).await;
        }
        Ok(true)
    }
    .await;
    if !absorb_abort(result)? {
        return Ok(Applied::of(Par2Outcome::Deferred));
    }

    let attempt_rename = cat::OTHERS_GROUP.contains(&release.categories_id);
    if !attempt_rename {
        let confirmed = !files.is_empty();
        return Ok(Applied {
            outcome: finish(&db, &locked_expected, guard_ref, expected, complete).await?,
            existing_name_confirmed: confirmed,
        });
    }
    for (name, _) in &files {
        if !guard.holds().await {
            return Ok(Applied::of(Par2Outcome::Deferred));
        }
        let text = String::from_utf8_lossy(name).into_owned();
        let mut claim = FilenameGuard::claim_only(release.id, guard.clone());
        if check_name(fixer, &release, &text, &mut claim).await? {
            return Ok(Applied::of(Par2Outcome::Renamed));
        }
    }
    Ok(Applied::of(
        finish(&db, &locked_expected, guard_ref, expected, complete).await?,
    ))
}

/// The closing check of PHP's transaction: the release still has the identity the inventory
/// was applied to, and the claim still holds. Otherwise the answer is deferred.
async fn finish(
    db: &sqlx::MySqlPool,
    expected_row: &LockedRelease,
    guard: &dyn Guard,
    expected: Option<&Expected>,
    complete: bool,
) -> Result<Par2Outcome> {
    let mut tx = db.begin().await?;
    let check = assert_inventory_snapshot(&mut tx, expected_row, guard, expected).await;
    tx.rollback().await?;
    match absorb_abort(check.map(|()| true))? {
        true => Ok(if complete {
            Par2Outcome::NoMatch
        } else {
            Par2Outcome::Retryable
        }),
        false => Ok(Par2Outcome::Deferred),
    }
}
