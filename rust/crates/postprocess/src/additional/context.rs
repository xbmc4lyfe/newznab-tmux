//! `ReleaseProcessingContext`: what one release's additional processing has found so far, and the
//! evidence it may write back when it finishes.
//!
//! PHP built a producer-naming claim from `additional_pp_claimed_at` when the release row carried
//! it, but the batch claim never selects that column, so the claim was always absent and every
//! branch on it was dead. It is left out here, with the `producerNamingAttempted` flag that only
//! mattered alongside it. The archived media slots belong to media extraction and arrive with it.

use std::collections::{HashMap, HashSet};
use std::sync::Arc;
use std::time::{Duration, Instant};

use nntmux_namefix::release::Snapshot;
use nntmux_namefix::update::CommittedIdentity;

use super::archive_extraction::ArchiveFlags;
use super::candidates::ClaimedRelease;
use super::media_source::{MediaByteBudget, MediaSourcePlan};
use super::nzb_contents::SourceFile;
use super::planner::{NzbFile, WorkPlan};
use crate::archive_info::ListEntry;

const MAX_RETAINED_ARCHIVES: usize = 2;
const MAX_RETAINED_ARCHIVE_BYTES: usize = 16_777_216;
const MAX_ARCHIVE_FILE_NAMES: usize = 256;
const MAX_NAMING_WITNESS_BYTES: usize = 32_768;
/// Longest name kept as a naming witness.
const MAX_WITNESS_NAME_BYTES: usize = 4096;

/// A downloaded archive kept for a later look, such as a nested NFO.
#[derive(Clone, Debug)]
pub struct DownloadedArchive {
    pub title: String,
    pub data: Arc<Vec<u8>>,
    pub files: Vec<ListEntry>,
}

/// The release identity full-PAR2 evidence was gathered against, with the claim that gathered it.
#[derive(Clone, Debug, PartialEq)]
pub struct EvidenceSnapshot {
    pub identity: Snapshot,
    pub claim_token: Option<String>,
}

/// A row for `release_files`, queued until finalization writes them all at once.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct PendingReleaseFile {
    pub name: String,
    pub size: i64,
    /// `Y-m-d H:i:s`.
    pub created_at: String,
    /// Unix seconds, as PHP wrote `updated_at`.
    pub updated_at: i64,
    pub passworded: i64,
    pub crc32: String,
}

/// One file's full MD5 and 16 KiB hash from a PAR2 descriptor, with its size when known.
#[derive(Clone, Debug, PartialEq, Eq, Hash)]
pub struct FullParHash {
    pub full_md5: String,
    pub hash16k: String,
    pub size: Option<String>,
}

/// A map that keeps its first insertion order, as a PHP array keyed by name does.
#[derive(Clone, Debug)]
pub struct Ordered<V> {
    keys: Vec<String>,
    values: HashMap<String, V>,
}

impl<V> Default for Ordered<V> {
    fn default() -> Self {
        Self {
            keys: Vec::new(),
            values: HashMap::new(),
        }
    }
}

impl<V> Ordered<V> {
    pub fn contains(&self, key: &str) -> bool {
        self.values.contains_key(key)
    }

    /// Replaces the value in place, or appends a new key.
    pub fn insert(&mut self, key: String, value: V) {
        if !self.values.contains_key(&key) {
            self.keys.push(key.clone());
        }
        self.values.insert(key, value);
    }

    pub fn len(&self) -> usize {
        self.keys.len()
    }

    pub fn is_empty(&self) -> bool {
        self.keys.is_empty()
    }

    pub fn keys(&self) -> &[String] {
        &self.keys
    }

    pub fn values(&self) -> impl Iterator<Item = &V> {
        self.keys.iter().filter_map(|key| self.values.get(key))
    }

    pub fn clear(&mut self) {
        self.keys.clear();
        self.values.clear();
    }
}

/// The state of one release's additional processing.
#[derive(Debug)]
pub struct ReleaseContext {
    pub release: ClaimedRelease,

    pub found_video: bool,
    pub found_media_info: bool,
    pub found_audio_info: bool,
    pub found_audio_sample: bool,
    pub found_jpg_sample: bool,
    pub found_sample: bool,
    pub found_par2_info: bool,

    pub password_status: i8,
    pub release_has_password: bool,
    /// A cut-off listing can't rule out an encrypted entry past the cut.
    pub archive_listing_incomplete: bool,

    pub release_has_no_nfo: bool,
    pub release_group_name: String,
    pub group_unavailable: bool,

    pub nzb_has_compressed_file: bool,
    pub nzb_source_digest: Option<String>,
    /// The NZB's full file inventory. Never proof on its own.
    pub nzb_source_files: Option<Vec<SourceFile>>,
    /// Complete archive listings, keyed by the SHA-256 of their first message ID, with the NZB
    /// digest they were read under.
    pub archive_source_witnesses: HashMap<String, String>,
    /// Directly posted videos the NZB nominates. Never proof on its own.
    pub direct_video_files: Option<Vec<SourceFile>>,
    /// First articles actually seen for direct videos, keyed by the SHA-256 of the message ID.
    pub direct_video_witnesses: HashMap<String, DirectVideoWitness>,
    pub archive_naming_inspection_complete: bool,
    /// Exact filenames seen in this attempt, in the order seen.
    archive_naming_witnesses: Vec<String>,
    witness_set: HashSet<String>,
    /// Filenames tried for naming; true only when archive proof deferred the episode name.
    pub archive_filename_naming_attempts: HashMap<String, bool>,
    pub naming_witnesses_omitted: bool,
    naming_witness_bytes: usize,
    pub archive_inspection_attempted: bool,
    pub archive_listing_undecodable: bool,
    pub archive_retryable: bool,
    /// Volume sets found to hold media rather than an archive listing.
    pub media_volume_sets: HashSet<String>,
    /// Archive data turned up outside the planned candidates, as from the probe.
    pub unplanned_archive_seen: bool,
    pub nzb_contents: Vec<NzbFile>,
    pub work_plan: Option<WorkPlan>,
    pub season_pack: bool,
    pub season_pack_probable: bool,
    pub archive_name_evidence_incomplete: bool,
    pub archive_file_names: Vec<String>,
    pub first_archive_files: Option<Vec<ListEntry>>,
    pub reverse_archive_summary: Option<crate::archive_info::Summary>,
    pub media_plan: Option<MediaSourcePlan>,
    pub media_budget: Option<MediaByteBudget>,
    pub downloaded_archives: Vec<DownloadedArchive>,
    retained_archive_bytes: usize,

    pub sample_message_ids: Vec<String>,
    pub jpg_message_ids: Vec<String>,
    pub media_info_message_ids: Vec<String>,
    pub audio_info_message_ids: Vec<String>,
    pub rar_file_message_ids: Vec<String>,
    pub audio_info_extension: String,

    pub added_file_info: usize,
    pub total_file_info: usize,
    pub compressed_files_checked: u32,
    pub pending_release_files: Ordered<PendingReleaseFile>,
    /// Lowercase 16 KiB hashes for `par_hashes`.
    pub pending_par_hashes: Ordered<()>,
    pub pending_full_par_hashes: Ordered<FullParHash>,
    /// The identity this attempt started from.
    pub par2_evidence_snapshot: EvidenceSnapshot,
    pub full_par2_evidence_deferred: bool,
    pub producer_naming_generation: u64,
    pub audio_metadata_committed: bool,
    pub audio_metadata_admission_generation: u64,
    current_par2_evidence_snapshot: EvidenceSnapshot,
    pub existing_release_file_names: Option<HashSet<String>>,
    pub release_files_changed: bool,

    pub tmp_path: std::path::PathBuf,
    started: Instant,
}

/// A direct video's first article, as seen.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct DirectVideoWitness {
    pub name: String,
    pub size: i64,
    pub digest: String,
}

impl ReleaseContext {
    pub fn new(release: ClaimedRelease) -> Self {
        let snapshot = EvidenceSnapshot {
            identity: Snapshot {
                id: release.id,
                guid: release.guid.clone(),
                name: Some(release.name.clone()),
                searchname: Some(release.searchname.clone()),
            },
            claim_token: release.claim_token.clone(),
        };
        Self {
            release,
            found_video: false,
            found_media_info: false,
            found_audio_info: false,
            found_audio_sample: false,
            found_jpg_sample: false,
            found_sample: false,
            found_par2_info: false,
            password_status: 0,
            release_has_password: false,
            archive_listing_incomplete: false,
            release_has_no_nfo: false,
            release_group_name: String::new(),
            group_unavailable: false,
            nzb_has_compressed_file: false,
            nzb_source_digest: None,
            nzb_source_files: None,
            archive_source_witnesses: HashMap::new(),
            direct_video_files: None,
            direct_video_witnesses: HashMap::new(),
            archive_naming_inspection_complete: false,
            archive_naming_witnesses: Vec::new(),
            witness_set: HashSet::new(),
            archive_filename_naming_attempts: HashMap::new(),
            naming_witnesses_omitted: false,
            naming_witness_bytes: 0,
            archive_inspection_attempted: false,
            archive_listing_undecodable: false,
            archive_retryable: false,
            media_volume_sets: HashSet::new(),
            unplanned_archive_seen: false,
            nzb_contents: Vec::new(),
            work_plan: None,
            season_pack: false,
            season_pack_probable: false,
            archive_name_evidence_incomplete: false,
            archive_file_names: Vec::new(),
            first_archive_files: None,
            reverse_archive_summary: None,
            media_plan: None,
            media_budget: None,
            downloaded_archives: Vec::new(),
            retained_archive_bytes: 0,
            sample_message_ids: Vec::new(),
            jpg_message_ids: Vec::new(),
            media_info_message_ids: Vec::new(),
            audio_info_message_ids: Vec::new(),
            rar_file_message_ids: Vec::new(),
            audio_info_extension: String::new(),
            added_file_info: 0,
            total_file_info: 0,
            compressed_files_checked: 0,
            pending_release_files: Ordered::default(),
            pending_par_hashes: Ordered::default(),
            pending_full_par_hashes: Ordered::default(),
            current_par2_evidence_snapshot: snapshot.clone(),
            par2_evidence_snapshot: snapshot,
            full_par2_evidence_deferred: false,
            producer_naming_generation: 0,
            audio_metadata_committed: false,
            audio_metadata_admission_generation: 0,
            existing_release_file_names: None,
            release_files_changed: false,
            tmp_path: std::path::PathBuf::new(),
            started: Instant::now(),
        }
    }

    /// The identity full-PAR2 evidence is now recorded against, after this attempt's own renames.
    pub fn full_par2_evidence_snapshot(&self) -> &EvidenceSnapshot {
        &self.current_par2_evidence_snapshot
    }

    /// Follows this attempt's own committed rename, so its evidence stays owned. False, changing
    /// nothing, when the rename wasn't from the identity this attempt holds.
    pub fn advance_full_par2_evidence_identity(
        &mut self,
        proof: Option<&CommittedIdentity>,
    ) -> bool {
        let Some(proof) = proof else {
            return false;
        };
        let start = &self.par2_evidence_snapshot.identity;
        if self.release.id != start.id || self.release.guid != start.guid {
            return false;
        }
        let current = &self.current_par2_evidence_snapshot.identity;
        if proof.before != *current {
            return false;
        }
        if proof.after.id != start.id || proof.after.guid != start.guid {
            return false;
        }
        self.current_par2_evidence_snapshot = EvidenceSnapshot {
            identity: proof.after.clone(),
            claim_token: self.par2_evidence_snapshot.claim_token.clone(),
        };
        self.release.name = proof.after.name.clone().unwrap_or_default();
        self.release.searchname = proof.after.searchname.clone().unwrap_or_default();
        true
    }

    /// `initializeFromConfig`: what isn't wanted counts as found.
    pub fn initialize_from_config(
        &mut self,
        process_video: bool,
        process_media_info: bool,
        process_audio_info: bool,
        process_audio_sample: bool,
        process_jpg_sample: bool,
        process_thumbnails: bool,
    ) {
        self.found_video = !process_video;
        self.found_media_info = !process_media_info;
        self.found_audio_info = !process_audio_info;
        self.found_audio_sample = !process_audio_sample;
        self.found_jpg_sample = !process_jpg_sample;
        self.found_sample = !process_thumbnails;
        self.found_par2_info = false;
    }

    pub fn reset_message_ids(&mut self) {
        self.sample_message_ids.clear();
        self.jpg_message_ids.clear();
        self.media_info_message_ids.clear();
        self.audio_info_message_ids.clear();
        self.rar_file_message_ids.clear();
        self.audio_info_extension.clear();
    }

    pub fn reset_counters(&mut self) {
        self.added_file_info = 0;
        self.total_file_info = 0;
        self.compressed_files_checked = 0;
        self.pending_release_files.clear();
        self.pending_par_hashes.clear();
        self.pending_full_par_hashes.clear();
        self.full_par2_evidence_deferred = false;
        self.producer_naming_generation += 1;
        self.audio_metadata_committed = false;
        self.audio_metadata_admission_generation += 1;
        self.existing_release_file_names = None;
        self.release_files_changed = false;
    }

    /// Everything one attempt found, so another can start clean.
    pub fn reset(&mut self) {
        self.password_status = 0;
        self.release_has_password = false;
        self.archive_listing_incomplete = false;
        self.nzb_has_compressed_file = false;
        self.archive_inspection_attempted = false;
        self.archive_listing_undecodable = false;
        self.archive_retryable = false;
        self.media_volume_sets.clear();
        self.unplanned_archive_seen = false;
        self.group_unavailable = false;
        self.work_plan = None;
        self.season_pack = false;
        self.season_pack_probable = false;
        self.archive_name_evidence_incomplete = false;
        self.archive_file_names.clear();
        self.archive_naming_witnesses.clear();
        self.witness_set.clear();
        self.archive_filename_naming_attempts.clear();
        self.naming_witness_bytes = 0;
        self.naming_witnesses_omitted = false;
        self.nzb_source_digest = None;
        self.nzb_source_files = None;
        self.archive_source_witnesses.clear();
        self.direct_video_files = None;
        self.direct_video_witnesses.clear();
        self.archive_naming_inspection_complete = false;
        self.first_archive_files = None;
        self.reverse_archive_summary = None;
        self.media_plan = None;
        self.media_budget = None;
        self.release_downloaded_archives();
        self.reset_message_ids();
        self.reset_counters();
    }

    /// The archive flags one inspection sets, merged in.
    pub fn absorb(&mut self, flags: &ArchiveFlags) {
        self.compressed_files_checked += flags.compressed_files_checked;
        self.archive_retryable |= flags.archive_retryable;
        self.archive_listing_undecodable |= flags.archive_listing_undecodable;
        self.archive_listing_incomplete |= flags.archive_listing_incomplete;
    }

    /// Keeps a downloaded archive for later, within two archives and 16 MiB.
    pub fn remember_downloaded_archive(
        &mut self,
        title: String,
        data: Arc<Vec<u8>>,
        files: Vec<ListEntry>,
    ) -> bool {
        let size = data.len();
        if size == 0
            || self.downloaded_archives.len() >= MAX_RETAINED_ARCHIVES
            || self.retained_archive_bytes + size > MAX_RETAINED_ARCHIVE_BYTES
        {
            return false;
        }
        self.downloaded_archives
            .push(DownloadedArchive { title, data, files });
        self.retained_archive_bytes += size;
        true
    }

    /// Records listed names until the witness bounds stop it.
    pub fn remember_archive_file_names(&mut self, files: &[ListEntry]) {
        for entry in files {
            let name = match entry {
                ListEntry::File(file) => file.name.as_slice(),
                _ => &[],
            };
            if !self.remember_naming_witness(name) {
                return;
            }
            let name = String::from_utf8_lossy(name).into_owned();
            if !self.archive_file_names.contains(&name) {
                self.archive_file_names.push(name);
            }
        }
    }

    /// Notes a naming attempt on a name already witnessed.
    pub fn remember_archive_filename_naming_attempt(&mut self, name: &str, proof_deferred: bool) {
        if self.witness_set.contains(name) {
            self.archive_filename_naming_attempts
                .insert(name.to_owned(), proof_deferred);
        }
    }

    /// Records a filename as seen. Once a name can't be kept (empty, too long, not UTF-8, or
    /// past the count or byte bounds), witnessing stops and the evidence counts as incomplete.
    pub fn remember_naming_witness(&mut self, name: &[u8]) -> bool {
        if self.naming_witnesses_omitted {
            return false;
        }
        let text = std::str::from_utf8(name).ok();
        if let Some(text) = text
            && self.witness_set.contains(text)
        {
            return true;
        }
        let Some(text) = text.filter(|text| {
            !text.is_empty()
                && text.len() <= MAX_WITNESS_NAME_BYTES
                && self.archive_naming_witnesses.len() < MAX_ARCHIVE_FILE_NAMES
                && self.naming_witness_bytes + text.len() <= MAX_NAMING_WITNESS_BYTES
        }) else {
            self.naming_witnesses_omitted = true;
            self.archive_name_evidence_incomplete = true;
            return false;
        };
        self.witness_set.insert(text.to_owned());
        self.archive_naming_witnesses.push(text.to_owned());
        self.naming_witness_bytes += text.len();
        true
    }

    /// The witnessed filenames, in the order seen.
    pub fn archive_naming_witnesses(&self) -> &[String] {
        &self.archive_naming_witnesses
    }

    pub fn has_naming_witness(&self, name: &str) -> bool {
        self.witness_set.contains(name)
    }

    /// One inner file names one episode, not a season pack, so hold name checks while a pack is
    /// possible or the evidence incomplete.
    pub fn defers_archive_name_checks(&self) -> bool {
        self.season_pack || self.season_pack_probable || self.archive_name_evidence_incomplete
    }

    pub fn release_downloaded_archives(&mut self) {
        self.downloaded_archives.clear();
        self.retained_archive_bytes = 0;
    }

    pub fn duplicate_message_id_count(&self) -> i64 {
        self.work_plan
            .as_ref()
            .map_or(0, |plan| plan.duplicate_message_id_count)
    }

    pub fn unsupported_reasons(&self) -> &[String] {
        self.work_plan
            .as_ref()
            .map_or(&[], |plan| plan.unsupported_reasons.as_slice())
    }

    /// Whether every planned archive turned out to be media volumes.
    pub fn compressed_files_are_media(&self) -> bool {
        if self.media_volume_sets.is_empty() || self.unplanned_archive_seen {
            return false;
        }
        self.work_plan.as_ref().is_none_or(|plan| {
            plan.archive_candidates
                .iter()
                .all(|candidate| self.media_volume_sets.contains(&candidate.volume_set()))
        })
    }

    pub fn needs_media_processing(&self) -> bool {
        !self.found_video
            || !self.found_media_info
            || !self.found_audio_info
            || !self.found_audio_sample
    }

    pub fn needs_sample(&self) -> bool {
        !self.found_sample || !self.found_jpg_sample
    }

    /// Past the per-release limit; never with a limit of zero or less.
    pub fn is_timed_out(&self, timeout_seconds: i64) -> bool {
        u64::try_from(timeout_seconds)
            .ok()
            .filter(|seconds| *seconds > 0)
            .is_some_and(|seconds| self.started.elapsed() > Duration::from_secs(seconds))
    }

    pub fn elapsed_seconds(&self) -> u64 {
        self.started.elapsed().as_secs()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn context() -> ReleaseContext {
        ReleaseContext::new(ClaimedRelease {
            id: 7,
            guid: "g7".to_owned(),
            name: "old.name".to_owned(),
            searchname: "old.search".to_owned(),
            size: 1,
            groups_id: 1,
            nfostatus: 0,
            fromname: None,
            completion: 100.0,
            categories_id: 5000,
            predb_id: 0,
            pp_timeout_count: 0,
            claim_token: Some("token".to_owned()),
        })
    }

    fn snapshot(name: &str) -> Snapshot {
        Snapshot {
            id: 7,
            guid: "g7".to_owned(),
            name: Some(name.to_owned()),
            searchname: Some(format!("{name}.search")),
        }
    }

    #[test]
    fn witnesses_stop_for_good_at_the_first_name_that_cant_be_kept() {
        let mut context = context();
        assert!(context.remember_naming_witness(b"a.mkv"));
        assert!(context.remember_naming_witness(b"a.mkv"));
        assert!(!context.remember_naming_witness(b"\xff.mkv"));
        assert!(context.naming_witnesses_omitted);
        assert!(context.archive_name_evidence_incomplete);
        assert!(context.defers_archive_name_checks());
        assert!(!context.remember_naming_witness(b"b.mkv"));
        assert_eq!(context.archive_naming_witnesses(), ["a.mkv"]);

        let mut context = self::context();
        for index in 0..MAX_ARCHIVE_FILE_NAMES {
            assert!(context.remember_naming_witness(format!("f{index}").as_bytes()));
        }
        assert!(!context.remember_naming_witness(b"one more"));

        let mut context = self::context();
        assert!(!context.remember_naming_witness(&vec![b'x'; MAX_WITNESS_NAME_BYTES + 1]));
    }

    #[test]
    fn naming_attempts_only_count_for_witnessed_names() {
        let mut context = context();
        context.remember_naming_witness(b"a.mkv");
        context.remember_archive_filename_naming_attempt("a.mkv", true);
        context.remember_archive_filename_naming_attempt("b.mkv", true);
        assert_eq!(
            context.archive_filename_naming_attempts,
            HashMap::from([("a.mkv".to_owned(), true)])
        );
    }

    #[test]
    fn evidence_identity_follows_only_this_attempts_own_renames() {
        let mut context = context();
        let start = context.par2_evidence_snapshot.identity.clone();
        let foreign = CommittedIdentity {
            before: snapshot("someone.else"),
            after: snapshot("new"),
        };
        assert!(!context.advance_full_par2_evidence_identity(Some(&foreign)));
        assert!(!context.advance_full_par2_evidence_identity(None));
        let own = CommittedIdentity {
            before: Snapshot {
                name: Some("old.name".to_owned()),
                searchname: Some("old.search".to_owned()),
                ..start.clone()
            },
            after: snapshot("new"),
        };
        assert!(context.advance_full_par2_evidence_identity(Some(&own)));
        assert_eq!(context.release.name, "new");
        assert_eq!(context.release.searchname, "new.search");
        let current = context.full_par2_evidence_snapshot();
        assert_eq!(current.identity, snapshot("new"));
        assert_eq!(current.claim_token.as_deref(), Some("token"));
        // The starting snapshot is kept.
        assert_eq!(context.par2_evidence_snapshot.identity, start);
        // Another rename has to start from the new identity.
        assert!(!context.advance_full_par2_evidence_identity(Some(&own)));
    }

    #[test]
    fn retained_archives_stop_at_two_or_sixteen_mebibytes() {
        let mut context = context();
        let small = Arc::new(vec![0; 10]);
        assert!(!context.remember_downloaded_archive("e".into(), Arc::new(Vec::new()), Vec::new()));
        assert!(context.remember_downloaded_archive("a".into(), small.clone(), Vec::new()));
        let big = Arc::new(vec![0; MAX_RETAINED_ARCHIVE_BYTES]);
        assert!(!context.remember_downloaded_archive("b".into(), big, Vec::new()));
        assert!(context.remember_downloaded_archive("c".into(), small.clone(), Vec::new()));
        assert!(!context.remember_downloaded_archive("d".into(), small, Vec::new()));
        context.reset();
        assert!(context.downloaded_archives.is_empty());
    }

    #[test]
    fn ordered_maps_keep_first_insertion_order() {
        let mut map = Ordered::default();
        map.insert("b".to_owned(), 1);
        map.insert("a".to_owned(), 2);
        map.insert("b".to_owned(), 3);
        assert_eq!(map.keys(), ["b", "a"]);
        assert_eq!(map.values().copied().collect::<Vec<_>>(), [3, 2]);
    }

    #[test]
    fn a_zero_timeout_never_expires() {
        let context = context();
        assert!(!context.is_timed_out(0));
        assert!(!context.is_timed_out(-1));
        assert!(!context.is_timed_out(3600));
    }
}
