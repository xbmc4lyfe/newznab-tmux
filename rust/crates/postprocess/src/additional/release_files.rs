//! `ReleaseFileManager`, first half: queueing the files an archive lists, naming the release from
//! them, and the guarded finalization that writes the inventory and statuses back. PAR2 and NFO
//! handling and naming from RAR contents follow in the next port.
//!
//! PHP's producer-naming claim was always absent (see [`super::context`]), so a rename here is
//! accepted exactly when name fixing committed one from the identity this attempt holds. PHP also
//! deferred acceptance to after an outer commit when one was open; nothing here renames inside an
//! outer transaction, so acceptance is immediate.
//!
//! File names are bytes in an archive and text in the database. A name that isn't UTF-8 is queued
//! with replacement characters, where MySQL's `INSERT IGNORE` would have cut it at the first bad
//! byte.

use std::collections::HashSet;
use std::path::{Path, PathBuf};

use anyhow::{Result, anyhow, bail};
use chrono::{DateTime, NaiveDateTime, TimeDelta, Utc};
use nntmux_core::pcre::Pcre;
use nntmux_namefix::release::{AdditionalClaim, Release};
use nntmux_namefix::service::NameFixer;
use nntmux_namefix::update::CommittedIdentity;
use nntmux_predb::season::{
    has_distinct_episode_identities, is_episode_name, is_multi_episode_set,
};
use sqlx::mysql::MySqlRow;
use sqlx::{MySql, MySqlConnection, MySqlPool, QueryBuilder, Row};

use super::archive_naming::{Observation, Scope, record_locked};
use super::context::{PendingReleaseFile, ReleaseContext};
use super::search_sync::SearchSync;
use crate::archive_info::ListEntry;

/// Inner files stored per release, unless it is a season pack.
pub const DEFAULT_RELEASE_FILE_LIMIT: usize = 11;
/// `ProcessingConfiguration::supportFileRegex`, without delimiters.
pub const SUPPORT_FILE_PATTERN: &str = r"\.(?:vol\d{1,3}\+\d{1,3}|par2|srs|sfv|nzb)";
/// `ReleaseBrowseService::PASSWD_RAR`.
const PASSWD_RAR: i8 = 1;
/// `NameFixingService::PROC_FILES_NONE`.
const PROC_FILES_NONE: i64 = 0;

/// The settings the manager reads.
#[derive(Clone, Debug)]
pub struct Settings {
    /// `innerFileBlacklist`, a delimited PCRE; `None` when unset.
    pub inner_file_blacklist: Option<String>,
    pub add_par2_files: bool,
    pub season_pack_max_release_files: usize,
    pub archive_retry_delay_seconds: i64,
    /// A claim older than this no longer owns its release.
    pub claim_ttl_seconds: i64,
    pub covers_path: PathBuf,
    /// The preview image format, `webp` or `jpg`.
    pub image_extension: String,
}

/// A value for one column of the final status update.
#[derive(Clone, Debug, PartialEq)]
enum Value {
    Int(i64),
    Null,
    Time(NaiveDateTime),
}

pub struct ReleaseFiles {
    pub pool: MySqlPool,
    pub settings: Settings,
    pub fixer: NameFixer,
    pub sync: SearchSync,
    blacklist: Option<Pcre>,
    support_line: Pcre,
    support_suffix: Pcre,
}

impl ReleaseFiles {
    pub fn new(
        pool: MySqlPool,
        settings: Settings,
        fixer: NameFixer,
        sync: SearchSync,
    ) -> Result<Self> {
        // An invalid blacklist matches nothing, as `preg_match` returning false did.
        let blacklist = settings
            .inner_file_blacklist
            .as_deref()
            .filter(|pattern| !pattern.is_empty())
            .and_then(|pattern| Pcre::new(pattern).ok());
        Ok(Self {
            pool,
            settings,
            fixer,
            sync,
            blacklist,
            support_line: Pcre::with_flags(
                &format!(
                    r"(?:{SUPPORT_FILE_PATTERN}|part\d+|[rz]\d{{1,3}}|zipr\d{{2,3}}|\d{{2,3}}|zipx?|zip|rar)(\s*\.rar)?$"
                ),
                "i",
            )?,
            support_suffix: Pcre::with_flags(&format!("{SUPPORT_FILE_PATTERN}$"), "i")?,
        })
    }

    fn claim_stale_before(&self) -> NaiveDateTime {
        (Utc::now() - TimeDelta::seconds(self.settings.claim_ttl_seconds)).naive_utc()
    }

    fn release_file_limit(&self, context: &ReleaseContext) -> usize {
        if context.season_pack {
            self.settings.season_pack_max_release_files
        } else {
            DEFAULT_RELEASE_FILE_LIMIT
        }
    }

    fn is_support_file(&self, name: &str) -> bool {
        self.support_suffix
            .is_match(strip_final_newline(name.as_bytes()))
    }

    /// `addFileInfo`: queues one listed file, or marks the release passworded, and tries the name
    /// against PreDB filenames. True when the file was queued.
    pub async fn add_file_info(
        &mut self,
        entry: &ListEntry,
        context: &mut ReleaseContext,
    ) -> Result<bool> {
        let ListEntry::File(file) = entry else {
            return Ok(false);
        };
        if file.pass != 0 {
            context.release_has_password = true;
            context.password_status = PASSWD_RAR;
            return Ok(false);
        }
        if self
            .blacklist
            .as_ref()
            .is_some_and(|blacklist| blacklist.is_match(&file.name))
        {
            context.release_has_password = true;
            context.password_status = PASSWD_RAR;
            return Ok(false);
        }
        if self.support_line.is_match(strip_final_newline(&file.name)) {
            return Ok(false);
        }
        if file.size < 0 {
            eprintln!(
                "release {}: skipping release file with invalid size metadata",
                context.release.id
            );
            return Ok(false);
        }
        context.total_file_info += 1;
        context.remember_naming_witness(&file.name);
        if context.added_file_info >= self.release_file_limit(context) {
            context.archive_name_evidence_incomplete = true;
            return Ok(false);
        }
        let name = String::from_utf8_lossy(&file.name).into_owned();
        if !self
            .queue_release_file(
                context,
                &name,
                file.size,
                file.date,
                file.pass,
                "",
                file.crc32.as_deref().unwrap_or(""),
            )
            .await?
        {
            return Ok(false);
        }
        if is_codec_spam(&file.name) {
            context.release_has_password = true;
            context.password_status = PASSWD_RAR;
        } else if !name.is_empty()
            && !name.starts_with('.')
            && !context.defers_archive_name_checks()
            && let Some(release) = self.producer_entry(context)
        {
            self.fixer.updater.reset();
            self.fixer
                .match_predb_file_names(&release, std::slice::from_ref(&name), true, true, None)
                .await?;
            let deferred = self.fixer.updater.was_episode_naming_deferred();
            context.remember_archive_filename_naming_attempt(&name, deferred);
            let proof = self.fixer.updater.last_committed_identity().cloned();
            self.accept_producer_name(context, proof.as_ref());
        }
        Ok(true)
    }

    /// The release as name fixing takes it, or `None` once naming is deferred.
    fn producer_entry(&self, context: &ReleaseContext) -> Option<Release> {
        if context.full_par2_evidence_deferred {
            return None;
        }
        let release = &context.release;
        Some(Release {
            id: release.id,
            guid: Some(release.guid.clone()),
            name: Some(release.name.clone()),
            searchname: Some(release.searchname.clone()),
            fromname: release.fromname.clone(),
            groups_id: release.groups_id,
            categories_id: i32::try_from(release.categories_id).unwrap_or_default(),
            relsize: release.size,
            predb_id: release.predb_id,
            nfostatus: i32::try_from(release.nfostatus).unwrap_or_default(),
            ..Release::default()
        })
    }

    /// `acceptProducerName`: a committed rename moves the evidence identity along, or defers
    /// naming when it wasn't this attempt's to make. True when a rename was accepted.
    fn accept_producer_name(
        &mut self,
        context: &mut ReleaseContext,
        proof: Option<&CommittedIdentity>,
    ) -> bool {
        if proof.is_some() && !context.advance_full_par2_evidence_identity(proof) {
            self.defer_producer_naming(context);
            return false;
        }
        proof.is_some()
    }

    fn defer_producer_naming(&mut self, context: &mut ReleaseContext) {
        context.full_par2_evidence_deferred = true;
        self.sync
            .discard(context.par2_evidence_snapshot.identity.id);
    }

    /// `matchFileNamesToPreDb`: the names held back while the release might have been a season
    /// pack, tried in order until one names it.
    pub async fn match_file_names_to_predb(
        &mut self,
        files: &[ListEntry],
        context: &mut ReleaseContext,
    ) -> Result<()> {
        if context.defers_archive_name_checks() {
            return Ok(());
        }
        for entry in files.iter().take(DEFAULT_RELEASE_FILE_LIMIT) {
            let name = match entry {
                ListEntry::File(file) => String::from_utf8_lossy(&file.name).into_owned(),
                _ => String::new(),
            };
            if name.is_empty() || name.starts_with('.') || self.is_support_file(&name) {
                continue;
            }
            let Some(release) = self.producer_entry(context) else {
                // PHP's applyProducerName declined, and the deferral ends the loop.
                return Ok(());
            };
            self.fixer.updater.reset();
            let matched = self
                .fixer
                .match_predb_file_names(&release, &split_filenames(&name), true, true, None)
                .await?;
            let deferred = self.fixer.updater.was_episode_naming_deferred();
            context.remember_archive_filename_naming_attempt(&name, deferred);
            let proof = self.fixer.updater.last_committed_identity().cloned();
            let accepted = self.accept_producer_name(context, proof.as_ref());
            if accepted || matched || context.full_par2_evidence_deferred {
                return Ok(());
            }
        }
        Ok(())
    }

    /// `queueReleaseFile`: queues a row for finalization unless the release already has it.
    /// True when queued.
    #[allow(clippy::too_many_arguments)]
    pub async fn queue_release_file(
        &mut self,
        context: &mut ReleaseContext,
        name: &str,
        size: i64,
        created: i64,
        passworded: i64,
        hash: &str,
        crc: &str,
    ) -> Result<bool> {
        if name.is_empty() {
            return Ok(false);
        }
        context.remember_naming_witness(name.as_bytes());
        self.load_existing_release_file_names(context).await?;
        if self.settings.add_par2_files
            && hash.len() == 32
            && hash.bytes().all(|byte| byte.is_ascii_hexdigit())
        {
            context
                .pending_par_hashes
                .insert(hash.to_ascii_lowercase(), ());
        }
        let existing = context
            .existing_release_file_names
            .get_or_insert_with(HashSet::new);
        if existing.contains(name) || context.pending_release_files.contains(name) {
            return Ok(false);
        }
        existing.insert(name.to_owned());
        let now = Utc::now();
        context.pending_release_files.insert(
            name.to_owned(),
            PendingReleaseFile {
                name: name.to_owned(),
                size,
                created_at: created_at(created, now),
                updated_at: now.timestamp(),
                passworded,
                crc32: crc.to_owned(),
            },
        );
        context.added_file_info += 1;
        Ok(true)
    }

    async fn load_existing_release_file_names(&self, context: &mut ReleaseContext) -> Result<()> {
        if context.existing_release_file_names.is_some() {
            return Ok(());
        }
        let names: Vec<String> =
            sqlx::query_scalar("SELECT name FROM release_files WHERE releases_id = ?")
                .bind(context.release.id)
                .fetch_all(&self.pool)
                .await?;
        context.existing_release_file_names = Some(names.into_iter().collect());
        Ok(())
    }

    /// `handleReleaseTimeout`: one more timeout, deleting the release at the limit and otherwise
    /// marking it processed so it isn't picked again. True when deleted.
    pub async fn handle_release_timeout(
        &mut self,
        context: &ReleaseContext,
        max_timeout_count: i64,
    ) -> Result<bool> {
        let release = &context.release;
        let count = release.pp_timeout_count + 1;
        if count >= max_timeout_count {
            eprintln!(
                "release {}: deleted after {count} post-processing timeouts",
                release.id
            );
            self.delete_release(release.id, &release.guid).await;
            return Ok(true);
        }
        sqlx::query(
            "UPDATE releases SET pp_timeout_count = ?, haspreview = 0, passwordstatus = 0, \
             additional_pp_claimed_at = NULL, additional_pp_claim_token = NULL WHERE id = ?",
        )
        .bind(count)
        .bind(release.id)
        .execute(&self.pool)
        .await?;
        self.sync.request(release.id).await;
        eprintln!(
            "release {}: skipped after post-processing timeout {count} of {max_timeout_count}",
            release.id
        );
        Ok(false)
    }

    /// `deleteRelease`: everything about a broken release, best effort. Failures are logged and
    /// swallowed, as in PHP.
    pub async fn delete_release(&mut self, id: i64, guid: &str) {
        if id == 0 {
            return;
        }
        self.sync.discard(id);
        if let Err(failure) = nntmux_verify::releases::delete_single_on(&self.pool, id, guid).await
        {
            eprintln!("release {id}: deletion failed: {failure:#}");
        }
    }

    fn image_exists(&self, directory: &str, basename: &str) -> bool {
        let directory = self.settings.covers_path.join(directory);
        let mut extensions = vec![self.settings.image_extension.as_str()];
        for extension in ["webp", "jpg", "jpeg"] {
            if !extensions.contains(&extension) {
                extensions.push(extension);
            }
        }
        extensions
            .iter()
            .any(|extension| is_readable(&directory.join(format!("{basename}.{extension}"))))
    }

    /// `ownsFullPar2Evidence`: the release still has the identity this attempt holds, under its
    /// claim. Locks the row when inside a transaction.
    pub async fn owns_full_par2_evidence(
        &self,
        conn: &mut MySqlConnection,
        context: &ReleaseContext,
    ) -> Result<bool> {
        let snapshot = context.full_par2_evidence_snapshot();
        let identity = &snapshot.identity;
        if context.release.id != identity.id || context.release.guid != identity.guid {
            return Ok(false);
        }
        let row = sqlx::query(
            "SELECT guid, name, searchname, additional_pp_claim_token, additional_pp_claimed_at \
             FROM releases WHERE id = ? FOR UPDATE",
        )
        .bind(identity.id)
        .fetch_optional(&mut *conn)
        .await?;
        let Some(row) = row else {
            return Ok(false);
        };
        let guid: Option<String> = row.try_get("guid")?;
        let name: Option<String> = row.try_get("name")?;
        let searchname: Option<String> = row.try_get("searchname")?;
        let token: Option<String> = row.try_get("additional_pp_claim_token")?;
        let claimed_at = claimed_at(&row)?;
        if guid.as_deref() != Some(identity.guid.as_str())
            || name != identity.name
            || searchname != identity.searchname
            || token != snapshot.claim_token
        {
            return Ok(false);
        }
        Ok(match snapshot.claim_token.as_deref() {
            None | Some("") => claimed_at.is_none(),
            Some(_) => claimed_at.is_some_and(|at| at >= self.claim_stale_before()),
        })
    }

    /// `archiveNamingIsAuthoritative`: a fully read inventory proves the naming scope, unless the
    /// release is a partial publication whose NZB lacks files never seen.
    pub async fn archive_naming_is_authoritative(&self, context: &ReleaseContext) -> Result<bool> {
        if !context.archive_naming_inspection_complete || context.archive_name_evidence_incomplete {
            return Ok(false);
        }
        let partial: Option<i64> = sqlx::query_scalar(
            "SELECT 1 FROM releases WHERE id = ? AND articles_expected IS NOT NULL LIMIT 1",
        )
        .bind(context.release.id)
        .fetch_optional(&self.pool)
        .await?;
        Ok(partial.is_none())
    }

    /// `finalizeRelease`: writes the queued inventory, PAR2 hashes, naming evidence and statuses in
    /// one guarded transaction, then refreshes search and tries the final filename match.
    pub async fn finalize_release(
        &mut self,
        context: &mut ReleaseContext,
        process_passwords: bool,
    ) -> Result<()> {
        let snapshot_id = context.par2_evidence_snapshot.identity.id;
        let Some(source_digest) = context
            .nzb_source_digest
            .clone()
            .filter(|_| !context.full_par2_evidence_deferred)
        else {
            context.full_par2_evidence_deferred = true;
            self.sync.discard(snapshot_id);
            return Ok(());
        };
        let guid = context.release.guid.clone();
        let mut values: Vec<(&'static str, Value)> = Vec::new();
        values.push((
            "haspreview",
            Value::Int(i64::from(
                self.image_exists("preview", &format!("{guid}_thumb")),
            )),
        ));
        if self
            .settings
            .covers_path
            .join("video")
            .join(format!("{guid}.ogv"))
            .is_file()
        {
            values.push(("videostatus", Value::Int(1)));
        }
        if self.image_exists("sample", &format!("{guid}_thumb")) {
            values.push(("jpgstatus", Value::Int(1)));
        }
        let password_status = context.password_status;
        if !process_passwords {
            context.release_has_password = false;
        }
        values.push(("additional_pp_claimed_at", Value::Null));
        values.push(("additional_pp_claim_token", Value::Null));
        let authoritative = self.archive_naming_is_authoritative(context).await?;

        let outcome = self
            .finalize_in_transaction(
                context,
                &values,
                &source_digest,
                process_passwords,
                password_status,
                authoritative,
            )
            .await;
        let (inserted, terminal) = match outcome {
            Ok(outcome) => outcome,
            Err(failure) => {
                self.sync.discard(snapshot_id);
                return Err(failure);
            }
        };
        context.pending_release_files.clear();
        context.pending_par_hashes.clear();
        context.pending_full_par_hashes.clear();
        let Some(inserted) = inserted else {
            context.full_par2_evidence_deferred = true;
            self.sync.discard(snapshot_id);
            return Ok(());
        };
        context.release_files_changed |= inserted > 0;
        self.sync.request(context.release.id).await;
        if let Some((release, names)) = terminal {
            self.match_finalized_file_names(release, &names, &source_digest)
                .await;
        }
        Ok(())
    }

    /// The transaction, retried on lock errors as `DB::transaction(..., 3)` was. Returns the rows
    /// inserted (`None` when the finalization was stale) and the terminal naming candidates.
    #[allow(clippy::type_complexity)]
    async fn finalize_in_transaction(
        &self,
        context: &ReleaseContext,
        values: &[(&'static str, Value)],
        source_digest: &str,
        process_passwords: bool,
        password_status: i8,
        authoritative: bool,
    ) -> Result<(Option<u64>, Option<(Release, Vec<String>)>)> {
        let mut attempt = 0;
        loop {
            attempt += 1;
            let mut tx = self.pool.begin().await?;
            let result = self
                .finalize_locked(
                    &mut tx,
                    context,
                    values,
                    source_digest,
                    process_passwords,
                    password_status,
                    authoritative,
                )
                .await;
            let result = match result {
                Ok(value) => tx.commit().await.map(|()| value).map_err(Into::into),
                Err(failure) => Err(failure),
            };
            match result {
                Err(failure) if attempt < 3 && nntmux_verify::txn::is_lock_error(&failure) => {
                    tokio::time::sleep(nntmux_verify::txn::lock_backoff(attempt)).await;
                }
                other => return other,
            }
        }
    }

    #[allow(clippy::too_many_arguments, clippy::type_complexity)]
    async fn finalize_locked(
        &self,
        conn: &mut MySqlConnection,
        context: &ReleaseContext,
        values: &[(&'static str, Value)],
        source_digest: &str,
        process_passwords: bool,
        password_status: i8,
        authoritative: bool,
    ) -> Result<(Option<u64>, Option<(Release, Vec<String>)>)> {
        if !self.owns_full_par2_evidence(conn, context).await? {
            return Ok((None, None));
        }
        let names = context.archive_naming_witnesses().to_vec();
        let scope = if context.season_pack || is_multi_episode_set(&names) {
            Scope::MultiEpisode
        } else if authoritative {
            Scope::CompleteSingle
        } else {
            Scope::Incomplete
        };
        let seen = Observation {
            release_id: context.release.id,
            guid: &context.release.guid,
            source_digest,
            scope,
            filenames: &names,
            authoritative_complete: authoritative,
            evidence_omitted: context.naming_witnesses_omitted,
        };
        if !record_locked(conn, &seen).await? {
            return Ok((None, None));
        }
        let inserted = self
            .persist(conn, context, values, process_passwords, password_status)
            .await?;

        let mut terminal = None;
        if authoritative
            && !context.naming_witnesses_omitted
            && !context.season_pack
            && !has_distinct_episode_identities(&names)
        {
            let candidates: Vec<String> = names
                .iter()
                .filter(
                    |name| match context.archive_filename_naming_attempts.get(*name) {
                        Some(deferred) => *deferred,
                        None => is_episode_name(name),
                    },
                )
                .cloned()
                .collect();
            if !candidates.is_empty() {
                // Name fixing reads the release as PHP's consumers selected it.
                let row = sqlx::query(
                    "SELECT *, id AS releases_id, size AS relsize FROM releases WHERE id = ? FOR UPDATE",
                )
                    .bind(context.release.id)
                    .fetch_optional(&mut *conn)
                    .await?;
                if let Some(row) = row
                    && column_i64(&row, "predb_id")? == 0
                    && column_i64(&row, "isrenamed")? == 0
                {
                    terminal = Some((self.terminal_entry(&row)?, candidates));
                }
            }
        }
        Ok((Some(inserted), terminal))
    }

    /// The writes that run once the naming evidence is recorded: PAR2 evidence, release files, PAR2
    /// hashes, then the guarded status update.
    async fn persist(
        &self,
        conn: &mut MySqlConnection,
        context: &ReleaseContext,
        values: &[(&'static str, Value)],
        process_passwords: bool,
        password_status: i8,
    ) -> Result<u64> {
        let mut values = values.to_vec();
        for evidence in context.pending_full_par_hashes.values() {
            if !self.remember_full_par_hash(conn, context, evidence).await? {
                bail!("Additional-processing evidence admission failed.");
            }
        }
        if !self.owns_full_par2_evidence(conn, context).await? {
            bail!("Additional-processing evidence ownership changed inside finalization.");
        }
        let inserted = self.insert_release_files(conn, context).await?;
        if inserted > 0 {
            let current: Option<(i64, i64)> = sqlx::query_as(
                "SELECT CAST(isrenamed AS SIGNED), CAST(predb_id AS SIGNED) FROM releases WHERE id = ? FOR UPDATE",
            )
            .bind(context.release.id)
            .fetch_optional(&mut *conn)
            .await?;
            if current == Some((0, 0)) {
                values.push(("proc_files", Value::Int(PROC_FILES_NONE)));
            }
        }
        if !context.pending_par_hashes.is_empty() {
            let mut insert =
                QueryBuilder::<MySql>::new("INSERT IGNORE INTO par_hashes (releases_id, hash) ");
            insert.push_values(context.pending_par_hashes.keys(), |mut row, hash| {
                row.push_bind(context.release.id).push_bind(hash.clone());
            });
            insert.build().execute(&mut *conn).await?;
        }
        let count: i64 =
            sqlx::query_scalar("SELECT COUNT(*) FROM release_files WHERE releases_id = ?")
                .bind(context.release.id)
                .fetch_one(&mut *conn)
                .await?;
        let haspreview_zero = values
            .iter()
            .any(|(column, value)| *column == "haspreview" && *value == Value::Int(0));
        if !context.release_has_password
            && context.nzb_has_compressed_file
            && !context.compressed_files_are_media()
            && count == 0
        {
            // No file list could be read, often because volumes were still missing. Queue the
            // release once more after a delay, but only when inspection ran and a later attempt
            // could still read the list; a second failure stays unknown.
            let mut retried = false;
            if haspreview_zero
                && context.archive_inspection_attempted
                && (!context.archive_listing_undecodable || context.archive_retryable)
            {
                let mut retry = values.clone();
                retry.retain(|(column, _)| *column != "haspreview");
                retry.push(("haspreview", Value::Int(-1)));
                let at = (Utc::now()
                    + TimeDelta::seconds(self.settings.archive_retry_delay_seconds))
                .naive_utc();
                retry.push(("archive_retry_at", Value::Time(at.with_nanosecond_zero())));
                retried = self
                    .commit_final_update(conn, context, &retry, true, true)
                    .await?
                    > 0;
            }
            if !retried {
                self.commit_final_update(conn, context, &values, false, false)
                    .await?;
            }
        } else {
            // A cut-off listing can't rule out an encrypted entry past the cut.
            if !process_passwords {
                values.push(("passwordstatus", Value::Int(0)));
            } else if context.release_has_password || !context.archive_listing_incomplete {
                values.push(("passwordstatus", Value::Int(i64::from(password_status))));
            }
            values.push(("rarinnerfilecount", Value::Int(count)));
            self.commit_final_update(conn, context, &values, false, false)
                .await?;
        }
        Ok(inserted)
    }

    async fn insert_release_files(
        &self,
        conn: &mut MySqlConnection,
        context: &ReleaseContext,
    ) -> Result<u64> {
        if context.pending_release_files.is_empty() {
            return Ok(0);
        }
        let mut insert = QueryBuilder::<MySql>::new(
            "INSERT IGNORE INTO release_files (releases_id, name, size, created_at, updated_at, passworded, crc32) ",
        );
        insert.push_values(context.pending_release_files.values(), |mut row, file| {
            row.push_bind(context.release.id)
                .push_bind(file.name.clone())
                .push_bind(file.size)
                .push_bind(file.created_at.clone())
                .push(" FROM_UNIXTIME(")
                .push_bind_unseparated(file.updated_at)
                .push_unseparated(")")
                .push_bind(file.passworded)
                .push_bind(file.crc32.clone());
        });
        Ok(insert.build().execute(&mut *conn).await?.rows_affected())
    }

    /// `Par2FileHashEvidence::remember` against the context's snapshot. PHP's guard re-ran the
    /// ownership check from inside; the row lock this transaction holds keeps it true, so it is run
    /// once just before. The snapshot PHP passed held no `predb_id` or `isrenamed`, so the current
    /// values stand in for them.
    async fn remember_full_par_hash(
        &self,
        conn: &mut MySqlConnection,
        context: &ReleaseContext,
        evidence: &super::context::FullParHash,
    ) -> Result<bool> {
        if !self.owns_full_par2_evidence(conn, context).await? {
            return Ok(false);
        }
        let identity = &context.full_par2_evidence_snapshot().identity;
        let current: Option<(i64, i64)> = sqlx::query_as(
            "SELECT CAST(predb_id AS SIGNED), CAST(isrenamed AS SIGNED) FROM releases WHERE id = ? FOR UPDATE",
        )
        .bind(identity.id)
        .fetch_optional(&mut *conn)
        .await?;
        let Some((predb_id, isrenamed)) = current else {
            return Ok(false);
        };
        let expected = nntmux_namefix::par2_evidence::EvidenceSnapshot {
            id: identity.id,
            guid: identity.guid.clone(),
            name: identity.name.clone(),
            searchname: identity.searchname.clone(),
            predb_id,
            isrenamed,
            proc_hash16k: None,
        };
        nntmux_namefix::par2_evidence::remember_hash(
            conn,
            identity.id,
            &evidence.full_md5,
            &evidence.hash16k,
            evidence.size.as_deref(),
            &expected,
            None,
        )
        .await
    }

    /// `commitFullPar2FinalizationUpdate`: the status update, only while this attempt still owns
    /// the release, and checked afterwards to have landed as written. `retry` adds the archive
    /// retry column's `IS NULL`; `allow_no_match` returns 0 instead of updating nothing.
    async fn commit_final_update(
        &self,
        conn: &mut MySqlConnection,
        context: &ReleaseContext,
        values: &[(&'static str, Value)],
        retry: bool,
        allow_no_match: bool,
    ) -> Result<u64> {
        if !self.owns_full_par2_evidence(conn, context).await? {
            bail!("Additional-processing evidence ownership changed before final status update.");
        }
        let snapshot = context.full_par2_evidence_snapshot().clone();
        let stamp: Option<Option<DateTime<Utc>>> = sqlx::query_scalar(
            "SELECT additional_pp_claimed_at FROM releases WHERE id = ? FOR UPDATE",
        )
        .bind(snapshot.identity.id)
        .fetch_optional(&mut *conn)
        .await?;
        let Some(stamp) = stamp else {
            bail!("Additional-processing release disappeared before final status update.");
        };
        let stamp = stamp.map(|at| at.naive_utc());
        let guard = |query: &mut QueryBuilder<MySql>| {
            query.push(" WHERE id = ").push_bind(snapshot.identity.id);
            if retry {
                query.push(" AND archive_retry_at IS NULL");
            }
            push_bytes_match(query, "guid", Some(&snapshot.identity.guid));
            push_bytes_match(query, "name", snapshot.identity.name.as_deref());
            push_bytes_match(query, "searchname", snapshot.identity.searchname.as_deref());
            push_bytes_match(
                query,
                "additional_pp_claim_token",
                snapshot.claim_token.as_deref(),
            );
            match stamp {
                None => query.push(" AND additional_pp_claimed_at IS NULL"),
                Some(stamp) => query
                    .push(" AND additional_pp_claimed_at = ")
                    .push_bind(stamp),
            };
            match snapshot.claim_token.as_deref() {
                None | Some("") => query.push(" AND additional_pp_claimed_at IS NULL"),
                Some(_) => query
                    .push(" AND additional_pp_claimed_at >= ")
                    .push_bind(self.claim_stale_before()),
            };
        };
        if allow_no_match {
            let mut exists = QueryBuilder::<MySql>::new("SELECT 1 FROM releases");
            guard(&mut exists);
            exists.push(" LIMIT 1");
            if exists.build().fetch_optional(&mut *conn).await?.is_none() {
                if !self.owns_full_par2_evidence(conn, context).await? {
                    bail!(
                        "Additional-processing evidence ownership changed during final status admission."
                    );
                }
                return Ok(0);
            }
        }
        let mut update = QueryBuilder::<MySql>::new("UPDATE releases SET ");
        for (index, (column, value)) in values.iter().enumerate() {
            if index > 0 {
                update.push(", ");
            }
            update.push(format!("{column} = "));
            push_value(&mut update, value);
        }
        guard(&mut update);
        let updated = update.build().execute(&mut *conn).await?.rows_affected();

        // The row must now read exactly as the snapshot with the values written over it.
        let mut check = QueryBuilder::<MySql>::new("SELECT 1 FROM releases WHERE id = ");
        check.push_bind(snapshot.identity.id);
        let overridden = |column: &str| values.iter().any(|(written, _)| *written == column);
        push_bytes_match(&mut check, "guid", Some(&snapshot.identity.guid));
        if !overridden("name") {
            push_bytes_match(&mut check, "name", snapshot.identity.name.as_deref());
        }
        if !overridden("searchname") {
            push_bytes_match(
                &mut check,
                "searchname",
                snapshot.identity.searchname.as_deref(),
            );
        }
        if !overridden("additional_pp_claim_token") {
            push_bytes_match(
                &mut check,
                "additional_pp_claim_token",
                snapshot.claim_token.as_deref(),
            );
        }
        for (column, value) in values {
            match value {
                Value::Null => {
                    check.push(format!(" AND {column} IS NULL"));
                }
                other => {
                    check.push(format!(" AND {column} = "));
                    push_value(&mut check, other);
                }
            }
        }
        check.push(" FOR UPDATE");
        if check.build().fetch_optional(&mut *conn).await?.is_none() {
            bail!(
                "Additional-processing evidence ownership or terminal state changed during final status update."
            );
        }
        if context.release.id != snapshot.identity.id
            || context.release.guid != snapshot.identity.guid
        {
            bail!("Additional-processing caller identity changed during final status update.");
        }
        Ok(updated)
    }

    /// The committed release as a new consumer sees it, frozen inside the transaction so a later
    /// worker can't make this attempt the owner of its successor's identity.
    fn terminal_entry(&self, row: &MySqlRow) -> Result<Release> {
        let mut release = Release::from_row(row)?;
        release.observed.isrenamed =
            Some(i32::try_from(column_i64(row, "isrenamed")?).unwrap_or_default());
        let token: Option<String> = row.try_get("additional_pp_claim_token")?;
        let claimed_at = claimed_at(row)?;
        release.observed.additional_claim = Some(AdditionalClaim {
            token,
            claimed_at: claimed_at.map(|at| at.format("%Y-%m-%d %H:%M:%S").to_string()),
            stale_before: self
                .claim_stale_before()
                .format("%Y-%m-%d %H:%M:%S")
                .to_string(),
        });
        Ok(release)
    }

    /// `matchFinalizedFileNames`: after commit, the first of these names that matches a PreDB
    /// filename names the release. Failures are logged, never a rollback: the metadata is
    /// already committed.
    async fn match_finalized_file_names(
        &mut self,
        release: Release,
        names: &[String],
        source_digest: &str,
    ) {
        let mut release = release;
        release.observed.archive_source_digest = Some(source_digest.to_owned());
        for name in names.iter().take(DEFAULT_RELEASE_FILE_LIMIT) {
            if name.is_empty() || name.starts_with('.') || self.is_support_file(name) {
                continue;
            }
            self.fixer.updater.reset();
            match self
                .fixer
                .match_predb_file_names(&release, &split_filenames(name), true, true, None)
                .await
            {
                Ok(true) => return,
                Ok(false) => {}
                Err(failure) => {
                    eprintln!(
                        "release {}: post-finalization filename matching failed: {failure:#}",
                        release.id
                    );
                    return;
                }
            }
        }
    }
}

trait SecondsOnly {
    fn with_nanosecond_zero(self) -> Self;
}

impl SecondsOnly for NaiveDateTime {
    fn with_nanosecond_zero(self) -> Self {
        use chrono::Timelike;
        self.with_nanosecond(0).unwrap_or(self)
    }
}

fn push_value(query: &mut QueryBuilder<MySql>, value: &Value) {
    match value {
        Value::Int(value) => {
            query.push_bind(*value);
        }
        Value::Null => {
            query.push("NULL");
        }
        Value::Time(value) => {
            query.push_bind(*value);
        }
    }
}

/// PHP compared identity columns by `HEX()`, so collation can't make two names equal.
fn push_bytes_match(query: &mut QueryBuilder<MySql>, column: &str, value: Option<&str>) {
    match value {
        None => {
            query.push(format!(" AND {column} IS NULL"));
        }
        Some(value) => {
            query
                .push(format!(" AND HEX({column}) = "))
                .push_bind(hex::encode_upper(value.as_bytes()));
        }
    }
}

/// The claim stamp is a `TIMESTAMP`, read in the connection's UTC.
fn claimed_at(row: &MySqlRow) -> Result<Option<NaiveDateTime>> {
    let at: Option<DateTime<Utc>> = row.try_get("additional_pp_claimed_at")?;
    Ok(at.map(|at| at.naive_utc()))
}

fn column_i64(row: &MySqlRow, column: &str) -> Result<i64> {
    if let Ok(value) = row.try_get::<Option<i64>, _>(column) {
        return Ok(value.unwrap_or(0));
    }
    if let Ok(value) = row.try_get::<Option<u64>, _>(column) {
        return Ok(value.map_or(0, |value| i64::try_from(value).unwrap_or(i64::MAX)));
    }
    if let Ok(value) = row.try_get::<Option<i32>, _>(column) {
        return Ok(value.map_or(0, i64::from));
    }
    if let Ok(value) = row.try_get::<Option<u32>, _>(column) {
        return Ok(value.map_or(0, i64::from));
    }
    if let Ok(value) = row.try_get::<Option<i8>, _>(column) {
        return Ok(value.map_or(0, i64::from));
    }
    Err(anyhow!("column {column} is not an integer"))
}

/// `matchPreDbFiles` split the stored filename on `||`.
fn split_filenames(name: &str) -> Vec<String> {
    name.split("||").map(str::to_owned).collect()
}

/// `normalizeCreatedTime` for an archive's timestamp: 0 is now.
fn created_at(timestamp: i64, now: DateTime<Utc>) -> String {
    let when = if timestamp == 0 {
        now
    } else {
        DateTime::from_timestamp(timestamp, 0).unwrap_or(now)
    };
    when.format("%Y-%m-%d %H:%M:%S").to_string()
}

/// `#(?:^|[/\\])Codec[/\\]Setup\.exe$#i`.
fn is_codec_spam(name: &[u8]) -> bool {
    let name = strip_final_newline(name).to_ascii_lowercase();
    let Some(rest) = name.strip_suffix(b"setup.exe") else {
        return false;
    };
    let Some(rest) = rest.strip_suffix(b"/").or_else(|| rest.strip_suffix(b"\\")) else {
        return false;
    };
    let Some(before) = rest.strip_suffix(b"codec") else {
        return false;
    };
    before.is_empty() || before.ends_with(b"/") || before.ends_with(b"\\")
}

/// PCRE's `$` also matches before one final line break; matching without it is the same.
fn strip_final_newline(subject: &[u8]) -> &[u8] {
    subject.strip_suffix(b"\n").unwrap_or(subject)
}

fn is_readable(path: &Path) -> bool {
    std::fs::File::open(path).is_ok_and(|file| file.metadata().is_ok_and(|meta| meta.is_file()))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn codec_spam_needs_its_own_directory() {
        assert!(is_codec_spam(b"Codec/Setup.exe"));
        assert!(is_codec_spam(b"x/codec\\SETUP.EXE"));
        assert!(is_codec_spam(b"Codec/Setup.exe\n"));
        assert!(!is_codec_spam(b"MyCodec/Setup.exe"));
        assert!(!is_codec_spam(b"Codec/XSetup.exe"));
    }

    #[test]
    fn creation_times_come_from_the_archive_or_now() {
        let now = DateTime::from_timestamp(1_700_000_000, 0).expect("valid");
        assert_eq!(created_at(0, now), "2023-11-14 22:13:20");
        assert_eq!(created_at(86_400, now), "1970-01-02 00:00:00");
    }
}
