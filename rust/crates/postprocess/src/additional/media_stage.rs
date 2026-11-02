//! The last attempt at a preview for a release the regular downloads gave none. Port of
//! `MediaSourceStage` and `MediaArticleFetcher`.
//!
//! Sources, best first: a sample stored in a downloaded archive volume, the start of a
//! full-length video posted as it is, the start of a video stored in an archive and, when
//! enabled, a chunk from the middle of either. Every download counts against the release's media
//! byte budget and the stage's deadline. A one-file post is a source like any other.

use std::fs::{self, OpenOptions};
use std::io::{Read, Write};
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant};

use async_trait::async_trait;
use nntmux_namefix::update::ReleaseSearch;
use rand::Rng;
use sha2::{Digest, Sha256};
use sqlx::MySqlPool;

use super::archive_extraction::has_archive_signature;
use super::context::{DirectVideoWitness, ReleaseContext};
use super::download::{DownloadKind, DownloadService};
use super::media_source::MediaByteBudget;
use super::nzb_contents::strnatcmp;
use super::planner::ArchiveCandidate;
use crate::media::Previews;
use crate::media::container::{MediaSourceHints, VideoContainer};
use crate::media::slicer::{self, ArchivedMediaEntry};
use crate::media::splicer;

/// A posted video's size includes yEnc overhead; this much of it is the file.
const POSTED_SIZE_TO_REAL_SIZE: f64 = 0.97;
/// Longest part name kept as a direct video witness.
const MAX_WITNESS_NAME_BYTES: usize = 4096;

/// The settings the stage reads.
#[derive(Clone, Copy, Debug)]
pub struct StageSettings {
    pub process_thumbnails: bool,
    pub stage_max_seconds: i64,
    pub main_video_fallback: bool,
    pub mid_sources: bool,
    pub max_bytes: i64,
    pub sample_max_bytes: i64,
    pub mid_bytes: i64,
    pub release_timeout_seconds: i64,
    pub ffmpeg_timeout: i64,
}

impl StageSettings {
    pub fn from_config(config: &crate::config::Config) -> Self {
        Self {
            process_thumbnails: config.planner.process_thumbnails,
            stage_max_seconds: config.media.stage_max_seconds,
            main_video_fallback: config.planner.media_main_video_fallback,
            mid_sources: config.media.mid_sources,
            max_bytes: config.media.max_bytes,
            sample_max_bytes: config.media.sample_max_bytes,
            mid_bytes: config.media.mid_bytes,
            release_timeout_seconds: config.media.release_timeout_seconds,
            ffmpeg_timeout: config.media.ffmpeg_timeout,
        }
    }
}

/// The media steps the stage hands its files to.
#[async_trait]
pub trait MediaSteps: Send + Sync {
    /// `getSample`.
    async fn sample(&self, file: &Path, tmp: &Path, guid: &str, hints: &MediaSourceHints) -> bool;
    /// `getMediaInfo`.
    async fn media_info(&self, file: &Path, release_id: i64) -> bool;
    /// `getVideo`.
    async fn video(&self, file: &Path, tmp: &Path, guid: &str, hints: &MediaSourceHints) -> bool;
}

/// The real media steps: previews, and media info into the database.
pub struct ReleaseMedia<'a> {
    pub previews: &'a Previews<'a>,
    pub pool: &'a MySqlPool,
    pub search: &'a dyn ReleaseSearch,
    pub mediainfo: Option<&'a Path>,
}

#[async_trait]
impl MediaSteps for ReleaseMedia<'_> {
    async fn sample(&self, file: &Path, tmp: &Path, guid: &str, hints: &MediaSourceHints) -> bool {
        self.previews.get_sample(file, tmp, guid, Some(hints)).await
    }

    async fn media_info(&self, file: &Path, release_id: i64) -> bool {
        crate::mediainfo::get_media_info(self.pool, self.search, self.mediainfo, file, release_id)
            .await
    }

    async fn video(&self, _: &Path, _: &Path, _: &str, _: &MediaSourceHints) -> bool {
        self.previews.get_video()
    }
}

/// What the stage did, in order, for the console.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum StageEvent {
    /// `(src:archive)`, `(src:video)` or `(src:mid)`.
    Source(&'static str),
    SampleCreated,
    MediaInfoAdded,
    VideoCreated,
}

impl StageEvent {
    /// The marker PHP's console printed.
    pub fn marker(self) -> String {
        match self {
            Self::Source(kind) => format!("(src:{kind})"),
            Self::SampleCreated => "s".to_owned(),
            Self::MediaInfoAdded => "m".to_owned(),
            Self::VideoCreated => "v".to_owned(),
        }
    }
}

#[derive(Clone, Debug)]
enum Source {
    Archived(Box<ArchivedMediaEntry>),
    Video(usize),
}

/// One release's downloads and limits, shared by every fetch.
struct Fetch<'a> {
    downloads: &'a mut DownloadService,
    budget: MediaByteBudget,
    deadline: Instant,
}

pub struct MediaSourceStage<'a> {
    pub settings: StageSettings,
    pub media: &'a dyn MediaSteps,
}

impl MediaSourceStage<'_> {
    /// `hasWork`.
    pub fn has_work(&self, context: &ReleaseContext) -> bool {
        self.settings.process_thumbnails
            && self.settings.stage_max_seconds > 0
            && !context.found_sample
            && !context.release_has_password
            && !context.group_unavailable
            && (!context.archived_media.is_empty() || !self.main_video_indexes(context).is_empty())
    }

    /// `run`.
    pub async fn run(
        &self,
        context: &mut ReleaseContext,
        downloads: &mut DownloadService,
    ) -> Vec<StageEvent> {
        let mut events = Vec::new();
        let budget = *context
            .media_budget
            .get_or_insert_with(|| MediaByteBudget::new(self.settings.max_bytes));
        let work = context.tmp_path.join(format!(
            "media_{}",
            hex::encode(rand::rng().random::<[u8; 4]>())
        ));
        if fs::create_dir_all(&work).is_err() {
            return events;
        }
        let _cleanup = WorkDirectory(work.clone());
        let mut fetch = Fetch {
            downloads,
            budget,
            deadline: self.deadline(context),
        };
        self.sources(context, &mut fetch, &work, &mut events).await;
        context.media_budget = Some(fetch.budget);
        events
    }

    async fn sources(
        &self,
        context: &mut ReleaseContext,
        fetch: &mut Fetch<'_>,
        work: &Path,
        events: &mut Vec<StageEvent>,
    ) {
        let mut mid_sources = Vec::new();
        for (number, source) in self.head_sources(context).into_iter().enumerate() {
            if context.found_sample || context.group_unavailable || expired(fetch.deadline) {
                break;
            }
            let base = work.join(format!("head_{number}"));
            let head = match &source {
                Source::Archived(entry) => self.archived_head(context, entry, &base, fetch).await,
                Source::Video(index) => self.video_file_head(context, *index, &base, fetch).await,
            };
            let Some((path, hints, container)) = head else {
                continue;
            };
            if expired(fetch.deadline) {
                continue;
            }
            let is_sample = matches!(&source, Source::Archived(entry) if entry.is_sample);
            events.push(StageEvent::Source(match source {
                Source::Archived(_) => "archive",
                Source::Video(_) => "video",
            }));
            self.process_head(context, &path, &hints, is_sample, events)
                .await;
            if let Some(container) = container
                && !context.found_sample
                && self.settings.mid_sources
                && container.can_resume_mid_stream()
            {
                mid_sources.push((source, path, container));
            }
        }

        for (number, (source, head_path, container)) in mid_sources.into_iter().enumerate() {
            if context.found_sample || context.group_unavailable || expired(fetch.deadline) {
                break;
            }
            let base = work.join(format!("mid_{number}"));
            let Some(mid_path) = self
                .mid_chunk(context, &source, &head_path, container, &base, fetch)
                .await
            else {
                continue;
            };
            if expired(fetch.deadline) {
                continue;
            }
            events.push(StageEvent::Source("mid"));
            let guid = context.release.guid.clone();
            let tmp = context.tmp_path.clone();
            if self
                .media
                .sample(&mid_path, &tmp, &guid, &MediaSourceHints::mid(0))
                .await
            {
                context.found_sample = true;
                events.push(StageEvent::SampleCreated);
            }
        }
    }

    /// Archived entries and NZB source indexes, best first.
    fn head_sources(&self, context: &ReleaseContext) -> Vec<Source> {
        let archived = |sample: bool| {
            context
                .archived_media
                .iter()
                .filter(move |entry| entry.is_sample == sample)
                .map(|entry| Source::Archived(Box::new(entry.clone())))
        };
        archived(true)
            .chain(
                self.main_video_indexes(context)
                    .iter()
                    .map(|index| Source::Video(*index)),
            )
            .chain(archived(false))
            .collect()
    }

    fn main_video_indexes<'c>(&self, context: &'c ReleaseContext) -> &'c [usize] {
        match &context.work_plan {
            Some(plan) if self.settings.main_video_fallback => &plan.main_video_source_indexes,
            _ => &[],
        }
    }

    async fn process_head(
        &self,
        context: &mut ReleaseContext,
        path: &Path,
        hints: &MediaSourceHints,
        is_sample: bool,
        events: &mut Vec<StageEvent>,
    ) {
        let guid = context.release.guid.clone();
        let tmp = context.tmp_path.clone();
        if !context.found_sample && self.media.sample(path, &tmp, &guid, hints).await {
            context.found_sample = true;
            events.push(StageEvent::SampleCreated);
        }
        if !context.found_media_info && self.media.media_info(path, context.release.id).await {
            context.found_media_info = true;
            events.push(StageEvent::MediaInfoAdded);
        }
        // Only a sample makes a fair clip; a feature's first seconds are usually logos.
        if is_sample && !context.found_video && self.media.video(path, &tmp, &guid, hints).await {
            context.found_video = true;
            events.push(StageEvent::VideoCreated);
        }
    }

    /// The archived video's start, continued from later segments of its volume when that helps.
    async fn archived_head(
        &self,
        context: &mut ReleaseContext,
        entry: &ArchivedMediaEntry,
        base: &Path,
        fetch: &mut Fetch<'_>,
    ) -> Option<(PathBuf, MediaSourceHints, Option<VideoContainer>)> {
        let path = with_extension(
            base,
            entry.container.map_or("bin", VideoContainer::extension),
        );
        fs::write(&path, &entry.head_bytes).ok()?;
        let continuation = entry.continuation_bytes(self.settings.sample_max_bytes);
        if continuation > 0 {
            let segments = segments_of(context, entry.source_index);
            let estimate = article_estimate(context, entry.source_index);
            append(
                context,
                fetch,
                &segments,
                entry.volume_segments_downloaded,
                Some(entry.volume_bytes_downloaded),
                continuation,
                &path,
                estimate,
            )
            .await;
        }
        let size = fs::metadata(&path).map_or(0, |meta| meta.len() as i64);
        Some((
            path,
            MediaSourceHints::head(Some(entry.real_size), size),
            entry.container,
        ))
    }

    /// The start of a video file posted outside any archive.
    async fn video_file_head(
        &self,
        context: &mut ReleaseContext,
        index: usize,
        base: &Path,
        fetch: &mut Fetch<'_>,
    ) -> Option<(PathBuf, MediaSourceHints, Option<VideoContainer>)> {
        let name = context
            .media_plan
            .as_ref()
            .and_then(|plan| plan.mapped.get(&index))
            .map(|mapped| mapped.file.name.clone())
            .or_else(|| {
                context
                    .nzb_contents
                    .get(index)
                    .map(|file| file.title.clone())
            })
            .unwrap_or_default();
        let path = with_extension(
            base,
            VideoContainer::from_file_name(&name).map_or("bin", VideoContainer::extension),
        );
        let segments = segments_of(context, index);
        let estimate = article_estimate(context, index);
        let written = append(
            context,
            fetch,
            &segments,
            0,
            Some(0),
            self.settings.sample_max_bytes,
            &path,
            estimate,
        )
        .await;
        if written == 0 {
            let _ = fs::remove_file(&path);
            return None;
        }
        let real_size = context
            .media_plan
            .as_ref()
            .and_then(|plan| plan.mapped.get(&index))
            .map(|mapped| mapped.file.size)
            .unwrap_or_else(|| {
                let posted = context.nzb_contents.get(index).map_or(0, |file| file.size);
                (posted as f64 * POSTED_SIZE_TO_REAL_SIZE).round() as i64
            });
        let container = VideoContainer::detect(&read_head(&path, 4096))
            .or_else(|| VideoContainer::from_file_name(&name));
        Some((
            path,
            MediaSourceHints::head(Some(real_size), written),
            container,
        ))
    }

    /// Bytes from the middle of the video, given the head's container headers.
    async fn mid_chunk(
        &self,
        context: &mut ReleaseContext,
        source: &Source,
        head_path: &Path,
        container: VideoContainer,
        base: &Path,
        fetch: &mut Fetch<'_>,
    ) -> Option<PathBuf> {
        let raw = with_extension(base, "raw");
        let mid = self.mid_bytes(context, source, &raw, fetch).await;
        let _ = fs::remove_file(&raw);
        let head = fs::read(head_path).ok()?;
        let spliced = splicer::splice(&head, &mid?, container)?;
        let path = with_extension(base, container.extension());
        fs::write(&path, spliced).ok()?;
        Some(path)
    }

    async fn mid_bytes(
        &self,
        context: &mut ReleaseContext,
        source: &Source,
        raw: &Path,
        fetch: &mut Fetch<'_>,
    ) -> Option<Vec<u8>> {
        match source {
            Source::Archived(entry) => {
                let volume = middle_volume(context, entry.source_index)?;
                let segments = segments_of(context, volume.source_index);
                let estimate = article_estimate(context, volume.source_index);
                let written = append(
                    context,
                    fetch,
                    &segments,
                    0,
                    Some(0),
                    self.settings.mid_bytes,
                    raw,
                    estimate,
                )
                .await;
                if written == 0 {
                    return None;
                }
                slicer::chunk_from_volume(&fs::read(raw).unwrap_or_default(), &entry.name)
            }
            Source::Video(index) => {
                let segments = segments_of(context, *index);
                if segments.len() < 4 {
                    return None;
                }
                let estimate = article_estimate(context, *index);
                let written = append(
                    context,
                    fetch,
                    &segments,
                    segments.len() / 2,
                    None,
                    self.settings.mid_bytes,
                    raw,
                    estimate,
                )
                .await;
                (written > 0).then(|| fs::read(raw).unwrap_or_default())
            }
        }
    }

    /// Early enough that one more ffmpeg call cannot push the release past its own timeout.
    fn deadline(&self, context: &ReleaseContext) -> Instant {
        let stage = Instant::now() + seconds(self.settings.stage_max_seconds);
        if self.settings.release_timeout_seconds <= 0 {
            return stage;
        }
        let allowed = self.settings.release_timeout_seconds - (self.settings.ffmpeg_timeout + 5);
        let release = if allowed >= 0 {
            context.started() + seconds(allowed)
        } else {
            context
                .started()
                .checked_sub(seconds(-allowed))
                .unwrap_or_else(|| context.started())
        };
        stage.min(release)
    }
}

/// `MediaArticleFetcher::append`: consecutive articles of one NZB file appended to `path`.
/// Every article's yEnc header must say it starts where the previous one ended, so a missing
/// article or an out-of-order NZB never leaves a hole. `expected_offset` is where the first
/// article must start, or `None` to accept any. Returns the bytes appended.
#[allow(clippy::too_many_arguments)]
async fn append(
    context: &mut ReleaseContext,
    fetch: &mut Fetch<'_>,
    segments: &[String],
    from_segment: usize,
    mut expected_offset: Option<i64>,
    max_bytes: i64,
    path: &Path,
    article_estimate: i64,
) -> i64 {
    let mut written = 0i64;
    let first_expected = expected_offset;
    let mut index = from_segment;
    while index < segments.len() && written < max_bytes {
        if context.group_unavailable
            || fetch.budget.exhausted()
            || expired(fetch.deadline)
            || !fetch.budget.allows(article_estimate)
        {
            break;
        }
        let group = context.release_group_name.clone();
        let result = fetch
            .downloads
            .download_article(DownloadKind::MediaSource, &segments[index], &group)
            .await;
        if result.download.group_unavailable {
            context.group_unavailable = true;
            break;
        }
        let Some(data) = result.download.data else {
            break;
        };
        fetch.budget.spend(data.len() as i64);
        // A cached copy has no yEnc header; only a file's first article is placed without one.
        let offset = match &result.part {
            Some(part) => part.offset(),
            None if index == 0 && expected_offset == Some(0) => 0,
            None => break,
        };
        if expected_offset.is_some_and(|expected| expected != offset) {
            break;
        }
        let room = usize::try_from(max_bytes - written).unwrap_or(0);
        let chunk = &data[..data.len().min(room)];
        let appended = OpenOptions::new()
            .create(true)
            .append(true)
            .open(path)
            .and_then(|mut file| file.write_all(chunk));
        if appended.is_err() {
            break;
        }
        if index == 0
            && first_expected == Some(0)
            && let Some(part) = &result.part
            && let Some(witness) = direct_video_witness(context, part, &data)
        {
            let key = hex::encode(Sha256::digest(segments[index].as_bytes()));
            context.direct_video_witnesses.insert(key, witness);
        }
        written += chunk.len() as i64;
        expected_offset = Some(offset + data.len() as i64);
        index += 1;
    }
    written
}

/// A direct video's first article, when its header names a plain video file that the data
/// agrees with.
fn direct_video_witness(
    context: &ReleaseContext,
    part: &nntmux_yenc::YencPart,
    data: &[u8],
) -> Option<DirectVideoWitness> {
    let digest = context.nzb_source_digest.as_ref()?;
    if part.offset() != 0
        || part.part.is_some_and(|number| number != 1)
        || part.size <= 0
        || data.len() as i64 > part.size
    {
        return None;
    }
    let name = std::str::from_utf8(&part.name).ok()?;
    if name.is_empty()
        || name.len() > MAX_WITNESS_NAME_BYTES
        || name.bytes().any(|byte| byte < 0x20 || byte == 0x7f)
        || name.contains(['/', '\\'])
        || name.starts_with(['.', '-'])
        || has_archive_signature(data)
    {
        return None;
    }
    let container = VideoContainer::detect(data)?;
    let extension = name.rsplit_once('.').map_or("", |(_, extension)| extension);
    if Some(container) != VideoContainer::from_file_name(&format!("video.{extension}")) {
        return None;
    }
    Some(DirectVideoWitness {
        name: name.to_owned(),
        size: part.size,
        digest: digest.clone(),
    })
}

/// Another volume of the same archive set, from the middle of the set when names give the order.
fn middle_volume(context: &ReleaseContext, head_index: usize) -> Option<ArchiveCandidate> {
    let plan = context.work_plan.as_ref()?;
    let mut volumes: Vec<&ArchiveCandidate> = plan
        .archive_candidates
        .iter()
        .filter(|candidate| candidate.source_index != head_index)
        .collect();
    if volumes.is_empty() {
        return None;
    }
    // `strnatcasecmp` folds to upper case.
    volumes.sort_by(|a, b| {
        strnatcmp(
            a.title.to_ascii_uppercase().as_bytes(),
            b.title.to_ascii_uppercase().as_bytes(),
        )
    });
    Some(volumes[volumes.len() / 2].clone())
}

fn segments_of(context: &ReleaseContext, index: usize) -> Vec<String> {
    context
        .nzb_contents
        .get(index)
        .map(|file| file.segments.clone())
        .unwrap_or_default()
}

/// The posted bytes per article, rounded up.
fn article_estimate(context: &ReleaseContext, index: usize) -> i64 {
    let Some(file) = context.nzb_contents.get(index) else {
        return 0;
    };
    let segments = file.segments.len().max(1) as f64;
    (file.size as f64 / segments).ceil() as i64
}

fn with_extension(base: &Path, extension: &str) -> PathBuf {
    let mut name = base.as_os_str().to_owned();
    name.push(".");
    name.push(extension);
    PathBuf::from(name)
}

fn read_head(path: &Path, limit: u64) -> Vec<u8> {
    let mut head = Vec::new();
    if let Ok(file) = fs::File::open(path) {
        let _ = file.take(limit).read_to_end(&mut head);
    }
    head
}

fn seconds(count: i64) -> Duration {
    Duration::from_secs(u64::try_from(count).unwrap_or(0))
}

fn expired(deadline: Instant) -> bool {
    Instant::now() >= deadline
}

/// Removes the stage's work directory however the stage ends.
struct WorkDirectory(PathBuf);

impl Drop for WorkDirectory {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}
