//! Renames releases: decides whether a candidate name may be written, and writes it only while
//! the release still has the identity the decision was made from.

use std::sync::Arc;

use anyhow::{Result, anyhow, bail};
use async_trait::async_trait;
use nntmux_categorize::Categorizer;
use nntmux_predb::filename::{
    OriginalFilenameEvidence, clean_for_matching, exact_value, extract_filename_from_path,
    format_search_name, has_control, is_literal_technical_scene_title, is_plausible_release_title,
    matches_predb_filename_evidence, normalize_candidate_title, strip_file_suffixes,
};
use nntmux_predb::season::{
    episode_identity, has_non_single_episode_evidence, is_ancillary_name, is_episode_name,
    is_season_pack,
};
use serde_json::Value;
use sqlx::{MySql, MySqlConnection, MySqlPool, QueryBuilder, Row};

use crate::guard::FilenameGuard;
use crate::release::{Release, Snapshot};

const TV_LOOKUP_PENDING: i32 = 0;
const MAX_WITNESSES: usize = 256;
const SINGLE_UPDATE_COLUMNS: [&str; 8] = [
    "predb_id",
    "proc_nfo",
    "proc_files",
    "proc_par2",
    "proc_uid",
    "proc_hash16k",
    "proc_srr",
    "proc_crc32",
];

/// What name fixing needs from the search index.
#[async_trait]
pub trait ReleaseSearch: Send + Sync {
    /// Refreshes one release's document. Failures are the backend's to log.
    async fn update_release(&self, release_id: i64);
}

/// A search index that does nothing, for dry runs and tests.
pub struct NoSearch;

#[async_trait]
impl ReleaseSearch for NoSearch {
    async fn update_release(&self, _release_id: i64) {}
}

/// `releases.searchname` and friends only change by one of these paths. `fixerCleaner` is
/// injected because it belongs to release cleaning.
pub type FixerCleaner = fn(&str) -> String;

/// The before and after identity of the last write, once committed and still current.
#[derive(Clone, Debug, PartialEq)]
pub struct CommittedIdentity {
    pub before: Snapshot,
    pub after: Snapshot,
}

pub struct ReleaseUpdater {
    pub db: MySqlPool,
    categorizer: Categorizer,
    search: Arc<dyn ReleaseSearch>,
    fixer_cleaner: FixerCleaner,
    relid: i64,
    pub matched: bool,
    pub done: bool,
    pub fixed: u64,
    pub checked: u64,
    episode_deferred: bool,
    committed: Option<CommittedIdentity>,
}

/// Whether a candidate was accepted, and what to do once it has committed.
#[derive(Default)]
struct Written {
    identity: Option<(Snapshot, Snapshot)>,
    event: Option<NameFixed>,
    /// Clear PAR2 probe progress for this now-terminal identity.
    clear_par2: Option<Snapshot>,
    search: bool,
}

struct NameFixed {
    release_id: i64,
    new_name: String,
    poster: String,
    expected: Option<Snapshot>,
}

impl ReleaseUpdater {
    pub fn new(
        db: MySqlPool,
        categorizer: Categorizer,
        search: Arc<dyn ReleaseSearch>,
        fixer_cleaner: FixerCleaner,
    ) -> Self {
        Self {
            db,
            categorizer,
            search,
            fixer_cleaner,
            relid: 0,
            matched: false,
            done: false,
            fixed: 0,
            checked: 0,
            episode_deferred: false,
            committed: None,
        }
    }

    pub fn reset(&mut self) {
        self.committed = None;
        self.episode_deferred = false;
        self.matched = false;
        self.done = false;
    }

    pub fn reset_archive_naming_deferral(&mut self) {
        self.episode_deferred = false;
    }

    pub fn was_episode_naming_deferred(&self) -> bool {
        self.episode_deferred
    }

    pub fn last_committed_identity(&self) -> Option<&CommittedIdentity> {
        self.committed.as_ref()
    }

    pub fn increment_checked(&mut self) {
        self.checked += 1;
    }

    /// Updates a release with a new name. `write` false only previews; `name_status` marks the
    /// source as processed; a positive `predb_id` means a confirmed catalogue title.
    #[allow(clippy::too_many_arguments)]
    pub async fn update_release(
        &mut self,
        release: &Release,
        name: &str,
        method: &str,
        write: bool,
        kind: &str,
        name_status: bool,
        predb_id: i64,
        filename_evidence: Option<&str>,
        guard: Option<&mut FilenameGuard>,
    ) -> Result<()> {
        self.committed = None;
        self.episode_deferred = false;
        self.matched = false;
        self.done = false;
        let has_snapshot = release.snapshot().is_some();
        if !has_snapshot && release.observed.additional_claim.is_some() {
            return Ok(());
        }
        if predb_id > 0 {
            return self
                .update_confirmed(
                    release,
                    name,
                    method,
                    write,
                    kind,
                    name_status,
                    predb_id,
                    filename_evidence,
                    guard,
                )
                .await;
        }
        if has_snapshot || self.relid != release.id {
            let literal = is_literal_technical_scene_title(name);
            let cleaned = if literal {
                name.to_owned()
            } else {
                (self.fixer_cleaner)(name)
            };
            // Normalize and sanity-check candidates from untrusted sources.
            let normalized = normalize_candidate_title(&cleaned);
            let mut new_name = if literal {
                name.to_owned()
            } else {
                format_search_name(&cleaned, Some(&normalized))
            };
            if !is_trusted_source(kind, method) && !is_plausible_release_title(&normalized) {
                self.done = true;
                return Ok(());
            }
            let current = release.searchname.as_deref().unwrap_or("");
            if !new_name.eq_ignore_ascii_case(current) {
                if renames_season_pack_to_episode(current, &new_name) {
                    self.done = true;
                    return Ok(());
                }
                if kind == "PAR2, " && !literal {
                    new_name = ucwords(&new_name);
                    if let Some(caps) = nntmux_categorize::rx!(i r"(.+?)\.[a-z0-9]{2,3}(PAR2)?$")
                        .captures(name.as_bytes())
                    {
                        new_name = String::from_utf8_lossy(&caps[1]).into_owned();
                    }
                }
                // Strip any path, then edge punctuation.
                let first = new_name.split('\\').next().unwrap_or("").to_owned();
                let trimmed = nntmux_categorize::pcre::replace_all(
                    nntmux_categorize::rx!(r"^[=_.:\s-]+"),
                    &first,
                    "",
                );
                let trimmed = nntmux_categorize::pcre::replace_all(
                    nntmux_categorize::rx!(r"[=_.:\s-]+$"),
                    &trimmed,
                    "",
                );
                let title = if literal {
                    trimmed
                } else {
                    floor_bytes(&trimmed, 299)
                };

                if !write && !self.allows_archive_naming_preview(release, &title).await? {
                    return Ok(());
                }
                if write {
                    let expected = has_snapshot.then(|| release.clone());
                    if !self
                        .perform_update(
                            release,
                            &title,
                            kind,
                            name_status,
                            0,
                            expected.as_ref(),
                            None,
                            guard,
                        )
                        .await?
                    {
                        self.relid = 0;
                        return Ok(());
                    }
                }
                self.matched = true;
                self.relid = release.id;
                if !write {
                    self.fixed += 1;
                }
                tracing::debug!(release = release.id, title, kind, method, "renamed");
            }
        }
        self.done = true;
        Ok(())
    }

    /// Confirmed catalogue titles keep their canonical bytes and need an unchanged snapshot.
    #[allow(clippy::too_many_arguments)]
    async fn update_confirmed(
        &mut self,
        release: &Release,
        title: &str,
        method: &str,
        write: bool,
        kind: &str,
        name_status: bool,
        predb_id: i64,
        evidence: Option<&str>,
        mut guard: Option<&mut FilenameGuard>,
    ) -> Result<()> {
        self.reset();
        if !valid_title(title) || release.snapshot().is_none() {
            return Ok(());
        }
        let mut title = title.to_owned();
        if !write {
            let mut tx = self.db.begin().await?;
            let accepted = match lock_identity(
                &mut tx,
                release,
                predb_id,
                &title,
                evidence,
                guard.as_deref_mut(),
            )
            .await?
            {
                None => false,
                Some(canonical) => match lock_snapshot(&mut tx, release).await? {
                    Some(current)
                        if !renames_season_pack_to_episode(
                            current.searchname.as_deref().unwrap_or(""),
                            &canonical,
                        ) && allows_archive_naming_locked(
                            &mut tx, &current, &canonical, evidence,
                        )
                        .await? =>
                    {
                        title = canonical;
                        true
                    }
                    _ => false,
                },
            };
            tx.rollback().await?;
            if !accepted {
                return Ok(());
            }
        }
        if exact_value(&title) == exact_value(release.searchname.as_deref().unwrap_or("")) {
            if write {
                self.attach_predb_snapshot(release, predb_id, Some(&title), evidence, guard)
                    .await?;
            } else {
                self.matched = true;
                self.done = true;
            }
            return Ok(());
        }
        if write
            && !self
                .perform_update(
                    release,
                    &title,
                    kind,
                    name_status,
                    predb_id,
                    Some(release),
                    evidence,
                    guard,
                )
                .await?
        {
            return Ok(());
        }
        self.relid = release.id;
        self.matched = true;
        self.done = true;
        if !write {
            self.fixed += 1;
        }
        tracing::debug!(release = release.id, title, kind, method, "renamed");
        Ok(())
    }

    /// Applies fulltext evidence only while the release keeps its confirmed identity and no link.
    pub async fn update_predb_candidate(
        &mut self,
        release: &Release,
        title: &str,
        predb_id: i64,
        source: &str,
    ) -> Result<bool> {
        self.reset();
        if release.id <= 0
            || predb_id <= 0
            || release.guid.is_none()
            || title.trim().is_empty()
            || title.chars().count() > 255
            || title.chars().any(char::is_control)
        {
            return Ok(false);
        }
        if exact_value(title) == exact_value(release.searchname.as_deref().unwrap_or("")) {
            let mut tx = self.db.begin().await?;
            if lock_snapshot(&mut tx, release).await?.is_none()
                || lock_canonical_title(&mut tx, predb_id, title, None)
                    .await?
                    .is_none()
            {
                return Ok(false);
            }
            let Some(before) = lock_unlinked(&mut tx, release).await? else {
                return Ok(false);
            };
            if !allows_archive_naming_locked(&mut tx, &before, title, None).await? {
                return Ok(false);
            }
            let mut update = QueryBuilder::<MySql>::new("UPDATE releases SET predb_id = ");
            update.push_bind(predb_id).push(" WHERE predb_id = 0 AND");
            release.push_snapshot(&mut update);
            if update.build().execute(&mut *tx).await?.rows_affected() != 1 {
                return Ok(false);
            }
            let after = lock_snapshot(&mut tx, release).await?;
            if lock_canonical_title(&mut tx, predb_id, title, None)
                .await?
                .is_none()
            {
                return Ok(false);
            }
            tx.commit().await?;
            self.after_commit(
                release.id,
                Written {
                    identity: identity_pair(&before, after.as_ref()),
                    clear_par2: release.snapshot(),
                    ..Written::default()
                },
            )
            .await?;
        } else {
            if !self
                .perform_update(
                    release,
                    title,
                    "PreDB FT Exact, ",
                    true,
                    predb_id,
                    Some(release),
                    None,
                    None,
                )
                .await?
            {
                return Ok(false);
            }
            tracing::debug!(
                release = release.id,
                title,
                source,
                "renamed by PreDB title"
            );
        }
        self.relid = release.id;
        self.matched = true;
        self.done = true;
        Ok(true)
    }

    /// Renames from media evidence and categorizes in one write, for additional processing.
    /// `guard` runs inside the transaction, so it can check the caller's claim.
    pub async fn update_media_snapshot(
        &mut self,
        release: &Release,
        title: &str,
        category_id: i32,
    ) -> Result<bool> {
        self.committed = None;
        self.matched = false;
        self.done = false;
        let Some(snapshot) = release.snapshot() else {
            return Ok(false);
        };
        if snapshot.id <= 0 || snapshot.guid.is_empty() || category_id <= 0 || !valid_title(title) {
            return Ok(false);
        }
        let mut tx = self.db.begin().await?;
        let Some(before) = lock_unlinked(&mut tx, release).await? else {
            return Ok(false);
        };
        if !allows_archive_naming_locked(&mut tx, &before, title, None).await? {
            return Ok(false);
        }
        let mut update = QueryBuilder::<MySql>::new("UPDATE releases SET searchname = ");
        update
            .push_bind(title.to_owned())
            .push(", categories_id = ")
            .push_bind(category_id)
            .push(", isrenamed = 1, iscategorized = 1, proc_pp = 1 WHERE predb_id = 0 AND");
        release.push_snapshot(&mut update);
        update.build().execute(&mut *tx).await?;
        #[allow(clippy::type_complexity)]
        let after: Option<(String, Option<String>, Option<String>, i64, i32, i32, i32, i32)> = sqlx::query_as(
            "SELECT guid, name, searchname, CAST(predb_id AS SIGNED), categories_id, isrenamed, iscategorized, proc_pp \
             FROM releases WHERE id = ? FOR UPDATE",
        )
        .bind(snapshot.id)
        .fetch_optional(&mut *tx)
        .await?;
        let Some((guid, name, searchname, predb_id, category, renamed, categorized, pp)) = after
        else {
            return Ok(false);
        };
        if guid != snapshot.guid
            || name != snapshot.name
            || predb_id != 0
            || searchname.as_deref() != Some(title)
            || category != category_id
            || renamed != 1
            || categorized != 1
            || pp != 1
        {
            return Ok(false);
        }
        tx.commit().await?;
        let after = Snapshot {
            id: snapshot.id,
            guid,
            name,
            searchname,
        };
        self.after_commit(
            release.id,
            Written {
                identity: Some((snapshot, after.clone())),
                clear_par2: Some(after),
                search: true,
                ..Written::default()
            },
        )
        .await?;
        self.matched = true;
        self.done = true;
        self.relid = release.id;
        Ok(true)
    }

    /// The actual write, inside PreDB and filename authority when either applies.
    #[allow(clippy::too_many_arguments)]
    async fn perform_update(
        &mut self,
        release: &Release,
        title: &str,
        kind: &str,
        name_status: bool,
        predb_id: i64,
        expected: Option<&Release>,
        evidence: Option<&str>,
        mut guard: Option<&mut FilenameGuard>,
    ) -> Result<bool> {
        let authority = expected.unwrap_or(release);
        let mut tx = self.db.begin().await?;
        let mut canonical = title.to_owned();
        if predb_id > 0 {
            match lock_identity(
                &mut tx,
                authority,
                predb_id,
                title,
                evidence,
                guard.as_deref_mut(),
            )
            .await?
            {
                Some(value) => canonical = value,
                None => return Ok(false),
            }
        } else if let Some(guard) = guard.as_deref_mut()
            && (lock_snapshot(&mut tx, authority).await?.is_none()
                || !guard.accepted(&mut tx).await?)
        {
            return Ok(false);
        }

        let actual: Option<Release> = sqlx::query(
            "SELECT id AS releases_id, guid, name, searchname, fromname, groups_id, categories_id, predb_id, isrenamed \
             FROM releases WHERE id = ? FOR UPDATE",
        )
        .bind(release.id)
        .fetch_optional(&mut *tx)
        .await?
        .map(|row| Release::from_row(&row))
        .transpose()?;
        let Some(actual) = actual else {
            return Ok(false);
        };
        if predb_id > 0
            && renames_season_pack_to_episode(
                actual.searchname.as_deref().unwrap_or(""),
                &canonical,
            )
        {
            return Ok(false);
        }
        if !allows_archive_naming_locked(&mut tx, &actual, &canonical, evidence).await? {
            // Only an otherwise current canonical filename attempt may be retried after
            // finalization supplies its missing archive proof.
            if predb_id > 0
                && evidence.is_some()
                && is_episode_name(&canonical)
                && let Some(expected) = expected
            {
                self.episode_deferred = lock_unlinked(&mut tx, expected).await?.is_some();
            }
            return Ok(false);
        }
        let mut completed = actual.snapshot();
        if let Some(completed) = &mut completed {
            completed.searchname = Some(canonical.clone());
        }

        let mut update =
            QueryBuilder::<MySql>::new("UPDATE releases SET videos_id = 0, tv_episodes_id = ");
        update
            .push_bind(TV_LOOKUP_PENDING)
            .push(", tv_lookup_attempts = 0, tv_lookup_retry_at = NULL, imdbid = NULL, book_lookup_attempts = 0, \
                   book_lookup_retry_at = NULL, book_name_normalized_at = NULL, predb_id = ")
            .push_bind(predb_id)
            .push(", searchname = ")
            .push_bind(canonical.clone());
        let status = status_columns(kind);
        if name_status {
            // Non-strict SQL mode stores these empty strings as 0, which lookups read as "not
            // looked up yet", where the other path below stores NULL.
            update.push(", musicinfo_id = '', consoleinfo_id = '', bookinfo_id = '', anidbid = ''");
            for column in status {
                update.push(", ").push(*column).push(" = 1");
            }
        } else {
            update.push(", musicinfo_id = NULL, consoleinfo_id = NULL, bookinfo_id = NULL, anidbid = NULL, iscategorized = 1");
        }
        update.push(" WHERE");
        match expected {
            Some(expected) => {
                update.push(" predb_id = 0 AND");
                expected.push_snapshot(&mut update);
            }
            None => {
                update.push(" id = ").push_bind(release.id);
            }
        }
        let affected = update.build().execute(&mut *tx).await?.rows_affected();
        if expected.is_some() && affected != 1 {
            return Ok(false);
        }

        let after: Option<Release> = sqlx::query(
            "SELECT id AS releases_id, guid, name, searchname FROM releases WHERE id = ? FOR UPDATE",
        )
        .bind(release.id)
        .fetch_optional(&mut *tx)
        .await?
        .map(|row| Release::from_row(&row))
        .transpose()?;
        let identity = after
            .as_ref()
            .filter(|after| after.searchname.as_deref() == Some(canonical.as_str()))
            .and_then(|after| identity_pair(&actual, Some(&after.clone())));

        // Lost authority rolls the whole write back.
        if predb_id > 0
            && lock_canonical_title(&mut tx, predb_id, title, evidence)
                .await?
                .as_deref()
                != Some(canonical.as_str())
        {
            self.committed = None;
            return Ok(false);
        }
        if let Some(guard) = guard
            && !guard.accepted(&mut tx).await?
        {
            self.committed = None;
            return Ok(false);
        }
        tx.commit().await?;

        let renamed_by_status = name_status && status.contains(&"isrenamed");
        let event = NameFixed {
            release_id: release.id,
            new_name: canonical,
            poster: release.fromname.clone().unwrap_or_default(),
            expected: completed.clone(),
        };
        self.after_commit(
            release.id,
            Written {
                identity,
                event: Some(event),
                clear_par2: (predb_id > 0 || renamed_by_status)
                    .then_some(completed)
                    .flatten(),
                search: true,
            },
        )
        .await?;
        self.fixed += 1;
        Ok(true)
    }

    async fn after_commit(&mut self, release_id: i64, written: Written) -> Result<()> {
        if let Some((before, after)) = written.identity
            && committed_snapshot_matches(&self.db, &after).await?
        {
            self.committed = Some(CommittedIdentity { before, after });
        }
        if let Some(snapshot) = written.clear_par2 {
            crate::par2_progress::forget_completed_snapshot(&self.db, &snapshot).await?;
        }
        if let Some(event) = written.event {
            self.recategorize(&event).await?;
        }
        if written.search {
            self.search.update_release(release_id).await;
        }
        Ok(())
    }

    /// `RecategorizeReleaseAfterNameFix`: only while the release still has the fixed name.
    async fn recategorize(&self, event: &NameFixed) -> Result<()> {
        if let Some(expected) = &event.expected
            && (expected.id != event.release_id
                || expected.searchname.as_deref() != Some(event.new_name.as_str()))
        {
            return Ok(());
        }
        let row = sqlx::query(
            "SELECT guid, name, searchname, CAST(groups_id AS SIGNED) AS groups_id, fromname, categories_id, iscategorized \
             FROM releases WHERE id = ? AND HEX(searchname) = HEX(?)",
        )
        .bind(event.release_id)
        .bind(&event.new_name)
        .fetch_optional(&self.db)
        .await?;
        let Some(row) = row else {
            return Ok(());
        };
        let guid: String = row.try_get("guid")?;
        if let Some(expected) = &event.expected {
            let name: Option<String> = row.try_get("name")?;
            if guid != expected.guid || name != expected.name {
                return Ok(());
            }
        }
        let group: i64 = row.try_get("groups_id")?;
        let fromname: Option<String> = row.try_get("fromname")?;
        let poster = fromname.unwrap_or_else(|| event.poster.clone());
        let decision = self
            .categorizer
            .determine(&self.db, group, &event.new_name, &poster)
            .await?;
        let current: i32 = row.try_get("categories_id")?;
        let categorized: i32 = row.try_get("iscategorized")?;
        if current == decision.category && categorized == 1 {
            return Ok(());
        }
        sqlx::query(
            "UPDATE releases SET categories_id = ?, iscategorized = 1 WHERE id = ? AND HEX(guid) = HEX(?) AND HEX(searchname) = HEX(?)",
        )
        .bind(decision.category)
        .bind(event.release_id)
        .bind(&guid)
        .bind(&event.new_name)
        .execute(&self.db)
        .await?;
        Ok(())
    }

    /// Updates one status column unconditionally. Prefer the snapshot form.
    pub async fn update_single_column(&self, column: &str, status: i64, id: i64) -> Result<()> {
        if !SINGLE_UPDATE_COLUMNS.contains(&column) {
            bail!("Unsupported release status column [{column}].");
        }
        if id == 0 {
            return Ok(());
        }
        let sql = format!("UPDATE releases SET {column} = ? WHERE id = ?");
        sqlx::query(sqlx::AssertSqlSafe(sql))
            .bind(status)
            .bind(id)
            .execute(&self.db)
            .await?;
        if (column == "proc_par2" && status == 1) || (column == "predb_id" && status > 0) {
            let row: Option<(String, Option<String>, Option<String>)> =
                sqlx::query_as("SELECT guid, name, searchname FROM releases WHERE id = ?")
                    .bind(id)
                    .fetch_optional(&self.db)
                    .await?;
            if let Some((guid, name, searchname)) = row {
                crate::par2_progress::forget_completed_snapshot(
                    &self.db,
                    &Snapshot {
                        id,
                        guid,
                        name,
                        searchname,
                    },
                )
                .await?;
            }
        }
        Ok(())
    }

    /// Updates a status only while the captured release stays unchanged and unlinked.
    pub async fn update_snapshot_column(
        &self,
        release: &Release,
        column: &str,
        status: i64,
    ) -> Result<bool> {
        self.update_snapshot_status(&self.db, release, column, status, 0)
            .await
    }

    /// Completes filename inspection only while the identity and PreDB link stay unchanged, and
    /// only while the filename evidence behind the decision still holds.
    pub async fn complete_filename_snapshot(
        &self,
        release: &Release,
        guard: &mut FilenameGuard,
    ) -> Result<bool> {
        if release.predb_id < 0 {
            return Ok(false);
        }
        for attempt in 0..3 {
            match self.try_complete_filename(release, guard).await {
                Err(error) if attempt < 2 && is_concurrency_error(&error) => {}
                other => return other,
            }
        }
        Ok(false)
    }

    async fn try_complete_filename(
        &self,
        release: &Release,
        guard: &mut FilenameGuard,
    ) -> Result<bool> {
        let mut tx = self.db.begin().await?;
        if lock_snapshot(&mut tx, release).await?.is_none() || !guard.accepted(&mut tx).await? {
            return Ok(false);
        }
        let done = self
            .update_snapshot_status(&mut *tx, release, "proc_files", 1, release.predb_id)
            .await?;
        if done && !guard.accepted(&mut tx).await? {
            return Ok(false);
        }
        tx.commit().await?;
        Ok(done)
    }

    async fn update_snapshot_status<'e>(
        &self,
        executor: impl sqlx::MySqlExecutor<'e>,
        release: &Release,
        column: &str,
        status: i64,
        expected_predb: i64,
    ) -> Result<bool> {
        if !SINGLE_UPDATE_COLUMNS.contains(&column) || column == "predb_id" {
            bail!("Unsupported release status column [{column}].");
        }
        let Some(snapshot) = release.snapshot() else {
            return Ok(false);
        };
        if snapshot.id <= 0 || snapshot.guid.is_empty() {
            return Ok(false);
        }
        let mut update = QueryBuilder::<MySql>::new("UPDATE releases SET ");
        update
            .push(column)
            .push(" = ")
            .push_bind(status)
            .push(" WHERE predb_id = ")
            .push_bind(expected_predb)
            .push(" AND");
        release.push_snapshot(&mut update);
        let updated = update.build().execute(executor).await?.rows_affected() > 0;
        Ok(updated)
    }

    /// Attaches same-name evidence only while the release identity is unchanged. An existing
    /// identical link counts as a match without another write.
    pub async fn attach_predb_snapshot(
        &mut self,
        release: &Release,
        predb_id: i64,
        expected_title: Option<&str>,
        evidence: Option<&str>,
        mut guard: Option<&mut FilenameGuard>,
    ) -> Result<bool> {
        self.reset();
        let Some(searchname) = release.searchname.as_deref() else {
            return Ok(false);
        };
        if release.id <= 0
            || predb_id <= 0
            || release.guid.is_none()
            || expected_title.is_some_and(|title| exact_value(title) != exact_value(searchname))
        {
            return Ok(false);
        }
        let title = expected_title.unwrap_or(searchname).to_owned();
        let mut tx = self.db.begin().await?;
        let Some(canonical) = lock_identity(
            &mut tx,
            release,
            predb_id,
            &title,
            evidence,
            guard.as_deref_mut(),
        )
        .await?
        else {
            return Ok(false);
        };
        let Some(before) = lock_snapshot(&mut tx, release).await? else {
            return Ok(false);
        };
        if !allows_archive_naming_locked(&mut tx, &before, &canonical, evidence).await? {
            self.episode_deferred = evidence.is_some() && is_episode_name(&canonical);
            return Ok(false);
        }
        let linked = |value: i64| value == predb_id;
        let mut attached = false;
        if !linked(before.predb_id) {
            let mut update = QueryBuilder::<MySql>::new("UPDATE releases SET predb_id = ");
            update.push_bind(predb_id).push(" WHERE predb_id = 0 AND");
            release.push_snapshot(&mut update);
            attached = update.build().execute(&mut *tx).await?.rows_affected() == 1;
        }
        let after = lock_snapshot(&mut tx, release).await?;
        if !after.as_ref().is_some_and(|after| linked(after.predb_id)) {
            return Ok(false);
        }
        if lock_canonical_title(&mut tx, predb_id, &title, evidence)
            .await?
            .as_deref()
            != Some(canonical.as_str())
        {
            return Ok(false);
        }
        if let Some(guard) = guard
            && !guard.accepted(&mut tx).await?
        {
            return Ok(false);
        }
        tx.commit().await?;
        self.after_commit(
            release.id,
            Written {
                identity: identity_pair(&before, after.as_ref()),
                clear_par2: attached.then(|| release.snapshot()).flatten(),
                ..Written::default()
            },
        )
        .await?;
        self.relid = release.id;
        self.matched = true;
        self.done = true;
        Ok(true)
    }

    /// Read-only preview evidence. The guarded write validates it again.
    pub async fn current_predb_title(
        &self,
        predb_id: i64,
        title: &str,
        evidence: Option<&str>,
    ) -> Result<Option<String>> {
        let mut conn = self.db.acquire().await?;
        canonical_title(&mut conn, predb_id, title, evidence, false).await
    }

    async fn allows_archive_naming_preview(&self, release: &Release, title: &str) -> Result<bool> {
        if !is_episode_name(title) {
            return Ok(true);
        }
        let mut tx = self.db.begin().await?;
        let allowed = match lock_snapshot(&mut tx, release).await? {
            Some(current) => allows_archive_naming_locked(&mut tx, &current, title, None).await?,
            None => false,
        };
        tx.rollback().await?;
        Ok(allowed)
    }

    /// The first PreDB title found literally in text, unless the text is a "Source :" block.
    pub async fn check_predb_match(&self, text: &str) -> Result<Option<(String, i64)>> {
        if nntmux_categorize::mi!(text, r"Source\s:") {
            return Ok(None);
        }
        let mut candidates: Vec<String> = Vec::new();
        for title in predb_regex_hits(text) {
            let title = title.trim().to_owned();
            if !title.is_empty() && !candidates.contains(&title) {
                candidates.push(title);
            }
        }
        if candidates.is_empty() {
            return Ok(None);
        }
        let mut query = QueryBuilder::<MySql>::new(
            "SELECT CAST(id AS SIGNED), title FROM predb WHERE title IN (",
        );
        let mut separated = query.separated(", ");
        for candidate in &candidates {
            separated.push_bind(candidate.clone());
        }
        separated.push_unseparated(")");
        let rows: Vec<(i64, String)> = query.build_query_as().fetch_all(&self.db).await?;
        for candidate in &candidates {
            let identity = exact_value(candidate);
            // The last row with an identity wins, as PHP's keyed array does.
            if let Some((id, title)) = rows
                .iter()
                .rev()
                .find(|(_, title)| exact_value(title) == identity)
            {
                return Ok(Some((title.clone(), *id)));
            }
        }
        Ok(None)
    }
}

fn is_concurrency_error(error: &anyhow::Error) -> bool {
    error
        .downcast_ref::<sqlx::Error>()
        .and_then(sqlx::Error::as_database_error)
        .and_then(|db| {
            db.code()
                .map(|code| matches!(code.as_ref(), "40001" | "HY000" | "1213" | "1205"))
        })
        .unwrap_or(false)
}

/// `PREDB_REGEX` hits over the whole text.
pub fn predb_regex_hits(text: &str) -> Vec<String> {
    predb_regex()
        .find_iter(text)
        .map(|hit| hit.as_str().to_owned())
        .collect()
}

/// A scene release name: `/([\w.'()\[\]-]+(?:[\s._-]+[\w.'()\[\]-]+)+[-.][\w]+)/ui`.
pub fn predb_regex() -> &'static regex::Regex {
    static RE: std::sync::LazyLock<regex::Regex> = std::sync::LazyLock::new(|| {
        regex::Regex::new(r"(?i)([\w.'()\[\]-]+(?:[\s._-]+[\w.'()\[\]-]+)+[-.][\w]+)")
            .expect("static pattern")
    });
    &RE
}

fn identity_pair(before: &Release, after: Option<&Release>) -> Option<(Snapshot, Snapshot)> {
    let before = before.snapshot()?;
    let after = after?.snapshot()?;
    (!before.guid.is_empty()
        && before.id == after.id
        && before.guid == after.guid
        && before.name == after.name)
        .then_some((before, after))
}

async fn committed_snapshot_matches(db: &MySqlPool, snapshot: &Snapshot) -> Result<bool> {
    let row: Option<(String, Option<String>, Option<String>)> =
        sqlx::query_as("SELECT guid, name, searchname FROM releases WHERE id = ?")
            .bind(snapshot.id)
            .fetch_optional(db)
            .await?;
    Ok(row.is_some_and(|(guid, name, searchname)| {
        guid == snapshot.guid && name == snapshot.name && searchname == snapshot.searchname
    }))
}

fn valid_title(title: &str) -> bool {
    !title.trim().is_empty() && title.chars().count() <= 255 && !has_control(title)
}

fn status_columns(kind: &str) -> &'static [&'static str] {
    match kind {
        "NFO, " => &["isrenamed", "iscategorized", "proc_nfo"],
        "PAR2, " => &["isrenamed", "iscategorized", "proc_par2"],
        "Filenames, " | "file matched source: " => &["isrenamed", "iscategorized", "proc_files"],
        "PreDB FT Exact, " | "sorter, " => &["isrenamed", "iscategorized"],
        "UID, " | "Mediainfo, " => &["isrenamed", "iscategorized", "proc_uid"],
        "PAR2 hash, " => &["isrenamed", "iscategorized", "proc_hash16k"],
        "SRR, " => &["isrenamed", "iscategorized", "proc_srr"],
        "CRC32, " => &["isrenamed", "iscategorized", "proc_crc32"],
        _ => &[],
    }
}

/// Trusted sources bypass the plausibility check.
fn is_trusted_source(kind: &str, method: &str) -> bool {
    let method = method.to_ascii_lowercase();
    kind.starts_with("PreDB")
        || kind.starts_with("PreDb")
        || matches!(kind, "UID, " | "PAR2 hash, " | "CRC32, " | "SRR, ")
        || method.contains("title match")
        || method.contains("file matched source")
        || method.contains("predb")
}

/// A release named as a whole season is never renamed to a single episode of it.
fn renames_season_pack_to_episode(current: &str, new_name: &str) -> bool {
    is_season_pack(current) && is_episode_name(new_name) && !is_season_pack(new_name)
}

/// PHP `substr`, without splitting a character.
fn floor_bytes(value: &str, limit: usize) -> String {
    if value.len() <= limit {
        return value.to_owned();
    }
    let mut end = limit;
    while !value.is_char_boundary(end) {
        end -= 1;
    }
    value[..end].to_owned()
}

/// PHP `ucwords`: ASCII letters after whitespace.
fn ucwords(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    let mut start = true;
    for c in value.chars() {
        out.push(if start { c.to_ascii_uppercase() } else { c });
        start = matches!(c, ' ' | '\t' | '\r' | '\n' | '\x0C' | '\x0B');
    }
    out
}

/// The current row, under a lock, while it still has the observed identity.
pub async fn lock_snapshot(
    conn: &mut MySqlConnection,
    release: &Release,
) -> Result<Option<Release>> {
    select_locked(conn, release, "").await
}

async fn lock_unlinked(conn: &mut MySqlConnection, release: &Release) -> Result<Option<Release>> {
    select_locked(conn, release, " predb_id = 0 AND").await
}

async fn select_locked(
    conn: &mut MySqlConnection,
    release: &Release,
    prefix: &str,
) -> Result<Option<Release>> {
    let mut query = QueryBuilder::<MySql>::new(
        "SELECT id AS releases_id, guid, name, searchname, fromname, groups_id, categories_id, predb_id, isrenamed FROM releases WHERE",
    );
    query.push(prefix);
    release.push_snapshot(&mut query);
    query.push(" LIMIT 1 FOR UPDATE");
    let row = query.build().fetch_optional(conn).await?;
    Ok(row.map(|row| Release::from_row(&row)).transpose()?)
}

/// Search documents and donor snapshots are discovery evidence, not the current canonical row.
/// Holds the row lock through the release write, whose caller checks again before committing.
async fn lock_identity(
    conn: &mut MySqlConnection,
    release: &Release,
    predb_id: i64,
    title: &str,
    evidence: Option<&str>,
    guard: Option<&mut FilenameGuard>,
) -> Result<Option<String>> {
    if lock_snapshot(conn, release).await?.is_none() {
        return Ok(None);
    }
    if let Some(guard) = guard
        && !guard.accepted(conn).await?
    {
        return Ok(None);
    }
    lock_canonical_title(conn, predb_id, title, evidence).await
}

async fn lock_canonical_title(
    conn: &mut MySqlConnection,
    predb_id: i64,
    title: &str,
    evidence: Option<&str>,
) -> Result<Option<String>> {
    canonical_title(conn, predb_id, title, evidence, true).await
}

async fn canonical_title(
    conn: &mut MySqlConnection,
    predb_id: i64,
    title: &str,
    evidence: Option<&str>,
    lock: bool,
) -> Result<Option<String>> {
    if predb_id <= 0 || !valid_title(title) {
        return Ok(None);
    }
    let sql = if lock {
        "SELECT title, filename FROM predb WHERE id = ? FOR UPDATE"
    } else {
        "SELECT title, filename FROM predb WHERE id = ?"
    };
    let row: Option<(String, String)> = sqlx::query_as(sql)
        .bind(predb_id)
        .fetch_optional(&mut *conn)
        .await?;
    let Some((current, filename)) = row else {
        return Ok(None);
    };
    if current.chars().count() > 255
        || has_control(&current)
        || exact_value(&current) != exact_value(title)
    {
        return Ok(None);
    }
    match evidence {
        None => Ok(Some(current)),
        Some(evidence) => Ok(OriginalFilenameEvidence::new(evidence)
            .accepts(&current, &filename)
            .then_some(current)),
    }
}

/// `ReleaseArchiveNamingEvidence::allowsEpisodeLocked`: an episode name needs a current complete
/// inventory that holds only that episode. Also applies to same-name attachments and previews.
pub async fn allows_archive_naming_locked(
    conn: &mut MySqlConnection,
    release: &Release,
    candidate: &str,
    evidence: Option<&str>,
) -> Result<bool> {
    if !is_episode_name(candidate) {
        return Ok(true);
    }
    let Some(candidate_identity) = episode_identity(candidate) else {
        return Ok(false);
    };
    let guid = release
        .guid
        .as_deref()
        .ok_or_else(|| anyhow!("release has no guid"))?;
    // The release lock serializes both observations and generation changes.
    let actual: Option<u64> =
        sqlx::query_scalar("SELECT id FROM releases WHERE id = ? AND guid = ? FOR UPDATE")
            .bind(release.id)
            .bind(guid)
            .fetch_optional(&mut *conn)
            .await?;
    if actual.is_none() {
        return Ok(false);
    }
    let row: Option<(String, i64, String, String, String)> = sqlx::query_as(
        "SELECT release_guid, CAST(proof_version AS SIGNED), scope, source_sha256, episode_files \
         FROM release_archive_naming_evidence WHERE releases_id = ? FOR UPDATE",
    )
    .bind(release.id)
    .fetch_optional(&mut *conn)
    .await?;
    let Some((release_guid, version, scope, source, files)) = row else {
        let names: Vec<String> = sqlx::query_scalar(
            "SELECT name FROM release_files WHERE releases_id = ? ORDER BY name LIMIT ? LOCK IN SHARE MODE",
        )
        .bind(release.id)
        .bind((MAX_WITNESSES + 1) as i64)
        .fetch_all(&mut *conn)
        .await?;
        // Historical singleton listings can't be certified retroactively. Keep legacy
        // compatibility, but reject positively known packs.
        return Ok(names.len() <= MAX_WITNESSES && !has_non_single_episode_evidence(&names));
    };
    if release_guid != guid
        || version != 1
        || !matches!(
            scope.as_str(),
            "unknown" | "incomplete" | "complete_single" | "multi_episode"
        )
    {
        bail!("Malformed archive naming evidence.");
    }
    let names = decode_witnesses(&files)?;
    if scope != "complete_single" || has_non_single_episode_evidence(&names) {
        return Ok(false);
    }
    let digest: Option<String> = sqlx::query_scalar(
        "SELECT source_digest FROM release_nzb_contents WHERE guid = ? LOCK IN SHARE MODE",
    )
    .bind(guid)
    .fetch_optional(&mut *conn)
    .await?;
    if digest.as_deref() != Some(source.as_str()) {
        return Ok(false);
    }
    let query = evidence.and_then(|evidence| clean_for_matching(evidence, true));
    let mut resolved_opaque = false;
    for name in &names {
        match episode_identity(name) {
            Some(identity) if identity != candidate_identity => return Ok(false),
            Some(_) => {}
            None if is_ancillary_name(name) => {}
            None => {
                // A canonical filename mapping resolves one opaque payload, whatever its
                // extension, but not an unclassified sibling.
                let matches = query
                    .as_deref()
                    .is_some_and(|query| matches_predb_filename_evidence(name, query));
                if resolved_opaque || !matches {
                    return Ok(false);
                }
                resolved_opaque = true;
            }
        }
    }
    for name in &names {
        if evidence.is_some() {
            if query
                .as_deref()
                .is_some_and(|query| matches_predb_filename_evidence(name, query))
            {
                return Ok(true);
            }
        } else {
            let title = strip_file_suffixes(extract_filename_from_path(name));
            if exact_value(&normalize_candidate_title(&title))
                == exact_value(&normalize_candidate_title(candidate))
            {
                return Ok(true);
            }
        }
    }
    Ok(false)
}

fn decode_witnesses(json: &str) -> Result<Vec<String>> {
    let parsed: Value =
        serde_json::from_str(json).map_err(|_| anyhow!("Malformed archive filename witnesses."))?;
    let list = parsed
        .as_array()
        .ok_or_else(|| anyhow!("Malformed archive filename witnesses."))?;
    let mut names = Vec::with_capacity(list.len());
    let mut bytes = 0;
    for value in list {
        let name = value
            .as_str()
            .ok_or_else(|| anyhow!("Malformed archive filename witness."))?;
        bytes += name.len();
        if name.is_empty()
            || name.len() > 4096
            || names.len() >= MAX_WITNESSES
            || bytes > 32768
            || names.iter().any(|n: &String| n == name)
        {
            bail!("Unbounded archive filename witnesses.");
        }
        names.push(name.to_owned());
    }
    Ok(names)
}
