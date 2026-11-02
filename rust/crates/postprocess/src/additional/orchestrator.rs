//! Batches of claimed releases through the release processor, and single releases by GUID. Port
//! of `AdditionalProcessingOrchestrator` and `AdditionalBatchResult`.

use std::collections::BTreeMap;
use std::path::PathBuf;
use std::sync::Arc;
use std::time::{Duration, Instant};

use anyhow::Result;
use nntmux_metadata::images::Images;
use nntmux_namefix::queries::FixNamesConfig;
use nntmux_namefix::service::NameFixer;
use nntmux_namefix::update::{ReleaseSearch, ReleaseUpdater};
use nntmux_nntp::{NntpClient, NntpConfig};
use serde::Serialize;
use sqlx::MySqlPool;

use super::candidates::{self, ClaimedRelease, Scope};
use super::context::ReleaseContext;
use super::download::{DownloadMetrics, DownloadService, MappingPolicy};
use super::media_stage::ReleaseMedia;
use super::processor::{Outcome, ProcessorConfig, ReleaseProcessor, ReleaseResult, Stage};
use super::release_files::{self, ReleaseFiles};
use super::search_sync::SearchSync;
use crate::config::Config;
use crate::media::frames::FfmpegFrameGrabber;
use crate::media::{PreviewSettings, Previews};
use crate::nfo::{self, NfoService};
use crate::workspace;

/// The columns a claim reads, as `ClaimedRelease` maps them.
impl release_files::Settings {
    /// The release-file manager's settings from the processing configuration.
    pub fn from_config(config: &Config) -> Self {
        let blacklist = &config.settings.inner_file_blacklist;
        let format = std::env::var("IMAGE_OUTPUT_FORMAT")
            .unwrap_or_else(|_| "webp".to_owned())
            .to_ascii_lowercase();
        Self {
            inner_file_blacklist: (!blacklist.is_empty()).then(|| blacklist.clone()),
            add_par2_files: config.add_par2_files,
            rename_par2: config.rename_par2,
            echo_cli: std::env::var("ECHOCLI")
                .map_or(true, |value| !matches!(value.as_str(), "false" | "0" | "")),
            season_pack_max_release_files: usize::try_from(
                config.media.season_pack_max_release_files,
            )
            .unwrap_or(30),
            archive_retry_delay_seconds: config.archive_retry_delay_seconds,
            claim_ttl_seconds: config.settings.claim_ttl_seconds(),
            covers_path: config.covers_path.clone(),
            image_extension: if matches!(format.as_str(), "jpg" | "jpeg") {
                "jpg".to_owned()
            } else {
                "webp".to_owned()
            },
        }
    }
}

/// One worker's connections and helpers, kept across releases and batches.
pub struct Services {
    pub config: Config,
    pub processor: ProcessorConfig,
    pub pool: MySqlPool,
    pub search: Arc<dyn ReleaseSearch>,
    pub files: ReleaseFiles,
    pub nfo: NfoService,
    pub downloads: DownloadService,
    pub images: Images,
    pub grabber: FfmpegFrameGrabber,
    group_names: Option<std::collections::HashMap<i64, String>>,
}

impl Services {
    /// Everything a worker needs, from the configuration and the environment.
    pub async fn build(
        pool: MySqlPool,
        config: Config,
        search: Arc<dyn ReleaseSearch>,
    ) -> Result<Self> {
        let nntp = NntpConfig::from_env();
        let alternate = nntp.use_alternate;
        let nntp = Arc::new(nntp);
        let categorizer = nntmux_categorize::Categorizer::load(&pool).await?;
        let updater = ReleaseUpdater::new(
            pool.clone(),
            categorizer,
            search.clone(),
            nntmux_predb::filename::fixer_cleaner,
        );
        let fixer = NameFixer::new(updater, FixNamesConfig::from_env());
        let files = ReleaseFiles::new(
            pool.clone(),
            release_files::Settings::from_config(&config),
            fixer,
            SearchSync::new(search.clone(), true),
        )?;
        let nfo = NfoService::new(
            pool.clone(),
            nfo::Settings::from_config(&config, nntp.alternate_article_fallback),
            NntpClient::new(Arc::clone(&nntp)),
        );
        let policy = MappingPolicy {
            process_thumbnails: config.planner.process_thumbnails,
            main_video_fallback: config.planner.media_main_video_fallback,
            main_video_min_bytes: config.planner.media_main_video_min_bytes,
        };
        let downloads = DownloadService::new(NntpClient::new(nntp), alternate, policy);
        let grabber = FfmpegFrameGrabber {
            ffmpeg: config.ffmpeg_path.as_deref().map(PathBuf::from),
            ffprobe: config.ffprobe_path.as_deref().map(PathBuf::from),
            timeout: Duration::from_secs(
                u64::try_from(config.media.ffmpeg_timeout.max(1)).unwrap_or(30),
            ),
        };
        Ok(Self {
            processor: ProcessorConfig::from_config(&config),
            config,
            pool,
            search,
            files,
            nfo,
            downloads,
            images: Images::from_env()?,
            grabber,
            group_names: None,
        })
    }

    /// Runs one release through the processor.
    pub async fn process(
        &mut self,
        release: ClaimedRelease,
        main_tmp: &std::path::Path,
    ) -> Result<ReleaseResult> {
        let previews = Previews {
            settings: PreviewSettings::from_config(&self.config),
            grabber: &self.grabber,
            images: &self.images,
        };
        let media = ReleaseMedia {
            previews: &previews,
            pool: &self.pool,
            search: self.search.as_ref(),
            mediainfo: self
                .config
                .mediainfo_path
                .as_deref()
                .map(std::path::Path::new),
        };
        let mut processor = ReleaseProcessor::new(
            self.processor.clone(),
            self.pool.clone(),
            &mut self.files,
            &mut self.nfo,
            &mut self.downloads,
            &media,
        );
        if let Some(names) = self.group_names.take() {
            processor.set_group_names(names);
        }
        let mut context = ReleaseContext::new(release);
        let result = processor.process(&mut context, main_tmp).await;
        self.group_names = Some(processor.into_group_names());
        result
    }
}

/// `AdditionalBatchResult`: one claim batch and what became of each release.
#[derive(Clone, Debug, Default, Serialize)]
pub struct BatchResult {
    pub claimed_ids: Vec<i64>,
    pub results: Vec<ReleaseResult>,
    pub elapsed_seconds: f64,
    /// Why the batch couldn't start, when it couldn't.
    pub setup_error: Option<String>,
}

impl BatchResult {
    pub fn claimed_count(&self) -> usize {
        self.claimed_ids.len()
    }

    pub fn attempted_count(&self) -> usize {
        self.results.len()
    }

    pub fn successful_count(&self) -> usize {
        self.results.iter().filter(|r| r.is_successful()).count()
    }

    pub fn unsuccessful_count(&self) -> usize {
        self.attempted_count() - self.successful_count()
    }

    pub fn artifacts_created_count(&self) -> usize {
        self.results.iter().filter(|r| r.artifacts_created).count()
    }

    pub fn artifact_yield_percent(&self) -> f64 {
        if self.results.is_empty() {
            return 0.0;
        }
        self.artifacts_created_count() as f64 * 100.0 / self.results.len() as f64
    }

    pub fn release_files_added(&self) -> usize {
        self.results.iter().map(|r| r.release_files_added).sum()
    }

    pub fn duplicate_message_id_count(&self) -> i64 {
        self.results
            .iter()
            .map(|r| r.duplicate_message_id_count)
            .sum()
    }

    pub fn outcome_counts(&self) -> BTreeMap<&'static str, usize> {
        let mut counts = BTreeMap::new();
        for result in &self.results {
            *counts.entry(result.outcome.as_str()).or_default() += 1;
        }
        counts
    }

    pub fn unsupported_reason_counts(&self) -> BTreeMap<String, usize> {
        let mut counts = BTreeMap::new();
        for reason in self.results.iter().flat_map(|r| &r.unsupported_reasons) {
            *counts.entry(reason.clone()).or_default() += 1;
        }
        counts
    }

    pub fn download_metrics(&self) -> DownloadMetrics {
        let mut total = DownloadMetrics::default();
        for metrics in self.results.iter().map(|r| r.download_metrics) {
            total.logical_requests += metrics.logical_requests;
            total.network_requests += metrics.network_requests;
            total.cache_hits += metrics.cache_hits;
            total.bytes_downloaded += metrics.bytes_downloaded;
            total.bytes_reused += metrics.bytes_reused;
            total.media_bytes_downloaded += metrics.media_bytes_downloaded;
        }
        total
    }

    pub fn stage_duration_totals(&self) -> BTreeMap<&'static str, f64> {
        let mut totals: BTreeMap<Stage, f64> = BTreeMap::new();
        for (stage, seconds) in self.results.iter().flat_map(|r| &r.stage_durations) {
            *totals.entry(*stage).or_default() += seconds;
        }
        totals
            .into_iter()
            .map(|(stage, seconds)| (stage.as_str(), round(seconds, 6)))
            .collect()
    }

    pub fn releases_per_second(&self) -> f64 {
        if self.elapsed_seconds <= 0.0 {
            return 0.0;
        }
        self.results.len() as f64 / self.elapsed_seconds
    }

    pub fn average_release_seconds(&self) -> f64 {
        if self.results.is_empty() {
            return 0.0;
        }
        self.results.iter().map(|r| r.elapsed_seconds).sum::<f64>() / self.results.len() as f64
    }

    /// The `additional-postprocessing-profile` record `postprocess:guid --profile` printed.
    pub fn profile(&self, guid_char: &str, batch: usize) -> serde_json::Value {
        let downloads = self.download_metrics();
        serde_json::json!({
            "event": "additional-postprocessing-profile",
            "pipeline": "v2",
            "guid_char": guid_char,
            "batch": batch,
            "claimed": self.claimed_count(),
            "attempted": self.attempted_count(),
            "successful": self.successful_count(),
            "failed": self.unsuccessful_count(),
            "outcomes": self.outcome_counts(),
            "artifacts_created": self.artifacts_created_count(),
            "artifact_yield_percent": round(self.artifact_yield_percent(), 2),
            "release_files_added": self.release_files_added(),
            "nntp_requests": downloads.network_requests,
            "nntp_bytes": downloads.bytes_downloaded,
            "duplicate_message_ids": self.duplicate_message_id_count(),
            "elapsed_seconds": round(self.elapsed_seconds, 6),
            "releases_per_hour": round(self.releases_per_second() * 3600.0, 2),
            "average_release_seconds": round(self.average_release_seconds(), 6),
            "stage_seconds": self.stage_duration_totals(),
        })
    }
}

fn round(value: f64, places: i32) -> f64 {
    let factor = 10f64.powi(places);
    (value * factor).round() / factor
}

/// `start`: claims one batch of a GUID bucket (or group) and processes it. Each release is
/// processed on its own, so one failing release can't stall the bucket, and its claim is given
/// back whatever happens.
pub async fn start(
    services: &mut Services,
    guid_char: &str,
    group_id: i64,
    worker_token: &str,
    excluded: &[i64],
) -> Result<BatchResult> {
    let started = Instant::now();
    let main_tmp = match setup_temp_path(&services.config, guid_char, group_id, worker_token) {
        Ok(path) => path,
        Err(error) => {
            eprintln!("Additional post-processing skipped: {error:#}");
            return Ok(BatchResult {
                setup_error: Some(format!("{error:#}")),
                ..BatchResult::default()
            });
        }
    };
    let token = candidates::new_token();
    let releases = candidates::claim_batch(
        &services.pool,
        &Scope::new(&services.config),
        guid_char,
        services.config.settings.query_limit(),
        &token,
        group_id,
        excluded,
    )
    .await;
    let releases = match releases {
        Ok(releases) => releases,
        Err(error) => {
            workspace::clear_directory(&main_tmp, false);
            return Err(error);
        }
    };
    let mut batch = BatchResult {
        claimed_ids: releases.iter().map(|release| release.id).collect(),
        ..BatchResult::default()
    };
    for release in releases {
        let (id, guid) = (release.id, release.guid.clone());
        let release_started = Instant::now();
        let result = match services.process(release, &main_tmp).await {
            Ok(result) => result,
            Err(error) => {
                // The release is picked again next cycle, and the timeout count eventually
                // drops it.
                eprintln!("Additional postprocessing failed for release {id}: {error:#}");
                failed(id, &guid, &format!("{error:#}"), release_started)
            }
        };
        batch.results.push(result);
        if let Err(error) = candidates::clear_claim(&services.pool, id, Some(&token)).await {
            eprintln!("could not clear the claim on release {id}: {error:#}");
        }
    }
    workspace::clear_directory(&main_tmp, false);
    batch.elapsed_seconds = started.elapsed().as_secs_f64();
    Ok(batch)
}

/// A release found by GUID, its late-article candidate and its GUID bucket.
type FoundRelease = (ClaimedRelease, Option<i64>, Option<String>);

/// `processSingleGuid`: one release, whatever its processing state, without a claim.
pub async fn process_single_guid(services: &mut Services, guid: &str) -> ReleaseResult {
    let started = Instant::now();
    let release: Result<Option<FoundRelease>> = async {
        let row = sqlx::query_as::<_, ClaimedRelease>(
            "SELECT id, guid, name, searchname, size, groups_id, nfostatus, fromname, \
             completion, categories_id, predb_id, pp_timeout_count, additional_pp_claim_token \
             FROM releases WHERE guid = ? LIMIT 1",
        )
        .bind(guid)
        .fetch_optional(&services.pool)
        .await?;
        let Some(release) = row else {
            return Ok(None);
        };
        let (late, leftguid): (Option<i64>, Option<String>) = sqlx::query_as(
            "SELECT CAST(late_article_candidate_id AS SIGNED), leftguid FROM releases WHERE id = ?",
        )
        .bind(release.id)
        .fetch_one(&services.pool)
        .await?;
        Ok(Some((release, late, leftguid)))
    }
    .await;
    let (release, late, leftguid) = match release {
        Ok(Some(found)) => found,
        Ok(None) => {
            eprintln!("Release not found for GUID: {guid}");
            return result_without_release(0, guid, Outcome::NotFound, "Release not found.");
        }
        Err(error) => return failed(0, guid, &format!("{error:#}"), started),
    };
    let id = release.id;
    if late.is_some() {
        eprintln!("Release is a partial still awaiting late articles: {guid}");
        return result_without_release(
            id,
            guid,
            Outcome::AwaitingLateArticles,
            "Release is a partial still awaiting late articles.",
        );
    }
    let guid_char = leftguid
        .filter(|left| !left.is_empty())
        .unwrap_or_else(|| guid.chars().take(1).collect());
    let token = candidates::new_token();
    let main_tmp = match setup_temp_path(&services.config, &guid_char, 0, &token) {
        Ok(path) => path,
        Err(error) => {
            return result_without_release(
                id,
                guid,
                Outcome::TemporaryWorkspaceUnavailable,
                &format!("{error:#}"),
            );
        }
    };
    let result = match services.process(release, &main_tmp).await {
        Ok(result) => result,
        Err(error) => failed(id, guid, &format!("{error:#}"), started),
    };
    workspace::clear_directory(&main_tmp, false);
    result
}

/// `setupTempPath`: the worker's own directory under the bucket, emptied.
fn setup_temp_path(
    config: &Config,
    guid_char: &str,
    group_id: i64,
    worker_token: &str,
) -> Result<PathBuf> {
    let group = if group_id > 0 {
        group_id.to_string()
    } else {
        String::new()
    };
    let token = if worker_token.is_empty() {
        candidates::new_token()
    } else {
        worker_token.to_owned()
    };
    let path = workspace::ensure_main_temp_path(&config.tmp_unrar_path, guid_char, &group, &token)?;
    workspace::clear_directory(&path, true);
    Ok(path)
}

fn failed(id: i64, guid: &str, reason: &str, started: Instant) -> ReleaseResult {
    let mut result = result_without_release(id, guid, Outcome::Failed, reason);
    result.elapsed_seconds = started.elapsed().as_secs_f64();
    result
}

fn result_without_release(id: i64, guid: &str, outcome: Outcome, reason: &str) -> ReleaseResult {
    ReleaseResult {
        release_id: id,
        guid: guid.to_owned(),
        outcome,
        artifacts_created: false,
        release_files_added: 0,
        reason: reason.to_owned(),
        elapsed_seconds: 0.0,
        stage_durations: Vec::new(),
        download_metrics: DownloadMetrics::default(),
        duplicate_message_id_count: 0,
        unsupported_reasons: Vec::new(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn outcome(outcome: Outcome, artifacts: bool, seconds: f64) -> ReleaseResult {
        let mut result = result_without_release(1, "g", outcome, "");
        result.artifacts_created = artifacts;
        result.elapsed_seconds = seconds;
        result.stage_durations = vec![(Stage::NzbParsing, seconds / 2.0)];
        result
    }

    #[test]
    fn batch_totals_follow_php() {
        let batch = BatchResult {
            claimed_ids: vec![1, 2, 3],
            results: vec![
                outcome(Outcome::Completed, true, 1.0),
                outcome(Outcome::Passworded, false, 2.0),
                outcome(Outcome::Failed, false, 3.0),
            ],
            elapsed_seconds: 6.0,
            setup_error: None,
        };
        assert_eq!(batch.successful_count(), 2);
        assert_eq!(batch.unsuccessful_count(), 1);
        assert_eq!(batch.artifacts_created_count(), 1);
        assert!((batch.artifact_yield_percent() - 33.333).abs() < 0.01);
        assert!((batch.average_release_seconds() - 2.0).abs() < 1e-9);
        assert_eq!(batch.outcome_counts()["passworded"], 1);
        assert_eq!(batch.stage_duration_totals()["nzb-parsing"], 3.0);
        let profile = batch.profile("a", 1);
        assert_eq!(profile["releases_per_hour"], 1800.0);
        assert_eq!(profile["claimed"], 3);
    }
}
