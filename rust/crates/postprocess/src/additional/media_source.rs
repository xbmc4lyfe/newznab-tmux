//! Finds real names for an NZB's obfuscated files from its PAR2 index, so previews and archive
//! inspection can pick the right files. Port of `MediaSourceResolver` and `MediaByteBudget`.

use std::collections::{BTreeMap, BTreeSet};
use std::sync::LazyLock;

use nntmux_core::pcre::Pcre;
use nntmux_namefix::par2_info;

use super::download::{DownloadKind, DownloadService, Par2FileEntry};
use super::par2_mapping::{self, MappedNzbFile};
use super::planner::{ArchiveCandidate, NzbFile, VIDEO_FILE, WorkPlan};

/// The preview bytes one release may download.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct MediaByteBudget {
    pub limit: i64,
    spent: i64,
}

impl MediaByteBudget {
    pub fn new(limit: i64) -> Self {
        Self { limit, spent: 0 }
    }

    pub fn allows(&self, bytes: i64) -> bool {
        self.spent.saturating_add(bytes.max(0)) <= self.limit
    }

    pub fn spend(&mut self, bytes: i64) {
        self.spent = self.spent.saturating_add(bytes.max(0));
    }

    pub fn remaining(&self) -> i64 {
        (self.limit - self.spent).max(0)
    }

    pub fn exhausted(&self) -> bool {
        self.spent >= self.limit
    }
}

/// NZB files the PAR2 index named, with the article size learned on the way.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct MediaSourcePlan {
    pub mapped: BTreeMap<usize, MappedNzbFile>,
    pub article_part_size: Option<i64>,
}

/// The settings the resolver reads.
#[derive(Clone, Copy, Debug)]
pub struct ResolverConfig {
    pub par2_media_mapping: bool,
    pub par2_verify_files: i64,
}

/// One release's inputs, and the state the resolver shares with the rest of its processing.
pub struct Release<'a> {
    pub nzb: &'a [NzbFile],
    pub group: &'a str,
    pub budget: &'a mut MediaByteBudget,
    pub group_unavailable: &'a mut bool,
}

const PAR2_SIGNATURE: &[u8] = b"PAR2\0PKT";

static FIRST_ARCHIVE: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/\.(?:part0*1\.rar|rar|7z(?:\.0*1)?|zip|0*1)$/i").expect("valid"));
static LATER_PART: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/\.part(?!0*1\.)\d+\.rar$/i").expect("valid"));
static VIDEO: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(&format!("/{VIDEO_FILE}$/i")).expect("valid"));
static SAMPLE: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/(?:^|[\\\/._ -])sample(?:[\\\/._ -]|$)/i").expect("valid"));
static IMAGE: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/\.(?:jpe?g|png|webp)$/i").expect("valid"));

/// `Par2FileEntry::listFromPar2Info` over `Par2FileList::getFiles`.
pub fn par2_entries(data: &[u8]) -> Vec<Par2FileEntry> {
    let Ok(info) = par2_info::parse(data) else {
        return Vec::new();
    };
    info.files()
        .into_iter()
        .filter_map(|file| {
            let size = file.size.and_then(|size| i64::try_from(size).ok())?;
            let name = file.name?;
            (size > 0).then(|| Par2FileEntry {
                name: String::from_utf8_lossy(&name).into_owned(),
                size,
                hash16k: file.hash_16k.unwrap_or_default().to_ascii_lowercase(),
            })
        })
        .collect()
}

/// PAR2 entries worth identifying for previews and archive inspection, most useful first: first
/// archive volumes, then samples, then other videos (largest first), then images. Positions in
/// `files`.
pub fn wanted_files(files: &[Par2FileEntry]) -> Vec<usize> {
    let mut ranked: Vec<(u8, i64, usize)> = files
        .iter()
        .enumerate()
        .filter_map(|(index, file)| {
            let name = file.name.as_bytes();
            let video = VIDEO.is_match(name);
            let rank = if FIRST_ARCHIVE.is_match(name) && !LATER_PART.is_match(name) {
                0
            } else if video && SAMPLE.is_match(name) {
                1
            } else if video {
                2
            } else if IMAGE.is_match(name) {
                3
            } else {
                return None;
            };
            Some((rank, -file.size, index))
        })
        .collect();
    ranked.sort_by_key(|(rank, size, _)| (*rank, *size));
    ranked.into_iter().map(|(_, _, index)| index).collect()
}

/// The bytes `articles` articles of an NZB file are expected to take.
fn estimated_bytes(nzb: &[NzbFile], source_index: usize, articles: usize) -> i64 {
    let Some(entry) = nzb.get(source_index) else {
        return 0;
    };
    let segments = entry.segments.len().max(1);
    let share = entry.size as f64 / segments as f64 * articles.min(segments) as f64;
    share.ceil() as i64
}

/// Maps the release's files to its PAR2 index, or `None` when there's nothing to map.
pub async fn resolve(
    downloads: &mut DownloadService,
    config: ResolverConfig,
    plan: &WorkPlan,
    release: &mut Release<'_>,
) -> Option<MediaSourcePlan> {
    if !config.par2_media_mapping || plan.par2_index_candidates.is_empty() {
        return None;
    }
    let files = read_index(downloads, &plan.par2_index_candidates, release).await;
    if files.is_empty() {
        return None;
    }
    let (verified, article_part_size) =
        verify_ambiguous_files(downloads, config, &files, release).await;
    let mapped = par2_mapping::map(release.nzb, &files, article_part_size, &verified);
    (!mapped.is_empty()).then_some(MediaSourcePlan {
        mapped,
        article_part_size,
    })
}

async fn read_index(
    downloads: &mut DownloadService,
    candidates: &[ArchiveCandidate],
    release: &mut Release<'_>,
) -> Vec<Par2FileEntry> {
    for candidate in candidates {
        let estimate = estimated_bytes(
            release.nzb,
            candidate.source_index,
            candidate.message_ids.len(),
        );
        if *release.group_unavailable
            || release.budget.exhausted()
            || !release.budget.allows(estimate)
        {
            return Vec::new();
        }
        let result = downloads
            .download(
                DownloadKind::Par2Index,
                &candidate.message_ids,
                release.group,
                false,
            )
            .await;
        if result.group_unavailable {
            *release.group_unavailable = true;
            return Vec::new();
        }
        let Some(data) = result.data else {
            continue;
        };
        release.budget.spend(data.len() as i64);
        if !data.starts_with(PAR2_SIGNATURE) {
            continue;
        }
        let files = par2_entries(&data);
        if !files.is_empty() {
            return files;
        }
    }
    Vec::new()
}

/// Downloads the first article of NZB files that more than one wanted PAR2 entry could be, and
/// identifies them by the PAR2 hash of their first 16 KiB or their yEnc name. Returns source
/// index to PAR2 file index, and the article size when an article showed it.
async fn verify_ambiguous_files(
    downloads: &mut DownloadService,
    config: ResolverConfig,
    files: &[Par2FileEntry],
    release: &mut Release<'_>,
) -> (BTreeMap<usize, usize>, Option<i64>) {
    let mut verified: BTreeMap<usize, usize> = BTreeMap::new();
    let mut article_part_size: Option<i64> = None;
    let mut attempts = 0i64;
    let size_matched: BTreeSet<usize> =
        par2_mapping::map(release.nzb, files, None, &BTreeMap::new())
            .values()
            .map(|mapped| mapped.file_index)
            .collect();
    let is_verified = |verified: &BTreeMap<usize, usize>, file: usize| {
        verified.values().any(|taken| *taken == file)
    };

    'wanted: for wanted in wanted_files(files) {
        if size_matched.contains(&wanted) || is_verified(&verified, wanted) {
            continue;
        }
        for source_index in
            par2_mapping::candidate_indexes(release.nzb, &files[wanted], article_part_size)
        {
            if attempts >= config.par2_verify_files || *release.group_unavailable {
                break 'wanted;
            }
            if verified.contains_key(&source_index) {
                continue;
            }
            let message_id = release.nzb[source_index]
                .segments
                .first()
                .cloned()
                .unwrap_or_default();
            if message_id.is_empty()
                || release.budget.exhausted()
                || !release
                    .budget
                    .allows(estimated_bytes(release.nzb, source_index, 1))
            {
                break 'wanted;
            }
            attempts += 1;
            let result = downloads
                .download_mapping_article(&message_id, release.group)
                .await;
            if result.download.group_unavailable {
                *release.group_unavailable = true;
                downloads.discard_pending_mapping();
                break 'wanted;
            }
            let Some(data) = result.download.data else {
                downloads.discard_pending_mapping();
                continue;
            };
            release.budget.spend(data.len() as i64);
            let part = result.part;
            if let Some(part) = &part
                && let (Some(length), Some(end)) = (part.length(), part.end)
                && end < part.size
            {
                article_part_size.get_or_insert(length);
            }
            for (file_index, file) in files.iter().enumerate() {
                if is_verified(&verified, file_index) {
                    continue;
                }
                let name_matches = part.as_ref().is_some_and(|part| {
                    part.name == file.name.as_bytes() && part.size == file.size
                });
                if file.matches_start_of(&data) || name_matches {
                    verified.insert(source_index, file_index);
                    downloads.retain_mapped_source(file);
                    break;
                }
            }
            downloads.discard_pending_mapping();
            if is_verified(&verified, wanted) {
                break;
            }
        }
    }
    (verified, article_part_size)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn entry(name: &str, size: i64) -> Par2FileEntry {
        Par2FileEntry {
            name: name.to_owned(),
            size,
            hash16k: String::new(),
        }
    }

    #[test]
    fn archives_come_first_then_samples_videos_and_images() {
        let files = [
            entry("cover.jpg", 10),
            entry("Movie.part02.rar", 50),
            entry("Movie.mkv", 100),
            entry("Movie.part01.rar", 50),
            entry("movie-sample.mkv", 5),
            entry("Extras.mkv", 200),
            entry("Movie.nfo", 1),
        ];
        assert_eq!(wanted_files(&files), vec![3, 4, 5, 2, 0]);
    }

    #[test]
    fn the_budget_never_goes_negative() {
        let mut budget = MediaByteBudget::new(10);
        assert!(budget.allows(10));
        budget.spend(-5);
        budget.spend(7);
        assert!(!budget.allows(4));
        assert_eq!(budget.remaining(), 3);
        budget.spend(3);
        assert!(budget.exhausted());
    }
}
