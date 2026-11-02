//! Reaches a post's real stream from its first articles, through as many stored archive layers as
//! it has, and reads what that stream says about itself.
//!
//! Password-protected archives are left alone. A compressed archive can't be seen through without
//! its whole volume, so it ends the walk too. Stored ones, the norm for video, put the inner
//! file's bytes straight after the header, so nothing has to be unpacked to read them.

use std::collections::{BTreeMap, HashMap, HashSet};
use std::fmt::Write as _;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{LazyLock, Mutex};

use anyhow::Result;
use nntmux_formats::archive::FormatHeader;
use nntmux_mediaprobe::mp4::{self, Mp4Probe};
use nntmux_mediaprobe::summary::MediaSummary;
use nntmux_mediaprobe::{Limits, Probe, probe_prefix};
use regex::Regex;
use rusqlite::Connection;

use super::archive_probe::{ArticleFetch, last_volume, post_position, volumes_to_try};
use super::catalog::{Options, for_each_target, load, open_read_only, text};
use super::ladder::{Context, Evidence, Hooks, Outcome, identify};
use super::local_predb::LocalPredb;
use super::name::{self, folded};
use super::nzb::{NzbFile, NzbSummary};
use super::sevenzip::{self, Opened};

/// Layers to walk through before giving up on a post built to waste our time.
const MAX_LAYERS: usize = 6;
/// Articles to read per file, about 6 MB at the usual size.
const MAX_ARTICLES: usize = 8;
/// How much of a compressed layer to decode, which is plenty to reach a stream's header.
const DECODED_LIMIT: usize = 32 * 1024 * 1024;
const RAR4_STORED: u8 = 0x30;
const RANDOMNESS_SAMPLE: usize = 64 * 1024;
/// Bits per byte, where 8 is perfectly random.
const MIN_RANDOM_ENTROPY: f64 = 7.9;
const ASF_MAGIC: &[u8] = &[
    0x30, 0x26, 0xb2, 0x75, 0x8e, 0x66, 0xcf, 0x11, 0xa6, 0xd9, 0x00, 0xaa, 0x00, 0x62, 0xce, 0x6c,
];

#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash, PartialOrd, Ord)]
pub enum End {
    Matroska,
    Mp4,
    MpegTs,
    Avi,
    Asf,
    Encrypted,
    /// A whole file encrypted by a tool we can name, such as rclone or OpenSSL.
    EncryptedWrapper,
    /// An NZB posted as a file, which names other posts.
    InnerNzb,
    /// No signature, and every byte value about as common as the rest.
    LooksRandom,
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
            Self::Asf => "WMV/ASF",
            Self::Encrypted => "password protected",
            Self::EncryptedWrapper => "encrypted wrapper (rclone, OpenSSL)",
            Self::InnerNzb => "NZB inside the post",
            Self::LooksRandom => "no signature, looks random",
            Self::Compressed => "compressed archive",
            Self::SevenZip => "7z",
            Self::Zip => "ZIP",
            Self::Par2 => "PAR2",
            Self::NeedMore => "cut short",
            Self::Other => "unrecognized",
        }
    }

    const fn is_video(self) -> bool {
        matches!(
            self,
            Self::Matroska | Self::Mp4 | Self::MpegTs | Self::Avi | Self::Asf
        )
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
    /// The layers as `rar5(stored)`, outermost first.
    pub chain: Vec<&'static str>,
}

const RAR4_MAGIC: &[u8] = b"Rar!\x1a\x07\x00";
const RAR5_MAGIC: &[u8] = b"Rar!\x1a\x07\x01\x00";

fn is_nzb(bytes: &[u8]) -> bool {
    let head = String::from_utf8_lossy(&bytes[..bytes.len().min(1024)]).to_ascii_lowercase();
    head.trim_start().starts_with('<') && head.contains("<nzb")
}

/// Whether a stretch of bytes has the even spread of encrypted data, which compression headers
/// and every media format here break straight away.
fn looks_random(bytes: &[u8]) -> bool {
    let sample = &bytes[..bytes.len().min(RANDOMNESS_SAMPLE)];
    if sample.len() < RANDOMNESS_SAMPLE / 8 {
        return false;
    }
    let mut counts = [0usize; 256];
    for byte in sample {
        counts[usize::from(*byte)] += 1;
    }
    let total = sample.len() as f64;
    let entropy: f64 = counts
        .iter()
        .filter(|count| **count > 0)
        .map(|count| {
            let share = *count as f64 / total;
            -share * share.log2()
        })
        .sum();
    entropy >= MIN_RANDOM_ENTROPY
}

fn kind_of(bytes: &[u8]) -> Option<End> {
    if bytes.starts_with(&[0x1a, 0x45, 0xdf, 0xa3]) {
        Some(End::Matroska)
    } else if bytes.get(4..8) == Some(b"ftyp") {
        Some(End::Mp4)
    } else if bytes.first() == Some(&0x47) && bytes.get(188) == Some(&0x47) {
        Some(End::MpegTs)
    } else if bytes.starts_with(b"RIFF") && bytes.get(8..11) == Some(b"AVI") {
        Some(End::Avi)
    } else if bytes.starts_with(ASF_MAGIC) {
        Some(End::Asf)
    } else if bytes.starts_with(b"RCLONE\0\0") || bytes.starts_with(b"Salted__") {
        Some(End::EncryptedWrapper)
    } else if is_nzb(bytes) {
        Some(End::InnerNzb)
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
fn step_into_rar<'a>(
    bytes: &'a [u8],
    names: &mut Vec<String>,
    chain: &mut Vec<&'static str>,
) -> Result<&'a [u8], End> {
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
                    chain.push("rar4(compressed)");
                    return Err(End::Compressed);
                }
                chain.push("rar4(stored)");
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
                    chain.push("rar5(compressed)");
                    return Err(End::Compressed);
                }
                chain.push("rar5(stored)");
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
    let mut chain = Vec::new();
    let mut layers = 0;
    loop {
        if let Some(end) = kind_of(stream) {
            return Peeled {
                end,
                layers,
                names,
                stream,
                chain,
            };
        }
        let is_rar = stream.starts_with(RAR4_MAGIC) || stream.starts_with(RAR5_MAGIC);
        // A signature cut off by the end of the bytes reads as nothing at all.
        let end = if !is_rar {
            Some(if stream.len() < 16 {
                End::NeedMore
            } else if looks_random(stream) {
                End::LooksRandom
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
                chain,
            };
        }
        match step_into_rar(stream, &mut names, &mut chain) {
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
                    chain,
                };
            }
        }
    }
}

/// A stored fingerprint of a release that already has a name.
#[derive(Clone, Debug)]
struct Known {
    release_id: i64,
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
                release_id,
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
    own_id: i64,
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
            if candidate.release_id != own_id
                && candidate.width == width as i64
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
    fetched_bytes: u64,
    over_budget: bool,
    /// Names the walk and the stream gave, for a second climb of the ladder.
    gained_inner: Vec<String>,
    gained_stream: Vec<String>,
    height: Option<i64>,
    /// What the ladder made of them: method, scope and name.
    identified: Option<(&'static str, &'static str, String)>,
    /// How the walk went, as `rar5(stored)>mkv`.
    chain: String,
    /// Sampled for a read of its stream.
    read: bool,
    header_read: bool,
    title_name: Option<(String, bool)>,
    inner_name: Option<(String, bool)>,
    uid_names: HashSet<String>,
    fingerprint_names: HashSet<String>,
    predb_candidates: usize,
    line: String,
    /// For a post nothing recognized: its first bytes, its name and its size.
    unknown: Option<String>,
}

struct Sampler<'a> {
    fetch: &'a dyn ArticleFetch,
    predb: &'a LocalPredb,
    /// The ladder to run again with what the stream gave. `None` only reports.
    ladder: Option<&'a Context<'a>>,
    known: &'a KnownReleases,
    media_budget: usize,
    media_taken: AtomicUsize,
    byte_budget: u64,
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
            let mut file_chain = String::new();
            let mut file_unknown = None;
            let mut tail: Option<Vec<u8>> = None;
            while index < file.head_message_ids.len().min(limit) {
                let Some(body) = self.get(group, &file.head_message_ids[index], &mut row) else {
                    row.missing |= !row.over_budget;
                    break;
                };
                buffer.extend_from_slice(&body);
                index += 1;
                let holder;
                let peeled = peel(&buffer);
                let mut chain: Vec<String> = peeled
                    .chain
                    .iter()
                    .map(|layer| (*layer).to_owned())
                    .collect();
                let mut names = peeled.names.clone();
                let mut current = peeled.end;
                let mut stream = peeled.stream;
                let mut layers = peeled.layers;
                // A 7z inside another layer has no tail of its own to read, for now.
                if current == End::SevenZip && layers == 0 {
                    match self.open_sevenzip(&buffer, nzb, file, group, &mut tail, &mut row) {
                        Opened::Encrypted { names: inner } => {
                            names.extend(inner);
                            chain.push("7z(aes)".to_owned());
                            current = End::Encrypted;
                        }
                        Opened::Inner {
                            method,
                            names: inner,
                            bytes,
                        } => {
                            names.extend(inner);
                            chain.push(format!("7z({method})"));
                            holder = bytes;
                            let within = peel(&holder);
                            chain.extend(within.chain.iter().map(|layer| (*layer).to_owned()));
                            names.extend(within.names);
                            current = within.end;
                            stream = within.stream;
                            layers += 1 + within.layers;
                        }
                        Opened::Unsupported {
                            method,
                            names: inner,
                        } => {
                            names.extend(inner);
                            chain.push(format!("7z({method})"));
                            current = End::Compressed;
                        }
                        Opened::NeedTail => chain.push("7z(index out of reach)".to_owned()),
                        Opened::Damaged => chain.push("7z(damaged)".to_owned()),
                    }
                }
                end = Some(current);
                chain.push(current.as_str().to_owned());
                file_chain = chain.join(">");
                file_unknown = matches!(current, End::Other | End::LooksRandom)
                    .then(|| unknown_line(evidence.id, file, stream));
                if let Some(found) = names.iter().find_map(|inner| scene_name(inner, self.predb)) {
                    row.inner_name = Some(found);
                }
                // Keep what earlier files gave: a later file that says nothing can't unsay it.
                for found in names {
                    if !row.gained_inner.contains(&found) {
                        row.gained_inner.push(found);
                    }
                }
                row.layers = layers;
                if current == End::NeedMore {
                    continue;
                }
                if current == End::Mp4 {
                    if !in_budget {
                        in_budget =
                            self.media_taken.fetch_add(1, Ordering::Relaxed) < self.media_budget;
                        if !in_budget {
                            break;
                        }
                        row.read = true;
                        limit = MAX_ARTICLES;
                    }
                    match mp4::probe_prefix(stream) {
                        Mp4Probe::Read(summary) => {
                            row.header_read = true;
                            self.record(&mut row, &summary, nzb.payload_bytes, evidence.posted_at);
                            break;
                        }
                        Mp4Probe::NeedPrefix { .. } => continue,
                        Mp4Probe::NotMp4 => break,
                        Mp4Probe::MoovInTail => {
                            if let Some(summary) = self.read_tail(group, file, &mut row) {
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
                if current != End::Matroska {
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
                match probe_prefix(stream, Limits::default()) {
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
                // What's reported is the file that decided the release, not the last one tried.
                if better {
                    best = Some(end);
                    row.chain = file_chain;
                    row.unknown = file_unknown;
                }
            }
            if best.is_some_and(End::is_video) {
                break;
            }
        }
        row.end = best;
        self.climb_again(evidence, &mut row);
        Some(row)
    }

    /// Climbs the ladder again with what the stream gave, as an inner file listing and media info.
    fn climb_again(&self, evidence: &Evidence, row: &mut Row) {
        let Some(context) = self.ladder else {
            return;
        };
        if row.gained_inner.is_empty() && row.gained_stream.is_empty() {
            return;
        }
        let mut enriched = evidence.clone();
        enriched
            .listing
            .extend(row.gained_inner.iter().map(|name| (name.clone(), 0)));
        enriched
            .media_names
            .extend(row.gained_stream.iter().cloned());
        enriched.height = row.height.or(evidence.height);
        if let (Outcome::Identified(found), _) = identify(&enriched, context) {
            row.identified = Some((found.method, found.scope.as_str(), found.name));
        }
    }

    /// One article, counted against the release's download budget.
    fn get(&self, group: &str, id: &str, row: &mut Row) -> Option<Vec<u8>> {
        if row.fetched_bytes >= self.byte_budget {
            row.over_budget = true;
            return None;
        }
        let body = self.fetch.fetch(group, id)?;
        row.fetched_bytes += body.len() as u64;
        Some(body)
    }

    /// An MP4 whose index comes after its media: read the file's last articles.
    fn read_tail(&self, group: &str, file: &NzbFile, row: &mut Row) -> Option<MediaSummary> {
        let mut bodies = Vec::new();
        for id in file.tail_message_ids.iter().rev() {
            bodies.insert(0, self.get(group, id, row)?);
            if let Some(summary) = mp4::probe_tail(&bodies.concat()) {
                return Some(summary);
            }
        }
        None
    }

    /// Opens a 7z, reading its last volume's tail back only as far as its index needs.
    fn open_sevenzip(
        &self,
        prefix: &[u8],
        nzb: &NzbSummary,
        file: &NzbFile,
        group: &str,
        tail: &mut Option<Vec<u8>>,
        row: &mut Row,
    ) -> Opened {
        if let Some(known) = tail {
            return sevenzip::open(prefix, known, DECODED_LIMIT);
        }
        let last = last_volume(nzb, file);
        let mut bodies: Vec<Vec<u8>> = Vec::new();
        for id in last.tail_message_ids.iter().rev() {
            let Some(body) = self.get(group, id, row) else {
                break;
            };
            bodies.insert(0, body);
            let joined = bodies.concat();
            let opened = sevenzip::open(prefix, &joined, DECODED_LIMIT);
            if opened != Opened::NeedTail {
                *tail = Some(joined);
                return opened;
            }
        }
        Opened::NeedTail
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
        let texts = stream_names(summary);
        row.title_name = texts.iter().find_map(|text| scene_name(text, self.predb));
        // Only the file's own title. Track names are mostly languages and codecs.
        row.gained_stream = summary.title.iter().cloned().collect();
        row.height = video
            .and_then(|video| video.height)
            .map(|height| height as i64);
        if let Some(uid) = summary.segment_uid.as_deref().and_then(uid_as_decimal) {
            for (_, shown) in self.known.uids.get(&uid).into_iter().flatten() {
                row.uid_names.insert(shown.clone());
            }
        }
        row.fingerprint_names =
            fingerprint_matches(self.known, summary, payload_bytes, row.release_id)
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

/// What's known of a post nothing recognized, so its format can be worked out and added.
fn unknown_line(release_id: i64, file: &NzbFile, stream: &[u8]) -> String {
    let head = &stream[..stream.len().min(16)];
    let hex: String = head.iter().map(|byte| format!("{byte:02x}")).collect();
    let ascii: String = head
        .iter()
        .map(|byte| {
            if byte.is_ascii_graphic() {
                *byte as char
            } else {
                '.'
            }
        })
        .collect();
    let printable = stream
        .iter()
        .take(4096)
        .filter(|byte| byte.is_ascii_graphic() || byte.is_ascii_whitespace())
        .count();
    let share = printable * 100 / stream.len().clamp(1, 4096);
    format!(
        "{release_id}\t{hex}\t{ascii}\t{share}\t{}\t{}",
        file.bytes,
        file.subject.replace(['\t', '\n'], " ")
    )
}

/// Whether two names are the same show episode or film, ignoring release tags.
fn same_content(left: &str, right: &str) -> bool {
    static DATE: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"(?:19|20)\d\d[._ -]\d\d[._ -]\d\d").expect("static pattern"));
    let date = |text: &str| DATE.find(text).map(|found| folded(found.as_str()));
    if date(left) != date(right) {
        return false;
    }
    let (left, right) = (name::parse(left), name::parse(right));
    let title = folded(&left.title);
    title.len() >= 2
        && title == folded(&right.title)
        && left.season == right.season
        && left.episode == right.episode
        && (left.year.is_none() || right.year.is_none() || left.year == right.year)
}

/// A subject with its name gone, keeping what opening the set relies on: the file's place in the
/// post, and its volume suffix on a name shared by the whole set.
fn masked_subject(subject: &str) -> String {
    static SUFFIX: LazyLock<Regex> = LazyLock::new(|| {
        Regex::new(r"(?i)(\.part\d+\.rar|(?:\.7z|\.zip)?\.\d{3}|\.[a-z0-9]{1,5})$")
            .expect("static pattern")
    });
    let shown = name::name_from_subject(subject).unwrap_or_default();
    let suffix = SUFFIX.find(&shown).map_or("", |found| found.as_str());
    match post_position(subject) {
        Some(position) => format!(r#"[{position}/999] "hidden{suffix}" yEnc"#),
        None => format!(r#""hidden{suffix}" yEnc"#),
    }
}

/// What the stream's evidence says about releases whose names are known, with the names hidden.
pub fn holdout(
    options: &Options,
    settings: &SampleOptions,
    fetch: &dyn ArticleFetch,
) -> Result<String> {
    let catalog_path = options.data_dir.join("catalog.db");
    let catalog = open_read_only(&catalog_path)?;
    let filter = "(r.predb_id IS NOT NULL OR r.is_renamed = 1)
                  AND (r.legacy_category BETWEEN 2000 AND 2999
                       OR r.legacy_category BETWEEN 5000 AND 5999)";
    let loaded = load(&catalog, filter, options.limit)?;
    let known = load_known(&catalog)?;
    drop(catalog);
    // The names to check against, then nothing of them left for the ladder to see.
    let truth: HashMap<i64, String> = loaded
        .targets
        .iter()
        .map(|target| (target.id, name::stem(&target.search_name)))
        .collect();
    let empty_donors = super::donor::DonorIndex::default();
    let context = Context {
        predb: &loaded.predb,
        donors: &empty_donors,
        hooks: Hooks::default(),
    };
    let sampler = Sampler {
        fetch,
        predb: &loaded.predb,
        ladder: Some(&context),
        known: &known,
        media_budget: settings.sample,
        media_taken: AtomicUsize::new(0),
        byte_budget: settings.byte_budget,
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
            if taken.fetch_add(1, Ordering::Relaxed) >= settings.sample {
                return;
            }
            let mut hidden = target.clone();
            hidden.name = String::new();
            hidden.search_name = String::new();
            hidden.par2_names.clear();
            hidden.listing.clear();
            hidden.media_names.clear();
            hidden.facts.clear();
            if let Some(nzb) = &mut hidden.nzb {
                for file in &mut nzb.files {
                    file.subject = masked_subject(&file.subject);
                }
            }
            if let Some(row) = sampler.read_release(&hidden) {
                rows.lock().expect("no panic while holding this").push(row);
            }
        },
    )?;
    let rows = rows.into_inner().expect("no panic while holding this");
    Ok(render_holdout(&rows, &truth))
}

fn render_holdout(rows: &[Row], truth: &HashMap<i64, String>) -> String {
    let is_right = |row: &Row, shown: &str| {
        truth
            .get(&row.release_id)
            .is_some_and(|known| same_content(shown, known))
    };
    let mut out = format!("# Stream hold-out\n\nReleases sampled: {}\n\n", rows.len());
    let video = rows
        .iter()
        .filter(|row| row.end.is_some_and(End::is_video))
        .count();
    let _ = writeln!(
        out,
        "Reached a video stream: {video}. Header read: {}.\n",
        rows.iter().filter(|row| row.header_read).count()
    );
    let mut names: BTreeMap<&str, (usize, usize)> = BTreeMap::new();
    let mut wrong = Vec::new();
    for row in rows {
        if let Some((method, _, shown)) = &row.identified {
            let entry = names.entry(method).or_default();
            entry.0 += 1;
            if is_right(row, shown) {
                entry.1 += 1;
            } else {
                wrong.push(format!(
                    "- {} {method}: `{shown}`, really `{}`",
                    row.release_id,
                    truth.get(&row.release_id).map_or("?", String::as_str)
                ));
            }
        }
    }
    let _ = writeln!(out, "| Rung | Answers | Right |\n|---|---|---|");
    for (method, (answers, right)) in &names {
        let _ = writeln!(out, "| {method} | {answers} | {right} |");
    }
    let (mut unique, mut unique_right, mut several) = (0, 0, 0);
    for row in rows {
        let distinct: HashSet<String> = row
            .fingerprint_names
            .iter()
            .map(|shown| folded(shown))
            .collect();
        match distinct.len() {
            0 => {}
            1 => {
                unique += 1;
                if row
                    .fingerprint_names
                    .iter()
                    .any(|shown| is_right(row, shown))
                {
                    unique_right += 1;
                }
            }
            _ => several += 1,
        }
    }
    let _ = writeln!(
        out,
        "\n## Fingerprint against other named releases\n\nFits exactly one: {unique}, of which the same content: {unique_right}. Fits several: {several}.\n\n## Wrong answers from names\n"
    );
    for line in wrong {
        let _ = writeln!(out, "{line}");
    }
    out
}

pub struct SampleOptions {
    /// Releases with no other evidence to classify.
    pub sample: usize,
    /// Of those, how many Matroska and MP4 streams to read in full.
    pub media: usize,
    /// The most to download for one release.
    pub byte_budget: u64,
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
        ladder: Some(&offline),
        known: &known,
        media_budget: settings.media,
        media_taken: AtomicUsize::new(0),
        byte_budget: settings.byte_budget,
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
    let mut chains: BTreeMap<&str, usize> = BTreeMap::new();
    for row in rows.iter().filter(|row| !row.chain.is_empty()) {
        *chains.entry(row.chain.as_str()).or_default() += 1;
    }
    let read: Vec<&Row> = rows.iter().filter(|row| row.read).collect();
    let count = |test: &dyn Fn(&Row) -> bool| read.iter().filter(|row| test(row)).count();
    let fetched: u64 = rows.iter().map(|row| row.fetched_bytes).sum();
    let largest = rows.iter().map(|row| row.fetched_bytes).max().unwrap_or(0);
    let mut out = format!(
        "# Stream sample\n\nReleases: {}\n\nDownloaded: {} MiB, averaging {} KiB a release, {} MiB at most. Over budget: {}.\n\n## What's inside\n\n| Reached | Releases |\n|---|---|\n",
        rows.len(),
        fetched / (1024 * 1024),
        fetched / 1024 / (rows.len().max(1) as u64),
        largest / (1024 * 1024),
        rows.iter().filter(|row| row.over_budget).count(),
    );
    for (label, total) in &ends {
        let _ = writeln!(out, "| {label} | {total} |");
    }
    let _ = writeln!(out, "\n## Layer chains\n\n| Chain | Releases |\n|---|---|");
    let mut by_count: Vec<_> = chains.iter().collect();
    by_count.sort_by_key(|(_, total)| std::cmp::Reverse(**total));
    for (chain, total) in by_count {
        let _ = writeln!(out, "| `{chain}` | {total} |");
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
         | Fingerprint fits one named release (unproven: 9 of 32 right in the hold-out) | {} |\n\
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
    let mut climbed: BTreeMap<(&str, &str), usize> = BTreeMap::new();
    for found in rows.iter().filter_map(|row| row.identified.as_ref()) {
        *climbed.entry((found.0, found.1)).or_default() += 1;
    }
    let _ = writeln!(
        out,
        "\n## Identified by the ladder from these names\n\nReleases: {}\n\n| Method | Scope | Releases |\n|---|---|---|",
        rows.iter().filter(|row| row.identified.is_some()).count()
    );
    for ((method, scope), total) in &climbed {
        let _ = writeln!(out, "| {method} | {scope} | {total} |");
    }
    let _ = writeln!(out, "\n## Identifications\n");
    for row in rows {
        if let Some((method, scope, shown)) = &row.identified {
            let _ = writeln!(out, "- {} {method} {scope}: {shown}", row.release_id);
        }
    }
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
    lines.push_str(
        "\n# unknown: release_id, first 16 bytes, ascii, percent printable, bytes, subject\n",
    );
    for row in rows
        .iter()
        .filter(|row| matches!(row.end, Some(End::Other | End::LooksRandom)))
    {
        if let Some(unknown) = &row.unknown {
            lines.push_str(unknown);
            lines.push('\n');
        }
    }
    (out, lines)
}

#[cfg(test)]
mod tests {
    use super::*;
    use sevenz_rust2::EncoderMethod;

    use crate::identify::archive_probe::tests::{Fake, rar4, rar4_method};
    use crate::identify::nzb::NzbFile;
    use crate::identify::sevenzip;

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
        assert_eq!(peel(ASF_MAGIC).end, End::Asf);
        assert_eq!(
            peel(b"RCLONE\0\0 and then the rest").end,
            End::EncryptedWrapper
        );
        assert_eq!(
            peel(b"Salted__ and then the rest").end,
            End::EncryptedWrapper
        );
        assert_eq!(
            peel(b"<?xml version=\"1.0\"?><!DOCTYPE nzb><nzb xmlns=\"x\"></nzb>").end,
            End::InnerNzb
        );
    }

    #[test]
    fn even_bytes_read_as_random_and_a_pattern_does_not() {
        // A simple generator, so the test doesn't need a random crate.
        let mut state = 0x2545_f491_4f6c_dd1du64;
        let noise: Vec<u8> = (0..RANDOMNESS_SAMPLE)
            .map(|_| {
                state ^= state << 13;
                state ^= state >> 7;
                state ^= state << 17;
                (state >> 32) as u8
            })
            .collect();
        assert_eq!(peel(&noise).end, End::LooksRandom);
        let pattern: Vec<u8> = (0..RANDOMNESS_SAMPLE).map(|i| (i % 32) as u8).collect();
        assert_eq!(peel(&pattern).end, End::Other);
        // Too little to tell.
        assert_eq!(peel(&noise[..64]).end, End::Other);
    }

    #[test]
    fn the_report_follows_the_file_that_decided_the_release() {
        let file = |subject: &str, id: &str| NzbFile {
            subject: subject.to_owned(),
            bytes: 100,
            group: Some("g".to_owned()),
            first_message_id: Some(id.to_owned()),
            head_message_ids: vec![id.to_owned()],
            tail_message_ids: vec![id.to_owned()],
        };
        let mut fake = Fake::default();
        fake.0.insert(
            "a".to_owned(),
            b"7z\xbc\xaf\x27\x1c and more bytes".to_vec(),
        );
        fake.0.insert("b".to_owned(), vec![0x9c; 4096]);
        let predb = LocalPredb::default();
        let known = KnownReleases::default();
        let sampler = Sampler {
            fetch: &fake,
            predb: &predb,
            ladder: None,
            known: &known,
            media_budget: 0,
            media_taken: AtomicUsize::new(0),
            byte_budget: 64 * 1024 * 1024,
        };
        let evidence = Evidence {
            nzb: Some(NzbSummary {
                files: vec![
                    file(r#""x.7z.001" yEnc"#, "a"),
                    file(r#""x.7z.002" yEnc"#, "b"),
                ],
                ..NzbSummary::default()
            }),
            ..Evidence::default()
        };
        let row = sampler.read_release(&evidence).expect("row");
        assert_eq!(row.end, Some(End::SevenZip));
        // The fake 7z has no index, and the random second volume doesn't replace it in the report.
        assert_eq!(row.chain, "7z(damaged)>7z");
        assert_eq!(row.unknown, None);
    }

    fn sevenzip_row(files: Vec<NzbFile>, fake: &Fake) -> Row {
        let predb = LocalPredb::default();
        let known = KnownReleases::default();
        let sampler = Sampler {
            fetch: fake,
            predb: &predb,
            ladder: None,
            known: &known,
            media_budget: 10,
            media_taken: AtomicUsize::new(0),
            byte_budget: 64 * 1024 * 1024,
        };
        let evidence = Evidence {
            nzb: Some(NzbSummary {
                files,
                ..NzbSummary::default()
            }),
            ..Evidence::default()
        };
        sampler.read_release(&evidence).expect("row")
    }

    fn volume(subject: &str, head: &str, tail: &str) -> NzbFile {
        NzbFile {
            subject: subject.to_owned(),
            bytes: 100,
            group: Some("g".to_owned()),
            first_message_id: Some(head.to_owned()),
            head_message_ids: vec![head.to_owned()],
            tail_message_ids: vec![tail.to_owned()],
        }
    }

    #[test]
    fn walks_through_a_compressed_7z_split_across_volumes_to_the_stream() {
        let archive = sevenzip::tests::archive(
            EncoderMethod::LZMA2,
            "Some.Film.2021.1080p.WEB-DL.H264-GRP.mkv",
            &mkv(),
        );
        // The last volume holds the index and whatever else fits, as with any fixed-size split.
        let (first, second) = archive.split_at(archive.len() - 512);
        let mut fake = Fake::default();
        fake.0.insert("one".to_owned(), first.to_vec());
        fake.0.insert("two".to_owned(), second.to_vec());
        let row = sevenzip_row(
            vec![
                volume(r#""x.7z.002" yEnc"#, "two", "two"),
                volume(r#""x.7z.001" yEnc"#, "one", "one"),
            ],
            &fake,
        );
        assert_eq!(row.chain, "7z(lzma2)>Matroska");
        assert_eq!(row.end, Some(End::Matroska));
        assert!(row.header_read);
        assert_eq!(row.layers, 1);
        assert_eq!(
            row.inner_name.map(|found| found.0).as_deref(),
            Some("Some.Film.2021.1080p.WEB-DL.H264-GRP")
        );
        assert!(row.fetched_bytes > 0);
    }

    #[test]
    fn a_name_inside_a_stream_goes_back_through_the_ladder() {
        let inner = "Some.Film.2021.1080p.WEB-DL.H264-GRP.mkv";
        let archive = sevenzip::tests::archive(EncoderMethod::COPY, inner, &mkv());
        let mut fake = Fake::default();
        fake.0.insert("a".to_owned(), archive);
        fake.0.insert("b".to_owned(), mkv());
        let predb = LocalPredb::default();
        let known = KnownReleases::default();
        let donors = crate::identify::DonorIndex::default();
        let context = Context {
            predb: &predb,
            donors: &donors,
            hooks: Hooks::default(),
        };
        let sampler = Sampler {
            fetch: &fake,
            predb: &predb,
            ladder: Some(&context),
            known: &known,
            media_budget: 10,
            media_taken: AtomicUsize::new(0),
            byte_budget: 64 * 1024 * 1024,
        };
        let release = |file| Evidence {
            nzb: Some(NzbSummary {
                files: vec![file],
                ..NzbSummary::default()
            }),
            ..Evidence::default()
        };
        // A name in an archive's index.
        let row = sampler
            .read_release(&release(volume(r#""x.7z" yEnc"#, "a", "a")))
            .expect("row");
        let (method, scope, name) = row.identified.expect("identified");
        assert_eq!(
            (method, scope, name.as_str()),
            (
                "archive_listing_regex",
                "in_scope",
                "Some.Film.2021.1080p.WEB-DL.H264-GRP"
            )
        );
        // The bare fixture's title is "Sample Movie 2024 2160p UHD BluRay REMUX".
        let row = sampler
            .read_release(&release(volume(r#""abc.mkv" yEnc"#, "b", "b")))
            .expect("row");
        let (method, scope, _) = row.identified.expect("identified");
        assert_eq!((method, scope), ("media_info_regex", "in_scope"));
    }

    #[test]
    fn a_stored_7z_is_walked_through_too() {
        let archive = sevenzip::tests::archive(EncoderMethod::COPY, "a.mkv", &mkv());
        let mut fake = Fake::default();
        fake.0.insert("a".to_owned(), archive);
        let row = sevenzip_row(vec![volume(r#""x.7z" yEnc"#, "a", "a")], &fake);
        assert_eq!(row.chain, "7z(copy)>Matroska");
    }

    #[test]
    fn the_download_budget_stops_a_release_and_is_counted() {
        let archive = sevenzip::tests::archive(EncoderMethod::COPY, "a.mkv", &mkv());
        let mut fake = Fake::default();
        fake.0.insert("a".to_owned(), archive);
        let predb = LocalPredb::default();
        let known = KnownReleases::default();
        let sampler = Sampler {
            fetch: &fake,
            predb: &predb,
            ladder: None,
            known: &known,
            media_budget: 10,
            media_taken: AtomicUsize::new(0),
            byte_budget: 0,
        };
        let evidence = Evidence {
            nzb: Some(NzbSummary {
                files: vec![volume(r#""x.7z" yEnc"#, "a", "a")],
                ..NzbSummary::default()
            }),
            ..Evidence::default()
        };
        let row = sampler.read_release(&evidence).expect("row");
        assert!(row.over_budget);
        assert!(!row.missing);
        assert_eq!(row.fetched_bytes, 0);
    }

    #[test]
    fn the_chain_records_each_layer() {
        let bytes = wrap(&wrap(&mkv(), "abc.mkv"), "abc.rar");
        assert_eq!(peel(&bytes).chain, ["rar4(stored)", "rar4(stored)"]);
        let mut packed = rar4_method("packed.mkv", 100, 1, 0, 0x33);
        packed.extend_from_slice(&mkv());
        assert_eq!(peel(&packed).chain, ["rar4(compressed)"]);
    }

    #[test]
    fn masking_keeps_a_set_together_and_the_name_out() {
        let first = masked_subject(r#"Secret.Name [01/10] "Secret.Name.7z.001" yEnc (1/90)"#);
        assert_eq!(first, r#"[1/999] "hidden.7z.001" yEnc"#);
        assert_eq!(
            masked_subject(r#""Secret.Name.part02.rar" yEnc"#),
            r#""hidden.part02.rar" yEnc"#
        );
        assert_eq!(
            masked_subject(r#""Secret.Name.mkv" yEnc"#),
            r#""hidden.mkv" yEnc"#
        );
        assert_eq!(masked_subject("Secret Name"), r#""hidden" yEnc"#);
    }

    #[test]
    fn content_compares_by_title_and_episode_not_tags() {
        assert!(same_content(
            "Zmora.S01E05.1080p.WEB.h264-AAA",
            "zmora s01e05 720p amzn web-dl-BBB"
        ));
        assert!(!same_content(
            "Zmora.S01E05.1080p.WEB-AAA",
            "Zmora.S01E06.1080p.WEB-AAA"
        ));
        assert!(!same_content(
            "Zmora.S01E05.1080p.WEB-AAA",
            "Other.S01E05.1080p.WEB-AAA"
        ));
        assert!(!same_content(
            "Film.2020.1080p.WEB-AAA",
            "Film.2021.1080p.WEB-AAA"
        ));
        assert!(same_content(
            "Toy Story 2 (1999)",
            "Toy.Story.2.1999.1080p.BluRay.x264-GRP"
        ));
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
