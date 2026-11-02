//! One release's additional processing: its NZB, direct downloads, archives, extracted files and
//! media sources, then the release's final state. Port of `ReleaseProcessor`.
//!
//! Audio info and audio samples are not ported: only TV and movies are indexed, so both count as
//! found from the start, as they did in PHP with those settings off.

use std::collections::HashMap;
use std::path::{Path, PathBuf};
use std::sync::{Arc, LazyLock};
use std::time::Instant;

use anyhow::Result;
use nntmux_core::pcre::Pcre;
use nntmux_predb::season;
use rand::Rng;
use serde::Serialize;
use sha2::{Digest, Sha256};
use sqlx::MySqlPool;

use super::archive_extraction::{
    ArchiveFlags, Extractor, Inspection, has_archive_signature, is_nfo_file,
    needs_seven_zip_end_header, with_seven_zip_end_header,
};
use super::context::ReleaseContext;
use super::download::{DownloadKind, DownloadMetrics, DownloadService, meets_minimum_size};
use super::file_type::{FileType, sniff_file};
use super::media_source::{self, MediaByteBudget, Release, ResolverConfig};
use super::media_stage::{MediaSourceStage, MediaSteps, StageSettings};
use super::nzb_contents::parse_release_nzb;
use super::planner::{self, ArchiveCandidate, NzbFile, VIDEO_FILE, WorkPlan};
use super::release_file_evidence::is_nfo_filename;
use super::release_files::ReleaseFiles;
use crate::archive_info::szip::MARKER_SIGNATURE;
use crate::archive_info::{FileRecord, Kind, ListEntry, MAIN_SOURCE};
use crate::config::{Config, PlannerConfig};
use crate::media::images;
use crate::media::slicer::{self, SlicerSettings};
use crate::nfo::NfoService;
use crate::workspace;

/// `AdditionalProcessingOrchestrator::MAX_COMPRESSED_FILES_TO_CHECK`.
pub const MAX_COMPRESSED_FILES_TO_CHECK: u32 = 10;
/// `ReleaseBrowseService::PASSWD_NONE`.
const PASSWD_NONE: i8 = 0;
/// The start of a PAR2 packet.
const PAR2_SIGNATURE: &[u8] = b"PAR2\0PKT";

/// Unpacked archives, nested inside the release's archives.
static NESTED_ARCHIVE: LazyLock<Pcre> = LazyLock::new(|| {
    Pcre::new(r"/.*\.([rz]\d{2,}|rar|zipx?|0{0,2}1)($|[^a-z0-9])/i").expect("valid")
});
static PAR2_FILE: LazyLock<Pcre> = LazyLock::new(|| Pcre::new(r"/\.par2$/i").expect("valid"));
static NFO_FILE: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/(\.(nfo|inf|ofn|diz)|info\.txt)$/i").expect("valid"));
pub(crate) static IMAGE_FILE: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/\.(jpe?g|png|webp)$/i").expect("valid"));
static VIDEO_NAME: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(&format!("/(.*){VIDEO_FILE}$/i")).expect("valid"));
static QUOTED: LazyLock<Pcre> = LazyLock::new(|| Pcre::new(r#"/"([^"\r\n]+)"/"#).expect("valid"));

/// `ProcessingOutcome`.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "kebab-case")]
pub enum Outcome {
    Completed,
    NoUsefulArtifacts,
    Passworded,
    GroupUnavailable,
    TemporaryWorkspaceUnavailable,
    TimedOut,
    DeletedAfterTimeout,
    DeletedBrokenNzb,
    DeletedPar2Only,
    NotFound,
    AwaitingLateArticles,
    Failed,
}

impl Outcome {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Completed => "completed",
            Self::NoUsefulArtifacts => "no-useful-artifacts",
            Self::Passworded => "passworded",
            Self::GroupUnavailable => "group-unavailable",
            Self::TemporaryWorkspaceUnavailable => "temporary-workspace-unavailable",
            Self::TimedOut => "timed-out",
            Self::DeletedAfterTimeout => "deleted-after-timeout",
            Self::DeletedBrokenNzb => "deleted-broken-nzb",
            Self::DeletedPar2Only => "deleted-par2-only",
            Self::NotFound => "not-found",
            Self::AwaitingLateArticles => "awaiting-late-articles",
            Self::Failed => "failed",
        }
    }

    pub fn is_successful(self) -> bool {
        matches!(
            self,
            Self::Completed | Self::NoUsefulArtifacts | Self::Passworded
        )
    }

    pub fn is_deleted(self) -> bool {
        matches!(
            self,
            Self::DeletedAfterTimeout | Self::DeletedBrokenNzb | Self::DeletedPar2Only
        )
    }
}

/// `ProcessingStage`.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash, PartialOrd, Ord, Serialize)]
#[serde(rename_all = "kebab-case")]
pub enum Stage {
    WorkspacePreparation,
    NzbParsing,
    ReleaseInitialization,
    MessageIdSelection,
    MediaSourcePlanning,
    DirectDownloads,
    ArchiveDownloads,
    ExtractedFiles,
    MediaSources,
    ArchiveFallbacks,
    TimeoutHandling,
    Finalization,
    WorkspaceCleanup,
}

impl Stage {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::WorkspacePreparation => "workspace-preparation",
            Self::NzbParsing => "nzb-parsing",
            Self::ReleaseInitialization => "release-initialization",
            Self::MessageIdSelection => "message-id-selection",
            Self::MediaSourcePlanning => "media-source-planning",
            Self::DirectDownloads => "direct-downloads",
            Self::ArchiveDownloads => "archive-downloads",
            Self::ExtractedFiles => "extracted-files",
            Self::MediaSources => "media-sources",
            Self::ArchiveFallbacks => "archive-fallbacks",
            Self::TimeoutHandling => "timeout-handling",
            Self::Finalization => "finalization",
            Self::WorkspaceCleanup => "workspace-cleanup",
        }
    }
}

/// `ReleaseProcessingResult`.
#[derive(Clone, Debug, PartialEq, Serialize)]
pub struct ReleaseResult {
    pub release_id: i64,
    pub guid: String,
    pub outcome: Outcome,
    pub artifacts_created: bool,
    pub release_files_added: usize,
    pub reason: String,
    pub elapsed_seconds: f64,
    /// Seconds per stage, in stage order.
    pub stage_durations: Vec<(Stage, f64)>,
    pub download_metrics: DownloadMetrics,
    pub duplicate_message_id_count: i64,
    pub unsupported_reasons: Vec<String>,
}

impl ReleaseResult {
    pub fn is_successful(&self) -> bool {
        self.outcome.is_successful()
    }
}

/// `ProcessingMetrics`: time spent per stage.
struct Metrics {
    started: Instant,
    stages: HashMap<Stage, f64>,
}

impl Metrics {
    fn new() -> Self {
        Self {
            started: Instant::now(),
            stages: HashMap::new(),
        }
    }

    fn record(&mut self, stage: Stage, since: Instant) {
        *self.stages.entry(stage).or_default() += since.elapsed().as_secs_f64();
    }

    fn durations(&self) -> Vec<(Stage, f64)> {
        let mut durations: Vec<_> = self.stages.iter().map(|(k, v)| (*k, *v)).collect();
        durations.sort_by_key(|(stage, _)| *stage);
        durations
    }
}

/// The settings the processor reads.
#[derive(Clone, Copy, Debug)]
pub struct ProcessorSettings {
    pub process_passwords: bool,
    pub process_thumbnails: bool,
    pub process_media_info: bool,
    pub process_video: bool,
    pub process_jpg_sample: bool,
    pub fetch_last_files: bool,
    pub max_nested_levels: i64,
    pub maximum_rar_segments: i64,
    pub maximum_rar_password_checks: i64,
    pub season_pack_archive_sets: i64,
    pub release_timeout_seconds: i64,
    pub max_pp_timeout_count: i64,
    pub media_max_bytes: i64,
    pub media_image_max_bytes: i64,
}

impl ProcessorSettings {
    pub fn from_config(config: &Config) -> Self {
        Self {
            process_passwords: config.media.process_passwords,
            process_thumbnails: config.planner.process_thumbnails,
            process_media_info: config.planner.process_media_info,
            process_video: config.media.process_video,
            process_jpg_sample: config.planner.process_jpg_sample,
            fetch_last_files: config.fetch_last_files,
            // `?: 3`.
            max_nested_levels: match config.settings.max_nested_levels {
                0 => 3,
                levels => levels,
            },
            maximum_rar_segments: config.planner.maximum_rar_segments,
            maximum_rar_password_checks: config.media.maximum_rar_password_checks,
            season_pack_archive_sets: config.media.season_pack_archive_sets,
            release_timeout_seconds: config.media.release_timeout_seconds,
            max_pp_timeout_count: config.media.max_pp_timeout_count,
            media_max_bytes: config.media.max_bytes,
            media_image_max_bytes: config.planner.media_image_max_bytes,
        }
    }

    /// `shouldProcessDownloads`.
    fn downloads_wanted(&self) -> bool {
        self.process_passwords
            || self.process_thumbnails
            || self.process_media_info
            || self.process_video
            || self.process_jpg_sample
    }
}

/// Everything a processor reads besides its services.
#[derive(Clone, Debug)]
pub struct ProcessorConfig {
    pub settings: ProcessorSettings,
    pub planner: PlannerConfig,
    pub resolver: ResolverConfig,
    pub slicer: SlicerSettings,
    pub stage: StageSettings,
    pub extractor: Extractor,
}

impl ProcessorConfig {
    pub fn from_config(config: &Config) -> Self {
        Self {
            settings: ProcessorSettings::from_config(config),
            planner: config.planner.clone(),
            resolver: ResolverConfig {
                par2_media_mapping: config.planner.par2_media_mapping,
                par2_verify_files: config.media.par2_verify_files,
            },
            slicer: SlicerSettings::from_config(config),
            stage: StageSettings::from_config(config),
            extractor: Extractor::from_config(config),
        }
    }
}

/// One worker's release processor. Keeps group names between releases.
pub struct ReleaseProcessor<'a> {
    pub config: ProcessorConfig,
    pub pool: MySqlPool,
    pub files: &'a mut ReleaseFiles,
    pub nfo: &'a mut NfoService,
    pub downloads: &'a mut DownloadService,
    pub media: &'a dyn MediaSteps,
    group_names: HashMap<i64, String>,
}

/// Archive sets read whole, by set key; false once one turned out partial.
type ReadSets = HashMap<String, bool>;

impl<'a> ReleaseProcessor<'a> {
    pub fn new(
        config: ProcessorConfig,
        pool: MySqlPool,
        files: &'a mut ReleaseFiles,
        nfo: &'a mut NfoService,
        downloads: &'a mut DownloadService,
        media: &'a dyn MediaSteps,
    ) -> Self {
        Self {
            config,
            pool,
            files,
            nfo,
            downloads,
            media,
            group_names: HashMap::new(),
        }
    }

    fn settings(&self) -> &ProcessorSettings {
        &self.config.settings
    }

    /// `process`: the release in its own download and search-sync scope, with its timings.
    pub async fn process(
        &mut self,
        context: &mut ReleaseContext,
        main_tmp: &Path,
    ) -> Result<ReleaseResult> {
        let mut metrics = Metrics::new();
        self.files.sync.begin_release_scope(context.release.id);
        self.downloads.begin_release_scope();
        let result = self.process_release(context, main_tmp, &mut metrics).await;
        context.release_downloaded_archives();
        self.files.sync.finish_release_scope().await;
        let download_metrics = self.downloads.finish_release_scope();
        let mut result = result?;
        result.elapsed_seconds = metrics.started.elapsed().as_secs_f64();
        result.stage_durations = metrics.durations();
        result.download_metrics = download_metrics;
        Ok(result)
    }

    async fn process_release(
        &mut self,
        context: &mut ReleaseContext,
        main_tmp: &Path,
        metrics: &mut Metrics,
    ) -> Result<ReleaseResult> {
        let started = Instant::now();
        let prepared = workspace::create_release_temp_folder(main_tmp, &context.release.guid);
        metrics.record(Stage::WorkspacePreparation, started);
        context.tmp_path = match prepared {
            Ok(path) => path,
            Err(error) => {
                eprintln!("Unable to prepare release temp directory: {error:#}");
                return Ok(result(
                    context,
                    Outcome::TemporaryWorkspaceUnavailable,
                    false,
                    &format!("{error:#}"),
                ));
            }
        };
        let outcome = self.run(context, metrics).await;
        let started = Instant::now();
        workspace::clear_directory(&context.tmp_path, false);
        metrics.record(Stage::WorkspaceCleanup, started);
        outcome
    }

    async fn run(
        &mut self,
        context: &mut ReleaseContext,
        metrics: &mut Metrics,
    ) -> Result<ReleaseResult> {
        let started = Instant::now();
        let parsed = parse_release_nzb(&self.pool, &context.release.guid).await;
        metrics.record(Stage::NzbParsing, started);
        let nzb = match parsed? {
            Ok(nzb) => nzb,
            Err(error) => {
                eprintln!("{}", error.message);
                if !error.source_changed {
                    let release = &context.release;
                    self.files.delete_release(release.id, &release.guid).await;
                }
                let outcome = if error.source_changed {
                    Outcome::Failed
                } else {
                    Outcome::DeletedBrokenNzb
                };
                return Ok(result(context, outcome, false, &error.message));
            }
        };
        context.nzb_source_digest = Some(nzb.source_digest);
        context.direct_video_files = nzb.direct_video_files;
        context.nzb_source_files = nzb.source_files;
        context.nzb_contents = nzb.contents;
        if let Some(timeout) = self.timeout_result(context, metrics).await? {
            return Ok(timeout);
        }

        let started = Instant::now();
        self.initialize_context(context).await;
        let contents = context.nzb_contents.clone();
        let name_changed = self
            .files
            .process_release_name_from_nzb_contents(&contents, context)
            .await;
        metrics.record(Stage::ReleaseInitialization, started);
        let name_changed = name_changed?;
        let needed_nfo = context.release_has_no_nfo;

        let started = Instant::now();
        let book_flood = self.prepare_message_ids(context);
        metrics.record(Stage::MessageIdSelection, started);
        let downloads_wanted = self.settings().downloads_wanted();
        let has_par2_index = context
            .work_plan
            .as_ref()
            .is_some_and(|plan| !plan.par2_index_candidates.is_empty());
        if has_par2_index && downloads_wanted {
            let started = Instant::now();
            self.apply_media_source_plan(context).await;
            metrics.record(Stage::MediaSourcePlanning, started);
            if let Some(timeout) = self.timeout_result(context, metrics).await? {
                return Ok(timeout);
            }
        }

        let probe = self.download_probe(context).await;
        let probe_is_only_file = context
            .work_plan
            .as_ref()
            .is_some_and(|plan| plan.probe_is_only_file);
        if let Some((_, data)) = &probe
            && probe_is_only_file
            && data.starts_with(PAR2_SIGNATURE)
        {
            eprintln!(
                "Release {} only contains PAR2 data, deleting.",
                context.release.id
            );
            let release = &context.release;
            self.files.delete_release(release.id, &release.guid).await;
            return Ok(result(
                context,
                Outcome::DeletedPar2Only,
                false,
                "The release only contains PAR2 recovery data.",
            ));
        }

        // The probed file may be an archive behind an obfuscated name.
        let probed_archive = probe.filter(|(_, data)| has_archive_signature(data));
        if probed_archive.is_some() {
            context.nzb_has_compressed_file = true;
            context.unplanned_archive_seen = true;
        }

        if downloads_wanted {
            let started = Instant::now();
            self.process_message_id_downloads(context).await;
            metrics.record(Stage::DirectDownloads, started);
            if let Some(timeout) = self.timeout_result(context, metrics).await? {
                return Ok(timeout);
            }

            if !book_flood
                && context.nzb_has_compressed_file
                && let Some(timeout) = self
                    .archive_downloads(context, metrics, probed_archive)
                    .await?
            {
                return Ok(timeout);
            }

            let stage = MediaSourceStage {
                settings: self.config.stage,
                media: self.media,
            };
            if stage.has_work(context) {
                let started = Instant::now();
                stage.run(context, self.downloads).await;
                metrics.record(Stage::MediaSources, started);
                if let Some(timeout) = self.timeout_result(context, metrics).await? {
                    return Ok(timeout);
                }
            }

            let started = Instant::now();
            let fallbacks = self.archive_fallbacks(context).await;
            metrics.record(Stage::ArchiveFallbacks, started);
            fallbacks?;
        }

        self.retain_direct_video_evidence(context).await?;
        context.archive_naming_inspection_complete = context.archive_naming_inspection_complete
            && !context.archive_name_evidence_incomplete
            && has_complete_source_coverage(context);
        let started = Instant::now();
        let finalized = self
            .files
            .finalize_release(context, self.config.settings.process_passwords)
            .await;
        metrics.record(Stage::Finalization, started);
        finalized?;

        if context.full_par2_evidence_deferred {
            return Ok(result(
                context,
                Outcome::Failed,
                false,
                "Release identity or additional-processing claim changed before metadata commit.",
            ));
        }

        let artifacts = name_changed || self.created_artifacts(context, needed_nfo);
        let (outcome, reason) = if context.release_has_password {
            (Outcome::Passworded, "Password protection was detected.")
        } else if context.group_unavailable {
            (
                Outcome::GroupUnavailable,
                "The release group was unavailable during processing.",
            )
        } else if !artifacts {
            (
                Outcome::NoUsefulArtifacts,
                "Processing completed without creating useful artifacts.",
            )
        } else {
            (Outcome::Completed, "")
        };
        Ok(result(context, outcome, artifacts, reason))
    }

    /// The archive walk, the reverse pass, extracted files and the naming verdict.
    async fn archive_downloads(
        &mut self,
        context: &mut ReleaseContext,
        metrics: &mut Metrics,
        probed_archive: Option<(ArchiveCandidate, Vec<u8>)>,
    ) -> Result<Option<ReleaseResult>> {
        context.archive_inspection_attempted = true;
        let mut tried = Vec::new();
        let mut read_sets = ReadSets::new();
        let started = Instant::now();
        let walked = async {
            if let Some((candidate, data)) = probed_archive {
                self.process_probed_archive(context, &candidate, data)
                    .await?;
            }
            self.process_nzb_compressed_files(context, false, &mut tried, &mut read_sets)
                .await
        }
        .await;
        metrics.record(Stage::ArchiveDownloads, started);
        walked?;
        if let Some(timeout) = self.timeout_result(context, metrics).await? {
            return Ok(Some(timeout));
        }

        if self.settings().fetch_last_files {
            let started = Instant::now();
            let walked = self
                .process_nzb_compressed_files(context, true, &mut tried, &mut read_sets)
                .await;
            metrics.record(Stage::ArchiveDownloads, started);
            walked?;
            if let Some(timeout) = self.timeout_result(context, metrics).await? {
                return Ok(Some(timeout));
            }
        }

        if !context.release_has_password {
            let started = Instant::now();
            let extracted = self.process_extracted_files(context).await;
            metrics.record(Stage::ExtractedFiles, started);
            extracted?;
            if let Some(timeout) = self.timeout_result(context, metrics).await? {
                return Ok(Some(timeout));
            }
        }

        let plan = context.work_plan.as_ref();
        let mut complete = plan.is_some_and(|plan| {
            !plan.archive_candidates.is_empty()
                && plan.unsupported_reasons.is_empty()
                && plan.probe_candidates.is_empty()
        }) && !context.unplanned_archive_seen
            && !context.archive_retryable
            && !context.archive_listing_incomplete
            && !context.archive_name_evidence_incomplete
            && !context.archive_listing_undecodable
            && !context.release_has_password;
        if let Some(plan) = plan
            && !all_sets_read(plan, &read_sets)
        {
            complete = false;
        }
        context.archive_naming_inspection_complete = complete;
        self.run_deferred_archive_name_checks(context, &read_sets)
            .await?;
        Ok(None)
    }

    /// The jpg and NFO fallbacks out of the release's archives.
    async fn archive_fallbacks(&mut self, context: &mut ReleaseContext) -> Result<()> {
        let budget_exhausted = context
            .media_budget
            .as_ref()
            .is_some_and(MediaByteBudget::exhausted);
        if !context.found_jpg_sample && self.settings().process_jpg_sample && !budget_exhausted {
            self.process_jpg_from_release_files(context).await?;
        }
        if context.release_has_no_nfo {
            self.process_nfo_from_downloaded_archives(context).await?;
        }
        if context.release_has_no_nfo {
            self.process_nfo_from_release_files(context).await?;
        }
        Ok(())
    }

    /// `downloadProbe`: the first segment of files the planner couldn't classify by name, up to
    /// the first archive. A lone file's sample is returned whatever it holds.
    async fn download_probe(
        &mut self,
        context: &mut ReleaseContext,
    ) -> Option<(ArchiveCandidate, Vec<u8>)> {
        let plan = context.work_plan.as_ref()?;
        // A multi-file probe only feeds archive inspection, which needs downloads enabled.
        if !plan.probe_is_only_file && !self.settings().downloads_wanted() {
            return None;
        }
        let only_file = plan.probe_is_only_file;
        for candidate in plan.probe_candidates.clone() {
            if context.group_unavailable {
                return None;
            }
            let Some(first) = candidate.message_ids.first() else {
                continue;
            };
            let download = self
                .downloads
                .download(
                    DownloadKind::Compressed,
                    std::slice::from_ref(first),
                    &context.release_group_name,
                    true,
                )
                .await;
            if download.group_unavailable {
                context.group_unavailable = true;
            }
            let Some(data) = download.data else {
                continue;
            };
            if only_file || has_archive_signature(&data) {
                return Some((candidate, data));
            }
        }
        None
    }

    /// `processProbedArchive`.
    async fn process_probed_archive(
        &mut self,
        context: &mut ReleaseContext,
        candidate: &ArchiveCandidate,
        mut data: Vec<u8>,
    ) -> Result<()> {
        if context.group_unavailable {
            context.archive_retryable = true;
            return Ok(());
        }
        if !data.starts_with(MARKER_SIGNATURE) {
            // Batch downloads can return a partial body as success when a later article is
            // missing, so the rest is read article by article.
            for message_id in candidate.message_ids.iter().skip(1) {
                let download = self
                    .downloads
                    .download(
                        DownloadKind::Compressed,
                        std::slice::from_ref(message_id),
                        &context.release_group_name,
                        true,
                    )
                    .await;
                if download.group_unavailable {
                    context.group_unavailable = true;
                }
                let Some(more) = download.data else {
                    context.archive_retryable = true;
                    return Ok(());
                };
                data.extend_from_slice(&more);
            }
        }
        let mut read_sets = ReadSets::new();
        self.process_archive_data(
            context,
            data,
            &candidate.tail_message_ids,
            false,
            &candidate.title,
            Some(candidate),
            &mut read_sets,
        )
        .await?;
        Ok(())
    }

    /// `retainDirectVideoEvidence`: names of directly posted videos whose first article was
    /// seen, as release files and naming witnesses.
    async fn retain_direct_video_evidence(&mut self, context: &mut ReleaseContext) -> Result<()> {
        let mut matched = Vec::new();
        for file in context.nzb_source_files.clone().unwrap_or_default() {
            let key = sha256_hex(&file.first_message_id);
            let Some(witness) = context.direct_video_witnesses.get(&key).cloned() else {
                continue;
            };
            if Some(&witness.digest) != context.nzb_source_digest.as_ref()
                || witness.name != file.name
            {
                continue;
            }
            context.remember_naming_witness(witness.name.as_bytes());
            if season::is_unclassified_episode_name(&witness.name) {
                context.archive_name_evidence_incomplete = true;
            }
            matched.push(witness);
        }
        context.season_pack =
            context.season_pack || season::is_multi_episode_set(context.archive_naming_witnesses());
        for witness in &matched {
            let entry = ListEntry::File(FileRecord {
                name: witness.name.as_bytes().to_vec(),
                size: witness.size,
                ..FileRecord::default()
            });
            self.files.add_file_info(&entry, context).await?;
        }
        if let Some(direct) = &context.direct_video_files
            && !context.nzb_has_compressed_file
            && !context.unplanned_archive_seen
        {
            context.archive_naming_inspection_complete = matched.len() == direct.len()
                && !context.archive_name_evidence_incomplete
                && !context.group_unavailable
                && !context.release_has_password;
        }
        Ok(())
    }

    /// `prepareMessageIds`: plans the release's downloads. True for a book flood.
    fn prepare_message_ids(&self, context: &mut ReleaseContext) -> bool {
        let plan = planner::plan(
            &self.config.planner,
            &context.nzb_contents,
            &context.release_group_name,
        );
        let book_flood = plan.book_flood;
        use_work_plan(context, plan);
        let contents = context.nzb_contents.clone();
        refresh_season_pack_state(context, &contents);
        book_flood
    }

    /// `applyMediaSourcePlan`: plans again under the real names the PAR2 index gave obfuscated
    /// files.
    async fn apply_media_source_plan(&mut self, context: &mut ReleaseContext) {
        let Some(plan) = context.work_plan.as_ref() else {
            return;
        };
        let media_max_bytes = self.settings().media_max_bytes;
        let budget = context
            .media_budget
            .get_or_insert_with(|| MediaByteBudget::new(media_max_bytes));
        let mut release = Release {
            nzb: &context.nzb_contents,
            group: &context.release_group_name,
            budget,
            group_unavailable: &mut context.group_unavailable,
        };
        let resolved =
            media_source::resolve(self.downloads, self.config.resolver, plan, &mut release).await;
        let Some(media_plan) = resolved else {
            return;
        };
        let mut renamed = context.nzb_contents.clone();
        for (index, mapped) in &media_plan.mapped {
            if let Some(file) = renamed.get_mut(*index) {
                file.title = format!("\"{}\" yEnc (1/{})", mapped.file.name, file.segments.len());
            }
        }
        context.media_plan = Some(media_plan);
        let mapped = planner::plan(&self.config.planner, &renamed, &context.release_group_name);
        let Some(current) = context.work_plan.as_ref() else {
            return;
        };
        let merged = current.with_mapped_names(mapped);
        use_work_plan(context, merged);
        refresh_season_pack_state(context, &renamed);
    }

    /// `initializeContext`.
    async fn initialize_context(&mut self, context: &mut ReleaseContext) {
        let settings = *self.settings();
        context.initialize_from_config(
            settings.process_video,
            settings.process_media_info,
            false,
            false,
            settings.process_jpg_sample,
            settings.process_thumbnails,
        );
        context.password_status = PASSWD_NONE;
        context.release_has_password = false;
        let group_id = context.release.groups_id;
        if !self.group_names.contains_key(&group_id) {
            let name =
                sqlx::query_scalar::<_, String>("SELECT name FROM usenet_groups WHERE id = ?")
                    .bind(group_id)
                    .fetch_optional(&self.pool)
                    .await;
            match name {
                Ok(name) => {
                    self.group_names.insert(group_id, name.unwrap_or_default());
                }
                Err(error) => {
                    eprintln!("could not read the name of group {group_id}: {error}");
                }
            }
        }
        context.release_group_name = self.group_names.get(&group_id).cloned().unwrap_or_default();
        context.release_has_no_nfo = context.release.nfostatus != 1;
        context.reset_message_ids();
        context.reset_counters();
    }

    /// `processingTimeoutResult`: past the per-release limit, the timeout is counted and the
    /// release skipped or, at the limit, deleted.
    async fn timeout_result(
        &mut self,
        context: &mut ReleaseContext,
        metrics: &mut Metrics,
    ) -> Result<Option<ReleaseResult>> {
        if !context.is_timed_out(self.settings().release_timeout_seconds) {
            return Ok(None);
        }
        let started = Instant::now();
        let deleted = self
            .files
            .handle_release_timeout(context, self.settings().max_pp_timeout_count)
            .await;
        metrics.record(Stage::TimeoutHandling, started);
        let deleted = deleted?;
        workspace::clear_directory(&context.tmp_path, false);
        let (outcome, reason) = if deleted {
            (
                Outcome::DeletedAfterTimeout,
                "The release was deleted after reaching the post-processing timeout limit.",
            )
        } else {
            (
                Outcome::TimedOut,
                "The release exceeded the post-processing timeout.",
            )
        };
        Ok(Some(result(context, outcome, false, reason)))
    }

    /// `createdArtifacts`.
    fn created_artifacts(&self, context: &ReleaseContext, needed_nfo: bool) -> bool {
        let settings = self.settings();
        context.release_files_changed
            || context.found_par2_info
            || (needed_nfo && !context.release_has_no_nfo)
            || (settings.process_video && context.found_video)
            || (settings.process_thumbnails && context.found_sample)
            || (settings.process_jpg_sample && context.found_jpg_sample)
            || (settings.process_media_info && context.found_media_info)
    }

    /// `processMessageIdDownloads`: the posted sample, media-info article and images.
    async fn process_message_id_downloads(&mut self, context: &mut ReleaseContext) {
        if (!context.found_sample || !context.found_video) && !context.sample_message_ids.is_empty()
        {
            let download = self
                .downloads
                .download(
                    DownloadKind::Sample,
                    &context.sample_message_ids,
                    &context.release_group_name,
                    true,
                )
                .await;
            if let Some(data) = download.data.filter(|data| meets_minimum_size(data, 40)) {
                let path = context.tmp_path.join(format!(
                    "sample_{}.avi",
                    rand::rng().random_range(0..=99_999)
                ));
                if std::fs::write(&path, &data).is_ok() {
                    self.sample_and_video(context, &path).await;
                    // The media-info article is the sample's first one; it is already here.
                    if !context.media_info_message_ids.is_empty()
                        && context.media_info_message_ids.first()
                            == context.sample_message_ids.first()
                    {
                        if !context.found_media_info
                            && self.media.media_info(&path, context.release.id).await
                        {
                            context.found_media_info = true;
                        }
                        context.media_info_message_ids.clear();
                    }
                }
            }
        }

        if (!context.found_media_info || !context.found_sample || !context.found_video)
            && !context.media_info_message_ids.is_empty()
        {
            let download = self
                .downloads
                .download(
                    DownloadKind::MediaInfo,
                    &context.media_info_message_ids,
                    &context.release_group_name,
                    true,
                )
                .await;
            if let Some(data) = download.data.filter(|data| meets_minimum_size(data, 40)) {
                let path = context.tmp_path.join("media.avi");
                if std::fs::write(&path, &data).is_ok() {
                    if !context.found_media_info
                        && self.media.media_info(&path, context.release.id).await
                    {
                        context.found_media_info = true;
                    }
                    self.sample_and_video(context, &path).await;
                }
            }
        }

        if !context.found_jpg_sample && !context.jpg_message_ids.is_empty() {
            self.process_image_downloads(context).await;
        }
    }

    async fn sample_and_video(&self, context: &mut ReleaseContext, path: &Path) {
        let (tmp, guid) = (&context.tmp_path, &context.release.guid);
        if !context.found_sample && self.media.sample(path, tmp, guid, None).await {
            context.found_sample = true;
        }
        if !context.found_video && self.media.video(path, tmp, guid, None).await {
            context.found_video = true;
        }
    }

    /// `processImageDownloads`: the best posted images, whole and one at a time, until one is
    /// usable.
    async fn process_image_downloads(&mut self, context: &mut ReleaseContext) {
        let settings = *self.settings();
        let mut budget = *context
            .media_budget
            .get_or_insert_with(|| MediaByteBudget::new(settings.media_max_bytes));
        let candidates = context
            .work_plan
            .as_ref()
            .map(|plan| plan.image_candidates.clone())
            .unwrap_or_default();
        let message_id_lists: Vec<Vec<String>> = if candidates.is_empty() {
            vec![context.jpg_message_ids.clone()]
        } else {
            candidates
                .into_iter()
                .map(|candidate| candidate.message_ids)
                .collect()
        };
        for message_ids in message_id_lists {
            if context.found_jpg_sample || context.group_unavailable || budget.exhausted() {
                break;
            }
            let bounded = self
                .downloads
                .download_bounded(
                    DownloadKind::Jpg,
                    &message_ids,
                    settings.media_image_max_bytes.min(budget.remaining()),
                )
                .await;
            budget.spend(bounded.bytes_downloaded);
            if bounded.download.group_unavailable {
                context.group_unavailable = true;
            }
            let Some(data) = bounded.download.data else {
                continue;
            };
            let path = context.tmp_path.join("samplepicture.img");
            if std::fs::write(&path, &data).is_ok()
                && self.media.usable_image(&path).await
                && self.media.jpg_sample(&path, &context.release.guid).await
            {
                context.found_jpg_sample = true;
            }
            let _ = std::fs::remove_file(&path);
        }
        context.media_budget = Some(budget);
    }

    /// `processNzbCompressedFiles`: downloads the release's archives, forwards or, for the last
    /// files, in reverse, until one lists files.
    async fn process_nzb_compressed_files(
        &mut self,
        context: &mut ReleaseContext,
        reverse: bool,
        tried: &mut Vec<String>,
        read_sets: &mut ReadSets,
    ) -> Result<()> {
        if context.group_unavailable {
            return Ok(());
        }
        let candidates = match &context.work_plan {
            None => Vec::new(),
            Some(plan) if reverse => plan.archive_candidates.iter().rev().cloned().collect(),
            Some(plan) => plan.prioritized_archive_candidates(),
        };
        if !reverse {
            tried.clear();
        }
        let settings = *self.settings();
        let (mut failed, mut downloaded) = (0i64, 0i64);
        let mut listed_sets: HashMap<String, bool> = HashMap::new();
        let mut set_failures: HashMap<String, i64> = HashMap::new();
        let mut set_key = String::new();

        for candidate in candidates {
            if context.release_has_password {
                break;
            }
            // A season pack packed per episode is read set by set: the first volume of each set
            // lists its episode, and a set that fails to download only skips that set.
            let walking =
                !reverse && context.work_plan.is_some() && context.defers_archive_name_checks();
            if !walking {
                if downloaded >= settings.maximum_rar_segments
                    || failed >= settings.maximum_rar_password_checks
                {
                    break;
                }
            } else {
                set_key = set_key_of(context, &candidate);
                let sets = settings.season_pack_archive_sets;
                if listed_sets.len() as i64 >= sets
                    || downloaded >= settings.maximum_rar_segments.max(sets)
                    || failed >= sets.saturating_mul(settings.maximum_rar_password_checks)
                    || context.is_timed_out(settings.release_timeout_seconds)
                {
                    break;
                }
                if listed_sets.contains_key(&set_key)
                    || set_failures.get(&set_key).copied().unwrap_or(0)
                        >= settings.maximum_rar_password_checks
                {
                    continue;
                }
            }

            if reverse && candidate.message_ids.iter().any(|id| tried.contains(id)) {
                continue;
            }
            // Further volumes of a file already found to be plain media hold no file list.
            if context.media_volume_sets.contains(&candidate.volume_set()) {
                continue;
            }
            if !reverse {
                tried.extend(candidate.message_ids.iter().cloned());
            }

            let download = self
                .downloads
                .download(
                    DownloadKind::Compressed,
                    &candidate.message_ids,
                    &context.release_group_name,
                    true,
                )
                .await;
            if download.group_unavailable {
                context.group_unavailable = true;
                context.archive_retryable = true;
                break;
            }
            let Some(data) = download.data else {
                failed += 1;
                context.archive_retryable = true;
                if walking {
                    *set_failures.entry(set_key.clone()).or_default() += 1;
                }
                continue;
            };
            downloaded += 1;
            let files_before = context.total_file_info;
            let processed = self
                .process_archive_data(
                    context,
                    data,
                    &candidate.tail_message_ids,
                    reverse,
                    &candidate.title,
                    Some(&candidate),
                    read_sets,
                )
                .await?;
            // The listing may have just shown this is a season pack: keep reading the other sets.
            if !reverse && context.work_plan.is_some() && context.defers_archive_name_checks() {
                if context.total_file_info > files_before {
                    listed_sets.insert(set_key_of(context, &candidate), true);
                }
                continue;
            }
            // Plain media says nothing about the release's other archives; keep checking them.
            if processed && !context.media_volume_sets.contains(&candidate.volume_set()) {
                break;
            }
        }
        Ok(())
    }

    /// `processArchiveData`: lists one downloaded archive, joining a 7z's end header from its
    /// tail, and records whether its set was read whole.
    #[allow(clippy::too_many_arguments)]
    async fn process_archive_data(
        &mut self,
        context: &mut ReleaseContext,
        data: Vec<u8>,
        tail_message_ids: &[String],
        reverse: bool,
        title: &str,
        source: Option<&ArchiveCandidate>,
        read_sets: &mut ReadSets,
    ) -> Result<bool> {
        // Without a tail, a 7z whose end header is already in the data is still listed by 7-Zip.
        let tail = self
            .download_seven_zip_tail(context, &data, tail_message_ids)
            .await;
        let mut flags = ArchiveFlags::default();
        let mut listing = if data.starts_with(MARKER_SIGNATURE) {
            self.config.extractor.list_encoded_seven_zip(
                &data,
                tail.as_deref(),
                context.release.size,
                &mut flags,
                &context.tmp_path,
            )
        } else {
            None
        };
        context.absorb(&flags);
        let mut archive_data = Arc::new(data);
        if listing.is_none() {
            if let Some(joined) = tail
                .as_deref()
                .and_then(|tail| with_seven_zip_end_header(&archive_data, tail))
            {
                archive_data = Arc::new(joined);
            }
            let mut flags = ArchiveFlags::default();
            let inspected = self.config.extractor.process_compressed_data(
                &archive_data,
                &mut flags,
                &context.tmp_path,
            );
            context.absorb(&flags);
            listing = Some(inspected?);
        }
        let Some(listing) = listing else {
            return Ok(false);
        };

        let mut partial =
            context.archive_listing_incomplete || has_partial_listing(&listing, &archive_data);
        if let (Some(source), Some(plan)) = (source, context.work_plan.as_ref()) {
            let key = plan.set_key_of(source);
            let readable = matches!(
                listing,
                Inspection::Listed(_) | Inspection::StandaloneVideo(_)
            );
            if partial {
                read_sets.insert(key.clone(), false);
            } else if !read_sets.contains_key(&key)
                && !context.archive_name_evidence_incomplete
                && readable
            {
                read_sets.insert(key.clone(), true);
            }
            partial = partial || read_sets.get(&key) == Some(&false);
        }

        if let (Some(source), Some(digest), Inspection::Listed(listed)) =
            (source, context.nzb_source_digest.clone(), &listing)
            && !partial
            && !listed.files.is_empty()
            && let Some(first) = source.message_ids.first()
        {
            context
                .archive_source_witnesses
                .insert(sha256_hex(first), digest);
        }

        self.handle_archive_result(
            listing,
            archive_data,
            context,
            reverse,
            title,
            source,
            partial,
        )
        .await
    }

    /// `downloadSevenZipTail`: the last articles of a 7z whose end header lies past the head.
    async fn download_seven_zip_tail(
        &mut self,
        context: &mut ReleaseContext,
        data: &[u8],
        tail_message_ids: &[String],
    ) -> Option<Vec<u8>> {
        if tail_message_ids.is_empty() || !needs_seven_zip_end_header(data) {
            return None;
        }
        let download = self
            .downloads
            .download(
                DownloadKind::Compressed,
                tail_message_ids,
                &context.release_group_name,
                true,
            )
            .await;
        if download.group_unavailable {
            context.group_unavailable = true;
        }
        download.data
    }

    /// `processCompressedData`, for an archive unpacked from another.
    async fn process_compressed_data(
        &mut self,
        context: &mut ReleaseContext,
        data: Vec<u8>,
        title: &str,
    ) -> Result<bool> {
        let data = Arc::new(data);
        let mut flags = ArchiveFlags::default();
        let inspected =
            self.config
                .extractor
                .process_compressed_data(&data, &mut flags, &context.tmp_path);
        context.absorb(&flags);
        self.handle_archive_result(inspected?, data, context, false, title, None, false)
            .await
    }

    /// `handleArchiveResult`: a password, plain media or a listing, and what the listing holds.
    #[allow(clippy::too_many_arguments)]
    async fn handle_archive_result(
        &mut self,
        inspection: Inspection,
        data: Arc<Vec<u8>>,
        context: &mut ReleaseContext,
        reverse: bool,
        title: &str,
        source: Option<&ArchiveCandidate>,
        partial: bool,
    ) -> Result<bool> {
        let listing = match inspection {
            Inspection::Passworded { .. } => {
                context.release_has_password = true;
                context.password_status = inspection.password_status();
                return Ok(true);
            }
            Inspection::StandaloneVideo(kind) => {
                if let Some(source) = source {
                    context.media_volume_sets.insert(source.volume_set());
                }
                let path = context.tmp_path.join(format!(
                    "inline_video_{}.{}",
                    unique_id(),
                    kind.extension()
                ));
                if std::fs::write(&path, data.as_slice()).is_ok() {
                    if !context.found_media_info
                        && self.media.media_info(&path, context.release.id).await
                    {
                        context.found_media_info = true;
                    }
                    self.sample_and_video(context, &path).await;
                }
                return Ok(context.found_media_info || context.found_sample || context.found_video);
            }
            Inspection::Failed { .. } => return Ok(false),
            Inspection::Listed(listing) => listing,
        };

        let files = &listing.files;
        if partial
            || context.archive_listing_incomplete
            || has_partial_listing_of(&listing, data.len())
        {
            let names_episode = files.iter().any(|entry| match entry {
                ListEntry::File(file) => {
                    season::is_episode_name(&base_name(&String::from_utf8_lossy(&file.name)))
                }
                _ => false,
            });
            if names_episode {
                context.archive_name_evidence_incomplete = true;
            }
        }
        note_archive_listing(context, files);
        if !reverse
            && !files.is_empty()
            && context.defers_archive_name_checks()
            && context.first_archive_files.is_none()
        {
            context.first_archive_files = Some(files.clone());
        }
        if reverse {
            if context.defers_archive_name_checks() {
                if context.reverse_archive_summary.is_none() {
                    context.reverse_archive_summary = Some(listing.summary.clone());
                }
            } else {
                self.files
                    .process_release_name_from_rar(&listing.summary, context)
                    .await?;
            }
        }
        for entry in files {
            if context.release_has_password {
                break;
            }
            self.files.add_file_info(entry, context).await?;
        }
        if listing.listing_only {
            return Ok(context.total_file_info > 0);
        }
        if !context.release_has_password {
            slicer::register(&self.config.slicer, context, &data, files, source);
        }
        if context.release_has_no_nfo && contains_nfo_candidate(files) {
            context.remember_downloaded_archive(title.to_owned(), data.clone(), files.clone());
        }
        if !context.found_jpg_sample && self.settings().process_jpg_sample {
            self.process_jpg_from_archive_file_list(&data, files, context)
                .await?;
        }
        Ok(context.total_file_info > 0)
    }

    /// `processExtractedFiles`: archives unpacked from the release's archives, then the PAR2,
    /// NFO, video and image files among what was unpacked.
    async fn process_extracted_files(&mut self, context: &mut ReleaseContext) -> Result<()> {
        let tmp = context.tmp_path.clone();
        let mut nested_levels = 0;
        while nested_levels < self.settings().max_nested_levels {
            if context.compressed_files_checked >= MAX_COMPRESSED_FILES_TO_CHECK {
                break;
            }
            let Ok(files) = workspace::list_files(&tmp) else {
                break;
            };
            let mut found = false;
            for path in files {
                let relative = path.strip_prefix(&tmp).unwrap_or(&path);
                if !NESTED_ARCHIVE.is_match(relative.as_os_str().as_encoded_bytes())
                    || !path.is_file()
                {
                    continue;
                }
                let data = std::fs::read(&path).unwrap_or_default();
                if !data.is_empty() {
                    let title = path
                        .file_name()
                        .map(|name| name.to_string_lossy().into_owned())
                        .unwrap_or_default();
                    self.process_compressed_data(context, data, &title).await?;
                    found = true;
                }
                let _ = std::fs::remove_file(&path);
            }
            if !found {
                break;
            }
            nested_levels += 1;
        }

        let Ok(files) = workspace::list_files(&tmp) else {
            return Ok(());
        };
        let mut images = Vec::new();
        for path in files {
            if !path.is_file() {
                continue;
            }
            let name = path.as_os_str().as_encoded_bytes();
            if !context.found_par2_info && PAR2_FILE.is_match(name) {
                self.files.process_par2_file(&path, context).await?;
                continue;
            }
            if context.release_has_no_nfo
                && (NFO_FILE.is_match(name) || is_nfo_filename(&path.to_string_lossy()))
            {
                self.files
                    .process_nfo_file(&path, context, self.nfo)
                    .await?;
                continue;
            }
            // Audio files are left alone: audio info is not wanted.
            if !context.found_jpg_sample && IMAGE_FILE.is_match(name) {
                images.push(path);
                continue;
            }
            if (!context.found_sample || !context.found_video || !context.found_media_info)
                && VIDEO_NAME.is_match(name)
            {
                self.process_video_file(context, &path).await;
                continue;
            }
            match sniff_file(&path) {
                Some(FileType::Image) if !context.found_jpg_sample => images.push(path),
                Some(FileType::Image) => {}
                Some(FileType::Video) => {
                    if !context.found_media_info || !context.found_sample || !context.found_video {
                        self.process_video_file(context, &path).await;
                    }
                }
                Some(FileType::Par2) if !context.found_par2_info => {
                    self.files.process_par2_file(&path, context).await?;
                }
                Some(FileType::Par2) | None => {}
            }
        }
        self.use_best_extracted_image(context, images).await;
        Ok(())
    }

    /// `MediaExtractionService::processVideoFile`.
    async fn process_video_file(&self, context: &mut ReleaseContext, path: &Path) {
        let (tmp, guid) = (context.tmp_path.clone(), context.release.guid.clone());
        if !context.found_sample && self.media.sample(path, &tmp, &guid, None).await {
            context.found_sample = true;
        }
        // A sample posted in several articles makes the video itself.
        if !context.found_video
            && context.sample_message_ids.len() < 2
            && self.media.video(path, &tmp, &guid, None).await
        {
            context.found_video = true;
        }
        if !context.found_media_info && self.media.media_info(path, context.release.id).await {
            context.found_media_info = true;
        }
    }

    /// `useBestExtractedImage`: the best usable image unpacked from the release's archives.
    async fn use_best_extracted_image(&self, context: &mut ReleaseContext, images: Vec<PathBuf>) {
        let mut ranked: Vec<(PathBuf, String, i64)> = images
            .into_iter()
            .map(|path| {
                let name = path.to_string_lossy().into_owned();
                let size = std::fs::metadata(&path).map_or(0, |meta| meta.len() as i64);
                (path, name, size)
            })
            .collect();
        images::sort_candidates(&mut ranked, |(_, name, _)| name, |(_, _, size)| *size);
        for (path, _, _) in ranked {
            if !context.found_jpg_sample
                && self.media.usable_image(&path).await
                && self.media.jpg_sample(&path, &context.release.guid).await
            {
                context.found_jpg_sample = true;
            }
            let _ = std::fs::remove_file(&path);
        }
    }

    /// `runDeferredArchiveNameChecks`: once every archive set was read, a confirmed season pack
    /// keeps its name, and otherwise the per-file renames held back during the walk run.
    async fn run_deferred_archive_name_checks(
        &mut self,
        context: &mut ReleaseContext,
        read_sets: &ReadSets,
    ) -> Result<()> {
        let Some(plan) = context.work_plan.as_ref() else {
            return Ok(());
        };
        if context.release_has_password
            || context.season_pack
            || !context.season_pack_probable
            || context.archive_listing_incomplete
            || context.archive_name_evidence_incomplete
            || !all_sets_read(plan, read_sets)
        {
            return Ok(());
        }
        context.season_pack_probable = false;
        if let Some(files) = context.first_archive_files.clone() {
            self.files
                .match_file_names_to_predb(&files, context)
                .await?;
        }
        if let Some(summary) = context.reverse_archive_summary.clone() {
            self.files
                .process_release_name_from_rar(&summary, context)
                .await?;
        }
        Ok(())
    }
}

fn result(
    context: &ReleaseContext,
    outcome: Outcome,
    artifacts_created: bool,
    reason: &str,
) -> ReleaseResult {
    ReleaseResult {
        release_id: context.release.id,
        guid: context.release.guid.clone(),
        outcome,
        artifacts_created,
        release_files_added: context.added_file_info,
        reason: reason.to_owned(),
        elapsed_seconds: 0.0,
        stage_durations: Vec::new(),
        download_metrics: DownloadMetrics::default(),
        duplicate_message_id_count: context.duplicate_message_id_count(),
        unsupported_reasons: context.unsupported_reasons().to_vec(),
    }
}

/// `useWorkPlan`.
fn use_work_plan(context: &mut ReleaseContext, plan: WorkPlan) {
    let single = |id: &str| {
        if id.is_empty() {
            Vec::new()
        } else {
            vec![id.to_owned()]
        }
    };
    context.nzb_has_compressed_file = plan.has_compressed_file();
    context.sample_message_ids = plan.sample_message_ids.clone();
    context.jpg_message_ids = plan.jpg_message_ids.clone();
    context.media_info_message_ids = single(&plan.media_info_message_id);
    context.audio_info_message_ids = single(&plan.audio_info_message_id);
    context.audio_info_extension = plan.audio_info_extension.clone();
    context.work_plan = Some(plan);
}

/// `refreshSeasonPackState`: a season pack by the release's name or its NZB file names.
fn refresh_season_pack_state(context: &mut ReleaseContext, contents: &[NzbFile]) {
    if context.season_pack {
        return;
    }
    if season::is_season_pack(&context.release.searchname) {
        context.season_pack = true;
        return;
    }
    let names: Vec<String> = contents
        .iter()
        .map(|file| {
            QUOTED
                .captures(file.title.as_bytes())
                .and_then(|captures| captures.group(1).map(<[u8]>::to_vec))
                .map_or_else(
                    || file.title.clone(),
                    |name| String::from_utf8_lossy(&name).into_owned(),
                )
        })
        .collect();
    context.season_pack = season::is_multi_episode_set(&names);
}

/// `noteArchiveListing`: what an archive listed, and whether that makes a season pack.
fn note_archive_listing(context: &mut ReleaseContext, files: &[ListEntry]) {
    context.remember_archive_file_names(files);
    if context
        .archive_file_names
        .iter()
        .any(|name| season::is_unclassified_episode_name(name))
    {
        context.archive_name_evidence_incomplete = true;
    }
    if context.season_pack {
        return;
    }
    if season::is_multi_episode_set(&context.archive_file_names) {
        context.season_pack = true;
        context.season_pack_probable = false;
        return;
    }
    let sets = context
        .work_plan
        .as_ref()
        .map_or(0, WorkPlan::archive_set_count);
    if !context.season_pack_probable
        && sets >= 2
        && context
            .archive_file_names
            .iter()
            .any(|name| season::is_episode_name(&base_name(name)))
    {
        context.season_pack_probable = true;
    }
}

fn set_key_of(context: &ReleaseContext, candidate: &ArchiveCandidate) -> String {
    context
        .work_plan
        .as_ref()
        .map(|plan| plan.set_key_of(candidate))
        .unwrap_or_default()
}

fn all_sets_read(plan: &WorkPlan, read_sets: &ReadSets) -> bool {
    plan.archive_candidates
        .iter()
        .all(|candidate| read_sets.get(&plan.set_key_of(candidate)) == Some(&true))
}

/// `hasCompleteSourceCoverage`: every NZB file was read as an archive or seen as a direct video
/// under the current NZB.
fn has_complete_source_coverage(context: &ReleaseContext) -> bool {
    let (Some(files), Some(digest)) = (&context.nzb_source_files, &context.nzb_source_digest)
    else {
        return false;
    };
    files.iter().all(|file| {
        let key = sha256_hex(&file.first_message_id);
        if context.archive_source_witnesses.get(&key) == Some(digest) {
            return true;
        }
        context
            .direct_video_witnesses
            .get(&key)
            .is_some_and(|direct| direct.digest == *digest && direct.name == file.name)
    })
}

/// `hasPartialArchiveListing`: whether a listing may leave out files of its archive.
fn has_partial_listing(inspection: &Inspection, data: &[u8]) -> bool {
    match inspection {
        Inspection::Listed(listing) => has_partial_listing_of(listing, data.len()),
        _ => true,
    }
}

fn has_partial_listing_of(listing: &super::archive_extraction::Listing, data_len: usize) -> bool {
    match listing.kind {
        Kind::Rar => {
            if listing.rar_listing_complete != Some(true) {
                return true;
            }
        }
        Kind::Szip => {}
        // File offsets alone do not attest the terminal directory of other archive types.
        _ => return true,
    }
    // A successful volume listing need not include the other episodes in its set.
    if listing.summary.is_volume {
        return true;
    }
    for entry in &listing.files {
        let file = match entry {
            ListEntry::File(file) => Some(file),
            _ => None,
        };
        if file.is_some_and(|file| file.split || file.split_after) {
            return true;
        }
        // Decoded 7z lists have an explicit completeness result instead of byte offsets.
        if listing.listing_only && listing.kind == Kind::Szip {
            continue;
        }
        // Nested offsets use another buffer, and missing bounds cannot prove completeness.
        let Some(file) = file else {
            return true;
        };
        let in_bounds = file
            .next_offset
            .is_some_and(|next| next >= 0 && next as u64 <= data_len as u64);
        if file.source != MAIN_SOURCE || !in_bounds {
            return true;
        }
    }
    false
}

/// `containsNfoCandidate`.
fn contains_nfo_candidate(files: &[ListEntry]) -> bool {
    files.iter().any(|entry| match entry {
        ListEntry::File(file) => is_nfo_file(&file.name),
        _ => false,
    })
}

/// `basename(str_replace('\\', '/', $name))`.
pub(crate) fn base_name(name: &str) -> String {
    let path = name.replace('\\', "/");
    let trimmed = path.trim_end_matches('/');
    trimmed.rsplit('/').next().unwrap_or_default().to_owned()
}

fn sha256_hex(value: &str) -> String {
    hex::encode(Sha256::digest(value.as_bytes()))
}

/// A random name part, as `uniqid` gave.
pub(crate) fn unique_id() -> String {
    hex::encode(rand::rng().random::<[u8; 8]>())
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::additional::archive_extraction::Listing;
    use crate::archive_info::Summary;

    fn listed(kind: Kind, files: Vec<ListEntry>) -> Listing {
        Listing {
            files,
            marker: None,
            kind,
            summary: Summary::default(),
            listing_only: false,
            rar_listing_complete: (kind == Kind::Rar).then_some(true),
        }
    }

    fn file(next_offset: Option<i64>, source: &str) -> ListEntry {
        ListEntry::File(FileRecord {
            name: b"a.mkv".to_vec(),
            next_offset,
            source: source.to_owned(),
            ..FileRecord::default()
        })
    }

    #[test]
    fn listings_are_partial_as_in_php() {
        let whole = listed(Kind::Rar, vec![file(Some(100), MAIN_SOURCE)]);
        assert!(!has_partial_listing_of(&whole, 100));
        assert!(has_partial_listing_of(&whole, 99));
        let mut unterminated = whole.clone();
        unterminated.rar_listing_complete = Some(false);
        assert!(has_partial_listing_of(&unterminated, 100));
        let mut volume = whole.clone();
        volume.summary.is_volume = true;
        assert!(has_partial_listing_of(&volume, 100));
        assert!(has_partial_listing_of(
            &listed(Kind::Zip, vec![file(Some(1), MAIN_SOURCE)]),
            100
        ));
        assert!(has_partial_listing_of(
            &listed(Kind::Rar, vec![file(Some(1), "nested")]),
            100
        ));
        assert!(has_partial_listing_of(
            &listed(Kind::Rar, vec![file(None, MAIN_SOURCE)]),
            100
        ));
        let mut split = whole.clone();
        if let ListEntry::File(record) = &mut split.files[0] {
            record.split = true;
        }
        assert!(has_partial_listing_of(&split, 100));
        let mut decoded = listed(Kind::Szip, vec![file(None, "nested")]);
        decoded.listing_only = true;
        assert!(!has_partial_listing_of(&decoded, 100));
        assert!(has_partial_listing(
            &Inspection::Failed {
                undecodable: false,
                listing_only: false
            },
            b""
        ));
    }

    #[test]
    fn base_names_follow_php() {
        assert_eq!(base_name("a\\b/Show.S01E01.mkv"), "Show.S01E01.mkv");
        assert_eq!(base_name("dir/"), "dir");
        assert_eq!(base_name("plain"), "plain");
    }

    #[test]
    fn outcomes_keep_their_php_values() {
        assert_eq!(Outcome::DeletedPar2Only.as_str(), "deleted-par2-only");
        assert_eq!(
            serde_json::to_string(&Outcome::TemporaryWorkspaceUnavailable).unwrap(),
            "\"temporary-workspace-unavailable\""
        );
        assert!(Outcome::Passworded.is_successful());
        assert!(!Outcome::GroupUnavailable.is_successful());
        assert!(Outcome::DeletedBrokenNzb.is_deleted());
        assert_eq!(Stage::ArchiveFallbacks.as_str(), "archive-fallbacks");
    }
}
