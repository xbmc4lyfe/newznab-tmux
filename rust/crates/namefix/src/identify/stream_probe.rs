//! Reaches a post's real stream from its first articles, through as many stored archive layers as
//! it has, and reads what that stream says about itself.
//!
//! Password-protected archives are left alone. A compressed archive can't be seen through without
//! its whole volume, so it ends the walk too. Stored ones, the norm for video, put the inner
//! file's bytes straight after the header, so nothing has to be unpacked to read them.

use std::collections::{BTreeMap, HashMap, HashSet};
use std::fmt::Write as _;
use std::sync::Mutex;
use std::sync::atomic::{AtomicUsize, Ordering};

use anyhow::Result;
use nntmux_formats::archive::FormatHeader;
use nntmux_mediaprobe::mp4::{self, Mp4Probe};
use nntmux_mediaprobe::summary::MediaSummary;
use nntmux_mediaprobe::{Limits, Probe, probe_prefix};
use rusqlite::Connection;

use super::archive_probe::{ArticleFetch, volumes_to_try};
use super::catalog::{Options, for_each_target, load, open_read_only, text};
use super::ladder::{Context, Evidence, Hooks, Outcome, identify};
use super::local_predb::LocalPredb;
use super::name::{self, folded};
use super::nzb::NzbFile;

/// Layers to walk through before giving up on a post built to waste our time.
const MAX_LAYERS: usize = 6;
/// Articles to read per file, about 6 MB at the usual size.
const MAX_ARTICLES: usize = 8;
const RAR4_STORED: u8 = 0x30;

#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash, PartialOrd, Ord)]
pub enum End {
    Matroska,
    Mp4,
    MpegTs,
    Avi,
    Encrypted,
    Compressed,
    SevenZip,
    Zip,
    Par2,
    /// The bytes ran out inside a header or before the next layer.
    NeedMore,
    Other,
}

impl End {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Matroska => "Matroska",
            Self::Mp4 => "MP4",
            Self::MpegTs => "MPEG-TS",
            Self::Avi => "AVI",
            Self::Encrypted => "password protected",
            Self::Compressed => "compressed archive",
            Self::SevenZip => "7z",
            Self::Zip => "ZIP",
            Self::Par2 => "PAR2",
            Self::NeedMore => "cut short",
            Self::Other => "unrecognized",
        }
    }

    const fn is_video(self) -> bool {
        matches!(self, Self::Matroska | Self::Mp4 | Self::MpegTs | Self::Avi)
    }
}

/// How far the walk got.
#[derive(Debug, PartialEq, Eq)]
pub struct Peeled<'a> {
    pub end: End,
    /// Archive layers walked through.
    pub layers: usize,
    /// Names the layers' headers gave for what they hold, outermost first.
    pub names: Vec<String>,
    /// The bytes after the last layer.
    pub stream: &'a [u8],
}

const RAR4_MAGIC: &[u8] = b"Rar!\x1a\x07\x00";
const RAR5_MAGIC: &[u8] = b"Rar!\x1a\x07\x01\x00";

fn kind_of(bytes: &[u8]) -> Option<End> {
    if bytes.starts_with(&[0x1a, 0x45, 0xdf, 0xa3]) {
        Some(End::Matroska)
    } else if bytes.get(4..8) == Some(b"ftyp") {
        Some(End::Mp4)
    } else if bytes.first() == Some(&0x47) && bytes.get(188) == Some(&0x47) {
        Some(End::MpegTs)
    } else if bytes.starts_with(b"RIFF") && bytes.get(8..11) == Some(b"AVI") {
        Some(End::Avi)
    } else if bytes.starts_with(b"7z\xbc\xaf\x27\x1c") {
        Some(End::SevenZip)
    } else if bytes.starts_with(b"PK\x03\x04") {
        Some(End::Zip)
    } else if bytes.starts_with(b"PAR2\0PKT") {
        Some(End::Par2)
    } else {
        None
    }
}

/// The next layer down from a RAR header, or how the walk ends here.
fn step_into_rar<'a>(bytes: &'a [u8], names: &mut Vec<String>) -> Result<&'a [u8], End> {
    let metadata = nntmux_formats::archive_metadata(bytes).ok_or(End::Other)?;
    for header in &metadata.headers {
        match header {
            FormatHeader::Rar4 {
                offset: 0,
                encrypted_headers,
                first_file,
                ..
            } => {
                if *encrypted_headers == Some(true) {
                    return Err(End::Encrypted);
                }
                let file = first_file.as_ref().ok_or(End::NeedMore)?;
                if file.checksum_valid != Some(true) {
                    return Err(if file.header_complete {
                        End::Other
                    } else {
                        End::NeedMore
                    });
                }
                names.extend(file.filename.clone());
                if file.encrypted {
                    return Err(End::Encrypted);
                }
                if file.method != RAR4_STORED {
                    return Err(End::Compressed);
                }
                return bytes.get(file.data_offset..).ok_or(End::NeedMore);
            }
            FormatHeader::Rar5 {
                offset: 0,
                encryption_algorithm_version,
                visible_block_walk,
                ..
            } => {
                if encryption_algorithm_version.is_some() {
                    return Err(End::Encrypted);
                }
                let blocks = visible_block_walk.iter().flat_map(|walk| &walk.blocks);
                let found = blocks
                    .filter_map(|block| block.file_header.as_ref().map(|file| (block, file)))
                    .find(|(_, file)| file.directory != Some(true));
                let (block, file) = found.ok_or(End::NeedMore)?;
                if file.header_crc32_valid != Some(true) {
                    return Err(End::Other);
                }
                names.extend(file.filename.clone());
                if file.encryption_presence == "present" {
                    return Err(End::Encrypted);
                }
                if file.compression_method != Some(0) {
                    return Err(End::Compressed);
                }
                let start = block.data_start_offset.ok_or(End::NeedMore)?;
                return bytes.get(start..).ok_or(End::NeedMore);
            }
            _ => {}
        }
    }
    Err(End::Other)
}

/// Walks down through stored RAR layers to whatever is inside.
pub fn peel(bytes: &[u8]) -> Peeled<'_> {
    let mut stream = bytes;
    let mut names = Vec::new();
    let mut layers = 0;
    loop {
        if let Some(end) = kind_of(stream) {
            return Peeled {
                end,
                layers,
                names,
                stream,
            };
        }
        let is_rar = stream.starts_with(RAR4_MAGIC) || stream.starts_with(RAR5_MAGIC);
        // A signature cut off by the end of the bytes reads as nothing at all.
        let end = if !is_rar {
            Some(if stream.len() < 16 {
                End::NeedMore
            } else {
                End::Other
            })
        } else if layers == MAX_LAYERS {
            Some(End::Other)
        } else {
            None
        };
        if let Some(end) = end {
            return Peeled {
                end,
                layers,
                names,
                stream,
            };
        }
        match step_into_rar(stream, &mut names) {
            Ok(next) => {
                stream = next;
                layers += 1;
            }
            Err(end) => {
                return Peeled {
                    end,
                    layers,
                    names,
                    stream,
                };
            }
        }
    }
}

/// A stored fingerprint of a release that already has a name.
#[derive(Clone, Debug)]
struct Known {
    name: String,
    width: i64,
    audio: usize,
    size_bytes: i64,
}

/// `02h:20m:03s`, the stored form of a duration.
pub fn duration_seconds(text: &str) -> Option<i64> {
    let mut total = 0;
    let mut seen = false;
    for part in text.split(':') {
        let part = part.trim();
        let (digits, unit) = part.split_at(part.len().checked_sub(1)?);
        let value: i64 = digits.trim().parse().ok()?;
        total += match unit {
            "h" => value * 3600,
            "m" => value * 60,
            "s" => value,
            _ => return None,
        };
        seen = true;
    }
    seen.then_some(total)
}

/// The Matroska SegmentUID as the catalog stores it: 32 hex digits read as one decimal number.
pub fn uid_as_decimal(hex: &str) -> Option<String> {
    u128::from_str_radix(hex, 16)
        .ok()
        .map(|uid| uid.to_string())
}

#[derive(Default)]
struct KnownReleases {
    by_shape: HashMap<(i64, i64), Vec<Known>>,
    uids: HashMap<String, Vec<(i64, String)>>,
}

fn load_known(catalog: &Connection) -> Result<KnownReleases> {
    let mut known = KnownReleases::default();
    let mut audio: HashMap<i64, usize> = HashMap::new();
    let mut statement =
        catalog.prepare("SELECT release_id, count(*) FROM media_audio GROUP BY 1")?;
    let mut rows = statement.query([])?;
    while let Some(row) = rows.next()? {
        audio.insert(row.get(0)?, row.get::<_, i64>(1)? as usize);
    }
    let named = "SELECT r.id, COALESCE(p.title, r.search_name), r.size_bytes FROM releases r
                 LEFT JOIN predb p ON p.id = r.predb_id
                 WHERE r.predb_id IS NOT NULL OR r.is_renamed = 1
                    OR r.legacy_category NOT IN (10, 20)";
    let mut names: HashMap<i64, (String, i64)> = HashMap::new();
    let mut statement = catalog.prepare(named)?;
    let mut rows = statement.query([])?;
    while let Some(row) = rows.next()? {
        let shown = name::stem(&text(row.get_ref(1)?));
        if name::usable(&shown) {
            names.insert(row.get(0)?, (shown, row.get(2)?));
        }
    }
    let mut statement =
        catalog.prepare("SELECT release_id, width, height, duration FROM media_video")?;
    let mut rows = statement.query([])?;
    while let Some(row) = rows.next()? {
        let release_id: i64 = row.get(0)?;
        let Some((shown, size_bytes)) = names.get(&release_id) else {
            continue;
        };
        let (Some(width), Some(height), Some(seconds)) = (
            row.get::<_, Option<i64>>(1)?,
            row.get::<_, Option<i64>>(2)?,
            duration_seconds(&text(row.get_ref(3)?)),
        ) else {
            continue;
        };
        known
            .by_shape
            .entry((height, seconds))
            .or_default()
            .push(Known {
                name: shown.clone(),
                width,
                audio: audio.get(&release_id).copied().unwrap_or(0),
                size_bytes: *size_bytes,
            });
    }
    let mut statement = catalog.prepare("SELECT release_id, unique_id FROM media_unique_ids")?;
    let mut rows = statement.query([])?;
    while let Some(row) = rows.next()? {
        let release_id: i64 = row.get(0)?;
        if let Some((shown, _)) = names.get(&release_id) {
            known
                .uids
                .entry(text(row.get_ref(1)?))
                .or_default()
                .push((release_id, shown.clone()));
        }
    }
    Ok(known)
}

/// The names of known releases whose picture, runtime and audio tracks fit.
fn fingerprint_matches<'a>(
    known: &'a KnownReleases,
    summary: &MediaSummary,
    payload_bytes: u64,
) -> Vec<&'a Known> {
    let (Some(video), Some(duration)) = (&summary.video, summary.duration_ms) else {
        return Vec::new();
    };
    let (Some(width), Some(height)) = (video.width, video.height) else {
        return Vec::new();
    };
    let seconds = (duration / 1000) as i64;
    let mut found = Vec::new();
    for near in seconds - 1..=seconds + 1 {
        for candidate in known
            .by_shape
            .get(&(height as i64, near))
            .into_iter()
            .flatten()
        {
            let ratio = payload_bytes as f64 / candidate.size_bytes.max(1) as f64;
            if candidate.width == width as i64
                && candidate.audio == summary.audio.len()
                && (0.8..=1.25).contains(&ratio)
            {
                found.push(candidate);
            }
        }
    }
    found
}

/// PreDB rows of the post's size and time whose name states the stream's resolution and codec.
fn predb_candidates<'a>(
    predb: &'a LocalPredb,
    summary: &MediaSummary,
    payload_bytes: u64,
    posted_at: i64,
) -> Vec<&'a str> {
    let Some(video) = &summary.video else {
        return Vec::new();
    };
    let wanted_resolution = match video.height {
        Some(height) if height > 1200 => Some("2160p"),
        Some(height) if height > 750 => Some("1080p"),
        _ => None,
    };
    let codec = video.codec.to_ascii_lowercase();
    predb
        .by_size(payload_bytes, posted_at)
        .into_iter()
        .filter(|row| {
            let parsed = name::parse(&row.title);
            parsed.resolution == wanted_resolution
                && match parsed.codec {
                    Some("h265") => codec.contains("hevc") || codec.contains("265"),
                    Some("h264") => codec.contains("avc") || codec.contains("264"),
                    _ => true,
                }
        })
        .map(|row| row.title.as_str())
        .collect()
}

/// Every text a stream carries that could spell out a release name.
fn stream_names(summary: &MediaSummary) -> Vec<String> {
    let mut names: Vec<String> = summary.title.iter().cloned().collect();
    names.extend(summary.audio.iter().filter_map(|track| track.name.clone()));
    names.extend(
        summary
            .subtitles
            .iter()
            .filter_map(|track| track.name.clone()),
    );
    names
}

/// The name a text gives, when it's good enough to identify a release or is in PreDB.
fn scene_name(text: &str, predb: &LocalPredb) -> Option<(String, bool)> {
    let stem = name::stem(text);
    if !name::usable(&stem) {
        return None;
    }
    if let Some((row, _)) = predb.by_name(&stem) {
        return Some((row.title.clone(), true));
    }
    name::acceptable(&name::parse(&stem), &stem, true).then_some((stem, false))
}

/// What one sampled release came to.
#[derive(Debug, Default)]
struct Row {
    release_id: i64,
    end: Option<End>,
    layers: usize,
    missing: bool,
    /// Sampled for a read of its stream.
    read: bool,
    header_read: bool,
    title_name: Option<(String, bool)>,
    inner_name: Option<(String, bool)>,
    uid_names: HashSet<String>,
    fingerprint_names: HashSet<String>,
    predb_candidates: usize,
    line: String,
}

struct Sampler<'a> {
    fetch: &'a dyn ArticleFetch,
    predb: &'a LocalPredb,
    known: &'a KnownReleases,
    media_budget: usize,
    media_taken: AtomicUsize,
}

impl Sampler<'_> {
    fn read_release(&self, evidence: &Evidence) -> Option<Row> {
        let nzb = evidence.nzb.as_ref()?;
        let mut row = Row {
            release_id: evidence.id,
            ..Row::default()
        };
        let mut best: Option<End> = None;
        for file in volumes_to_try(nzb) {
            let Some(group) = &file.group else { continue };
            let mut buffer = Vec::new();
            let mut limit = 3;
            let mut index = 0;
            let mut end = None;
            let mut in_budget = false;
            while index < file.head_message_ids.len().min(limit) {
                let Some(body) = self.fetch.fetch(group, &file.head_message_ids[index]) else {
                    row.missing = true;
                    break;
                };
                buffer.extend_from_slice(&body);
                index += 1;
                let peeled = peel(&buffer);
                end = Some(peeled.end);
                if let Some(found) = peeled
                    .names
                    .iter()
                    .find_map(|inner| scene_name(inner, self.predb))
                {
                    row.inner_name = Some(found);
                }
                row.layers = peeled.layers;
                if peeled.end == End::NeedMore {
                    continue;
                }
                if peeled.end == End::Mp4 {
                    if !in_budget {
                        in_budget =
                            self.media_taken.fetch_add(1, Ordering::Relaxed) < self.media_budget;
                        if !in_budget {
                            break;
                        }
                        row.read = true;
                        limit = MAX_ARTICLES;
                    }
                    match mp4::probe_prefix(peeled.stream) {
                        Mp4Probe::Read(summary) => {
                            row.header_read = true;
                            self.record(&mut row, &summary, nzb.payload_bytes, evidence.posted_at);
                            break;
                        }
                        Mp4Probe::NeedPrefix { .. } => continue,
                        Mp4Probe::NotMp4 => break,
                        Mp4Probe::MoovInTail => {
                            if let Some(summary) = self.read_tail(group, file) {
                                row.header_read = true;
                                self.record(
                                    &mut row,
                                    &summary,
                                    nzb.payload_bytes,
                                    evidence.posted_at,
                                );
                            }
                            break;
                        }
                    }
                }
                if peeled.end != End::Matroska {
                    break;
                }
                if !in_budget {
                    in_budget =
                        self.media_taken.fetch_add(1, Ordering::Relaxed) < self.media_budget;
                    if !in_budget {
                        break;
                    }
                    row.read = true;
                    limit = MAX_ARTICLES;
                }
                match probe_prefix(peeled.stream, Limits::default()) {
                    Probe::Read {
                        summary,
                        wants_more,
                    } => {
                        row.header_read = true;
                        self.record(&mut row, &summary, nzb.payload_bytes, evidence.posted_at);
                        if !wants_more {
                            break;
                        }
                    }
                    Probe::NeedHeader { .. } => {}
                    Probe::NotMatroska => break,
                }
            }
            if let Some(end) = end {
                let better = best.is_none_or(|known| !known.is_video() && end.is_video());
                if better {
                    best = Some(end);
                }
            }
            if best.is_some_and(End::is_video) {
                break;
            }
        }
        row.end = best;
        Some(row)
    }

    /// An MP4 whose index comes after its media: read the file's last articles.
    fn read_tail(&self, group: &str, file: &NzbFile) -> Option<MediaSummary> {
        let mut bodies = Vec::new();
        for id in file.tail_message_ids.iter().rev() {
            bodies.insert(0, self.fetch.fetch(group, id)?);
            if let Some(summary) = mp4::probe_tail(&bodies.concat()) {
                return Some(summary);
            }
        }
        None
    }

    fn record(&self, row: &mut Row, summary: &MediaSummary, payload_bytes: u64, posted_at: i64) {
        let video = summary.video.as_ref();
        let audio: Vec<String> = summary
            .audio
            .iter()
            .map(|track| {
                format!(
                    "{}/{}/{}/{}",
                    track.codec,
                    track.language,
                    track.channels.as_deref().unwrap_or("?"),
                    track.name.as_deref().unwrap_or("")
                )
            })
            .collect();
        let subtitles: Vec<String> = summary
            .subtitles
            .iter()
            .map(|track| format!("{}/{}", track.language, track.name.as_deref().unwrap_or("")))
            .collect();
        row.title_name = stream_names(summary)
            .iter()
            .find_map(|text| scene_name(text, self.predb));
        if let Some(uid) = summary.segment_uid.as_deref().and_then(uid_as_decimal) {
            for (_, shown) in self.known.uids.get(&uid).into_iter().flatten() {
                row.uid_names.insert(shown.clone());
            }
        }
        row.fingerprint_names = fingerprint_matches(self.known, summary, payload_bytes)
            .iter()
            .map(|known| known.name.clone())
            .collect();
        row.predb_candidates =
            predb_candidates(self.predb, summary, payload_bytes, posted_at).len();
        row.line = format!(
            "{}\t{}\t{}x{}\t{}\t{}\t{}\t{}\t{}\t{}\t{}\t{}",
            row.release_id,
            video.map_or("?", |video| video.codec.as_str()),
            video.and_then(|video| video.width).unwrap_or(0),
            video.and_then(|video| video.height).unwrap_or(0),
            summary.duration_ms.unwrap_or(0) / 1000,
            summary
                .title
                .as_deref()
                .unwrap_or("")
                .replace(['\t', '\n'], " "),
            audio.join("; ").replace(['\t', '\n'], " "),
            subtitles.join("; ").replace(['\t', '\n'], " "),
            summary.writing_app.as_deref().unwrap_or(""),
            row.uid_names.len(),
            row.fingerprint_names.len(),
            row.predb_candidates,
        );
    }
}

pub struct SampleOptions {
    /// Releases with no other evidence to classify.
    pub sample: usize,
    /// Of those, how many Matroska and MP4 streams to read in full.
    pub media: usize,
}

/// Classifies a sample of the releases nothing else can name, and fingerprints the streams it can
/// reach. It reads only, and returns the report and one line per stream read.
pub fn sample(
    options: &Options,
    settings: &SampleOptions,
    fetch: &dyn ArticleFetch,
) -> Result<(String, String)> {
    let catalog_path = options.data_dir.join("catalog.db");
    let catalog = open_read_only(&catalog_path)?;
    let loaded = load(&catalog, super::catalog::TARGET, options.limit)?;
    let known = load_known(&catalog)?;
    drop(catalog);
    let offline = Context {
        predb: &loaded.predb,
        donors: &loaded.donors,
        hooks: Hooks::default(),
    };
    let sampler = Sampler {
        fetch,
        predb: &loaded.predb,
        known: &known,
        media_budget: settings.media,
        media_taken: AtomicUsize::new(0),
    };
    let taken = AtomicUsize::new(0);
    let rows = Mutex::new(Vec::new());
    let mut targets = loaded.targets;
    targets.sort_by_key(|target| super::catalog::sample_order(target.id));
    for_each_target(
        &options.data_dir.join("nzbs.db"),
        options.threads,
        targets,
        |target| {
            if taken.load(Ordering::Relaxed) >= settings.sample {
                return;
            }
            let (outcome, flags) = identify(target, &offline);
            if matches!(outcome, Outcome::Identified(_)) || !flags.obfuscated_only() {
                return;
            }
            if taken.fetch_add(1, Ordering::Relaxed) >= settings.sample {
                return;
            }
            if let Some(row) = sampler.read_release(target) {
                rows.lock().expect("no panic while holding this").push(row);
            }
        },
    )?;
    let rows = rows.into_inner().expect("no panic while holding this");
    Ok(render(&rows))
}

fn render(rows: &[Row]) -> (String, String) {
    let mut ends: BTreeMap<&str, usize> = BTreeMap::new();
    let mut layers: BTreeMap<usize, usize> = BTreeMap::new();
    for row in rows {
        let label = match (row.end, row.missing) {
            (Some(end), _) => end.as_str(),
            (None, true) => "articles unavailable",
            (None, false) => "nothing to read",
        };
        *ends.entry(label).or_default() += 1;
        if row.end.is_some_and(End::is_video) {
            *layers.entry(row.layers).or_default() += 1;
        }
    }
    let read: Vec<&Row> = rows.iter().filter(|row| row.read).collect();
    let count = |test: &dyn Fn(&Row) -> bool| read.iter().filter(|row| test(row)).count();
    let mut out = format!(
        "# Stream sample\n\nReleases: {}\n\n## What's inside\n\n| Reached | Releases |\n|---|---|\n",
        rows.len()
    );
    for (label, total) in &ends {
        let _ = writeln!(out, "| {label} | {total} |");
    }
    let _ = writeln!(
        out,
        "\n## Archive layers above a video stream\n\n| Layers | Releases |\n|---|---|"
    );
    for (depth, total) in &layers {
        let _ = writeln!(out, "| {depth} | {total} |");
    }
    let _ = write!(
        out,
        "\n## Streams read\n\n| | Releases |\n|---|---|\n\
         | Sampled | {} |\n| Header read | {} |\n\
         | Title or track name gives a scene name | {} (in PreDB: {}) |\n\
         | Inner archive name gives a scene name | {} (in PreDB: {}) |\n\
         | SegmentUID matches a named release | {} |\n\
         | Fingerprint fits exactly one named release | {} |\n\
         | Fingerprint fits several | {} |\n\
         | Exactly one PreDB size candidate after stream filter | {} |\n\
         | Several PreDB size candidates | {} |\n",
        read.len(),
        count(&|row| row.header_read),
        count(&|row| row.title_name.is_some()),
        count(&|row| row.title_name.as_ref().is_some_and(|found| found.1)),
        count(&|row| row.inner_name.is_some()),
        count(&|row| row.inner_name.as_ref().is_some_and(|found| found.1)),
        count(&|row| !row.uid_names.is_empty()),
        count(&|row| {
            let unique: HashSet<String> = row
                .fingerprint_names
                .iter()
                .map(|shown| folded(shown))
                .collect();
            unique.len() == 1
        }),
        count(&|row| row.fingerprint_names.len() > 1),
        count(&|row| row.predb_candidates == 1),
        count(&|row| row.predb_candidates > 1),
    );
    let _ = writeln!(out, "\n## Names found\n");
    for row in &read {
        for (label, found) in [("title", &row.title_name), ("inner", &row.inner_name)] {
            if let Some((shown, in_predb)) = found {
                let _ = writeln!(
                    out,
                    "- {} {label} predb={in_predb}: {shown}",
                    row.release_id
                );
            }
        }
        for shown in row.uid_names.iter().take(1) {
            let _ = writeln!(out, "- {} segment_uid: {shown}", row.release_id);
        }
        for shown in row.fingerprint_names.iter().take(1) {
            let _ = writeln!(
                out,
                "- {} fingerprint ({} fit): {shown}",
                row.release_id,
                row.fingerprint_names.len()
            );
        }
    }
    let mut lines = String::from(
        "release_id\tcodec\tsize\tseconds\ttitle\taudio\tsubtitles\twriting_app\tuid_matches\tfingerprint_matches\tpredb_candidates\n",
    );
    for row in read.iter().filter(|row| !row.line.is_empty()) {
        lines.push_str(&row.line);
        lines.push('\n');
    }
    (out, lines)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::identify::archive_probe::tests::{rar4, rar4_method};

    fn mkv() -> Vec<u8> {
        std::fs::read(concat!(
            env!("CARGO_MANIFEST_DIR"),
            "/../mediaprobe/tests/fixtures/mkv/sample.mkv"
        ))
        .expect("fixture")
    }

    fn wrap(inner: &[u8], name: &str) -> Vec<u8> {
        let mut out = rar4(name, inner.len() as u32, 1, 0);
        out.extend_from_slice(inner);
        out
    }

    #[test]
    fn walks_through_nested_stored_archives_to_the_stream() {
        let bytes = wrap(
            &wrap(&mkv(), "abc.mkv"),
            "Some.Film.2021.1080p.WEB-DL.H264-GRP.rar",
        );
        let peeled = peel(&bytes);
        assert_eq!(peeled.end, End::Matroska);
        assert_eq!(peeled.layers, 2);
        assert_eq!(
            peeled.names,
            ["Some.Film.2021.1080p.WEB-DL.H264-GRP.rar", "abc.mkv"]
        );
        let Probe::Read { summary, .. } = probe_prefix(peeled.stream, Limits::default()) else {
            panic!("expected a stream");
        };
        assert_eq!(
            summary.title.as_deref(),
            Some("Sample Movie 2024 2160p UHD BluRay REMUX")
        );
    }

    #[test]
    fn a_bare_stream_has_no_layers() {
        let bytes = mkv();
        let peeled = peel(&bytes);
        assert_eq!((peeled.end, peeled.layers), (End::Matroska, 0));
    }

    #[test]
    fn password_protection_ends_the_walk() {
        let mut outer = rar4("secret.mkv", 100, 1, 0x0004);
        outer.extend_from_slice(&mkv());
        let peeled = peel(&outer);
        assert_eq!(peeled.end, End::Encrypted);
        assert_eq!(peeled.layers, 0);
    }

    #[test]
    fn compression_ends_the_walk() {
        let mut outer = rar4_method("packed.mkv", 100, 1, 0, 0x33);
        outer.extend_from_slice(&mkv());
        assert_eq!(peel(&outer).end, End::Compressed);
    }

    #[test]
    fn a_damaged_layer_ends_the_walk() {
        let mut bytes = wrap(&mkv(), "abc.mkv");
        bytes[20] ^= 0xff;
        assert_ne!(peel(&bytes).end, End::Matroska);
    }

    #[test]
    fn too_many_layers_end_the_walk() {
        let mut bytes = mkv();
        for _ in 0..=MAX_LAYERS {
            bytes = wrap(&bytes, "x.rar");
        }
        assert_eq!(peel(&bytes).end, End::Other);
    }

    #[test]
    fn other_containers_are_told_apart() {
        let mut mp4 = vec![0, 0, 0, 24];
        mp4.extend_from_slice(b"ftypisom");
        assert_eq!(peel(&mp4).end, End::Mp4);
        let mut ts = vec![0u8; 400];
        ts[0] = 0x47;
        ts[188] = 0x47;
        assert_eq!(peel(&ts).end, End::MpegTs);
        assert_eq!(peel(b"PAR2\0PKT and more").end, End::Par2);
        assert_eq!(
            peel(b"7z\xbc\xaf\x27\x1c and more bytes").end,
            End::SevenZip
        );
        assert_eq!(peel(&[0x9c; 64]).end, End::Other);
    }

    #[test]
    fn reads_stored_durations_and_uids() {
        assert_eq!(duration_seconds("02h:20m:03s"), Some(8403));
        assert_eq!(duration_seconds("12m:19s"), Some(739));
        assert_eq!(duration_seconds(""), None);
        assert_eq!(
            uid_as_decimal("000000000000000000000000000000ff").as_deref(),
            Some("255")
        );
    }
}
