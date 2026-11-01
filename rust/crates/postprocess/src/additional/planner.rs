//! Decides what to download from an NZB, from its subjects alone. Port of `AdditionalWorkPlanner`,
//! `AdditionalWorkPlan`, `ArchiveCandidate` and `ImageCandidateRanker`.
//!
//! Patterns are PHP's, compiled through [`Pcre`], because they use lookaheads and match bytes.

use std::collections::{HashMap, HashSet};
use std::sync::LazyLock;

use nntmux_core::pcre::{Match, Pcre};
use serde::Serialize;

use crate::config::PlannerConfig;

/// Posted images smaller than this are icons or banners.
const MIN_IMAGE_POSTED_BYTES: i64 = 10_240;
const MAX_IMAGE_CANDIDATES: usize = 3;
const TYPICAL_ARTICLE_BYTES: i64 = 716_800;

/// `(audio file extension)`, without the delimiters.
pub const AUDIO_FILE: &str =
    r"\.(AAC|AIFF|APE|AC3|ASF|DTS|FLAC|MKA|MKS|MP2|MP3|RA|OGG|OGM|W64|WAV|WMA)";
/// `(video file extension)`, without the delimiters.
pub const VIDEO_FILE: &str =
    r"\.(AVI|F4V|IFO|M1V|M2V|M4V|MKV|MOV|MP4|MPEG|MPG|MPGV|MPV|OGV|QT|RM|RMVB|TS|VOB|WMV)";
/// PAR2, SRS, SFV and NZB names, without the delimiters.
pub const SUPPORT_FILE: &str = r"\.(?:vol\d{1,3}\+\d{1,3}|par2|srs|sfv|nzb)";

macro_rules! pattern {
    ($name:ident, $pattern:expr) => {
        static $name: LazyLock<Pcre> =
            LazyLock::new(|| Pcre::new($pattern).expect("a valid regex"));
    };
}

pattern!(SEVEN_ZIP, r#"/([^"\s\/]+)\.7z(?:\.(\d{3}))?($|[ ")]|-)/i"#);
pattern!(SEVEN_ZIP_QUOTED, r#"/"([^"\r\n]+)\.7z(?:\.(\d{3}))?"/i"#);
pattern!(
    NUMBERED_VOLUME,
    r#"/"([^"\r\n]+)\.(\d{3})"|(?:^|\s)([^"\s\/]+)\.(\d{3})(?=$|\s+(?:yEnc\b|\(\d+\/\d+\)))/i"#
);
pattern!(
    ARCHIVE,
    r#"/(\.(part\d+|[rz]\d+|rar|7z|0+|0*10?|zipr\d{2,3}|zipx?)("|\s*\.rar)*($|[ ")]|-])|"[a-f0-9]{32}\.[1-9]\d{1,2}".*\(\d+\/\d{2,}\)$)/i"#
);
pattern!(
    IGNORE_BOOK,
    r"/\b(epub|lit|mobi|pdf|sipdf|html)\b.*\.rar(?!.{20,})/i"
);
pattern!(IMAGE_END, r"/\.(?:jpe?g|png|webp)$/i");
pattern!(IMAGE_ANY, r#"/\.(?:jpe?g|png|webp)(?:[. ")\]]|$)/i"#);
pattern!(
    MUSIC_GROUP,
    r"/flac|lossless|mp3|music|inner-sanctum|sound/i"
);
pattern!(
    SUPPORT,
    &format!(r#"/(?:{SUPPORT_FILE}|nfo\b|inf\b|ofn\b)($|[ ")]|-])(?!.{{20,}})/i"#)
);
pattern!(VIDEO_DELIMITED, &format!(r#"/{VIDEO_FILE}[. ")\]]/i"#));
pattern!(
    VIDEO_DELIMITED_OR_END,
    &format!(r#"/{VIDEO_FILE}(?:[. ")\]]|$)/i"#)
);
pattern!(AUDIO_DELIMITED, &format!(r#"/{AUDIO_FILE}[. ")\]]/i"#));
pattern!(
    MEDIA_SPLIT,
    &format!(r"/{VIDEO_FILE}\.(?<volume>\d{{3}})$/i")
);
pattern!(QUOTED_NAME, r#"/"([^"]+)"/"#);
pattern!(QUOTED_NAME_NOLINE, r#"/"([^"\r\n]+)"/"#);
pattern!(HAS_EXTENSION, r"/\.[a-z0-9]{2,4}$/i");
pattern!(PAR2, r"/\.par2\b/i");
pattern!(PAR2_VOLUME, r"/\.vol\d+[+-]\d+\.par2\b/i");
pattern!(PART_NUMBER, r"/\.part0*(\d+)/i");
pattern!(
    HASHED_POSITION,
    r#"/"[a-f0-9]{32}\.[1-9]\d{1,2}".*\((\d+)\/\d{2,}\)$/i"#
);
pattern!(RAR_OR_ZIP, r#"/\.(rar|zip)($|[ ")]|-])/i"#);
pattern!(RAR_PART_QUOTED, r#"/^(.+)\.part(\d+)\.rar(?:$|[ ")-])/i"#);
pattern!(RAR_QUOTED, r#"/^(.+)\.rar(?:$|[ ")-])/i"#);
pattern!(RAR_OLD_QUOTED, r#"/^(.+)\.r(\d{2,3})(?:$|[ ")-])/i"#);
pattern!(RAR_PART, r#"/([^"\s\/]+)\.part(\d+)\.rar(?:$|[ ")-])/i"#);
pattern!(RAR, r#"/([^"\s\/]+)\.rar(?:$|[ ")-])/i"#);
pattern!(RAR_OLD, r#"/([^"\s\/]+)\.r(\d{2,3})(?:$|[ ")-])/i"#);
pattern!(SET_RAR, r"/^(.+?)\.(?:part\d+\.rar|rar|r\d{2,3})$/");
pattern!(SET_7Z, r"/^(.+?)\.7z(?:\.\d{3})?$/");
pattern!(SET_ZIP, r"/^(.+?)\.(?:zipx?|zipr\d{2,3}|z\d{2,3})$/");
pattern!(
    SET_SPLIT,
    r"/^([a-f0-9]{32})\.[1-9]\d{1,2}$|^(.+?)\.\d{3}$/"
);
pattern!(YENC_TAIL, r"/(?:^|\s)yEnc\b.*$/is");
pattern!(
    TRAILING_COUNTER,
    r"/(?:\s*(?:-|[\[(]\s*\d+\s*(?:\/|of)\s*\d+\s*[\])]))+\s*$/i"
);
pattern!(
    LEADING_COUNTER,
    r"/^(?:\s*(?:-(?=\s)|[\[(]\s*\d+\s*(?:\/|of)\s*\d+\s*[\])]))+\s*/i"
);
pattern!(
    INNER_COUNTER,
    r"/\s+[\[(]\s*\d+\s*(?:\/|of)\s*\d+\s*[\])]\s+-\s+/i"
);
pattern!(
    RANK_CONTENT,
    r"/(?:^|[^a-z])(?:screens?|screenshots?|screencaps?|caps|contact[ ._-]?sheets?|sheet|thumbs?|thumbnails?|previews?|samples?)(?:[^a-z]|$)/i"
);
pattern!(
    RANK_COVER,
    r"/(?:^|[^a-z])(?:cover|front|poster|folder|box|keyart)(?:[^a-z]|$)/i"
);
pattern!(
    RANK_LEAST,
    r"/(?:^|[^a-z])(?:back|rear|cd\d*|dvd\d*|disc\d*|disk\d*|label|inlay|insert|proof|logo|banner|avatar|sig|signature|spine)(?:[^a-z]|$)/i"
);

fn is_match(pattern: &Pcre, subject: &str) -> bool {
    pattern.is_match(subject.as_bytes())
}

fn group(found: &Match, index: usize) -> String {
    found
        .group(index)
        .map(|bytes| String::from_utf8_lossy(bytes).into_owned())
        .unwrap_or_default()
}

/// PHP's `(int)` on a run of digits.
fn int(text: &str) -> i64 {
    text.parse()
        .unwrap_or(if text.is_empty() { 0 } else { i64::MAX })
}

/// One file entry from `NzbParserService::parseNzbFileList`.
#[derive(Clone, Debug, Default, PartialEq, Eq, serde::Deserialize)]
pub struct NzbFile {
    pub title: String,
    pub segments: Vec<String>,
    pub size: i64,
    /// Stripped subjects can merge several NZB files into one entry.
    pub filecount: i64,
    /// Segment 1 holds the file header; without it the file can't be inspected.
    pub firstsegment: bool,
}

/// An NZB file worth reading as an archive.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize)]
pub struct ArchiveCandidate {
    pub title: String,
    pub message_ids: Vec<String>,
    pub likely_first_volume: bool,
    pub source_index: usize,
    pub tail_message_ids: Vec<String>,
    pub has_first_segment: bool,
    pub set_key: String,
}

impl ArchiveCandidate {
    /// The key shared by every volume or piece of this candidate's file.
    pub fn volume_set(&self) -> String {
        volume_set_key(&self.title)
    }
}

/// A posted image, with every article it needs to arrive whole.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct ImageCandidate {
    pub title: String,
    pub message_ids: Vec<String>,
    pub source_index: usize,
    pub posted_size: i64,
}

/// What to download for one release.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize)]
pub struct WorkPlan {
    pub sample_message_ids: Vec<String>,
    pub jpg_message_ids: Vec<String>,
    pub media_info_message_id: String,
    pub audio_info_message_id: String,
    pub audio_info_extension: String,
    pub archive_candidates: Vec<ArchiveCandidate>,
    pub book_file_count: i64,
    pub book_flood: bool,
    pub duplicate_message_id_count: i64,
    pub unsupported_reasons: Vec<String>,
    pub probe_candidates: Vec<ArchiveCandidate>,
    pub probe_is_only_file: bool,
    pub par2_index_candidates: Vec<ArchiveCandidate>,
    pub main_video_source_indexes: Vec<usize>,
    pub image_candidates: Vec<ImageCandidate>,
}

impl WorkPlan {
    /// Fills what this plan lacks from a plan made after the PAR2 index revealed real file names.
    /// Anything found under the posted names is kept.
    pub fn with_mapped_names(&self, mapped: Self) -> Self {
        let take_archives =
            self.archive_candidates.is_empty() && !mapped.archive_candidates.is_empty();
        let keep_audio = !self.audio_info_message_id.is_empty();
        let keep_images = !self.jpg_message_ids.is_empty();
        let keep_probes = !self.archive_candidates.is_empty();
        let mut merged = Self {
            sample_message_ids: first_nonempty(&self.sample_message_ids, mapped.sample_message_ids),
            jpg_message_ids: first_nonempty(&self.jpg_message_ids, mapped.jpg_message_ids),
            media_info_message_id: if self.media_info_message_id.is_empty() {
                mapped.media_info_message_id
            } else {
                self.media_info_message_id.clone()
            },
            audio_info_message_id: if keep_audio {
                self.audio_info_message_id.clone()
            } else {
                mapped.audio_info_message_id
            },
            audio_info_extension: if keep_audio {
                self.audio_info_extension.clone()
            } else {
                mapped.audio_info_extension
            },
            archive_candidates: if take_archives {
                mapped.archive_candidates
            } else {
                self.archive_candidates.clone()
            },
            book_file_count: self.book_file_count,
            book_flood: self.book_flood,
            duplicate_message_id_count: self.duplicate_message_id_count,
            unsupported_reasons: self.unsupported_reasons.clone(),
            // Only files still unnamed are worth probing.
            probe_candidates: if keep_probes {
                self.probe_candidates.clone()
            } else {
                mapped.probe_candidates
            },
            probe_is_only_file: if keep_probes {
                self.probe_is_only_file
            } else {
                mapped.probe_is_only_file
            },
            par2_index_candidates: self.par2_index_candidates.clone(),
            main_video_source_indexes: first_nonempty(
                &self.main_video_source_indexes,
                mapped.main_video_source_indexes,
            ),
            image_candidates: if keep_images {
                self.image_candidates.clone()
            } else {
                mapped.image_candidates
            },
        };
        if merged.has_supported_candidates() {
            merged
                .unsupported_reasons
                .retain(|reason| reason != "no-supported-candidates");
        }
        merged
    }

    pub fn has_supported_candidates(&self) -> bool {
        !self.archive_candidates.is_empty()
            || !self.sample_message_ids.is_empty()
            || !self.jpg_message_ids.is_empty()
            || !self.media_info_message_id.is_empty()
            || !self.audio_info_message_id.is_empty()
            || !self.probe_candidates.is_empty()
    }

    pub fn has_compressed_file(&self) -> bool {
        !self.archive_candidates.is_empty()
    }

    /// Distinct archive sets in the release, such as one per episode of a season pack.
    pub fn archive_set_count(&self) -> usize {
        self.archive_candidates
            .iter()
            .map(|candidate| self.set_key_of(candidate))
            .collect::<HashSet<_>>()
            .len()
    }

    pub fn set_key_of(&self, candidate: &ArchiveCandidate) -> String {
        if candidate.set_key.is_empty() {
            format!("src:{}", candidate.source_index)
        } else {
            candidate.set_key.clone()
        }
    }

    /// First volumes first, keeping each group's order.
    pub fn prioritized_archive_candidates(&self) -> Vec<ArchiveCandidate> {
        let (first, rest): (Vec<_>, Vec<_>) = self
            .archive_candidates
            .iter()
            .cloned()
            .partition(|candidate| candidate.likely_first_volume);
        [first, rest].concat()
    }
}

fn first_nonempty<T: Clone>(own: &[T], mapped: Vec<T>) -> Vec<T> {
    if own.is_empty() { mapped } else { own.to_vec() }
}

/// Builds the plan for a release's NZB.
pub fn plan(config: &PlannerConfig, nzb: &[NzbFile], group_name: &str) -> WorkPlan {
    let mut plan = WorkPlan::default();
    let mut seen = Seen::default();
    let mut images: Vec<(usize, &NzbFile)> = Vec::new();
    let mut seven_zip_tails: HashMap<String, (i64, String)> = HashMap::new();
    let music_group = is_match(&MUSIC_GROUP, group_name);

    for (source_index, file) in nzb.iter().enumerate() {
        let title = file.title.as_str();
        let segments = file.segments.as_slice();

        if is_match(&IGNORE_BOOK, title) {
            plan.book_file_count += 1;
        }

        // 7z keeps its file list at the end of the last volume.
        if let (Some((set, number)), Some(last)) = (split_volume(title), segments.last()) {
            let behind = seven_zip_tails
                .get(&set)
                .is_none_or(|(seen, _)| *seen < number);
            if behind {
                seven_zip_tails.insert(set, (number, last.clone()));
            }
        }

        // HJSplit-style pieces of a video (name.mkv.001) are usually plain media but may hide an
        // archive: only the first article of the first piece is checked, by its data.
        if let Some(piece) = media_split_volume(title) {
            let message_ids = if piece <= 1 {
                seen.extract(segments, 1)
            } else {
                Vec::new()
            };
            if !message_ids.is_empty() {
                plan.archive_candidates.push(ArchiveCandidate {
                    title: title.to_owned(),
                    message_ids,
                    likely_first_volume: true,
                    source_index,
                    tail_message_ids: Vec::new(),
                    has_first_segment: file.firstsegment,
                    set_key: volume_set_key(title),
                });
            }
        } else if is_match(&ARCHIVE, title) {
            let message_ids = seen.extract(segments, config.maximum_rar_segments);
            if !message_ids.is_empty() {
                plan.archive_candidates.push(ArchiveCandidate {
                    title: title.to_owned(),
                    message_ids,
                    likely_first_volume: is_likely_first_volume(title),
                    source_index,
                    tail_message_ids: Vec::new(),
                    has_first_segment: file.firstsegment,
                    set_key: volume_set_key(title),
                });
            }
        }

        if is_match(&SUPPORT, title) {
            continue;
        }

        let is_sample = contains_ignore_case(title, "sample");
        if config.process_thumbnails
            && plan.sample_message_ids.is_empty()
            && !segments.is_empty()
            && is_sample
            && !is_match(&IMAGE_END, title)
            && !is_match(&ARCHIVE, title)
        {
            plan.sample_message_ids = seen.extract(segments, config.segments_to_download);
        }

        if config.process_jpg_sample
            && !segments.is_empty()
            && !music_group
            && is_match(&IMAGE_ANY, title)
        {
            images.push((source_index, file));
        }

        if config.process_media_info
            && plan.media_info_message_id.is_empty()
            && is_sample
            && is_match(&VIDEO_DELIMITED, title)
            && let Some(first) = segments.first()
        {
            plan.media_info_message_id = first.clone();
            seen.record(first);
        }

        if config.process_audio_info
            && plan.audio_info_message_id.is_empty()
            && !segments.is_empty()
            && let Some(found) = AUDIO_DELIMITED.captures(title.as_bytes())
        {
            plan.audio_info_extension = group(&found, 1);
            plan.audio_info_message_id = segments[0].clone();
            seen.record(&segments[0]);
        }
    }

    for candidate in &mut plan.archive_candidates {
        let tail = split_volume(&candidate.title)
            .and_then(|(set, _)| seven_zip_tails.get(&set))
            .map(|(_, tail)| tail);
        if let Some(tail) = tail
            && candidate.likely_first_volume
            && !candidate.message_ids.contains(tail)
        {
            candidate.tail_message_ids = vec![tail.clone()];
        }
    }

    prefer_rar_volumes_with_headers(&mut plan.archive_candidates);

    // Files without an extension can't be classified by name; sample their first segments. Each
    // entry must stand for exactly one NZB file, not several merged by a stripped subject.
    if plan.archive_candidates.is_empty() {
        let mut unnamed: Vec<(usize, &NzbFile)> = nzb
            .iter()
            .enumerate()
            .filter(|(_, file)| {
                !file.segments.is_empty() && file.filecount == 1 && !has_file_extension(&file.title)
            })
            .collect();
        plan.probe_is_only_file = !unnamed.is_empty() && nzb.len() == 1;

        // Archive volumes carry the payload, so the largest files are tried first.
        unnamed.sort_by_key(|entry| std::cmp::Reverse(entry.1.size));
        let probes = if plan.probe_is_only_file {
            1
        } else {
            usize::try_from(config.archive_probe_files).unwrap_or(0)
        };
        for (source_index, file) in unnamed.into_iter().take(probes) {
            let message_ids = seen.extract(&file.segments, config.maximum_rar_segments.max(1));
            let Some(first) = message_ids.first() else {
                continue;
            };
            // A 7z found by the probe needs its last segment for the file list.
            let last = &file.segments[file.segments.len() - 1];
            plan.probe_candidates.push(ArchiveCandidate {
                title: file.title.clone(),
                tail_message_ids: if last == first {
                    Vec::new()
                } else {
                    vec![last.clone()]
                },
                message_ids,
                likely_first_volume: false,
                source_index,
                has_first_segment: true,
                set_key: String::new(),
            });
        }
    }

    plan.image_candidates = image_candidates(config, images, &mut seen);
    plan.jpg_message_ids = plan
        .image_candidates
        .first()
        .map(|candidate| candidate.message_ids.clone())
        .unwrap_or_default();
    if config.par2_media_mapping {
        plan.par2_index_candidates = par2_index_candidates(config, nzb);
    }
    plan.main_video_source_indexes = main_video_source_indexes(config, nzb);
    plan.duplicate_message_id_count = seen.duplicates;

    plan.book_flood = plan.book_file_count > 80
        && plan.book_file_count * 2 >= i64::try_from(nzb.len()).unwrap_or(i64::MAX);
    if plan.book_flood {
        plan.unsupported_reasons.push("book-flood".to_owned());
    }
    if !plan.has_supported_candidates() {
        plan.unsupported_reasons
            .push("no-supported-candidates".to_owned());
    }
    plan
}

fn contains_ignore_case(haystack: &str, needle: &str) -> bool {
    haystack.to_ascii_lowercase().contains(needle)
}

/// Message ids already planned, so duplicates can be counted.
#[derive(Default)]
struct Seen {
    ids: HashSet<String>,
    duplicates: i64,
}

impl Seen {
    fn record(&mut self, message_id: &str) {
        if !self.ids.insert(message_id.to_owned()) {
            self.duplicates += 1;
        }
    }

    /// Up to `limit` of the leading segments, each once.
    fn extract(&mut self, segments: &[String], limit: i64) -> Vec<String> {
        let mut message_ids: Vec<String> = Vec::new();
        for segment in segments.iter().take(usize::try_from(limit).unwrap_or(0)) {
            if segment.is_empty() {
                continue;
            }
            if message_ids.contains(segment) {
                self.duplicates += 1;
                continue;
            }
            message_ids.push(segment.clone());
            self.record(segment);
        }
        message_ids
    }
}

/// The best few posted images. Icons and images too large to download whole are skipped.
fn image_candidates(
    config: &PlannerConfig,
    images: Vec<(usize, &NzbFile)>,
    seen: &mut Seen,
) -> Vec<ImageCandidate> {
    let mut ranked = images;
    ranked.sort_by_key(|(_, file)| (image_rank(&file.title), -file.size));
    let mut candidates = Vec::new();
    for (source_index, file) in ranked {
        let size = file.size;
        if size > 0 && (size < MIN_IMAGE_POSTED_BYTES || size > config.media_image_max_bytes) {
            continue;
        }
        // Without a posted size, fetch as many articles as the size limit could fill.
        let limit = if size > 0 {
            i64::try_from(file.segments.len()).unwrap_or(i64::MAX)
        } else {
            config.segments_to_download.max(
                (config.media_image_max_bytes + TYPICAL_ARTICLE_BYTES - 1) / TYPICAL_ARTICLE_BYTES,
            )
        };
        let message_ids = seen.extract(&file.segments, limit);
        if message_ids.is_empty() {
            continue;
        }
        candidates.push(ImageCandidate {
            title: file.title.clone(),
            message_ids,
            source_index,
            posted_size: size,
        });
        if candidates.len() >= MAX_IMAGE_CANDIDATES {
            break;
        }
    }
    candidates
}

/// The PAR2 index lists every file's real name and size. It's the smallest `.par2` that isn't a
/// recovery volume or, in an obfuscated release, one of its smallest unnamed files.
fn par2_index_candidates(config: &PlannerConfig, nzb: &[NzbFile]) -> Vec<ArchiveCandidate> {
    let mut named = Vec::new();
    let mut unnamed = Vec::new();
    for (source_index, file) in nzb.iter().enumerate() {
        if file.segments.is_empty() || file.filecount != 1 {
            continue;
        }
        if is_match(&PAR2, &file.title) {
            if !is_match(&PAR2_VOLUME, &file.title) {
                named.push((source_index, file));
            }
        } else if !has_file_extension(&file.title) {
            unnamed.push((source_index, file));
        }
    }
    named.sort_by_key(|(_, file)| file.size);
    unnamed.sort_by_key(|(_, file)| file.size);
    let chosen: Vec<(usize, &NzbFile)> = if !named.is_empty() {
        named.into_iter().take(1).collect()
    } else if unnamed.len() >= 3 {
        // A lone unnamed file is the release itself, not an index of it.
        unnamed.into_iter().take(2).collect()
    } else {
        Vec::new()
    };
    chosen
        .into_iter()
        .map(|(source_index, file)| {
            let mut message_ids: Vec<String> = Vec::new();
            for segment in file
                .segments
                .iter()
                .take(usize::try_from(config.par2_index_max_segments).unwrap_or(0))
            {
                if !message_ids.contains(segment) {
                    message_ids.push(segment.clone());
                }
            }
            ArchiveCandidate {
                title: file.title.clone(),
                message_ids,
                likely_first_volume: false,
                source_index,
                tail_message_ids: Vec::new(),
                has_first_segment: true,
                set_key: String::new(),
            }
        })
        .collect()
}

/// Full-length video files posted as they are, largest first, which can stand in for a sample.
fn main_video_source_indexes(config: &PlannerConfig, nzb: &[NzbFile]) -> Vec<usize> {
    if !config.media_main_video_fallback || !config.process_thumbnails {
        return Vec::new();
    }
    let mut sizes: Vec<(usize, i64)> = nzb
        .iter()
        .enumerate()
        .filter(|(_, file)| {
            !file.segments.is_empty()
                && file.filecount == 1
                && file.size >= config.media_main_video_min_bytes
                && !contains_ignore_case(&file.title, "sample")
                && !is_match(&ARCHIVE, &file.title)
                && is_match(&VIDEO_DELIMITED_OR_END, &file.title)
        })
        .map(|(index, file)| (index, file.size))
        .collect();
    sizes.sort_by_key(|entry| std::cmp::Reverse(entry.1));
    sizes.into_iter().take(2).map(|(index, _)| index).collect()
}

/// Every RAR volume starts with its own archive and file headers, but only in segment 1. When the
/// first volume lacks that segment, the earliest volume that has it is inspected instead.
fn prefer_rar_volumes_with_headers(candidates: &mut [ArchiveCandidate]) {
    let mut order: Vec<String> = Vec::new();
    let mut sets: HashMap<String, Vec<(usize, i64)>> = HashMap::new();
    for (index, candidate) in candidates.iter().enumerate() {
        if let Some((set, volume)) = rar_volume(&candidate.title) {
            if !sets.contains_key(&set) {
                order.push(set.clone());
            }
            sets.entry(set).or_default().push((index, volume));
        }
    }
    for set in order {
        let Some(mut volumes) = sets.remove(&set) else {
            continue;
        };
        volumes.sort_by_key(|(_, volume)| *volume);
        let first = volumes[0].0;
        if !candidates[first].likely_first_volume || candidates[first].has_first_segment {
            continue;
        }
        let replacement = volumes
            .iter()
            .map(|(index, _)| *index)
            .find(|index| candidates[*index].has_first_segment);
        if let Some(index) = replacement {
            candidates[first].likely_first_volume = false;
            candidates[index].likely_first_volume = true;
        }
    }
}

/// Set name and volume order (`.rar` before `.r00`, `part01` before `part02`).
fn rar_volume(title: &str) -> Option<(String, i64)> {
    let quoted = QUOTED_NAME_NOLINE.captures(title.as_bytes());
    let (name, part, plain, old) = match &quoted {
        Some(found) => (
            group(found, 1),
            &*RAR_PART_QUOTED,
            &*RAR_QUOTED,
            &*RAR_OLD_QUOTED,
        ),
        None => (title.to_owned(), &*RAR_PART, &*RAR, &*RAR_OLD),
    };
    if let Some(found) = part.captures(name.as_bytes()) {
        return Some((
            group(&found, 1).to_ascii_lowercase(),
            int(&group(&found, 2)),
        ));
    }
    if let Some(found) = plain.captures(name.as_bytes()) {
        return Some((group(&found, 1).to_ascii_lowercase(), 0));
    }
    if let Some(found) = old.captures(name.as_bytes()) {
        return Some((
            group(&found, 1).to_ascii_lowercase(),
            int(&group(&found, 2)).saturating_add(1),
        ));
    }
    None
}

fn media_split_volume(title: &str) -> Option<i64> {
    let name = file_name(title);
    let found = MEDIA_SPLIT.captures(name.as_bytes())?;
    Some(int(&group(&found, 2)))
}

fn has_file_extension(title: &str) -> bool {
    let name = QUOTED_NAME
        .captures(title.as_bytes())
        .map_or_else(|| title.to_owned(), |found| group(&found, 1));
    is_match(&HAS_EXTENSION, php_trim(&name))
}

fn php_trim(text: &str) -> &str {
    text.trim_matches(|c| matches!(c, ' ' | '\t' | '\n' | '\r' | '\0' | '\x0B'))
}

fn is_likely_first_volume(title: &str) -> bool {
    if let Some(found) = PART_NUMBER.captures(title.as_bytes()) {
        return int(&group(&found, 1)) == 1;
    }
    if let Some(found) = HASHED_POSITION.captures(title.as_bytes()) {
        return int(&group(&found, 1)) == 1;
    }
    if let Some((_, number)) = seven_zip_volume(title) {
        return number <= 1;
    }
    if is_match(&RAR_OR_ZIP, title) {
        return true;
    }
    numbered_volume(title).is_some_and(|(_, number)| number <= 1)
}

/// A 7z volume, or a plain numbered split that may turn out to be 7z once its first bytes are
/// read, so the last volume's tail is kept for both.
fn split_volume(title: &str) -> Option<(String, i64)> {
    seven_zip_volume(title).or_else(|| numbered_volume(title))
}

/// Set name (kept apart from 7z set names) and volume number.
fn numbered_volume(title: &str) -> Option<(String, i64)> {
    let found = NUMBERED_VOLUME.captures(title.as_bytes())?;
    let quoted = found.group(1).is_some_and(|name| !name.is_empty());
    let (name, number) = if quoted { (1, 2) } else { (3, 4) };
    Some((
        format!("#{}", group(&found, name).to_ascii_lowercase()),
        int(&group(&found, number)),
    ))
}

/// Set name and volume number (0 for a single `.7z`).
fn seven_zip_volume(title: &str) -> Option<(String, i64)> {
    let found = SEVEN_ZIP_QUOTED
        .captures(title.as_bytes())
        .or_else(|| SEVEN_ZIP.captures(title.as_bytes()))?;
    Some((
        group(&found, 1).to_ascii_lowercase(),
        int(&group(&found, 2)),
    ))
}

/// The key of the volume set a subject belongs to: the archive family and the name without its
/// volume suffix, like `rar:movie` for `movie.part01.rar`, `movie.rar` and `movie.r00`. Only real
/// sibling volumes share a key; a `movie.zip` next to `movie.part01.rar` is a different set.
pub fn volume_set_key(subject: &str) -> String {
    let name = file_name(subject);
    let families: [(&str, &Pcre); 4] = [
        ("rar", &SET_RAR),
        ("7z", &SET_7Z),
        ("zip", &SET_ZIP),
        ("split", &SET_SPLIT),
    ];
    for (family, pattern) in families {
        if let Some(found) = pattern.captures(name.as_bytes()) {
            let first = group(&found, 1);
            let stem = if first.is_empty() {
                group(&found, 2)
            } else {
                first
            };
            return format!("{family}:{stem}");
        }
    }
    format!("file:{name}")
}

/// The file name in an NZB subject, quoted or not, lower-cased. Unquoted, only `yEnc` and what
/// follows, part counters and `-` separators around the name, and `[01/10] - ` post counters
/// inside it are dropped. Everything else stays, spaces and any poster prefix included, because
/// "First Movie.rar" and "Second Movie.rar" must never share a name.
pub fn file_name(subject: &str) -> String {
    if let Some(found) = QUOTED_NAME_NOLINE.captures(subject.as_bytes()) {
        return php_trim(&group(&found, 1)).to_ascii_lowercase();
    }
    let replace = |pattern: &Pcre, text: &[u8], with: &[u8]| pattern.replace_all(text, with);
    let name = replace(&YENC_TAIL, subject.as_bytes(), b"");
    let name = replace(&TRAILING_COUNTER, &name, b"");
    let name = replace(&LEADING_COUNTER, &name, b"");
    let name = replace(&INNER_COUNTER, &name, b" ");
    php_trim(&String::from_utf8_lossy(&name)).to_ascii_lowercase()
}

/// Screen captures and contact sheets show the content itself, covers come next, and scans of
/// disc labels, backs, proofs and banners last. Lower is better.
pub fn image_rank(name: &str) -> u8 {
    let name = image_file_name(name);
    // "CD1.screens.jpg" is a contact sheet; "cover.back.jpg" is not a front cover.
    if is_match(&RANK_CONTENT, &name) {
        0
    } else if is_match(&RANK_LEAST, &name) {
        3
    } else if is_match(&RANK_COVER, &name) {
        1
    } else {
        2
    }
}

/// The file name inside a yEnc subject, or the base name of a path.
fn image_file_name(name: &str) -> String {
    let inner = QUOTED_NAME
        .captures(name.as_bytes())
        .map_or_else(|| name.to_owned(), |found| group(&found, 1));
    let normalized = php_trim(&inner).replace('\\', "/");
    let trimmed = normalized.trim_end_matches('/');
    trimmed.rsplit('/').next().unwrap_or(trimmed).to_owned()
}
