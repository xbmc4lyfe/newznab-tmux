//! Orchestrates the name sources: NFO, files, CRC, SRR, media info, PAR2 hashes and the PreDB
//! fulltext sweep.

use std::collections::{BTreeMap, HashMap};
use std::sync::Arc;

use anyhow::{Result, anyhow};
use async_trait::async_trait;
use nntmux_core::pcre::Pcre;
use nntmux_predb::filename::{exact_value, has_control, is_known_file_suffix};
use nntmux_predb::selector::{PredbHit, select_best_match};
use sqlx::MySqlConnection;

use crate::checkers;
use crate::donor;
use crate::extractors;
use crate::file_prioritizer as files;
use crate::guard::{FilenameGuard, FilenamePass, collect_pass};
use crate::queries::{self, Donor, FileRow, FixNamesConfig, MediaRow, PredbTitle, Source};
use crate::release::{Release, parse_generation};
use crate::update::{ReleaseUpdater, lock_snapshot, predb_regex};

const PREDB_CANDIDATE_PAGE_SIZE: u64 = 21;
const PREDB_CANDIDATE_MAX_PAGES: u32 = 10;
/// Seconds before a strong PAR2 hash miss is looked at again.
const HASH_RETRY_DELAY_SECONDS: i64 = 60;

/// A page of release ids a title may belong to, in ascending order.
#[derive(Clone, Debug)]
pub struct CandidatePage {
    pub ids: Vec<i64>,
    pub has_more: bool,
}

/// Discovery for the PreDB fulltext sweep. An outage is an error, never "no candidates".
#[async_trait]
pub trait CandidateSearch: Send + Sync {
    async fn release_candidates_page(&self, title: &str, limit: u64, after_id: i64) -> Result<CandidatePage>;
}

/// The result of probing a release's PAR2 files.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Par2Outcome {
    Renamed,
    NoMatch,
    Retryable,
    Deferred,
}

/// How a standard batch reaches PAR2, which needs Usenet.
#[async_trait]
pub trait Par2Probe: Send {
    async fn probe(&mut self, release: &Release) -> Result<Par2Outcome>;
}

/// The sweep could only see part of the evidence, so the title stays unsearched.
#[derive(Debug)]
pub struct IncompleteMatch(pub String);

impl std::fmt::Display for IncompleteMatch {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.0)
    }
}

impl std::error::Error for IncompleteMatch {}

/// What matching one PreDB title did.
#[derive(Clone, Copy, Debug, PartialEq)]
pub struct FulltextOutcome {
    /// Releases written, or -1 when there were too many candidates to trust.
    pub updated: i32,
    pub matched: bool,
    pub retry: bool,
}

pub struct NameFixer {
    pub updater: ReleaseUpdater,
    config: FixNamesConfig,
    total: i64,
}

fn group_files(names: &[FileRow]) -> Vec<String> {
    let mut seen = Vec::new();
    for row in names {
        if !seen.contains(&row.name) {
            seen.push(row.name.clone());
        }
    }
    seen
}

fn distinct(values: impl Iterator<Item = String>) -> Vec<String> {
    let mut seen: Vec<String> = Vec::new();
    for value in values {
        if !value.is_empty() && !seen.contains(&value) {
            seen.push(value);
        }
    }
    seen
}

fn valid_catalogue_title(title: &str) -> bool {
    title.chars().count() <= 255 && !has_control(title)
}

impl NameFixer {
    pub fn new(updater: ReleaseUpdater, config: FixNamesConfig) -> Self {
        Self { updater, config, total: 0 }
    }

    fn db(&self) -> sqlx::MySqlPool {
        self.updater.db.clone()
    }

    async fn start_batch(&mut self, source: Source, time: i32, categories: i32, message: &str) -> Result<bool> {
        self.total = queries::count_candidates(&self.db(), source, time, categories).await?;
        if self.total == 0 {
            println!("Nothing to fix.");
            return Ok(false);
        }
        println!("{}{message}", self.total);
        Ok(true)
    }

    async fn next_batch(&self, source: Source, time: i32, categories: i32, after_id: i64) -> Result<Vec<Release>> {
        queries::candidate_batch(&self.db(), &self.config, source, time, categories, after_id, queries::BATCH_SIZE, None).await
    }

    fn found(&self, write: bool, kind: &str) {
        let (fixed, checked) = (self.updater.fixed, self.updater.checked);
        if write {
            println!("{fixed} releases have had their names changed out of: {checked}{kind}.");
        } else {
            println!("{fixed} releases could have their names changed. {checked}{kind} were checked.");
        }
    }

    async fn mark_processed(&self, write: bool, status: bool, column: &str, id: i64) -> Result<()> {
        if write && status {
            self.updater.update_single_column(column, 1, id).await?;
        }
        Ok(())
    }

    /// Whether a batch ended the source: anything short of a full page is the last.
    fn batch_full(&self, len: usize) -> bool {
        len == self.config.batch_limit.unwrap_or(queries::BATCH_SIZE)
    }

    pub async fn fix_names_with_nfo(&mut self, time: i32, write: bool, categories: i32, status: bool, show: bool) -> Result<()> {
        println!("Fixing search names {} using .nfo files.", window(time));
        let pre_id_only = categories == 3;
        if !self.start_batch(Source::Nfo, time, categories, " releases to process.").await? {
            return Ok(());
        }
        let mut after = 0;
        loop {
            let releases = self.next_batch(Source::Nfo, time, categories, after).await?;
            let Some(last) = releases.last().map(|r| r.id) else {
                break;
            };
            let ids: Vec<i64> = releases.iter().map(|r| r.id).collect();
            let nfos = queries::nfo_rows(&self.db(), &ids).await?;
            for release in &releases {
                self.updater.increment_checked();
                self.updater.reset();
                match nfos.get(&release.id) {
                    None => {
                        if write && status {
                            self.updater.update_snapshot_column(release, "proc_nfo", 1).await?;
                        }
                    }
                    Some(text) => {
                        self.process_nfo(release, text, write, status, true, !pre_id_only, true).await?;
                        self.progress(show);
                    }
                }
            }
            after = last;
            if !self.batch_full(releases.len()) {
                break;
            }
        }
        self.found(write, " NFO's");
        Ok(())
    }

    pub async fn fix_names_with_files(&mut self, time: i32, write: bool, categories: i32, status: bool, show: bool) -> Result<()> {
        println!("Fixing search names {} using file names.", window(time));
        if !self.start_batch(Source::Files, time, categories, " file names to process.").await? {
            return Ok(());
        }
        let mut after = 0;
        loop {
            let releases = self.next_batch(Source::Files, time, categories, after).await?;
            let Some(last) = releases.last().map(|r| r.id) else {
                break;
            };
            let ids: Vec<i64> = releases.iter().map(|r| r.id).collect();
            let rows = queries::file_rows(&self.db(), &ids, Source::Files).await?;
            for release in &releases {
                let rows = rows.get(&release.id).map_or(&[][..], Vec::as_slice);
                self.process_file_candidates(release, rows, write, status, true).await?;
                self.progress(show);
            }
            after = last;
            if !self.batch_full(releases.len()) {
                break;
            }
        }
        self.found(write, " files");
        Ok(())
    }

    pub async fn fix_names_with_srr(&mut self, time: i32, write: bool, categories: i32, status: bool, show: bool) -> Result<()> {
        println!("Fixing search names {} using SRR file names.", window(time));
        if !self.start_batch(Source::Srr, time, categories, " srr file extensions to process.").await? {
            return Ok(());
        }
        let mut after = 0;
        loop {
            let releases = self.next_batch(Source::Srr, time, categories, after).await?;
            let Some(last) = releases.last().map(|r| r.id) else {
                break;
            };
            let ids: Vec<i64> = releases.iter().map(|r| r.id).collect();
            let rows = queries::file_rows(&self.db(), &ids, Source::Srr).await?;
            for release in &releases {
                self.updater.reset();
                self.updater.increment_checked();
                for row in rows.get(&release.id).map_or(&[][..], Vec::as_slice) {
                    if self.srr_name_check(release, &row.name, write, "SRR, ", status).await? {
                        break;
                    }
                }
                if !self.updater.matched {
                    self.mark_processed(write, status, "proc_srr", release.id).await?;
                }
                self.progress(show);
            }
            after = last;
            if !self.batch_full(releases.len()) {
                break;
            }
        }
        self.found(write, " files");
        Ok(())
    }

    pub async fn fix_names_with_crc(&mut self, time: i32, write: bool, categories: i32, status: bool, show: bool) -> Result<()> {
        println!("Fixing search names {} using CRC32.", window(time));
        if !self.start_batch(Source::Crc, time, categories, " CRC32's to process.").await? {
            return Ok(());
        }
        let mut after = 0;
        loop {
            let releases = self.next_batch(Source::Crc, time, categories, after).await?;
            let Some(last) = releases.last().map(|r| r.id) else {
                break;
            };
            let ids: Vec<i64> = releases.iter().map(|r| r.id).collect();
            let rows = queries::file_rows(&self.db(), &ids, Source::Crc).await?;
            let crcs = distinct(rows.values().flatten().filter_map(|row| row.crc32.clone()));
            let donors = queries::crc_donors(&self.db(), &crcs).await?;
            for release in &releases {
                self.updater.reset();
                self.updater.increment_checked();
                let rows = rows.get(&release.id).map_or(&[][..], Vec::as_slice);
                self.crc_pass(release, rows, &donors, write, status).await?;
                if !self.updater.matched {
                    self.mark_processed(write, status, "proc_crc32", release.id).await?;
                }
                self.progress(show);
            }
            after = last;
            if !self.batch_full(releases.len()) {
                break;
            }
        }
        self.found(write, " crc32's");
        Ok(())
    }

    /// Tries the CRCs of the most useful files first.
    async fn crc_pass(
        &mut self,
        release: &Release,
        rows: &[FileRow],
        donors: &HashMap<String, Vec<Donor>>,
        write: bool,
        status: bool,
    ) -> Result<()> {
        let mut prioritized: BTreeMap<i32, Vec<String>> = BTreeMap::new();
        for row in rows {
            let crc = row.crc32.clone().unwrap_or_default();
            if crc.is_empty() {
                continue;
            }
            let bucket = prioritized.entry(files::get_crc_priority(&row.name)).or_default();
            if !bucket.contains(&crc) {
                bucket.push(crc);
            }
        }
        for crc in prioritized.values().flatten() {
            let list = donors.get(crc).map_or(&[][..], Vec::as_slice);
            if self.apply_donor_match(release, list, 5, "crcCheck: CRC32", "CRC32, ", write, status).await? {
                break;
            }
        }
        Ok(())
    }

    pub async fn fix_names_with_media(&mut self, time: i32, write: bool, categories: i32, status: bool, show: bool) -> Result<()> {
        println!("Fixing search names {} using mediainfo Unique_IDs.", window(time));
        if !self.start_batch(Source::Uid, time, categories, " unique ids to process.").await? {
            return Ok(());
        }
        let mut after = 0;
        loop {
            let releases = self.next_batch(Source::Uid, time, categories, after).await?;
            let Some(last) = releases.last().map(|r| r.id) else {
                break;
            };
            let ids: Vec<i64> = releases.iter().map(|r| r.id).collect();
            let media = queries::media_rows(&self.db(), &ids).await?;
            let uids = distinct(media.values().flatten().filter_map(|row| row.uid.clone()));
            let donors = queries::uid_donors(&self.db(), &uids).await?;
            for release in &releases {
                self.updater.reset();
                self.updater.increment_checked();
                for row in media.get(&release.id).map_or(&[][..], Vec::as_slice) {
                    let uid = row.uid.clone().unwrap_or_default();
                    if uid.is_empty() {
                        continue;
                    }
                    let list = donors.get(&uid).map_or(&[][..], Vec::as_slice);
                    if self.apply_donor_match(release, list, 10, "uidCheck: Unique_ID", "UID, ", write, status).await? {
                        break;
                    }
                }
                if !self.updater.matched {
                    self.mark_processed(write, status, "proc_uid", release.id).await?;
                }
                self.progress(show);
            }
            after = last;
            if !self.batch_full(releases.len()) {
                break;
            }
        }
        self.found(write, " UID's");
        Ok(())
    }

    pub async fn fix_names_with_par_hash(&mut self, time: i32, write: bool, categories: i32, status: bool, show: bool) -> Result<()> {
        println!("Fixing search names {} using PAR2 hash_16K.", window(time));
        if !self.start_batch(Source::Hash, time, categories, " hash_16K to process.").await? {
            return Ok(());
        }
        let mut after = 0;
        loop {
            let releases = self.next_batch(Source::Hash, time, categories, after).await?;
            let Some(last) = releases.last().map(|r| r.id) else {
                break;
            };
            let ids: Vec<i64> = releases.iter().map(|r| r.id).collect();
            let hashes = queries::legacy_hash_rows(&self.db(), &ids).await?;
            let values = distinct(hashes.values().flatten().cloned());
            let donors = queries::hash_donors(&self.db(), &values).await?;
            for release in &releases {
                self.updater.reset();
                self.updater.increment_checked();
                let rows = hashes.get(&release.id).map_or(&[][..], Vec::as_slice);
                self.process_par2_hash(release, rows, &donors, write, status).await?;
                self.progress(show);
            }
            after = last;
            if !self.batch_full(releases.len()) {
                break;
            }
        }
        self.found(write, " hashes");
        Ok(())
    }

    /// Serializes hash completion and evidence requeue on the exact target. Strong misses stay
    /// pending with a GUID-bound cooldown for later donor linkage.
    async fn process_par2_hash(
        &mut self,
        release: &Release,
        legacy: &[String],
        legacy_donors: &HashMap<String, Vec<Donor>>,
        write: bool,
        status: bool,
    ) -> Result<bool> {
        let Some(guid) = release.guid.clone() else {
            return Ok(false);
        };
        let db = self.db();
        let mut tx = db.begin().await?;
        let mut release = release.clone();
        release.observed.isrenamed = Some(release.isrenamed);
        if !par2_snapshot_matches(&mut tx, &release).await? {
            return Ok(false);
        }
        let retry: Option<(String, bool)> = sqlx::query_as(
            "SELECT release_guid, retry_at > NOW() FROM par2_hash_retry WHERE releases_id = ? FOR UPDATE",
        )
        .bind(release.id)
        .fetch_optional(&mut *tx)
        .await?;
        if retry.as_ref().is_some_and(|(retry_guid, waiting)| *retry_guid == guid && *waiting) {
            return Ok(false);
        }
        let strong = queries::full_hash_donors_for_release(&mut tx, release.id, &guid).await?;
        release.observed.par2_hash_generation = Some(strong.generation.clone());
        if strong.known {
            if !par2_snapshot_matches(&mut tx, &release).await? {
                return Ok(false);
            }
            let chosen = if strong.usable && !strong.overflow { donor::select_canonical(&strong.donors) } else { None };
            if queries::hash_evidence_generation(&mut tx, release.id).await? != strong.generation {
                return Ok(false);
            }
            // The write takes its own locks, so this read transaction ends first. Its snapshot
            // predicates include the generation, so a change in between refuses the write.
            tx.commit().await?;
            if let Some(chosen) = chosen {
                self.updater
                    .update_release(
                        &release,
                        &chosen.canonical_title,
                        "hashCheck: PAR2 full MD5 and exact file size",
                        write,
                        "PAR2 hash, ",
                        status,
                        chosen.predb_id as i64,
                        None,
                        None,
                    )
                    .await?;
                if self.updater.matched {
                    if write {
                        sqlx::query("DELETE FROM par2_hash_retry WHERE releases_id = ? AND HEX(release_guid) = HEX(?)")
                            .bind(release.id)
                            .bind(&guid)
                            .execute(&db)
                            .await?;
                    }
                    return Ok(true);
                }
            }
            if write && status {
                let mut tx = db.begin().await?;
                if par2_snapshot_matches(&mut tx, &release).await?
                    && queries::hash_evidence_generation(&mut tx, release.id).await? == strong.generation
                {
                    defer_par2_hash(&mut tx, &release, &guid, &strong.generation, retry.is_some()).await?;
                }
                tx.commit().await?;
            }
            return Ok(false);
        }
        tx.commit().await?;
        for hash in legacy {
            if hash.is_empty() {
                continue;
            }
            let list = legacy_donors.get(hash).map_or(&[][..], Vec::as_slice);
            if self.apply_donor_match(&release, list, 5, "hashCheck: PAR2 hash_16K", "PAR2 hash, ", write, status).await? {
                return Ok(true);
            }
        }
        if write && status {
            let mut update = sqlx::QueryBuilder::<sqlx::MySql>::new(
                "UPDATE releases SET proc_hash16k = 1 WHERE predb_id = 0 \
                 AND NOT EXISTS (SELECT 1 FROM par_file_hashes WHERE par_file_hashes.releases_id = releases.id) AND",
            );
            let mut plain = release.clone();
            plain.observed.par2_hash_generation = None;
            plain.push_snapshot(&mut update);
            update.build().execute(&db).await?;
        }
        Ok(false)
    }

    #[allow(clippy::too_many_arguments)]
    async fn apply_donor_match(
        &mut self,
        release: &Release,
        donors: &[Donor],
        tolerance: i64,
        method: &str,
        kind: &str,
        write: bool,
        status: bool,
    ) -> Result<bool> {
        let Some(chosen) = donor::select(donors, release.relsize, tolerance) else {
            return Ok(false);
        };
        let current = release.searchname.as_deref().unwrap_or("");
        if exact_value(&chosen.searchname) == exact_value(current) {
            if write {
                self.updater.attach_predb_snapshot(release, chosen.predb_id as i64, None, None, None).await?;
            }
            return Ok(self.updater.matched);
        }
        self.updater
            .update_release(release, &chosen.searchname, method, write, kind, status, chosen.predb_id as i64, None, None)
            .await?;
        Ok(self.updater.matched)
    }

    /// Check an SRR or SRS file for a release name.
    async fn srr_name_check(&mut self, release: &Release, text: &str, write: bool, kind: &str, status: bool) -> Result<bool> {
        let lower = text.to_ascii_lowercase();
        let extracted = if !text.contains('\n') && text.len() > 4 && (lower.ends_with(".srr") || lower.ends_with(".srs")) {
            &text[..text.len() - 4]
        } else {
            return Ok(false);
        };
        let name = nntmux_predb::filename::extract_filename_from_path(extracted);
        if predb_regex().is_match(name) {
            self.updater.update_release(release, name, "fileCheck: SRR extension", write, kind, status, 0, None, None).await?;
            return Ok(self.updater.matched);
        }
        Ok(false)
    }

    /// Freezes NFO provenance before lookup, extraction, naming or completion.
    #[allow(clippy::too_many_arguments)]
    async fn process_nfo(
        &mut self,
        release: &Release,
        text: &str,
        write: bool,
        status: bool,
        allow_extraction: bool,
        allow_pattern_fallback: bool,
        mark_processed: bool,
    ) -> Result<bool> {
        self.updater.reset();
        if !nntmux_categorize::ms!(text, r"^=newz\[NZB\]=\w+") {
            let view = extractors::predb_candidates(text);
            if !view.complete {
                return Ok(false);
            }
            let rows = literal_titles(&self.db(), &view.titles).await?;
            if !rows.is_empty() {
                let expected: Vec<String> = view.titles.iter().map(|t| exact_value(t)).collect();
                let mut identities: Vec<(i64, String)> = Vec::new();
                for (id, title) in &rows {
                    let identity = exact_value(title);
                    if *id <= 0 || !valid_catalogue_title(title) || !expected.contains(&identity) {
                        return Ok(false);
                    }
                    if !identities.iter().any(|(known, value)| known == id && *value == identity) {
                        identities.push((*id, identity));
                    }
                }
                if rows.len() == 101 || identities.len() != 1 {
                    return Ok(false);
                }
                let (id, title) = &rows[0];
                self.updater.update_release(release, title, "preDB: NFO literal match", write, "NFO, ", status, *id, None, None).await?;
                return Ok(self.updater.matched);
            }
            if allow_extraction {
                if let Some(result) = extractors::extract_from_nfo(text) {
                    let method = format!("nfoCheck: {}", result.method);
                    self.updater.update_release(release, &result.new_name, &method, write, "NFO, ", status, 0, None, None).await?;
                }
                if !self.updater.matched && allow_pattern_fallback {
                    let identity = extractors::identity_text(text);
                    if let Some(result) = checkers::check(&identity) {
                        let method = result.formatted_method();
                        self.updater.update_release(release, &result.new_name, &method, write, "NFO, ", status, 0, None, None).await?;
                    }
                }
            }
        }
        if !self.updater.matched && mark_processed && write && status {
            self.updater.update_snapshot_column(release, "proc_nfo", 1).await?;
        }
        Ok(self.updater.matched)
    }

    async fn process_file_candidates(
        &mut self,
        release: &Release,
        rows: &[FileRow],
        write: bool,
        status: bool,
        increment_checked: bool,
    ) -> Result<()> {
        self.updater.reset();
        if increment_checked {
            self.updater.increment_checked();
        }
        let filenames = group_files(rows);
        let prioritized = files::prioritize_for_matching(&filenames);
        // No updater call may occur until every prioritized observation is resolved.
        let pass = collect_pass(&self.db(), &prioritized, 0).await;
        let mut guard = FilenameGuard::new(release.id, &pass, 0, filenames.clone());
        for (filename, hit) in pass.unique_matches() {
            self.apply_filename_match(release, &filename, &hit, write, "Filenames, ", status, &mut guard).await?;
            if self.updater.matched {
                break;
            }
        }
        let all_complete = pass.complete() && !pass.has_matches();
        if !self.updater.matched && all_complete {
            for lookup in pass.lookups.iter().filter(|lookup| !lookup.evidence) {
                if let Some(result) = extractors::extract_from_file(&lookup.filename) {
                    let method = format!("fileCheck: {}", result.method);
                    self.updater
                        .update_release(release, &result.new_name, &method, write, "Filenames, ", status, 0, None, Some(&mut guard))
                        .await?;
                }
                if self.updater.matched {
                    break;
                }
            }
        }
        if !self.updater.matched && all_complete {
            self.complete_filename_pass(release, &filenames, write, status, &mut guard, &pass).await?;
        }
        Ok(())
    }

    async fn complete_filename_pass(
        &self,
        release: &Release,
        filenames: &[String],
        write: bool,
        status: bool,
        guard: &mut FilenameGuard,
        pass: &FilenamePass,
    ) -> Result<()> {
        if !write || !status {
            return Ok(());
        }
        let sorted = |mut names: Vec<String>| {
            names.sort();
            names.dedup();
            names
        };
        if sorted(pass.filenames()) != sorted(filenames.to_vec()) {
            return Ok(());
        }
        let mut release = release.clone();
        release.observed.isrenamed = Some(release.isrenamed);
        self.updater.complete_filename_snapshot(&release, guard).await?;
        Ok(())
    }

    #[allow(clippy::too_many_arguments)]
    async fn apply_filename_match(
        &mut self,
        release: &Release,
        filename: &str,
        hit: &PredbHit,
        write: bool,
        kind: &str,
        status: bool,
        guard: &mut FilenameGuard,
    ) -> Result<bool> {
        if hit.id <= 0 {
            return Ok(false);
        }
        if !write && self.updater.current_predb_title(hit.id, &hit.title, Some(filename)).await?.is_none() {
            return Ok(false);
        }
        if exact_value(&hit.title) == exact_value(release.searchname.as_deref().unwrap_or("")) {
            if write {
                self.updater.attach_predb_snapshot(release, hit.id, Some(&hit.title), Some(filename), Some(guard)).await?;
            }
            return Ok(self.updater.matched);
        }
        self.updater
            .update_release(release, &hit.title, "PreDb: Filename match", write, kind, status, hit.id, Some(filename), Some(guard))
            .await?;
        Ok(self.updater.matched)
    }

    /// Fix XXX release names using specific file names.
    pub async fn fix_xxx_names_with_files(&mut self, time: i32, write: bool, categories: i32, status: bool, show: bool) -> Result<()> {
        println!("Fixing search names {} using file names.", window(time));
        let kind = "Filenames, ";
        if !self.start_batch(Source::Xxx, time, categories, " xxx file names to process.").await? {
            return Ok(());
        }
        let mut after = 0;
        loop {
            let releases = self.next_batch(Source::Xxx, time, categories, after).await?;
            let Some(last) = releases.last().map(|r| r.id) else {
                break;
            };
            let ids: Vec<i64> = releases.iter().map(|r| r.id).collect();
            let rows = queries::file_rows(&self.db(), &ids, Source::Files).await?;
            for release in &releases {
                self.updater.reset();
                self.updater.increment_checked();
                let filenames = group_files(rows.get(&release.id).map_or(&[][..], Vec::as_slice));
                let pass = collect_pass(&self.db(), &filenames, 0).await;
                let mut guard = FilenameGuard::new(release.id, &pass, 0, filenames.clone());
                for (filename, hit) in pass.unique_matches() {
                    self.apply_filename_match(release, &filename, &hit, write, kind, status, &mut guard).await?;
                    if self.updater.matched {
                        break;
                    }
                }
                let all_complete = pass.complete() && !pass.has_matches();
                if !self.updater.matched && all_complete {
                    for lookup in pass.lookups.iter().filter(|lookup| !lookup.evidence) {
                        if let Some(caps) = nntmux_categorize::rx!(i r"^.+?SDPORN").find(lookup.filename.as_bytes()) {
                            let name = String::from_utf8_lossy(caps.as_bytes()).into_owned();
                            self.updater
                                .update_release(release, &name, "fileCheck: XXX SDPORN", write, kind, status, 0, None, Some(&mut guard))
                                .await?;
                            if self.updater.matched {
                                break;
                            }
                        }
                    }
                }
                if !self.updater.matched && all_complete {
                    self.complete_filename_pass(release, &filenames, write, status, &mut guard, &pass).await?;
                }
                self.progress(show);
            }
            after = last;
            if !self.batch_full(releases.len()) {
                break;
            }
        }
        self.found(write, " files");
        Ok(())
    }

    pub async fn fix_names_with_media_movie_name(&mut self, time: i32, write: bool, categories: i32, status: bool, show: bool) -> Result<()> {
        println!("Fixing search names {} using Mediainfo movie_name.", window(time));
        if !self.start_batch(Source::MediaMovie, time, categories, " mediainfo movie names to process.").await? {
            return Ok(());
        }
        let mut after = 0;
        loop {
            let releases = self.next_batch(Source::MediaMovie, time, categories, after).await?;
            let Some(last) = releases.last().map(|r| r.id) else {
                break;
            };
            let ids: Vec<i64> = releases.iter().map(|r| r.id).collect();
            let media = queries::media_rows(&self.db(), &ids).await?;
            for release in &releases {
                self.updater.increment_checked();
                self.updater.reset();
                for row in media.get(&release.id).map_or(&[][..], Vec::as_slice) {
                    if self.media_movie_name_check(release, row, write, "Mediainfo, ", status).await? {
                        break;
                    }
                }
                if !self.updater.matched {
                    self.mark_processed(write, status, "proc_uid", release.id).await?;
                }
                self.progress(show);
            }
            after = last;
            if !self.batch_full(releases.len()) {
                break;
            }
        }
        self.found(write, " MediaInfo's");
        Ok(())
    }

    async fn media_movie_name_check(&mut self, release: &Release, row: &MediaRow, write: bool, kind: &str, status: bool) -> Result<bool> {
        let Some(movie) = row.movie_name.as_deref().filter(|m| !m.is_empty() && *m != "0") else {
            return Ok(false);
        };
        let new_name = if let Some(hit) = predb_regex().find(movie) {
            hit.as_str().to_owned()
        } else if let Some(caps) = nntmux_categorize::rx!(i r"(.+),(\sRMZ\.cr)?$").captures(movie.as_bytes()) {
            String::from_utf8_lossy(&caps[1]).into_owned()
        } else {
            movie.to_owned()
        };
        if new_name.is_empty() || !valid_catalogue_title(&new_name) {
            return Ok(false);
        }
        let rows: Vec<(i64, String)> = sqlx::query_as("SELECT CAST(id AS SIGNED), title FROM predb WHERE title = ? LIMIT 2")
            .bind(&new_name)
            .fetch_all(&self.db())
            .await?;
        if !rows.is_empty() {
            if rows.len() != 1 {
                return Ok(false);
            }
            let (id, title) = &rows[0];
            if *id <= 0 || exact_value(title) != exact_value(&new_name) {
                return Ok(false);
            }
            let hits = [PredbHit { id: *id, title: title.clone(), filename: String::new(), source: String::new() }];
            let Some(found) = select_best_match(&new_name, &hits, None) else {
                return Ok(false);
            };
            self.updater
                .update_release(release, &found.title, "MediaInfo: Exact PreDB Title", write, kind, status, found.id, None, None)
                .await?;
            return Ok(self.updater.matched);
        }
        self.updater
            .update_release(release, &new_name, "MediaInfo: Movie Name", write, kind, status, release.predb_id, None, None)
            .await?;
        Ok(self.updater.matched)
    }

    /// One GUID-partitioned batch through all standard sources: `(checked, fixed)`.
    pub async fn process_standard_batch(
        &mut self,
        releases: Vec<Release>,
        show: bool,
        par2: &mut dyn Par2Probe,
    ) -> Result<(usize, u64)> {
        if releases.is_empty() {
            return Ok((0, 0));
        }
        let db = self.db();
        let ids: Vec<i64> = releases.iter().map(|r| r.id).collect();
        let nfos = queries::nfo_rows(&db, &ids).await?;
        let files_by_release = queries::file_rows(&db, &ids, Source::Files).await?;
        let media = queries::media_rows(&db, &ids).await?;
        let hashes = queries::legacy_hash_rows(&db, &ids).await?;
        let uid_donors = queries::uid_donors(&db, &distinct(media.values().flatten().filter_map(|r| r.uid.clone()))).await?;
        let crc_donors = queries::crc_donors(&db, &distinct(files_by_release.values().flatten().filter_map(|r| r.crc32.clone()))).await?;
        let hash_donors = queries::hash_donors(&db, &distinct(hashes.values().flatten().cloned())).await?;
        let fixed_before = self.updater.fixed;

        for release in &releases {
            self.updater.increment_checked();
            self.updater.reset();
            let release_media = media.get(&release.id).map_or(&[][..], Vec::as_slice);
            let release_files = files_by_release.get(&release.id).map_or(&[][..], Vec::as_slice);

            if release.proc_uid == 0 {
                self.updater.reset();
                for row in release_media {
                    let uid = row.uid.clone().unwrap_or_default();
                    if uid.is_empty() {
                        continue;
                    }
                    let list = uid_donors.get(&uid).map_or(&[][..], Vec::as_slice);
                    if self.apply_donor_match(release, list, 10, "uidCheck: Unique_ID", "UID, ", true, true).await? {
                        break;
                    }
                }
                if !self.updater.matched {
                    for row in release_media {
                        if self.media_movie_name_check(release, row, true, "Mediainfo, ", true).await? {
                            break;
                        }
                    }
                }
                if !self.updater.matched {
                    self.updater.update_single_column("proc_uid", 1, release.id).await?;
                }
            }
            if self.updater.matched {
                continue;
            }
            if release.proc_crc32 == 0 {
                self.updater.reset();
                self.crc_pass(release, release_files, &crc_donors, true, true).await?;
                if !self.updater.matched {
                    self.updater.update_single_column("proc_crc32", 1, release.id).await?;
                }
            }
            if self.updater.matched {
                continue;
            }
            if release.proc_srr == 0 {
                self.updater.reset();
                for row in release_files {
                    if self.srr_name_check(release, &row.name, true, "SRR, ", true).await? {
                        break;
                    }
                }
                if !self.updater.matched {
                    self.updater.update_single_column("proc_srr", 1, release.id).await?;
                }
            }
            if self.updater.matched {
                continue;
            }
            if release.proc_hash16k == 0 {
                self.updater.reset();
                let rows = hashes.get(&release.id).map_or(&[][..], Vec::as_slice);
                self.process_par2_hash(release, rows, &hash_donors, true, true).await?;
            }
            if self.updater.matched {
                continue;
            }
            if release.proc_nfo == 0 {
                let text = nfos.get(&release.id).cloned().unwrap_or_default();
                self.process_nfo(release, &text, true, true, true, true, true).await?;
            }
            if self.updater.matched {
                continue;
            }
            if release.proc_files == 0 {
                self.process_file_candidates(release, release_files, true, true, false).await?;
            }
            if self.updater.matched {
                continue;
            }
            if release.proc_par2 == 0 {
                self.updater.reset();
                match par2.probe(release).await? {
                    Par2Outcome::Renamed => self.updater.fixed += 1,
                    Par2Outcome::NoMatch => self.updater.update_single_column("proc_par2", 1, release.id).await?,
                    Par2Outcome::Retryable | Par2Outcome::Deferred => {}
                }
            }
        }
        if show {
            println!("Processed {} releases", releases.len());
        }
        Ok((releases.len(), self.updater.fixed - fixed_before))
    }

    /// Matches one PreDB title against release candidates. Successful writes stay separate from
    /// current canonical matches and unfinished candidates.
    pub async fn match_predb_fulltext(
        &mut self,
        pre: &PredbTitle,
        search: &dyn CandidateSearch,
    ) -> Result<FulltextOutcome> {
        let title = pre.title.as_str();
        let unmatched = FulltextOutcome { updated: 0, matched: false, retry: false };
        static SHAPE: std::sync::LazyLock<regex::Regex> = std::sync::LazyLock::new(|| {
            regex::Regex::new(r"([\w()]+[\s._-]){2,}[\w()]+-\w+").expect("static pattern")
        });
        if title.chars().count() > 255 || title.chars().any(char::is_control) || title.len() < 15 || !SHAPE.is_match(title) {
            return Ok(unmatched);
        }
        let predb_id = pre.predb_id as i64;
        let mut matched = false;
        let matches = self.paged_literal_candidates(title, predb_id, &mut matched, search).await?;
        if matches.len() >= 16 {
            let (link_matched, link_retry) =
                if matched { (true, false) } else { self.predb_link_completion(predb_id, title).await? };
            return Ok(FulltextOutcome { updated: -1, matched: link_matched, retry: link_retry });
        }
        let mut matching = 0;
        let mut retry = false;
        for release in &matches {
            if self.updater.update_predb_candidate(release, title, predb_id, &pre.source).await? {
                matching += 1;
                matched = true;
                continue;
            }
            let current: Option<(i64, Option<String>, Option<String>)> =
                sqlx::query_as("SELECT CAST(predb_id AS SIGNED), name, searchname FROM releases WHERE id = ?")
                    .bind(release.id)
                    .fetch_optional(&self.db())
                    .await?;
            let Some((current_predb, name, searchname)) = current else {
                continue;
            };
            let name = name.unwrap_or_default();
            let searchname = searchname.unwrap_or_default();
            if current_predb == predb_id && exact_value(&searchname) == exact_value(title) {
                matched = true;
            } else if current_predb == 0
                && (has_literal_predb_title(&name, title) || has_literal_predb_title(&searchname, title))
            {
                retry = true;
            }
        }
        if !matched {
            let (link_matched, link_retry) = self.predb_link_completion(predb_id, title).await?;
            matched = link_matched;
            retry = retry || link_retry;
        }
        Ok(FulltextOutcome { updated: matching, matched, retry })
    }

    async fn predb_link_completion(&self, predb_id: i64, title: &str) -> Result<(bool, bool)> {
        let links = queries::existing_predb_links(&self.db(), predb_id).await?;
        let identity = exact_value(title);
        if links.iter().any(|name| exact_value(name.as_deref().unwrap_or("")) == identity) {
            return Ok((true, false));
        }
        Ok((false, links.len() >= 17))
    }

    async fn paged_literal_candidates(
        &self,
        title: &str,
        predb_id: i64,
        matched: &mut bool,
        search: &dyn CandidateSearch,
    ) -> Result<Vec<Release>> {
        let mut matches: BTreeMap<i64, Release> = BTreeMap::new();
        let mut after = 0;
        for _ in 0..PREDB_CANDIDATE_MAX_PAGES {
            let page = search.release_candidates_page(title, PREDB_CANDIDATE_PAGE_SIZE, after).await?;
            if page.ids.len() as u64 > PREDB_CANDIDATE_PAGE_SIZE
                || (page.has_more && (page.ids.len() as u64) < PREDB_CANDIDATE_PAGE_SIZE)
            {
                return Err(IncompleteMatch("PreDB fulltext matching is incomplete: invalid candidate page boundaries.".into()).into());
            }
            let mut last = after;
            for id in &page.ids {
                if *id <= last {
                    return Err(IncompleteMatch("PreDB fulltext matching is incomplete: candidate IDs did not advance in order.".into()).into());
                }
                last = *id;
            }
            for release in queries::confirm_predb_candidates(&self.db(), &page.ids, title).await? {
                if has_literal_predb_title(release.name.as_deref().unwrap_or(""), title)
                    || has_literal_predb_title(release.searchname.as_deref().unwrap_or(""), title)
                {
                    matches.insert(release.id, release);
                }
            }
            if !*matched {
                let identity = exact_value(title);
                *matched = queries::linked_predb_candidates(&self.db(), &page.ids, predb_id)
                    .await?
                    .iter()
                    .any(|name| exact_value(name.as_deref().unwrap_or("")) == identity);
            }
            if matches.len() >= 16 || !page.has_more {
                return Ok(matches.into_values().collect());
            }
            after = last;
        }
        Err(IncompleteMatch("PreDB fulltext matching is incomplete: the 210-candidate scan budget was reached.".into()).into())
    }

    /// `checkName`: for other flows that hold one text. Returns whether it matched.
    #[allow(clippy::too_many_arguments)]
    pub async fn check_name(
        &mut self,
        release: &Release,
        text: &str,
        write: bool,
        kind: &str,
        status: bool,
        pre_id_only: bool,
        mark_processed: bool,
    ) -> Result<bool> {
        if kind == "NFO, " {
            return self.process_nfo(release, text, write, status, !pre_id_only, false, mark_processed && !pre_id_only).await;
        }
        if kind == "Filenames, " {
            return self.check_filename(release, text, write, status, pre_id_only, mark_processed).await;
        }
        if let Some((title, id)) = self.updater.check_predb_match(text).await? {
            if exact_value(&title) == exact_value(release.searchname.as_deref().unwrap_or("")) {
                if write {
                    self.updater.attach_predb_snapshot(release, id, None, None, None).await?;
                }
            } else {
                self.updater.update_release(release, &title, "preDB: Match", write, kind, status, id, None, None).await?;
            }
            return Ok(self.updater.matched);
        }
        if pre_id_only {
            return Ok(self.updater.matched);
        }
        if kind == "PAR2, " {
            if let Some(result) = extractors::extract_from_file(text) {
                let method = format!("fileCheck: {}", result.method);
                self.updater.update_release(release, &result.new_name, &method, write, kind, status, 0, None, None).await?;
            }
        } else if let Some(result) = checkers::check(text) {
            let method = result.formatted_method();
            self.updater.update_release(release, &result.new_name, &method, write, kind, status, 0, None, None).await?;
        }
        if write && status && mark_processed && !self.updater.matched {
            let column = match kind {
                "PAR2, " => Some("proc_par2"),
                "PAR2 hash, " => Some("proc_hash16k"),
                "SRR, " => Some("proc_srr"),
                "UID, " | "Mediainfo, " => Some("proc_uid"),
                "CRC32, " => Some("proc_crc32"),
                _ => None,
            };
            if let Some(column) = column {
                self.updater.update_single_column(column, 1, release.id).await?;
            }
        }
        Ok(self.updater.matched)
    }

    async fn check_filename(
        &mut self,
        release: &Release,
        text: &str,
        write: bool,
        status: bool,
        pre_id_only: bool,
        mark_processed: bool,
    ) -> Result<bool> {
        let kind = "Filenames, ";
        self.updater.reset();
        let inventory: Vec<String> = sqlx::query_scalar("SELECT name FROM release_files WHERE releases_id = ?")
            .bind(release.id)
            .fetch_all(&self.db())
            .await?;
        let pass = collect_pass(&self.db(), &[text.to_owned()], 0).await;
        let mut guard = FilenameGuard::new(release.id, &pass, 0, inventory.clone());
        let had_evidence = pass.lookups.first().is_none_or(|lookup| lookup.evidence);
        let mut complete = pass.complete();
        if let Some((filename, hit)) = pass.unique_matches().into_iter().next() {
            let matched = self.apply_filename_match(release, &filename, &hit, write, kind, status, &mut guard).await?;
            if write && !matched {
                complete = false;
            }
        }
        if !pre_id_only && !self.updater.matched && !had_evidence
            && let Some(result) = extractors::extract_from_file(text)
        {
            let method = format!("fileCheck: {}", result.method);
            self.updater.update_release(release, &result.new_name, &method, write, kind, status, 0, None, Some(&mut guard)).await?;
        }
        if !pre_id_only && write && status && mark_processed && complete && !self.updater.matched {
            self.complete_filename_pass(release, &inventory, write, status, &mut guard, &pass).await?;
        }
        Ok(self.updater.matched)
    }

    /// Matches independent filename observations without serializing their boundaries.
    pub async fn match_predb_file_names(
        &mut self,
        release: &Release,
        files_list: &[String],
        write: bool,
        status: bool,
        persisted: Option<Vec<String>>,
    ) -> Result<bool> {
        self.updater.reset_archive_naming_deferral();
        let inventory = match persisted {
            Some(names) => names,
            None => {
                sqlx::query_scalar("SELECT name FROM release_files WHERE releases_id = ?")
                    .bind(release.id)
                    .fetch_all(&self.db())
                    .await?
            }
        };
        let pass = collect_pass(&self.db(), &files::prioritize_for_predb(files_list), 8).await;
        let mut guard = FilenameGuard::new(release.id, &pass, 8, inventory);
        for (filename, hit) in pass.unique_matches() {
            let same = exact_value(&hit.title) == exact_value(release.searchname.as_deref().unwrap_or(""));
            let has_snapshot = release.guid.is_some() && release.name.is_some();
            if same && write {
                if !self.updater.attach_predb_snapshot(release, hit.id, Some(&hit.title), Some(&filename), Some(&mut guard)).await? {
                    continue;
                }
            } else if !write && !has_snapshot {
                if self.updater.current_predb_title(hit.id, &hit.title, Some(&filename)).await?.is_none() {
                    continue;
                }
                if !same {
                    self.updater
                        .update_release(release, &hit.title, "file matched source: ", false, "PreDB file match, ", status, hit.id, Some(&filename), Some(&mut guard))
                        .await?;
                    if !self.updater.matched {
                        continue;
                    }
                }
            } else {
                self.updater
                    .update_release(release, &hit.title, "file matched source: ", write, "PreDB file match, ", status, hit.id, Some(&filename), Some(&mut guard))
                    .await?;
                if !self.updater.matched {
                    continue;
                }
            }
            return Ok(true);
        }
        Ok(false)
    }

    /// `match:prefiles`: matches release file names to PreDB filenames.
    pub async fn match_prefiles(&mut self, limit: Option<i64>, show: bool) -> Result<()> {
        let requested = limit.map_or(i64::MAX, |limit| limit.max(1));
        println!("Matching predb filename to cleaned release_files.name.");
        let available = queries::count_prefile_candidates(&self.db()).await?;
        let total = available.min(requested);
        if total == 0 {
            println!("Nothing to do.");
            return Ok(());
        }
        println!("{total} releases to process.");
        let started = std::time::Instant::now();
        let (mut counter, mut counted) = (0i64, 0i64);
        let limited = limit.is_some();
        let mut cursor = if limited { i64::MAX } else { 0 };
        while counter < total {
            let batch_limit = (queries::BATCH_SIZE as i64).min(total - counter) as usize;
            let batch = queries::prefile_candidate_batch(&self.db(), cursor, batch_limit, limited).await?;
            let Some(last) = batch.last().map(|r| r.id) else {
                break;
            };
            let ids: Vec<i64> = batch.iter().map(|r| r.id).collect();
            let rows = queries::file_rows(&self.db(), &ids, Source::Files).await?;
            for release in &batch {
                let mut names: Vec<String> =
                    rows.get(&release.id).map_or(&[][..], Vec::as_slice).iter().map(|row| row.name.clone()).collect();
                names.sort_by_key(|name| std::cmp::Reverse(name.len()));
                if self.match_predb_file_names(release, &names, true, true, Some(names.clone())).await? {
                    counted += 1;
                }
                counter += 1;
                if !show {
                    println!("Renamed Releases: [{counted}] {counter}/{total}");
                }
            }
            cursor = last;
        }
        println!("Renamed {counted} releases in {} seconds.", started.elapsed().as_secs());
        Ok(())
    }

    fn progress(&self, show: bool) {
        let checked = self.updater.checked as i64;
        if checked % 500 == 0 && checked > 0 {
            println!("{checked} files processed.");
        }
        if show && self.total > 0 {
            let interval = (self.total / 10).max(1);
            if checked % interval == 0 || checked == self.total {
                println!(
                    "Renamed: {} | Processed: {checked}/{} ({:.1}%)",
                    self.updater.fixed,
                    self.total,
                    checked as f64 / self.total as f64 * 100.0
                );
            }
        }
    }
}

fn window(time: i32) -> &'static str {
    if time == 1 { "in the past 6 hours" } else { "since the beginning" }
}

async fn literal_titles(db: &sqlx::MySqlPool, titles: &[String]) -> Result<Vec<(i64, String)>> {
    if titles.is_empty() {
        return Ok(Vec::new());
    }
    let mut query = sqlx::QueryBuilder::<sqlx::MySql>::new("SELECT CAST(id AS SIGNED), title FROM predb WHERE title IN (");
    let mut separated = query.separated(", ");
    for title in titles {
        separated.push_bind(title.clone());
    }
    separated.push_unseparated(") LIMIT 101");
    Ok(query.build_query_as().fetch_all(db).await?)
}

async fn par2_snapshot_matches(conn: &mut MySqlConnection, release: &Release) -> Result<bool> {
    let mut plain = release.clone();
    plain.observed.par2_hash_generation = None;
    let Some(current) = lock_snapshot(conn, &plain).await? else {
        return Ok(false);
    };
    Ok(current.predb_id == 0 && current.isrenamed == release.isrenamed)
}

async fn defer_par2_hash(
    conn: &mut MySqlConnection,
    release: &Release,
    guid: &str,
    generation: &str,
    exists: bool,
) -> Result<()> {
    let (last, count) = parse_generation(generation).ok_or_else(|| anyhow!("malformed hash generation"))?;
    // Only while the release and its hash evidence are what was examined.
    let current: (i64, i64) = sqlx::query_as(
        "SELECT COALESCE(CAST(MAX(id) AS SIGNED), 0), COUNT(*) FROM par_file_hashes WHERE releases_id = ?",
    )
    .bind(release.id)
    .fetch_one(&mut *conn)
    .await?;
    if current != (last, count) {
        return Ok(());
    }
    let sql = if exists {
        "UPDATE par2_hash_retry SET release_guid = ?, retry_at = NOW() + INTERVAL ? SECOND WHERE releases_id = ?"
    } else {
        "INSERT IGNORE INTO par2_hash_retry (release_guid, retry_at, releases_id) VALUES (?, NOW() + INTERVAL ? SECOND, ?)"
    };
    sqlx::query(sql)
        .bind(guid)
        .bind(HASH_RETRY_DELAY_SECONDS)
        .bind(release.id)
        .execute(&mut *conn)
        .await?;
    Ok(())
}

/// SQL LIKE only discovers candidates. Confirms an intact literal title, optionally followed by
/// known filename suffixes, inside explicit subject delimiters. Never strips title text.
pub fn has_literal_predb_title(subject: &str, title: &str) -> bool {
    let literal = exact_value(title);
    let subject = exact_value(subject);
    if subject == literal {
        return true;
    }
    let opening = r#"(?:[\s\[({]|(?<![\p{L}\p{N}._-])(?:#34;|["']))"#;
    let closing = r#"(?:[\s\])}]|(?:#34;|["'])(?![\p{L}\p{N}._-]))"#;
    let pattern = format!(
        r#"~(?:^|{opening})({}(?:(?!#34;)[^\s"'\[\](){{}}])*)(?=$|{closing})~u"#,
        preg_quote(&literal)
    );
    let Ok(re) = Pcre::new(&pattern) else {
        return false;
    };
    re.captures_all(subject.as_bytes()).into_iter().any(|m| {
        m.group(1).is_some_and(|candidate| {
            candidate.len() >= literal.len()
                && std::str::from_utf8(&candidate[literal.len()..]).is_ok_and(is_known_file_suffix)
        })
    })
}

fn preg_quote(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    for c in value.chars() {
        if ".\\+*?[^]$(){}=!<>|:-#~".contains(c) {
            out.push('\\');
        }
        out.push(c);
    }
    out
}
